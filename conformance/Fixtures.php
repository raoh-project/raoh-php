<?php

declare(strict_types=1);

namespace Raoh\Conformance;

use Raoh\Decoder;
use Raoh\Issues;
use Raoh\Path;
use Raoh\Result;

/**
 * The fixtures of catalog/fixtures.json, written as a user of raoh-php writes such functions
 * (spec/fixtures.md).
 */
final class Fixtures
{
    private function __construct()
    {
    }

    /**
     * A `map`, `refine` or `flatMap` operation with the fixture it names.
     *
     * @param Decoder<mixed, mixed> $decoder
     * @param array<string, mixed> $ty
     * @return array{Decoder<mixed, mixed>, array<string, mixed>}
     */
    public static function apply(Decoder $decoder, array $ty, string $operation, string $fixture): array
    {
        if ($operation === 'map') {
            [$out, $f] = self::map($fixture, $ty);
            return [$decoder->map($f), $out];
        }
        if ($operation === 'refine' && $fixture === 'even') {
            return [
                $decoder->refine(
                    static fn (int $v): bool => $v % 2 === 0,
                    'must_be_even',
                    'must be even',
                    static fn (int $v): array => ['actual' => $v],
                ),
                $ty,
            ];
        }
        if ($operation === 'flatMap' && $fixture === 'ordered_period') {
            return [$decoder->flatMap(self::orderedPeriod(...)), $ty];
        }
        throw new \InvalidArgumentException("no {$operation} fixture {$fixture}");
    }

    /**
     * @param array<string, mixed> $input
     * @return array{array<string, mixed>, \Closure(mixed): mixed}
     */
    private static function map(string $name, array $input): array
    {
        $int = Value::t('int32');
        $shiftAdd = static fn (int $by): \Closure => static fn (array $v): int => $v[0] * $by + $v[1];
        return match ($name) {
            'first' => isset($input['parts']) && count($input['parts']) === 1
                ? [$input['parts'][0], static fn (array $v): mixed => $v[0]]
                : throw new \InvalidArgumentException('first takes a product of one element'),
            'square_side', 'square' => [$int, static fn (array $v): int => $v[0] * $v[0]],
            'area' => [$int, static fn (array $v): int => $v[0] * $v[1]],
            'shift_add_10' => [$int, $shiftAdd(10)],
            'shift_add_100' => [$int, $shiftAdd(100)],
            'shift_add_1000' => [$int, $shiftAdd(1000)],
            'decimal_string' => [Value::t('string'), static fn (int $v): string => (string) $v],
            default => throw new \InvalidArgumentException("no map fixture {$name}"),
        };
    }

    /**
     * The product unchanged where start <= end, and otherwise an issue at `end`, relative to where
     * the decoder is.
     *
     * @param list<int> $period
     * @return Result<list<int>>
     */
    public static function orderedPeriod(array $period): Result
    {
        return $period[0] <= $period[1]
            ? Result::ok($period)
            : Result::failCustom(Path::of('end'), 'invalid_value', 'end is before start', [], 'invalid_value');
    }

    public static function issueCountPlus10(Issues $issues): int
    {
        return count($issues->toArray()) + 10;
    }

    public static function identity(mixed $v): mixed
    {
        return $v;
    }
}
