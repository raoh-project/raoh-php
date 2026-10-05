<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Notation199x\ScalarValues;

/**
 * Reads what a caller gives the library: the arguments of a decoder, and the parts of an issue,
 * a path or a catalogue. {@see Input} reads what a decoder is given to decode; this reads
 * everything else, by the same value model, so that both sides of the library hold one rule.
 *
 * A string the library keeps is a string of the value model, a sequence of Unicode scalar
 * values: a PHP string of other bytes is refused, with an \InvalidArgumentException, where it is
 * given. It would otherwise reach an issue's metadata, path or message, which a client writes as
 * JSON and could not. Refusing it where it is given is what lets a decoder never throw once built.
 *
 * @internal
 */
final class Arguments
{
    private function __construct()
    {
    }

    /**
     * A string of Unicode scalar values.
     */
    public static function text(mixed $value, string $what): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$what} is a string, not " . get_debug_type($value));
        }
        $bad = ScalarValues::invalidUtf8At($value);
        if ($bad !== null) {
            throw new \InvalidArgumentException("{$what} is not UTF-8 at byte {$bad}");
        }
        return $value;
    }

    /**
     * The message given to an operation, when one is.
     */
    public static function message(?string $message): ?string
    {
        return $message === null ? null : self::text($message, 'a message');
    }

    /**
     * A list of strings of Unicode scalar values.
     *
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function texts(array $values, string $what): array
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException("{$what} are a list");
        }
        return array_map(static fn (mixed $v): string => self::text($v, "each of {$what}"), $values);
    }
}
