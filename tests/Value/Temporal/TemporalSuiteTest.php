<?php

declare(strict_types=1);

namespace Raoh\Tests\Value\Temporal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Tests\Specification;
use Raoh\Value\Temporal\Instant;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;
use Raoh\Value\Temporal\LocalTime;
use Raoh\Value\Temporal\OffsetDateTime;

/**
 * The cases of suite/core/temporal.json that read a string as a temporal, and compare it with
 * before, after or between, run against the value types alone.
 */
final class TemporalSuiteTest extends TestCase
{
    private const OPERATIONS = ['date', 'time', 'dateTime', 'offsetDateTime', 'iso8601'];

    /**
     * @return iterable<string, array{string, string, list<mixed>|null, array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $path = Specification::file('suite/core/temporal.json');
        if ($path === null) {
            yield 'suite not found' => ['', '', null, []];
            return;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            self::fail('cannot read ' . $path);
        }
        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $decoder = $case['decoder'];
            if (!is_array($decoder) || ($decoder[0] ?? null) !== 'string' || !is_string($case['input'] ?? null)) {
                continue;
            }
            $read = $decoder[1] ?? null;
            if (!is_array($read) || !in_array($read[0] ?? null, self::OPERATIONS, true)) {
                continue;
            }
            $compare = $decoder[2] ?? null;
            if (count($decoder) > 3 || ($compare !== null && !is_array($compare))) {
                continue;
            }
            yield $case['id'] . ' ' . $case['title'] => [$read[0], $case['input'], $compare, $case];
        }
    }

    /**
     * @param list<mixed>|null $compare
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testCase(string $operation, string $input, ?array $compare, array $case): void
    {
        if ($operation === '') {
            $this->markTestSkipped('raoh-specification not found; set RAOH_SPECIFICATION_DIR');
        }
        $value = self::parse($operation, $input);
        if (array_key_exists('ok', $case)) {
            $this->assertNotNull($value, "$input is admitted");
            $expected = $case['ok'];
            $this->assertIsString($expected);
            $observed = self::parse($operation, $expected);
            $this->assertNotNull($observed, "the observation $expected is admitted by the same parse");
            $this->assertTrue($value->equals($observed), "$input is the value $expected");
            $this->assertTrue(
                self::parse($operation, (string) $value)?->equals($value) ?? false,
                "the message form $value is an observation of $input",
            );
            if ($compare !== null) {
                $this->assertTrue(self::holds($operation, $value, $compare), "$input passes " . json_encode($compare));
            }
            return;
        }
        $issues = $case['issues'];
        $this->assertIsArray($issues);
        $this->assertCount(1, $issues);
        $code = $issues[0]['code'];
        if ($code === 'invalid_format') {
            $this->assertNull($value, "$input is not admitted");
            return;
        }
        $this->assertSame('out_of_range', $code);
        $this->assertNotNull($value);
        $this->assertNotNull($compare);
        $this->assertFalse(self::holds($operation, $value, $compare), "$input fails " . json_encode($compare));
    }

    private static function parse(string $operation, string $text): LocalDate|LocalTime|LocalDateTime|OffsetDateTime|Instant|null
    {
        return match ($operation) {
            'date' => LocalDate::parse($text),
            'time' => LocalTime::parse($text),
            'dateTime' => LocalDateTime::parse($text),
            'offsetDateTime' => OffsetDateTime::parse($text),
            'iso8601' => Instant::parse($text),
        };
    }

    /**
     * Whether $value passes before, after or between as the operation is written in the suite.
     *
     * @param list<mixed> $compare
     */
    private static function holds(
        string $operation,
        LocalDate|LocalTime|LocalDateTime|OffsetDateTime|Instant $value,
        array $compare,
    ): bool {
        $bound = static function (mixed $text) use ($operation): LocalDate|LocalTime|LocalDateTime|OffsetDateTime|Instant {
            self::assertIsString($text);
            $b = self::parse($operation, $text);
            self::assertNotNull($b, "bound $text is admitted");
            return $b;
        };
        // compareTo takes a value of its own class; the bound is parsed with the same operation.
        return match ($compare[0]) {
            'before' => $value->compareTo($bound($compare[1])) < 0,
            'after' => $value->compareTo($bound($compare[1])) > 0,
            'between' => $value->compareTo($bound($compare[1])) >= 0 && $value->compareTo($bound($compare[2])) <= 0,
        };
    }
}
