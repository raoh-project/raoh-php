<?php

declare(strict_types=1);

namespace Raoh\Conformance;

use Raoh\Input\JsonNumber;
use Raoh\Input\JsonObject;

/**
 * Writes the input model back as JSON text: a number as its lexeme, a JsonObject with its members
 * in order, a list as an array, and an associative array as an object.
 */
final class Json
{
    private function __construct()
    {
    }

    public static function write(mixed $v, string $indent = ''): string
    {
        $next = $indent === '' ? '' : $indent . '  ';
        $nl = $indent === '' ? '' : "\n";
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v) => (string) $v,
            is_string($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $v instanceof JsonNumber => $v->lexeme,
            $v instanceof JsonObject => self::object(iterator_to_array($v->members()), $indent),
            is_array($v) && array_is_list($v) => $v === []
                ? '[]'
                : '[' . $nl . $next . implode(',' . $nl . $next, array_map(
                    static fn (mixed $e): string => self::write($e, $next),
                    $v,
                )) . $nl . substr($indent, 2) . ']',
            is_array($v) => self::object($v, $indent),
            default => throw new \UnexpectedValueException('cannot write ' . get_debug_type($v)),
        };
    }

    /**
     * @param array<array-key, mixed> $members
     */
    private static function object(array $members, string $indent): string
    {
        if ($members === []) {
            return '{}';
        }
        $next = $indent === '' ? '' : $indent . '  ';
        $nl = $indent === '' ? '' : "\n";
        $parts = [];
        foreach ($members as $k => $e) {
            $parts[] = self::write((string) $k) . ($indent === '' ? ':' : ': ') . self::write($e, $next);
        }
        return '{' . $nl . $next . implode(',' . $nl . $next, $parts) . $nl . substr($indent, 2) . '}';
    }
}
