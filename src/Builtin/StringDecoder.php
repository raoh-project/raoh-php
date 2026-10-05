<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Format\Cuid;
use Raoh\Internal\Format\Email;
use Raoh\Internal\Format\Ip;
use Raoh\Internal\Format\Ulid;
use Raoh\Internal\Format\Uri;
use Raoh\Internal\Format\Uuid;
use Raoh\Internal\Text;
use Raoh\Notation199x\CaseConversion;
use Raoh\Notation199x\Normalization;
use Raoh\Notation199x\NormalizationForm;
use Raoh\Notation199x\Pattern;
use Raoh\Notation199x\ScalarValues;
use Raoh\Path;
use Raoh\Result;
use Raoh\Value\Decimal;
use Raoh\Value\Temporal\Instant;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;
use Raoh\Value\Temporal\LocalTime;
use Raoh\Value\Temporal\OffsetDateTime;

/**
 * A decoder of strings, with the operations of the specification's `string`.
 *
 * Text is read by the rules of notation-199x: White_Space, case mapping and normalization of
 * Unicode 18.0.0, lengths in Unicode scalar values, and the pattern language of the
 * specification, whatever Unicode version and PCRE this PHP was built with.
 *
 * Every operation that checks something takes an optional last argument, the message to give in
 * place of the catalogue's.
 *
 * @extends BaseDecoder<string>
 */
final class StringDecoder extends BaseDecoder
{
    // Transforms

    /** Removes leading and trailing White_Space. */
    public function trim(): static
    {
        return $this->then(static fn (string $v): Result => Result::ok(Text::trim($v)));
    }

    /** The default lowercase mapping of Unicode 18.0.0, with no language tailoring. */
    public function toLowerCase(): static
    {
        return $this->then(static fn (string $v): Result => Result::ok(CaseConversion::lowercase($v)));
    }

    /** The default uppercase mapping of Unicode 18.0.0 (ß becomes SS), with no language tailoring. */
    public function toUpperCase(): static
    {
        return $this->then(static fn (string $v): Result => Result::ok(CaseConversion::uppercase($v)));
    }

    /**
     * Unicode 18.0.0 normalization in the form named: NFC, NFD, NFKC or NFKD.
     */
    public function normalize(string $form = 'NFC'): static
    {
        $f = null;
        foreach (NormalizationForm::cases() as $case) {
            if ($case->name === $form) {
                $f = $case;
            }
        }
        if ($f === null) {
            throw new \InvalidArgumentException("no normalization form {$form}");
        }
        return $this->then(static fn (string $v): Result => Result::ok(Normalization::normalize($f, $v)));
    }

    // Checks

    /** Fails for a string that is empty or holds only White_Space. */
    public function nonBlank(?string $message = null): static
    {
        return $this->check(static fn (string $v): bool => !Text::isBlank($v), 'blank', [], $message);
    }

    /** At least that many Unicode scalar values. */
    public function minLength(int $min, ?string $message = null): static
    {
        return $this->then(static function (string $v, Path $p) use ($min, $message): Result {
            $n = ScalarValues::count($v);
            return $n < $min
                ? Result::issue($p, 'too_short', ['min' => $min, 'actual' => $n], $message)
                : Result::ok($v);
        });
    }

    /** At most that many Unicode scalar values. */
    public function maxLength(int $max, ?string $message = null): static
    {
        return $this->then(static function (string $v, Path $p) use ($max, $message): Result {
            $n = ScalarValues::count($v);
            return $n > $max
                ? Result::issue($p, 'too_long', ['max' => $max, 'actual' => $n], $message)
                : Result::ok($v);
        });
    }

    /** Exactly that many Unicode scalar values. */
    public function fixedLength(int $length, ?string $message = null): static
    {
        return $this->then(static function (string $v, Path $p) use ($length, $message): Result {
            $n = ScalarValues::count($v);
            return $n !== $length
                ? Result::issue($p, 'invalid_length', ['expected' => $length, 'actual' => $n], $message)
                : Result::ok($v);
        });
    }

