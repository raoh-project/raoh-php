<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Absent;
use Raoh\Builtin\DictDecoder;
use Raoh\Builtin\ListDecoder;
use Raoh\Decoder;
use Raoh\Decoders;
use Raoh\Err;
use Raoh\Input\Json;
use Raoh\Value\Decimal;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;

/**
 * A decoder is refused when it is built, or decodes without throwing: what is known when it is
 * built is checked then, and never left for a value to find. Every entry point of the public API
 * that gives a decoder is called with each of its arguments, one at a time, replaced by values
 * that are wrong in the ways an argument can be (not UTF-8, empty, a list where names are wanted,
 * a resource, another type, a code a message key does not refine, out of range ...). Each call is
 * refused, or gives a decoder that decodes every input below, good and bad, into a result whose
 * issues are JSON.
 *
 * A function given as an argument keeps its contract here (a decoder's function gives a Result):
 * what a function does when it is called is the caller's, not something the decoder can check
 * when it is built.
 */
class BuildOrRefuseTest extends TestCase
{
    /**
     * @return iterable<string, array{\ReflectionMethod|\ReflectionFunction}>
     */
    public static function builders(): iterable
    {
        foreach (PublicApi::entries() as $entry) {
            if (self::givesDecoder($entry)) {
                yield EntryPoints::name($entry) => [$entry];
            }
        }
    }

    #[DataProvider('builders')]
    public function testRefusedWhenBuiltOrDecodesWithoutThrowing(\ReflectionMethod|\ReflectionFunction $entry): void
    {
        $base = EntryPoints::arguments($entry);
        $runs = [['as given', $base]];
        foreach ($entry->getParameters() as $i => $p) {
            foreach (self::wrong($p) as $what => $value) {
                $args = $base;
                $args[$i] = $value;
                $runs[] = ["\${$p->getName()} {$what}", $args];
            }
        }
        $receivers = [null];
        if ($entry instanceof \ReflectionMethod && !$entry->isStatic()
            && in_array($entry->getDeclaringClass()->getName(), [ListDecoder::class, DictDecoder::class], true)) {
            // An operation on elements meets every kind of element a decoder can give: values of
            // the model, a presence, and what a function given to map() made.
            $receivers = [];
            foreach (self::elements() as $element) {
                $receivers[] = $entry->getDeclaringClass()->getName() === ListDecoder::class
                    ? Decoders::list_($element)
                    : Decoders::dict($element);
            }
        }
        foreach ($receivers as $receiver) {
            $this->buildAndDecode($entry, $runs, $receiver);
        }
    }

    /**
     * @param list<array{string, list<mixed>}> $runs
     */
    private function buildAndDecode(\ReflectionMethod|\ReflectionFunction $entry, array $runs, ?object $receiver): void
    {
        foreach ($runs as [$what, $args]) {
            try {
                $decoder = EntryPoints::call($entry, $args, $receiver);
            } catch (\InvalidArgumentException | \TypeError) {
                $this->addToAssertionCount(1);
                continue;
            }
            $this->assertInstanceOf(Decoder::class, $decoder);
            foreach (self::inputs() as $input => $value) {
                try {
                    $r = $decoder->decode($value);
                } catch (\Throwable $e) {
                    $this->fail(EntryPoints::name($entry) . " built with {$what} threw on {$input}: "
                        . $e::class . ': ' . $e->getMessage());
                }
                if ($r instanceof Err) {
                    $this->assertNotFalse(
                        json_encode($r->issues->toJsonList()),
                        EntryPoints::name($entry) . " built with {$what} gave issues that are not JSON on {$input}",
                    );
                }
            }
        }
    }

