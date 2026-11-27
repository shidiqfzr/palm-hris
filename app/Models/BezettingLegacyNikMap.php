<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tautan satu arah (HMAC-SHA256) antara nomor induk lama PTPN XIII (14–15 digit, pada berkas Bezetting sebelum
 * Oktober 2022) dan NIK 8 digit karyawan pada aplikasi (DOMAIN.md §5.5, SECURITY.md §4.4). Nomor induk lama dalam
 * teks terang tidak pernah disimpan.
 */
class BezettingLegacyNikMap extends Model
{
    protected $fillable = [
        'legacy_nik_hash',
        'employee_nik',
        'source_run_id',
    ];

    /**
     * Normalizes a legacy PTPN XIII number (14–15 digits, with optional dots/spaces) to bare digits.
     * Returns null for 7- or 8-digit NIKs, alphabetic text, or empty strings.
     */
    public static function canonicalize(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || preg_match('/^[\d.\s]+$/', $trimmed) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $trimmed);

        return preg_match('/^\d{14,15}$/', $digits) === 1 ? $digits : null;
    }

    /** Keyed HMAC-SHA256 hash of a canonical 14–15 digit legacy PTPN XIII number. */
    public static function hash(string $canonical): string
    {
        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }

    /** Canonicalizes and hashes a raw cell value, or returns null when it is not a legacy PTPN XIII number. */
    public static function hashFromRaw(string $raw): ?string
    {
        $canonical = self::canonicalize($raw);

        return $canonical === null ? null : self::hash($canonical);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_nik', 'nik');
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(BezettingBackfillRun::class, 'source_run_id');
    }
}
