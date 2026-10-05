<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Absent;
use Raoh\Input\JsonNumber;
use Raoh\Input\JsonObject;

/**
 * Reads a PHP value as a value of the input model.
 *
 * What {@see \Raoh\Input\Json::parse()} gives is the input model itself. A decoder also reads what
 * an application already has: an associative array or a stdClass is an object, a list is an
 * array, and an int or a float is a number, read as the number it is and not as the text it once
 * was (`json_decode` has already turned `1.50` into 1.5). `[]` is both an empty object and an
 * empty array, since PHP does not tell them apart. {@see Absent} is the value of a member that is
 * not there.
 *
 * @internal
 */
final class Input
{
    private function __construct()
    {
    }

    public static function isAbsent(mixed $in): bool
    {
        return $in instanceof Absent;
    }

    /**
     * The kind of the value, as the `actual` of a type mismatch names it.
     */
    public static function kind(mixed $in): string
    {
        return match (true) {
            $in instanceof Absent => 'missing',
            $in === null => 'null',
            is_bool($in) => 'boolean',
            is_string($in) => 'string',
            $in instanceof JsonNumber, is_int($in), is_float($in) => 'number',
            is_array($in) => array_is_list($in) ? 'array' : 'object',
            $in instanceof JsonObject, $in instanceof \stdClass => 'object',
            default => throw self::outside($in),
        };
    }

    /**
     * The members of an object, or null when the value is not one.
     *
     * @return array<array-key, mixed>|null
     */
    public static function members(mixed $in): ?array
    {
        if ($in instanceof JsonObject) {
            return iterator_to_array($in->members());
        }
        if ($in instanceof \stdClass) {
            return get_object_vars($in);
        }
        if (is_array($in) && ($in === [] || !array_is_list($in))) {
            return $in;
        }
        return null;
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
     */
    public static function lexeme(mixed $in): ?string
    {
        if ($in instanceof JsonNumber) {
            return $in->lexeme;
        }
        if (is_int($in)) {
            return (string) $in;
        }
        if (is_float($in)) {
            if (!is_finite($in)) {
                throw self::outside($in);
            }
            // var_export writes the shortest text that reads back as the float, with a fraction.
            return var_export($in, true);
        }
        return null;
    }

    /**
     * Whether a member of that name is there, and its value.
     *
     * @param array<array-key, mixed> $members
     */
    public static function member(array $members, string $name): mixed
    {
        return array_key_exists($name, $members) ? $members[$name] : new Absent();
    }

    private static function outside(mixed $in): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'the input model has no place for ' . get_debug_type($in),
        );
    }
}
