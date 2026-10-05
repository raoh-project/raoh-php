<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Notation199x\WhiteSpace;

/**
 * Text read by the Unicode White_Space property of Unicode 18.0.0, whatever version PHP was built
 * with. The text is valid UTF-8.
 *
 * @internal
 */
final class Text
{
    private function __construct()
    {
    }

    public static function trim(string $s): string
    {
        $start = 0;
        $end = strlen($s);
        while ($start < $end) {
            [$cp, $width] = self::at($s, $start);
            if (!WhiteSpace::contains($cp)) {
                break;
            }
            $start += $width;
        }
        while ($end > $start) {
            $lead = $end - 1;
            while ((ord($s[$lead]) & 0xC0) === 0x80) {
                $lead--;
            }
            [$cp] = self::at($s, $lead);
            if (!WhiteSpace::contains($cp)) {
                break;
            }
            $end = $lead;
        }
        return substr($s, $start, $end - $start);
    }

    public static function isBlank(string $s): bool
    {
        return self::trim($s) === '';
    }

    /**
     * Reads the \u escape that starts at a byte offset: `\uXXXX`, or the two escapes of a
     * surrogate pair, `\uD83D\uDE00`, which stand for the one character they encode, as JSON
     * and a Java properties file both write a character past the basic plane.
     *
     * Gives the UTF-8 bytes of the character and the offset after the escape, or null when the
     * text there is not an escape of a scalar value: fewer than four hexadecimal digits, or a
     * surrogate that is not one half of a pair.
     *
     * @return array{string, int}|null
     */
    public static function unicodeEscape(string $s, int $at): ?array
    {
        $unit = self::hex4($s, $at);
        if ($unit === null || ($unit >= 0xDC00 && $unit <= 0xDFFF)) {
            return null;
        }
        if ($unit < 0xD800 || $unit > 0xDBFF) {
            return [self::encode($unit), $at + 6];
        }
        $low = self::hex4($s, $at + 6);
        if ($low === null || $low < 0xDC00 || $low > 0xDFFF) {
            return null;
        }
        return [self::encode(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00)), $at + 12];
    }

    /**
     * The UTF-8 bytes of a scalar value.
     */
    public static function encode(int $cp): string
    {
        if ($cp < 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            throw new \InvalidArgumentException('not a scalar value: U+' . strtoupper(dechex($cp)));
        }
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
        }
        return chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F)
            . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
    }

    /**
     * The code unit of `\uXXXX` at the offset, or null.
     */
    private static function hex4(string $s, int $at): ?int
    {
        if (preg_match('/\\G\\\\u([0-9A-Fa-f]{4})/', $s, $m, 0, $at) !== 1) {
            return null;
        }
        return (int) hexdec($m[1]);
    }

    /**
     * The scalar value at a byte offset where a character begins, and its width in bytes.
     *
     * @return array{int, int}
     */
    private static function at(string $s, int $i): array
    {
        $b = ord($s[$i]);
        if ($b < 0x80) {
            return [$b, 1];
        }
        if ($b < 0xE0) {
            return [($b & 0x1F) << 6 | ord($s[$i + 1]) & 0x3F, 2];
        }
        if ($b < 0xF0) {
            return [($b & 0x0F) << 12 | (ord($s[$i + 1]) & 0x3F) << 6 | ord($s[$i + 2]) & 0x3F, 3];
        }
        return [
            ($b & 0x07) << 18 | (ord($s[$i + 1]) & 0x3F) << 12 | (ord($s[$i + 2]) & 0x3F) << 6
                | ord($s[$i + 3]) & 0x3F,
            4,
        ];
    }
}
