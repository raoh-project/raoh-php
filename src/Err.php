<?php

declare(strict_types=1);

namespace Raoh;

/**
 * @template-covariant T
 * @extends Result<T>
 */
final readonly class Err extends Result
{
    public function __construct(public readonly Issues $issues)
    {
    }

    public function __toString(): string
    {
        return 'Err[' . implode(', ', array_map(
            fn (Issue $i) => ($i->path->toJsonPointer() ?: '/') . ': ' . $i->message,
            $this->issues->toArray(),
        )) . ']';
    }
}