    /**
     * One of the allowed strings, which are distinct.
     *
     * @param list<string> $allowed
     */
    public function oneOf(array $allowed, ?string $message = null): static
    {
        if (count(array_unique($allowed)) !== count($allowed)) {
            throw new \InvalidArgumentException('oneOf: the allowed strings are not distinct');
        }
        $set = array_flip($allowed);
        $sorted = $allowed;
        // UTF-8 compared byte by byte is in code point order.
        sort($sorted, SORT_STRING);
        return $this->check(
            static fn (string $v): bool => isset($set[$v]),
            'not_allowed',
            static fn (string $v): array => ['allowed' => $sorted, 'actual' => $v],
            $message,
        );
    }

    public function startsWith(string $prefix, ?string $message = null): static
    {
        return $this->check(
            static fn (string $v): bool => str_starts_with($v, $prefix),
            'invalid_format.starts_with',
            ['prefix' => $prefix],
            $message,
        );
    }

    public function endsWith(string $suffix, ?string $message = null): static
    {
        return $this->check(
            static fn (string $v): bool => str_ends_with($v, $suffix),
            'invalid_format.ends_with',
            ['suffix' => $suffix],
            $message,
        );
    }

    public function includes(string $substring, ?string $message = null): static
    {
        return $this->check(
            static fn (string $v): bool => str_contains($v, $substring),
            'invalid_format.includes',
            ['substring' => $substring],
            $message,
        );
    }

    /**
     * The whole string is one of the strings the pattern denotes. The pattern is written in the
     * pattern language of the specification (spec/pattern.md), not PCRE's; one it refuses, or one
     * past its limits, is refused here with an \InvalidArgumentException.
     */
    public function pattern(string $pattern, ?string $message = null): static
    {
        $read = Pattern::read($pattern);
        if (!$read instanceof Pattern) {
            throw new \InvalidArgumentException("not a pattern: {$pattern}");
        }
        return $this->check(
            static fn (string $v): bool => $read->matches($v),
            'invalid_format',
            ['pattern' => $pattern],
            $message,
        );
    }

    public function email(?string $message = null): static
    {
        return $this->check(Email::matches(...), 'invalid_format.email', [], $message);
    }

    public function ipv4(?string $message = null): static
    {
        return $this->check(Ip::isV4(...), 'invalid_format.ipv4', [], $message);
    }

    public function ipv6(?string $message = null): static
    {
        return $this->check(Ip::isV6(...), 'invalid_format.ipv6', [], $message);
    }

    public function ip(?string $message = null): static
    {
        return $this->check(Ip::isIp(...), 'invalid_format.ip', [], $message);
    }

    public function ulid(?string $message = null): static
    {
        return $this->check(Ulid::matches(...), 'invalid_format.ulid', [], $message);
    }

    public function cuid(?string $message = null): static
    {
        return $this->check(Cuid::matches(...), 'invalid_format.cuid', [], $message);
    }

    /** A UUID in either case, given as 32 lower-case hexadecimal digits grouped 8-4-4-4-12. */
    public function uuid(?string $message = null): static
    {
        return $this->then(static function (string $v, Path $p) use ($message): Result {
            $uuid = Uuid::read($v);
            return $uuid === null
                ? Result::issue($p, 'invalid_format.uuid', [], $message)
                : Result::ok($uuid);
        });
    }

    /** An RFC 3986 URI with an http or https scheme and a non-empty host, as written. */
    public function url(?string $message = null): static
    {
        return $this->check(Uri::isUrl(...), 'invalid_format.url', [], $message);
    }

    /** An RFC 3986 URI (not a relative reference), as written. */
    public function uri(?string $message = null): static
    {
        return $this->check(Uri::isUri(...), 'invalid_format.uri', [], $message);
    }

    // Conversions

    /** An optional sign and ASCII digits, within the int32 range. */
    public function toInt(?string $message = null): IntDecoder
    {
        return new IntDecoder($this->followedBy(self::integer(IntDecoder::MIN, IntDecoder::MAX, 'integer', $message)));
    }

