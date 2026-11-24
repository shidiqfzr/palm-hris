<?php

namespace App\Services\Bezetting;

use App\Exceptions\ImportAbortedException;
use App\Models\BezettingBackfillRun;
use App\Models\BezettingNameMapping;
use App\Models\JobHistory;
use App\Models\MasterAuditLog;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use App\Support\HrisPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Governed lifecycle of a Bezetting backfill run (DOMAIN.md §5.5, SECURITY.md §4.4 and §5 item 1c):
 * stage → decide names → submit → approve by another admin (atomic commit) → undo within the undo window → purge.
 *
 * Every step requires `karyawan.import`. The second approver is always required, whatever `hris.import.require_approval`
 * says. Messages are safe to show to admins: they carry counts, never NIKs or names.
 */
class BezettingBackfillService
{
    public const CONFIRMATION_WORD = 'BATALKAN';

    private const MIN_REASON = 10;

    private const ALLOWED_EXTENSIONS = ['xls', 'xlsx'];

    public function __construct(private BezettingWorkbookReader $reader, private BackfillPlanner $planner) {}

    /**
     * Reads the uploaded workbooks, keeps only the minimized rows (encrypted on the run) and deletes each file.
     *
     * @param  list<array{path: string, name: string}>  $files
     */
    public function stage(array $files, User $maker): BezettingBackfillRun
    {
        $this->authorize($maker);

        try {
            $max = (int) config('hris.bezetting.max_files', 24);
            if ($files === [] || count($files) > $max) {
                throw new ImportAbortedException("Unggah 1 sampai {$max} berkas Bezetting dalam satu proses.");
            }

            $snapshots = [];
            $meta = [];
            foreach ($files as $file) {
                $this->assertFile($file);
                $read = $this->reader->read($file['path'], $file['name']);
                if (isset($snapshots[$read['period']])) {
                    throw new ImportAbortedException("Periode {$read['period']} terunggah lebih dari sekali.");
                }
                $snapshots[$read['period']] = ['period' => $read['period'], 'rows' => $read['rows'], 'issues' => $read['issues']];
                $meta[] = [
                    'name' => $file['name'],
                    'period' => $read['period'],
                    'sha256' => hash_file('sha256', $file['path']),
                    'rows' => count($read['rows']),
                    'issues' => count($read['issues']),
                    'warnings' => $read['warnings'],
                ];
            }
        } catch (BezettingReadException $e) {
            throw new ImportAbortedException($e->getMessage(), previous: $e);
        } finally {
            foreach ($files as $file) {
                if (is_file($file['path'])) {
                    @unlink($file['path']);
                }
            }
        }

        ksort($snapshots);
        usort($meta, fn (array $a, array $b) => strcmp($a['period'], $b['period']));

        return DB::transaction(function () use ($snapshots, $meta, $maker): BezettingBackfillRun {
            $run = BezettingBackfillRun::create([
                'status' => BezettingBackfillRun::STATUS_STAGED,
                'maker_id' => $maker->id,
                'maker_name' => $maker->name,
                'files' => $meta,
                'period_from' => array_key_first($snapshots),
                'period_to' => array_key_last($snapshots),
                'payload' => ['snapshots' => array_values($snapshots)],
            ]);
            $this->audit($maker, 'bezetting.staged', $run, null, ['berkas' => count($meta), 'periode' => "{$run->period_from} s.d. {$run->period_to}"]);

            return $run;
        });
    }

    /** The current plan of a run. Never persisted: it holds NIKs. */
    public function plan(BezettingBackfillRun $run): array
    {
        if ($run->payload === null) {
            throw new ImportAbortedException('Data berkas proses ini sudah dihapus. Unggah ulang berkas Bezetting.');
        }

        return $this->planner->plan($run->payload['snapshots']);
    }

