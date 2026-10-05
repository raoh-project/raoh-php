<?php

declare(strict_types=1);

namespace Raoh\Internal\Format;

/**
 * A ULID in its canonical text form: 26 characters of Crockford's base 32
 * in either case, at most 7ZZZZZZZZZZZZZZZZZZZZZZZZZ.
 *
 * @internal
 */
final class Ulid
{
    private function __construct()
    {
    }

    public static function matches(string $s): bool
    {
        // 26 base-32 digits carry 130 bits; the value fits in 128 bits
        // exactly when the leading digit is 0 to 7.
        return preg_match('/\A[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}\z/', $s) === 1;
    }
}
