<?php

declare(strict_types=1);

namespace Raoh\Value;

use Raoh\Internal\Number\BigNat;

/**
 * A decimal number: an integer coefficient and an int32 scale, worth coefficient × 10^-scale.
 *
 * The scale is part of the value, so 1.5 and 1.50 are different decimals; compareTo() compares
 * them by number. The coefficient is kept as decimal digits, with a leading `-` when negative, no
 * leading zero, and never `-0`.
 */
final readonly class Decimal
{
    private const SCALE_MIN = -2147483648;
    private const SCALE_MAX = 2147483647;

    private function __construct(
        private string $coefficient,
        private int $scale,
    ) {
    }

    /** The decimal coefficient × 10^-scale. */
    public static function of(string $coefficient, int $scale): self
    {
        if (preg_match('/\A([+-]?)([0-9]+)\z/', $coefficient, $m) !== 1) {
            throw new \InvalidArgumentException("not an integer: '$coefficient'");
        }
        if ($scale < self::SCALE_MIN || $scale > self::SCALE_MAX) {
            throw new \InvalidArgumentException("a scale is an int32, not $scale");
        }
        return self::normalized($m[1] === '-', $m[2], $scale);
    }

    /**
     * The decimal a JSON number lexeme (RFC 8259) denotes, with the scale the lexeme gives: the
     * digits after the point less the exponent. Null when that scale is not an int32.
     */
    public static function fromLexeme(string $jsonNumber): ?self
    {
        if (preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?)([0-9]+))?\z/', $jsonNumber, $m) !== 1) {
            throw new \InvalidArgumentException("not a JSON number: '$jsonNumber'");
        }
        return self::fromParts($m[1] === '-', $m[2], $m[3] ?? '', $m[4] ?? '', $m[5] ?? '');
    }

    /**
     * The decimal a text of `[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?` denotes, with the
     * same scale rule as fromLexeme(). Null when the text does not match or the scale is not an
     * int32.
     */
    public static function parse(string $text): ?self
    {
        if (preg_match('/\A([+-]?)(?:([0-9]+)(?:\.([0-9]*))?|\.([0-9]+))(?:[eE]([+-]?)([0-9]+))?\z/', $text, $m) !== 1) {
            return null;
        }
        $integer = $m[2] ?? '';
        $fraction = ($m[3] ?? '') . ($m[4] ?? '');
        return self::fromParts($m[1] === '-', $integer, $fraction, $m[5] ?? '', $m[6] ?? '');
    }

    public function coefficient(): string
    {
        return $this->coefficient;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    /** -1, 0 or 1 as the decimal is negative, zero or positive. */
    public function signum(): int
    {
        if ($this->coefficient === '0') {
            return 0;
        }
        return $this->coefficient[0] === '-' ? -1 : 1;
    }

    /** -1, 0 or 1 as this is numerically less than, equal to or greater than $other. */
    public function compareTo(self $other): int
    {
        $sign = $this->signum();
        $otherSign = $other->signum();
        if ($sign !== $otherSign) {
            return $sign < $otherSign ? -1 : 1;
        }
        if ($sign === 0) {
            return 0;
        }
        $digits = $this->digits();
        $otherDigits = $other->digits();
        $adjusted = strlen($digits) - 1 - $this->scale;
        $otherAdjusted = strlen($otherDigits) - 1 - $other->scale;
        if ($adjusted !== $otherAdjusted) {
            $magnitude = $adjusted < $otherAdjusted ? -1 : 1;
        } else {
            $length = max(strlen($digits), strlen($otherDigits));
            $magnitude = strcmp(str_pad($digits, $length, '0'), str_pad($otherDigits, $length, '0')) <=> 0;
        }
        return $sign * $magnitude;
    }

    /** The same decimal: coefficient and scale both equal. */
    public function equals(self $other): bool
    {
        return $this->coefficient === $other->coefficient && $this->scale === $other->scale;
    }

    /** Whether this is an integer multiple of $divisor, which must not be zero. */
    public function isMultipleOf(self $divisor): bool
    {
        if ($divisor->signum() === 0) {
            throw new \InvalidArgumentException('the divisor of a multiple is not zero');
        }
        if ($this->signum() === 0) {
            return true;
        }
        $a = $this->digits();
        $b = $divisor->digits();
        // this / divisor = (a / b) × 10^k
        $k = $divisor->scale - $this->scale;
        if ($k < 0) {
            // b × 10^-k must divide a: a ends in -k zeros, and b divides what is left.
            if (-$k >= strlen($a) || strspn(strrev($a), '0') < -$k) {
                return false;
            }
            $a = substr($a, 0, strlen($a) + $k);
            return self::divides(BigNat::fromDecimalString($b), BigNat::fromDecimalString($a));
        }
        // b divides a × 10^k: the part of b prime to 10 divides a, and a × 10^k has at least as
        // many factors 2 and 5 as b.
        $aNat = BigNat::fromDecimalString($a);
        [$bTwos, $bRest] = self::factorOut(BigNat::fromDecimalString($b), 2);
        [$bFives, $bRest] = self::factorOut($bRest, 5);
        if (!self::divides($bRest, $aNat)) {
            return false;
        }
        if ($bTwos > $k && self::factorOut($aNat, 2, $bTwos - $k)[0] + $k < $bTwos) {
            return false;
        }
        if ($bFives > $k && self::factorOut($aNat, 5, $bFives - $k)[0] + $k < $bFives) {
            return false;
        }
        return true;
    }

    /**
     * The message form, as Java's BigDecimal.toString writes it: plain when the scale is not
     * negative and the adjusted exponent is at least -6 (`0.00010`, `10`), and otherwise the first
     * digit, a point and the others when there are any, `E`, a sign and the adjusted exponent
     * (`1E+3`, `1.5E-7`). It is also the text of the decimal's observation.
     */
    public function __toString(): string
    {
        $digits = $this->digits();
        $sign = $this->signum() < 0 ? '-' : '';
        $length = strlen($digits);
        $adjusted = $length - 1 - $this->scale;
        if ($this->scale >= 0 && $adjusted >= -6) {
            if ($this->scale === 0) {
                return $sign . $digits;
            }
            if ($length > $this->scale) {
                return $sign . substr($digits, 0, $length - $this->scale) . '.' . substr($digits, $length - $this->scale);
            }
            return $sign . '0.' . str_repeat('0', $this->scale - $length) . $digits;
        }
        $mantissa = $length > 1 ? $digits[0] . '.' . substr($digits, 1) : $digits;
        return $sign . $mantissa . 'E' . ($adjusted >= 0 ? '+' : '') . $adjusted;
    }

    /** The digits of the coefficient, without its sign. */
    private function digits(): string
    {
        return ltrim($this->coefficient, '-');
    }

    private static function normalized(bool $negative, string $digits, int $scale): self
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return new self('0', $scale);
        }
        return new self(($negative ? '-' : '') . $digits, $scale);
    }

    private static function fromParts(
        bool $negative,
        string $integer,
        string $fraction,
        string $exponentSign,
        string $exponentDigits,
    ): ?self {
        $exponentDigits = ltrim($exponentDigits, '0');
        // An exponent of more digits than this puts the scale beyond an int32 for any lexeme.
        if (strlen($exponentDigits) > 15) {
            return null;
        }
        $exponent = $exponentDigits === '' ? 0 : (int) $exponentDigits;
        $scale = strlen($fraction) - ($exponentSign === '-' ? -$exponent : $exponent);
        if ($scale < self::SCALE_MIN || $scale > self::SCALE_MAX) {
            return null;
        }
        return self::normalized($negative, $integer . $fraction, $scale);
    }

    private static function divides(BigNat $divisor, BigNat $dividend): bool
    {
        return $dividend->divMod($divisor)[1]->isZero();
    }

    /**
     * How many times $prime divides $n, counting up to $limit, and what is left of $n.
     *
     * @return array{0: int, 1: BigNat}
     */
    private static function factorOut(BigNat $n, int $prime, int $limit = PHP_INT_MAX): array
    {
        $count = 0;
        while ($count < $limit && !$n->isZero()) {
            [$quotient, $remainder] = $n->divModSmall($prime);
            if ($remainder !== 0) {
                break;
            }
            $n = $quotient;
            $count++;
        }
        return [$count, $n];
    }
}
