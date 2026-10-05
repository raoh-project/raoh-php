<?php

declare(strict_types=1);

namespace Raoh\Tests;

use Raoh\Boundary\Array_\Encode\PropertyEncoder;
use Raoh\Builtin\BoolDecoder;
use Raoh\Builtin\DecimalDecoder;
use Raoh\Builtin\DictDecoder;
use Raoh\Builtin\DoubleDecoder;
use Raoh\Builtin\FloatDecoder;
use Raoh\Builtin\IntDecoder;
use Raoh\Builtin\ListDecoder;
use Raoh\Builtin\LongDecoder;
use Raoh\Builtin\NumberDecoder;
use Raoh\Builtin\ObjectDecoder;
use Raoh\Builtin\StringDecoder;
use Raoh\Builtin\TemporalDecoder;
use Raoh\CallableDecoder;
use Raoh\CallableEncoder;
use Raoh\Combinator\Combiner;
use Raoh\Decoders;
use Raoh\Field\Field;
use Raoh\Field\OptionalField;
use Raoh\Field\OptionalNullableField;
use Raoh\Input\JsonObject;
use Raoh\Issue;
use Raoh\Issues;
use Raoh\Messages;
use Raoh\Path;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Decoder;
use Raoh\Result;

/**
 * Calls the entry points of the public API that {@see PublicApi::entries()} gives, for the tests
 * that hold every one of them to a rule: a receiver for each class, and for each parameter a
 * well-formed argument, by its name, its type or its default. An entry point it has no argument
 * for fails the test that calls it, until one is given here.
 */
final class EntryPoints
{
    private function __construct()
    {
    }

    /**
     * @param list<mixed> $args
     */
    public static function call(\ReflectionMethod|\ReflectionFunction $entry, array $args): mixed
    {
        if ($entry instanceof \ReflectionFunction) {
            return $entry->invokeArgs($args);
        }
        if ($entry->isConstructor()) {
            return $entry->getDeclaringClass()->newInstanceArgs($args);
        }
        if ($entry->isStatic()) {
            return $entry->invokeArgs(null, $args);
        }
        // Called by name, as a caller does, so that a trait's method is called on a class using it.
        return self::receiver($entry)->{$entry->getName()}(...$args);
    }

    /**
     * Well-formed arguments: each one the parameter's name calls for, or its default.
     *
     * @return list<mixed>
     */
    public static function arguments(\ReflectionMethod|\ReflectionFunction $entry): array
    {
        $args = [];
        foreach ($entry->getParameters() as $p) {
            // A variadic parameter is given one argument, as a call with none is refused.
            $args[] = self::argument($entry, $p);
            if ($p->isVariadic()) {
                break;
            }
        }
        return $args;
    }

    public static function argument(\ReflectionMethod|\ReflectionFunction $entry, \ReflectionParameter $p): mixed
    {
        $class = $entry instanceof \ReflectionMethod ? $entry->getDeclaringClass()->getName() : null;
        $name = $p->getName();
        return match (true) {
            in_array($name, ['bound', 'from'], true) => '2000-01-01',
            $name === 'to' => '2001-01-01',
            $name === 'form' => 'NFC',
            $name === 'type' => LocalDate::class,
            $name === 'symbols' => ['A'],
            $name === 'text' => 'raoh.a=b',
            in_array($name, ['jsonNumber', 'lexeme', 'coefficient'], true) => '1',
            $name === 'code' => 'invalid_value',
            in_array($name, ['messageKey'], true) && !$p->allowsNull() => 'invalid_value',
            $name === 'variants' => ['a' => Decoders::int_()],
            $name === 'knownFields' => ['a'],
            $name === 'allowed' => $class === StringDecoder::class ? ['a'] : [1],
            in_array($name, ['element'], true) => 1,
            $name === 'elements' => [1],
            $name === 'decoders' => [Decoders::int_()],
            $name === 'items' => [],
            $name === 'templates' => [],
            $name === 'meta' => [],
            $name === 'divisor' => 1,
            // A bound of NumberDecoder is a value of the decoder's type, which the receiver is int_().
            in_array($name, ['n', 'min', 'max'], true) && (string) $p->getType() === 'mixed' => 1,
            $name === 'rest' => 'b',
            $p->isDefaultValueAvailable() => $p->getDefaultValue(),
            default => self::byType($entry, $p),
        };
    }

