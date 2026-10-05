<?php

declare(strict_types=1);

namespace Raoh\Tests;

/**
 * Where the tests find the Raoh Specification's suite: the checkout `RAOH_SPECIFICATION_DIR` names,
 * as scripts/conformance.sh reads it, or else `raoh-specification` beside this repository.
 *
 * A test that reads the suite is skipped where there is none, so that a checkout of raoh-php alone
 * runs the rest. CI sets `RAOH_REQUIRE_SPECIFICATION=1`, under which a missing suite fails instead:
 * a skipped test there would pass on cases nobody ran.
 */
final class Specification
{
    private function __construct()
    {
    }

    /**
     * The path of a file of the suite, such as `suite/core/string.json`, or null where there is
     * no specification to read it from.
     */
    public static function file(string $relative): ?string
    {
        $dir = getenv('RAOH_SPECIFICATION_DIR');
        if ($dir === false || $dir === '') {
            $dir = dirname(__DIR__, 2) . '/raoh-specification';
        }
        $path = $dir . '/' . $relative;
        if (is_file($path)) {
            return $path;
        }
        if (getenv('RAOH_REQUIRE_SPECIFICATION') === '1') {
            throw new \RuntimeException("{$path} does not exist, and RAOH_REQUIRE_SPECIFICATION is set");
        }
        return null;
    }
}
