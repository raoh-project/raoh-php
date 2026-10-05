<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Path;
use Raoh\Result;
use Raoh\Value\Temporal\Instant;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;
use Raoh\Value\Temporal\LocalTime;
use Raoh\Value\Temporal\OffsetDateTime;

/**
 * A decoder of a temporal value: a LocalDate, LocalTime, LocalDateTime, OffsetDateTime or Instant
 * of {@see \Raoh\Value\Temporal}. Bounds are compared chronologically; offset date-times by the
 * instant alone, so 09:00Z is not before 10:00+01:00. A bound is a value of the same type, or the
 * text that value is read from.
 *
 * @template T of LocalDate|LocalTime|LocalDateTime|OffsetDateTime|Instant
 * @extends BaseDecoder<T>
 */
final class TemporalDecoder extends BaseDecoder
{
    /** @var \Closure(string): (T|null) */
    private \Closure $parse;

    /**
     * @template U of LocalDate|LocalTime|LocalDateTime|OffsetDateTime|Instant
     * @param \Closure(mixed, Path): Result<U> $run
     * @param callable(string): (U|null) $parse reads a bound written as text
     * @return self<U>
     */
    public static function over(\Closure $run, callable $parse): self
    {
        $d = new self($run);
        $d->parse = \Closure::fromCallable($parse);
        return $d;
    }

    /** @param T|string $bound */
    public function before(object|string $bound, ?string $message = null): static
    {
        $bound = $this->bound($bound);
        return $this->compared(
            static fn (object $v): bool => self::compare($v, $bound) < 0,
            'out_of_range.before',
            ['before' => $bound],
            $message,
        );
    }

    /** @param T|string $bound */
    public function after(object|string $bound, ?string $message = null): static
    {
        $bound = $this->bound($bound);
        return $this->compared(
            static fn (object $v): bool => self::compare($v, $bound) > 0,
            'out_of_range.after',
            ['after' => $bound],
            $message,
        );
    }

    /**
     * @param T|string $from
     * @param T|string $to
     */
    public function between(object|string $from, object|string $to, ?string $message = null): static
    {
        $from = $this->bound($from);
        $to = $this->bound($to);
        if (self::compare($from, $to) > 0) {
            throw new \InvalidArgumentException('between: from must not be after to');
        }
        return $this->compared(
            static fn (object $v): bool => self::compare($v, $from) >= 0 && self::compare($v, $to) <= 0,
            'out_of_range.between',
            ['from' => $from, 'to' => $to],
            $message,
        );
    }

    /**
     * @param callable(T): bool $holds
     * @param array<string, mixed> $meta
     */
    private function compared(callable $holds, string $messageKey, array $meta, ?string $message): static
    {
        $d = $this->then(static function (object $v, Path $p) use ($holds, $messageKey, $meta, $message): Result {
            return $holds($v) ? Result::ok($v) : Result::issue($p, $messageKey, [...$meta, 'actual' => $v], $message);
        });
        $d->parse = $this->parse;
        return $d;
    }

    /**
     * @param T|string $bound
     * @return T
     */
    private function bound(object|string $bound): object
    {
        if (is_string($bound)) {
            return ($this->parse)($bound)
                ?? throw new \InvalidArgumentException("not a bound of this decoder: {$bound}");
        }
        return $bound;
    }

    /**
     * The chronology of two values of one temporal type.
     */
    private static function compare(object $a, object $b): int
    {
        return match (true) {
            $a instanceof LocalDate && $b instanceof LocalDate,
            $a instanceof LocalTime && $b instanceof LocalTime,
            $a instanceof LocalDateTime && $b instanceof LocalDateTime,
            $a instanceof OffsetDateTime && $b instanceof OffsetDateTime,
            $a instanceof Instant && $b instanceof Instant => $a->compareTo($b),
            default => throw new \InvalidArgumentException(
                get_debug_type($b) . ' is not a bound of ' . get_debug_type($a),
            ),
        };
    }
}
