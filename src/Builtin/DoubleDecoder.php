<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Number\Floats;

/**
 * A decoder of float64 values, held in PHP floats.
 *
 * @extends NumberDecoder<float>
 */
final class DoubleDecoder extends NumberDecoder
{
    /**
     * @param list<float|int> $allowed
     */
    public function oneOf(array $allowed, ?string $message = null): static
    {
        return $this->allowed($allowed, $message);
    }

    protected static function order(mixed $a, mixed $b): int
    {
        return Floats::compare($a, $b);
    }

    protected static function value(mixed $v): float
    {
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        throw new \InvalidArgumentException('not a float: ' . get_debug_type($v));
    }

    protected static function least(): float
    {
        return 0.0;
    }

    protected static function greatest(): float
    {
        return 0.0;
    }

    protected static function zero(): float
    {
        return 0.0;
    }
}
