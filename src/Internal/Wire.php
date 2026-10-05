<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Internal\Number\Floats;
use Raoh\Issues;
use Raoh\Value\Decimal;
use Raoh\Value\Float32;

/**
 * Metadata as values `json_encode` writes the way the specification observes them: a decimal and
 * a temporal value as their text, a float that JSON cannot carry (-0, NaN, ±Infinity) as a tag
 * such as `{"float": "-0"}`, and the issues `one_of_failed` lists as issues are written.
 *
 * @internal
 */
final class Wire
{
    private function __construct()
    {
    }

    public static function of(mixed $v): mixed
    {
        return match (true) {
            $v === null, is_bool($v), is_int($v), is_string($v) => $v,
            is_float($v) => self::float($v, 64),
            $v instanceof Float32 => self::float($v->value, 32),
            $v instanceof Decimal => (string) $v,
            $v instanceof Issues => $v->toJsonList(),
            $v instanceof \UnitEnum => $v->name,
            is_array($v) => array_map(self::of(...), $v),
            $v instanceof \Stringable => (string) $v,
            default => $v,
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
