<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\PresentNull;
use Raoh\Present;
use Raoh\Absent;
use Raoh\Internal\Number\Floats;
use Raoh\Issues;
use Raoh\Value\Decimal;
use Raoh\Value\Float32;
use Raoh\Value\Temporal\Instant;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;
use Raoh\Value\Temporal\LocalTime;
use Raoh\Value\Temporal\OffsetDateTime;

/**
 * The values an issue's metadata holds, defined once: what {@see check()} admits is exactly what
 * {@see of()} writes as JSON, since both read a value through the one {@see kind()}.
 *
 * They are the values of the specification's value model as raoh-php holds them: null, a bool, an
 * int, a float (a float64) and a {@see Float32}, a string of Unicode scalar values, a
 * {@see Decimal}, the five temporal values, an enum case (written by its name), a presence
 * (`"absent"`, `"null"` or `{"present": v}`, as the specification observes one), the issues
 * `one_of_failed` lists, and lists and maps of these with names that are text. These are every
 * value a decoder of this library gives, so an operation may put what it decoded into an issue;
 * a test holds the decoders of the specification's suite to that. Nothing else is:
 * not a resource, not an object of another class, and not a Stringable, whose text could be
 * anything and could change between the issue's making and its writing.
 *
 * A decimal, a float32 and a temporal value write themselves as text that is ASCII; they are the
 * only objects whose string the library takes.
 *
 * @internal
 */
final class Wire
{
    /** The objects that are values of the model and write themselves as their text. */
    public const TEXT_VALUES = [
        Decimal::class,
        Float32::class,
        LocalDate::class,
        LocalTime::class,
        LocalDateTime::class,
        OffsetDateTime::class,
        Instant::class,
    ];

    private function __construct()
    {
    }

    /**
     * Refuses, with an \InvalidArgumentException, a value metadata cannot hold.
     */
    public static function check(mixed $v, string $what): void
    {
        $kind = self::kind($v);
        if ($kind === null) {
            throw new \InvalidArgumentException("{$what} is not a value an issue can hold: " . get_debug_type($v)
                . (is_string($v) ? ' that is not UTF-8' : ''));
        }
        if ($kind === 'presence' && $v instanceof Present) {
            self::check($v->value, $what);
        } elseif ($kind === 'map') {
            assert(is_array($v));
            self::checkMap($v, $what);
        } elseif ($kind === 'list') {
            assert(is_array($v));
            foreach ($v as $e) {
                self::check($e, $what);
            }
        }
    }

    /**
     * Refuses, with an \InvalidArgumentException, an array that is not a map from names to values
     * metadata can hold, as an issue's metadata itself is one. A name is a key read as the string it
     * is: PHP keys a name such as "404" as the int 404, which names the member "404" all the same.
     * A list, which PHP keys 0, 1, 2 ..., names nothing, and is refused unless it is empty.
     *
     * @param array<array-key, mixed> $map
     */
    public static function checkMap(array $map, string $what): void
    {
        if ($map !== [] && array_is_list($map)) {
            throw new \InvalidArgumentException("{$what} is a list; it has to name its values");
        }
        foreach ($map as $name => $value) {
            Arguments::text((string) $name, "a name in {$what}");
            self::check($value, "{$what} {$name}");
        }
    }

    /**
     * The value as metadata can hold it: itself where it is a value of the model, and otherwise
     * what it is in PHP's words (`App\Tag`, `resource (stream)`), as a decoder names the kind of
     * an input outside the model. An operation that puts a value it decoded into an issue, such as
     * the duplicates `unique()` lists, puts it through this: what a function given to `map()` made
     * is the application's, and no rule of this library says it can be written.
     */
    public static function describe(mixed $v): mixed
    {
        if ($v instanceof Present) {
            return new Present(self::describe($v->value));
        }
        if (is_array($v)) {
            // Each element as metadata holds it; names that are not text are no names a map has.
            return Input::members($v) === null && !array_is_list($v)
                ? Input::kind($v)
                : array_map(self::describe(...), $v);
        }
        return self::kind($v) === null ? Input::kind($v) : $v;
    }

    /**
     * The value as json_encode writes it the way the specification observes it: a decimal and a
     * temporal value as their text, a float JSON cannot carry (-0, NaN, ±Infinity) as a tag such as
     * `{"float": "-0"}`, and the issues `one_of_failed` lists as issues are written.
     */
    public static function of(mixed $v): mixed
    {
        return match (self::kind($v)) {
            'scalar' => $v,
            'float' => self::float($v, 64),
            'float32' => self::float($v->value, 32),
            'text' => (string) $v,
            'enum' => $v->name,
            'presence' => match (true) {
                $v instanceof Absent => 'absent',
                $v instanceof PresentNull => 'null',
                default => ['present' => self::of($v->value)],
            },
            'issues' => $v->toJsonList(),
            'list', 'map' => array_map(self::of(...), $v),
            default => throw new \LogicException(get_debug_type($v) . ' is not a value an issue can hold'),
        };
    }

    /**
     * Whether the value is one of those that write themselves as their text.
     */
    public static function isText(mixed $v): bool
    {
        return is_object($v) && in_array($v::class, self::TEXT_VALUES, true);
    }

    /**
     * Which of the metadata values it is, or null when it is none.
     */
    private static function kind(mixed $v): ?string
    {
        return match (true) {
            $v === null, is_bool($v), is_int($v) => 'scalar',
            is_string($v) => Input::text($v) === null ? null : 'scalar',
            is_float($v) => 'float',
            $v instanceof Float32 => 'float32',
            self::isText($v) => 'text',
            $v instanceof \UnitEnum => Input::text($v->name) === null ? null : 'enum',
            $v instanceof Absent, $v instanceof PresentNull => 'presence',
            $v instanceof Present => self::kind($v->value) === null ? null : 'presence',
            $v instanceof Issues => 'issues',
            is_array($v) => array_is_list($v) ? 'list' : 'map',
            default => null,
        };
    }

    /**
     * @return float|int|array{float: string}
     */
    private static function float(float $v, int $width): float|int|array
    {
        $o = Floats::observation($v, $width);
        if (is_array($o)) {
            return $o;
        }
        // A float32 as the binary64 nearest its canonical decimal, which json_encode writes as
        // that decimal: 0.1 rather than 0.10000000149011612.
        return $v === 0.0 ? 0 : (float) $o;
    }
}
