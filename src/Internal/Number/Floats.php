<?php

declare(strict_types=1);

namespace Raoh\Internal\Number;

/**
 * IEEE 754 binary32 and binary64 values as the Raoh Specification reads and writes them.
 *
 * A binary64 is a PHP float; a binary32 is the PHP float that equals it exactly. A width is 32 or
 * 64.
 *
 * @internal
 */
final class Floats
{
    /** 10^0 to 10^22, each an exact binary64. */
    private const POWERS_OF_TEN = [
        1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
        1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22,
    ];

    private const EXACT_INTEGER = 1 << 53;

    /** An exponent beyond this is as good as infinite: no digit string is that long. */
    private const HUGE_EXPONENT = 1_000_000_000_000_000;

    private function __construct()
    {
    }

    /**
     * The float of the given width nearest to ±digits × 10^exponent, rounding to nearest with ties
     * to even, once: ±INF where it rounds beyond the largest finite value, ±0.0 where it rounds to
     * zero. A binary32 is not rounded through a binary64 first.
     */
    public static function nearest(bool $negative, string $digits, int $exponent, int $width): float
    {
        [$precision, $minExponent, $maxExponent] = self::format($width);
        if ($digits === '' || strspn($digits, '0123456789') !== strlen($digits)) {
            throw new \InvalidArgumentException("not a string of decimal digits: '$digits'");
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return $negative ? -0.0 : 0.0;
        }
        $exponent = max(-self::HUGE_EXPONENT, min(self::HUGE_EXPONENT, $exponent));
        $trimmed = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($trimmed);
        $digits = $trimmed;
        $length = strlen($digits);

        if ($length <= 15) {
            $small = (int) $digits;
            // Clinger's fast path: an integer below 2^53 and a power of ten up to 10^22 are exact
            // binary64 values, so one multiplication or division rounds the number once.
            if ($width === 64 && $exponent >= -22 && $exponent <= 22) {
                $power = self::POWERS_OF_TEN[abs($exponent)];
                $value = $exponent >= 0 ? $small * $power : $small / $power;
                return $negative ? -$value : $value;
            }
            // A binary32 can take the same path only where the binary64 is exact, so that it
            // is rounded once, to binary32.
            if ($width === 32 && $exponent >= 0 && $exponent <= 15
                && $small <= intdiv(self::EXACT_INTEGER, 10 ** $exponent)) {
                $value = self::toFloat32((float) ($small * 10 ** $exponent));
                return $negative ? -$value : $value;
            }
        }

        // Far enough beyond either end of binary64 that the answer is known without arithmetic.
        $magnitude = $length - 1 + $exponent;
        if ($magnitude > 400) {
            return $negative ? -INF : INF;
        }
        if ($magnitude < -400) {
            return $negative ? -0.0 : 0.0;
        }
        $numerator = BigNat::fromDecimalString($digits);
        $denominator = BigNat::one();
        if ($exponent >= 0) {
            $numerator = $numerator->mul(BigNat::pow10($exponent));
        } else {
            $denominator = BigNat::pow10(-$exponent);
        }
        // The binary exponent of the leading bit: 2^e <= numerator / denominator < 2^(e+1).
        $e = $numerator->bitLength() - $denominator->bitLength();
        if (self::compareWithPowerOfTwo($numerator, $denominator, $e) < 0) {
            $e--;
        }
        $shift = max($e, $minExponent) - ($precision - 1);
        if ($shift >= 0) {
            $denominator = $denominator->shiftLeft($shift);
        } else {
            $numerator = $numerator->shiftLeft(-$shift);
        }
        [$quotient, $remainder] = $numerator->divMod($denominator);
        $mantissa = $quotient->toInt();
        $half = $remainder->shiftLeft(1)->compare($denominator);
        if ($half > 0 || ($half === 0 && ($mantissa & 1) === 1)) {
            $mantissa++;
        }
        if ($mantissa === 1 << $precision) {
            $mantissa >>= 1;
            $shift++;
        }
        if (self::bitLengthOf($mantissa) - 1 + $shift > $maxExponent) {
            return $negative ? -INF : INF;
        }
        $value = $mantissa * (2.0 ** $shift);
        return $negative ? -$value : $value;
    }

