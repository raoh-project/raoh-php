<?php

declare(strict_types=1);

namespace Raoh\Value;

use Raoh\Internal\Number\Floats;

/**
 * An IEEE 754 binary32 value, held as the PHP float that equals it exactly.
 *
 * The width decides how the float is written: a float32 of 0.1 reads `0.1`, not the binary64
 * digits of the same value.
 */
final readonly class Float32
{
    public float $value;

    /** The binary32 nearest to $value. */
    public function __construct(float $value)
    {
        $this->value = Floats::toFloat32($value);
    }

    /** The binary32 a JSON number lexeme denotes, rounded once from the exact decimal. */
    public static function fromLexeme(string $lexeme): self
    {
        return new self(Floats::fromLexeme($lexeme, 32));
    }

    /** The same float: +0 and -0 differ, and every NaN is the same. */
    public function equals(self $other): bool
    {
        return Floats::same($this->value, $other->value);
    }

    /** The message form, as Java's Float.toString writes it. */
    public function __toString(): string
    {
        return Floats::messageForm($this->value, 32);
    }
}
