<?php

declare(strict_types=1);

namespace Raoh\Tests\Boundary;

use PHPUnit\Framework\TestCase;
use Raoh\Absent;
use Raoh\Err;
use Raoh\Ok;
use Raoh\Present;
use Raoh\PresentNull;

use function Raoh\Boundary\Array_\bool_;
use function Raoh\Boundary\Array_\bytes;
use function Raoh\Boundary\Array_\combine;
use function Raoh\Boundary\Array_\enum_of;
use function Raoh\Boundary\Array_\field;
use function Raoh\Boundary\Array_\float_;
use function Raoh\Boundary\Array_\int_;
use function Raoh\Boundary\Array_\list_of;
use function Raoh\Boundary\Array_\nested;
use function Raoh\Boundary\Array_\nullable;
use function Raoh\Boundary\Array_\optional_field;
use function Raoh\Boundary\Array_\optional_nullable_field;
use function Raoh\Boundary\Array_\string_;

class ArrayDecodersTest extends TestCase
{
    public function testFieldRequired(): void
    {
        $dec = field('name', string_());
        $r = $dec->decode(['name' => 'Alice']);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('Alice', $r->value);
    }

    public function testFieldMissingIsRequired(): void
    {
        $dec = field('name', string_());
        $r = $dec->decode([]);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('required', $r->issues->toArray()[0]->code);
        $this->assertSame('/name', $r->issues->toArray()[0]->path->toJsonPointer());
    }

    public function testOptionalFieldAbsent(): void
    {
        $dec = optional_field('bio', string_());
        $r = $dec->decode([]);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertNull($r->value);
    }

    public function testOptionalFieldPresent(): void
    {
        $dec = optional_field('bio', string_());
        $r = $dec->decode(['bio' => 'Developer']);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('Developer', $r->value);
    }

    public function testOptionalNullableAbsent(): void
    {
        $dec = optional_nullable_field('bio', string_());
        $r = $dec->decode([]);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertInstanceOf(Absent::class, $r->value);
    }

    public function testOptionalNullableNull(): void
    {
        $dec = optional_nullable_field('bio', string_());
        $r = $dec->decode(['bio' => null]);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertInstanceOf(PresentNull::class, $r->value);
    }

    public function testOptionalNullablePresent(): void
    {
        $dec = optional_nullable_field('bio', string_());
        $r = $dec->decode(['bio' => 'Developer']);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertInstanceOf(Present::class, $r->value);
        $this->assertSame('Developer', $r->value->value);
    }

    public function testNestedObject(): void
    {
        $dec = combine(
            field('name',    string_()),
            field('address', nested(combine(
                field('city',  string_()),
                field('zip',   string_()),
            )->map(fn ($city, $zip) => ['city' => $city, 'zip' => $zip]))),
        )->map(fn ($name, $address) => ['name' => $name, 'address' => $address]);

        $r = $dec->decode(['name' => 'Alice', 'address' => ['city' => 'Tokyo', 'zip' => '100-0001']]);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('Tokyo', $r->value['address']['city']);
    }

    public function testNestedPropagatesPath(): void
    {
        $dec = combine(
            field('address', nested(combine(
                field('city', string_()->nonBlank()),
            )->map(fn ($city) => ['city' => $city]))),
        )->map(fn ($address) => $address);

        $r = $dec->decode(['address' => ['city' => '']]);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('/address/city', $r->issues->toArray()[0]->path->toJsonPointer());
    }

    public function testListOf(): void
    {
        $dec = list_of(int_());
        $r = $dec->decode([1, 2, 3]);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame([1, 2, 3], $r->value);
    }

    public function testListOfAccumulatesErrors(): void
    {
        $dec = list_of(int_()->positive());
        $r = $dec->decode([1, -2, 3, -4]);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertCount(2, $r->issues->toArray());
        $this->assertSame('/1', $r->issues->toArray()[0]->path->toJsonPointer());
        $this->assertSame('/3', $r->issues->toArray()[1]->path->toJsonPointer());
    }

    public function testNullable(): void
    {
        $dec = nullable(string_());
        $this->assertInstanceOf(Ok::class, $dec->decode(null));
        $this->assertNull($dec->decode(null)->value);
        $this->assertInstanceOf(Ok::class, $dec->decode('hello'));
    }

    public function testBool(): void
    {
        $r = bool_()->decode(true);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertTrue($r->value);
    }

    public function testFloat(): void
    {
        $r = float_()->decode(3.14);
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('3.14', (string) $r->value);
    }

    public function testEnumOfValid(): void
    {
        $r = enum_of(\Raoh\Tests\Fixtures\Status::class)->decode('active');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(\Raoh\Tests\Fixtures\Status::Active, $r->value);
    }

    public function testEnumOfInvalid(): void
    {
        $r = enum_of(\Raoh\Tests\Fixtures\Status::class)->decode('unknown');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('invalid_format.enum', $r->issues->toArray()[0]->messageKey);
    }

    public function testEnumOfRequired(): void
    {
        $r = enum_of(\Raoh\Tests\Fixtures\Status::class)->decode(null);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('required', $r->issues->toArray()[0]->code);
    }

    public function testEnumOfPureEnumValid(): void
    {
        $r = enum_of(\Raoh\Tests\Fixtures\Color::class)->decode('Red');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(\Raoh\Tests\Fixtures\Color::Red, $r->value);
    }

    public function testEnumOfPureEnumInvalid(): void
    {
        $r = enum_of(\Raoh\Tests\Fixtures\Color::class)->decode('Green');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('invalid_format.enum', $r->issues->toArray()[0]->messageKey);
    }

    public function testEnumOfNotEnumThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        enum_of(\stdClass::class);
    }

    public function testBytesDecodesString(): void
    {
        $dec = bytes();
        $r = $dec->decode("\x00\x01\x02");
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame("\x00\x01\x02", $r->value);
    }

    public function testBytesFailsOnNull(): void
    {
        $dec = bytes();
        $r = $dec->decode(null);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('required', $r->issues->toArray()[0]->code);
    }

    public function testBytesFailsOnNonString(): void
    {
        $dec = bytes();
        $r = $dec->decode(42);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('type_mismatch', $r->issues->toArray()[0]->code);
    }
}
