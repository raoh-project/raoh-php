<?php

declare(strict_types=1);

namespace Raoh\Internal;

/**
 * Reads a properties file as java.util.Properties.load reads one, the format the specification's
 * message catalogues are written in, so that a catalogue written for Raoh for Java reads the same
 * here: the whole format, not the part the shipped catalogues use.
 *
 * A natural line ends at LF, CR or CRLF. Leading white space (space, tab, form feed) is skipped; a
 * line that is then empty, or begins with `#` or `!`, is a comment, and a comment is never
 * continued. A line that ends with an odd number of backslashes continues on the next, whose
 * leading white space is skipped. The key runs to the first unescaped `=`, `:` or white space;
 * white space, then at most one `=` or `:`, then white space, separate it from the value. In both,
 * `\t`, `\n`, `\r`, `\f` and `\uXXXX` are escapes, a surrogate pair of `\u` escapes is the one
 * character it encodes, and a backslash before any other character is that character.
 *
 * The text is UTF-8, as the catalogues are, and not the ISO 8859-1 Properties.load(InputStream)
 * reads; Properties.load(Reader) reads characters, which this matches.
 *
 * @internal
 */
final class Properties
{
    private function __construct()
    {
    }

    /**
     * The entries, by key, a later one replacing an earlier one of the same key.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException when the text is not UTF-8 or a \u escape is malformed
     */
    public static function read(string $text): array
    {
        Arguments::text($text, 'a properties file');
        $entries = [];
        foreach (self::logicalLines($text) as $line) {
            [$key, $value] = self::split($line);
            $entries[self::unescape($key)] = self::unescape($value);
        }
        return $entries;
    }

    /**
     * The logical lines: comments and blank lines left out, continuations joined, each with its
     * escapes still in it.
     *
     * @return list<string>
     */
    private static function logicalLines(string $text): array
    {
        $natural = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = [];
        $current = null;
        foreach ($natural as $line) {
            $line = ltrim($line, " \t\f");
            if ($current === null) {
                if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                    continue;
                }
                $current = '';
            }
            if (self::continues($line)) {
                $current .= substr($line, 0, -1);
                continue;
            }
            $lines[] = $current . $line;
            $current = null;
        }
        if ($current !== null) {
            // A continuation at the end of the text continues onto nothing.
            $lines[] = $current;
        }
        return $lines;
    }

    /** Whether the line ends with an odd number of backslashes. */
    private static function continues(string $line): bool
    {
        $n = strlen($line) - strlen(rtrim($line, '\\'));
        return $n % 2 === 1;
    }

    /**
     * The key and the value of a logical line, each still escaped.
     *
     * @return array{string, string}
     */
    private static function split(string $line): array
    {
        $n = strlen($line);
        $at = 0;
        while ($at < $n) {
            $c = $line[$at];
            if ($c === '\\') {
                $at += 2;
                continue;
            }
            if ($c === '=' || $c === ':' || $c === ' ' || $c === "\t" || $c === "\f") {
                break;
            }
            $at++;
        }
        $key = substr($line, 0, min($at, $n));
        $rest = substr($line, min($at, $n));
        $rest = ltrim($rest, " \t\f");
        if ($rest !== '' && ($rest[0] === '=' || $rest[0] === ':')) {
            $rest = ltrim(substr($rest, 1), " \t\f");
        }
        return [$key, $rest];
    }

    private static function unescape(string $s): string
    {
        $out = '';
        $at = 0;
        $n = strlen($s);
        while (($slash = strpos($s, '\\', $at)) !== false) {
            $out .= substr($s, $at, $slash - $at);
            $e = $s[$slash + 1] ?? '';
            if ($e === 'u') {
                $read = Text::unicodeEscape($s, $slash)
                    ?? throw new \InvalidArgumentException('a \u escape of no character at byte ' . $slash);
                $out .= $read[0];
                $at = $read[1];
                continue;
            }
            $out .= match ($e) {
                't' => "\t",
                'n' => "\n",
                'r' => "\r",
                'f' => "\f",
                default => $e,
            };
            $at = min($slash + 2, $n);
        }
        return $out . substr($s, $at);
    }
}
