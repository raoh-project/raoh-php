<?php

declare(strict_types=1);

namespace Raoh\Internal\Number;

/**
 * The grammar of a JSON number (RFC 8259: `-? int frac? exp?`), written once: the JSON parser
 * scans with it, a JsonNumber is held to it, and a float or a decimal reads its parts with it.
 *
 * @internal
 */
final class Lexeme
{
    /**
     * The grammar, unanchored, with five groups: the minus sign, the integer digits, the fraction
     * digits, the exponent's sign and the exponent's digits.
     */
    public const GRAMMAR = '(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?)([0-9]+))?';

    private function __construct()
    {
    }

    public static function is(string $text): bool
    {
        return preg_match('/\A' . self::GRAMMAR . '\z/', $text) === 1;
    }

    /**
     * The parts of a lexeme, or null when the text is not one: whether it has a minus sign, the
     * integer digits, the fraction digits, whether the exponent is negative, and its digits.
     *
     * @return array{bool, string, string, string, string}|null
     */
    public static function read(string $text): ?array
    {
        if (preg_match('/\A' . self::GRAMMAR . '\z/', $text, $m) !== 1) {
            return null;
        }
        return [$m[1] === '-', $m[2], $m[3] ?? '', $m[4] ?? '', $m[5] ?? ''];
    }
}