    /**
     * The float of the given width a JSON number lexeme (RFC 8259: -?int frac? exp?) denotes, read
     * exactly and rounded once. A minus sign and the value zero give -0.0.
     */
    public static function fromLexeme(string $lexeme, int $width): float
    {
        $parts = Lexeme::read($lexeme) ?? throw new \InvalidArgumentException("not a JSON number: '$lexeme'");
        [$negative, $integer, $fraction, $exponentSign, $exponentDigits] = $parts;
        $exponent = self::exponentOf($exponentSign, $exponentDigits);
        return self::nearest($negative, $integer . $fraction, $exponent - strlen($fraction), $width);
    }

    /** A binary64 rounded to the nearest binary32, held as a PHP float. */
    public static function toFloat32(float $value): float
    {
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('g', pack('g', $value));
        return $unpacked[1];
    }

    /**
     * The canonical decimal of a finite non-zero float, as the digits of c (no sign, no trailing
     * zero) and q, so that |value| is near c × 10^q: of the decimals of least length that round to
     * the float, the closest; where one digit is enough, the closest of those of one or two
     * digits. Of two equally close, the one with the even c.
     *
     * @return array{0: string, 1: int}
     */
    public static function canonicalDecimal(float $value, int $width): array
    {
        if (is_nan($value) || is_infinite($value) || $value == 0.0) {
            throw new \InvalidArgumentException('only a finite non-zero float has a canonical decimal');
        }
        [$precision, $minExponent] = self::format($width);
        $magnitude = abs($value);
        [$mantissa, $exponent] = self::decompose($magnitude, $width);
        $m = BigNat::fromInt($mantissa);

        // The decimals that round to the float lie between these bounds, in units of 2^(exponent-2).
        $lowestExponent = $minExponent - ($precision - 1);
        $narrowBelow = $mantissa === 1 << ($precision - 1) && $exponent > $lowestExponent;
        $low = BigNat::fromInt(4 * $mantissa - ($narrowBelow ? 1 : 2));
        $high = BigNat::fromInt(4 * $mantissa + 2);
        $inclusive = ($mantissa & 1) === 0;
        $rounds = static function (BigNat $k, int $grid) use ($low, $high, $exponent, $inclusive): bool {
            $above = self::compareScaled($k, $grid, $low, $exponent - 2);
            $below = self::compareScaled($k, $grid, $high, $exponent - 2);
            return $inclusive ? $above >= 0 && $below <= 0 : $above > 0 && $below < 0;
        };

        $leading = (int) floor(log10($magnitude));
        $one = BigNat::one();
        while (self::compareScaled($one, $leading, $m, $exponent) > 0) {
            $leading--;
        }
        while (self::compareScaled($one, $leading + 1, $m, $exponent) <= 0) {
            $leading++;
        }

        for ($length = 1; $length <= 20; $length++) {
            $grid = $leading - $length + 1;
            $candidates = self::bracket($m, $exponent, $grid, $rounds);
            if ($candidates === []) {
                continue;
            }
            if ($length === 1) {
                // One digit reaches the float, so two may come closer.
                $grid--;
                $candidates = self::bracket($m, $exponent, $grid, $rounds);
            }
            if (count($candidates) === 1) {
                return self::stripped($candidates[0], $grid);
            }
            [$floor, $ceiling] = $candidates;
            // Which is closer: compare the midpoint (2 floor + 1) × 10^grid with 2 × the float.
            $side = self::compareScaled($floor->shiftLeft(1)->add($one), $grid, $m, $exponent + 1);
            if ($side > 0) {
                return self::stripped($floor, $grid);
            }
            if ($side < 0) {
                return self::stripped($ceiling, $grid);
            }
            $floorDecimal = self::stripped($floor, $grid);
            $evenFloor = in_array($floorDecimal[0][strlen($floorDecimal[0]) - 1], ['0', '2', '4', '6', '8'], true);
            return $evenFloor ? $floorDecimal : self::stripped($ceiling, $grid);
        }
        throw new \LogicException("no short decimal rounds to $value");
    }

