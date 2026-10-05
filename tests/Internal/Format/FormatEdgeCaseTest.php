<?php

declare(strict_types=1);

namespace Raoh\Tests\Internal\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Internal\Format\Cuid;
use Raoh\Internal\Format\Email;
use Raoh\Internal\Format\Ip;
use Raoh\Internal\Format\Ulid;
use Raoh\Internal\Format\Uri;
use Raoh\Internal\Format\Uuid;

final class FormatEdgeCaseTest extends TestCase
{
    public function testEmailLocalPartLength(): void
    {
        $this->assertTrue(Email::matches(str_repeat('a', 64) . '@b.co'));
        $this->assertFalse(Email::matches(str_repeat('a', 65) . '@b.co'));
        $this->assertTrue(Email::matches(str_repeat('a.', 31) . 'aa@b.co'));
    }

    public function testEmailLabelLength(): void
    {
        $this->assertTrue(Email::matches('a@' . str_repeat('b', 63) . '.co'));
        $this->assertFalse(Email::matches('a@' . str_repeat('b', 64) . '.co'));
        $this->assertTrue(Email::matches('a@co.' . str_repeat('b', 63)));
        $this->assertFalse(Email::matches('a@co.' . str_repeat('b', 64)));
    }

    public function testEmailWholeLength(): void
    {
        // 1 + 1 + 252 = 254 octets.
        $domain252 = implode('.', [str_repeat('b', 63), str_repeat('b', 63), str_repeat('b', 63), str_repeat('b', 60)]);
        $this->assertSame(252, strlen($domain252));
        $this->assertTrue(Email::matches('a@' . $domain252));
        $this->assertFalse(Email::matches('ab@' . $domain252));
    }

