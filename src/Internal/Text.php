<?php

declare(strict_types=1);

namespace Raoh\Internal;

use Raoh\Notation199x\WhiteSpace;

/**
 * Text read by the Unicode White_Space property of Unicode 18.0.0, whatever version PHP was built
 * with. The text is valid UTF-8.
 *
 * @internal
 */
final class Text
{
    private function __construct()
    {
    }

    public static function trim(string $s): string
    {
        $start = 0;
        $end = strlen($s);
        while ($start < $end) {
            [$cp, $width] = self::at($s, $start);
            if (!WhiteSpace::contains($cp)) {
                break;
            }
            $start += $width;
        }
        while ($end > $start) {
            $lead = $end - 1;
            while ((ord($s[$lead]) & 0xC0) === 0x80) {
                $lead--;
            }
            [$cp] = self::at($s, $lead);
            if (!WhiteSpace::contains($cp)) {
                break;
            }
            $end = $lead;
        }
        return substr($s, $start, $end - $start);
    }

    public static function isBlank(string $s): bool
    {
        return self::trim($s) === '';
    }

    /**
     * The scalar value at a byte offset where a character begins, and its width in bytes.
     *
     * @return array{int, int}
     */
    private static function at(string $s, int $i): array
    {
        $b = ord($s[$i]);
        if ($b < 0x80) {
            return [$b, 1];
        }
        if ($b < 0xE0) {
            return [($b & 0x1F) << 6 | ord($s[$i + 1]) & 0x3F, 2];
        }
        if ($b < 0xF0) {
            return [($b & 0x0F) << 12 | (ord($s[$i + 1]) & 0x3F) << 6 | ord($s[$i + 2]) & 0x3F, 3];
        }
        return [
            ($b & 0x07) << 18 | (ord($s[$i + 1]) & 0x3F) << 12 | (ord($s[$i + 2]) & 0x3F) << 6
                | ord($s[$i + 3]) & 0x3F,
            4,
        ];
    }
}
