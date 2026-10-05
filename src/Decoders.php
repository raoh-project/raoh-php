<?php

declare(strict_types=1);

namespace Raoh;

use Raoh\Builtin\BoolDecoder;
use Raoh\Builtin\DecimalDecoder;
use Raoh\Builtin\DictDecoder;
use Raoh\Builtin\DoubleDecoder;
use Raoh\Builtin\FloatDecoder;
use Raoh\Builtin\IntDecoder;
use Raoh\Builtin\Integers;
use Raoh\Builtin\ListDecoder;
use Raoh\Builtin\LongDecoder;
use Raoh\Builtin\ObjectDecoder;
use Raoh\Builtin\StringDecoder;
use Raoh\Combinator\Combiner;
use Raoh\Field\Field;
use Raoh\Field\OptionalField;
use Raoh\Field\OptionalNullableField;
use Raoh\Internal\Input;
use Raoh\Internal\Number\Floats;
use Raoh\Notation199x\ScalarValues;
use Raoh\Value\Decimal;
use Raoh\Value\Float32;

/**
 * The decoders of the Raoh Specification, over its input model: what a JSON text denotes, read
 * by {@see Input\Json::parse()}, or the PHP values an application already has (see
 * {@see Internal\Input}).
 *
 * A null input and an absent one (a member that is not there) both give `required` to the
 * decoders of values; any other kind than the one a decoder reads gives `type_mismatch`.
 */
final class Decoders
{
    private function __construct()
    {
    }

    // Values

    public static function string_(): StringDecoder
    {
        return new StringDecoder(static function (mixed $in, Path $p): Result {
            if (!is_string($in)) {
                return self::mismatch($in, $p, 'string');
            }
            if (ScalarValues::invalidUtf8At($in) !== null) {
                // Not text, which the input model has no place for: a PHP string of other bytes.
                return Result::issue($p, 'invalid_format.utf8');
            }
            return Result::ok($in);
        });
    }

    /** An integer within the int32 range, written with no fraction and no exponent. */
    public static function int_(): IntDecoder
    {
        return new IntDecoder(self::integer(IntDecoder::MIN, IntDecoder::MAX, 'integer'));
    }

    /** An integer within the int64 range, written with no fraction and no exponent. */
    public static function long(): LongDecoder
    {
        return new LongDecoder(self::integer(PHP_INT_MIN, PHP_INT_MAX, 'long'));
    }

    /** Any number, rounded once to the nearest float32, given as a {@see Float32}. */
    public static function float_(): FloatDecoder
    {
        return new FloatDecoder(self::floating(32, 'float'));
    }

    /** Any number, rounded once to the nearest float64, a PHP float. */
    public static function double(): DoubleDecoder
    {
        return new DoubleDecoder(self::floating(64, 'double'));
    }

    /** Any number, as a {@see Decimal} at the scale its text gives. */
    public static function decimal(): DecimalDecoder
    {
        return new DecimalDecoder(static function (mixed $in, Path $p): Result {
            $lexeme = self::isNothing($in) ? null : Input::lexeme($in);
            if ($lexeme === null) {
                return self::mismatch($in, $p, 'number');
            }
            $d = Decimal::fromLexeme($lexeme);
            return $d === null ? Result::issue($p, 'type_mismatch', ['expected' => 'number', 'actual' => 'number']) : Result::ok($d);
        });
    }

    public static function bool_(): BoolDecoder
    {
        return new BoolDecoder(
            static fn (mixed $in, Path $p): Result => is_bool($in) ? Result::ok($in) : self::mismatch($in, $p, 'boolean'),
        );
    }

    // Structures

    /**
     * An array, each element decoded at the path of its index. The issues of every failing
     * element are given, in index order.
     *
     * @template E
     * @param Decoder<mixed, E> $element
     * @return ListDecoder<E>
     */
    public static function list_(Decoder $element): ListDecoder
    {
        return new ListDecoder(static function (mixed $in, Path $p) use ($element): Result {
            $elements = self::isNothing($in) ? null : Input::elements($in);
            if ($elements === null) {
                return self::mismatch($in, $p, 'array');
            }
            return Result::traverse(
                $elements,
                static fn (mixed $e, Path $at): Result => $element->decode($e, $at),
                $p,
            );
        });
    }

