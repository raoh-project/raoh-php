<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Decoders;
use Raoh\Issues;
use Raoh\Ok;

use function Raoh\Boundary\Array_\discriminate;
use function Raoh\Boundary\Array_\int_;
use function Raoh\Boundary\Array_\string_;

/**
 * A decoder refuses an argument it cannot use when it is built, so that a mistake shows where the
 * decoder is written, and not as an exception on every value decoded later.
 */
class ArgumentTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function refused(): iterable
    {
        yield 'a non-string allowed value of a string' => [static fn () => string_()->oneOf(['a', 1])];
        yield 'a variant that is not a decoder' => [static fn () => discriminate('kind', ['a' => int_(), 'b' => 'int'])];
        yield 'a known field that is not a string' => [static fn () => Decoders::strict(int_(), ['a', 2])];
        yield 'a Closure as the fallback of recover' => [static fn () => int_()->recover(static fn (Issues $i): int => 42)];
        yield 'a Closure as the default of withDefault' => [static fn () => int_()->withDefault(static fn (): int => 0)];
        yield 'an invokable object as the fallback of recover' => [static fn () => int_()->recover(new class () {
            public function __invoke(Issues $i): int
            {
                return 42;
            }
        })];
        yield 'an array callable as the default of withDefault' => [static fn () => int_()->withDefault([new \ArrayObject(), 'count'])];
        yield 'a bound of another temporal type' => [static fn () => string_()->time()->after('2024-01-01')];
        yield 'an int32 bound outside int32' => [static fn () => int_()->min(PHP_INT_MAX)];
        yield 'a pattern the language does not have' => [static fn () => string_()->pattern('(?=a)a')];
        yield 'a message key of refine() that does not refine its code' => [static fn () => int_()->refine(static fn (int $v): bool => false, 'required', 'bad', [], 'blank')];
        yield 'metadata of refine() that is a list' => [static fn () => int_()->refine(static fn (int $v): bool => false, 'custom', 'bad', [0 => 'x'])];
    }

    #[DataProvider('refused')]
    public function testRefusedWhenBuilt(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }

    public function testAStringIsAValueEvenWhereItNamesAFunction(): void
    {
        $r = Decoders::string_()->withDefault('date')->decode(null);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('date', $r->value);
    }

    public function testRecoverWithComputesTheValueFromTheIssues(): void
    {
        $r = int_()->recoverWith(static fn (Issues $i): int => count($i->toArray()) + 41)->decode('x');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(42, $r->value);
    }

    public function testATagThatIsNotAStringNamesNoVariant(): void
    {
        $d = Decoders::discriminateBy('kind', int_(), ['1' => int_()]);
        $r = $d->decode(1);
        $this->assertSame('not_allowed', $r->fold(static fn (): string => 'ok', static fn (Issues $i): string => $i->toArray()[0]->code));
    }
}
