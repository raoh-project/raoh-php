<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Arguments;
use Raoh\Internal\Values;
use Raoh\Path;
use Raoh\Result;

/**
 * The bounds every numeric decoder has. Floats are compared in the float order of the value
 * model, in which -0 is less than +0; decimals by value.
 *
 * @template T
 * @extends BaseDecoder<T>
 */
abstract class NumberDecoder extends BaseDecoder
{
    /**
     * The value model's order on the decoder's values.
     *
     * @param T $a
     * @param T $b
     */
    abstract protected static function order(mixed $a, mixed $b): int;

    /**
     * A bound or an allowed value, as a value of the decoder's type.
     *
     * @return T
     */
    abstract protected static function value(mixed $v): mixed;

    /**
     * The least value `positive` allows, and the zero the other signs are compared with.
     *
     * @return T
     */
    abstract protected static function least(): mixed;

    /**
     * The greatest value `negative` allows.
     *
     * @return T
     */
    abstract protected static function greatest(): mixed;

    /** @return T */
    abstract protected static function zero(): mixed;

    public function min(mixed $n, ?string $message = null): static
    {
        $message = Arguments::message($message);
        $min = static::value($n);
        return $this->bound(
            static fn (mixed $v): bool => static::order($v, $min) >= 0,
            'out_of_range.minimum',
            ['min' => $min],
            $message,
        );
    }

    public function max(mixed $n, ?string $message = null): static
    {
        $message = Arguments::message($message);
        $max = static::value($n);
        return $this->bound(
            static fn (mixed $v): bool => static::order($v, $max) <= 0,
            'out_of_range.maximum',
            ['max' => $max],
            $message,
        );
    }

    public function range(mixed $min, mixed $max, ?string $message = null): static
    {
        $message = Arguments::message($message);
        $min = static::value($min);
        $max = static::value($max);
        if (static::order($min, $max) > 0) {
            throw new \InvalidArgumentException('range: min must not be greater than max');
        }
        return $this->bound(
            static fn (mixed $v): bool => static::order($v, $min) >= 0 && static::order($v, $max) <= 0,
            'out_of_range.range',
            ['min' => $min, 'max' => $max],
            $message,
        );
    }

    public function positive(?string $message = null): static
    {
        $message = Arguments::message($message);
        $zero = static::zero();
        return $this->bound(
            static fn (mixed $v): bool => static::order($v, $zero) > 0,
            'out_of_range.positive',
            ['min' => static::least()],
            $message,
        );
    }

    public function negative(?string $message = null): static
    {
        $message = Arguments::message($message);
        $zero = static::zero();
        return $this->bound(
            static fn (mixed $v): bool => static::order($v, $zero) < 0,
            'out_of_range.negative',
            ['max' => static::greatest()],
            $message,
        );
    }

    public function nonNegative(?string $message = null): static
    {
        $message = Arguments::message($message);
        $zero = static::zero();
        return $this->bound(
            static fn (mixed $v): bool => static::order($v, $zero) >= 0,
            'out_of_range.non_negative',
            ['min' => $zero],
            $message,
        );
    }

    public function nonPositive(?string $message = null): static
    {
        $message = Arguments::message($message);
        $zero = static::zero();
        return $this->bound(
            static fn (mixed $v): bool => static::order($v, $zero) <= 0,
            'out_of_range.non_positive',
            ['max' => $zero],
            $message,
        );
    }

    /**
     * One of the allowed values, which are distinct; compared as the value model compares, so
     * for floats +0 and -0 differ and NaN is NaN.
     *
     * @param list<mixed> $allowed
     */
    protected function allowed(array $allowed, ?string $message): static
    {
        $allowed = array_map(static fn (mixed $v): mixed => static::value($v), $allowed);
        $keys = [];
        foreach ($allowed as $a) {
            $keys[Values::key($a)] = true;
        }
        if (count($keys) !== count($allowed)) {
            throw new \InvalidArgumentException('oneOf: the allowed values are not distinct');
        }
        usort($allowed, static fn (mixed $a, mixed $b): int => static::order($a, $b));
        return $this->check(
            static fn (mixed $v): bool => isset($keys[Values::key($v)]),
            'not_allowed',
            static fn (mixed $v): array => ['allowed' => $allowed, 'actual' => $v],
            $message,
        );
    }

    /**
     * A multiple of an integer divisor, for the integer decoders.
     */
    protected function multiple(int $divisor, ?string $message): static
    {
        if ($divisor === 0) {
            throw new \InvalidArgumentException('multipleOf: the divisor must not be zero');
        }
        return $this->check(
            static fn (mixed $v): bool => $v % $divisor === 0,
            'not_multiple_of',
            static fn (mixed $v): array => ['divisor' => $divisor, 'actual' => $v],
            $message,
        );
    }

    /**
     * @param callable(T): bool $holds
     * @param array<string, mixed> $meta
     */
    private function bound(callable $holds, string $messageKey, array $meta, ?string $message): static
    {
        return $this->then(static function (mixed $v, Path $p) use ($holds, $messageKey, $meta, $message): Result {
            return $holds($v)
                ? Result::ok($v)
                : Result::issue($p, $messageKey, [...$meta, 'actual' => $v], $message);
        });
    }
}
