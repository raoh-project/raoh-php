<?php

declare(strict_types=1);

namespace Raoh\Value\Temporal;

use Raoh\Notation199x\TemporalAnswer;
use Raoh\Notation199x\TemporalKind;
use Raoh\Notation199x\TemporalText;

/**
 * A date and a time of day, with no offset.
 */
final readonly class LocalDateTime
{
    private function __construct(
        private LocalDate $date,
        private LocalTime $time,
    ) {
    }

    /**
     * The date-time $text names, as `string().dateTime()` reads it, or null where it names none.
     */
    public static function parse(string $text): ?self
    {
        if (TemporalText::check(TemporalKind::DateTime, $text) !== TemporalAnswer::Admitted) {
            return null;
        }
        return self::ofAdmitted($text);
    }

    /**
     * The date-time of a text TemporalText admitted as a date-time, whose part before the T is a
     * date and whose part after it is a time.
     */
    private static function ofAdmitted(string $text): self
    {
        $t = Fields::timeStart($text);
        $date = LocalDate::parse(substr($text, 0, $t - 1));
        $time = LocalTime::parse(substr($text, $t));
        if ($date === null || $time === null) {
            throw new \LogicException('the parts of an admitted date-time are a date and a time');
        }
        return new self($date, $time);
    }

    public function date(): LocalDate
    {
        return $this->date;
    }

    public function time(): LocalTime
    {
        return $this->time;
    }

    public function year(): int
    {
        return $this->date->year();
    }

    public function month(): int
    {
        return $this->date->month();
    }

    public function day(): int
    {
        return $this->date->day();
    }

    public function hour(): int
    {
        return $this->time->hour();
    }

    public function minute(): int
    {
        return $this->time->minute();
    }

    public function second(): int
    {
        return $this->time->second();
    }

    public function nano(): int
    {
        return $this->time->nano();
    }

    /** Whether $other is the same date and time of day. */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /** -1, 0 or 1 as this date-time is before, the same as or after $other. */
    public function compareTo(self $other): int
    {
        return $this->date->compareTo($other->date) ?: $this->time->compareTo($other->time);
    }

    /** This date-time on the clock of UTC, the nanoseconds cut to microseconds. */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return Fields::dateTimeImmutable(
            $this->year(),
            $this->month(),
            $this->day(),
            $this->hour(),
            $this->minute(),
            $this->second(),
            $this->nano(),
            Fields::utc(),
        );
    }

    /** The message form: the date, `T`, the time. */
    public function __toString(): string
    {
        return $this->date . 'T' . $this->time;
    }
}