    /**
     * The message form of a float: its canonical decimal, plain with at least one digit after the
     * point where the exponent of its first digit is from -3 to 6, and otherwise a mantissa with at
     * least one digit after the point, `E` and the exponent; `0.0`, `-0.0`, `NaN`, `Infinity`,
     * `-Infinity`. This is what Java's Float.toString and Double.toString write.
     */
    public static function messageForm(float $value, int $width): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if ($value == 0.0) {
            return self::isNegativeZero($value) ? '-0.0' : '0.0';
        }
        $sign = $value < 0 ? '-' : '';
        [$digits, $exponent] = self::canonicalDecimal($value, $width);
        $length = strlen($digits);
        $first = $exponent + $length - 1;
        if ($first < -3 || $first > 6) {
            $rest = substr($digits, 1);
            return $sign . $digits[0] . '.' . ($rest === '' ? '0' : $rest) . 'E' . $first;
        }
        if ($exponent >= 0) {
            return $sign . $digits . str_repeat('0', $exponent) . '.0';
        }
        $point = $length + $exponent;
        if ($point > 0) {
            return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        return $sign . '0.' . str_repeat('0', -$point) . $digits;
    }

    /**
     * The observation of a float: for a finite value other than -0, the text of a JSON number
     * whose value is its canonical decimal (`"0.1"`, `"1.0E7"`, `"0"` for +0); otherwise the tag
     * `['float' => '-0']`, `['float' => 'NaN']`, `['float' => '+Infinity']` or
     * `['float' => '-Infinity']`.
     *
     * @return string|array{float: string}
     */
    public static function observation(float $value, int $width): string|array
    {
        if (is_nan($value)) {
            return ['float' => 'NaN'];
        }
        if (is_infinite($value)) {
            return ['float' => $value > 0 ? '+Infinity' : '-Infinity'];
        }
        if ($value == 0.0) {
            return self::isNegativeZero($value) ? ['float' => '-0'] : '0';
        }
        return self::messageForm($value, $width);
    }

    /**
     * -1, 0 or 1 as $a comes before, with or after $b in the float order: -INF, the negative
     * values, -0, +0, the positive values, +INF, and last NaN.
     */
    public static function compare(float $a, float $b): int
    {
        $aNaN = is_nan($a);
        $bNaN = is_nan($b);
        if ($aNaN || $bNaN) {
            return $aNaN === $bNaN ? 0 : ($aNaN ? 1 : -1);
        }
        if ($a != $b) {
            return $a < $b ? -1 : 1;
        }
        if ($a == 0.0) {
            $aNegative = self::isNegativeZero($a);
            $bNegative = self::isNegativeZero($b);
            return $aNegative === $bNegative ? 0 : ($aNegative ? -1 : 1);
        }
        return 0;
    }

