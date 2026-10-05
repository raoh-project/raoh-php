<?php

declare(strict_types=1);

namespace Raoh\Conformance;

use Raoh\Absent;
use Raoh\Input\JsonNumber;
use Raoh\Input\JsonObject;
use Raoh\Internal\Number\Floats;
use Raoh\Internal\Wire;
use Raoh\Issue;
use Raoh\Issues;
use Raoh\Present;
use Raoh\PresentNull;
use Raoh\Value\Decimal;
use Raoh\Value\Float32;
use Raoh\Value\Temporal\Instant;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;
use Raoh\Value\Temporal\LocalTime;
use Raoh\Value\Temporal\OffsetDateTime;

/**
 * The types of the value model as the runner holds them, and their observations
 * (spec/value-model.md, spec/observation.md).
 *
 * A type is an array: `['kind' => 'int32']`, `['kind' => 'list', 'of' => T]`,
 * `['kind' => 'product', 'parts' => [T, ...]]`, `['kind' => 'symbol', 'symbols' => [...]]`.
 *
 * @phpstan-type Ty array{kind: string, of?: array<string, mixed>, parts?: list<array<string, mixed>>, symbols?: list<string>}
 */
final class Value
{
    private const TEMPORALS = [
        'date' => LocalDate::class,
        'time' => LocalTime::class,
        'datetime' => LocalDateTime::class,
        'offset_datetime' => OffsetDateTime::class,
        'instant' => Instant::class,
    ];

    private function __construct()
    {
    }

    /** @return array{kind: string} */
    public static function t(string $kind): array
    {
        return ['kind' => $kind];
    }

    /**
     * @param array<string, mixed> $of
     * @return array{kind: string, of: array<string, mixed>}
     */
    public static function of(string $kind, array $of): array
    {
        return ['kind' => $kind, 'of' => $of];
    }

    /**
     * Reads a node of a case file as an observation of the type: a value argument, a default, or
     * an encoder's input.
     *
     * @param array<string, mixed> $ty
     */
    public static function read(array $ty, mixed $json): mixed
    {
        $wrong = static fn (): \InvalidArgumentException => new \InvalidArgumentException(
            Json::write($json) . ' is not an observation of ' . json_encode($ty),
        );
        switch ($ty['kind']) {
            case 'bool':
                return is_bool($json) ? $json : throw $wrong();
            case 'int32':
            case 'int64':
                if (!$json instanceof JsonNumber || preg_match('/\A-?[0-9]+\z/', $json->lexeme) !== 1) {
                    throw $wrong();
                }
                return (int) $json->lexeme;
            case 'float32':
            case 'float64':
                $width = $ty['kind'] === 'float32' ? 32 : 64;
                $v = self::readFloat($json, $width) ?? throw $wrong();
                return $width === 32 ? new Float32($v) : $v;
            case 'decimal':
                return (is_string($json) ? Decimal::parse($json) : null) ?? throw $wrong();
            case 'string':
            case 'symbol':
            case 'uuid':
            case 'uri':
                return is_string($json) ? $json : throw $wrong();
            case 'date':
            case 'time':
            case 'datetime':
            case 'offset_datetime':
            case 'instant':
                $class = self::TEMPORALS[$ty['kind']];
                return (is_string($json) ? $class::parse($json) : null) ?? throw $wrong();
            case 'list':
            case 'set':
                if (!is_array($json)) {
                    throw $wrong();
                }
                return array_map(static fn (mixed $e): mixed => self::read($ty['of'], $e), $json);
            case 'map':
                if (!$json instanceof JsonObject) {
                    throw $wrong();
                }
                $out = [];
                foreach ($json->members() as $k => $v) {
                    $out[$k] = self::read($ty['of'], $v);
                }
                return $out;
            case 'product':
                if (!is_array($json) || count($json) !== count($ty['parts'])) {
                    throw $wrong();
                }
                return array_map(static fn (mixed $e, array $t): mixed => self::read($t, $e), $json, $ty['parts']);
            case 'presence':
                if ($json === 'absent') {
                    return new Absent();
                }
                if ($json === 'null') {
                    return new PresentNull();
                }
                if ($json instanceof JsonObject && count($json) === 1 && $json->has('present')) {
                    return new Present(self::read($ty['of'], $json->get('present')));
                }
                throw $wrong();
            case 'optional':
            case 'nullable':
                return $json === null ? null : self::read($ty['of'], $json);
        }
        throw $wrong();
    }

