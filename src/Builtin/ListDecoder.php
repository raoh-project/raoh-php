<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Wire;
use Raoh\Internal\Arguments;
use Raoh\Internal\Values;
use Raoh\Path;
use Raoh\Result;

/**
 * A decoder of lists. Elements are compared as the value model compares them: for floats +0 and
 * -0 differ and NaN is NaN, and a decimal keeps its scale.
 *
 * @template E
 * @extends BaseDecoder<list<E>>
 */
final class ListDecoder extends BaseDecoder
{
    /** Fails when there is no element. */
    public function nonempty(?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->size(static fn (int $n): bool => $n >= 1, 'too_small.nonempty', ['min' => 1], $message);
    }

    public function minSize(int $min, ?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->size(static fn (int $n): bool => $n >= $min, 'too_small', ['min' => $min], $message);
    }

    public function maxSize(int $max, ?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->size(static fn (int $n): bool => $n <= $max, 'too_big', ['max' => $max], $message);
    }

    public function fixedSize(int $size, ?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->size(static fn (int $n): bool => $n === $size, 'invalid_size', ['expected' => $size], $message);
    }

    /**
     * Fails when an element occurs more than once, listing each such element once, in the order of
     * the occurrence that first makes it a duplicate.
     */
    public function unique(?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->then(static function (array $v, Path $p) use ($message): Result {
            $seen = [];
            $duplicates = [];
            foreach ($v as $e) {
                $key = Values::key($e);
                if (!isset($seen[$key])) {
                    $seen[$key] = 1;
                } elseif ($seen[$key]++ === 1) {
                    $duplicates[] = $e;
                }
            }
            return $duplicates === []
                ? Result::ok($v)
                : Result::issue($p, 'duplicate_element', ['duplicates' => Wire::describe($duplicates)], $message);
        });
    }

    /**
     * @param E $element
     */
    public function contains(mixed $element, ?string $message = null): static
    {
        $message = Arguments::message($message);
        Wire::check($element, 'the element');
        $key = Values::key($element);
        return $this->check(
            static function (array $v) use ($key): bool {
                foreach ($v as $e) {
                    if (Values::key($e) === $key) {
                        return true;
                    }
                }
                return false;
            },
            'missing_element',
            ['expected' => $element],
            $message,
        );
    }

    /**
     * Fails unless every one of the elements occurs; `missing` lists, in the order given, each
     * that does not, as many times as it was given.
     *
     * @param list<E> $elements
     */
    public function containsAll(array $elements, ?string $message = null): static
    {
        $message = Arguments::message($message);
        if ($elements === []) {
            throw new \InvalidArgumentException('containsAll: the elements must not be empty');
        }
        Wire::check($elements, 'the elements');
        $keys = array_map(Values::key(...), $elements);
        return $this->then(static function (array $v, Path $p) use ($elements, $keys, $message): Result {
            $present = [];
            foreach ($v as $e) {
                $present[Values::key($e)] = true;
            }
            $missing = [];
            foreach ($elements as $i => $e) {
                if (!isset($present[$keys[$i]])) {
                    $missing[] = $e;
                }
            }
            return $missing === []
                ? Result::ok($v)
                : Result::issue($p, 'missing_elements', ['expected' => $elements, 'missing' => $missing], $message);
        });
    }

    /**
     * The set of the elements, as a list holding each once, where it first occurs.
     *
     * @return ListDecoder<E>
     */
    public function toSet(): self
    {
        return $this->then(static fn (array $v): Result => Result::ok(Values::distinct($v)));
    }

    /**
     * @param callable(int): bool $holds
     * @param array<string, int> $meta
     */
    private function size(callable $holds, string $messageKey, array $meta, ?string $message): static
    {
        return $this->then(static function (array $v, Path $p) use ($holds, $messageKey, $meta, $message): Result {
            $n = count($v);
            return $holds($n) ? Result::ok($v) : Result::issue($p, $messageKey, [...$meta, 'actual' => $n], $message);
        });
    }
}