    /**
     * Saves one HR decision on a name, recorded on master_audit_logs. A mapping target must already exist in the master
     * (unit, position) or be an official status; nothing is created in the master.
     */
    public function decide(string $kind, string $text, string $decision, ?string $target, User $user, ?string $note = null): BezettingNameMapping
    {
        $this->authorize($user);

        if (! in_array($kind, BezettingNameMapping::KINDS, true) || ! in_array($decision, BezettingNameMapping::DECISIONS, true)) {
            throw new ImportAbortedException('Jenis keputusan tidak dikenal.');
        }
        if ($kind === BezettingNameMapping::KIND_STATUS && $decision !== BezettingNameMapping::DECISION_MAP) {
            throw new ImportAbortedException('Frasa status hanya dapat dipetakan ke status penugasan resmi.');
        }

        $target = $decision === BezettingNameMapping::DECISION_MAP ? $this->assertTarget($kind, (string) $target) : null;

        return DB::transaction(function () use ($kind, $text, $decision, $target, $user, $note): BezettingNameMapping {
            $mapping = BezettingNameMapping::query()->firstOrNew(['kind' => $kind, 'source_text' => BezettingNameMapping::normalize($text)]);
            $old = $mapping->exists ? ['keputusan' => $mapping->decision, 'tujuan' => $mapping->target_value] : null;
            $mapping->fill(['decision' => $decision, 'target_value' => $target, 'note' => $note, 'decided_by' => $user->id])->save();

            MasterAuditLog::record($user, 'bezetting.mapping_decided', 'bezetting_mapping', $mapping->id,
                "{$kind}: {$mapping->source_text}", $old, ['keputusan' => $decision, 'tujuan' => $target]);

            return $mapping;
        });
    }

    /** The maker submits a run whose plan has no undecided names and at least one row. */
    public function submit(BezettingBackfillRun $run, User $maker): BezettingBackfillRun
    {
        $this->authorize($maker);

        return DB::transaction(function () use ($run, $maker): BezettingBackfillRun {
            $locked = BezettingBackfillRun::lockForUpdate()->findOrFail($run->id);
            $this->assertStatus($locked, [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED]);
            if ($locked->maker_id !== $maker->id) {
                throw new ImportAbortedException('Hanya pengaju proses ini yang dapat mengajukannya.');
            }

            $plan = $this->plan($locked);
            if ($plan['blocking'] !== []) {
                throw new ImportAbortedException(count($plan['blocking']).' nama unit, jabatan, atau status belum diputuskan.');
            }
            if ($plan['rows'] === []) {
                throw new ImportAbortedException('Tidak ada riwayat yang dapat direkonstruksi dari berkas ini.');
            }

            $locked->update([
                'status' => BezettingBackfillRun::STATUS_PENDING,
                'plan_digest' => $plan['digest'],
                'summary' => $plan['summary'],
                'submitted_at' => now(),
                'checker_id' => null,
                'checker_name' => null,
                'decided_at' => null,
                'decision_note' => null,
            ]);
            $this->audit($maker, 'bezetting.submitted', $locked, null, $plan['summary']);

            return $locked;
        });
    }

