<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\Err;
use Raoh\ErrorCodes;
use Raoh\MessageKeys;
use Raoh\Ok;

use function Raoh\Boundary\Array_\string_;

class StringDecoderTest extends TestCase
{
    public function testDecodeString(): void
    {
        $r = string_()->decode('hello');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('hello', $r->value);
    }

    public function testRequiredOnNull(): void
    {
        $r = string_()->decode(null);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('required', $r->issues->toArray()[0]->code);
    }

    public function testTypeMismatch(): void
    {
        $r = string_()->decode(42);
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('type_mismatch', $r->issues->toArray()[0]->code);
    }

    public function testTrim(): void
    {
        $r = string_()->trim()->decode('  hello  ');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('hello', $r->value);
    }

    public function testToLowerCase(): void
    {
        $r = string_()->toLowerCase()->decode('HELLO');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('hello', $r->value);
    }

    public function testNonBlankPass(): void
    {
        $r = string_()->nonBlank()->decode('hello');
        $this->assertInstanceOf(Ok::class, $r);
    }

    public function testNonBlankFail(): void
    {
        $r = string_()->nonBlank()->decode('   ');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('blank', $r->issues->toArray()[0]->code);
    }

    public function testMinLength(): void
    {
        $r = string_()->minLength(3)->decode('ab');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('too_short', $r->issues->toArray()[0]->code);
    }

    public function testMaxLength(): void
    {
        $r = string_()->maxLength(5)->decode('toolong');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('too_long', $r->issues->toArray()[0]->code);
    }

    public function testEmailValid(): void
    {
        $r = string_()->email()->decode('user@example.com');
        $this->assertInstanceOf(Ok::class, $r);
    }

    public function testEmailInvalid(): void
    {
        $r = string_()->email()->decode('not-an-email');
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(ErrorCodes::InvalidFormat->value, $issue->code);
        $this->assertSame(MessageKeys::InvalidFormatEmail->value, $issue->messageKey);
    }

    public function testEmailCustomMessageSurvivesResolve(): void
    {
        $r = string_()->email('メールアドレスの形式が不正です')->decode('not-an-email');
        $issue = $r->issues->toArray()[0];
        $this->assertTrue($issue->customMessage);
        $resolved = $issue->resolve(fn () => 'resolver output');
        $this->assertSame('メールアドレスの形式が不正です', $resolved->message);
    }

    public function testChaining(): void
    {
        $dec = string_()->trim()->toLowerCase()->email();
        $r = $dec->decode('  USER@EXAMPLE.COM  ');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('user@example.com', $r->value);
    }

    public function testPattern(): void
    {
        // The pattern language of the specification, matched against the whole string.
        $r = string_()->pattern('\d{3}-\d{4}')->decode('123-4567');
        $this->assertInstanceOf(Ok::class, $r);

        $r2 = string_()->pattern('\d{3}-\d{4}')->decode('invalid');
        $this->assertInstanceOf(Err::class, $r2);
        $this->assertSame(ErrorCodes::InvalidFormat->value, $r2->issues->toArray()[0]->messageKey);
        $this->assertSame(['pattern' => '\d{3}-\d{4}'], $r2->issues->toArray()[0]->meta);
    }

    public function testPatternRefusesWhatTheLanguageDoesNotHave(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        string_()->pattern('(?=a)a');
    }

    public function testUuid(): void
    {
        $r = string_()->uuid()->decode('550e8400-e29b-41d4-a716-446655440000');
        $this->assertInstanceOf(Ok::class, $r);

        $r2 = string_()->uuid()->decode('not-a-uuid');
        $this->assertInstanceOf(Err::class, $r2);
        $issue = $r2->issues->toArray()[0];
        $this->assertSame(ErrorCodes::InvalidFormat->value, $issue->code);
        $this->assertSame(MessageKeys::InvalidFormatUuid->value, $issue->messageKey);
    }

    public function testUlid(): void
    {
        $r = string_()->ulid()->decode('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $this->assertInstanceOf(Ok::class, $r);

        $r2 = string_()->ulid()->decode('not-a-ulid');
        $this->assertInstanceOf(Err::class, $r2);
        $this->assertSame(MessageKeys::InvalidFormatUlid->value, $r2->issues->toArray()[0]->messageKey);
    }

    public function testIp(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->ip()->decode('127.0.0.1'));
        $r = string_()->ip()->decode('not-an-ip');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame(MessageKeys::InvalidFormatIp->value, $r->issues->toArray()[0]->messageKey);
    }

    public function testIpv4(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->ipv4()->decode('127.0.0.1'));
        $r = string_()->ipv4()->decode('::1');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame(MessageKeys::InvalidFormatIpv4->value, $r->issues->toArray()[0]->messageKey);
    }

    public function testIpv6(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->ipv6()->decode('::1'));
        $r = string_()->ipv6()->decode('127.0.0.1');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame(MessageKeys::InvalidFormatIpv6->value, $r->issues->toArray()[0]->messageKey);
    }

    public function testStartsWith(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->startsWith('foo')->decode('foobar'));
        $r = string_()->startsWith('foo')->decode('barfoo');
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(MessageKeys::InvalidFormatStartsWith->value, $issue->messageKey);
        $this->assertSame(['prefix' => 'foo'], $issue->meta);
    }

    public function testEndsWith(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->endsWith('bar')->decode('foobar'));
        $r = string_()->endsWith('bar')->decode('barfoo');
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(MessageKeys::InvalidFormatEndsWith->value, $issue->messageKey);
        $this->assertSame(['suffix' => 'bar'], $issue->meta);
    }

    public function testIncludes(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->includes('oob')->decode('foobar'));
        $r = string_()->includes('xyz')->decode('foobar');
        $this->assertInstanceOf(Err::class, $r);
        $issue = $r->issues->toArray()[0];
        $this->assertSame(MessageKeys::InvalidFormatIncludes->value, $issue->messageKey);
        $this->assertSame(['substring' => 'xyz'], $issue->meta);
    }

    public function testToInt(): void
    {
        $r = string_()->toInt()->decode('42');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame(42, $r->value);
    }

    public function testToIntFail(): void
    {
        $r = string_()->toInt()->decode('12.5');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('type_mismatch', $r->issues->toArray()[0]->code);
    }

    public function testMap(): void
    {
        $r = string_()->map(fn ($s) => strtoupper($s))->decode('hello');
        $this->assertInstanceOf(Ok::class, $r);
        $this->assertSame('HELLO', $r->value);
    }

    public function testPatternInvalidRegexThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        string_()->pattern('/[invalid/');
    }

    public function testUrlValid(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->url()->decode('https://example.com'));
        $this->assertInstanceOf(Ok::class, string_()->url()->decode('http://example.com:8080/path'));
    }

    public function testUrlInvalidScheme(): void
    {
        $r = string_()->url()->decode('ftp://example.com');
        $this->assertInstanceOf(Err::class, $r);
        $this->assertSame('invalid_format', $r->issues->toArray()[0]->code);
    }

    public function testUrlPortIsNotCheckedAgainstARange(): void
    {
        // RFC 3986's port is any run of digits; the specification does not check a range.
        $this->assertInstanceOf(Ok::class, string_()->url()->decode('http://example.com:99999'));
    }

    public function testUrlPortBoundary(): void
    {
        $this->assertInstanceOf(Ok::class, string_()->url()->decode('http://example.com:1'));
        $this->assertInstanceOf(Ok::class, string_()->url()->decode('http://example.com:65535'));
    }
}
