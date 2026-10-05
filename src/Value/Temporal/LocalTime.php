<?php

declare(strict_types=1);

namespace Raoh\Value\Temporal;

use Raoh\Notation199x\TemporalAnswer;
use Raoh\Notation199x\TemporalKind;
use Raoh\Notation199x\TemporalText;

/**
 * A time of day to the nanosecond. `09:00` and `09:00:00.000` are the same time.
 */
final readonly class LocalTime
{
    private function __construct(
        private int $hour,
        private int $minute,
        private int $second,
        private int $nano,
    ) {
    }

    /**
     * The time $text names, as `string().time()` reads it, or null where it names none.
     */
    public static function parse(string $text): ?self
    {
        if (TemporalText::check(TemporalKind::Time, $text) !== TemporalAnswer::Admitted) {
            return null;
        }
        [$hour, $minute, $second, $nano] = Fields::time($text);
        return new self($hour, $minute, $second, $nano);
    }

    /** From 0 to 23. */
    public function hour(): int
    {
        return $this->hour;
    }

    /** From 0 to 59. */
    public function minute(): int
    {
        return $this->minute;
    }

    /** From 0 to 59. */
    public function second(): int
    {
        return $this->second;
    }

    /** From 0 to 999999999. */
    public function nano(): int
    {
        return $this->nano;
    }

    /** Seconds from midnight, without the fraction. */
    public function secondOfDay(): int
    {
        return $this->hour * 3600 + $this->minute * 60 + $this->second;
    }

    /** Whether $other is the same time of day. */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /** -1, 0 or 1 as this time is before, the same as or after $other. */
    public function compareTo(self $other): int
    {
        return [$this->secondOfDay(), $this->nano] <=> [$other->secondOfDay(), $other->nano];
    }

    /**
     * The message form: `hh:mm`, then `:ss` where the seconds or the fraction are not zero, then
     * the fraction in three, six or nine digits where it is not zero.
     */
    public function __toString(): string
    {
        return Fields::clock($this->hour, $this->minute, $this->second, $this->nano, false);
    }
}
