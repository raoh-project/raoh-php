<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Arguments;
use Raoh\Path;
use Raoh\Result;

/**
 * A decoder of objects whose members all decode alike, giving a PHP array keyed by member name.
 * PHP turns a name such as "1" into the int key 1; `(string) $key` gives the name back.
 *
 * @template V
 * @extends BaseDecoder<array<array-key, V>>
 */
final class DictDecoder extends BaseDecoder
{
    /** Fails when there is no member. */
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
