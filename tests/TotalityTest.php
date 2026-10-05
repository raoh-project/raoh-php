<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Err;
use Raoh\ErrorCodes;
use Raoh\Input\JsonObject;
use Raoh\Issue;
use Raoh\MessageKeys;

/**
 * Decoding is total: every decoder the specification's suite names reports bad input as issues,
 * whatever PHP value it is handed, and never throws. It is run on the values the input model has
 * no place for, given as the whole input and as each member or element of the case's own input,
 * so that a decoder deep inside a form meets them too.
 *
 * Each issue it gives is one a client can rely on: its message key is one ErrorCodes or
 * MessageKeys names, or the key of an issue a fixture made itself, and the issues are JSON that
 * json_encode writes.
 */
class TotalityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function cases(): iterable
    {
        $cases = SuiteForms::cases();
        if ($cases === []) {
            yield 'suite not found' => ['', null, null];
            return;
        }
        foreach ($cases as [$id, $form, $input]) {
            yield $id => [$id, $form, $input];
        }
    }

    /**
     * PHP values outside the input model.
     *
     * @return array<string, mixed>
     */
    private static function foreign(): array
    {
        return [
            'object' => new \DateTimeImmutable('2024-01-01'),
            'array-like object' => new \ArrayObject(['a' => 1]),
            'closure' => static fn (): int => 1,
            'resource' => STDIN,
            'NaN' => NAN,
            'infinity' => -INF,
            'non-UTF-8 string' => "\xff\xfe",
            'array with a non-UTF-8 key' => ["\xff" => 1],
            'list holding an object' => [new \stdClass(), new \DateTimeImmutable()],
            'object holding NaN' => ['a' => NAN, 'b' => "\xc3"],
        ];
    }

    #[DataProvider('cases')]
    public function testEveryDecoderReportsForeignInputAsIssues(string $id, mixed $form, mixed $input): void
    {
        if ($id === '') {
            $this->markTestSkipped('raoh-specification not found; set RAOH_SPECIFICATION_DIR');
        }
        [$decoder] = SuiteForms::binder()->decoder($form);
        foreach (self::foreign() as $what => $value) {
            foreach (self::placements($input, $value) as $where => $placed) {
                try {
                    $r = $decoder->decode($placed);
                } catch (\Throwable $e) {
                    $this->fail("{$id}: {$what} {$where} threw " . $e::class . ': ' . $e->getMessage());
                }
                if ($r instanceof Err) {
                    foreach ($r->issues->toArray() as $issue) {
                        $this->assertKnownKey($issue, "{$id}: {$what} {$where}");
                    }
                    $this->assertNotFalse(
                        json_encode($r->issues->toJsonList()),
                        "{$id}: the issues of {$what} {$where} are not JSON: " . json_last_error_msg(),
                    );
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * The value as the whole input, and in place of each member or element of the case's input.
     *
     * @return iterable<string, mixed>
     */
    private static function placements(mixed $input, mixed $value): iterable
    {
        yield 'as the input' => $value;
        if ($input instanceof JsonObject) {
            foreach ($input->names() as $name) {
                $members = iterator_to_array($input->members());
                $members[$name] = $value;
                yield "at /{$name}" => $members;
            }
        } elseif (is_array($input) && $input !== []) {
            $elements = $input;
            $elements[0] = $value;
            yield 'at /0' => $elements;
        }
    }

    private function assertKnownKey(Issue $issue, string $where): void
    {
        $this->assertTrue(
            ErrorCodes::tryFrom($issue->messageKey) !== null
                || MessageKeys::tryFrom($issue->messageKey) !== null
                || in_array($issue->messageKey, self::fixtureKeys(), true),
            "{$where} gave the message key {$issue->messageKey}, which neither enum names",
        );
    }

    /**
     * The message keys of the issues the fixtures make, as user code makes its own.
     *
     * @return list<string>
     */
    private static function fixtureKeys(): array
    {
        static $keys = null;
        if ($keys === null) {
            $fixtures = json_decode(
                (string) file_get_contents((string) Specification::file('catalog/fixtures.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $keys = array_values(array_filter(array_map(
                static fn (array $f): ?string => $f['issue']['message_key'] ?? null,
                $fixtures,
            )));
        }
        return $keys;
    }
}
