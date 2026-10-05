<?php

declare(strict_types=1);

namespace Raoh\Conformance;

use Raoh\Builtin\BoolDecoder;
use Raoh\Builtin\DecimalDecoder;
use Raoh\Builtin\DictDecoder;
use Raoh\Builtin\DoubleDecoder;
use Raoh\Builtin\FloatDecoder;
use Raoh\Builtin\IntDecoder;
use Raoh\Builtin\ListDecoder;
use Raoh\Builtin\LongDecoder;
use Raoh\Builtin\NumberDecoder;
use Raoh\Builtin\StringDecoder;
use Raoh\Builtin\TemporalDecoder;
use Raoh\Decoder;
use Raoh\Decoders as D;
use Raoh\FieldDecoder;
use Raoh\Input\JsonObject;

use function Raoh\Boundary\Array_\Encode\object_;
use function Raoh\Boundary\Array_\Encode\property;
use function Raoh\Boundary\Array_\Encode\string_ as stringEncoder;
use function Raoh\Boundary\Array_\Encode\with_default;

/**
 * Translates the forms of a case (spec/decoder-language.md) into raoh-php's decoders and
 * encoders, recording every feature they use.
 */
final class Binder
{
    /** @var array<string, true> */
    public array $used = [];

    /**
     * @param array<string, list<array{kind: string, optional: bool}>> $constructors
     * @param array<string, list<array{kind: string, optional: bool}>> $operations
     */
    public function __construct(private readonly array $constructors, private readonly array $operations)
    {
    }

    /**
     * The decoder a form names, and its result type.
     *
     * @return array{Decoder<mixed, mixed>, array<string, mixed>}
     */
    public function decoder(mixed $form): array
    {
        $f = self::form($form);
        $name = $f[0];
        $this->use("decoder.{$name}");
        $defs = $this->constructors[$name] ?? throw new \InvalidArgumentException("no constructor {$name}");
        $required = count(array_filter($defs, static fn (array $d): bool => !$d['optional']));
        $args = array_slice($f, 1, $required);
        $rest = array_slice($f, 1 + $required);
        $message = null;
        $takesMessage = array_filter($defs, static fn (array $d): bool => $d['kind'] === 'message') !== [];
        if ($takesMessage && isset($rest[0]) && is_string($rest[0])) {
            $this->use("decoder.{$name}.message");
            $message = $rest[0];
            $rest = array_slice($rest, 1);
        }
        $built = $this->construct($name, $args, $message);
        foreach ($rest as $operation) {
            $built = $this->operation($built, $operation);
        }
        return $built;
    }

    /**
     * The JSON the encoder form writes for the value the observation denotes.
     */
    public function encode(mixed $form, mixed $input): mixed
    {
        $f = self::form($form);
        $this->use("encoder.{$f[0]}");
        if ($f[0] === 'string') {
            return stringEncoder()->encode(Value::read(Value::t('string'), $input));
        }
        if ($f[0] !== 'object') {
            throw new \InvalidArgumentException("no encoder {$f[0]}");
        }
        $properties = [];
        foreach ($f[1] as $each) {
            $p = self::form($each);
            $this->use("property.{$p[0]}");
            if ($p[0] !== 'propertyWithDefault') {
                throw new \InvalidArgumentException("no property {$p[0]}");
            }
            $this->use("fixture.{$p[2]}");
            if ($p[2] !== 'identity') {
                throw new \InvalidArgumentException("no getter fixture {$p[2]}");
            }
            $inner = self::form($p[3]);
            $this->use("encoder.{$inner[0]}");
            if ($inner[0] !== 'string') {
                throw new \InvalidArgumentException("no encoder {$inner[0]} in a property");
            }
            $default = Value::read(Value::t('string'), $p[4]);
            $properties[] = property($p[1], Fixtures::identity(...), with_default(stringEncoder(), $default));
        }
        // identity reads the value being encoded, a nullable<string>, as the property's value.
        $value = Value::read(Value::of('nullable', Value::t('string')), $input);
        return object_(...$properties)->encode($value);
    }

    private function use(string $feature): void
    {
        $this->used[$feature] = true;
    }

