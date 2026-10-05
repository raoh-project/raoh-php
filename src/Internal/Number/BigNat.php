<?php

declare(strict_types=1);

namespace Raoh\Internal\Number;

/**
 * An immutable natural number of any size, in pure PHP.
 *
 * The number is kept as limbs of 31 bits, least significant first, with no zero limb at the top,
 * so that the product of two limbs and a carry stays within a 64-bit int.
 *
 * @internal
 */
final class BigNat
{
    private const BITS = 31;
    private const BASE = 1 << self::BITS;
    private const MASK = self::BASE - 1;
    private const DECIMAL_CHUNK = 1_000_000_000;
    private const DECIMAL_CHUNK_DIGITS = 9;

    /** @var array<int, self> */
    private static array $powersOfTen = [];

    /** @var array<int, self> */
    private static array $powersOfFive = [];

    /**
     * @param list<int> $limbs
     */
    private function __construct(private readonly array $limbs)
    {
    }

    /**
     * @param list<int> $limbs
     */
    private static function normalized(array $limbs): self
    {
        $n = count($limbs);
        while ($n > 0 && $limbs[$n - 1] === 0) {
            array_pop($limbs);
            $n--;
        }
        return new self($limbs);
    }

    public static function zero(): self
    {
        return new self([]);
    }

