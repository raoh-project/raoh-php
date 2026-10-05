<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Internal\Number\Floats;

/**
 * How a metadata value is written into a message (the message forms of the specification's
 * issues.md).
 *
 * A PHP float is a float64; a float32 is a {@see \Raoh\Value\Float32}. A decimal, a float32 and a
 * temporal value write their own message form as their string. A list writes its elements
 * between brackets. Other values have no message form.
 *
 * @internal
 */
final class MessageForm
{
    private function __construct()
    {
    }

    public static function of(mixed $v): ?string
    {
        return match (true) {
            is_string($v) => $v,
            is_int($v) => (string) $v,
            is_bool($v) => $v ? 'true' : 'false',
            is_float($v) => Floats::messageForm($v, 64),
            is_array($v) && array_is_list($v) => self::list($v),
            $v instanceof \UnitEnum => $v->name,
            $v instanceof \Stringable => (string) $v,
            default => null,
        };
    }

    /**
     * @param list<mixed> $elements
     */
    private static function list(array $elements): ?string
    {
        $forms = [];
        foreach ($elements as $e) {
            $form = self::of($e);
            if ($form === null) {
                return null;
            }
            $forms[] = $form;
        }
        return '[' . implode(', ', $forms) . ']';
    }

    /**
     * The UTF-8 bytes of a scalar value.
     */
    public static function utf8(int $cp): string
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            throw new \InvalidArgumentException("not a scalar value: {$cp}");
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
}
