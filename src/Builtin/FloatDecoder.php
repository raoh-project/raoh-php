<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Arguments;
use Raoh\Internal\Number\Floats;
use Raoh\Value\Float32;

/**
 * A decoder of float32 values, each a {@see Float32}: PHP has no float32 of its own, and its
 * float is a float64, which {@see DoubleDecoder} gives.
 *
 * @extends NumberDecoder<Float32>
 */
final class FloatDecoder extends NumberDecoder
{
    /**
     * @param list<float|int|Float32> $allowed
     */
    public function oneOf(array $allowed, ?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->allowed($allowed, $message);
    }

    protected static function order(mixed $a, mixed $b): int
    {
        return Floats::compare($a->value, $b->value);
    }

    protected static function value(mixed $v): Float32
    {
        if ($v instanceof Float32) {
            return $v;
        }
        if (is_float($v) || is_int($v)) {
            return new Float32((float) $v);
        }
        throw new \InvalidArgumentException('not a float: ' . get_debug_type($v));
    }

    protected static function least(): Float32
    {
        return new Float32(0.0);
    }

    protected static function greatest(): Float32
    {
        return new Float32(0.0);
    }

    protected static function zero(): Float32
    {
        return new Float32(0.0);
    }
}
