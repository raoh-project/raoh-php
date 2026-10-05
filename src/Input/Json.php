<?php

declare(strict_types=1);

namespace Raoh\Input;

use Raoh\Internal\Text;
use Raoh\Notation199x\ScalarValues;

/**
 * Reads a JSON text (RFC 8259) into the input model.
 *
 * A number is a {@see JsonNumber} holding its lexeme, an object a {@see JsonObject} holding its
 * members in order, an array a list, and a string a PHP string of UTF-8. `json_decode` cannot be
 * used for this: it converts every number, and it gives `{}` and `[]` as the same PHP value or
 * makes every object a stdClass whose integer-like names it cannot keep apart from indexes.
 *
 * What the input model has no place for is refused with a {@see \JsonException}: text that is
 * not JSON, an object that repeats a member name, a string holding an unpaired surrogate.
 */
final class Json
{
    private const NUMBER = '/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/';
    private const PLAIN = '/\G[^"\\\\\x00-\x1F]*/';

    private int $at = 0;
    private int $depth = 0;

    private function __construct(private readonly string $text, private readonly int $maxDepth)
    {
    }

    /**
     * @throws \JsonException when the text is not a JSON text the input model holds
     */
    public static function parse(string $text, int $maxDepth = 512): mixed
    {
        if ($maxDepth < 1) {
            throw new \InvalidArgumentException('maxDepth must be at least 1');
        }
        $bad = ScalarValues::invalidUtf8At($text);
        if ($bad !== null) {
            throw new \JsonException("the text is not UTF-8 at byte {$bad}");
        }
        $reader = new self($text, $maxDepth);
        $reader->skip();
        $value = $reader->value();
        $reader->skip();
        if ($reader->at !== strlen($text)) {
            throw $reader->error('nothing may follow the value');
        }
        return $value;
    }

    private function value(): mixed
    {
        $c = $this->text[$this->at] ?? '';
        switch ($c) {
            case '{':
                return $this->object();
            case '[':
                return $this->array();
            case '"':
                return $this->string();
            case 't':
                return $this->word('true', true);
            case 'f':
                return $this->word('false', false);
            case 'n':
                return $this->word('null', null);
        }
        if (preg_match(self::NUMBER, $this->text, $m, 0, $this->at) === 1) {
            $this->at += strlen($m[0]);
            return new JsonNumber($m[0]);
        }
        throw $this->error('a value was expected');
    }

    private function object(): JsonObject
    {
        $this->enter();
        $this->at++;
        $members = [];
        $this->skip();
        if ($this->peek() === '}') {
            $this->at++;
            $this->depth--;
            return new JsonObject();
        }
        while (true) {
            if ($this->peek() !== '"') {
                throw $this->error('a member name was expected');
            }
            $name = $this->string();
            if (array_key_exists($name, $members)) {
                throw $this->error("the member name \"{$name}\" is repeated");
            }
            $this->skip();
            $this->expect(':');
            $this->skip();
            $members[$name] = $this->value();
            $this->skip();
            if ($this->peek() === ',') {
                $this->at++;
                $this->skip();
                continue;
            }
            $this->expect('}');
            break;
        }
        $this->depth--;
        return new JsonObject($members);
    }

    /**
     * @return list<mixed>
     */
    private function array(): array
    {
        $this->enter();
        $this->at++;
        $elements = [];
        $this->skip();
        if ($this->peek() === ']') {
            $this->at++;
            $this->depth--;
            return [];
        }
        while (true) {
            $elements[] = $this->value();
            $this->skip();
            if ($this->peek() === ',') {
                $this->at++;
                $this->skip();
                continue;
            }
            $this->expect(']');
            break;
        }
        $this->depth--;
        return $elements;
    }

    private function string(): string
    {
        $this->at++;
        $out = '';
        while (true) {
            $plain = preg_match(self::PLAIN, $this->text, $m, 0, $this->at) === 1 ? $m[0] : '';
            $out .= $plain;
            $this->at += strlen($plain);
            $c = $this->text[$this->at] ?? '';
            if ($c === '"') {
                $this->at++;
                return $out;
            }
            if ($c !== '\\') {
                throw $this->error($c === '' ? 'the string is not closed' : 'a control character must be escaped');
            }
            $e = $this->text[$this->at + 1] ?? '';
            $this->at += 2;
            $out .= match ($e) {
                '"' => '"',
                '\\' => '\\',
                '/' => '/',
                'b' => "\x08",
                'f' => "\f",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'u' => $this->unicodeEscape(),
                default => throw $this->error('not an escape'),
            };
        }
    }

    private function unicodeEscape(): string
    {
        // The escape begins at the backslash, two bytes back.
        $read = Text::unicodeEscape($this->text, $this->at - 2)
            ?? throw $this->error('\\u is followed by four hexadecimal digits of a scalar value or a surrogate pair');
        $this->at = $read[1];
        return $read[0];
    }

    private function word(string $word, ?bool $value): ?bool
    {
        if (substr_compare($this->text, $word, $this->at, strlen($word)) !== 0) {
            throw $this->error('a value was expected');
        }
        $this->at += strlen($word);
        return $value;
    }

    private function enter(): void
    {
        if (++$this->depth > $this->maxDepth) {
            throw $this->error("nested deeper than {$this->maxDepth}");
        }
    }

    private function skip(): void
    {
        $this->at += strspn($this->text, " \t\n\r", $this->at);
    }

    private function peek(): string
    {
        return $this->text[$this->at] ?? '';
    }

    private function expect(string $c): void
    {
        if ($this->peek() !== $c) {
            throw $this->error("{$c} was expected");
        }
        $this->at++;
    }

    private function error(string $what): \JsonException
    {
        return new \JsonException("{$what} at byte {$this->at}");
    }
}