    public function testEmailDomainOf255IsRejectedByTheWholeLimit(): void
    {
        // The 254-octet limit on the whole mailbox is the binding one: a
        // 255-octet domain is within its own limit but makes the whole too long.
        $domain = implode('.', array_fill(0, 4, str_repeat('b', 63)));
        $this->assertSame(255, strlen($domain));
        $this->assertFalse(Email::matches('a@' . $domain));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function emails(): iterable
    {
        yield 'trailing newline' => ["a@b.co\n", false];
        yield 'single-character label' => ['a@b', true];
        yield 'hyphen inside label' => ['a@b--c.co', true];
        yield 'empty' => ['', false];
        yield 'no at' => ['ab.co', false];
        yield 'domain starting with dot' => ['a@.b', false];
        yield 'NUL' => ["a\0@b.co", false];
    }

    #[DataProvider('emails')]
    public function testEmail(string $s, bool $ok): void
    {
        $this->assertSame($ok, Email::matches($s));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ipv4s(): iterable
    {
        yield 'leading zero' => ['01.2.3.4', false];
        yield 'double zero' => ['00.2.3.4', false];
        yield 'zero' => ['0.0.0.0', true];
        yield 'ten' => ['10.0.0.1', true];
        yield '199' => ['199.249.250.251', true];
        yield '260' => ['1.2.3.260', false];
        yield 'empty octet' => ['1..3.4', false];
        yield 'trailing dot' => ['1.2.3.4.', false];
        yield 'trailing newline' => ["1.2.3.4\n", false];
        yield 'plus sign' => ['+1.2.3.4', false];
    }

    #[DataProvider('ipv4s')]
    public function testIpv4(string $s, bool $ok): void
    {
        $this->assertSame($ok, Ip::isV4($s));
        $this->assertSame($ok, Ip::isIp($s));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ipv6s(): iterable
    {
        yield 'full' => ['2001:0db8:0000:0000:0000:ff00:0042:8329', true];
        yield 'upper case' => ['2001:DB8::FF', true];
        yield 'seven groups without ::' => ['1:2:3:4:5:6:7', false];
        yield 'nine groups' => ['1:2:3:4:5:6:7:8:9', false];
        yield ':: replacing one group' => ['1:2:3:4:5:6:7::', true];
        yield ':: replacing one group at start' => ['::2:3:4:5:6:7:8', true];
        yield ':: with eight groups' => ['1:2:3:4::5:6:7:8', false];
        yield ':: with eight groups at end' => ['1:2:3:4:5:6:7:8::', false];
        yield 'trailing ::' => ['1::', true];
        yield 'triple colon' => [':::', false];
        yield 'single leading colon' => [':1:2:3:4:5:6:7', false];
        yield 'single trailing colon' => ['1:2:3:4:5:6:7:', false];
        yield 'five hex digits' => ['12345::', false];
        yield 'embedded ipv4 full' => ['1:2:3:4:5:6:1.2.3.4', true];
        yield 'embedded ipv4 too many groups' => ['1:2:3:4:5:6:7:1.2.3.4', false];
        yield 'embedded ipv4 with ::' => ['1:2:3:4:5::1.2.3.4', true];
        yield 'embedded ipv4 with :: filling nothing' => ['1:2:3:4:5:6::1.2.3.4', false];
        yield 'embedded ipv4 not last' => ['1.2.3.4::', false];
        yield 'embedded ipv4 in middle' => ['::1.2.3.4:1', false];
        yield 'embedded ipv4 leading zero' => ['::01.2.3.4', false];
        yield 'embedded ipv4 alone' => ['1.2.3.4', false];
        yield 'empty' => ['', false];
        yield 'zone on fe80::/10 upper end' => ['febf::1%1', true];
        yield 'zone on ::' => ['::%1', false];
        yield 'zone on embedded ipv4 link-local' => ['fe80::1.2.3.4%x', true];
        yield 'zone on upper case link-local' => ['FE80::1%x', true];
        yield 'zone on multicast with flags' => ['ff12::1%x', true];
        yield 'zone on multicast scope e with flags' => ['ff1e::1%x', false];
        yield 'zone with non-ascii' => ['fe80::1%日本', true];
        yield 'zone with space' => ['fe80::1% ', true];
        yield 'percent only on bad address' => ['fe80::g%x', false];
    }

    #[DataProvider('ipv6s')]
    public function testIpv6(string $s, bool $ok): void
    {
        $this->assertSame($ok, Ip::isV6($s));
        $this->assertSame($ok || Ip::isV4($s), Ip::isIp($s));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ulids(): iterable
    {
        yield 'max' => ['7ZZZZZZZZZZZZZZZZZZZZZZZZZ', true];
        yield 'max lower case' => ['7zzzzzzzzzzzzzzzzzzzzzzzzz', true];
        yield 'zero' => ['00000000000000000000000000', true];
        yield 'over max' => ['8ZZZZZZZZZZZZZZZZZZZZZZZZZ', false];
        yield 'leading Z' => ['Z0000000000000000000000000', false];
        yield 'contains L' => ['0000000000000000000000000L', false];
        yield 'contains O' => ['0000000000000000000000000o', false];
        yield 'contains U' => ['0000000000000000000000000U', false];
        yield '25 characters' => ['0000000000000000000000000', false];
        yield '27 characters' => ['000000000000000000000000000', false];
        yield 'trailing newline' => ["00000000000000000000000000\n", false];
    }

    #[DataProvider('ulids')]
    public function testUlid(string $s, bool $ok): void
    {
        $this->assertSame($ok, Ulid::matches($s));
    }

    public function testCuidRejectsTrailingNewline(): void
    {
        $this->assertFalse(Cuid::matches("cjld2cjxh0000qzrmn831i7rn\n"));
    }

    public function testUuid(): void
    {
        $this->assertSame('abcdef01-2345-6789-abcd-ef0123456789', Uuid::read('ABCDEF01-2345-6789-AbCd-eF0123456789'));
        $this->assertNull(Uuid::read("123e4567-e89b-12d3-a456-426614174000\n"));
        $this->assertNull(Uuid::read('123e4567-e89b-12d3-a456_426614174000'));
        $this->assertNull(Uuid::read(' 123e4567-e89b-12d3-a456-426614174000'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function uris(): iterable
    {
        yield 'ipv4 host' => ['http://127.0.0.1:8080/', true];
        yield 'empty port' => ['http://host:/', true];
        yield 'non-digit port' => ['http://host:8a/', false];
        yield 'two ports' => ['http://host:1:2/', false];
        yield 'empty userinfo' => ['http://@host/', true];
        yield 'two at signs' => ['http://a@b@host/', false];
        yield 'userinfo with colons' => ['http://a:b:c@host/', true];
        yield 'bracket in userinfo' => ['http://a[@host/', false];
        yield 'ip literal followed by garbage' => ['http://[::1]x/', false];
        yield 'ipv6 with embedded ipv4' => ['http://[::ffff:1.2.3.4]/', true];
        yield 'empty ip literal' => ['http://[]/', false];
        yield 'ipvfuture without address' => ['http://[v1.]/', false];
        yield 'ipvfuture without version' => ['http://[v.abc]/', false];
        yield 'ipvfuture with pct-encoding' => ['http://[v1.%41]/', false];
        yield 'ipvfuture with colon' => ['http://[v1f.a:b]/', true];
        yield 'empty authority with path' => ['file:///a/b', true];
        yield 'path-absolute with empty segments' => ['a:/b//c', true];
        yield 'path-rootless with empty segments' => ['a:b//c', true];
        yield 'path-rootless with colon' => ['a:b:c', true];
        yield 'query with slash and question mark' => ['a:?/?x', true];
        yield 'fragment with question mark' => ['a:#?/x', true];
        yield 'bracket in path' => ['a:b[c]', false];
        yield 'bracket in query' => ['a:?[', false];
        yield 'percent at end' => ['a:b%', false];
        yield 'percent with one digit' => ['a:b%4', false];
        yield 'backslash' => ['a:b\\c', false];
        yield 'trailing newline' => ["a:b\n", false];
        yield 'NUL' => ["a:b\0", false];
        yield 'empty scheme' => [':b', false];
        yield 'scheme with underscore' => ['a_b:c', false];
        yield 'pct-encoded host' => ['http://%7e/', true];
        yield 'sub-delims host' => ["http://a!$&'()*+,;=/", true];
        yield 'zone identifier with dash' => ['http://[fe80::1%25-]/', false];
    }

    #[DataProvider('uris')]
    public function testUri(string $s, bool $ok): void
    {
        $this->assertSame($ok, Uri::isUri($s));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'mixed case scheme' => ['HtTpS://host', true];
        yield 'no authority' => ['http:host', false];
        yield 'path-absolute' => ['http:/host', false];
        yield 'userinfo but empty host' => ['http://user@/', false];
        yield 'port but empty host' => ['http://:80/', false];
        yield 'ip literal host' => ['https://[::1]', true];
        yield 'httpx scheme' => ['httpx://host', false];
        yield 'not a uri' => ['http://host/ a', false];
    }

    #[DataProvider('urls')]
    public function testUrl(string $s, bool $ok): void
    {
        $this->assertSame($ok, Uri::isUrl($s));
    }
}
