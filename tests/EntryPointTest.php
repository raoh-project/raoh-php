<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

/**
 * Every entry point of the public API that takes a string refuses one that is not UTF-8, with an
 * \InvalidArgumentException, where it is given: a string the library keeps ends up in an issue, a
 * path or a message, which a client writes as JSON. The entry points are found by reflection over
 * the API PublicApi records, so a method added later is held to the rule without being listed
 * here; one this test cannot call fails until it is given its arguments below.
 *
 * Readers of text are the exception: what they are given is input, and text that is not UTF-8 is
 * input they reject as they reject any other bad input.
 */
class EntryPointTest extends TestCase
{
    /** Entry points whose string is input to read, not an argument to keep, and how they reject bad input. */
    private const READERS = [
        'Raoh\Input\Json::parse' => 'throws JsonException for text that is not JSON',
        'Raoh\Input\JsonObject::get' => 'looks a name up; no member has a non-UTF-8 name',
        'Raoh\Input\JsonObject::has' => 'looks a name up; no member has a non-UTF-8 name',
        'Raoh\Messages::format' => 'looks a key up; no template has a non-UTF-8 key',
        'Raoh\Messages::__invoke' => 'looks a key up; no template has a non-UTF-8 key',
        'Raoh\Value\Decimal::parse' => 'gives null for text that is not a decimal',
        'Raoh\Value\Temporal\Instant::parse' => 'gives null for text that is not an instant',
        'Raoh\Value\Temporal\LocalDate::parse' => 'gives null for text that is not a date',
        'Raoh\Value\Temporal\LocalDateTime::parse' => 'gives null for text that is not a date-time',
        'Raoh\Value\Temporal\LocalTime::parse' => 'gives null for text that is not a time',
        'Raoh\Value\Temporal\OffsetDateTime::parse' => 'gives null for text that is not an offset date-time',
    ];

    /**
     * @return iterable<string, array{\ReflectionMethod|\ReflectionFunction, int}>
     */
    public static function stringParameters(): iterable
    {
        foreach (PublicApi::entries() as $entry) {
            $name = self::entryName($entry);
            if (isset(self::READERS[$name])) {
                continue;
            }
            foreach ($entry->getParameters() as $i => $p) {
                if (self::takesString($p)) {
                    yield "{$name}(\${$p->getName()})" => [$entry, $i];
                }
            }
        }
    }

    #[DataProvider('stringParameters')]
    public function testANonUtf8StringIsRefusedWhereItIsGiven(\ReflectionMethod|\ReflectionFunction $entry, int $at): void
    {
        $args = $this->arguments($entry);
        // The call with every argument well formed is accepted, so that a refusal below is the
        // refusal of the one argument that is not.
        $this->call($entry, $args);
        $args[$at] = "\xff";
        try {
            $this->call($entry, $args);
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail(self::entryName($entry) . ' kept a string that is not UTF-8 in $' . $entry->getParameters()[$at]->getName());
    }

    public function testEveryReaderIsAnEntryPoint(): void
    {
        $names = array_map(self::entryName(...), PublicApi::entries());
        foreach (array_keys(self::READERS) as $reader) {
            $this->assertContains($reader, $names, "{$reader} is no longer an entry point; take it out of READERS");
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function call(\ReflectionMethod|\ReflectionFunction $entry, array $args): void
    {
        if ($entry instanceof \ReflectionFunction) {
            $entry->invokeArgs($args);
            return;
        }
        if ($entry->isConstructor()) {
            $entry->getDeclaringClass()->newInstanceArgs($args);
            return;
        }
        if ($entry->isStatic()) {
            $entry->invokeArgs(null, $args);
            return;
        }
        // Called by name, as a caller does, so that a trait's method is called on a class using it.
        $this->receiver($entry)->{$entry->getName()}(...$args);
    }

    /**
     * Well-formed arguments: each one the parameter's name calls for, or its default.
     *
     * @return list<mixed>
     */
    private function arguments(\ReflectionMethod|\ReflectionFunction $entry): array
    {
        $args = [];
        foreach ($entry->getParameters() as $p) {
            if ($p->isVariadic()) {
                break;
            }
            $args[] = $this->argument($entry, $p);
        }
        return $args;
    }

    private function argument(\ReflectionMethod|\ReflectionFunction $entry, \ReflectionParameter $p): mixed
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
            $p->isDefaultValueAvailable() => $p->getDefaultValue(),
            default => $this->byType($entry, $p),
        };
    }

    private function byType(\ReflectionMethod|\ReflectionFunction $entry, \ReflectionParameter $p): mixed
    {
        $type = (string) $p->getType();
        return match (true) {
            str_contains($type, 'string') => 'a',
            $type === 'int' => 1,
            $type === 'Raoh\Path' => Path::root(),
            in_array($type, ['callable', 'Closure'], true) => static fn (mixed ...$_): mixed => null,
            $type === 'Raoh\Decoder', $type === 'Raoh\FieldDecoder' => Decoders::field('a', Decoders::int_()),
            $type === 'Raoh\Encoder' => CallableEncoder::of(static fn (mixed $v): mixed => $v),
            $type === 'Raoh\Issue' => Issue::of(Path::root(), 'required', 'is required'),
            $type === 'Raoh\Issues' => Issues::of([Issue::of(Path::root(), 'required', 'is required')]),
            $type === 'mixed' => 1,
            default => throw new \LogicException(self::entryName($entry) . " takes \${$p->getName()} of {$type}, which this test has no argument for"),
        };
    }

    private function receiver(\ReflectionMethod $method): object
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
            CallableDecoder::class, 'Raoh\DecoderTrait' => CallableDecoder::of(static fn (): mixed => null),
            Issue::class => Issue::of(Path::root(), 'required', 'is required'),
            Issues::class => Issues::empty(),
            Path::class => Path::root(),
            Messages::class => Messages::english(),
            JsonObject::class => new JsonObject(),
            PropertyEncoder::class => new PropertyEncoder('a', static fn (mixed $v): mixed => $v, CallableEncoder::of(static fn (mixed $v): mixed => $v)),
            default => throw new \LogicException("this test has no receiver for {$class}"),
        };
    }

    private static function takesString(\ReflectionParameter $p): bool
    {
        $type = $p->getType();
        $names = match (true) {
            $type instanceof \ReflectionNamedType => [$type->getName()],
            $type instanceof \ReflectionUnionType => array_map(static fn (\ReflectionType $t): string => (string) $t, $type->getTypes()),
            default => [],
        };
        return in_array('string', $names, true);
    }

    private static function entryName(\ReflectionMethod|\ReflectionFunction $entry): string
    {
        return $entry instanceof \ReflectionMethod
            ? $entry->getDeclaringClass()->getName() . '::' . $entry->getName()
            : $entry->getName();
    }
}
