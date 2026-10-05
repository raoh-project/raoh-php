<?php

declare(strict_types=1);

namespace Raoh\Internal\Format;

use Raoh\Internal\Text;

/**
 * The URI production of RFC 3986 section 3, parsed by hand over the ABNF of
 * RFC 3986 Appendix A, in ASCII only. An IPv6 host with a zone identifier is
 * rejected (RFC 9844 removed it from the URI syntax).
 *
 * @internal
 */
final class Uri
{
    private const UNRESERVED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    private const SUB_DELIMS = "!$&'()*+,;=";
    private const PCHAR = self::UNRESERVED . self::SUB_DELIMS . ':@';

    private function __construct()
    {
    }

    /**
     * URI = scheme ":" hier-part [ "?" query ] [ "#" fragment ]
     */
    public static function isUri(string $s): bool
    {
        return self::parse($s) !== null;
    }

    /**
     * A URI per isUri whose scheme is http or https (ASCII case-insensitive)
     * and which has an authority with a non-empty host (RFC 9110 section 4.2).
     */
    public static function isUrl(string $s): bool
    {
        $parsed = self::parse($s);
        if ($parsed === null) {
            return false;
        }
        $scheme = Text::asciiLower($parsed['scheme']);
        return ($scheme === 'http' || $scheme === 'https')
            && $parsed['host'] !== null
            && $parsed['host'] !== '';
    }

    /**
     * @return array{scheme: string, host: ?string}|null the scheme and the host
     *         (null when there is no authority), or null when not a URI
     */
    private static function parse(string $s): ?array
    {
        $colon = strpos($s, ':');
        if ($colon === false) {
            return null;
        }
        $scheme = substr($s, 0, $colon);
        // scheme = ALPHA *( ALPHA / DIGIT / "+" / "-" / "." )
        if (preg_match('/\A[A-Za-z][A-Za-z0-9+\-.]*\z/', $scheme) !== 1) {
            return null;
        }
        $rest = substr($s, $colon + 1);

        // hier-part contains neither "?" nor "#", and query contains no "#",
        // so the first "#" starts the fragment and the first "?" before it
        // starts the query.
        $hash = strpos($rest, '#');
        if ($hash !== false) {
            // fragment = *( pchar / "/" / "?" )
            if (!self::consistsOf(substr($rest, $hash + 1), self::PCHAR . '/?')) {
                return null;
            }
            $rest = substr($rest, 0, $hash);
        }
        $question = strpos($rest, '?');
        if ($question !== false) {
            // query = *( pchar / "/" / "?" )
            if (!self::consistsOf(substr($rest, $question + 1), self::PCHAR . '/?')) {
                return null;
            }
            $rest = substr($rest, 0, $question);
        }

        if (str_starts_with($rest, '//')) {
            // "//" authority path-abempty
            $afterSlashes = substr($rest, 2);
            $slash = strpos($afterSlashes, '/');
            $authority = $slash === false ? $afterSlashes : substr($afterSlashes, 0, $slash);
            $path = $slash === false ? '' : substr($afterSlashes, $slash);
            $host = self::authorityHost($authority);
            if ($host === null) {
                return null;
            }
            // path-abempty = *( "/" segment ): empty or starting with "/".
            if (!self::consistsOf($path, self::PCHAR . '/')) {
                return null;
            }
            return ['scheme' => $scheme, 'host' => $host];
        }

        // path-absolute / path-rootless / path-empty. Without a leading "//",
        // any run of pchar and "/" is one of these: "/..." is path-absolute
        // (its first segment is non-empty or absent), a non-empty run not
        // starting with "/" is path-rootless, and "" is path-empty.
        if (!self::consistsOf($rest, self::PCHAR . '/')) {
            return null;
        }
        return ['scheme' => $scheme, 'host' => null];
    }

    /**
     * authority = [ userinfo "@" ] host [ ":" port ]
     *
     * @return string|null the host, or null when the authority is malformed
     */
    private static function authorityHost(string $authority): ?string
    {
        $hostPort = $authority;
        $at = strpos($authority, '@');
        if ($at !== false) {
            // userinfo = *( unreserved / pct-encoded / sub-delims / ":" )
            if (!self::consistsOf(substr($authority, 0, $at), self::UNRESERVED . self::SUB_DELIMS . ':')) {
                return null;
            }
            $hostPort = substr($authority, $at + 1);
        }

        if (str_starts_with($hostPort, '[')) {
            // IP-literal = "[" ( IPv6address / IPvFuture ) "]"
            $close = strpos($hostPort, ']');
            if ($close === false) {
                return null;
            }
            $literal = substr($hostPort, 1, $close - 1);
            if (!Ip::isV6Address($literal) && !self::isIpvFuture($literal)) {
                return null;
            }
            $host = substr($hostPort, 0, $close + 1);
            $afterHost = substr($hostPort, $close + 1);
        } else {
            // IPv4address / reg-name; reg-name = *( unreserved / pct-encoded / sub-delims ),
            // and every IPv4address is also a reg-name.
            $portColon = strpos($hostPort, ':');
            $host = $portColon === false ? $hostPort : substr($hostPort, 0, $portColon);
            $afterHost = $portColon === false ? '' : substr($hostPort, $portColon);
            if (!self::consistsOf($host, self::UNRESERVED . self::SUB_DELIMS)) {
                return null;
            }
        }

        if ($afterHost !== '') {
            // ":" port, port = *DIGIT
            if ($afterHost[0] !== ':' || preg_match('/\A[0-9]*\z/', substr($afterHost, 1)) !== 1) {
                return null;
            }
        }
        return $host;
    }

    /**
     * IPvFuture = "v" 1*HEXDIG "." 1*( unreserved / sub-delims / ":" )
     */
    private static function isIpvFuture(string $s): bool
    {
        if (preg_match('/\A[vV][0-9A-Fa-f]+\.(.+)\z/s', $s, $m) !== 1) {
            return false;
        }
        return self::consistsOf($m[1], self::UNRESERVED . self::SUB_DELIMS . ':', false);
    }

    /**
     * Whether every character of $s is in $allowed or, when $pctEncoded is
     * true, part of a pct-encoded triplet "%" HEXDIG HEXDIG.
     */
    private static function consistsOf(string $s, string $allowed, bool $pctEncoded = true): bool
    {
        $length = strlen($s);
        $i = 0;
        while ($i < $length) {
            $c = $s[$i];
            if ($c === '%' && $pctEncoded) {
                if ($i + 2 >= $length || !self::isHexDigit($s[$i + 1]) || !self::isHexDigit($s[$i + 2])) {
                    return false;
                }
                $i += 3;
                continue;
            }
            if (!str_contains($allowed, $c)) {
                return false;
            }
            $i++;
        }
        return true;
    }

    private static function isHexDigit(string $c): bool
    {
        return str_contains('0123456789ABCDEFabcdef', $c);
    }
}
