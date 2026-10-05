<?php

declare(strict_types=1);

namespace Raoh\Boundary\Array_;

use Raoh\Absent;
use Raoh\CallableDecoder;
use Raoh\Combinator\Combiner;
use Raoh\Decoder;
use Raoh\Decoders;
use Raoh\FieldDecoder;
use Raoh\Internal\Input;
use Raoh\Path;
use Raoh\Present;
use Raoh\PresentNull;
use Raoh\Result;
use Raoh\Builtin\BoolDecoder;
use Raoh\Builtin\DecimalDecoder;
use Raoh\Builtin\DictDecoder;
use Raoh\Builtin\DoubleDecoder;
use Raoh\Builtin\FloatDecoder;
use Raoh\Builtin\IntDecoder;
use Raoh\Builtin\ListDecoder;
use Raoh\Builtin\LongDecoder;
use Raoh\Builtin\ObjectDecoder;
use Raoh\Builtin\StringDecoder;

/**
 * The decoders for PHP arrays and form data, which read them as the input model does (see
 * {@see Input}): an associative array is an object, a list an array.
 * Public API is exposed via functions.php.
 */
final class ArrayDecoders
{
    private function __construct()
    {
    }

    public static function string_(): StringDecoder
    {
        return Decoders::string_();
    }

    public static function int_(): IntDecoder
    {
        return Decoders::int_();
    }

    public static function long(): LongDecoder
    {
        return Decoders::long();
    }

    public static function float_(): FloatDecoder
    {
        return Decoders::float_();
    }

    public static function double(): DoubleDecoder
    {
        return Decoders::double();
    }

    public static function decimal(): DecimalDecoder
    {
        return Decoders::decimal();
    }

    public static function bool_(): BoolDecoder
    {
        return Decoders::bool_();
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return FieldDecoder<mixed, T>
     */
    public static function field(string $name, Decoder $dec): FieldDecoder
    {
        return Decoders::field($name, $dec);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return FieldDecoder<mixed, T|null>
     */
    public static function optionalField(string $name, Decoder $dec): FieldDecoder
    {
        return Decoders::optionalField($name, $dec);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return FieldDecoder<mixed, Absent|PresentNull|Present<T>>
     */
    public static function optionalNullableField(string $name, Decoder $dec): FieldDecoder
    {
        return Decoders::optionalNullableField($name, $dec);
    }

    /**
     * Requires an object, then delegates to $dec. Fields check for an object themselves; this
     * gives one `required` or `type_mismatch` for the whole value instead of one per field.
     *
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return Decoder<mixed, T>
     */
    public static function nested(Decoder $dec): Decoder
    {
        return CallableDecoder::of(static function (mixed $in, ?Path $path = null) use ($dec): Result {
            $p = $path ?? Path::root();
            if (Input::members($in) === null) {
                return Input::mismatch($in, $p, 'object');
            }
            return $dec->decode($in, $p);
        });
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $elementDec
     * @return ListDecoder<T>
     */
    public static function listOf(Decoder $elementDec): ListDecoder
    {
        return Decoders::list_($elementDec);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $valueDec
     * @return DictDecoder<T>
     */
    public static function dict(Decoder $valueDec): DictDecoder
    {
        return Decoders::dict($valueDec);
    }

    /**
     * @param Decoder<mixed, mixed> ...$fields
     */
    public static function object(Decoder ...$fields): ObjectDecoder
    {
        return Decoders::object(...$fields);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return Decoder<mixed, T|null>
     */
    public static function nullable(Decoder $dec): Decoder
    {
        return Decoders::nullable($dec);
    }

    /** @param Decoder<mixed, mixed> ...$decoders */
    public static function combine(Decoder ...$decoders): Combiner
    {
        return Decoders::combine(...$decoders);
    }

    /**
     * @param list<string>|class-string<\UnitEnum> $symbols
     * @return Decoder<mixed, mixed>
     */
    public static function enumOf(array|string $symbols, ?StringDecoder $string = null, ?string $message = null): Decoder
    {
        return Decoders::enumOf($symbols, $string, $message);
    }

    /** @return Decoder<mixed, string> */
    public static function literal(string $expected, ?StringDecoder $string = null, ?string $message = null): Decoder
    {
        return Decoders::literal($expected, $string, $message);
    }

    /**
     * Any PHP string, whatever bytes it holds.
     *
     * @return Decoder<mixed, string>
     */
    public static function bytes(): Decoder
    {
        return CallableDecoder::of(static function (mixed $in, ?Path $path = null): Result {
            $p = $path ?? Path::root();
            if (!is_string($in)) {
                return Input::mismatch($in, $p, 'string');
            }
            return Result::ok($in);
        });
    }
}
