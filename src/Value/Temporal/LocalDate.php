<?php

declare(strict_types=1);

namespace Raoh\Value\Temporal;

use Raoh\Notation199x\TemporalAnswer;
use Raoh\Notation199x\TemporalKind;
use Raoh\Notation199x\TemporalText;

/**
 * A day of the proleptic Gregorian calendar, from year -999999999 to 999999999.
 */
final readonly class LocalDate
{
    private function __construct(
        private int $year,
        private int $month,
        private int $day,
    ) {
    }

    /**
     * The date $text names, as `string().date()` reads it, or null where it names none.
     */
    public static function parse(string $text): ?self
    {
        if (TemporalText::check(TemporalKind::Date, $text) !== TemporalAnswer::Admitted) {
            return null;
        }
        [$year, $month, $day] = Fields::date($text);
        return new self($year, $month, $day);
    }

    public function year(): int
    {
        return $this->year;
    }

    /** From 1 to 12. */
    public function month(): int
    {
        return $this->month;
    }

    /** From 1 to the month's last. */
    public function day(): int
    {
        return $this->day;
    }

    /** Whether $other is the same date. */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /** -1, 0 or 1 as this date is before, the same as or after $other. */
    public function compareTo(self $other): int
    {
        return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
    }

    /** Midnight of this date in UTC. */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return Fields::dateTimeImmutable($this->year, $this->month, $this->day, 0, 0, 0, 0, Fields::utc());
    }

    /**
     * The message form: a year from 0 to 9999 in four digits, a negative one as `-` and at least
     * four, a greater one as `+` and its digits; `-`, two digits of month, `-`, two digits of day.
     */
    public function __toString(): string
    {
        return Fields::year($this->year) . '-' . Fields::two($this->month) . '-' . Fields::two($this->day);
    }
}
