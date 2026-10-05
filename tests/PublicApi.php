<?php

declare(strict_types=1);

namespace Raoh\Tests;

/**
 * Writes the public API of raoh-php as text, one line for each public method of a class that is
 * not internal, each case of an enum, and each function of the facades, with the parameter names
 * (which named arguments make part of the API), the defaults, and the generic types the PHPDoc
 * declares. tests/public-api.txt holds what it wrote last, and PublicApiTest fails when the two
 * differ, so that a change to the API is a change to that file, seen in review beside the
 * CHANGELOG entry it calls for.
 */
final class PublicApi
{
    private function __construct()
    {
    }

    public static function describe(): string
    {
        $src = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                require_once $file->getPathname();
            }
        }
        $blocks = [];
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $name) {
            $class = new \ReflectionClass($name);
            if (!self::isPublicApi($class)) {
                continue;
            }
            $members = [];
            if ($class->isEnum()) {
                foreach ((new \ReflectionEnum($name))->getCases() as $case) {
                    $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '';
                    $members[] = "  case {$case->getName()}{$value}";
                }
            } else {
                foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    // A trait's methods are listed once, under the trait.
                    if ($method->getDeclaringClass()->getName() === $name && $method->getFileName() === $class->getFileName()) {
                        $members[] = "  {$method->getName()}" . self::signature($method);
                    }
                }
                foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                    if ($property->getDeclaringClass()->getName() === $name) {
                        $members[] = "  \${$property->getName()}: " . ($property->getType() ?? 'mixed');
                    }
                }
            }
            sort($members);
            $uses = $class->getTraitNames() === [] ? '' : ' uses ' . implode(', ', $class->getTraitNames());
            $head = self::kind($class) . " {$name}{$uses}"
                . self::doc($class->getDocComment(), ['template', 'template-covariant', 'extends', 'implements']);
            $blocks[$name] = implode("\n", [$head, ...$members]);
        }
        $functions = [];
        foreach (get_defined_functions()['user'] as $function) {
            $reflection = new \ReflectionFunction($function);
            if (str_starts_with($reflection->getNamespaceName(), 'Raoh\\Boundary')) {
                $functions[] = 'function ' . $reflection->getName() . self::signature($reflection);
            }
        }
        ksort($blocks);
        sort($functions);
        return implode("\n\n", $blocks) . "\n\n" . implode("\n", $functions) . "\n";
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function isPublicApi(\ReflectionClass $class): bool
    {
        $name = $class->getName();
        return str_starts_with($name, 'Raoh\\')
            && !str_starts_with($name, 'Raoh\\Notation199x\\')
            && !str_starts_with($name, 'Raoh\\Internal\\')
            && !str_starts_with($name, 'Raoh\\Tests\\')
            && !str_starts_with($name, 'Raoh\\Conformance\\')
            && !str_contains((string) $class->getDocComment(), '@internal');
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function kind(\ReflectionClass $class): string
    {
        return match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            $class->isAbstract() => 'abstract class',
            $class->isFinal() => 'final class',
            default => 'class',
        };
    }

    private static function signature(\ReflectionFunctionAbstract $f): string
    {
        $class = $f instanceof \ReflectionMethod ? $f->getDeclaringClass()->getName() : null;
        $params = [];
        foreach ($f->getParameters() as $p) {
            $params[] = self::type($p->getType(), $class) . ' ' . ($p->isVariadic() ? '...' : '') . '$' . $p->getName()
                . ($p->isDefaultValueAvailable() ? ' = ' . self::value($p->getDefaultValue()) : '');
        }
        $static = $f instanceof \ReflectionMethod && $f->isStatic() ? ' static' : '';
        return '(' . implode(', ', $params) . '): ' . self::type($f->getReturnType(), $class) . $static
            . self::doc($f->getDocComment(), ['template', 'param', 'return']);
    }

    /**
     * A type as it is written, with `self` named by its class: PHP 8.5 reflects `self` as the
     * class and earlier versions as `self`, and the text has to be the same on every PHP CI runs.
     */
    private static function type(?\ReflectionType $type, ?string $class): string
    {
        $text = $type === null ? 'mixed' : (string) $type;
        return $class === null ? $text : (string) preg_replace('/(?<![\\\\\w])self\b/', $class, $text);
    }

    private static function value(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_array($v) => $v === [] ? '[]' : json_encode($v, JSON_THROW_ON_ERROR),
            default => var_export($v, true),
        };
    }

    /**
     * The PHPDoc tags of those names, one after another, with their text on one line.
     *
     * @param list<string> $tags
     */
    private static function doc(string|false $comment, array $tags): string
    {
        if ($comment === false) {
            return '';
        }
        $found = [];
        $pattern = '/@(' . implode('|', array_map('preg_quote', $tags)) . ')\s+([^\n*]+(?:\n\s*\*\s{2,}[^\n@*][^\n]*)*)/';
        if (preg_match_all($pattern, $comment, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $tag) {
                $found[] = '@' . $tag[1] . ' ' . trim((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $tag[2]));
            }
        }
        return $found === [] ? '' : ' {' . implode('; ', $found) . '}';
    }
}
