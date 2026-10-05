<?php

declare(strict_types=1);

namespace Raoh\Tests;

/**
 * The public API of raoh-php: every class, interface, trait and enum under `Raoh\` that is not
 * internal, with its public constants, cases, properties and methods, and every function of the
 * facades. A method or a class whose PHPDoc says `@internal` is not part of it.
 *
 * {@see describe()} writes it as text: one line for each member, with the parameter names (which
 * named arguments make part of the API), the defaults, the modifiers, the parent and the generic
 * types the PHPDoc declares. tests/public-api.txt holds what it wrote last, and PublicApiTest fails
 * when the two differ, so that a change to the API is a change to that file, seen in review beside
 * the CHANGELOG entry it calls for. {@see entries()} gives the same methods and functions to tests
 * that hold every entry point to a rule.
 */
final class PublicApi
{
    private function __construct()
    {
    }

    /**
     * @return list<\ReflectionClass<object>>
     */
    public static function classes(): array
    {
        $src = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                require_once $file->getPathname();
            }
        }
        $classes = [];
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $name) {
            $class = new \ReflectionClass($name);
            if (self::isPublicApi($class)) {
                $classes[$name] = $class;
            }
        }
        ksort($classes);
        return array_values($classes);
    }

    /**
     * The public methods each class declares itself (a trait's under the trait), and the functions
     * of the facades.
     *
     * @return list<\ReflectionMethod|\ReflectionFunction>
     */
    public static function entries(): array
    {
        $entries = [];
        foreach (self::classes() as $class) {
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $class->getName()
                    && $method->getFileName() === $class->getFileName()
                    && !str_contains((string) $method->getDocComment(), '@internal')) {
                    $entries[] = $method;
                }
            }
        }
        foreach (get_defined_functions()['user'] as $function) {
            $reflection = new \ReflectionFunction($function);
            if (str_starts_with($reflection->getNamespaceName(), 'Raoh\\Boundary')) {
                $entries[] = $reflection;
            }
        }
        return $entries;
    }

    public static function describe(): string
    {
        $entries = self::entries();
        $blocks = [];
        foreach (self::classes() as $class) {
            $name = $class->getName();
            $members = [];
            foreach ($class->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() === $name && !$constant->isEnumCase()) {
                    $members[] = "  const {$constant->getName()} = " . self::value($constant->getValue());
                }
            }
            if ($class->isEnum()) {
                foreach ((new \ReflectionEnum($name))->getCases() as $case) {
                    $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '';
                    $members[] = "  case {$case->getName()}{$value}";
                }
            } else {
                foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                    if ($property->getDeclaringClass()->getName() === $name) {
                        $readonly = $property->isReadOnly() ? 'readonly ' : '';
                        $members[] = "  {$readonly}\${$property->getName()}: " . self::type($property->getType(), $name);
                    }
                }
            }
            foreach ($entries as $entry) {
                if ($entry instanceof \ReflectionMethod && $entry->getDeclaringClass()->getName() === $name) {
                    $members[] = "  {$entry->getName()}" . self::signature($entry);
                }
            }
            sort($members);
            $parent = $class->getParentClass();
            $extends = $parent === false ? '' : ' extends ' . $parent->getName();
            $implements = $class->isInterface() ? [] : array_diff(
                $class->getInterfaceNames(),
                $parent === false ? [] : $parent->getInterfaceNames(),
                ['UnitEnum', 'BackedEnum'],
            );
            sort($implements);
            $implements = $implements === [] ? '' : ' implements ' . implode(', ', $implements);
            $uses = $class->getTraitNames() === [] ? '' : ' uses ' . implode(', ', $class->getTraitNames());
            $head = self::kind($class) . " {$name}{$extends}{$implements}{$uses}"
                . self::doc($class->getDocComment(), ['template', 'template-covariant', 'extends', 'implements']);
            $blocks[$name] = implode("\n", [$head, ...$members]);
        }
        $functions = [];
        foreach ($entries as $entry) {
            if ($entry instanceof \ReflectionFunction) {
                $functions[] = 'function ' . $entry->getName() . self::signature($entry);
            }
        }
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
        $readonly = !$class->isEnum() && $class->isReadOnly() ? 'readonly ' : '';
        return match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            $class->isAbstract() => "abstract {$readonly}class",
            $class->isFinal() => "final {$readonly}class",
            default => "{$readonly}class",
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
        $modifiers = '';
        if ($f instanceof \ReflectionMethod) {
            $modifiers .= $f->isStatic() ? ' static' : '';
            $modifiers .= $f->isFinal() && !$f->getDeclaringClass()->isFinal() ? ' final' : '';
            $modifiers .= $f->isAbstract() && !$f->getDeclaringClass()->isInterface() ? ' abstract' : '';
        }
        return '(' . implode(', ', $params) . '): ' . self::type($f->getReturnType(), $class) . $modifiers
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
