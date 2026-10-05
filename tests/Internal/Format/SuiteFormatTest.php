<?php

declare(strict_types=1);

namespace Raoh\Tests\Internal\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Internal\Format\Cuid;
use Raoh\Internal\Format\Email;
use Raoh\Internal\Format\Ip;
use Raoh\Internal\Format\Ulid;
use Raoh\Internal\Format\Uri;
use Raoh\Internal\Format\Uuid;

/**
 * Runs the format operations against the cases of the Raoh Specification's
 * suite/core/string.json whose decoder is exactly ["string", [OP]].
 *
 * The specification is looked up in $RAOH_SPEC_DIR, or next to this repository.
 */
final class SuiteFormatTest extends TestCase
{
    private const OPS = ['email', 'ipv4', 'ipv6', 'ip', 'ulid', 'cuid', 'uuid', 'uri', 'url'];

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function cases(): iterable
    {
        $dir = getenv('RAOH_SPEC_DIR');
        if ($dir === false || $dir === '') {
            $dir = dirname(__DIR__, 4) . '/raoh-specification';
        }
        $file = $dir . '/suite/core/string.json';
        if (!is_file($file)) {
            yield 'suite not found' => ['', '', null];
            return;
        }
        $cases = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $decoder = $case['decoder'];
            if (count($decoder) !== 2 || $decoder[0] !== 'string' || !is_array($decoder[1])) {
                continue;
            }
            $op = $decoder[1][0];
            if (!in_array($op, self::OPS, true) || !is_string($case['input'])) {
                continue;
            }
            if (array_key_exists('ok', $case)) {
                $expected = $case['ok'];
            } elseif (($case['issues'][0]['code'] ?? null) === 'invalid_format') {
                $expected = null;
            } else {
                continue;
            }
            yield $case['id'] . ' ' . $case['title'] => [$op, $case['input'], $expected];
        }
    }

    #[DataProvider('cases')]
    public function testSuiteCase(string $op, string $input, ?string $expected): void
    {
        if ($op === '') {
            $this->markTestSkipped('raoh-specification not found; set RAOH_SPEC_DIR');
        }
        if ($op === 'uuid') {
            $this->assertSame($expected, Uuid::read($input));
            return;
        }
        $accepted = match ($op) {
            'email' => Email::matches($input),
            'ipv4' => Ip::isV4($input),
            'ipv6' => Ip::isV6($input),
            'ip' => Ip::isIp($input),
            'ulid' => Ulid::matches($input),
            'cuid' => Cuid::matches($input),
            'uri' => Uri::isUri($input),
            'url' => Uri::isUrl($input),
            default => throw new \LogicException("unknown operation $op"),
        };
        if ($expected === null) {
            $this->assertFalse($accepted);
        } else {
            $this->assertSame($input, $expected, 'the operation gives the string unchanged');
            $this->assertTrue($accepted);
        }
    }
}
