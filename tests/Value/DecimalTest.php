<?php

declare(strict_types=1);

namespace Raoh\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Value\Decimal;

final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function lexemes(): iterable
    {
        yield '1.50' => ['1.50', '150', 2];
        yield '1e3' => ['1e3', '1', -3];
        yield '1E+3' => ['1E+3', '1', -3];
        yield '-0.0' => ['-0.0', '0', 1];
        yield '-0' => ['-0', '0', 0];
        yield '0.0001' => ['0.0001', '1', 4];
        yield '-12.5e-2' => ['-12.5e-2', '-125', 3];
        yield '1e2147483648' => ['1e2147483648', '1', -2147483648];
        yield '1.5e2147483649' => ['1.5e2147483649', '15', -2147483648];
        yield '123456789012345678901234567890' => ['123456789012345678901234567890', '123456789012345678901234567890', 0];
    }

    #[DataProvider('lexemes')]
    public function testFromLexemeKeepsTheScale(string $lexeme, string $coefficient, int $scale): void
    {
        $decimal = Decimal::fromLexeme($lexeme);
        $this->assertNotNull($decimal);
        $this->assertSame($coefficient, $decimal->coefficient());
        $this->assertSame($scale, $decimal->scale());
    }

    public function testScaleOutsideInt32IsNull(): void
    {
        $this->assertNull(Decimal::fromLexeme('1e2147483649'));
        $this->assertNull(Decimal::fromLexeme('1e-2147483648'));
        $this->assertNull(Decimal::fromLexeme('1e-99999999999999999999999999'));
        $this->assertNull(Decimal::fromLexeme('1.0e2147483650'));
        $this->assertNotNull(Decimal::fromLexeme('1.0e2147483649'));
        $this->assertNotNull(Decimal::fromLexeme('1e-2147483647'));
    }

    public function testParse(): void
    {
        $this->assertTrue(Decimal::of('5', 1)->equals(Decimal::parse('.5') ?? Decimal::of('0', 0)));
        $this->assertTrue(Decimal::of('5', 0)->equals(Decimal::parse('5.') ?? Decimal::of('0', 0)));
        $this->assertTrue(Decimal::of('7', 2147483647)->equals(Decimal::parse('7E-2147483647') ?? Decimal::of('0', 0)));
        $this->assertTrue(Decimal::of('-150', 2)->equals(Decimal::parse('-001.50') ?? Decimal::of('0', 0)));
        $this->assertTrue(Decimal::of('3', -2)->equals(Decimal::parse('+3e+2') ?? Decimal::of('0', 0)));
        $this->assertTrue(Decimal::of('0', 0)->equals(Decimal::parse('-0') ?? Decimal::of('1', 0)));
        foreach (['', '.', '1..2', 'e5', '1e', '1.5e+', ' 1', '0x10', 'NaN', '--1', '1e2147483648x'] as $text) {
            $this->assertNull(Decimal::parse($text), $text);
        }
        $this->assertNull(Decimal::parse('1e-2147483648'));
    }

    public function testOf(): void
    {
        $decimal = Decimal::of('-000150', 2);
        $this->assertSame('-150', $decimal->coefficient());
        $this->assertSame(-1, $decimal->signum());
        $this->assertSame('0', Decimal::of('-0', 3)->coefficient());
        $this->assertSame(0, Decimal::of('-0', 3)->signum());
        $this->assertSame(1, Decimal::of('+12', 0)->signum());
    }

    public function testOfRejectsAScaleOutsideInt32(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::of('1', 2147483648);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function messageForms(): iterable
    {
        yield '0.00010' => ['10', 5, '0.00010'];
        yield '10' => ['10', 0, '10'];
        yield '1E+3' => ['1', -3, '1E+3'];
        yield '1.5E-7' => ['15', 8, '1.5E-7'];
        yield '0.000001' => ['1', 6, '0.000001'];
        yield '1E-7' => ['1', 7, '1E-7'];
        yield '12.5' => ['125', 1, '12.5'];
        yield '-1.5' => ['-15', 1, '-1.5'];
        yield '0' => ['0', 0, '0'];
        yield '0.0' => ['0', 1, '0.0'];
        yield '0E+3' => ['0', -3, '0E+3'];
        yield '0E-7' => ['0', 7, '0E-7'];
        yield '1.23E+5' => ['123', -3, '1.23E+5'];
        yield '-1.23E+5' => ['-123', -3, '-1.23E+5'];
        yield '7E-2147483647' => ['7', 2147483647, '7E-2147483647'];
        yield '1E+100' => ['1', -100, '1E+100'];
    }

    /** Java's BigDecimal.toString writes the same. */
    #[DataProvider('messageForms')]
    public function testMessageForm(string $coefficient, int $scale, string $expected): void
    {
        $this->assertSame($expected, (string) Decimal::of($coefficient, $scale));
    }

    public function testEqualsKeepsTheScale(): void
    {
        $a = Decimal::fromLexeme('1.5');
        $b = Decimal::fromLexeme('1.50');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertFalse($a->equals($b));
        $this->assertSame(0, $a->compareTo($b));
        $this->assertTrue($a->equals(Decimal::of('15', 1)));
    }

    public function testCompareTo(): void
    {
        $ordered = ['-1E+100', '-10', '-1.5', '-0.001', '0', '1E-2147483647', '0.001', '1.4', '1.5', '10', '1E+100', '1e2147483648'];
        foreach ($ordered as $i => $x) {
            foreach ($ordered as $j => $y) {
                $a = Decimal::fromLexeme($x);
                $b = Decimal::fromLexeme($y);
                $this->assertNotNull($a);
                $this->assertNotNull($b);
                $this->assertSame($i <=> $j, $a->compareTo($b), "$x vs $y");
            }
        }
        $this->assertSame(0, Decimal::of('0', -5)->compareTo(Decimal::of('0', 9)));
        $this->assertSame(0, Decimal::of('100', 2)->compareTo(Decimal::of('1', 0)));
        $this->assertSame(-1, Decimal::of('-100', 2)->compareTo(Decimal::of('-99', 2)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function multiples(): iterable
    {
        // From suite/core/decimal.json.
        yield '1.5 of 0.5' => ['1.5', '0.5', true];
        yield '1.2 of 0.5' => ['1.2', '0.5', false];
        yield '1e100 of 7E-2147483647' => ['1e100', '7E-2147483647', false];

        yield '0 of 7' => ['0', '7', true];
        yield '21 of 7' => ['21', '7', true];
        yield '-21 of 7' => ['-21', '7', true];
        yield '21 of -7' => ['21', '-7', true];
        yield '22 of 7' => ['22', '7', false];
        yield '1 of 1E-2147483647' => ['1', '1E-2147483647', true];
        yield '1 of 2E-2147483647' => ['1', '2E-2147483647', true];
        yield '1 of 3E-2147483647' => ['1', '3E-2147483647', false];
        yield '6 of 12E-1' => ['6', '12E-1', true];
        yield '0.6 of 0.12' => ['0.6', '0.12', true];
        yield '0.6 of 0.25' => ['0.6', '0.25', false];
        yield '1E+3 of 1000' => ['1E+3', '1000', true];
        yield '1E+3 of 1E+4' => ['1E+3', '1E+4', false];
        yield '1E+100 of 7E+50' => ['1E+100', '7E+50', false];
        yield '7E+100 of 7E+50' => ['7E+100', '7E+50', true];
        yield '14E+100 of 7E+50' => ['1.4E+101', '7E+50', true];
        yield '1e2147483647 of 1e-2147483647' => ['1e2147483647', '1e-2147483647', true];
        yield '1e-2147483647 of 1e2147483647' => ['1e-2147483647', '1e2147483647', false];
        yield '1.000 of 0.5' => ['1.000', '0.5', true];
        yield '1.000 of 0.0004' => ['1.000', '0.0004', true];
        yield '1.000 of 0.0003' => ['1.000', '0.0003', false];
        yield '1.25 of 0.125' => ['1.25', '0.125', true];
        yield '0.0016 of 2.56E-6' => ['0.0016', '2.56E-6', true];
    }

    #[DataProvider('multiples')]
    public function testIsMultipleOf(string $value, string $divisor, bool $expected): void
    {
        $a = Decimal::parse($value);
        $b = Decimal::parse($divisor);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($expected, $a->isMultipleOf($b));
    }

    public function testIsMultipleOfZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::of('1', 0)->isMultipleOf(Decimal::of('0', 2));
    }
}