    /** An optional sign and ASCII digits, within the int64 range. */
    public function toLong(?string $message = null): LongDecoder
    {
        return new LongDecoder($this->followedBy(self::integer(PHP_INT_MIN, PHP_INT_MAX, 'long', $message)));
    }

    /** A decimal number, keeping the scale it is written with. */
    public function toDecimal(?string $message = null): DecimalDecoder
    {
        return new DecimalDecoder($this->followedBy(static function (string $v, Path $p) use ($message): Result {
            $d = Decimal::parse($v);
            return $d === null
                ? Result::issue($p, 'type_mismatch', ['expected' => 'decimal'], $message)
                : Result::ok($d);
        }));
    }

    /** true, 1, yes or on, and false, 0, no or off, ASCII case-insensitively. */
    public function toBool(?string $message = null): BoolDecoder
    {
        return new BoolDecoder($this->followedBy(static function (string $v, Path $p) use ($message): Result {
            return match (strtr($v, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')) {
                'true', '1', 'yes', 'on' => Result::ok(true),
                'false', '0', 'no', 'off' => Result::ok(false),
                default => Result::issue($p, 'type_mismatch', ['expected' => 'boolean'], $message),
            };
        }));
    }

    /**
     * An ISO 8601 calendar date, year-mm-dd.
     *
     * @return TemporalDecoder<LocalDate>
     */
    public function date(?string $message = null): TemporalDecoder
    {
        return $this->temporal(LocalDate::parse(...), 'invalid_format.date', $message);
    }

    /**
     * A local time, hh:mm, hh:mm:ss or hh:mm:ss with a fraction.
     *
     * @return TemporalDecoder<LocalTime>
     */
    public function time(?string $message = null): TemporalDecoder
    {
        return $this->temporal(LocalTime::parse(...), 'invalid_format.time', $message);
    }

    /**
     * A local date-time: a date, T, and a time.
     *
     * @return TemporalDecoder<LocalDateTime>
     */
    public function dateTime(?string $message = null): TemporalDecoder
    {
        return $this->temporal(LocalDateTime::parse(...), 'invalid_format.date_time', $message);
    }

    /**
     * A date-time and an offset, which is kept, not applied.
     *
     * @return TemporalDecoder<OffsetDateTime>
     */
    public function offsetDateTime(?string $message = null): TemporalDecoder
    {
        return $this->temporal(OffsetDateTime::parse(...), 'invalid_format.offset_date_time', $message);
    }

    /**
     * An instant: a date-time with seconds and an offset, which is applied.
     *
     * @return TemporalDecoder<Instant>
     */
    public function iso8601(?string $message = null): TemporalDecoder
    {
        return $this->temporal(Instant::parse(...), 'invalid_format.instant', $message);
    }

    /**
     * @template U of LocalDate|LocalTime|LocalDateTime|OffsetDateTime|Instant
     * @param callable(string): (U|null) $parse
     * @return TemporalDecoder<U>
     */
    private function temporal(callable $parse, string $messageKey, ?string $message): TemporalDecoder
    {
        return TemporalDecoder::over(
            $this->followedBy(static function (string $v, Path $p) use ($parse, $messageKey, $message): Result {
                $t = $parse($v);
                return $t === null ? Result::issue($p, $messageKey, [], $message) : Result::ok($t);
            }),
            $parse,
        );
    }

    /**
     * @return \Closure(string, Path): Result<int>
     */
    private static function integer(int $min, int $max, string $expected, ?string $message): \Closure
    {
        return static function (string $v, Path $p) use ($min, $max, $expected, $message): Result {
            if (preg_match('/\A([+-]?)0*([0-9]+)\z/', $v, $m) !== 1) {
                return Result::issue($p, 'type_mismatch', ['expected' => $expected], $message);
            }
            $n = Integers::read($m[1] === '-', $m[2], $min, $max);
            return $n === null
                ? Result::issue($p, 'type_mismatch.numeric_range', ['expected' => $expected], $message)
                : Result::ok($n);
        };
    }
}
