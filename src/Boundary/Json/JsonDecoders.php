<?php

declare(strict_types=1);

namespace Raoh\Boundary\Json;

use Raoh\Boundary\Array_\ArrayDecoders;
use Raoh\CallableDecoder;
use Raoh\Decoder;
use Raoh\Input\Json;
use Raoh\Internal\Input;
use Raoh\Path;
use Raoh\Result;

final class JsonDecoders
{
    private function __construct()
    {
    }

    /**
     * Wrap any decoder to accept a raw JSON string as input. The text is read into the input
     * model by {@see Json::parse()}, which keeps every number as it is written, and then given
     * to $dec. Text that is not JSON gives `invalid_format`.
     *
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return Decoder<mixed, T>
     */
    public static function fromJson(Decoder $dec, int $depth = 512): Decoder
    {
        if ($depth < 1) {
            throw new \InvalidArgumentException('depth must be at least 1');
        }
        return CallableDecoder::of(function (mixed $in, ?Path $path = null) use ($dec, $depth): Result {
            $p = $path ?? Path::root();
            if (!is_string($in)) {
                return Input::mismatch($in, $p, 'string');
            }
            try {
                $decoded = Json::parse($in, $depth);
            } catch (\JsonException $e) {
                return Result::issue($p, 'invalid_format.json', ['reason' => $e->getMessage()]);
            }
            return $dec->decode($decoded, $p);
        });
    }

    // Convenience re-exports from ArrayDecoders

    /** @return \Raoh\Builtin\StringDecoder */
    public static function string_(): \Raoh\Builtin\StringDecoder
    {
        return ArrayDecoders::string_();
    }

    /** @return \Raoh\Builtin\IntDecoder */
    public static function int_(): \Raoh\Builtin\IntDecoder
    {
        return ArrayDecoders::int_();
    }

    /** @return \Raoh\Builtin\FloatDecoder */
    public static function float_(): \Raoh\Builtin\FloatDecoder
    {
        return ArrayDecoders::float_();
    }

    /** @return \Raoh\Builtin\BoolDecoder */
    public static function bool_(): \Raoh\Builtin\BoolDecoder
    {
        return ArrayDecoders::bool_();
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return \Raoh\FieldDecoder<mixed, T>
     */
    public static function field(string $name, Decoder $dec): \Raoh\FieldDecoder
    {
        return ArrayDecoders::field($name, $dec);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return \Raoh\FieldDecoder<mixed, T|null>
     */
    public static function optionalField(string $name, Decoder $dec): \Raoh\FieldDecoder
    {
        return ArrayDecoders::optionalField($name, $dec);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return Decoder<mixed, T>
     */
    public static function nested(Decoder $dec): Decoder
    {
        return ArrayDecoders::nested($dec);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return Decoder<mixed, list<T>>
     */
    public static function listOf(Decoder $dec): Decoder
    {
        return ArrayDecoders::listOf($dec);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return Decoder<mixed, T|null>
     */
    public static function nullable(Decoder $dec): Decoder
    {
        return ArrayDecoders::nullable($dec);
    }

    /** @param Decoder<mixed, mixed> ...$decoders */
    public static function combine(Decoder ...$decoders): \Raoh\Combinator\Combiner
    {
        return ArrayDecoders::combine(...$decoders);
    }
}
