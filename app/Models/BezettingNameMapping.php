<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keputusan admin SDM atas satu nama dari berkas Bezetting (DOMAIN.md §5.5 butir 5–6): dipetakan ke Master Formasi
 * atau status baku, disimpan apa adanya (di luar struktur saat ini), atau dilewati. Tidak memuat data pribadi.
 */
class BezettingNameMapping extends Model
{
    public const KIND_UNIT = 'unit';

    public const KIND_JABATAN = 'jabatan';

    public const KIND_STATUS = 'status';

    public const KINDS = [self::KIND_UNIT, self::KIND_JABATAN, self::KIND_STATUS];

    /** Dipetakan ke nama Master Formasi saat ini (unit, jabatan) atau status baku (status). */
    public const DECISION_MAP = 'map';

    /** Disimpan apa adanya dan ditandai di luar struktur saat ini; tidak ada unit atau jabatan yang dibuat. */
    public const DECISION_KEEP = 'keep';

    /** Baris dengan nama ini tidak ditulis dan dicantumkan pada laporan. */
    public const DECISION_SKIP = 'skip';

    public const DECISIONS = [self::DECISION_MAP, self::DECISION_KEEP, self::DECISION_SKIP];

    protected $fillable = [
        'kind',
        'source_text',
        'decision',
        'target_value',
        'note',
        'decided_by',
    ];

    /**
     * Kunci pencarian satu nama: huruf besar, spasi dirapatkan. Tanda baca dipertahankan karena dapat membedakan unit
     * (mis. "(K.SAWIT)" dan "(KARET)").
     */
    public static function normalize(string $text): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    public function setSourceTextAttribute(string $value): void
    {
        $this->attributes['source_text'] = self::normalize($value);
    }

    /** Keputusan tersimpan untuk satu nama, atau null bila belum diputuskan. */
    public static function decisionFor(string $kind, string $text): ?self
    {
        return self::query()->where('kind', $kind)->where('source_text', self::normalize($text))->first();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
