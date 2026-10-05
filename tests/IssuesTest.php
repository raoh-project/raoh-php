<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Issue;
use Raoh\Issues;
use Raoh\Path;

class IssuesTest extends TestCase
{
    public function testEmptyIsEmpty(): void
    {
        $this->assertTrue(Issues::empty()->isEmpty());
    }

    public function testAddMakesNonEmpty(): void
    {
        $issues = Issues::empty()->add(Issue::of(Path::root(), 'required', 'is required'));
        $this->assertFalse($issues->isEmpty());
        $this->assertCount(1, $issues->toArray());
    }

    public function testMergeCombinesIssues(): void
    {
        $a = Issues::empty()->add(Issue::of(Path::of('email'), 'invalid_format', 'bad email'));
        $b = Issues::empty()->add(Issue::of(Path::of('age'), 'too_big', 'too large'));
        $merged = $a->merge($b);
        $this->assertCount(2, $merged->toArray());
    }

    public function testFlatten(): void
    {
        $issues = Issues::empty()
            ->add(Issue::of(Path::of('email'), 'invalid_format', 'bad email'))
            ->add(Issue::of(Path::of('email'), 'too_long', 'too long'));
        $flat = $issues->flatten();
        $this->assertArrayHasKey('/email', $flat);
        $this->assertCount(2, $flat['/email']);
    }

    public function testToJsonList(): void
    {
        $issues = Issues::empty()
            ->add(Issue::of(Path::of('email'), 'invalid_format', 'bad email', ['detail' => 'x']));
        $list = $issues->toJsonList();
        $this->assertCount(1, $list);
        $this->assertSame('/email', $list[0]['path']);
        $this->assertSame('invalid_format', $list[0]['code']);
        $this->assertArrayNotHasKey('messageKey', $list[0]);
        $this->assertSame('bad email', $list[0]['message']);
        $this->assertSame(['detail' => 'x'], $list[0]['meta']);
    }

    public function testFormat(): void
    {
        $issues = Issues::empty()
            ->add(Issue::of(Path::of('address', 'city'), 'blank', 'is blank'));
        $formatted = $issues->format();
        $this->assertSame(['is blank'], $formatted['address']['city']['_errors']);
    }

    public function testRebase(): void
    {
        $issues = Issues::empty()
            ->add(Issue::of(Path::of('name'), 'required', 'is required'));
        $rebased = $issues->rebase(Path::of('user'));
        $this->assertSame('/user/name', $rebased->toArray()[0]->path->toJsonPointer());
    }

    public function testFlattenUsesEmptyStringForRootPath(): void
    {
        $issues = Issues::empty()->add(
            Issue::of(Path::root(), 'required', 'is required')
        );
        $flat = $issues->flatten();
        $this->assertArrayHasKey('', $flat);
        $this->assertArrayNotHasKey('/', $flat);
    }
}
