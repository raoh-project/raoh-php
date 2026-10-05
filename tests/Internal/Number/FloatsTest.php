<?php

declare(strict_types=1);

namespace Raoh\Tests\Internal\Number;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Internal\Number\Floats;

final class FloatsTest extends TestCase
{
    /**
     * Float.toString and Double.toString of JDK 19 and later, which write the canonical decimal.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function javaOutputs(): iterable
    {
        yield 'float 0.1' => ['0.1', 32, '0.1'];
        yield 'float 1.0E7' => ['1.0E7', 32, '1.0E7'];
        yield 'float least positive' => ['1.4E-45', 32, '1.4E-45'];
        yield 'float greatest' => ['3.4028235E38', 32, '3.4028235E38'];
        // JDK 19 and later; older JDKs wrote 1.17549435E-38, one digit longer than needed.
        yield 'float least normal' => ['1.17549435E-38', 32, '1.1754944E-38'];
        yield 'float 16777216' => ['16777216', 32, '1.6777216E7'];
        yield 'float 2e-44' => ['2e-44', 32, '2.0E-44'];
        yield 'double least positive' => ['4.9E-324', 64, '4.9E-324'];
        yield 'double 5e-324' => ['5e-324', 64, '4.9E-324'];
        yield 'double 1.0E-4' => ['1.0E-4', 64, '1.0E-4'];
        yield 'double 0.001' => ['0.001', 64, '0.001'];
        yield 'double 1234567.0' => ['1234567.0', 64, '1234567.0'];
        yield 'double 1.0E7' => ['1.0E7', 64, '1.0E7'];
        yield 'double 0.5' => ['0.5', 64, '0.5'];
        yield 'double 100' => ['100', 64, '100.0'];
        yield 'double greatest' => ['1.7976931348623157e308', 64, '1.7976931348623157E308'];
        yield 'double least normal' => ['2.2250738585072014E-308', 64, '2.2250738585072014E-308'];
        yield 'double 1e23' => ['1e23', 64, '1.0E23'];
        yield 'double 2^63' => ['9223372036854775808', 64, '9.223372036854776E18'];
        yield 'double -1.5' => ['-1.5', 64, '-1.5'];
    }

    #[DataProvider('javaOutputs')]
    public function testMessageFormMatchesJava(string $lexeme, int $width, string $expected): void
    {
        $this->assertSame($expected, Floats::messageForm(Floats::fromLexeme($lexeme, $width), $width));
    }

    public function testCanonicalDecimal(): void
    {
        $this->assertSame(['49', -325], Floats::canonicalDecimal(4.9E-324, 64));
        $this->assertSame(['14', -46], Floats::canonicalDecimal(Floats::fromLexeme('1.4e-45', 32), 32));
        $this->assertSame(['1', -1], Floats::canonicalDecimal(Floats::toFloat32(0.1), 32));
        $this->assertSame(['16777216', 0], Floats::canonicalDecimal(16777216.0, 32));
        $this->assertSame(['1', 7], Floats::canonicalDecimal(1.0E7, 64));
        $this->assertSame(['15', -1], Floats::canonicalDecimal(-1.5, 64));
    }

    public function testNearestRoundsOnceToBinary32(): void
    {
        // The midpoint between 1 and the next float32 rounds to even; a binary64 first would not.
        $this->assertSame(1.0, Floats::fromLexeme('1.000000059604644775390625', 32));
        $this->assertSame(1.00000011920928955078125, Floats::fromLexeme('1.0000000596046448', 32));
        $this->assertSame(1.00000011920928955078125, Floats::fromLexeme('1.000000059604644775390625000000001', 32));
        $this->assertSame(16777216.0, Floats::fromLexeme('16777217', 32));
        $this->assertSame(33554436.0, Floats::fromLexeme('33554435', 32));
        $this->assertSame(0.1, Floats::nearest(false, '1', -1, 64));
        $this->assertSame(-0.1, Floats::nearest(true, '0001000', -4, 64));
    }

    public function testOverflowAndUnderflow(): void
    {
        $this->assertSame(INF, Floats::fromLexeme('1e400', 64));
        $this->assertSame(-INF, Floats::fromLexeme('-1e39', 32));
        $this->assertSame(INF, Floats::fromLexeme('3.4028236e38', 32));
        $this->assertSame(INF, Floats::fromLexeme('1e99999999999999999999999', 64));
        $this->assertTrue(Floats::same(0.0, Floats::fromLexeme('1e-99999999999', 64)));
        $this->assertTrue(Floats::same(-0.0, Floats::fromLexeme('-1e-50', 32)));
        $this->assertTrue(Floats::same(-0.0, Floats::fromLexeme('-1e-400', 64)));
        $this->assertTrue(Floats::same(0.0, Floats::fromLexeme('0.0000e999999999999999999', 64)));
        $this->assertSame(1.7976931348623157e308, Floats::fromLexeme('1.7976931348623158e308', 64));
        $this->assertSame(1.0, Floats::fromLexeme('1' . str_repeat('0', 500) . 'e-500', 64));
    }

    public function testNegativeZeroLexemes(): void
    {
        foreach (['-0', '-0.0', '-0e0', '-0.000e10'] as $lexeme) {
            $this->assertTrue(Floats::isNegativeZero(Floats::fromLexeme($lexeme, 64)), $lexeme);
            $this->assertTrue(Floats::isNegativeZero(Floats::fromLexeme($lexeme, 32)), $lexeme);
        }
        $this->assertFalse(Floats::isNegativeZero(Floats::fromLexeme('0', 64)));
    }

    public function testRejectsWhatIsNotAJsonNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Floats::fromLexeme('01', 64);
    }

    public function testObservation(): void
    {
        $this->assertSame('0', Floats::observation(0.0, 64));
        $this->assertSame(['float' => '-0'], Floats::observation(-0.0, 64));
        $this->assertSame(['float' => 'NaN'], Floats::observation(NAN, 32));
        $this->assertSame(['float' => '+Infinity'], Floats::observation(INF, 64));
        $this->assertSame(['float' => '-Infinity'], Floats::observation(-INF, 32));
        $this->assertSame('0.1', Floats::observation(Floats::toFloat32(0.1), 32));
        $this->assertSame('1.0E7', Floats::observation(1.0E7, 64));
    }

    public function testMessageFormOfSpecialValues(): void
    {
        $this->assertSame('0.0', Floats::messageForm(0.0, 64));
        $this->assertSame('-0.0', Floats::messageForm(-0.0, 32));
        $this->assertSame('NaN', Floats::messageForm(NAN, 64));
        $this->assertSame('Infinity', Floats::messageForm(INF, 64));
        $this->assertSame('-Infinity', Floats::messageForm(-INF, 32));
    }

    public function testFloatOrder(): void
    {
        $ordered = [-INF, -2.0, -1.0, -0.0, 0.0, 1.0, 2.0, INF, NAN];
        foreach ($ordered as $i => $a) {
            foreach ($ordered as $j => $b) {
                $this->assertSame($i <=> $j, Floats::compare($a, $b), "$i vs $j");
                $this->assertSame($i === $j, Floats::same($a, $b));
            }
        }
        $this->assertTrue(Floats::same(NAN, -NAN));
    }

    public function testToFloat32(): void
    {
        $this->assertSame(0.100000001490116119384765625, Floats::toFloat32(0.1));
        $this->assertSame(16777216.0, Floats::toFloat32(16777217.0));
        $this->assertTrue(is_nan(Floats::toFloat32(NAN)));
    }

    /**
     * PHP writes the shortest decimal that reads back to a binary64; the canonical decimal is the
     * same except where one digit is enough, when it may take a second.
     */
    public function testAgreesWithPhpShortestRepresentation(): void
    {
        mt_srand(2024);
        for ($i = 0; $i < 3000; $i++) {
            $bits = (mt_rand(0, 0x7fefffff) << 32) | mt_rand(0, 0xffffffff);
            /** @var array{1: float} $unpacked */
            $unpacked = unpack('E', pack('J', $bits));
            $value = $unpacked[1];
            if ($value == 0.0) {
                continue;
            }
            [$digits, $exponent] = Floats::canonicalDecimal($value, 64);
            [$phpDigits, $phpExponent] = self::shortestOf($value);
            if (strlen($phpDigits) === 1) {
                $this->assertLessThanOrEqual(2, strlen($digits));
            } else {
                $this->assertSame([$phpDigits, $phpExponent], [$digits, $exponent], var_export($value, true));
            }
            $this->assertSame($value, Floats::fromLexeme($digits . 'e' . $exponent, 64));
        }
    }

