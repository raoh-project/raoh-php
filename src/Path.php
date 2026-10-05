<?php

declare(strict_types=1);

namespace Raoh;

use Raoh\Internal\Arguments;
final class Path
{
    private static ?Path $root = null;

    private function __construct(
        private readonly ?Path $parent,
        private readonly ?string $head,
    ) {
    }

    public static function root(): self
    {
        return self::$root ??= new self(null, null);
    }

    public static function of(string $first, string ...$rest): self
    {
        $p = self::root()->append($first);
        foreach ($rest as $segment) {
            $p = $p->append($segment);
        }
        return $p;
    }

    /**
     * @throws \InvalidArgumentException when the segment is not UTF-8, which no member name is
     */
    public function append(string $segment): self
    {
        return new self($this, Arguments::text($segment, 'a path segment'));
    }

    public function appendPath(self $other): self
    {
        $result = $this;
        foreach ($other->segments() as $seg) {
            $result = $result->append($seg);
        }
        return $result;
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        if ($this->parent === null) {
            return [];
        }
        $segs = [];
        $cur = $this;
        while ($cur->parent !== null) {
            $segs[] = (string) $cur->head;
            $cur = $cur->parent;
        }
        return array_reverse($segs);
    }

    public function toJsonPointer(): string
    {
        if ($this->parent === null) {
            return '';
        }
        $escaped = array_map(
            fn (string $s) => str_replace(['~', '/'], ['~0', '~1'], $s),
            $this->segments()
        );
        return '/' . implode('/', $escaped);
    }

    public function __toString(): string
    {
        return $this->toJsonPointer();
    }
}
