<?php

declare(strict_types=1);

namespace Raoh\Boundary\Json;

use Raoh\Builtin\BoolDecoder;
use Raoh\Builtin\FloatDecoder;
use Raoh\Builtin\IntDecoder;
use Raoh\Builtin\StringDecoder;
use Raoh\Combinator\Combiner;
use Raoh\Decoder;
use Raoh\FieldDecoder;

/**
 * Import these functions with:
 *   use function Raoh\Boundary\Json\{from_json, field, string_, int_, float_, bool_, combine, nested, list_of};
 */

/**
 * Wrap a decoder to accept a raw JSON string. The text is read into the input model by
 * {@see \Raoh\Input\Json::parse()}: $dec is given a JsonObject for an object, a JsonNumber for a
 * number and a list for an array, not what json_decode gives, so a decoder written to read PHP
 * arrays is not one to pass here; the decoders of this library read both.
 *
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return Decoder<mixed, T>
 */
function from_json(Decoder $dec, int $depth = 512): Decoder
{
    return JsonDecoders::fromJson($dec, $depth);
}

/** @return StringDecoder */
function string_(): StringDecoder
{
    return JsonDecoders::string_();
}

/** @return IntDecoder */
function int_(): IntDecoder
{
    return JsonDecoders::int_();
}

/** @return FloatDecoder */
function float_(): FloatDecoder
{
    return JsonDecoders::float_();
}

/** @return BoolDecoder */
function bool_(): BoolDecoder
{
    return JsonDecoders::bool_();
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return FieldDecoder<mixed, T>
 */
function field(string $name, Decoder $dec): FieldDecoder
{
    return JsonDecoders::field($name, $dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return FieldDecoder<mixed, T|null>
 */
function optional_field(string $name, Decoder $dec): FieldDecoder
{
    return JsonDecoders::optionalField($name, $dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return Decoder<mixed, T>
 */
function nested(Decoder $dec): Decoder
{
    return JsonDecoders::nested($dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return Decoder<mixed, list<T>>
 */
function list_of(Decoder $dec): Decoder
{
    return JsonDecoders::listOf($dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return Decoder<mixed, T|null>
 */
function nullable(Decoder $dec): Decoder
{
    return JsonDecoders::nullable($dec);
}

/** @param Decoder<mixed, mixed> ...$decoders */
function combine(Decoder ...$decoders): Combiner
{
    return JsonDecoders::combine(...$decoders);
}
