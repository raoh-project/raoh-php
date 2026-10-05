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
}
