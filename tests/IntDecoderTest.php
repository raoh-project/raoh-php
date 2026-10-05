<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Err;
use Raoh\ErrorCodes;
use Raoh\MessageKeys;
use Raoh\Ok;

use function Raoh\Boundary\Array_\int_;

class IntDecoderTest extends TestCase
{
    public function testDecodeInt(): void
    {
        $r = int_()->decode(42);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(42, $r->value);
    }

    public function testNumericStringIsNotANumber(): void
    {
        // A string is a string: form data is read with string_()->toInt().
        $r = int_()->decode('42');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame(['expected' => 'integer', 'actual' => 'string'], $r->issues->toArray()[0]->meta);
    }

    public function testRequiredOnNull(): void
    {
        $r = int_()->decode(null);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('required', $r->issues->toArray()[0]->code);
    }

    public function testMin(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->min(0)->decode(0));
        $r = int_()->min(1)->decode(0);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangeMinimum->value, $issue->messageKey);
        $this->assertSame(['min' => 1, 'actual' => 0], $issue->meta);
    }

    public function testMax(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->max(10)->decode(10));
        $r = int_()->max(10)->decode(11);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangeMaximum->value, $issue->messageKey);
        $this->assertSame(['max' => 10, 'actual' => 11], $issue->meta);
    }

    public function testRange(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->range(0, 150)->decode(25));
        $r = int_()->range(0, 150)->decode(200);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangeRange->value, $issue->messageKey);
        $this->assertSame(['min' => 0, 'max' => 150, 'actual' => 200], $issue->meta);
    }

    public function testPositive(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->positive()->decode(1));
        $r = int_()->positive()->decode(0);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangePositive->value, $issue->messageKey);
        $this->assertInstanceOf(Err::class, int_()->positive()->decode(-1));
    }

    public function testNegative(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->negative()->decode(-1));
        $r = int_()->negative()->decode(0);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangeNegative->value, $issue->messageKey);
    }

    public function testNonNegative(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->nonNegative()->decode(0));
        $r = int_()->nonNegative()->decode(-1);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangeNonNegative->value, $issue->messageKey);
    }

    public function testNonPositive(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->nonPositive()->decode(0));
        $r = int_()->nonPositive()->decode(1);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangeNonPositive->value, $issue->messageKey);
    }

    public function testCustomMessageSurvivesResolve(): void
    {
        $r = int_()->positive('正の数にしてください')->decode(0);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertTrue($issue->customMessage);
        $this->assertSame('正の数にしてください', $issue->message);
        $resolved = $issue->resolve(fn () => 'must be at least {min}');
        $this->assertSame('正の数にしてください', $resolved->message);
    }

    public function testMultipleOf(): void
    {
        $this->assertInstanceOf(Ok::class, int_()->multipleOf(3)->decode(9));
        $r = int_()->multipleOf(3)->decode(10);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('not_multiple_of', $r->issues->toArray()[0]->code);
    }

    public function testRangeInvertedThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        int_()->range(100, 50);
    }
}
