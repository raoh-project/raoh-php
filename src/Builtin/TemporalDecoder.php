<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Arguments;
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

    /** @var class-string<T> */
    private string $type;

    /**
     * Made by the temporal operations of {@see StringDecoder}.
     *
     * @internal
     * @template U of LocalDate|LocalTime|LocalDateTime|OffsetDateTime|Instant
     * @param \Closure(mixed, Path): Result<U> $run
     * @param callable(string): (U|null) $parse reads a bound written as text
     * @param class-string<U> $type the class of the values, which a bound has to be
     * @return self<U>
     */
    public static function over(\Closure $run, callable $parse, string $type): self
    {
        if (!self::isTemporal($type)) {
            throw new \InvalidArgumentException("{$type} is not a temporal type");
        }
        $d = new self($run);
        $d->parse = \Closure::fromCallable($parse);
        $d->type = $type;
        return $d;
    }

    /** @param T|string $bound */
    public function before(object|string $bound, ?string $message = null): static
    {
        $message = Arguments::message($message);
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
        $message = Arguments::message($message);
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
        $message = Arguments::message($message);
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
        $d->type = $this->type;
        return $d;
    }

    /**
     * A bound as a value of the decoder's type, refused when the decoder is built rather than
     * when a value is compared with it: a LocalDateTime is no bound of a decoder of dates.
     *
     * @param T|string $bound
     * @return T
     */
    private function bound(object|string $bound): object
    {
        if (is_string($bound)) {
            return ($this->parse)($bound)
                ?? throw new \InvalidArgumentException("not a bound of this decoder: {$bound}");
        }
        if (!$bound instanceof $this->type) {
            throw new \InvalidArgumentException(
                get_debug_type($bound) . " is not a bound of a decoder of {$this->type}",
            );
        }
        return $bound;
    }

    private static function isTemporal(string $type): bool
    {
        return in_array($type, [LocalDate::class, LocalTime::class, LocalDateTime::class, OffsetDateTime::class, Instant::class], true);
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