    /** Whether the two are the same float: +0 and -0 differ, and every NaN is the same. */
    public static function same(float $a, float $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function isNegativeZero(float $value): bool
    {
        return $value == 0.0 && fdiv(1.0, $value) < 0;
    }

    /**
     * @return array{0: int, 1: int, 2: int} precision, least and greatest exponent
     */
    private static function format(int $width): array
    {
        return match ($width) {
            32 => [24, -126, 127],
            64 => [53, -1022, 1023],
            default => throw new \InvalidArgumentException("a float is 32 or 64 bits wide, not $width"),
        };
    }

    /** The exponent of a lexeme, held to ±HUGE_EXPONENT so that a huge one does not overflow. */
    private static function exponentOf(string $sign, string $digits): int
    {
        if ($digits === '') {
            return 0;
        }
        $digits = ltrim($digits, '0');
        $value = strlen($digits) > 15 ? self::HUGE_EXPONENT : (int) $digits;
        return $sign === '-' ? -$value : $value;
    }

    /**
     * A positive finite float of the given width as mantissa × 2^exponent, with the mantissa
     * below 2^precision and the exponent at least the least one of the width.
     *
     * @return array{0: int, 1: int}
     */
    private static function decompose(float $magnitude, int $width): array
    {
        [$precision, $minExponent] = self::format($width);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', pack('e', $magnitude));
        $bits = $unpacked[1];
        $biased = ($bits >> 52) & 0x7ff;
        $fraction = $bits & ((1 << 52) - 1);
        $mantissa = $biased === 0 ? $fraction : $fraction | (1 << 52);
        $exponent = ($biased === 0 ? 1 : $biased) - 1075;
        while (($mantissa & 1) === 0) {
            $mantissa >>= 1;
            $exponent++;
        }
        $lowest = $minExponent - ($precision - 1);
        while (self::bitLengthOf($mantissa) < $precision && $exponent > $lowest) {
            $mantissa <<= 1;
            $exponent--;
        }
        if (self::bitLengthOf($mantissa) > $precision || $exponent < $lowest) {
            throw new \InvalidArgumentException("$magnitude is not a float of width $width");
        }
        return [$mantissa, $exponent];
    }

    /**
     * The integers on the grid of 10^grid next to mantissa × 2^exponent that round to the float:
     * the one below or at it, then the one above it.
     *
     * @param \Closure(BigNat, int): bool $rounds
     * @return list<BigNat>
     */
    private static function bracket(BigNat $mantissa, int $exponent, int $grid, \Closure $rounds): array
    {
        $numerator = $mantissa;
        $denominator = BigNat::one();
        if ($exponent >= 0) {
            $numerator = $numerator->shiftLeft($exponent);
        } else {
            $denominator = $denominator->shiftLeft(-$exponent);
        }
        if ($grid >= 0) {
            $denominator = $denominator->mul(BigNat::pow10($grid));
        } else {
            $numerator = $numerator->mul(BigNat::pow10(-$grid));
        }
        [$floor, $remainder] = $numerator->divMod($denominator);
        $points = $remainder->isZero() ? [$floor] : [$floor, $floor->add(BigNat::one())];
        return array_values(array_filter($points, static fn (BigNat $k): bool => $rounds($k, $grid)));
    }

    /** The sign of k × 10^ten - b × 2^two. */
    private static function compareScaled(BigNat $k, int $ten, BigNat $b, int $two): int
    {
        $left = $k;
        $right = $b;
        if ($ten >= 0) {
            $left = $left->mul(BigNat::pow10($ten));
        } else {
            $right = $right->mul(BigNat::pow10(-$ten));
        }
        if ($two >= 0) {
            $right = $right->shiftLeft($two);
        } else {
            $left = $left->shiftLeft(-$two);
        }
        return $left->compare($right);
    }

    /** The sign of numerator / denominator - 2^e. */
    private static function compareWithPowerOfTwo(BigNat $numerator, BigNat $denominator, int $e): int
    {
        return $e >= 0
            ? $numerator->compare($denominator->shiftLeft($e))
            : $numerator->shiftLeft(-$e)->compare($denominator);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function stripped(BigNat $coefficient, int $exponent): array
    {
        $digits = $coefficient->toDecimalString();
        $trimmed = rtrim($digits, '0');
        return [$trimmed, $exponent + strlen($digits) - strlen($trimmed)];
    }

    private static function bitLengthOf(int $value): int
    {
        $bits = 0;
        while ($value > 0) {
            $bits++;
            $value >>= 1;
        }
        return $bits;
    }
}
