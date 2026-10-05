<?php

declare(strict_types=1);

namespace Raoh\Tests;

use Raoh\Conformance\Binder;
use Raoh\Input\Json;
use Raoh\Input\JsonObject;

/**
 * The decoder forms of the specification's suite, built by the conformance runner's binder, so
 * that a test can run every decoder the specification names on inputs of its own.
 */
final class SuiteForms
{
    private function __construct()
    {
    }

    /**
     * Each decoder case of the core profile: its id, its decoder form and its input, as the case
     * file writes them.
     *
     * @return list<array{string, mixed, mixed}>
     */
    public static function cases(): array
    {
        $dir = Specification::file('suite/core/string.json');
        if ($dir === null) {
            return [];
        }
        $files = glob(dirname($dir) . '/*.json') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            foreach (Json::parse((string) file_get_contents($file)) as $case) {
                assert($case instanceof JsonObject);
                if ($case->has('decoder')) {
                    $out[] = [(string) $case->get('id'), $case->get('decoder'), $case->get('input')];
                }
            }
        }
        return $out;
    }

    public static function binder(): Binder
    {
        $operations = json_decode(
            (string) file_get_contents((string) Specification::file('catalog/operations.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $defs = static fn (array $args): array => array_map(
            static fn (array $a): array => ['kind' => $a['kind'], 'optional' => $a['optional'] ?? false],
            $args,
        );
        $constructors = [];
        foreach ($operations['constructors'] as $name => $c) {
            $constructors[$name] = $defs($c['args'] ?? []);
        }
        $ops = [];
        foreach ($operations['operations'] as $o) {
            $ops[$o['name']] ??= $defs($o['args'] ?? []);
        }
        return new Binder($constructors, $ops);
    }
}