    /**
     * @param list<mixed> $args
     * @return array{Decoder<mixed, mixed>, array<string, mixed>}
     */
    private function construct(string $name, array $args, ?string $message): array
    {
        switch ($name) {
            case 'string':
                return [D::string_(), Value::t('string')];
            case 'int':
                return [D::int_(), Value::t('int32')];
            case 'long':
                return [D::long(), Value::t('int64')];
            case 'float':
                return [D::float_(), Value::t('float32')];
            case 'double':
                return [D::double(), Value::t('float64')];
            case 'decimal':
                return [D::decimal(), Value::t('decimal')];
            case 'bool':
                return [D::bool_(), Value::t('bool')];
            case 'list':
                [$element, $ty] = $this->decoder($args[0]);
                return [D::list_($element), Value::of('list', $ty)];
            case 'dict':
                [$value, $ty] = $this->decoder($args[0]);
                return [D::dict($value), Value::of('map', $ty)];
            case 'object':
            case 'strictObject':
                [$fields, $tys] = $this->fields($args[0]);
                $object = D::object(...$fields);
                return [$name === 'object' ? $object : $object->strict(), ['kind' => 'product', 'parts' => $tys]];
            case 'strict':
                [$inner, $ty] = $this->decoder($args[0]);
                return [D::strict($inner, Value::read(Value::of('list', Value::t('string')), $args[1])), $ty];
            case 'nullable':
                [$inner, $ty] = $this->decoder($args[0]);
                return [$inner->nullable(), Value::of('nullable', $ty)];
            case 'enum':
                $symbols = Value::read(Value::of('list', Value::t('string')), $args[0]);
                return [
                    D::enumOf($symbols, $this->stringDecoder($args[1]), $message),
                    ['kind' => 'symbol', 'symbols' => $symbols],
                ];
            case 'literal':
                return [
                    D::literal(Value::read(Value::t('string'), $args[0]), $this->stringDecoder($args[1]), $message),
                    Value::t('string'),
                ];
            case 'discriminate':
                [$variants, $ty] = $this->variants($args[1]);
                return [D::discriminate(Value::read(Value::t('string'), $args[0]), $variants), $ty];
            case 'discriminateBy':
                [$tag, $tagTy] = $this->decoder($args[1]);
                if ($tagTy['kind'] !== 'string') {
                    throw new \InvalidArgumentException('the tag decoder does not give a string');
                }
                [$variants, $ty] = $this->variants($args[2]);
                return [D::discriminateBy(Value::read(Value::t('string'), $args[0]), $tag, $variants), $ty];
            case 'oneOf':
                $candidates = array_map(fn (mixed $c): array => $this->decoder($c), $args[0]);
                return [D::oneOf(...array_column($candidates, 0)), $candidates[0][1]];
            case 'withDefault':
                [$inner, $ty] = $this->decoder($args[0]);
                return [$inner->withDefault(Value::read($ty, $args[1])), $ty];
            case 'recover':
                [$inner, $ty] = $this->decoder($args[0]);
                return [$inner->recover(Value::read($ty, $args[1])), $ty];
            case 'recoverWith':
                [$inner, $ty] = $this->decoder($args[0]);
                $this->use("fixture.{$args[1]}");
                if ($args[1] !== 'issue_count_plus_10') {
                    throw new \InvalidArgumentException("no recover fixture {$args[1]}");
                }
                return [$inner->recoverWith(Fixtures::issueCountPlus10(...)), $ty];
        }
        throw new \InvalidArgumentException("no constructor {$name}");
    }

    /**
     * @return array{list<Decoder<mixed, mixed>>, list<array<string, mixed>>}
     */
    private function fields(mixed $value): array
    {
        $fields = [];
        $tys = [];
        foreach ($value as $each) {
            $f = self::form($each);
            $this->use("field.{$f[0]}");
            if ($f[0] === 'flat') {
                [$inner, $ty] = $this->decoder($f[1]);
                $fields[] = $inner;
                $tys[] = $ty;
                continue;
            }
            $name = Value::read(Value::t('string'), $f[1]);
            [$inner, $ty] = $this->decoder($f[2]);
            [$fields[], $tys[]] = match ($f[0]) {
                'field' => [D::field($name, $inner), $ty],
                'optionalField' => [D::optionalField($name, $inner), Value::of('optional', $ty)],
                'optionalNullableField' => [D::optionalNullableField($name, $inner), Value::of('presence', $ty)],
                default => throw new \InvalidArgumentException("no field kind {$f[0]}"),
            };
        }
        return [$fields, $tys];
    }

