<?php

declare(strict_types=1);

namespace Raoh\Value\Temporal;

use Raoh\Notation199x\TemporalAnswer;
use Raoh\Notation199x\TemporalKind;
use Raoh\Notation199x\TemporalText;

/**
 * A point on the UTC time-line to the nanosecond, counted from 1970-01-01T00:00:00Z: from the first
 * second of year -1000000000 to the last of year 1000000000, a year further on either side than a
 * date holds, since an offset and an hour 24 move a moment across the end of a year.
 */
final readonly class Instant
{
    private function __construct(
        private int $epochSecond,
        private int $nano,
    ) {
    }

    /**
     * The instant $text names, as `string().iso8601()` reads it, or null where it names none. The
     * offset is applied, and 24:00:00 is the start of the next day. A leap second is no instant.
     */
    public static function parse(string $text): ?self
    {
        if (TemporalText::check(TemporalKind::Instant, $text) !== TemporalAnswer::Admitted) {
            return null;
        }
        $t = Fields::timeStart($text);
        $o = Fields::offsetStart($text);
        [$year, $month, $day] = Fields::date(substr($text, 0, $t - 1));
        [$hour, $minute, $second, $nano] = Fields::time(substr($text, $t, $o - $t));
        $epochSecond = Fields::daysFromCivil($year, $month, $day) * Fields::SECONDS_PER_DAY
            + $hour * 3600 + $minute * 60 + $second - Fields::offset(substr($text, $o));
        return new self($epochSecond, $nano);
    }

    /** Seconds from 1970-01-01T00:00:00Z to the second at or before the instant. */
    public function epochSecond(): int
    {
        return $this->epochSecond;
    }

    /** Nanoseconds from that second, from 0 to 999999999. */
    public function nano(): int
    {
        return $this->nano;
    }

    /** Whether $other is the same instant. */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /** -1, 0 or 1 as this instant is before, the same as or after $other. */
    public function compareTo(self $other): int
    {
        return [$this->epochSecond, $this->nano] <=> [$other->epochSecond, $other->nano];
    }

    /** This instant in UTC, the nanoseconds cut to microseconds. */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        [$year, $month, $day, $hour, $minute, $second] = $this->fields();
        return Fields::dateTimeImmutable($year, $month, $day, $hour, $minute, $second, $this->nano, Fields::utc());
    }

    /** The message form: the date, `T`, the time in UTC with the seconds always written, `Z`. */
    public function __toString(): string
    {
        [$year, $month, $day, $hour, $minute, $second] = $this->fields();
        return Fields::year($year) . '-' . Fields::two($month) . '-' . Fields::two($day)
            . 'T' . Fields::clock($hour, $minute, $second, $this->nano, true) . 'Z';
    }

    /**
     * The year, month, day, hour, minute and second of this instant in UTC.
     *
     * @return array{int, int, int, int, int, int}
     */
    private function fields(): array
    {
        $days = Fields::floorDiv($this->epochSecond, Fields::SECONDS_PER_DAY);
        $ofDay = $this->epochSecond - $days * Fields::SECONDS_PER_DAY;
        [$year, $month, $day] = Fields::civilFromDays($days);
        return [$year, $month, $day, intdiv($ofDay, 3600), intdiv($ofDay, 60) % 60, $ofDay % 60];
    }
}
