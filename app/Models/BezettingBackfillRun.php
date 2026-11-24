<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu proses rekonstruksi riwayat karir dari Bezetting bulanan (DOMAIN.md §5.5). Siklus: staged → pending_approval →
 * completed → rolled_back; pending_approval → rejected / expired; staged → expired. `payload` terenkripsi dan
 * dikosongkan setelah tidak diperlukan lagi (SECURITY.md §4.4).
 */
class BezettingBackfillRun extends Model
{
    public const STATUS_STAGED = 'staged';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LABELS = [
        self::STATUS_STAGED => 'Ditinjau',
        self::STATUS_PENDING => 'Menunggu Persetujuan',
        self::STATUS_REJECTED => 'Dikembalikan',
        self::STATUS_COMPLETED => 'Tersimpan',
        self::STATUS_ROLLED_BACK => 'Dibatalkan',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
    ];

    protected $fillable = [
        'status',
        'maker_id',
        'maker_name',
        'checker_id',
        'checker_name',
        'files',
        'period_from',
        'period_to',
        'payload',
        'plan_digest',
        'summary',
        'submitted_at',
        'decided_at',
        'decision_note',
        'committed_at',
        'rows_written',
        'rolled_back_at',
        'rolled_back_by',
        'rollback_reason',
        'payload_purged_at',
    ];

    protected $casts = [
        'files' => 'array',
        'payload' => 'encrypted:array',
        'summary' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'committed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'payload_purged_at' => 'datetime',
        'rows_written' => 'integer',
    ];

    /** Payload is never serialized to arrays or JSON (it holds NIK, names and birth dates). */
    protected $hidden = ['payload'];

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_id');
    }

    public function jobHistories(): HasMany
    {
        return $this->hasMany(JobHistory::class, 'backfill_run_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