    /**
     * Values an argument of that type can be wrong with.
     *
     * @return array<string, mixed>
     */
    private static function wrong(\ReflectionParameter $p): array
    {
        $type = (string) $p->getType();
        $wrong = [];
        if (str_contains($type, 'string')) {
            $wrong += [
                'not UTF-8' => "\xff",
                'empty' => '',
                'another code' => 'blank',
                'a refined key of another code' => 'too_small.nonempty',
                'a number' => '1',
                'a date-time' => '2000-01-01T00:00',
                'a class' => LocalDateTime::class,
                'a pattern the language has not' => '(?=a)',
            ];
        }
        if (str_contains($type, 'int')) {
            $wrong += ['zero' => 0, 'negative' => -1, 'beyond int32' => PHP_INT_MAX, 'the least int' => PHP_INT_MIN];
        }
        if (str_contains($type, 'array') || $type === 'mixed') {
            $wrong += [
                'empty array' => [],
                'list' => [0 => 'x'],
                'list of a resource' => [STDIN],
                'list of ints' => [1, 1],
                'map' => ['a' => 1],
                'map of a resource' => ['a' => STDIN],
                'map named by numbers' => [404 => 'x', 'a' => 1],
                'map of decoders' => ['a' => Decoders::int_(), 'b' => 'int'],
                'map with a non-UTF-8 name' => ["\xff" => 1],
            ];
        }
        if ($type === 'mixed') {
            $wrong += [
                'null' => null,
                'a resource' => STDIN,
                'an object' => new \stdClass(),
                'NaN' => NAN,
                'a string' => 'a',
                'a decimal' => Decimal::of('5', 1),
                'a date' => LocalDate::parse('2000-01-01'),
            ];
        }
        if (str_contains($type, 'object')) {
            $wrong += ['a date-time object' => LocalDateTime::parse('2000-01-01T00:00')];
        }
        if (str_contains($type, 'Closure') && str_contains($type, 'array')) {
            $wrong += ['a function of metadata' => static fn (): array => ['a' => 1]];
        }
        return $wrong;
    }

    /**
     * Element decoders that give each kind of value: of the model, a presence, an object of the
     * application (the same one each time, as a cache gives), and a resource.
     *
     * @return list<Decoder<mixed, mixed>>
     */
    private static function elements(): array
    {
        $shared = new \stdClass();
        return [
            Decoders::int_(),
            Decoders::object(Decoders::optionalNullableField('a', Decoders::int_())),
            Decoders::int_()->map(static fn (int $v): \stdClass => $shared),
            Decoders::int_()->map(static fn (int $v): mixed => STDIN),
        ];
    }

    /**
     * Inputs good and bad, so that each check a decoder makes runs and fails.
     *
     * @return array<string, mixed>
     */
    private static function inputs(): array
    {
        return [
            'null' => null,
            'absent' => new Absent(),
            '0' => 0,
            '1' => 1,
            '-1' => -1,
            '1.5' => 1.5,
            'NaN' => NAN,
            '"a"' => 'a',
            '""' => '',
            '"blank"' => 'blank',
            'a date' => '2000-01-01',
            'non-UTF-8' => "\xff",
            'true' => true,
            '[]' => [],
            '[1,2,2]' => [1, 2, 2],
            '[[],[]]' => [[], []],
            '{"a":1,"b":1}' => ['a' => 1, 'b' => 1],
            '{"a":1}' => ['a' => 1],
            '{"kind":"a","a":1}' => ['kind' => 'a', 'a' => 1],
            'a JSON object' => Json::parse('{"a":"x","b":1.50}'),
            'a JSON text' => '{"a":1}',
            'an object' => new \DateTimeImmutable(),
            'a resource' => STDIN,
        ];
    }

    private static function givesDecoder(\ReflectionMethod|\ReflectionFunction $entry): bool
    {
        $type = $entry->getReturnType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        $name = $type->getName();
        if (in_array($name, ['static', 'self'], true) && $entry instanceof \ReflectionMethod) {
            $name = $entry->getDeclaringClass()->getName();
        }
        return (class_exists($name) || interface_exists($name)) && is_a($name, Decoder::class, true);
    }
}
