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

    public function testAnObjectKeepsItsMembersInOrder(): void
    {
        $o = new JsonObject(['b' => 1, 'a' => 2, '1' => 3]);
        $this->assertSame(['b', 'a', '1'], $o->names());
    }
}
