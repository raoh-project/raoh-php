<?php

declare(strict_types=1);

namespace Raoh;

/**
 * Provides default combinators for any class implementing Decoder.
 *
 * @template I
 * @template T
 */
trait DecoderTrait
{
    /**
     * @template U
     * @param callable(T): U $f
     * @return Decoder<I, U>
     */
    public function map(callable $f): Decoder
    {
        return CallableDecoder::of(
            fn (mixed $in, ?Path $path = null) => $this->decode($in, $path)->map($f),
        );
    }

    /**
     * @template U
     * @param callable(T): Result<U> $f
     * @return Decoder<I, U>
     */
    public function flatMap(callable $f): Decoder
    {
        return CallableDecoder::of(function (mixed $in, ?Path $path = null) use ($f): Result {
            $resolvedPath = $path ?? Path::root();
            return $this->decode($in, $resolvedPath)->flatMap(
                function (mixed $value) use ($f, $resolvedPath): Result {
                    $r = $f($value);
                    if ($r instanceof Err) {
                        return Result::err($r->issues->rebase($resolvedPath));
                    }
                    return $r;
                },
            );
        });
    }

    /**
     * Fails, at this decoder's path, with an issue whose message is the one given, when the
     * predicate does not hold of the value.
     *
     * @param callable(T): bool $predicate
     * @param array<string, mixed>|\Closure(T): array<string, mixed> $meta
     * @return Decoder<I, T>
     */
    public function refine(
        callable $predicate,
        string $code,
        string $message,
        array|\Closure $meta = [],
        ?string $messageKey = null,
    ): Decoder {
        // The issue is made now, by Issue, so that every part known now is held to Issue's own
        // invariants now. Only metadata a Closure computes from the value waits for the value.
        $issue = Issue::custom(Path::root(), $code, $message, is_array($meta) ? $meta : [], $messageKey);
        return CallableDecoder::of(
            fn (mixed $in, ?Path $path = null): Result => $this->decode($in, $path)->flatMap(
                static fn (mixed $v): Result => $predicate($v)
                    ? Result::ok($v)
                    : Result::err(Issues::of([
                        ($meta instanceof \Closure ? $issue->withMeta($meta($v)) : $issue)->rebase($path ?? Path::root()),
                    ])),
            ),
        );
    }

    /**
     * Null for a null input; anything else, an absent value included, goes to this decoder.
     *
     * @return Decoder<I, T|null>
     */
    public function nullable(): Decoder
    {
        return Decoders::nullable($this);
    }

    /**
     * The default for a null or absent input; anything else goes to this decoder.
     *
     * @param T $fallback
     * @return Decoder<I, T>
     */
    public function withDefault(mixed $fallback): Decoder
    {
        return Decoders::withDefault($this, $fallback);
    }

    /**
     * The fallback in place of any failure.
     *
     * @param T $fallback
     * @return Decoder<I, T>
     */
    public function recover(mixed $fallback): Decoder
    {
        return Decoders::recover($this, $fallback);
    }

    /**
     * What the function computes from the issues, in place of any failure.
     *
     * @param callable(Issues): T $recovery
     * @return Decoder<I, T>
     */
    public function recoverWith(callable $recovery): Decoder
    {
        return Decoders::recoverWith($this, $recovery);
    }

    /**
     * @template U
     * @param Decoder<T, U> $next
     * @return Decoder<I, U>
     */
    public function pipe(Decoder $next): Decoder
    {
        return CallableDecoder::of(
            fn (mixed $in, ?Path $path = null) => $this->decode($in, $path)
                ->flatMap(fn (mixed $v) => $next->decode($v, $path)),
        );
    }

    /**
     * An array of what this decoder reads, as {@see Decoders::list_()} reads one.
     *
     * @return Decoder<mixed, list<T>>
     */
    public function asList(): Decoder
    {
        return Decoders::list_($this);
    }
}