    /**
     * An object whose every member's value is decoded, at the path of its name.
     *
     * @template V
     * @param Decoder<mixed, V> $value
     * @return DictDecoder<V>
     */
    public static function dict(Decoder $value): DictDecoder
    {
        return new DictDecoder(static function (mixed $in, Path $p) use ($value): Result {
            $members = self::isNothing($in) ? null : Input::members($in);
            if ($members === null) {
                return self::mismatch($in, $p, 'object');
            }
            $issues = Issues::empty();
            $values = [];
            foreach ($members as $name => $v) {
                $r = $value->decode($v, $p->append((string) $name));
                if ($r instanceof Ok) {
                    $values[$name] = $r->value;
                } else {
                    assert($r instanceof Err);
                    $issues = $issues->merge($r->issues);
                }
            }
            return $issues->isEmpty() ? Result::ok($values) : Result::err($issues);
        });
    }

    /**
     * The fields' values, as a list. See {@see ObjectDecoder}.
     *
     * @param Decoder<mixed, mixed> ...$fields
     */
    public static function object(Decoder ...$fields): ObjectDecoder
    {
        return new ObjectDecoder(...$fields);
    }

    /**
     * As object, also failing with `unknown_field` for every member no field names.
     *
     * @param FieldDecoder<mixed, mixed> ...$fields
     * @return Decoder<mixed, list<mixed>>
     */
    public static function strictObject(FieldDecoder ...$fields): Decoder
    {
        return (new ObjectDecoder(...$fields))->strict();
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $decoder
     * @return Field<T>
     */
    public static function field(string $name, Decoder $decoder): Field
    {
        return new Field($name, $decoder);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $decoder
     * @return OptionalField<T>
     */
    public static function optionalField(string $name, Decoder $decoder): OptionalField
    {
        return new OptionalField($name, $decoder);
    }

    /**
     * @template T
     * @param Decoder<mixed, T> $decoder
     * @return OptionalNullableField<T>
     */
    public static function optionalNullableField(string $name, Decoder $decoder): OptionalNullableField
    {
        return new OptionalNullableField($name, $decoder);
    }

    /**
     * Combine decoders with error accumulation, spreading their values into the function that
     * {@see Combiner::map()} takes.
     *
     * @param Decoder<mixed, mixed> ...$decoders
     */
    public static function combine(Decoder ...$decoders): Combiner
    {
        return new Combiner(array_values($decoders));
    }

    /**
     * Decodes with the inner decoder, and when the input is an object, also fails with
     * `unknown_field` for every member that is not known and that the inner decoder has not
     * already reported unknown, after the inner decoder's issues. The inner decoder's value is
     * discarded when there is an unknown member.
     *
     * @template T
     * @param Decoder<mixed, T> $inner
     * @param list<string> $known
     * @return Decoder<mixed, T>
     */
    public static function strict(Decoder $inner, array $known): Decoder
    {
        $knownSet = array_flip($known);
        return CallableDecoder::of(static function (mixed $in, ?Path $path = null) use ($inner, $knownSet): Result {
            $p = $path ?? Path::root();
            $result = $inner->decode($in, $p);
            $members = self::isNothing($in) ? null : Input::members($in);
            if ($members === null) {
                return $result;
            }
            $reported = [];
            if ($result instanceof Err) {
                foreach ($result->issues->toArray() as $issue) {
                    if ($issue->messageKey === 'unknown_field') {
                        $reported[$issue->path->toJsonPointer()] = true;
                    }
                }
            }
            $unknown = Issues::empty();
            foreach (array_keys($members) as $name) {
                $name = (string) $name;
                $at = $p->append($name);
                if (!isset($knownSet[$name]) && !isset($reported[$at->toJsonPointer()])) {
                    $unknown = $unknown->add(Issue::derived($at, 'unknown_field', ['field' => $name]));
                }
            }
            if ($unknown->isEmpty()) {
                return $result;
            }
            return Result::err($result instanceof Err ? $result->issues->merge($unknown) : $unknown);
        });
    }

    /**
     * Null for a null input; anything else, an absent value included, goes to the decoder.
     *
     * @template T
     * @param Decoder<mixed, T> $dec
     * @return Decoder<mixed, T|null>
     */
    public static function nullable(Decoder $dec): Decoder
    {
        return CallableDecoder::of(
            static fn (mixed $in, ?Path $path = null): Result => $in === null ? Result::ok(null) : $dec->decode($in, $path),
        );
    }

    /**
     * The default for a null or absent input; anything else goes to the decoder, whose failures
     * are given as they are.
     *
     * @template T
     * @param Decoder<mixed, T> $dec
     * @param T $default
     * @return Decoder<mixed, T>
     */
    public static function withDefault(Decoder $dec, mixed $default): Decoder
    {
        return CallableDecoder::of(
            static fn (mixed $in, ?Path $path = null): Result => self::isNothing($in)
                ? Result::ok($default)
                : $dec->decode($in, $path),
        );
    }

    /**
     * The fallback in place of any failure.
     *
     * @template T
     * @param Decoder<mixed, T> $dec
     * @param T $fallback
     * @return Decoder<mixed, T>
     */
    public static function recover(Decoder $dec, mixed $fallback): Decoder
    {
        return self::recoverWith($dec, static fn (): mixed => $fallback);
    }

    /**
     * What the function computes from the issues, in place of any failure.
     *
     * @template T
     * @param Decoder<mixed, T> $dec
     * @param callable(Issues): T $recovery
     * @return Decoder<mixed, T>
     */
    public static function recoverWith(Decoder $dec, callable $recovery): Decoder
    {
        return CallableDecoder::of(static function (mixed $in, ?Path $path = null) use ($dec, $recovery): Result {
            $r = $dec->decode($in, $path);
            return $r instanceof Err ? Result::ok($recovery($r->issues)) : $r;
        });
    }

    /**
     * The first candidate that succeeds on the input. When every one fails, `one_of_failed` at
     * the input's path, listing in `candidates` the issues of each, by index.
     *
     * @template T
     * @param Decoder<mixed, T> ...$candidates
     * @return Decoder<mixed, T>
     */
    public static function oneOf(Decoder ...$candidates): Decoder
    {
        if ($candidates === []) {
            throw new \InvalidArgumentException('oneOf takes at least one decoder');
        }
        $candidates = array_values($candidates);
        return CallableDecoder::of(static function (mixed $in, ?Path $path = null) use ($candidates): Result {
            $p = $path ?? Path::root();
            $failed = [];
            foreach ($candidates as $i => $dec) {
                $r = $dec->decode($in, $p);
                if ($r instanceof Ok) {
                    return $r;
                }
                assert($r instanceof Err);
                $failed[] = ['candidate' => $i, 'issues' => $r->issues];
            }
            return Result::issue($p, 'one_of_failed', ['candidates' => $failed]);
        });
    }

    /**
     * One of the symbols, matched ASCII case-insensitively against what the string decoder gives.
     *
     * The symbols are a list of names, which gives the name as listed, or an enum class: a
     * string-backed enum's symbols are its values, any other enum's its case names, and the
     * decoder gives the case.
     *
     * @param list<string>|class-string<\UnitEnum> $symbols
     * @return Decoder<mixed, mixed>
     */
    public static function enumOf(array|string $symbols, ?StringDecoder $string = null, ?string $message = null): Decoder
    {
        $byName = [];
        if (is_string($symbols)) {
            if (!enum_exists($symbols)) {
                throw new \InvalidArgumentException("{$symbols} is not an enum");
            }
            foreach ($symbols::cases() as $case) {
                $name = $case instanceof \BackedEnum && is_string($case->value) ? $case->value : $case->name;
                $byName[self::asciiLower($name)] = $case;
            }
            $count = count($symbols::cases());
        } else {
            foreach ($symbols as $name) {
                $byName[self::asciiLower($name)] = $name;
            }
            $count = count($symbols);
        }
        if (count($byName) !== $count || $count === 0) {
            throw new \InvalidArgumentException('enum: the symbols are not distinct when A-Z are read as a-z');
        }
        $allowed = array_map('strval', array_keys($byName));
        sort($allowed, SORT_STRING);
        $string ??= self::string_();
        return CallableDecoder::of(
            static fn (mixed $in, ?Path $path = null): Result => $string->decode($in, $path ?? Path::root())
                ->flatMap(static function (string $v) use ($byName, $allowed, $path, $message): Result {
                    $key = self::asciiLower($v);
                    return array_key_exists($key, $byName)
                        ? Result::ok($byName[$key])
                        : Result::issue($path ?? Path::root(), 'invalid_format.enum', ['allowed' => $allowed], $message);
                }),
        );
    }

    /**
     * The literal, compared with what the string decoder gives.
     *
     * @return Decoder<mixed, string>
     */
    public static function literal(string $literal, ?StringDecoder $string = null, ?string $message = null): Decoder
    {
        $string ??= self::string_();
        return CallableDecoder::of(
            static fn (mixed $in, ?Path $path = null): Result => $string->decode($in, $path ?? Path::root())
                ->flatMap(static fn (string $v): Result => $v === $literal
                    ? Result::ok($v)
                    : Result::issue($path ?? Path::root(), 'invalid_format.literal', ['expected' => $literal], $message)),
        );
    }

    /**
     * Reads the tag, the string member the field names, and decodes the whole input with the
     * variant the tag names. A tag that names no variant gives `not_allowed` at the field's path.
     *
     * @param array<string, Decoder<mixed, mixed>> $variants
     * @return Decoder<mixed, mixed>
     */
    public static function discriminate(string $field, array $variants): Decoder
    {
        $tag = self::field($field, self::string_());
        return self::tagged($field, $tag, $variants);
    }

    /**
     * As discriminate, except that the tag is what the tag decoder gives for the whole input; its
     * issues are given as they are.
     *
     * @param Decoder<mixed, string> $tag
     * @param array<string, Decoder<mixed, mixed>> $variants
     * @return Decoder<mixed, mixed>
     */
    public static function discriminateBy(string $field, Decoder $tag, array $variants): Decoder
    {
        return self::tagged($field, $tag, $variants);
    }

    /**
     * Lazily-evaluated decoder — useful for recursive/self-referential structures.
     *
     * @template I
     * @template T
     * @param callable(): Decoder<I, T> $supplier
     * @return Decoder<I, T>
     */
    public static function lazy(callable $supplier): Decoder
    {
        return CallableDecoder::of(
            fn (mixed $in, ?Path $path = null) => $supplier()->decode($in, $path),
        );
    }

    /**
     * @param Decoder<mixed, string> $tag
     * @param array<string, Decoder<mixed, mixed>> $variants
     * @return Decoder<mixed, mixed>
     */
    private static function tagged(string $field, Decoder $tag, array $variants): Decoder
    {
        if ($variants === []) {
            throw new \InvalidArgumentException('discriminate takes at least one variant');
        }
        $allowed = array_map('strval', array_keys($variants));
        sort($allowed, SORT_STRING);
        return CallableDecoder::of(static function (mixed $in, ?Path $path = null) use ($field, $tag, $variants, $allowed): Result {
            $p = $path ?? Path::root();
            return $tag->decode($in, $p)->flatMap(
                static function (string $name) use ($in, $p, $field, $variants, $allowed): Result {
                    if (!array_key_exists($name, $variants)) {
                        return Result::issue($p->append($field), 'not_allowed', ['allowed' => $allowed]);
                    }
                    return $variants[$name]->decode($in, $p);
                },
            );
        });
    }

    /**
     * @return \Closure(mixed, Path): Result<int>
     */
    private static function integer(int $min, int $max, string $expected): \Closure
    {
        return static function (mixed $in, Path $p) use ($min, $max, $expected): Result {
            $lexeme = self::isNothing($in) ? null : Input::lexeme($in);
            if ($lexeme === null) {
                return self::mismatch($in, $p, $expected);
            }
            if (preg_match('/\A(-?)([0-9]+)\z/', $lexeme, $m) !== 1) {
                return Result::issue($p, 'type_mismatch', ['expected' => $expected, 'actual' => 'number']);
            }
            $n = Integers::read($m[1] === '-', $m[2], $min, $max);
            return $n === null
                ? Result::issue($p, 'type_mismatch.numeric_range', ['expected' => $expected])
                : Result::ok($n);
        };
    }

    /**
     * @return \Closure(mixed, Path): Result<mixed>
     */
    private static function floating(int $width, string $expected): \Closure
    {
        return static function (mixed $in, Path $p) use ($width, $expected): Result {
            $lexeme = self::isNothing($in) ? null : Input::lexeme($in);
            if ($lexeme === null) {
                return self::mismatch($in, $p, $expected);
            }
            $v = Floats::fromLexeme($lexeme, $width);
            if (is_infinite($v)) {
                return Result::issue($p, 'type_mismatch.numeric_range', ['expected' => $expected]);
            }
            return Result::ok($width === 32 ? new Float32($v) : $v);
        };
    }

    /**
     * `required` for a null or absent input, and otherwise `type_mismatch`.
     *
     * @return Err<never>
     */
    private static function mismatch(mixed $in, Path $p, string $expected): Err
    {
        if (self::isNothing($in)) {
            return Result::issue($p, 'required');
        }
        return Result::issue($p, 'type_mismatch', ['expected' => $expected, 'actual' => Input::kind($in)]);
    }

    private static function isNothing(mixed $in): bool
    {
        return $in === null || $in instanceof Absent;
    }

    private static function asciiLower(string $s): string
    {
        return strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
