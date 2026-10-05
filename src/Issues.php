<?php

declare(strict_types=1);

namespace Raoh;

final class Issues
{
    /**
     * @param list<Issue> $items
     */
    private function __construct(private readonly array $items = [])
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<Issue> $items
     */
    public static function of(array $items): self
    {
        foreach ($items as $item) {
            if (!$item instanceof Issue) {
                throw new \InvalidArgumentException('issues hold Issue values, not ' . get_debug_type($item));
            }
        }
        return new self(array_values($items));
    }

    public function add(Issue $issue): self
    {
        return new self([...$this->items, $issue]);
    }

    public function merge(self $other): self
    {
        if ($this->isEmpty()) {
            return $other;
        }
        if ($other->isEmpty()) {
            return $this;
        }
        return new self([...$this->items, ...$other->items]);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * @return list<Issue>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function rebase(Path $prefix): self
    {
        return new self(array_map(
            fn (Issue $i) => $i->rebase($prefix),
            $this->items,
        ));
    }

    public function resolve(callable $resolver): self
    {
        return new self(array_map(
            fn (Issue $i) => $i->resolve($resolver),
            $this->items,
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    public function flatten(): array
    {
        $result = [];
        foreach ($this->items as $issue) {
            $key = $issue->path->toJsonPointer();
            $result[$key][] = $issue->message;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function format(): array
    {
        $root = [];
        foreach ($this->items as $issue) {
            $segments = $issue->path->segments();
            $current = &$root;
            foreach ($segments as $seg) {
                if (!array_key_exists($seg, $current) || !is_array($current[$seg])) {
                    $current[$seg] = [];
                }
                $current = &$current[$seg];
            }
            $current['_errors'][] = $issue->message;
        }
        return $root;
    }

    /**
     * Converts issues to a list of maps suitable for JSON serialization.
     *
     * Deliberately excludes `messageKey`, matching kawasima/raoh's `Issues.toJsonList()`:
     * `messageKey` refines `code` for the message resolver and is not part of the
     * cross-language JSON API contract. Read it from `Issue::$messageKey` directly.
     *
     * @return list<array{path: string, code: string, message: string, meta: array<string, mixed>}>
     */
    public function toJsonList(): array
    {
        return array_map(fn (Issue $i) => [
            'path'    => $i->path->toJsonPointer(),
            'code'    => $i->code,
            'message' => $i->message,
            'meta'    => array_map(Internal\Wire::of(...), $i->meta),
        ], $this->items);
    }
}
