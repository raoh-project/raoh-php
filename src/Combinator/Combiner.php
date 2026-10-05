<?php

declare(strict_types=1);

namespace Raoh\Combinator;

use Raoh\CallableDecoder;
use Raoh\Decoder;
use Raoh\Decoders;
use Raoh\Err;
use Raoh\FieldDecoder;
use Raoh\Issues;
use Raoh\Ok;
use Raoh\Path;
use Raoh\Result;

/**
 * Applicative combiner — runs N decoders independently, accumulates all errors.
 * Replaces Java's Combiner2–Combiner16 hierarchy with a single variadic class.
 */
final class Combiner
{
    /**
     * @param list<Decoder<mixed, mixed>> $decoders
     */
    public function __construct(private readonly array $decoders)
    {
        foreach ($decoders as $decoder) {
            if (!$decoder instanceof Decoder) {
                throw new \InvalidArgumentException('combine takes decoders, not ' . get_debug_type($decoder));
            }
        }
    }

    /**
     * Run all decoders, accumulate errors.
     * On success, spread values as positional arguments to $f.
     *
     * @param callable $f
     * @return Decoder<mixed, mixed>
     */
    public function map(callable $f): Decoder
    {
        return CallableDecoder::of(function (mixed $in, ?Path $path = null) use ($f): Result {
            [$values, $issues] = $this->runAll($in, $path ?? Path::root());
            if (!$issues->isEmpty()) {
                return Result::err($issues);
            }
            return Result::ok($f(...$values));
        });
    }

    /**
     * Like map(), but $f returns a Result (allows further validation after combining).
     *
     * @param callable $f
     * @return Decoder<mixed, mixed>
     */
    public function flatMap(callable $f): Decoder
    {
        return CallableDecoder::of(function (mixed $in, ?Path $path = null) use ($f): Result {
            $resolvedPath = $path ?? Path::root();
            [$values, $issues] = $this->runAll($in, $resolvedPath);
            if (!$issues->isEmpty()) {
                return Result::err($issues);
            }
            $result = $f(...$values);
            if ($result instanceof Err) {
                return Result::err($result->issues->rebase($resolvedPath));
            }
            return $result;
        });
    }

    /**
     * Like map(), but also rejects unknown fields in the input array.
     * Automatically collects known field names from FieldDecoder instances.
     *
     * @param callable $f
     * @return Decoder<mixed, mixed>
     */
    public function strict(callable $f): Decoder
    {
        $knownFields = [];
        foreach ($this->decoders as $dec) {
            if ($dec instanceof FieldDecoder) {
                $knownFields[] = $dec->fieldName();
            }
        }
        return Decoders::strict($this->map($f), $knownFields);
    }

    /**
     * @return array{list<mixed>, Issues}
     */
    private function runAll(mixed $in, Path $path): array
    {
        $accumulated = Issues::empty();
        $values = [];
        foreach ($this->decoders as $decoder) {
            $r = $decoder->decode($in, $path);
            if ($r instanceof Ok) {
                $values[] = $r->value;
            } else {
                assert($r instanceof Err);
                $accumulated = $accumulated->merge($r->issues);
            }
        }
        return [$values, $accumulated];
    }
}
