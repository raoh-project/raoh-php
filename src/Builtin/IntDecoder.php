<?php

declare(strict_types=1);

namespace Raoh\Builtin;

/**
 * A decoder of int32 values, held in PHP ints.
 *
 * @extends NumberDecoder<int>
 */
final class IntDecoder extends NumberDecoder
{
    public const MIN = -2147483648;
    public const MAX = 2147483647;

    public function multipleOf(int $divisor, ?string $message = null): static
    {
        return $this->multiple(self::value($divisor), $message);
    }

    /**
     * @param list<int> $allowed
     */
    public function oneOf(array $allowed, ?string $message = null): static
    {
        return $this->allowed($allowed, $message);
    }

    protected static function order(mixed $a, mixed $b): int
    {
        return $a <=> $b;
    }

    protected static function value(mixed $v): int
    {
        if (!is_int($v) || $v < self::MIN || $v > self::MAX) {
            throw new \InvalidArgumentException('not an int32: ' . var_export($v, true));
        }
        return $v;
    }

    protected static function least(): int
    {
        return 1;
    }

    protected static function greatest(): int
    {
        return -1;
    }

    protected static function zero(): int
    {
        return 0;
    }
}
