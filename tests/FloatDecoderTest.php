<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Err;
use Raoh\ErrorCodes;
use Raoh\Input\Json;
use Raoh\MessageKeys;
use Raoh\Ok;
use Raoh\Value\Float32;

use function Raoh\Boundary\Array_\double;
use function Raoh\Boundary\Array_\float_;

class FloatDecoderTest extends TestCase
{
    public function testDecodeDouble(): void
    {
        $r = double()->decode(3.14);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(3.14, $r->value);
    }

    public function testDecodeIntAsDouble(): void
    {
        $r = double()->decode(42);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(42.0, $r->value);
    }

    public function testNumericStringIsNotANumber(): void
    {
        $r = double()->decode('1.23');
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame('type_mismatch', $issue->code);
        $this->assertSame(['expected' => 'double', 'actual' => 'string'], $issue->meta);
    }

    public function testFloatIsRoundedOnceToFloat32(): void
    {
        $r = float_()->decode(Json::parse('0.1'));
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertInstanceOf(Float32::class, $r->value);
        $this->assertSame('0.1', (string) $r->value);
        $this->assertSame((float) 0.100000001490116119384765625, $r->value->value);
    }

    public function testNegativeZeroIsKept(): void
    {
        $r = double()->decode(Json::parse('-0'));
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(-INF, fdiv(1, $r->value));
    }

    public function testOutOfRangeFloat32(): void
    {
        $r = float_()->decode(Json::parse('1e39'));
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('type_mismatch.numeric_range', $r->issues->toArray()[0]->messageKey);
    }

    public function testRequiredOnNull(): void
    {
        $r = double()->decode(null);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('required', $r->issues->toArray()[0]->code);
    }

    public function testMin(): void
    {
        $this->assertInstanceOf(Ok::class, double()->min(0.0)->decode(0.0));
        $r = double()->min(1.0)->decode(0.5);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::OutOfRange->value, $issue->code);
        $this->assertSame(MessageKeys::OutOfRangeMinimum->value, $issue->messageKey);
        $this->assertSame(['min' => 1.0, 'actual' => 0.5], $issue->meta);
        $this->assertSame('must be at least 1.0', $issue->message);
    }

    public function testFloat32BoundIsWrittenAtItsWidth(): void
    {
        $r = float_()->min(0.1)->decode(0.05);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('must be at least 0.1', $r->issues->toArray()[0]->message);
    }

    public function testMax(): void
    {
        $this->assertInstanceOf(Ok::class, double()->max(10.0)->decode(10.0));
        $r = double()->max(10.0)->decode(10.1);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(MessageKeys::OutOfRangeMaximum->value, $issue->messageKey);
        $this->assertSame(['max' => 10.0, 'actual' => 10.1], $issue->meta);
    }

    public function testRange(): void
    {
        $this->assertInstanceOf(Ok::class, double()->range(0.0, 1.0)->decode(0.5));
        $r = double()->range(0.0, 1.0)->decode(1.1);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(MessageKeys::OutOfRangeRange->value, $issue->messageKey);
        $this->assertSame(['min' => 0.0, 'max' => 1.0, 'actual' => 1.1], $issue->meta);
    }

    public function testRangeInvertedThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        double()->range(100.0, 50.0);
    }

    public function testPositive(): void
    {
        $this->assertInstanceOf(Ok::class, double()->positive()->decode(0.1));
        $r = double()->positive()->decode(0.0);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(MessageKeys::OutOfRangePositive->value, $issue->messageKey);
        $this->assertSame(['min' => 0.0, 'actual' => 0.0], $issue->meta);
        $this->assertInstanceOf(Err::class, double()->positive()->decode(-1.0));
    }

    public function testNegativeZeroIsNegative(): void
    {
        $this->assertInstanceOf(Ok::class, double()->negative()->decode(Json::parse('-0.0')));
        $this->assertInstanceOf(Err::class, double()->nonNegative()->decode(Json::parse('-0.0')));
    }

    public function testCustomMessageSurvivesResolve(): void
    {
        $r = double()->positive('正の数にしてください')->decode(0.0);
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertTrue($issue->customMessage);
        $this->assertSame('正の数にしてください', $issue->message);
        $resolved = $issue->resolve(fn () => 'must be positive');
        $this->assertSame('正の数にしてください', $resolved->message);
    }
}
