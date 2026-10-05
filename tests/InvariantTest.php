<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Builtin\TemporalDecoder;
use Raoh\Combinator\Combiner;
use Raoh\Err;
use Raoh\Input\JsonObject;
use Raoh\Issue;
use Raoh\Issues;
use Raoh\Messages;
use Raoh\Path;
use Raoh\Result;

/**
 * A public value type holds its own invariants: whoever makes one, by its constructor or a
 * factory, gets one that holds them or an \InvalidArgumentException. That a parser or a decoder
 * only ever makes good ones is not enough, since a caller can make one too.
 */
class InvariantTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function broken(): iterable
    {
        yield 'an object that names a member twice' => [static function (): JsonObject {
            $twice = static function (): \Generator {
                yield 'a' => 1;
                yield 'a' => 2;
            };
            return new JsonObject($twice());
        }];
        yield 'an object that names a member twice, once as an int' => [static function (): JsonObject {
            $twice = static function (): \Generator {
                yield 1 => 'x';
                yield '1' => 'y';
            };
            return new JsonObject($twice());
        }];
        yield 'an object with a non-UTF-8 name' => [static fn (): JsonObject => new JsonObject(["\xff" => 1])];
        yield 'a message key that does not refine its code' => [static fn (): Issue => Issue::of(Path::root(), 'required', 'm', [], 'blank')];
        yield 'a message key that only starts like its code' => [static fn (): Issue => Issue::of(Path::root(), 'too_small', 'm', [], 'too_smallish')];
        yield 'metadata indexed, not named' => [static fn (): Issue => Issue::of(Path::root(), 'required', 'm', [1, 2])];
        yield 'metadata holding a non-UTF-8 string' => [static fn (): Issue => Issue::of(Path::root(), 'required', 'm', ['a' => ["\xff"]])];
        yield 'metadata holding a resource' => [static fn (): Issue => Issue::of(
            Path::root(),
            'custom',
            'm',
            ['value' => fopen('php://memory', 'r')],
        )];
        yield 'metadata holding a Stringable that writes what is not UTF-8' => [static fn (): Issue => Issue::of(
            Path::root(),
            'custom',
            'm',
            ['value' => new class () implements \Stringable {
                public function __toString(): string
                {
                    return "\xff";
                }
            }],
        )];
        yield 'metadata holding an object the value model has no place for' => [static fn (): Issue => Issue::of(Path::root(), 'custom', 'm', ['at' => new \DateTimeImmutable()])];
        yield 'metadata holding a closure' => [static fn (): Issue => Issue::of(Path::root(), 'custom', 'm', ['f' => static fn (): int => 1])];
        yield 'metadata holding a list with a resource in it' => [static fn (): Issue => Issue::of(Path::root(), 'custom', 'm', ['list' => [1, STDIN]])];
        yield 'an element contains() would keep, outside the value model' => [static fn (): mixed => \Raoh\Decoders::list_(\Raoh\Decoders::int_())->contains(new \stdClass())];
        yield 'metadata refine() would give, outside the value model' => [static fn (): mixed => \Raoh\Decoders::int_()->refine(static fn (): bool => true, 'custom', 'm', ['r' => STDIN])];
        yield 'a resolver that writes a non-UTF-8 message' => [static fn (): Issue => Issue::of(Path::root(), 'required', 'm')->resolve(static fn (): string => "\xff")];
        yield 'issues holding what is not an issue' => [static fn (): Issues => Issues::of(['required'])];
        yield 'a failure with no issue' => [static fn (): Err => Result::err(Issues::empty())];
        yield 'a path segment that is not UTF-8' => [static fn (): Path => Path::root()->append("\xff")];
        yield 'combine given what is not a decoder' => [static fn (): Combiner => new Combiner(['int'])];
        yield 'a catalogue that is not UTF-8' => [static fn (): Messages => Messages::fromProperties("raoh.a=\xff")];
        yield 'a template that is not UTF-8' => [static fn (): Messages => Messages::english()->with(['a' => "\xff"])];
        yield 'a temporal decoder of another type' => [static fn (): TemporalDecoder => TemporalDecoder::over(
            static fn (): Result => Result::ok(null),
            static fn (): null => null,
            \DateTimeImmutable::class,
        )];
    }

    #[DataProvider('broken')]
    public function testRefused(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    /**
     * Every value an issue's metadata may hold, which the issue keeps and json_encode writes.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function metadata(): iterable
    {
        yield 'null' => [null];
        yield 'a bool' => [true];
        yield 'an int' => [PHP_INT_MIN];
        yield 'a float' => [0.1];
        yield 'negative zero' => [-0.0];
        yield 'NaN' => [NAN];
        yield 'infinity' => [-INF];
        yield 'a float32' => [new \Raoh\Value\Float32(0.1)];
        yield 'a string' => ['日本語'];
        yield 'a decimal' => [\Raoh\Value\Decimal::of('150', 2)];
        yield 'a date' => [\Raoh\Value\Temporal\LocalDate::parse('2024-02-29')];
        yield 'an instant' => [\Raoh\Value\Temporal\Instant::parse('2024-02-29T00:00:00Z')];
        yield 'an enum case' => [\Raoh\ErrorCodes::Required];
        yield 'issues' => [Issues::of([Issue::of(Path::of('a'), 'required', 'is required')])];
        yield 'a list' => [[1, 'a', [0.5]]];
        yield 'a map' => [['a' => 1, 'b' => ['c' => null]]];
    }

    #[DataProvider('metadata')]
    public function testMetadataTheIssueKeepsIsJson(mixed $value): void
    {
        $issues = Issues::of([Issue::of(Path::root(), 'custom', 'm', ['value' => $value])]);
        $this->assertNotFalse(json_encode($issues->toJsonList()), json_last_error_msg());
    }

    public function testMetadataNamedByANumberIsNamedAllTheSame(): void
    {
        // PHP keys the name "404" as the int 404; it names the member "404" all the same.
        $issue = Issue::of(Path::root(), 'custom', 'm', ['404' => 'not found']);
        $this->assertSame('{"404":"not found"}', json_encode($issue->meta));
    }

    public function testAnObjectKeepsItsMembersInOrder(): void
    {
        $o = new JsonObject(['b' => 1, 'a' => 2, '1' => 3]);
        $this->assertSame(['b', 'a', '1'], $o->names());
    }
}
