<?php

namespace App\Services\Bezetting;

use App\Exceptions\ImportAbortedException;
use App\Models\BezettingBackfillRun;
use App\Models\BezettingLegacyNikMap;
use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\MasterAuditLog;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use App\Services\EmployeeDeletionService;
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

    /** Follow-up types and their business labels: one source for the recap screen, its filter, and the HR download. */
    public const FOLLOWUP_LABELS = [
        'perpindahan' => 'Perubahan perlu diperiksa',
        'celah' => 'Celah bulan',
        'keluar' => 'Keluar dari daftar',
    ];

    /**
     * What each follow-up means, what HR does, and how its month reads on a row ("%s" is the month or period). These are
     * people who ARE processed; something the files show is deliberately not written by the rebuild. None blocks submitting.
     */
    public const FOLLOWUP_HELP = [
        'perpindahan' => [
            'arti' => 'Berkas menunjukkan jabatan atau unit karyawan berubah sesudah riwayat pertamanya di aplikasi, dan aplikasi belum mencatat perubahan itu pada tanggal yang sama. Penyebabnya dapat berupa tanggal mulai di aplikasi yang terlalu lama, penulisan unit atau jabatan yang berbeda di berkas, atau perpindahan yang memang belum dicatat.',
            'tindakan' => 'Lihat jabatan dan unit "Dari" dan "Ke" pada daftar, lalu bandingkan dengan Riwayat Karir. Bila aplikasi sudah mencatat jabatan itu, periksa tanggal mulainya (Ubah Riwayat). Bila hanya beda penulisan, petakan namanya. Bila perpindahan itu benar-benar belum tercatat, catat melalui modul Mutasi beserta SK-nya; rekonstruksi tidak menuliskannya.',
            'detail' => 'Berubah mulai %s',
        ],
        'celah' => [
            'arti' => 'Karyawan tidak tercantum pada satu bulan atau lebih lalu tercantum lagi. Rekonstruksi menganggapnya satu karier yang sama, bukan dua.',
            'tindakan' => 'SDM memeriksa apakah ketidakhadiran itu memang terjadi (misalnya cuti atau penugasan) atau kekeliruan berkas.',
            'detail' => 'Tidak tercantum %s',
        ],
        'keluar' => [
            'arti' => 'Karyawan tercantum pada bulan-bulan awal, tetapi tidak ada pada berkas bulan terakhir.',
            'tindakan' => 'SDM memeriksa sebabnya (pensiun, berhenti, atau pindah dari daftar Karyawan Pimpinan). Pemberhentian dicatat lewat fitur pemberhentian, bukan oleh rekonstruksi.',
            'detail' => 'Terakhir tercantum %s',
        ],
    ];

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

            // Persist conflict-free hashed legacy PTPN XIII → 8-digit NIK links from dual-ID rows for older runs (2012–Sep 2022).
            $legacyPairs = $this->planner->identitySafeLegacyPairs($locked->payload['snapshots'] ?? [], $plan['excluded']);
            if ($legacyPairs !== []) {
                $existingHashes = BezettingLegacyNikMap::query()->whereIn('legacy_nik_hash', array_keys($legacyPairs))->pluck('legacy_nik_hash')->flip();
                $toInsert = [];
                foreach ($legacyPairs as $hash => $nik) {
                    if (! $existingHashes->has($hash)) {
                        $toInsert[] = [
                            'legacy_nik_hash' => $hash,
                            'employee_nik' => $nik,
                            'source_run_id' => $locked->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                foreach (array_chunk($toInsert, 200) as $chunk) {
                    BezettingLegacyNikMap::query()->insert($chunk);
                }
            }

            $locked->update([
                'status' => BezettingBackfillRun::STATUS_COMPLETED,
                'checker_id' => $checker->id,
                'checker_name' => $checker->name,
                'decided_at' => $now,
                'committed_at' => $now,
                'rows_written' => count($plan['rows']),
                'summary' => $plan['summary'],
                // Captured now: once the history is written the plan can no longer show what still needs follow-up.
                'followups' => self::followupsFrom($plan),
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
     * The maker closes a run that is still with them (nothing written yet), for example one with no history to write.
     * The read data is cleared at once (data minimisation, SECURITY.md §4.4); the run stays listed for the audit trail.
     */
    public function withdraw(BezettingBackfillRun $run, User $maker, string $reason): BezettingBackfillRun
    {
        $this->authorize($maker);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($run, $maker, $reason): BezettingBackfillRun {
            $locked = BezettingBackfillRun::lockForUpdate()->findOrFail($run->id);
            $this->assertStatus($locked, [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED]);
            if ($locked->maker_id !== $maker->id) {
                throw new ImportAbortedException('Hanya pengaju proses ini yang dapat menutupnya.');
            }

            $locked->update([
                'status' => BezettingBackfillRun::STATUS_WITHDRAWN,
                'decided_at' => now(),
                'decision_note' => $reason,
                'payload' => null,
                'payload_purged_at' => now(),
            ]);
            $this->audit($maker, 'bezetting.withdrawn', $locked, null, null, $reason);

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

            $olderCompleted = BezettingBackfillRun::query()
                ->where('status', BezettingBackfillRun::STATUS_COMPLETED)
                ->where('period_from', '<', $locked->period_from)
                ->orderBy('period_from')
                ->first();
            if ($olderCompleted !== null) {
                throw new ImportAbortedException("Batalkan proses periode lebih lama lebih dulu ({$olderCompleted->period_from} s.d. {$olderCompleted->period_to}) sebelum membatalkan proses ini.");
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

            BezettingLegacyNikMap::query()->where('source_run_id', $locked->id)->delete();

            $locked->update([
                'status' => BezettingBackfillRun::STATUS_ROLLED_BACK,
                'rolled_back_at' => now(),
                'rolled_back_by' => $actor->id,
                'rollback_reason' => $reason,
                // The undone run no longer asks for follow-up; a new run rebuilds the list (data minimisation).
                'followups' => null,
                'followups_purged_at' => now(),
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

        // Runs never submitted (or returned and never resubmitted) within the retention period expire, so no run stays
        // "Ditinjau" without the data needed to review it.
        $undoEnd = now()->subDays((int) config('hris.import.undo_window_days', 7));
        $stagingEnd = now()->subDays((int) config('hris.bezetting.staging_retention_days', 30));
        BezettingBackfillRun::query()
            ->whereIn('status', [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED])
            ->where('created_at', '<', $stagingEnd)
            ->get()->each(function (BezettingBackfillRun $run) use (&$expired): void {
                $run->update(['status' => BezettingBackfillRun::STATUS_EXPIRED]);
                $this->audit(null, 'bezetting.expired', $run, null, null);
                $expired++;
            });

        $purged = BezettingBackfillRun::query()
            ->whereNull('payload_purged_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('status', [BezettingBackfillRun::STATUS_COMPLETED, BezettingBackfillRun::STATUS_ROLLED_BACK])
                    ->where('committed_at', '<', $undoEnd))
                ->orWhere(fn ($q) => $q->whereNotIn('status', [BezettingBackfillRun::STATUS_COMPLETED, BezettingBackfillRun::STATUS_ROLLED_BACK])
                    ->where('created_at', '<', $stagingEnd)))
            ->update(['payload' => null, 'payload_purged_at' => now()]);

        // The follow-up worklist (NIK and month) of a saved run is kept for the Mutasi follow-up, then cleared.
        $followupsPurged = BezettingBackfillRun::query()
            ->whereNull('followups_purged_at')->whereNotNull('followups')
            ->where('committed_at', '<', now()->subDays((int) config('hris.bezetting.followup_retention_days', 90)))
            ->update(['followups' => null, 'followups_purged_at' => now()]);

        return ['expired' => $expired, 'purged' => $purged, 'followups_purged' => $followupsPurged];
    }

    /**
     * Per-employee recap of a run, for the review before approval and the reconciliation afterwards (DOMAIN.md §5.5
     * rule 9): what the plan will write (open run), or what was written (saved or undone run), plus the follow-up worklist.
     * Rebuilt on every call and never persisted: it holds NIKs, which the screen masks.
     *
     * Each history row says what is written and why: position and unit (with the file text), the Bezetting month it
     * comes from, where the start date comes from (the file's date, or an estimate), and why it ends (the day before the
     * employee's first row in the application, or the last month seen).
     *
     * @return array{source: 'plan'|'saved'|null, rows: list<array{nik: string, nama: string, jabatan: string, unit: string, status: string, dari: string, sampai: string,
     *     perkiraan: bool, perubahan: ?string, sumber: string, teks_sumber: string, selesai_karena: 'aplikasi'|'berkas', aplikasi_mulai: ?string}>,
     *     karyawan: int, belum_diputuskan: int, followups: list<array{jenis: string, nik: string, nama: string, periode: string}>, followups_available: bool,
     *     excluded: list<array{nik: string, nama: string, reason: string, alasan: string, periode: ?string}>}
     */
    public function recap(BezettingBackfillRun $run): array
    {
        $saved = in_array($run->status, [BezettingBackfillRun::STATUS_COMPLETED, BezettingBackfillRun::STATUS_ROLLED_BACK], true);
        $undecided = 0;
        $excluded = [];
        if ($saved) {
            $rows = JobHistory::withTrashed()->where('backfill_run_id', $run->id)->orderBy('tmt_awal')
                ->get(['employee_nik', 'jabatan', 'unit_kerja', 'status_penugasan', 'tmt_awal', 'tmt_akhir', 'tmt_awal_diperkirakan', 'jenis_perubahan_terdeteksi', 'teks_sumber', 'catatan'])
                ->map(fn (JobHistory $h) => ['tmt_awal' => $h->tmt_awal->toDateString(), 'tmt_akhir' => $h->tmt_akhir?->toDateString()] + $h->only(
                    'employee_nik', 'jabatan', 'unit_kerja', 'status_penugasan', 'tmt_awal_diperkirakan', 'jenis_perubahan_terdeteksi', 'teks_sumber', 'catatan'))
                ->all();
            $followups = $run->followups;
        } else {
            $plan = $run->payload === null ? null : $this->plan($run);
            if ($plan === null) {
                return ['source' => null, 'rows' => [], 'karyawan' => 0, 'belum_diputuskan' => 0, 'followups' => [], 'followups_available' => false, 'excluded' => []];
            }
            $rows = $plan['rows'];
            $undecided = count($plan['blocking']);
            $followups = self::followupsFrom($plan);
            // People the plan does not process, with the reason (the plan is the only place that knows them; a saved run keeps none).
            // The name is the one written in the file (these people may not be in the application), shown beside the masked NIK so HR can verify.
            $fileNames = [];
            foreach ($run->payload['snapshots'] as $snapshot) {
                foreach ($snapshot['rows'] as $row) {
                    $fileNames[(string) $row['nik']] ??= trim((string) ($row['nama'] ?? ''));
                }
            }
            $excluded = collect($plan['excluded'])->map(fn (array $item, $nik) => ['nik' => (string) $nik, 'nama' => $fileNames[(string) $nik] ?? '', 'reason' => $item['reason'],
                'alasan' => BackfillPlanner::EXCLUSION_LABELS[$item['reason']] ?? $item['reason'], 'periode' => $item['periode'] ?? null])->values()->all();
        }

        $niks = array_values(array_unique([...array_column($rows, 'employee_nik'), ...array_column(self::flattenFollowups($followups ?? []), 'nik')]));
        $names = Employee::query()->whereIn('nik', $niks)->pluck('nama', 'nik')->map(fn ($n) => (string) $n)->all();
        // Start of each employee's own history in the application (not Bezetting rows): a rebuilt row ends the day before.
        $firstOwn = JobHistory::query()->applied()->where('sumber', '!=', JobHistory::SUMBER_BEZETTING)
            ->whereIn('employee_nik', array_column($rows, 'employee_nik'))
            ->selectRaw('employee_nik, min(tmt_awal) as mulai')->groupBy('employee_nik')
            ->pluck('mulai', 'employee_nik')->map(fn ($date) => substr((string) $date, 0, 10))->all();

        $items = array_map(function (array $row) use ($names, $firstOwn): array {
            $nik = (string) $row['employee_nik'];
            $mulaiAplikasi = $firstOwn[$nik] ?? null;
            $dayAfter = $row['tmt_akhir'] ? date('Y-m-d', strtotime($row['tmt_akhir'].' +1 day')) : null;

            return [
                'nik' => $nik,
                'nama' => $names[$nik] ?? '',
                'jabatan' => (string) $row['jabatan'],
                'unit' => (string) $row['unit_kerja'],
                'status' => (string) ($row['status_penugasan'] ?? ''),
                'dari' => (string) $row['tmt_awal'],
                'sampai' => (string) $row['tmt_akhir'],
                'perkiraan' => (bool) $row['tmt_awal_diperkirakan'],
                'perubahan' => $row['jenis_perubahan_terdeteksi'] ? (JobHistory::JENIS_PERUBAHAN_LABELS[$row['jenis_perubahan_terdeteksi']] ?? $row['jenis_perubahan_terdeteksi']) : null,
                'sumber' => trim(str_replace(['Direkonstruksi dari ', '(bukan SK mutasi)'], '', (string) $row['catatan'])),
                'sumber_singkat' => JobHistory::berkasLabel((string) $row['catatan']) ?? 'Bezetting',
                'teks_sumber' => (string) $row['teks_sumber'],
                'teks_sumber_baris' => (string) JobHistory::teksBerkasBaris((string) $row['teks_sumber'], $row['jenis_perubahan_terdeteksi'] ?? null),
                'selesai_karena' => $mulaiAplikasi !== null && $dayAfter === $mulaiAplikasi ? 'aplikasi' : 'berkas',
                'aplikasi_mulai' => $mulaiAplikasi,
            ];
        }, $rows);
        usort($items, fn (array $a, array $b) => [$a['nama'], $a['dari']] <=> [$b['nama'], $b['dari']]);

        return [
            'source' => $saved ? 'saved' : 'plan',
            'rows' => $items,
            'karyawan' => count(array_unique(array_column($items, 'nik'))),
            'belum_diputuskan' => $undecided,
            'followups' => array_map(fn (array $item) => $item + ['nama' => $names[$item['nik']] ?? ''], self::flattenFollowups($followups ?? [])),
            'followups_available' => $followups !== null,
            'excluded' => $excluded,
        ];
    }

    /**
     * NIK and month only, from a plan: what HR still has to follow up outside the rebuild.
     *
     * @return array{perpindahan_belum_tercatat: list<array{nik: string, periode: string}>, celah: list<array{nik: string, dari: string, sampai: string}>, keluar_daftar: list<array{nik: string, terakhir_terlihat: string}>}
     */
    public static function followupsFrom(array $plan): array
    {
        return [
            'perpindahan_belum_tercatat' => array_values($plan['info']['perpindahan_belum_tercatat'] ?? []),
            'celah' => array_values($plan['info']['celah'] ?? []),
            'keluar_daftar' => array_values($plan['info']['keluar_daftar'] ?? []),
        ];
    }

    /**
     * One list with a business label and a period text per item.
     *
     * @return list<array{jenis: string, nik: string, periode: string}>
     */
    private static function flattenFollowups(array $followups): array
    {
        $items = [];
        foreach ($followups['perpindahan_belum_tercatat'] ?? [] as $item) {
            $items[] = ['jenis' => 'perpindahan', 'nik' => (string) $item['nik'], 'periode' => (string) $item['periode'], 'nama_disimpan' => (bool) ($item['nama_disimpan'] ?? false),
                'dari' => (string) ($item['dari'] ?? ''), 'ke' => (string) ($item['ke'] ?? ''), 'aplikasi_sudah' => (bool) ($item['aplikasi_sudah'] ?? false)];
        }
        foreach ($followups['celah'] ?? [] as $item) {
            $items[] = ['jenis' => 'celah', 'nik' => (string) $item['nik'], 'periode' => $item['dari'].' s.d. '.$item['sampai']];
        }
        foreach ($followups['keluar_daftar'] ?? [] as $item) {
            $items[] = ['jenis' => 'keluar', 'nik' => (string) $item['nik'], 'periode' => (string) $item['terakhir_terlihat']];
        }

        return $items;
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
        // Same limits as every other mandatory reason (App\Support\ReasonRule).
        if (mb_strlen($reason) < EmployeeDeletionService::REASON_MIN_LENGTH) {
            throw new ImportAbortedException('Alasan wajib diisi, minimal '.EmployeeDeletionService::REASON_MIN_LENGTH.' karakter.');
        }

        return mb_substr($reason, 0, EmployeeDeletionService::REASON_MAX_LENGTH);
    }

    private function audit(?User $user, string $action, BezettingBackfillRun $run, ?array $old, ?array $new, ?string $reason = null): void
    {
        MasterAuditLog::record($user, $action, 'bezetting_run', $run->id, "Rekonstruksi Bezetting {$run->period_from} s.d. {$run->period_to}", $old, $new, $reason);
    }
}