    /**
     * Another admin approves: the plan is rebuilt inside the transaction and must match what was submitted, then every
     * row is written in one transaction.
     */
    public function approve(BezettingBackfillRun $run, User $checker): BezettingBackfillRun
    {
        $this->authorize($checker);

        return DB::transaction(function () use ($run, $checker): BezettingBackfillRun {
            $locked = BezettingBackfillRun::lockForUpdate()->findOrFail($run->id);
            $this->assertStatus($locked, [BezettingBackfillRun::STATUS_PENDING]);
            if ($locked->maker_id === $checker->id) {
                throw new ImportAbortedException('Persetujuan harus dilakukan admin lain, bukan pengaju.');
            }
            if ($locked->submitted_at->lt(now()->subDays((int) config('hris.import.approval_ttl_days', 7)))) {
                throw new ImportAbortedException('Pengajuan ini sudah kedaluwarsa. Ajukan ulang.');
            }

            $plan = $this->plan($locked);
            if (! hash_equals((string) $locked->plan_digest, $plan['digest'])) {
                throw new ImportAbortedException('Data atau keputusan nama berubah sejak diajukan. Pengaju perlu mengajukan ulang.');
            }

            $now = now();
            $base = ['backfill_run_id' => $locked->id, 'created_by' => $locked->maker_id, 'created_at' => $now, 'updated_at' => $now];
            foreach (array_chunk($plan['rows'], 200) as $chunk) {
                JobHistory::query()->insert(array_map(fn (array $row) => array_merge($row, $base), $chunk));
            }

            $locked->update([
                'status' => BezettingBackfillRun::STATUS_COMPLETED,
                'checker_id' => $checker->id,
                'checker_name' => $checker->name,
                'decided_at' => $now,
                'committed_at' => $now,
                'rows_written' => count($plan['rows']),
                'summary' => $plan['summary'],
            ]);
            $this->audit($checker, 'bezetting.committed', $locked, null, ['baris_riwayat' => count($plan['rows']), 'karyawan' => $plan['summary']['karyawan']]);

            return $locked;
        });
    }

