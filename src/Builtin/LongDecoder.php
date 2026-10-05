<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Arguments;

/**
 * A decoder of int64 values, held in PHP ints.
 *
 * @extends NumberDecoder<int>
 */
final class LongDecoder extends NumberDecoder
{
    public function multipleOf(int $divisor, ?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->multiple($divisor, $message);
    }

    /**
     * @param list<int> $allowed
     */
    public function oneOf(array $allowed, ?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->allowed($allowed, $message);
    }

    protected static function order(mixed $a, mixed $b): int
    {
        return $a <=> $b;
    }

    protected static function value(mixed $v): int
    {
        if (!is_int($v)) {
            throw new \InvalidArgumentException('not an int64: ' . var_export($v, true));
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
