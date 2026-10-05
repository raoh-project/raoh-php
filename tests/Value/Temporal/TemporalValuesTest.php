<?php

declare(strict_types=1);

namespace Raoh\Tests\Value\Temporal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Value\Temporal\Fields;
use Raoh\Value\Temporal\Instant;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;
use Raoh\Value\Temporal\LocalTime;
use Raoh\Value\Temporal\OffsetDateTime;

final class TemporalValuesTest extends TestCase
{
    public function testOffsetDateTimeSamenessAndChronologyDiffer(): void
    {
        $utc = OffsetDateTime::parse('2024-01-01T09:00Z');
        $plusOne = OffsetDateTime::parse('2024-01-01T10:00+01:00');
        $this->assertNotNull($utc);
        $this->assertNotNull($plusOne);
        $this->assertSame(0, $utc->compareTo($plusOne));
        $this->assertFalse($utc->equals($plusOne));
    }

    public function testZeroOffsetsAreOneValue(): void
    {
        $z = OffsetDateTime::parse('2024-01-15T10:30Z');
        $plus = OffsetDateTime::parse('2024-01-15T10:30+00:00');
        $minus = OffsetDateTime::parse('2024-01-15T10:30:00-00:00');
        $this->assertNotNull($z);
        $this->assertNotNull($plus);
        $this->assertNotNull($minus);
        $this->assertTrue($z->equals($plus));
        $this->assertTrue($z->equals($minus));
        $this->assertSame('2024-01-15T10:30Z', (string) $minus);
    }

    public function testOffsetIsKeptNotApplied(): void
    {
        $v = OffsetDateTime::parse('2024-01-15T10:30:00.5-05:30:15');
        $this->assertNotNull($v);
        $this->assertSame(10, $v->hour());
        $this->assertSame(500_000_000, $v->nano());
        $this->assertSame(-19815, $v->offsetSeconds());
        $this->assertSame('2024-01-15T10:30:00.500-05:30:15', (string) $v);
    }

    public function testLeapSecondIsNoInstant(): void
    {
        $this->assertNull(Instant::parse('2016-12-31T23:59:60Z'));
    }

