<?php

declare(strict_types=1);

namespace Raoh\Tests\Value;

use PHPUnit\Framework\TestCase;
use Raoh\Value\Float32;

final class Float32Test extends TestCase
{
    public function testHoldsTheNearestBinary32(): void
    {
        $this->assertSame(0.100000001490116119384765625, (new Float32(0.1))->value);
        $this->assertSame(16777216.0, (new Float32(16777217.0))->value);
    }

    public function testWritesItsMessageForm(): void
    {
        $this->assertSame('0.1', (string) new Float32(0.1));
        $this->assertSame('1.0E7', (string) new Float32(1.0E7));
        $this->assertSame('1.4E-45', (string) Float32::fromLexeme('1.4e-45'));
        $this->assertSame('-0.0', (string) new Float32(-0.0));
        $this->assertSame('NaN', (string) new Float32(NAN));
    }

    public function testEquals(): void
    {
        $this->assertTrue((new Float32(0.1))->equals(Float32::fromLexeme('0.1')));
        $this->assertFalse((new Float32(0.0))->equals(new Float32(-0.0)));
        $this->assertTrue((new Float32(NAN))->equals(new Float32(NAN)));
        $this->assertFalse((new Float32(1.0))->equals(new Float32(2.0)));
    }

    public function testRoundsOnceFromTheLexeme(): void
    {
        $this->assertSame(1.0, Float32::fromLexeme('1.000000059604644775390625')->value);
        $this->assertSame('1.0000001', (string) Float32::fromLexeme('1.0000000596046448'));
    }
}
