<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Messages;

class MessagesTest extends TestCase
{
    public function testTheShippedCataloguesHaveEveryKeyInBothLanguages(): void
    {
        $en = Messages::english()->templates();
        $ja = Messages::japanese()->templates();
        $this->assertSame('must be at least {min}', $en['out_of_range.minimum']);
        $this->assertSame(array_keys($en), array_keys($ja));
    }

    public function testAPlaceholderWithNoEntryStaysAsWritten(): void
    {
        $this->assertSame('must be between 1 and {max}', Messages::english()->format('out_of_range.range', 'out_of_range', ['min' => 1]));
    }

    public function testAKeyWithNoTemplateFallsBackToItsCode(): void
    {
        $this->assertSame('invalid format', Messages::english()->format('invalid_format.json', 'invalid_format', []));
    }

    public function testEscapesAreRead(): void
    {
        $m = Messages::fromProperties("raoh.a=\\u00e9t\\u00e9\\tok\nraoh.b=x\\\\y\n");
        $this->assertSame("été\tok", $m->templates()['a']);
        $this->assertSame('x\\y', $m->templates()['b']);
    }

    public function testASurrogatePairIsReadAsTheCharacterItEncodes(): void
    {
        $m = Messages::fromProperties("raoh.smile=\\uD83D\\uDE00 {min}\n");
        $this->assertSame("\u{1F600} {min}", $m->templates()['smile']);
        $this->assertNotFalse(json_encode($m->templates()));
    }

    public function testAnUnpairedSurrogateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Messages::fromProperties("raoh.bad=\\uD83D alone\n");
    }
}
