<?php

declare(strict_types=1);

namespace Raoh\Internal\Format;

/**
 * The ASCII lexical profile of RFC 5321's Mailbox (Dot-string "@" Domain)
 * that the {@code email} operation accepts.
 *
 * @internal
 */
final class Email
{
    private const LOCAL_PART = '/\A[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~]+(?:\.[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~]+)*\z/';
    private const LABEL = '/\A[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\z/';

    private function __construct()
    {
    }

    public static function matches(string $s): bool
    {
        if (strlen($s) > 254) {
            return false;
        }
        $parts = explode('@', $s);
        if (count($parts) !== 2) {
            return false;
        }
        [$local, $domain] = $parts;
        if (strlen($local) > 64 || preg_match(self::LOCAL_PART, $local) !== 1) {
            return false;
        }
        if ($domain === '' || strlen($domain) > 255) {
            return false;
        }
        foreach (explode('.', $domain) as $label) {
            if (strlen($label) > 63 || preg_match(self::LABEL, $label) !== 1) {
                return false;
            }
        }
        return true;
    }
}