    /**
     * @return array{array<string, Decoder<mixed, mixed>>, array<string, mixed>}
     */
    private function variants(mixed $value): array
    {
        if (!$value instanceof JsonObject) {
            throw new \InvalidArgumentException('variants are an object');
        }
        $variants = [];
        $ty = null;
        foreach ($value->members() as $tag => $form) {
            [$variants[$tag], $t] = $this->decoder($form);
            $ty ??= $t;
        }
        return [$variants, $ty ?? throw new \InvalidArgumentException('there is no variant')];
    }

    private function stringDecoder(mixed $form): StringDecoder
    {
        [$d] = $this->decoder($form);
        if (!$d instanceof StringDecoder) {
            throw new \InvalidArgumentException('not a string decoder');
        }
        return $d;
    }

    /**
     * @param array{Decoder<mixed, mixed>, array<string, mixed>} $built
     * @return array{Decoder<mixed, mixed>, array<string, mixed>}
     */
    private function operation(array $built, mixed $form): array
    {
        [$decoder, $ty] = $built;
        $f = self::form($form);
        $name = $f[0];
        $generic = in_array($name, ['map', 'refine', 'flatMap'], true);
        $kind = $generic ? 'any' : $ty['kind'];
        $this->use("operation.{$kind}.{$name}");
        [$values, $message] = $this->split($name, array_slice($f, 1));
        if ($message !== null) {
            $this->use("operation.{$kind}.{$name}.message");
        }
        if ($generic) {
            $fixture = Value::read(Value::t('string'), $values[0]);
            $this->use("fixture.{$fixture}");
            return Fixtures::apply($decoder, $ty, $name, $fixture);
        }
        $arg = static fn (int $i, array $t): mixed => array_key_exists($i, $values) ? Value::read($t, $values[$i]) : null;
        $no = static fn (): \InvalidArgumentException => new \InvalidArgumentException(
            "no operation {$name} on " . json_encode($ty),
        );
        if ($decoder instanceof StringDecoder) {
            return self::stringOperation($decoder, $name, $arg, $message) ?? throw $no();
        }
        if ($decoder instanceof NumberDecoder) {
            $d = match ($name) {
                'min' => $decoder->min($arg(0, $ty), $message),
                'max' => $decoder->max($arg(0, $ty), $message),
                'range' => $decoder->range($arg(0, $ty), $arg(1, $ty), $message),
                'positive' => $decoder->positive($message),
                'negative' => $decoder->negative($message),
                'nonNegative' => $decoder->nonNegative($message),
                'nonPositive' => $decoder->nonPositive($message),
                'oneOf' => $decoder instanceof IntDecoder || $decoder instanceof LongDecoder
                    || $decoder instanceof FloatDecoder || $decoder instanceof DoubleDecoder
                    ? $decoder->oneOf($arg(0, Value::of('list', $ty)), $message) : throw $no(),
                'multipleOf' => $decoder instanceof IntDecoder || $decoder instanceof LongDecoder
                    || $decoder instanceof DecimalDecoder
                    ? $decoder->multipleOf($arg(0, $ty), $message) : throw $no(),
                'scale' => $decoder instanceof DecimalDecoder
                    ? $decoder->scale($arg(0, Value::t('int32')), $message) : throw $no(),
                default => throw $no(),
            };
            return [$d, $ty];
        }
        if ($decoder instanceof TemporalDecoder) {
            $d = match ($name) {
                'before' => $decoder->before($arg(0, $ty), $message),
                'after' => $decoder->after($arg(0, $ty), $message),
                'between' => $decoder->between($arg(0, $ty), $arg(1, $ty), $message),
                default => throw $no(),
            };
            return [$d, $ty];
        }
        if ($decoder instanceof BoolDecoder && $name === 'isTrue') {
            return [$decoder->isTrue($message), $ty];
        }
        if ($decoder instanceof ListDecoder) {
            return match ($name) {
                'nonempty' => [$decoder->nonempty($message), $ty],
                'minSize' => [$decoder->minSize($arg(0, Value::t('int32')), $message), $ty],
                'maxSize' => [$decoder->maxSize($arg(0, Value::t('int32')), $message), $ty],
                'fixedSize' => [$decoder->fixedSize($arg(0, Value::t('int32')), $message), $ty],
                'unique' => [$decoder->unique($message), $ty],
                'contains' => [$decoder->contains($arg(0, $ty['of']), $message), $ty],
                'containsAll' => [$decoder->containsAll($arg(0, Value::of('list', $ty['of'])), $message), $ty],
                'toSet' => [$decoder->toSet(), Value::of('set', $ty['of'])],
                default => throw $no(),
            };
        }
        if ($decoder instanceof DictDecoder) {
            return match ($name) {
                'nonempty' => [$decoder->nonempty($message), $ty],
                'minSize' => [$decoder->minSize($arg(0, Value::t('int32')), $message), $ty],
                'maxSize' => [$decoder->maxSize($arg(0, Value::t('int32')), $message), $ty],
                'fixedSize' => [$decoder->fixedSize($arg(0, Value::t('int32')), $message), $ty],
                default => throw $no(),
            };
        }
        throw $no();
    }

