<?php

declare(strict_types=1);

namespace Raoh;

/**
 * @template-covariant T
 * @extends Result<T>
 */
final readonly class Err extends Result
{
    /**
     * @throws \InvalidArgumentException when there is no issue: a failure gives at least one, and
     *     no issue is success (the specification's issues.md)
     */
    public function __construct(public readonly Issues $issues)
    {
        if ($issues->isEmpty()) {
            throw new \InvalidArgumentException('a failure has at least one issue');
        }
    }

    public function __toString(): string
    {
        return 'Err[' . implode(', ', array_map(
            fn (Issue $i) => ($i->path->toJsonPointer() ?: '/') . ': ' . $i->message,
            $this->issues->toArray(),
        )) . ']';
    }
}
