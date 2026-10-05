<?php

declare(strict_types=1);

namespace Raoh\Internal\Format;

use Raoh\Internal\Text;

/**
 * A UUID as 32 hexadecimal digits in either case grouped 8-4-4-4-12 by hyphens
 * (RFC 9562 section 4), of any version and variant.
 *
 * @internal
 */
final class Uuid
{
    private function __construct()
    {
    }

    /**
     * Returns the UUID in lower case, or null when the string is not one.
     */
    public static function read(string $s): ?string
    {
        if (preg_match('/\A[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\z/', $s) !== 1) {
            return null;
        }
        return Text::asciiLower($s);
    }
}
