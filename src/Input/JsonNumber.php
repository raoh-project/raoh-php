<?php

declare(strict_types=1);

namespace Raoh\Input;

use Raoh\Internal\Number\Lexeme;

/**
 * A number of the input model, kept as it is written.
 *
 * Decoders tell lexemes apart: `int` accepts `1` and rejects `1.0`, `decimal` reads `1.50` with
 * scale 2, and `double` reads `-0` as -0. A PHP int or float has already lost that, so
 * {@see Json::parse()} gives every number as a JsonNumber.
 */
final readonly class JsonNumber
{
    public function __construct(public string $lexeme)
    {
        if (!Lexeme::is($lexeme)) {
            throw new \InvalidArgumentException("not a JSON number: {$lexeme}");
        }
    }

    public function __toString(): string
    {
        return $this->lexeme;
    }
}
