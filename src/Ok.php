<?php

declare(strict_types=1);

namespace Raoh;

/**
 * @template-covariant T
 * @extends Result<T>
 */
final readonly class Ok extends Result
{
    /**
     * @param T $value
     */
    public function __construct(public readonly mixed $value)
    {
    }

    public function __toString(): string
    {
        return 'Ok[' . (is_scalar($this->value) ? (string) $this->value : gettype($this->value)) . ']';
    }
}
