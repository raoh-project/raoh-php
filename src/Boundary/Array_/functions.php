<?php

declare(strict_types=1);

namespace Raoh\Boundary\Array_;

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
use Raoh\Combinator\Combiner;
use Raoh\Decoder;
use Raoh\Decoders;
use Raoh\FieldDecoder;

/**
 * Import these functions with:
 *   use function Raoh\Boundary\Array_\{field, string_, int_, object, combine, list_of, nullable};
 */

function string_(): StringDecoder
{
    return ArrayDecoders::string_();
}

/** An int32. */
function int_(): IntDecoder
{
    return ArrayDecoders::int_();
}

/** An int64. */
function long(): LongDecoder
{
    return ArrayDecoders::long();
}

/** A float32, given as a {@see \Raoh\Value\Float32}. */
function float_(): FloatDecoder
{
    return ArrayDecoders::float_();
}

/** A float64, a PHP float. */
function double(): DoubleDecoder
{
    return ArrayDecoders::double();
}

function decimal(): DecimalDecoder
{
    return ArrayDecoders::decimal();
}

function bool_(): BoolDecoder
{
    return ArrayDecoders::bool_();
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return FieldDecoder<mixed, T>
 */
function field(string $name, Decoder $dec): FieldDecoder
{
    return ArrayDecoders::field($name, $dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return FieldDecoder<mixed, T|null>
 */
function optional_field(string $name, Decoder $dec): FieldDecoder
{
    return ArrayDecoders::optionalField($name, $dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return FieldDecoder<mixed, \Raoh\Absent|\Raoh\PresentNull|\Raoh\Present<T>>
 */
function optional_nullable_field(string $name, Decoder $dec): FieldDecoder
{
    return ArrayDecoders::optionalNullableField($name, $dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return Decoder<mixed, T>
 */
function nested(Decoder $dec): Decoder
{
    return ArrayDecoders::nested($dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return ListDecoder<T>
 */
function list_of(Decoder $dec): ListDecoder
{
    return ArrayDecoders::listOf($dec);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return DictDecoder<T>
 */
function dict(Decoder $dec): DictDecoder
{
    return ArrayDecoders::dict($dec);
}

/** @param Decoder<mixed, mixed> ...$fields */
function object(Decoder ...$fields): ObjectDecoder
{
    return ArrayDecoders::object(...$fields);
}

/**
 * @param FieldDecoder<mixed, mixed> ...$fields
 * @return Decoder<mixed, list<mixed>>
 */
function strict_object(FieldDecoder ...$fields): Decoder
{
    return Decoders::strictObject(...$fields);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @param list<string> $knownFields
 * @return Decoder<mixed, T>
 */
function strict(Decoder $dec, array $knownFields): Decoder
{
    return Decoders::strict($dec, $knownFields);
}

/**
 * @template T
 * @param Decoder<mixed, T> $dec
 * @return Decoder<mixed, T|null>
 */
function nullable(Decoder $dec): Decoder
{
    return ArrayDecoders::nullable($dec);
}

/** @param Decoder<mixed, mixed> ...$decoders */
function combine(Decoder ...$decoders): Combiner
{
    return ArrayDecoders::combine(...$decoders);
}

/**
 * @template T
 * @param Decoder<mixed, T> ...$candidates
 * @return Decoder<mixed, T>
 */
function one_of(Decoder ...$candidates): Decoder
{
    return Decoders::oneOf(...$candidates);
}

/**
 * @param array<string, Decoder<mixed, mixed>> $variants
 * @return Decoder<mixed, mixed>
 */
function discriminate(string $field, array $variants): Decoder
{
    return Decoders::discriminate($field, $variants);
}

/**
 * @param Decoder<mixed, string> $tag
 * @param array<string, Decoder<mixed, mixed>> $variants
 * @return Decoder<mixed, mixed>
 */
function discriminate_by(string $field, Decoder $tag, array $variants): Decoder
{
    return Decoders::discriminateBy($field, $tag, $variants);
}

/**
 * @param list<string>|class-string<\UnitEnum> $symbols
 * @return Decoder<mixed, mixed>
 */
function enum_of(array|string $symbols, ?StringDecoder $string = null, ?string $message = null): Decoder
{
    return ArrayDecoders::enumOf($symbols, $string, $message);
}

/** @return Decoder<mixed, string> */
function literal(string $expected, ?StringDecoder $string = null, ?string $message = null): Decoder
{
    return ArrayDecoders::literal($expected, $string, $message);
}

/** @return Decoder<mixed, string> */
function bytes(): Decoder
{
    return ArrayDecoders::bytes();
}
