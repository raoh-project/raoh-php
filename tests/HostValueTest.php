<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Err;
use Raoh\Ok;

use function Raoh\Boundary\Array_\decimal;
use function Raoh\Boundary\Array_\double;
use function Raoh\Boundary\Array_\field;
use function Raoh\Boundary\Array_\int_;
use function Raoh\Boundary\Array_\object;
use function Raoh\Boundary\Array_\string_;

/**
 * PHP values a decoder reads besides the input model: what a framework's request array or a
 * database row can hold.
 */
class HostValueTest extends TestCase
{
    public function testAnObjectOutsideTheModelIsATypeMismatch(): void
    {
        $r = string_()->decode(new \DateTimeImmutable());
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame('type_mismatch', $issue->code);
        $this->assertSame(['expected' => 'string', 'actual' => 'DateTimeImmutable'], $issue->meta);
    }

    public function testAFieldOfAnObjectOutsideTheModelIsATypeMismatch(): void
    {
        $r = object(field('a', string_()))->decode(new \ArrayObject(['a' => 'x']));
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame('/a', $issue->path->toJsonPointer());
        $this->assertSame(['expected' => 'object', 'actual' => 'ArrayObject'], $issue->meta);
    }

    public function testAMemberOutsideTheModelLeavesTheOthersDecoded(): void
    {
        $upload = new \SplFileInfo(__FILE__);
        $r = object(field('name', string_()), field('photo', string_()))
            ->decode(['name' => 'Ken', 'photo' => $upload]);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertCount(1, $r->issues->toArray());
        $this->assertSame('/photo', $r->issues->toArray()[0]->path->toJsonPointer());
    }

    public function testAFloatThatIsNotFiniteIsATypeMismatch(): void
    {
        $nan = double()->decode(NAN);
        $this->assertInstanceOf(Err::class, $nan);
        $this->assertSame(['expected' => 'double', 'actual' => 'NAN'], $nan->issues->toArray()[0]->meta);

        $inf = int_()->decode(-INF);
        $this->assertInstanceOf(Err::class, $inf);
        $this->assertSame(['expected' => 'integer', 'actual' => 'INF'], $inf->issues->toArray()[0]->meta);
    }

    public function testAFloatIsReadAsItsShortestTextWhateverSerializePrecisionIs(): void
    {
        $was = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
        try {
            $r = decimal()->decode(0.1);
            $this->assertInstanceOf(Ok::class, $r);
            $this->assertSame('0.1', (string) $r->value);
            $this->assertSame(1, $r->value->scale());
            $this->assertSame('17', ini_get('serialize_precision'), 'the setting is given back');
        } finally {
            ini_set('serialize_precision', $was === false ? '-1' : $was);
        }
    }
}
