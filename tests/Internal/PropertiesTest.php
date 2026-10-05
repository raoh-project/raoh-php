<?php

declare(strict_types=1);

namespace Raoh\Tests\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Internal\Properties;

/**
 * A properties file reads as java.util.Properties.load(Reader) reads it. Each expected value below
 * is what Java 25's Properties gave for the same text: continuations, comments that do not
 * continue, the separators, escapes in keys and values, surrogate pairs, and a malformed \u
 * escape, which Java refuses too.
 */
class PropertiesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>|null}>
     */
    public static function cases(): iterable
    {
        yield 'bang-comment' => ["! c\na=1\n", ["a" => "1"]];
        yield 'colon' => ["a:1\n", ["a" => "1"]];
        yield 'comment-not-continued' => ["# c \\\na=1\n", ["a" => "1"]];
        yield 'cont-blank' => ["a=1\\\n\nb=2\n", ["a" => "1", "b" => "2"]];
        yield 'cont-eof' => ["a=1\\", ["a" => "1"]];
        yield 'cont-in-key' => ["ab\\\n  cd=1\n", ["abcd" => "1"]];
        yield 'cont-ws-only' => ["a=1\\\n   \nb=2\n", ["a" => "1", "b" => "2"]];
        yield 'continuation' => ["a=first \\\n    second\n", ["a" => "first second"]];
        yield 'crlf' => ["a=1\r\nb=2\rc=3\n", ["a" => "1", "b" => "2", "c" => "3"]];
        yield 'double-sep' => ["a==1\n", ["a" => "=1"]];
        yield 'dup' => ["a=1\na=2\n", ["a" => "2"]];
        yield 'empty-value' => ["a=\nb\n", ["a" => "", "b" => ""]];
        yield 'escaped-key' => ["a\\ b\\=c=1\n", ["a b=c" => "1"]];
        yield 'escapes' => ["a=\\t\\n\\r\\f\\x\\\\\n", ["a" => "\t\n\r\fx\\"]];
        yield 'even-backslashes' => ["a=x\\\\\nb=y\n", ["a" => "x\\", "b" => "y"]];
        yield 'formfeed-sep' => ["a\f1\n", ["a" => "1"]];
        yield 'key-only-ws-after' => ["a   \n", ["a" => ""]];
        yield 'leading-ws' => ["   \t a=1\n", ["a" => "1"]];
        yield 'malformed-u' => ["a=\\u12\n", null];
        yield 'odd-three' => ["a=x\\\\\\\n  y\n", ["a" => "x\\y"]];
        yield 'space-eq-space' => ["a = 1\n", ["a" => "1"]];
        yield 'space-sep' => ["a 1\n", ["a" => "1"]];
        yield 'trailing-ws-value' => ["a=1  \n", ["a" => "1  "]];
        yield 'unicode' => ["a=\\u00e9\\uD83D\\uDE00\n", ["a" => "\xc3\xa9\xf0\x9f\x98\x80"]];
        yield 'utf8' => ["raoh.x=\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e {min}\n", ["raoh.x" => "\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e {min}"]];
    }

    /**
     * @param array<string, string>|null $expected
     */
    #[DataProvider('cases')]
    public function testReadsAsJavaPropertiesReads(string $text, ?array $expected): void
    {
        if ($expected === null) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $read = Properties::read($text);
        $this->assertSame($expected, array_combine(array_map('strval', array_keys($read)), $read));
    }
}