    /** Another admin returns the run to the maker with a reason. */
    public function reject(BezettingBackfillRun $run, User $checker, string $reason): BezettingBackfillRun
    {
        $this->authorize($checker);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($run, $checker, $reason): BezettingBackfillRun {
            $locked = BezettingBackfillRun::lockForUpdate()->findOrFail($run->id);
            $this->assertStatus($locked, [BezettingBackfillRun::STATUS_PENDING]);
            if ($locked->maker_id === $checker->id) {
                throw new ImportAbortedException('Pengembalian harus dilakukan admin lain, bukan pengaju.');
            }

            $locked->update([
                'status' => BezettingBackfillRun::STATUS_REJECTED,
                'checker_id' => $checker->id,
                'checker_name' => $checker->name,
                'decided_at' => now(),
                'decision_note' => $reason,
            ]);
            $this->audit($checker, 'bezetting.rejected', $locked, null, null, $reason);

            return $locked;
        });
    }

    /**
     * Undoes a completed run within the undo window: soft delete with a reason, never a hard delete. Refused when any of
     * its rows changed after the commit.
     */
    public function rollback(BezettingBackfillRun $run, User $actor, string $reason, ?string $confirmation = null): BezettingBackfillRun
    {
        $this->authorize($actor);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($run, $actor, $reason, $confirmation): BezettingBackfillRun {
            $locked = BezettingBackfillRun::lockForUpdate()->findOrFail($run->id);
            $this->assertStatus($locked, [BezettingBackfillRun::STATUS_COMPLETED]);
            if ($locked->committed_at->lt(now()->subDays((int) config('hris.import.undo_window_days', 7)))) {
                throw new ImportAbortedException('Masa pembatalan proses ini sudah berakhir.');
            }
            if ($locked->rows_written >= (int) config('hris.import.typed_confirmation_threshold', 50)
                && strcasecmp(trim((string) $confirmation), self::CONFIRMATION_WORD) !== 0) {
                throw new ImportAbortedException("Pembatalan ini menyentuh {$locked->rows_written} baris riwayat. Ketik kata ".self::CONFIRMATION_WORD.' untuk mengonfirmasi.');
            }

            $rows = JobHistory::query()->where('backfill_run_id', $locked->id);
            if ((clone $rows)->where('updated_at', '>', $locked->committed_at)->exists()) {
                throw new ImportAbortedException('Sebagian riwayat dari proses ini sudah berubah sesudah disimpan, sehingga tidak dapat dibatalkan.');
            }

            $count = (clone $rows)->update([
                'cancelled_by' => $actor->id,
                'alasan_pembatalan' => mb_substr('Pembatalan rekonstruksi Bezetting: '.$reason, 0, 255),
                'deleted_at' => now(),
            ]);

            $locked->update([
                'status' => BezettingBackfillRun::STATUS_ROLLED_BACK,
                'rolled_back_at' => now(),
                'rolled_back_by' => $actor->id,
                'rollback_reason' => $reason,
            ]);
            $this->audit($actor, 'bezetting.rolled_back', $locked, null, ['baris_riwayat' => $count], $reason);

            return $locked;
        });
    }

    /**
     * Expires stale submissions and clears staged data no longer needed (SECURITY.md §4.4). Returns counts.
     *
     * @return array{expired: int, purged: int}
     */
    public function purge(): array
    {
        $expired = 0;
        $ttl = now()->subDays((int) config('hris.import.approval_ttl_days', 7));
        BezettingBackfillRun::query()->where('status', BezettingBackfillRun::STATUS_PENDING)->where('submitted_at', '<', $ttl)
            ->get()->each(function (BezettingBackfillRun $run) use (&$expired): void {
                $run->update(['status' => BezettingBackfillRun::STATUS_EXPIRED]);
                $this->audit(null, 'bezetting.expired', $run, null, null);
                $expired++;
            });

        $undoEnd = now()->subDays((int) config('hris.import.undo_window_days', 7));
        $stagingEnd = now()->subDays((int) config('hris.bezetting.staging_retention_days', 30));
        $purged = BezettingBackfillRun::query()
            ->whereNull('payload_purged_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('status', [BezettingBackfillRun::STATUS_COMPLETED, BezettingBackfillRun::STATUS_ROLLED_BACK])
                    ->where('committed_at', '<', $undoEnd))
                ->orWhere(fn ($q) => $q->whereNotIn('status', [BezettingBackfillRun::STATUS_COMPLETED, BezettingBackfillRun::STATUS_ROLLED_BACK])
                    ->where('created_at', '<', $stagingEnd)))
            ->update(['payload' => null, 'payload_purged_at' => now()]);

        return ['expired' => $expired, 'purged' => $purged];
    }

    private function authorize(User $user): void
    {
        if (! Gate::forUser($user)->allows(HrisPermission::KARYAWAN_IMPORT)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk rekonstruksi riwayat karir.');
        }
    }

    /** @param array{path: string, name: string} $file */
    private function assertFile(array $file): void
    {
        $extension = mb_strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ImportAbortedException("Berkas {$file['name']} bukan berkas Excel (.xls atau .xlsx).");
        }
        if (! is_file($file['path']) || filesize($file['path']) > (int) config('hris.bezetting.max_file_kb', 10240) * 1024) {
            throw new ImportAbortedException("Berkas {$file['name']} tidak ada atau melebihi batas ukuran.");
        }
    }

    private function assertTarget(string $kind, string $target): string
    {
        $target = trim($target);
        $exists = match ($kind) {
            BezettingNameMapping::KIND_UNIT => Unit::query()->where('nama', $target)->exists(),
            BezettingNameMapping::KIND_JABATAN => Position::query()->where('nama', $target)->exists(),
            default => in_array($target, BackfillPlanner::statuses(), true),
        };
        if (! $exists) {
            throw new ImportAbortedException('Nama tujuan tidak ada pada Master Formasi atau daftar status resmi.');
        }

        return $target;
    }

    private function assertStatus(BezettingBackfillRun $run, array $allowed): void
    {
        if (! in_array($run->status, $allowed, true)) {
            throw new ImportAbortedException("Proses ini berstatus {$run->status_label}, sehingga tindakan ini tidak dapat dilakukan.");
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw new ImportAbortedException('Alasan wajib diisi, minimal '.self::MIN_REASON.' karakter.');
        }

        return mb_substr($reason, 0, 500);
    }

    private function audit(?User $user, string $action, BezettingBackfillRun $run, ?array $old, ?array $new, ?string $reason = null): void
    {
        MasterAuditLog::record($user, $action, 'bezetting_run', $run->id, "Rekonstruksi Bezetting {$run->period_from} s.d. {$run->period_to}", $old, $new, $reason);
    }
}
