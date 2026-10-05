<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Err;
use Raoh\Ok;
use Raoh\Value\Temporal\LocalDate;
use Raoh\Value\Temporal\LocalDateTime;

use function Raoh\Boundary\Array_\string_;

class TemporalDecoderTest extends TestCase
{
    public function testABoundIsAValueOrTheTextOfOne(): void
    {
        $byValue = string_()->date()->before(LocalDate::parse('2024-01-01') ?? throw new \LogicException());
        $byText = string_()->date()->before('2024-01-01');
        foreach ([$byValue, $byText] as $d) {
            $this->assertInstanceOf(Ok::class, $d->decode('2023-12-31'));
            $r = $d->decode('2024-01-01');
            $this->assertInstanceOf(Err::class, $r);
            $this->assertSame('out_of_range.before', $r->issues->toArray()[0]->messageKey);
        }
    }

    public function testABoundOfAnotherTypeIsRefusedWhenTheDecoderIsBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        string_()->date()->before(LocalDateTime::parse('2024-01-01T00:00') ?? throw new \LogicException());
    }

    public function testBoundsOfAnotherTypeAreRefusedByBetween(): void
    {
        $from = LocalDateTime::parse('2024-01-01T00:00') ?? throw new \LogicException();
        $to = LocalDateTime::parse('2024-12-31T00:00') ?? throw new \LogicException();
        $this->expectException(\InvalidArgumentException::class);
        string_()->date()->between($from, $to);
    }

    public function testTheTypeIsKeptAcrossOperations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        string_()->date()->after('2000-01-01')
            ->before(LocalDateTime::parse('2024-01-01T00:00') ?? throw new \LogicException());
    }
}
