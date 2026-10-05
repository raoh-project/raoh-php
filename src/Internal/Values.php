<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Absent;
use Raoh\Present;
use Raoh\PresentNull;
use Raoh\Value\Decimal;
use Raoh\Value\Float32;

/**
 * Sameness of values as the value model says (spec value-model.md), which `unique`, `contains`,
 * `containsAll`, `toSet` and `oneOf` compare by: +0 and -0 differ, every NaN is the same, a
 * decimal keeps its scale, and an offset date-time its offset.
 *
 * @internal
 */
final class Values
{
    private function __construct()
    {
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::key($a) === self::key($b);
    }

    /**
     * A string that two values share exactly when they are the same value.
     */
    public static function key(mixed $v): string
    {
        return match (true) {
            $v === null => 'n',
            is_bool($v) => $v ? 't' : 'f',
            is_int($v) => 'i' . $v,
            is_string($v) => 's' . $v,
            is_float($v) => 'd' . self::floatKey($v),
            $v instanceof Float32 => 'g' . self::floatKey($v->value),
            $v instanceof Decimal => 'm' . $v->coefficient() . 'e' . $v->scale(),
            $v instanceof Absent => 'A',
            $v instanceof PresentNull => 'N',
            $v instanceof Present => 'P' . self::key($v->value),
            $v instanceof \UnitEnum => 'u' . $v::class . '::' . $v->name,
            is_array($v) => self::arrayKey($v),
            $v instanceof \Stringable => 'o' . $v::class . ':' . $v,
            is_object($v) => 'r' . spl_object_id($v),
            default => throw new \InvalidArgumentException('no sameness for ' . get_debug_type($v)),
        };
    }

    /**
     * @param array<array-key, mixed> $v
     */
    private static function arrayKey(array $v): string
    {
        $parts = [];
        foreach ($v as $k => $e) {
            $key = self::key($e);
            $parts[] = strlen((string) $k) . ':' . $k . strlen($key) . ':' . $key;
        }
        if (!array_is_list($v)) {
            sort($parts);
        }
        return (array_is_list($v) ? 'l' : 'o') . implode('', $parts);
    }

    private static function floatKey(float $v): string
    {
        if (is_nan($v)) {
            return 'NaN';
        }
        // The bits tell -0 from +0, which == does not.
        return bin2hex(pack('E', $v));
    }

    /**
     * The first occurrence of each value, in order.
     *
     * @template T
     * @param list<T> $values
     * @return list<T>
     */
    public static function distinct(array $values): array
    {
        $seen = [];
        $out = [];
        foreach ($values as $v) {
            $key = self::key($v);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $v;
            }
        }
        return $out;
    }
}
