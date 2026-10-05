<?php

declare(strict_types=1);

namespace Raoh\Internal\Format;

/**
 * IPv4 and IPv6 addresses as RFC 3986 writes IPv4address and IPv6address.
 *
 * @internal
 */
final class Ip
{
    private const DEC_OCTET = '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])';

    private function __construct()
    {
    }

    /**
     * Four decimal numbers from 0 to 255 separated by ".", with no leading zero.
     */
    public static function isV4(string $s): bool
    {
        $o = self::DEC_OCTET;
        return preg_match("/\\A{$o}\\.{$o}\\.{$o}\\.{$o}\\z/", $s) === 1;
    }

    /**
     * An IPv6address, optionally followed by "%" and a non-empty zone ID without
     * "%" or U+0000, which is allowed only for link-local unicast (fe80::/10) and
     * multicast whose scope field is 1 to D.
     */
    public static function isV6(string $s): bool
    {
        $percent = strpos($s, '%');
        if ($percent === false) {
            return self::firstGroup($s) !== null;
        }
        $zone = substr($s, $percent + 1);
        if ($zone === '' || str_contains($zone, '%') || str_contains($zone, "\0")) {
            return false;
        }
        $first = self::firstGroup(substr($s, 0, $percent));
        if ($first === null) {
            return false;
        }
        if (($first & 0xffc0) === 0xfe80) {
            return true;
        }
        $scope = $first & 0x000f;
        return ($first & 0xff00) === 0xff00 && $scope >= 0x1 && $scope <= 0xd;
    }

    /**
     * What isV4 or isV6 accepts.
     */
    public static function isIp(string $s): bool
    {
        return self::isV4($s) || self::isV6($s);
    }

    /**
     * Whether the string is an IPv6address of RFC 3986, with no zone ID.
     */
    public static function isV6Address(string $s): bool
    {
        return self::firstGroup($s) !== null;
    }

    /**
     * Parses an IPv6address with no zone ID and returns the value of its first
     * 16-bit group, or null when the string is not one.
     */
    private static function firstGroup(string $s): ?int
    {
        $doubleColons = substr_count($s, '::');
        if ($doubleColons > 1) {
            return null;
        }
        if ($doubleColons === 1) {
            $at = (int) strpos($s, '::');
            $left = substr($s, 0, $at);
            $right = substr($s, $at + 2);
            $leftGroups = $left === '' ? [] : self::groups($left, false);
            $rightGroups = $right === '' ? [] : self::groups($right, true);
            if ($leftGroups === null || $rightGroups === null) {
                return null;
            }
            // "::" stands for one or more zero groups.
            if (count($leftGroups) + count($rightGroups) > 7) {
                return null;
            }
            return $leftGroups[0] ?? 0;
        }
        $all = self::groups($s, true);
        if ($all === null || count($all) !== 8) {
            return null;
        }
        return $all[0];
    }

    /**
     * Parses h16 *( ":" h16 ), where the last element may be an IPv4address
     * standing for two groups when $ipv4Tail is true.
     *
     * @return list<int>|null the 16-bit groups, or null when malformed
     */
    private static function groups(string $s, bool $ipv4Tail): ?array
    {
        $parts = explode(':', $s);
        $last = count($parts) - 1;
        $groups = [];
        foreach ($parts as $i => $part) {
            if ($i === $last && $ipv4Tail && str_contains($part, '.')) {
                if (!self::isV4($part)) {
                    return null;
                }
                $octets = array_map('intval', explode('.', $part));
                $groups[] = ($octets[0] << 8) | $octets[1];
                $groups[] = ($octets[2] << 8) | $octets[3];
                continue;
            }
            if (preg_match('/\A[0-9A-Fa-f]{1,4}\z/', $part) !== 1) {
                return null;
            }
            $groups[] = (int) hexdec($part);
        }
        return $groups;
    }
}