    public function testHour24IsTheNextDay(): void
    {
        $this->assertSame('2025-01-01T00:00:00Z', (string) Instant::parse('2024-12-31T24:00:00Z'));
        $this->assertSame('2024-12-31T15:00:00Z', (string) Instant::parse('2024-12-31T24:00:00+09:00'));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function instants(): iterable
    {
        yield ['-1000000000-01-01T00:00:00Z', '-1000000000-01-01T00:00:00Z'];
        yield ['+1000000000-12-31T23:59:59.999999999Z', '+1000000000-12-31T23:59:59.999999999Z'];
        yield ['-999999999-01-01T00:00:00+18:00', '-1000000000-12-31T06:00:00Z'];
        yield ['+999999999-12-31T23:59:59-18:00', '+1000000000-01-01T17:59:59Z'];
        yield ['1969-12-31T23:59:59.5Z', '1969-12-31T23:59:59.500Z'];
        yield ['0000-03-01T00:00:00Z', '0000-03-01T00:00:00Z'];
        yield ['-0004-02-29T00:00:00Z', '-0004-02-29T00:00:00Z'];
    }

    #[DataProvider('instants')]
    public function testInstantRoundTrip(string $text, string $written): void
    {
        $instant = Instant::parse($text);
        $this->assertNotNull($instant);
        $this->assertSame($written, (string) $instant);
        $this->assertTrue($instant->equals(Instant::parse($written) ?? $this->fail()));
    }

    public function testInstantEnds(): void
    {
        $this->assertSame(-31_557_014_167_219_200, Instant::parse('-1000000000-01-01T00:00:00Z')?->epochSecond());
        $this->assertSame(31_556_889_864_403_199, Instant::parse('+1000000000-12-31T23:59:59Z')?->epochSecond());
        $this->assertSame(-1, Instant::parse('1969-12-31T23:59:59.5Z')?->epochSecond());
    }

    public function testCivilDaysRoundTripAcrossTheRange(): void
    {
        foreach ([-1_000_000_001, -999_999_999, -401, -400, -1, 0, 1, 1970, 2000, 2100, 999_999_999, 1_000_000_001] as $year) {
            foreach ([[1, 1], [2, 28], [3, 1], [12, 31]] as [$month, $day]) {
                $days = Fields::daysFromCivil($year, $month, $day);
                $this->assertSame([$year, $month, $day], Fields::civilFromDays($days));
                $this->assertSame($days + 1, Fields::daysFromCivil(...Fields::civilFromDays($days + 1)));
            }
        }
        $this->assertSame(0, Fields::daysFromCivil(1970, 1, 1));
    }

    public function testDateMessageForms(): void
    {
        $this->assertSame('0000-01-01', (string) LocalDate::parse('0000-01-01'));
        $this->assertSame('-0001-12-31', (string) LocalDate::parse('-0001-12-31'));
        $this->assertSame('-10000-01-01', (string) LocalDate::parse('-10000-01-01'));
        $this->assertSame('+10000-01-01', (string) LocalDate::parse('+10000-01-01'));
        $this->assertSame(-1, LocalDate::parse('-10000-01-01')?->compareTo(LocalDate::parse('-0001-01-01') ?? $this->fail()));
    }

    public function testTimeMessageForms(): void
    {
        $this->assertSame('09:00', (string) LocalTime::parse('09:00:00.000'));
        $this->assertSame('09:00:00.001', (string) LocalTime::parse('09:00:00.001'));
        $this->assertSame('09:00:00.010', (string) LocalTime::parse('09:00:00.01'));
        $this->assertSame('09:00:00.000100', (string) LocalTime::parse('09:00:00.0001'));
        $this->assertSame('09:00:00.000000010', (string) LocalTime::parse('09:00:00.00000001'));
        $this->assertTrue(LocalTime::parse('09:00')?->equals(LocalTime::parse('09:00:00.000') ?? $this->fail()));
        $this->assertSame('2024-01-15T00:00:01Z', (string) Instant::parse('2024-01-15T00:00:01Z'));
        $this->assertSame('2024-01-15T00:00:00Z', (string) Instant::parse('2024-01-15T00:00:00Z'));
    }

    public function testRejectsWhatTheGrammarRejects(): void
    {
        $this->assertNull(LocalDate::parse('2024-01-15T00:00'));
        $this->assertNull(LocalTime::parse('24:00'));
        $this->assertNull(LocalDateTime::parse('2024-01-15T10:30Z'));
        $this->assertNull(OffsetDateTime::parse('2024-01-15T10:30'));
        $this->assertNull(OffsetDateTime::parse('2024-01-15T24:00Z'));
        $this->assertNull(Instant::parse('2024-01-15T10:30Z'));
        $this->assertNull(LocalDate::parse("２０２４-01-15"));
    }

    public function testToDateTimeImmutable(): void
    {
        $this->assertSame(
            '2024-01-15T00:00:00.000000+00:00',
            LocalDate::parse('2024-01-15')?->toDateTimeImmutable()->format('Y-m-d\TH:i:s.uP'),
        );
        $this->assertSame(
            '2024-01-15T10:30:45.123456+00:00',
            LocalDateTime::parse('2024-01-15T10:30:45.123456789')?->toDateTimeImmutable()->format('Y-m-d\TH:i:s.uP'),
        );
        $this->assertSame(
            '2024-01-15T10:30:00.000000+09:00',
            OffsetDateTime::parse('2024-01-15T10:30+09:00')?->toDateTimeImmutable()->format('Y-m-d\TH:i:s.uP'),
        );
        $instant = Instant::parse('1969-12-31T23:59:59.5Z');
        $this->assertNotNull($instant);
        $this->assertSame('-1.500000', $instant->toDateTimeImmutable()->format('U.u'));
        $this->assertSame(
            OffsetDateTime::parse('2024-01-15T10:30+09:00')?->toDateTimeImmutable()->getTimestamp(),
            Instant::parse('2024-01-15T10:30:00+09:00')?->toDateTimeImmutable()->getTimestamp(),
        );
    }
}
