<?php

declare(strict_types=1);

namespace Raoh\Tests\Internal\Number;

use PHPUnit\Framework\TestCase;
use Raoh\Internal\Number\BigNat;

final class BigNatTest extends TestCase
{
    public function testDecimalRoundTrip(): void
    {
        foreach (['0', '1', '2147483647', '2147483648', '9223372036854775807', '123456789012345678901234567890', '1' . str_repeat('0', 400)] as $digits) {
            $this->assertSame($digits, BigNat::fromDecimalString($digits)->toDecimalString());
        }
        $this->assertSame('42', BigNat::fromDecimalString('00042')->toDecimalString());
        $this->assertSame('0', BigNat::fromDecimalString('000')->toDecimalString());
    }

    public function testArithmeticOnKnownValues(): void
    {
        $a = BigNat::fromDecimalString('123456789012345678901234567890');
        $b = BigNat::fromDecimalString('987654321098765432109876543210');
        $this->assertSame('1111111110111111111011111111100', $a->add($b)->toDecimalString());
        $this->assertSame('864197532086419753208641975320', $b->sub($a)->toDecimalString());
        $this->assertSame(
            '121932631137021795226185032733622923332237463801111263526900',
            $a->mul($b)->toDecimalString(),
        );
        [$q, $r] = $b->divMod($a);
        $this->assertSame('8', $q->toDecimalString());
        $this->assertSame('9000000000900000000090', $r->toDecimalString());
        $this->assertSame('1' . str_repeat('0', 30), BigNat::pow10(30)->toDecimalString());
        $this->assertSame(1024, BigNat::one()->shiftLeft(10)->toInt());
        $this->assertSame(11, BigNat::fromInt(1024)->bitLength());
        $this->assertSame(0, BigNat::zero()->bitLength());
        $this->assertSame(6, BigNat::fromInt(1000)->modSmall(7));
    }

    public function testAgreesWithIntArithmetic(): void
    {
        mt_srand(11);
        for ($i = 0; $i < 2000; $i++) {
            $x = mt_rand(0, PHP_INT_MAX >> 1);
            $y = mt_rand(1, $i % 2 === 0 ? 0x7fffffff : PHP_INT_MAX >> 1);
            $a = BigNat::fromInt($x);
            $b = BigNat::fromInt($y);
            $this->assertSame($x + $y, $a->add($b)->toInt());
            $this->assertSame(intdiv($x, $y), $a->divMod($b)[0]->toInt());
            $this->assertSame($x % $y, $a->divMod($b)[1]->toInt());
            $this->assertSame($x <=> $y, $a->compare($b));
            $this->assertSame((string) $x, $a->toDecimalString());
            $this->assertSame($x >> 7, $a->shiftRight(7)->toInt());
            if ($y < 0x7fffffff) {
                $this->assertSame($x % $y, $a->modSmall($y));
            }
        }
    }

    public function testDivisionIsConsistentOnLargeNumbers(): void
    {
        mt_srand(5);
        for ($i = 0; $i < 300; $i++) {
            $n = BigNat::fromDecimalString(self::randomDigits(mt_rand(1, 300)));
            $d = BigNat::fromDecimalString(self::randomDigits(mt_rand(1, 150)));
            if ($d->isZero()) {
                continue;
            }
            [$q, $r] = $n->divMod($d);
            $this->assertSame(-1, $r->compare($d));
            $this->assertSame(0, $q->mul($d)->add($r)->compare($n));
            $this->assertSame(0, $n->shiftLeft(77)->shiftRight(77)->compare($n));
            $this->assertSame(0, $n->add($d)->sub($d)->compare($n));
        }
    }

    public function testSubtractionBelowZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BigNat::fromInt(1)->sub(BigNat::fromInt(2));
    }

    private static function randomDigits(int $length): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) mt_rand(0, 9);
        }
        return $digits;
    }
}
