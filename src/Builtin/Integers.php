<?php

declare(strict_types=1);

namespace Raoh\Builtin;

/**
 * Reads an integer written in decimal digits within a range, without overflowing on the way.
 *
 * @internal
 */
final class Integers
{
    private function __construct()
    {
    }

    /**
     * The integer, or null when it is outside [min, max].
     *
     * @param string $digits ASCII digits with no leading zero, or "0"
     */
    public static function read(bool $negative, string $digits, int $min, int $max): ?int
    {
        $bound = $negative ? substr((string) $min, 1) : (string) $max;
        if ($negative && $min >= 0) {
            return $digits === '0' ? 0 : null;
        }
        if (strlen($digits) > strlen($bound)
            || (strlen($digits) === strlen($bound) && strcmp($digits, $bound) > 0)) {
            return null;
        }
        if ($negative && $digits === $bound) {
            return $min;
        }
        $n = (int) $digits;
        return $negative ? -$n : $n;
    }
}
