<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Arguments;
use Raoh\Path;
use Raoh\Result;
use Raoh\Value\Decimal;

/**
 * A decoder of decimals, each a {@see Decimal} that keeps the scale it was written with.
 * Bounds compare decimals by value: 1.5 and 1.50 are equal there, and different decimals.
 *
 * @extends NumberDecoder<Decimal>
 */
final class DecimalDecoder extends NumberDecoder
{
    public function multipleOf(Decimal|string|int $divisor, ?string $message = null): static
    {
        $message = Arguments::message($message);
        $divisor = self::value($divisor);
        if ($divisor->signum() === 0) {
            throw new \InvalidArgumentException('multipleOf: the divisor must not be zero');
        }
        return $this->check(
            static fn (Decimal $v): bool => $v->isMultipleOf($divisor),
            'not_multiple_of',
            static fn (Decimal $v): array => ['divisor' => $divisor, 'actual' => $v],
            $message,
        );
    }

    /** Fails when the decimal's scale is greater than max. */
    public function scale(int $max, ?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->then(static function (Decimal $v, Path $p) use ($max, $message): Result {
            return $v->scale() > $max
                ? Result::issue($p, 'invalid_scale', ['maxScale' => $max, 'actualScale' => $v->scale()], $message)
                : Result::ok($v);
        });
    }

    protected static function order(mixed $a, mixed $b): int
    {
        return $a->compareTo($b);
    }

    protected static function value(mixed $v): Decimal
    {
        if ($v instanceof Decimal) {
            return $v;
        }
        $d = is_int($v) ? Decimal::of((string) $v, 0) : (is_string($v) ? Decimal::parse($v) : null);
        if ($d === null) {
            throw new \InvalidArgumentException('not a decimal: ' . var_export($v, true));
        }
        return $d;
    }

    protected static function least(): Decimal
    {
        return Decimal::of('0', 0);
    }

    protected static function greatest(): Decimal
    {
        return Decimal::of('0', 0);
    }

    protected static function zero(): Decimal
    {
        return Decimal::of('0', 0);
    }
}