    public function testFloat32RoundTrips(): void
    {
        mt_srand(32);
        for ($i = 0; $i < 3000; $i++) {
            /** @var array{1: float} $unpacked */
            $unpacked = unpack('g', pack('V', mt_rand(0, 0x7f7fffff)));
            $value = $unpacked[1];
            if ($value == 0.0) {
                continue;
            }
            [$digits, $exponent] = Floats::canonicalDecimal($value, 32);
            $this->assertLessThanOrEqual(9, strlen($digits));
            $this->assertSame($value, Floats::fromLexeme($digits . 'e' . $exponent, 32));
            $this->assertSame($value, Floats::fromLexeme(Floats::messageForm($value, 32), 32));
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function shortestOf(float $value): array
    {
        $text = var_export(abs($value), true);
        if (preg_match('/\A([0-9]+)(?:\.([0-9]+))?(?:E([+-]?[0-9]+))?\z/', $text, $m) !== 1) {
            throw new \LogicException("unexpected representation $text");
        }
        $fraction = $m[2] ?? '';
        $exponent = (int) ($m[3] ?? '0') - strlen($fraction);
        $digits = ltrim($m[1] . $fraction, '0');
        $trimmed = rtrim($digits, '0');
        return [$trimmed, $exponent + strlen($digits) - strlen($trimmed)];
    }
}
