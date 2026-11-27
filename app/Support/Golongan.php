<?php

namespace App\Support;

/**
 * Golongan in the standard format [Gol]/[Berkala] with a two-digit step (DOMAIN.md §2.2), the pattern the employee
 * import validates. One place for every writer of history rows (manual correction and the Bezetting rebuild).
 */
final class Golongan
{
    /** "ivb / 3" → "IVB/03", "IVD" → "IVD"; anything that is not a golongan → null. */
    public static function standard(?string $text): ?string
    {
        $compact = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $text));
        if (preg_match('/^(I|II|III|IV)([A-D])(?:\/(\d{1,2}))?$/', $compact, $m) !== 1) {
            return null;
        }

        return $m[1].$m[2].(isset($m[3]) ? '/'.str_pad($m[3], 2, '0', STR_PAD_LEFT) : '');
    }
}