    public static function one(): self
    {
        return new self([1]);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("a natural number is not negative: $value");
        }
        $limbs = [];
        while ($value > 0) {
            $limbs[] = $value & self::MASK;
            $value >>= self::BITS;
        }
        return new self($limbs);
    }

    /** From a string of decimal digits; leading zeros are allowed. */
    public static function fromDecimalString(string $digits): self
    {
        if ($digits === '' || strspn($digits, '0123456789') !== strlen($digits)) {
            throw new \InvalidArgumentException("not a string of decimal digits: '$digits'");
        }
        $digits = ltrim($digits, '0');
        $length = strlen($digits);
        if ($length === 0) {
            return self::zero();
        }
        $head = $length % self::DECIMAL_CHUNK_DIGITS;
        if ($head === 0) {
            $head = self::DECIMAL_CHUNK_DIGITS;
        }
        $limbs = self::fromInt((int) substr($digits, 0, $head))->limbs;
        for ($i = $head; $i < $length; $i += self::DECIMAL_CHUNK_DIGITS) {
            $limbs = self::mulAddSmall($limbs, self::DECIMAL_CHUNK, (int) substr($digits, $i, self::DECIMAL_CHUNK_DIGITS));
        }
        return self::normalized($limbs);
    }

    /** 10^n for n >= 0. */
    public static function pow10(int $n): self
    {
        if ($n < 0) {
            throw new \InvalidArgumentException("a negative power of ten is not natural: $n");
        }
        if (isset(self::$powersOfTen[$n])) {
            return self::$powersOfTen[$n];
        }
        $power = self::pow5($n)->shiftLeft($n);
        if ($n <= 2048) {
            self::$powersOfTen[$n] = $power;
        }
        return $power;
    }

    /** 5^n for n >= 0. */
    public static function pow5(int $n): self
    {
        if ($n < 0) {
            throw new \InvalidArgumentException("a negative power of five is not natural: $n");
        }
        if (isset(self::$powersOfFive[$n])) {
            return self::$powersOfFive[$n];
        }
        if ($n <= 13) {
            $power = self::fromInt(5 ** $n);
        } else {
            $half = self::pow5(intdiv($n, 2));
            $power = $half->mul($half);
            if ($n % 2 === 1) {
                $power = $power->mulSmall(5);
            }
        }
        if ($n <= 2048) {
            self::$powersOfFive[$n] = $power;
        }
        return $power;
    }

    public function toDecimalString(): string
    {
        if ($this->limbs === []) {
            return '0';
        }
        $chunks = [];
        $limbs = $this->limbs;
        while ($limbs !== []) {
            [$limbs, $remainder] = self::divModSmallLimbs($limbs, self::DECIMAL_CHUNK);
            $chunks[] = $remainder;
        }
        $text = (string) array_pop($chunks);
        while ($chunks !== []) {
            $text .= str_pad((string) array_pop($chunks), self::DECIMAL_CHUNK_DIGITS, '0', STR_PAD_LEFT);
        }
        return $text;
    }

    /** The number as an int; it must be at most PHP_INT_MAX. */
    public function toInt(): int
    {
        if ($this->bitLength() > 63) {
            throw new \OverflowException('the number does not fit in an int');
        }
        $value = 0;
        for ($i = count($this->limbs) - 1; $i >= 0; $i--) {
            $value = ($value << self::BITS) | $this->limbs[$i];
        }
        return $value;
    }

    public function isZero(): bool
    {
        return $this->limbs === [];
    }

    public function isOdd(): bool
    {
        return $this->limbs !== [] && ($this->limbs[0] & 1) === 1;
    }

    /** The number of bits, 0 for zero. */
    public function bitLength(): int
    {
        $n = count($this->limbs);
        if ($n === 0) {
            return 0;
        }
        $top = $this->limbs[$n - 1];
        $bits = 0;
        while ($top > 0) {
            $bits++;
            $top >>= 1;
        }
        return ($n - 1) * self::BITS + $bits;
    }

    /** -1, 0 or 1 as this is less than, equal to or greater than $other. */
    public function compare(self $other): int
    {
        $a = $this->limbs;
        $b = $other->limbs;
        $n = count($a);
        $m = count($b);
        if ($n !== $m) {
            return $n < $m ? -1 : 1;
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] < $b[$i] ? -1 : 1;
            }
        }
        return 0;
    }

    public function add(self $other): self
    {
        $a = $this->limbs;
        $b = $other->limbs;
        if (count($a) < count($b)) {
            [$a, $b] = [$b, $a];
        }
        $result = [];
        $carry = 0;
        $m = count($b);
        foreach ($a as $i => $limb) {
            $sum = $limb + ($i < $m ? $b[$i] : 0) + $carry;
            $result[] = $sum & self::MASK;
            $carry = $sum >> self::BITS;
        }
        if ($carry > 0) {
            $result[] = $carry;
        }
        return new self($result);
    }

    /** This less $other, which must not be greater than this. */
    public function sub(self $other): self
    {
        if ($this->compare($other) < 0) {
            throw new \InvalidArgumentException('a natural number cannot go below zero');
        }
        $b = $other->limbs;
        $m = count($b);
        $result = [];
        $borrow = 0;
        foreach ($this->limbs as $i => $limb) {
            $diff = $limb - ($i < $m ? $b[$i] : 0) - $borrow;
            if ($diff < 0) {
                $diff += self::BASE;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result[] = $diff;
        }
        return self::normalized($result);
    }

    /** This times $factor, for 0 <= $factor. */
    public function mulSmall(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException("a natural number is not negative: $factor");
        }
        if ($factor >= self::BASE) {
            return $this->mul(self::fromInt($factor));
        }
        if ($factor === 0 || $this->limbs === []) {
            return self::zero();
        }
        return new self(self::mulAddSmall($this->limbs, $factor, 0));
    }

    public function mul(self $other): self
    {
        $a = $this->limbs;
        $b = $other->limbs;
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            return self::zero();
        }
        $result = array_fill(0, $n + $m, 0);
        for ($i = 0; $i < $n; $i++) {
            $carry = 0;
            $ai = $a[$i];
            if ($ai === 0) {
                continue;
            }
            for ($j = 0; $j < $m; $j++) {
                $t = $result[$i + $j] + $ai * $b[$j] + $carry;
                $result[$i + $j] = $t & self::MASK;
                $carry = $t >> self::BITS;
            }
            $k = $i + $m;
            while ($carry > 0) {
                $t = $result[$k] + $carry;
                $result[$k] = $t & self::MASK;
                $carry = $t >> self::BITS;
                $k++;
            }
        }
        return self::normalized(array_values($result));
    }

    /** This times 2^$bits, for $bits >= 0. */
    public function shiftLeft(int $bits): self
    {
        if ($bits < 0) {
            throw new \InvalidArgumentException("a shift is not negative: $bits");
        }
        if ($bits === 0 || $this->limbs === []) {
            return $this;
        }
        $whole = intdiv($bits, self::BITS);
        $part = $bits % self::BITS;
        $result = array_fill(0, $whole, 0);
        if ($part === 0) {
            return new self(array_merge($result, $this->limbs));
        }
        $carry = 0;
        foreach ($this->limbs as $limb) {
            $t = ($limb << $part) | $carry;
            $result[] = $t & self::MASK;
            $carry = $t >> self::BITS;
        }
        if ($carry > 0) {
            $result[] = $carry;
        }
        return new self($result);
    }

    /** This divided by 2^$bits, rounded down, for $bits >= 0. */
    public function shiftRight(int $bits): self
    {
        if ($bits < 0) {
            throw new \InvalidArgumentException("a shift is not negative: $bits");
        }
        if ($bits === 0) {
            return $this;
        }
        $whole = intdiv($bits, self::BITS);
        $part = $bits % self::BITS;
        $limbs = array_slice($this->limbs, $whole);
        if ($part === 0 || $limbs === []) {
            return new self($limbs);
        }
        $result = [];
        $n = count($limbs);
        for ($i = 0; $i < $n; $i++) {
            $high = $i + 1 < $n ? $limbs[$i + 1] : 0;
            $result[] = (($limbs[$i] >> $part) | ($high << (self::BITS - $part))) & self::MASK;
        }
        return self::normalized($result);
    }

    /**
     * The quotient and remainder of this divided by $divisor, which must not be zero.
     *
     * @return array{0: self, 1: self}
     */
    public function divMod(self $divisor): array
    {
        $v = $divisor->limbs;
        $n = count($v);
        if ($n === 0) {
            throw new \DivisionByZeroError('division by zero');
        }
        if ($this->compare($divisor) < 0) {
            return [self::zero(), $this];
        }
        if ($n === 1) {
            [$quotient, $remainder] = self::divModSmallLimbs($this->limbs, $v[0]);
            return [self::normalized($quotient), self::fromInt($remainder)];
        }
        // Knuth's algorithm D, with the divisor shifted so that its top limb has its top bit set.
        $shift = self::BITS - self::topBits($v[$n - 1]);
        $v = $divisor->shiftLeft($shift)->limbs;
        $u = $this->shiftLeft($shift)->limbs;
        $u[] = 0;
        $m = count($u) - $n - 1;
        $q = array_fill(0, $m + 1, 0);
        $vTop = $v[$n - 1];
        $vNext = $v[$n - 2];
        for ($j = $m; $j >= 0; $j--) {
            $numerator = ($u[$j + $n] << self::BITS) | $u[$j + $n - 1];
            $qhat = intdiv($numerator, $vTop);
            $rhat = $numerator - $qhat * $vTop;
            while ($qhat >= self::BASE || $qhat * $vNext > (($rhat << self::BITS) | $u[$j + $n - 2])) {
                $qhat--;
                $rhat += $vTop;
                if ($rhat >= self::BASE) {
                    break;
                }
            }
            $borrow = 0;
            $carry = 0;
            for ($i = 0; $i < $n; $i++) {
                $p = $qhat * $v[$i] + $carry;
                $carry = $p >> self::BITS;
                $t = $u[$i + $j] - ($p & self::MASK) - $borrow;
                if ($t < 0) {
                    $t += self::BASE;
                    $borrow = 1;
                } else {
                    $borrow = 0;
                }
                $u[$i + $j] = $t;
            }
            $t = $u[$j + $n] - $carry - $borrow;
            if ($t < 0) {
                // qhat was one too large: add the divisor back.
                $u[$j + $n] = $t + self::BASE;
                $qhat--;
                $carry = 0;
                for ($i = 0; $i < $n; $i++) {
                    $s = $u[$i + $j] + $v[$i] + $carry;
                    $u[$i + $j] = $s & self::MASK;
                    $carry = $s >> self::BITS;
                }
                $u[$j + $n] = ($u[$j + $n] + $carry) & self::MASK;
            } else {
                $u[$j + $n] = $t;
            }
            $q[$j] = $qhat;
        }
        $remainder = self::normalized(array_slice($u, 0, $n))->shiftRight($shift);
        return [self::normalized(array_values($q)), $remainder];
    }

    /**
     * The quotient and remainder of this divided by $divisor, for 0 < $divisor < 2^31.
     *
     * @return array{0: self, 1: int}
     */
    public function divModSmall(int $divisor): array
    {
        if ($divisor <= 0 || $divisor >= self::BASE) {
            throw new \InvalidArgumentException("a small divisor is from 1 to 2^31-1: $divisor");
        }
        [$quotient, $remainder] = self::divModSmallLimbs($this->limbs, $divisor);
        return [self::normalized($quotient), $remainder];
    }

    /** This modulo $divisor, for 0 < $divisor < 2^31. */
    public function modSmall(int $divisor): int
    {
        if ($divisor <= 0 || $divisor >= self::BASE) {
            throw new \InvalidArgumentException("a small divisor is from 1 to 2^31-1: $divisor");
        }
        $remainder = 0;
        for ($i = count($this->limbs) - 1; $i >= 0; $i--) {
            $remainder = (($remainder << self::BITS) | $this->limbs[$i]) % $divisor;
        }
        return $remainder;
    }

    private static function topBits(int $limb): int
    {
        $bits = 0;
        while ($limb > 0) {
            $bits++;
            $limb >>= 1;
        }
        return $bits;
    }

    /**
     * @param list<int> $limbs
     * @return list<int>
     */
    private static function mulAddSmall(array $limbs, int $factor, int $addend): array
    {
        $result = [];
        $carry = $addend;
        foreach ($limbs as $limb) {
            $t = $limb * $factor + $carry;
            $result[] = $t & self::MASK;
            $carry = $t >> self::BITS;
        }
        while ($carry > 0) {
            $result[] = $carry & self::MASK;
            $carry >>= self::BITS;
        }
        return $result;
    }

    /**
     * @param list<int> $limbs
     * @return array{0: list<int>, 1: int}
     */
    private static function divModSmallLimbs(array $limbs, int $divisor): array
    {
        $n = count($limbs);
        $result = $n === 0 ? [] : array_fill(0, $n, 0);
        $remainder = 0;
        for ($i = $n - 1; $i >= 0; $i--) {
            $current = ($remainder << self::BITS) | $limbs[$i];
            $digit = intdiv($current, $divisor);
            $result[$i] = $digit;
            $remainder = $current - $digit * $divisor;
        }
        while ($n > 0 && $result[$n - 1] === 0) {
            array_pop($result);
            $n--;
        }
        return [array_values($result), $remainder];
    }
}
