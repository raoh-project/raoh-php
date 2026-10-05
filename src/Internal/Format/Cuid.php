<?php

declare(strict_types=1);

namespace Raoh\Internal\Format;

/**
 * A CUID of version 1: "c" followed by 24 lower-case ASCII letters or digits.
 *
 * @internal
 */
final class Cuid
{
    private function __construct()
    {
    }

    public static function matches(string $s): bool
    {
        return preg_match('/\Ac[a-z0-9]{24}\z/', $s) === 1;
    }
}
