<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Decoder;
use Raoh\DecoderTrait;
use Raoh\Path;
use Raoh\Result;

/**
 * A decoder whose operations give a decoder of the same kind, so that they chain:
 * `string_()->trim()->minLength(3)`. Each operation runs only when everything before it has
 * succeeded.
 *
 * @template T
 * @implements Decoder<mixed, T>
 */
abstract class BaseDecoder implements Decoder
{
    /** @use DecoderTrait<mixed, T> */
    use DecoderTrait;

    /**
     * Made by the factories of {@see \Raoh\Decoders} and by the operations, which are what keeps
     * the value a decoder gives of the type its operations take. PHP has no constructor a package
     * alone may call, so this one is public, and not part of the API.
     *
     * @internal
     * @param \Closure(mixed, Path): Result<T> $run
     */
    final public function __construct(private readonly \Closure $run)
    {
    }

    public function decode(mixed $in, ?Path $path = null): Result
    {
        return ($this->run)($in, $path ?? Path::root());
    }

    /**
     * This decoder followed by a step on its value, as a decoder of the same kind.
     *
     * @param callable(T, Path): Result<T> $step
     */
    protected function then(callable $step): static
    {
        return new static($this->followedBy($step));
    }

    /**
     * This decoder followed by a step on its value.
     *
     * @template U
     * @param callable(T, Path): Result<U> $step
     * @return \Closure(mixed, Path): Result<U>
     */
    protected function followedBy(callable $step): \Closure
    {
        $run = $this->run;
        return static fn (mixed $in, Path $p): Result => $run($in, $p)
            ->flatMap(static fn (mixed $v): Result => $step($v, $p));
    }

    /**
     * This decoder followed by a check that gives one issue when it fails.
     *
     * @param callable(T): bool $holds
     * @param array<array-key, mixed>|\Closure(T): array<array-key, mixed> $meta
     */
    protected function check(callable $holds, string $messageKey, array|\Closure $meta, ?string $message): static
    {
        return $this->then(static function (mixed $v, Path $p) use ($holds, $messageKey, $meta, $message): Result {
            if ($holds($v)) {
                return Result::ok($v);
            }
            return Result::issue($p, $messageKey, is_array($meta) ? $meta : $meta($v), $message);
        });
    }
}
