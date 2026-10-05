<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Err;
use Raoh\Issue;
use Raoh\Issues;
use Raoh\Ok;
use Raoh\Path;
use Raoh\Result;

class ResultTest extends TestCase
{
    public function testOkIsOk(): void
    {
        $r = Result::ok(42);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertTrue($r->isOk());
        $this->assertFalse($r->isErr());
        $this->assertSame(42, $r->value);
    }

    public function testErrIsErr(): void
    {
        $r = Result::err(Issues::empty()->add(Issue::of(Path::root(), 'required', 'required')));
        $this->assertInstanceOf(Err::class, $r);
        $this->assertFalse($r->isOk());
        $this->assertTrue($r->isErr());
    }

    public function testMapOk(): void
    {
        $r = Result::ok(5)->map(fn ($v) => $v * 2);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(10, $r->value);
    }

    public function testMapErr(): void
    {
        $issues = Issues::empty()->add(Issue::of(Path::root(), 'required', 'required'));
        $r = Result::err($issues)->map(fn ($v) => $v * 2);
        $this->assertInstanceOf(Err::class, $r);
    }

    public function testFlatMapOk(): void
    {
        $r = Result::ok(5)->flatMap(fn ($v) => Result::ok($v + 1));
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(6, $r->value);
    }

    public function testFlatMapReturnsErr(): void
    {
        $r = Result::ok('bad')->flatMap(fn ($v) => Result::fail(Path::root(), 'invalid', 'bad value'));
        $this->assertInstanceOf(Err::class, $r);
    }

    public function testFold(): void
    {
        $ok = Result::ok(42)->fold(fn ($v) => "ok:{$v}", fn ($e) => 'err');
        $this->assertSame('ok:42', $ok);

        $issues = Issues::empty()->add(Issue::of(Path::root(), 'required', 'required'));
        $err = Result::err($issues)->fold(fn ($v) => 'ok', fn ($e) => 'err');
        $this->assertSame('err', $err);
    }

    public function testGetOrThrow(): void
    {
        $this->assertSame(42, Result::ok(42)->getOrThrow());
    }

    public function testGetOrThrowThrowsOnErr(): void
    {
        $this->expectException(\RuntimeException::class);
        $issues = Issues::empty()->add(Issue::of(Path::root(), 'required', 'required'));
        Result::err($issues)->getOrThrow();
    }

    public function testMap2BothOk(): void
    {
        $r = Result::map2(Result::ok(1), Result::ok(2), fn ($a, $b) => $a + $b);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(3, $r->value);
    }

    public function testMap2AccumulatesErrors(): void
    {
        $ra = Result::fail(Path::of('email'), 'invalid_format', 'bad');
        $rb = Result::fail(Path::of('age'), 'out_of_range', 'too big');
        $r = Result::map2($ra, $rb, fn ($a, $b) => null);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertCount(2, $r->issues->toArray());
    }

    public function testTraverseAllOk(): void
    {
        $r = Result::traverse([1, 2, 3], fn ($item, $path) => Result::ok($item * 2));
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame([2, 4, 6], $r->value);
    }

    public function testTraverseAccumulatesErrors(): void
    {
        $r = Result::traverse(['a', 'b', 'c'], function ($item, Path $path): Result {
            if ($item === 'b') {
                return Result::fail($path, 'invalid', 'bad item');
            }
            return Result::ok($item);
        });
        $this->assertInstanceOf(Err::class, $r);
        $this->assertCount(1, $r->issues->toArray());
        $this->assertSame('/1', $r->issues->toArray()[0]->path->toJsonPointer());
    }

    public function testFailWithUsesDefaultMessageWhenNoneGiven(): void
    {
        $r = Result::failWith(
            Path::root(),
            'out_of_range',
            'out_of_range.positive',
            null,
            'must be positive',
        );
        $issue = $r->issues->toArray()[0];
        $this->assertSame('out_of_range', $issue->code);
        $this->assertSame('out_of_range.positive', $issue->messageKey);
        $this->assertSame('must be positive', $issue->message);
        $this->assertFalse($issue->customMessage);
    }

    public function testFailWithMarksExplicitMessageAsCustom(): void
    {
        $r = Result::failWith(
            Path::root(),
            'out_of_range',
            'out_of_range.positive',
            'カスタム',
            'must be positive',
        );
        $issue = $r->issues->toArray()[0];
        $this->assertSame('カスタム', $issue->message);
        $this->assertTrue($issue->customMessage);
        $this->assertSame('out_of_range.positive', $issue->messageKey);

        $resolved = $issue->resolve(fn () => 'resolver output');
        $this->assertSame('カスタム', $resolved->message);
    }
}
