<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Issue;
use Raoh\Path;

class IssueTest extends TestCase
{
    public function testMessageKeyDefaultsToCode(): void
    {
        $issue = Issue::of(Path::root(), 'required', 'is required');
        $this->assertSame('required', $issue->messageKey);
    }

    public function testMessageKeyCanBeExplicit(): void
    {
        $issue = Issue::of(Path::root(), 'out_of_range', 'must be positive', [], 'out_of_range.positive');
        $this->assertSame('out_of_range', $issue->code);
        $this->assertSame('out_of_range.positive', $issue->messageKey);
    }

    public function testRebasePreservesMessageKey(): void
    {
        $issue = Issue::of(Path::of('n'), 'out_of_range', 'must be positive', [], 'out_of_range.positive');
        $rebased = $issue->rebase(Path::of('user'));
        $this->assertSame('out_of_range.positive', $rebased->messageKey);
    }

    public function testWithCustomMessagePreservesMessageKey(): void
    {
        $issue = Issue::of(Path::root(), 'out_of_range', 'must be positive', [], 'out_of_range.positive');
        $custom = $issue->withCustomMessage('カスタム');
        $this->assertSame('out_of_range.positive', $custom->messageKey);
        $this->assertTrue($custom->customMessage);
        $this->assertSame('カスタム', $custom->message);
    }

    public function testResolveTriesMessageKeyBeforeCode(): void
    {
        $issue = Issue::of(Path::root(), 'out_of_range', 'must be positive', [], 'out_of_range.positive');
        $resolved = $issue->resolve(fn (string $key, array $meta) => match ($key) {
            'out_of_range.positive' => 'must be greater than zero',
            default => null,
        });
        $this->assertSame('must be greater than zero', $resolved->message);
        $this->assertTrue($resolved->customMessage);
    }

    public function testResolveFallsBackToCodeWhenKeyUnresolved(): void
    {
        $issue = Issue::of(Path::root(), 'out_of_range', 'must be positive', [], 'out_of_range.positive');
        $resolved = $issue->resolve(fn (string $key, array $meta) => match ($key) {
            'out_of_range' => 'out of range',
            default => null,
        });
        $this->assertSame('out of range', $resolved->message);
    }

    public function testResolveKeepsOriginalMessageWhenNothingResolves(): void
    {
        $issue = Issue::of(Path::root(), 'out_of_range', 'must be positive', [], 'out_of_range.positive');
        $resolved = $issue->resolve(fn (string $key, array $meta) => null);
        $this->assertSame('must be positive', $resolved->message);
        $this->assertTrue($resolved->customMessage);
    }

    public function testResolveCallsResolverOnceWhenMessageKeyEqualsCode(): void
    {
        $issue = Issue::of(Path::root(), 'required', 'is required');
        $calls = 0;
        $issue->resolve(function (string $key, array $meta) use (&$calls): ?string {
            $calls++;
            return null;
        });
        $this->assertSame(1, $calls);
    }

    public function testResolveDoesNotOverrideExistingCustomMessage(): void
    {
        $issue = Issue::of(Path::root(), 'out_of_range', 'must be positive', [], 'out_of_range.positive')
            ->withCustomMessage('カスタム');
        $resolved = $issue->resolve(fn (string $key, array $meta) => 'resolved');
        $this->assertSame('カスタム', $resolved->message);
    }
}
