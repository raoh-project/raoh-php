<?php

declare(strict_types=1);

namespace Raoh\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Internal\Number\Floats;
use Raoh\Value\Decimal;

/**
 * Reads the input lexemes of the cases of suite/core/{float,double,decimal}.json whose decoder is
 * exactly ["float"], ["double"] or ["decimal"] and that succeed, and compares the value with the
 * case's observation. Cases with issues are left to the decoders.
 *
 * The specification is looked up in $RAOH_SPEC_DIR, or next to this repository.
 */
final class NumberSuiteTest extends TestCase
{
    private const FILES = ['float' => 'float.json', 'double' => 'double.json', 'decimal' => 'decimal.json'];

    /**
     * @return iterable<string, array{string, string, string|array<string, string>}>
     */
    public static function cases(): iterable
    {
        $dir = getenv('RAOH_SPEC_DIR');
        if ($dir === false || $dir === '') {
            $dir = dirname(__DIR__, 3) . '/raoh-specification';
        }
        foreach (self::FILES as $type => $name) {
            $file = $dir . '/suite/core/' . $name;
            if (!is_file($file)) {
                yield 'suite not found' => ['', '', ''];
                return;
            }
            $text = (string) file_get_contents($file);
            $cases = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            foreach ($cases as $case) {
                if ($case['decoder'] !== [$type] || !array_key_exists('ok', $case)) {
                    continue;
                }
                $raw = self::rawCase($text, $case['id']);
                $input = self::rawNumber($raw, 'input');
                if ($input === null) {
                    continue;
                }
                $ok = is_array($case['ok']) ? $case['ok'] : (self::rawNumber($raw, 'ok') ?? (string) $case['ok']);
                yield $case['id'] . ' ' . $case['title'] => [$type, $input, $ok];
            }
        }
    }

    /**
     * @param string|array<string, string> $expected
     */
    #[DataProvider('cases')]
    public function testSuiteCase(string $type, string $input, string|array $expected): void
    {
        if ($type === '') {
            $this->markTestSkipped('raoh-specification not found; set RAOH_SPEC_DIR');
        }
        if ($type === 'decimal') {
            $this->assertIsString($expected);
            $actual = Decimal::fromLexeme($input);
            $this->assertNotNull($actual);
            $this->assertTrue(Decimal::fromLexeme($expected)?->equals($actual), "$input gives $actual");
            $this->assertSame($expected, (string) $actual);
            return;
        }
        $width = $type === 'float' ? 32 : 64;
        $value = Floats::fromLexeme($input, $width);
        $observation = Floats::observation($value, $width);
        if (is_array($expected)) {
            $this->assertSame($expected, $observation);
            return;
        }
        $this->assertIsString($observation);
        $this->assertTrue(
            Floats::same(Floats::fromLexeme($expected, $width), Floats::fromLexeme($observation, $width)),
            "$input observed as $observation, expected $expected",
        );
        if ($observation === '0') {
            return;
        }
        // The observation is the canonical decimal, which the message form writes too.
        $this->assertSame(Floats::messageForm(Floats::fromLexeme($expected, $width), $width), $observation);
    }

    /** The text of the case object with the given id, up to the next case. */
    private static function rawCase(string $text, string $id): string
    {
        $start = strpos($text, '"id": "' . $id . '"');
        if ($start === false) {
            $start = (int) strpos($text, '"id":"' . $id . '"');
        }
        $end = strpos($text, '"id"', $start + 1);
        return substr($text, $start, $end === false ? null : $end - $start);
    }

    /** The lexeme of the member $name when its value is a number. */
    private static function rawNumber(string $raw, string $name): ?string
    {
        if (preg_match('/"' . $name . '"\s*:\s*(-?[0-9][0-9.eE+-]*)/', $raw, $m) !== 1) {
            return null;
        }
        return $m[1];
    }
}