    /**
     * @param callable(int, array<string, mixed>): mixed $arg
     * @return array{Decoder<mixed, mixed>, array<string, mixed>}|null
     */
    private static function stringOperation(StringDecoder $d, string $name, callable $arg, ?string $message): ?array
    {
        $s = Value::t('string');
        $string = static fn (Decoder $decoder): array => [$decoder, $s];
        return match ($name) {
            'trim' => $string($d->trim()),
            'toLowerCase' => $string($d->toLowerCase()),
            'toUpperCase' => $string($d->toUpperCase()),
            'normalize' => $string($d->normalize($arg(0, $s) ?? 'NFC')),
            'nonBlank' => $string($d->nonBlank($message)),
            'minLength' => $string($d->minLength($arg(0, Value::t('int32')), $message)),
            'maxLength' => $string($d->maxLength($arg(0, Value::t('int32')), $message)),
            'fixedLength' => $string($d->fixedLength($arg(0, Value::t('int32')), $message)),
            'oneOf' => $string($d->oneOf($arg(0, Value::of('list', $s)), $message)),
            'startsWith' => $string($d->startsWith($arg(0, $s), $message)),
            'endsWith' => $string($d->endsWith($arg(0, $s), $message)),
            'includes' => $string($d->includes($arg(0, $s), $message)),
            'pattern' => $string($d->pattern($arg(0, $s), $message)),
            'email' => $string($d->email($message)),
            'ipv4' => $string($d->ipv4($message)),
            'ipv6' => $string($d->ipv6($message)),
            'ip' => $string($d->ip($message)),
            'ulid' => $string($d->ulid($message)),
            'cuid' => $string($d->cuid($message)),
            'uuid' => [$d->uuid($message), Value::t('uuid')],
            'url' => [$d->url($message), Value::t('uri')],
            'uri' => [$d->uri($message), Value::t('uri')],
            'toInt' => [$d->toInt($message), Value::t('int32')],
            'toLong' => [$d->toLong($message), Value::t('int64')],
            'toDecimal' => [$d->toDecimal($message), Value::t('decimal')],
            'toBool' => [$d->toBool($message), Value::t('bool')],
            'date' => [$d->date($message), Value::t('date')],
            'time' => [$d->time($message), Value::t('time')],
            'dateTime' => [$d->dateTime($message), Value::t('datetime')],
            'offsetDateTime' => [$d->offsetDateTime($message), Value::t('offset_datetime')],
            'iso8601' => [$d->iso8601($message), Value::t('instant')],
            default => null,
        };
    }

    /**
     * Splits an operation's arguments into its value arguments and its message.
     *
     * @param list<mixed> $given
     * @return array{list<mixed>, ?string}
     */
    private function split(string $name, array $given): array
    {
        $defs = $this->operations[$name] ?? throw new \InvalidArgumentException("no operation {$name}");
        $values = [];
        $message = null;
        $at = 0;
        foreach ($defs as $def) {
            $next = $given[$at] ?? null;
            if ($def['kind'] === 'message') {
                if (is_string($next)) {
                    $message = $next;
                    $at++;
                }
            } elseif ($at < count($given)) {
                $values[] = $next;
                $at++;
            } elseif (!$def['optional']) {
                throw new \InvalidArgumentException("{$name} is missing an argument");
            }
        }
        if ($at < count($given)) {
            throw new \InvalidArgumentException("{$name} has too many arguments");
        }
        return [$values, $message];
    }

    /**
     * @return non-empty-list<mixed>
     */
    private static function form(mixed $value): array
    {
        if (!is_array($value) || !isset($value[0]) || !is_string($value[0])) {
            throw new \InvalidArgumentException(Json::write($value) . ' is not a form');
        }
        return $value;
    }
}
