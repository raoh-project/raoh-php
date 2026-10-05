<?php

declare(strict_types=1);

namespace Raoh\Input;

use Raoh\Notation199x\ScalarValues;

/**
 * An object of the input model: members in the order they were written, no name twice.
 *
 * A PHP array cannot stand for one: `{}` and `[]` would both be `[]`, and `{"0":1}` would read as
 * a list. A PHP array turns a member name such as "1" into the int key 1, so the names are given
 * back as strings by {@see names()} and {@see members()}.
 */
final class JsonObject implements \Countable
{
    /** @var array<array-key, mixed> */
    private array $members;

    /**
     * @param iterable<string|int, mixed> $members
     * @throws \InvalidArgumentException when a name is not UTF-8, which no member of the input model has
     */
    public function __construct(iterable $members = [])
    {
        $this->members = [];
        foreach ($members as $name => $value) {
            $name = (string) $name;
            if (ScalarValues::invalidUtf8At($name) !== null) {
                throw new \InvalidArgumentException('a member name is not UTF-8');
            }
            $this->members[$name] = $value;
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->members);
    }

    public function get(string $name): mixed
    {
        return $this->members[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->members));
    }

    /**
     * @return \Generator<string, mixed>
     */
    public function members(): \Generator
    {
        foreach ($this->members as $name => $value) {
            yield (string) $name => $value;
        }
    }

    public function count(): int
    {
        return count($this->members);
    }
}