    public static function byType(\ReflectionMethod|\ReflectionFunction $entry, \ReflectionParameter $p): mixed
    {
        $type = (string) $p->getType();
        return match (true) {
            str_contains($type, 'string') => 'a',
            $type === 'int' => 1,
            $type === 'Raoh\Path' => Path::root(),
            in_array($type, ['callable', 'Closure'], true) => self::callable($entry, $p),
            $type === 'Raoh\Decoder', $type === 'Raoh\FieldDecoder' => Decoders::field('a', Decoders::int_()),
            $type === 'Raoh\Encoder' => CallableEncoder::of(static fn (mixed $v): mixed => $v),
            $type === 'Raoh\Issue' => Issue::of(Path::root(), 'required', 'is required'),
            $type === 'Raoh\Issues' => Issues::of([Issue::of(Path::root(), 'required', 'is required')]),
            $type === 'mixed' => 1,
            default => throw new \LogicException(self::name($entry) . " takes \${$p->getName()} of {$type}, which this test has no argument for"),
        };
    }

    /**
     * A function that keeps the contract its parameter has: a decoder's function gives a Result,
     * a supplier a decoder, a predicate false (so that the issue it guards is made).
     */
    public static function callable(\ReflectionMethod|\ReflectionFunction $entry, \ReflectionParameter $p): \Closure
    {
        $name = self::name($entry);
        return match (true) {
            $p->getName() === 'fn' => static fn (mixed ...$_): Result => Result::ok(1),
            $p->getName() === 'f' && str_ends_with($name, '::flatMap') => static fn (mixed ...$_): Result => Result::ok(1),
            $p->getName() === 'supplier' => static fn (): Decoder => Decoders::int_(),
            $p->getName() === 'predicate' => static fn (mixed ...$_): bool => false,
            $p->getName() === 'recovery' => static fn (mixed ...$_): int => 1,
            default => static fn (mixed ...$_): mixed => null,
        };
    }

    public static function receiver(\ReflectionMethod $method): object
    {
        $class = $method->getDeclaringClass()->getName();
        return match ($class) {
            StringDecoder::class => Decoders::string_(),
            IntDecoder::class, NumberDecoder::class => Decoders::int_(),
            LongDecoder::class => Decoders::long(),
            FloatDecoder::class => Decoders::float_(),
            DoubleDecoder::class => Decoders::double(),
            DecimalDecoder::class => Decoders::decimal(),
            BoolDecoder::class => Decoders::bool_(),
            ListDecoder::class => Decoders::list_(Decoders::int_()),
            DictDecoder::class => Decoders::dict(Decoders::int_()),
            TemporalDecoder::class => Decoders::string_()->date(),
            ObjectDecoder::class => Decoders::object(Decoders::field('a', Decoders::int_())),
            Field::class => Decoders::field('a', Decoders::int_()),
            OptionalField::class => Decoders::optionalField('a', Decoders::int_()),
            OptionalNullableField::class => Decoders::optionalNullableField('a', Decoders::int_()),
            Combiner::class => Decoders::combine(Decoders::int_()),
            CallableDecoder::class, 'Raoh\DecoderTrait' => CallableDecoder::of(static fn (): Result => Result::ok(1)),
            Issue::class => Issue::of(Path::root(), 'required', 'is required'),
            Issues::class => Issues::empty(),
            Path::class => Path::root(),
            Messages::class => Messages::english(),
            JsonObject::class => new JsonObject(),
            PropertyEncoder::class => new PropertyEncoder('a', static fn (mixed $v): mixed => $v, CallableEncoder::of(static fn (mixed $v): mixed => $v)),
            default => throw new \LogicException("this test has no receiver for {$class}"),
        };
    }

    public static function takesString(\ReflectionParameter $p): bool
    {
        $type = $p->getType();
        $names = match (true) {
            $type instanceof \ReflectionNamedType => [$type->getName()],
            $type instanceof \ReflectionUnionType => array_map(static fn (\ReflectionType $t): string => (string) $t, $type->getTypes()),
            default => [],
        };
        return in_array('string', $names, true);
    }

    public static function name(\ReflectionMethod|\ReflectionFunction $entry): string
    {
        return $entry instanceof \ReflectionMethod
            ? $entry->getDeclaringClass()->getName() . '::' . $entry->getName()
            : $entry->getName();
    }
}
