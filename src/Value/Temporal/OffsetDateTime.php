<?php

declare(strict_types=1);

namespace Raoh\Value\Temporal;

use Raoh\Notation199x\TemporalAnswer;
use Raoh\Notation199x\TemporalKind;
use Raoh\Notation199x\TemporalText;

/**
 * A date-time and an offset from UTC. The offset is kept, not applied: two values at different
 * offsets are different values even where they name the same instant, and compareTo compares the
 * instants alone, so `09:00Z` and `10:00+01:00` compare 0 without being equal.
 */
final readonly class OffsetDateTime
{
    private function __construct(
        private LocalDateTime $dateTime,
        private int $offsetSeconds,
    ) {
    }

    /**
     * The offset date-time $text names, as `string().offsetDateTime()` reads it, or null where it
     * names none. `Z`, `+00:00` and `-00:00` all give the offset zero.
     */
    public static function parse(string $text): ?self
    {
        if (TemporalText::check(TemporalKind::OffsetDateTime, $text) !== TemporalAnswer::Admitted) {
            return null;
        }
        $o = Fields::offsetStart($text);
        $dateTime = LocalDateTime::parse(substr($text, 0, $o));
        if ($dateTime === null) {
            throw new \LogicException('the part of an admitted offset date-time before its offset is a date-time');
        }
        return new self($dateTime, Fields::offset(substr($text, $o)));
    }

    /** The date and time of day as written, not moved by the offset. */
    public function dateTime(): LocalDateTime
    {
        return $this->dateTime;
    }

    public function date(): LocalDate
    {
        return $this->dateTime->date();
    }

    public function time(): LocalTime
    {
        return $this->dateTime->time();
    }

    /** Seconds east of UTC, from -64800 to 64800. */
    public function offsetSeconds(): int
    {
        return $this->offsetSeconds;
    }

    public function year(): int
    {
        return $this->dateTime->year();
    }

    public function month(): int
    {
        return $this->dateTime->month();
    }

    public function day(): int
    {
        return $this->dateTime->day();
    }

    public function hour(): int
    {
        return $this->dateTime->hour();
    }

    public function minute(): int
    {
        return $this->dateTime->minute();
    }

    public function second(): int
    {
        return $this->dateTime->second();
    }

    public function nano(): int
    {
        return $this->dateTime->nano();
    }

    /** Seconds from 1970-01-01T00:00:00Z to the instant this names, without the fraction. */
    public function epochSecond(): int
    {
        return Fields::epochSecond($this->date(), $this->time()->secondOfDay()) - $this->offsetSeconds;
    }

    /** Whether $other is the same date-time at the same offset. */
    public function equals(self $other): bool
    {
        return $this->dateTime->equals($other->dateTime) && $this->offsetSeconds === $other->offsetSeconds;
    }

    /**
     * -1, 0 or 1 as the instant this names is before, the same as or after the one $other names.
     * The offset is applied, so values that are not equal can compare 0.
     */
    public function compareTo(self $other): int
    {
        return [$this->epochSecond(), $this->nano()] <=> [$other->epochSecond(), $other->nano()];
    }

    /**
     * This date-time at its offset, the nanoseconds cut to microseconds.
     *
     * @throws \RangeException where this PHP cannot make a zone of the offset
     */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone($this->offsetText(true));
        } catch (\Exception $e) {
            throw new \RangeException(sprintf('offset %s is not one DateTimeZone holds', $this->offsetText(true)), 0, $e);
        }
        if ($zone->getOffset(new \DateTimeImmutable('@0')) !== $this->offsetSeconds) {
            throw new \RangeException(sprintf('offset %s is not one DateTimeZone holds', $this->offsetText(true)));
        }
        return Fields::dateTimeImmutable(
            $this->year(),
            $this->month(),
            $this->day(),
            $this->hour(),
            $this->minute(),
            $this->second(),
            $this->nano(),
            $zone,
        );
    }

    /**
     * The message form: the date-time, then `Z` for the zero offset and otherwise `±hh:mm`, then
     * `:ss` where the offset's seconds are not zero.
     */
    public function __toString(): string
    {
        return $this->dateTime . $this->offsetText(false);
    }

    private function offsetText(bool $signedZero): string
    {
        if ($this->offsetSeconds === 0 && !$signedZero) {
            return 'Z';
        }
        $total = abs($this->offsetSeconds);
        $seconds = $total % 60;
        return ($this->offsetSeconds < 0 ? '-' : '+')
            . Fields::two(intdiv($total, 3600)) . ':' . Fields::two(intdiv($total, 60) % 60)
            . ($seconds === 0 ? '' : ':' . Fields::two($seconds));
    }
}