    private static function readFloat(mixed $json, int $width): ?float
    {
        if ($json instanceof JsonNumber) {
            return Floats::fromLexeme($json->lexeme, $width);
        }
        if ($json instanceof JsonObject && count($json) === 1) {
            return match ($json->get('float')) {
                '-0' => -0.0,
                'NaN' => NAN,
                '+Infinity' => INF,
                '-Infinity' => -INF,
                default => null,
            };
        }
        return null;
    }

    /**
     * Writes a value of the type as its observation, a tree {@see Json::write()} writes.
     *
     * @param array<string, mixed> $ty
     */
    public static function observe(array $ty, mixed $v): mixed
    {
        $mismatch = static fn (): \UnexpectedValueException => new \UnexpectedValueException(
            get_debug_type($v) . ' is not a value of ' . json_encode($ty),
        );
        switch ($ty['kind']) {
            case 'list':
            case 'set':
                return is_array($v) && array_is_list($v)
                    ? array_map(static fn (mixed $e): mixed => self::observe($ty['of'], $e), $v)
                    : throw $mismatch();
            case 'map':
                if (!is_array($v)) {
                    throw $mismatch();
                }
                $out = [];
                foreach ($v as $k => $e) {
                    $out[(string) $k] = self::observe($ty['of'], $e);
                }
                return new JsonObject($out);
            case 'product':
                if (!is_array($v) || count($v) !== count($ty['parts'])) {
                    throw $mismatch();
                }
                return array_map(static fn (mixed $e, array $t): mixed => self::observe($t, $e), $v, $ty['parts']);
            case 'presence':
                return match (true) {
                    $v instanceof Absent => 'absent',
                    $v instanceof PresentNull => 'null',
                    $v instanceof Present => new JsonObject(['present' => self::observe($ty['of'], $v->value)]),
                    default => throw $mismatch(),
                };
            case 'optional':
            case 'nullable':
                return $v === null ? null : self::observe($ty['of'], $v);
            case 'int32':
            case 'int64':
                return is_int($v) ? new JsonNumber((string) $v) : throw $mismatch();
            case 'float32':
                return $v instanceof Float32 ? self::float($v->value, 32) : throw $mismatch();
            case 'float64':
                return is_float($v) ? self::float($v, 64) : throw $mismatch();
            case 'decimal':
                return $v instanceof Decimal ? (string) $v : throw $mismatch();
            case 'bool':
                return is_bool($v) ? $v : throw $mismatch();
            case 'string':
            case 'symbol':
            case 'uuid':
            case 'uri':
                return is_string($v) ? $v : throw $mismatch();
            default:
                $class = self::TEMPORALS[$ty['kind']] ?? null;
                return $class !== null && $v instanceof $class ? (string) $v : throw $mismatch();
        }
    }

    /**
     * A metadata value, written by what the PHP value is: the library's values carry their
     * types, a float32 as a Float32 and a float64 as a PHP float.
     */
    public static function meta(mixed $v): mixed
    {
        return match (true) {
            $v === null, is_bool($v), is_string($v) => $v,
            is_int($v) => new JsonNumber((string) $v),
            is_float($v) => self::float($v, 64),
            $v instanceof Float32 => self::float($v->value, 32),
            Wire::isText($v) => (string) $v,
            $v instanceof Issues => array_map(static fn (Issue $i): JsonObject => self::issue($i, false), $v->toArray()),
            is_array($v) && array_is_list($v) => array_map(self::meta(...), $v),
            is_array($v) => new JsonObject(array_map(self::meta(...), $v)),
            default => throw new \UnexpectedValueException('no observation of ' . get_debug_type($v)),
        };
    }

    /**
     * An issue as a case writes one; the issues `one_of_failed` lists have no message key.
     */
    public static function issue(Issue $issue, bool $withKey = true): JsonObject
    {
        $out = ['path' => $issue->path->toJsonPointer(), 'code' => $issue->code];
        if ($withKey) {
            $out['message_key'] = $issue->messageKey;
        }
        $out['message'] = $issue->message;
        $out['meta'] = new JsonObject(array_map(self::meta(...), $issue->meta));
        return new JsonObject($out);
    }

    private static function float(float $v, int $width): JsonNumber|JsonObject
    {
        $o = Floats::observation($v, $width);
        return is_array($o) ? new JsonObject($o) : new JsonNumber($o);
    }
}
