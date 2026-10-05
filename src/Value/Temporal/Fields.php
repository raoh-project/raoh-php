<?php

declare(strict_types=1);

namespace Raoh\Value\Temporal;

/**
 * Reads the fields of a temporal text that TemporalText has already admitted, and writes them
 * back in their message forms.
 *
 * Nothing here decides whether a text is a temporal: each reader is handed only text that
 * TemporalText::check admitted, whose forms are ASCII and fixed, so the fields are where the
 * grammar puts them. The arithmetic is Howard Hinnant's on 64-bit integers, which holds the days
 * and seconds of every year from -1000000001 to 1000000001 without overflow.
 *
 * @internal
 */
final class Fields
{
    public const SECONDS_PER_DAY = 86_400;
    /** The days from 0000-03-01 to 1970-01-01. */
    private const DAYS_TO_EPOCH = 719_468;
    private const DAYS_PER_ERA = 146_097;

    private function __construct()
    {
    }

    /**
     * The year, month and day an admitted date writes, read from the start of $text up to the
     * second `-` after the year: a sign, the year's digits, `-`, two digits, `-`, two digits.
     *
     * @return array{int, int, int}
     */
    public static function date(string $text): array
    {
        $sign = $text[0] === '-' ? -1 : 1;
        $start = ($text[0] === '-' || $text[0] === '+') ? 1 : 0;
        $digits = strspn($text, '0123456789', $start);
        $year = $sign * self::digits($text, $start, $digits);
        $at = $start + $digits + 1;
        return [$year, self::digits($text, $at, 2), self::digits($text, $at + 3, 2)];
    }

    /**
     * The hour, minute, second and nanosecond an admitted time writes: hh:mm, then :ss, then a
     * full stop and one to nine digits. What is not written is zero.
     *
     * @return array{int, int, int, int}
     */
    public static function time(string $text): array
    {
        $hour = self::digits($text, 0, 2);
        $minute = self::digits($text, 3, 2);
        $second = 0;
        $nano = 0;
        if (strlen($text) > 5) {
            $second = self::digits($text, 6, 2);
            if (strlen($text) > 9) {
                $fraction = substr($text, 9);
                $nano = self::digits(str_pad($fraction, 9, '0'), 0, 9);
            }
        }
        return [$hour, $minute, $second, $nano];
    }

    /**
     * The displacement from UTC an admitted offset writes, in seconds: 0 for Z, otherwise a sign
     * and hh:mm or hh:mm:ss.
     */
    public static function offset(string $text): int
    {
        if ($text === 'Z') {
            return 0;
        }
        $magnitude = self::digits($text, 1, 2) * 3600 + self::digits($text, 4, 2) * 60;
        if (strlen($text) > 6) {
            $magnitude += self::digits($text, 7, 2);
        }
        return $text[0] === '-' ? -$magnitude : $magnitude;
    }

    /**
     * Where the time of a date-time starts: the character after the `T`, which no year writes.
     */
    public static function timeStart(string $text): int
    {
        $t = strpos($text, 'T');
        if ($t === false) {
            throw new \LogicException('an admitted date-time has a T');
        }
        return $t + 1;
    }

    /**
     * Where the offset of an admitted date-time with an offset starts: the first `Z`, `+` or `-`
     * after the `T`, since a time writes none of them.
     */
    public static function offsetStart(string $text): int
    {
        $from = self::timeStart($text);
        return $from + strcspn($text, 'Z+-', $from);
    }

    private static function digits(string $text, int $at, int $n): int
    {
        $value = 0;
        for ($i = 0; $i < $n; $i++) {
            $value = $value * 10 + (ord($text[$at + $i]) - 0x30);
        }
        return $value;
    }

    public static function floorDiv(int $a, int $b): int
    {
        $q = intdiv($a, $b);
        return ($a % $b !== 0 && (($a < 0) !== ($b < 0))) ? $q - 1 : $q;
    }

    /**
     * Days from 1970-01-01 to a day of the proleptic Gregorian calendar, by Howard Hinnant's
     * days_from_civil.
     */
    public static function daysFromCivil(int $year, int $month, int $day): int
    {
        $y = $month <= 2 ? $year - 1 : $year;
        $era = self::floorDiv($y, 400);
        $yearOfEra = $y - $era * 400;
        $dayOfYear = intdiv(153 * ($month > 2 ? $month - 3 : $month + 9) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;
        return $era * self::DAYS_PER_ERA + $dayOfEra - self::DAYS_TO_EPOCH;
    }

    /**
     * The year, month and day $days after 1970-01-01, by Howard Hinnant's civil_from_days.
     *
     * @return array{int, int, int}
     */
    public static function civilFromDays(int $days): array
    {
        $shifted = $days + self::DAYS_TO_EPOCH;
        $era = self::floorDiv($shifted, self::DAYS_PER_ERA);
        $dayOfEra = $shifted - $era * self::DAYS_PER_ERA;
        $yearOfEra = intdiv(
            $dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36_524) - intdiv($dayOfEra, 146_096),
            365,
        );
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $mp = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp + 3 : $mp - 9;
        return [$yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }

    /**
     * Seconds from 1970-01-01T00:00:00 to a date and a time of day, both taken as UTC.
     */
    public static function epochSecond(LocalDate $date, int $secondOfDay): int
    {
        return self::daysFromCivil($date->year(), $date->month(), $date->day()) * self::SECONDS_PER_DAY
            + $secondOfDay;
    }

    public static function two(int $n): string
    {
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /**
     * A year from 0 to 9999 in four digits, a negative one as `-` and at least four, a greater one
     * as `+` and its digits.
     */
    public static function year(int $year): string
    {
        if ($year >= 0 && $year <= 9999) {
            return str_pad((string) $year, 4, '0', STR_PAD_LEFT);
        }
        return $year < 0
            ? '-' . str_pad((string) -$year, 4, '0', STR_PAD_LEFT)
            : '+' . $year;
    }

    /**
     * The fraction of a second in three, six or nine digits, with its point, or nothing where it
     * is zero.
     */
    public static function fraction(int $nano): string
    {
        if ($nano === 0) {
            return '';
        }
        if ($nano % 1_000_000 === 0) {
            return '.' . str_pad((string) intdiv($nano, 1_000_000), 3, '0', STR_PAD_LEFT);
        }
        if ($nano % 1_000 === 0) {
            return '.' . str_pad((string) intdiv($nano, 1_000), 6, '0', STR_PAD_LEFT);
        }
        return '.' . str_pad((string) $nano, 9, '0', STR_PAD_LEFT);
    }

    /**
     * hh:mm, then :ss where the seconds or the fraction are not zero (always where $seconds), then
     * the fraction.
     */
    public static function clock(int $hour, int $minute, int $second, int $nano, bool $seconds): string
    {
        $written = self::two($hour) . ':' . self::two($minute);
        if (!$seconds && $second === 0 && $nano === 0) {
            return $written;
        }
        return $written . ':' . self::two($second) . self::fraction($nano);
    }

    /**
     * A DateTimeImmutable at the given wall-clock fields in $zone, the nanoseconds cut to
     * microseconds.
     */
    public static function dateTimeImmutable(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        int $nano,
        \DateTimeZone $zone,
    ): \DateTimeImmutable {
        $value = (new \DateTimeImmutable('@0'))
            ->setTimezone($zone)
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second, intdiv($nano, 1000));
        if ((int) $value->format('Y') !== $year) {
            throw new \RangeException(sprintf('year %d is beyond what DateTimeImmutable holds', $year));
        }
        return $value;
    }

    public static function utc(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
