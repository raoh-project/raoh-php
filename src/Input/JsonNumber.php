<?php

declare(strict_types=1);

namespace Raoh\Input;

/**
 * A number of the input model, kept as it is written.
 *
 * Decoders tell lexemes apart: `int` accepts `1` and rejects `1.0`, `decimal` reads `1.50` with
 * scale 2, and `double` reads `-0` as -0. A PHP int or float has already lost that, so
 * {@see Json::parse()} gives every number as a JsonNumber.
 */
final readonly class JsonNumber
{
    private const GRAMMAR = '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/';

    public function __construct(public string $lexeme)
    {
        if (preg_match(self::GRAMMAR, $lexeme) !== 1) {
            throw new \InvalidArgumentException("not a JSON number: {$lexeme}");
        }
    }

    public function __toString(): string
    {
        return $this->lexeme;
    }
}
