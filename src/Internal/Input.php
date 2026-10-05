<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Result;
use Raoh\Path;
use Raoh\Err;
use Raoh\Absent;
use Raoh\Input\JsonNumber;
use Raoh\Input\JsonObject;
use Raoh\Internal\Number\Floats;
use Raoh\Notation199x\ScalarValues;

/**
 * Reads a PHP value as a value of the input model. Every decoder asks this class, and nothing
 * else, what its input is, so that what a PHP value counts as is decided in one place.
 *
 * What {@see \Raoh\Input\Json::parse()} gives is the input model itself. A decoder also reads what
 * an application already has: an associative array or a stdClass is an object, a list is an
 * array, and an int or a float is a number, read as the number it is and not as the text it once
 * was (`json_decode` has already turned `1.50` into 1.5). `[]` is both an empty object and an
 * empty array, since PHP does not tell them apart. {@see Absent} is the value of a member that is
 * not there.
 *
 * Every function here is total: it answers for any PHP value and never throws. A value the input
 * model has no place for is of no kind a decoder reads, so every decoder gives `type_mismatch`
 * for it, whose `actual` names what was found in PHP's words, as the specification lets an
 * implementation that reads host values say: an object of another class (`DateTimeImmutable`, an
 * uploaded file a framework left in a request array), a resource, a float that is NaN or
 * infinite, a string that is not UTF-8, and an array with a key that is not UTF-8. A decoder
 * therefore reports bad input as an issue whatever it is handed.
 *
 * @internal
 */
final class Input
{
    private function __construct()
    {
    }

    /**
     * The kind of the value, as the `actual` of a type mismatch names it: one of the kinds of the
     * input model, or what a value outside it is.
     */
    public static function kind(mixed $in): string
    {
        return match (true) {
            $in instanceof Absent => 'missing',
            $in === null => 'null',
            is_bool($in) => 'boolean',
            is_string($in) => self::isText($in) ? 'string' : 'non-UTF-8 string',
            $in instanceof JsonNumber, is_int($in) => 'number',
            is_float($in) => is_finite($in) ? 'number' : (is_nan($in) ? 'NAN' : 'INF'),
            is_array($in) => !self::hasTextKeys($in)
                ? 'array with a non-UTF-8 key'
                : (array_is_list($in) ? 'array' : 'object'),
            $in instanceof JsonObject, $in instanceof \stdClass => 'object',
            default => get_debug_type($in),
        };
    }

    /**
     * The value as text, or null when it is not a string of Unicode scalar values.
     */
    public static function text(mixed $in): ?string
    {
        return is_string($in) && self::isText($in) ? $in : null;
    }

    /**
     * The members of an object, by name, or null when the value is not one.
     *
     * @return array<array-key, mixed>|null
     */
    public static function members(mixed $in): ?array
    {
        if ($in instanceof JsonObject) {
            return iterator_to_array($in->members());
        }
        if ($in instanceof \stdClass) {
            $in = get_object_vars($in);
        } elseif (!is_array($in) || ($in !== [] && array_is_list($in))) {
            return null;
        }
        return self::hasTextKeys($in) ? $in : null;
    }

    /**
     * The elements of an array, or null when the value is not one.
     *
     * @return list<mixed>|null
     */
    public static function elements(mixed $in): ?array
    {
        return is_array($in) && array_is_list($in) ? $in : null;
    }

    /**
     * The lexeme of a number, or null when the value is not one.
     *
     * A PHP float is written as the canonical decimal of the float64 it is, by the specification's
     * own algorithm, with a fraction or an exponent so that it is never read as an integer. Nothing
     * PHP prints a float with is used: what `var_export`, `json_encode` and a string cast write
     * follows the `serialize_precision` and `precision` settings of the php.ini in use.
     */
    public static function lexeme(mixed $in): ?string
    {
        return match (true) {
            $in instanceof JsonNumber => $in->lexeme,
            is_int($in) => (string) $in,
            is_float($in) && is_finite($in) => Floats::messageForm($in, 64),
            default => null,
        };
    }

    /**
     * Whether there is no value: null, or a member that is not there. The decoders of values give
     * `required` for both.
     */
    public static function isNothing(mixed $in): bool
    {
        return $in === null || $in instanceof Absent;
    }

    /**
     * What a decoder of values gives for input it does not read: `required` for nothing, and
     * otherwise `type_mismatch` naming the kind it expected and the kind it found. Every decoder
     * that reports the kind of its input reports it through this.
     *
     * @return Err<never>
     */
    public static function mismatch(mixed $in, Path $p, string $expected): Err
    {
        if (self::isNothing($in)) {
            return Result::issue($p, 'required');
        }
        return Result::issue($p, 'type_mismatch', ['expected' => $expected, 'actual' => self::kind($in)]);
    }

    /**
     * The value of the member of that name, or {@see Absent} when it is not there.
     *
     * @param array<array-key, mixed> $members
     */
    public static function member(array $members, string $name): mixed
    {
        return array_key_exists($name, $members) ? $members[$name] : new Absent();
    }

    private static function isText(string $s): bool
    {
        return ScalarValues::invalidUtf8At($s) === null;
    }

    /**
     * @param array<array-key, mixed> $a
     */
    private static function hasTextKeys(array $a): bool
    {
        foreach ($a as $k => $_) {
            if (is_string($k) && !self::isText($k)) {
                return false;
            }
        }
        return true;
    }
}
