<?php

namespace App\Services;

use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\MasterAuditLog;
use App\Models\Position;
use App\Models\StructureDecree;
use App\Models\Unit;
use App\Models\User;
use App\Services\Mutasi\FormationService;
use App\Services\Structure\StructureAsOfService;
use App\Services\Structure\StructureReader;
use App\Support\Golongan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual correction of an employee's career history (Riwayat Karir): add, correct, or remove a CLOSED history row.
 *
 * - Moving an employee is never done here (AGENTS.md rule 8): a row that is still running (no end date, or the current
 *   position) keeps its position, unit and dates; only its note can change. A new current position comes from Mutasi.
 * - Position and unit are master data, and the position must belong to the unit's formasi on the start date (the
 *   structure SK then in force, or the current Master Formasi before the first SK). A name outside the master is a
 *   controlled exception, only before the first structure SK, recorded in the shared old-name dictionary.
 * - Rows are closed (end date required, not in the future) and a definitive row never overlaps another definitive row.
 * - Golongan is stored in the standard [Gol]/[Berkala] format; the level (Karpim/Karpel) follows the RM Band of the
 *   position in the Master Formasi and is chosen by hand only for old names outside the master (DOMAIN.md §4.1).
 * - Record-only rows never receive a new SK; Bezetting rows have no mutation type (DOMAIN.md §2.2, §5.5).
 * - Every add, change and removal needs a reason and is written to master_audit_logs in the same transaction, without
 *   NIK or name (SECURITY.md §5 item 6b pattern); removal is a soft delete that keeps who and why.
 */
class JobHistoryCorrectionService
{
    public const LEVELS = ['Karpim', 'Karpel'];

    /** Values recorded on the audit log; the free-text note is recorded by name only. */
    private const AUDITED_VALUES = ['jabatan', 'unit_kerja', 'level', 'golongan', 'tmt_awal', 'tmt_akhir', 'jenis_mutasi', 'nomor_sk', 'tanggal_sk'];

    private const POSITION_FIELDS = ['jabatan', 'unit_kerja', 'level', 'golongan', 'tmt_awal', 'tmt_akhir', 'jenis_mutasi', 'nomor_sk', 'tanggal_sk'];

    /** @var list<array{0: string, 1: string}> old names this correction keeps as written (kind, text), recorded on save */
    private array $keptNames = [];

    public function __construct(
        private FormationService $formations,
        private StructureAsOfService $asOf,
        private StructureReader $reader,
    ) {}

    public function isRunning(Employee $employee, JobHistory $row): bool
    {
        return $row->tmt_akhir === null || (bool) $employee->currentJob?->is($row);
    }

    /** RM Band of the position per the Master Formasi, or null for a name outside the master (never guessed). */
    public function rmBand(string $jabatan): ?string
    {
        return $this->formations->masterPositionRm($jabatan);
    }

    /** @param array<string, mixed> $data validated input */
    public function create(Employee $employee, array $data, User $actor, string $reason): JobHistory
    {
        if (empty($data['tmt_akhir'])) {
            throw ValidationException::withMessages(['tmt_akhir' => 'Isi TMT Akhir. Riwayat yang ditambahkan di sini adalah jabatan yang sudah selesai; jabatan yang sedang berjalan dicatat melalui modul Mutasi.']);
        }
        $values = $this->normalise($data);
        $this->assertClosedPeriod($employee, $values, null);

        // An official mutation needs its type and its SK (number and date) from the document; anything else is a data
        // update record, never labelled as an official mutation (SECURITY.md §5 item 1).
        $official = ($values['jenis_mutasi'] ?? null) !== null || ($values['nomor_sk'] ?? null) !== null || ($values['tanggal_sk'] ?? null) !== null;
        if ($official) {
            $missing = array_filter(['jenis_mutasi' => 'Pilih jenis mutasi sesuai SK.', 'nomor_sk' => 'Isi nomor SK sesuai dokumen.', 'tanggal_sk' => 'Isi tanggal SK sesuai dokumen.'],
                fn ($message, $field) => ($values[$field] ?? null) === null, ARRAY_FILTER_USE_BOTH);
            if ($missing !== []) {
                throw ValidationException::withMessages(array_map(fn ($m) => $m.' Riwayat mutasi resmi wajib memuat jenis mutasi, nomor SK, dan tanggal SK; kosongkan ketiganya bila riwayat ini bukan dari SK.', $missing));
            }
        }

        return DB::transaction(function () use ($employee, $values, $official, $actor, $reason): JobHistory {
            $row = JobHistory::create($values + [
                'sumber' => $official ? JobHistory::SUMBER_MUTASI : JobHistory::SUMBER_PEMBARUAN_DATA,
                'employee_nik' => $employee->nik,
                'status_penugasan' => Employee::STATUS_DEFINITIF,
                'is_applied' => true,
                'created_by' => $actor->id,
            ]);
            MasterAuditLog::record($actor, 'job_history.created', 'job_history', $row->id, $this->label($row), null, $this->audited($values), $reason);
            $this->recordKeptNames($actor, $reason);

            return $row;
        });
    }

    /** @param array<string, mixed> $data validated input */
    public function update(Employee $employee, JobHistory $row, array $data, User $actor, string $reason): JobHistory
    {
        if ($this->isRunning($employee, $row)) {
            // Only the note of a running row changes here; position, unit and dates change through Mutasi.
            $values = ['catatan' => $data['catatan'] ?? null];
        } else {
            if (empty($data['tmt_akhir'])) {
                throw ValidationException::withMessages(['tmt_akhir' => 'Riwayat yang sudah selesai tetap wajib memiliki TMT Akhir. Jabatan yang sedang berjalan hanya berubah melalui modul Mutasi.']);
            }
            $this->assertRecordOnlyRules($row, $data);
            $values = $this->normalise($data, $row);
            $this->assertClosedPeriod($employee, $values, $row);
        }

        $before = $row->only(array_keys($values));
        $changed = array_keys(array_filter($values, fn ($value, $key) => (string) $this->plain($before[$key] ?? null) !== (string) $this->plain($value), ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return $row;
        }

        return DB::transaction(function () use ($row, $values, $before, $changed, $actor, $reason): JobHistory {
            // HR set the start date from a document: it is no longer the estimate the Bezetting rebuild made.
            if (in_array('tmt_awal', $changed, true) && $row->tmt_awal_diperkirakan) {
                $values['tmt_awal_diperkirakan'] = false;
            }
            $row->update($values);
            $old = $this->audited(array_intersect_key($before, array_flip($changed)));
            $new = $this->audited(array_intersect_key($values, array_flip($changed)));
            if (in_array('catatan', $changed, true)) {
                $new['kolom_diubah'] = implode(', ', array_map(fn ($c) => MasterAuditLog::FIELD_LABELS[$c] ?? $c, $changed));
            }
            MasterAuditLog::record($actor, 'job_history.updated', 'job_history', $row->id, $this->label($row), $old, $new, $reason);
            $this->recordKeptNames($actor, $reason);

            return $row;
        });
    }

    public function delete(Employee $employee, JobHistory $row, User $actor, string $reason): void
    {
        if ($this->isRunning($employee, $row)) {
            throw ValidationException::withMessages(['alasan' => 'Riwayat jabatan yang masih berjalan tidak dapat dihapus. Gunakan modul Mutasi & Penempatan untuk mengubah jabatan karyawan.']);
        }

        DB::transaction(function () use ($row, $actor, $reason): void {
            $row->update(['cancelled_by' => $actor->id, 'alasan_pembatalan' => mb_substr($reason, 0, 255)]);
            $row->delete();
            MasterAuditLog::record($actor, 'job_history.deleted', 'job_history', $row->id, $this->label($row), $this->audited($row->only(self::AUDITED_VALUES)), null, $reason);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, ?JobHistory $row = null): array
    {
        $values = array_intersect_key($data, array_flip([...self::POSITION_FIELDS, 'catatan']));
        foreach ($values as $key => $value) {
            $values[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        [$values['jabatan'], $values['unit_kerja']] = $this->placement(
            (string) ($values['jabatan'] ?? ''), (string) ($values['unit_kerja'] ?? ''), (string) ($values['tmt_awal'] ?? ''), $row, ! empty($data['nama_di_luar_master']),
        );

        if (($values['golongan'] ?? null) !== null) {
            $standard = Golongan::standard($values['golongan']);
            if ($standard === null) {
                throw ValidationException::withMessages(['golongan' => 'Format Golongan tidak valid. Gunakan format baku [Gol]/[Berkala], contoh: IIIA/00 atau IVB/03.']);
            }
            $values['golongan'] = $standard;
        }

        // The level follows the RM Band of a master position (RM-1..RM-3 Karpim, RM-4..RM-7 Karpel); chosen by hand only
        // for an old name outside the master.
        $rm = $this->rmBand((string) $values['jabatan']);
        $chosen = $values['level'] ?? $row?->level;
        $values['level'] = $rm !== null ? $this->formations->deriveLevelForRm($rm, $chosen) : $chosen;

        // Record-only rows: a stored SK is kept as it is (the form does not offer the fields).
        if ($row?->isRecordOnly()) {
            $values['nomor_sk'] = $row->nomor_sk;
            $values['tanggal_sk'] = $row->tanggal_sk?->format('Y-m-d');
            if ($row->sumber === JobHistory::SUMBER_BEZETTING) {
                $values['jenis_mutasi'] = null;
            }
        }

        return $values;
    }

    /**
     * Formasi in force on $date: the structure SK whose effective date is the latest on or before it, or, before the first
     * structure SK (no structure is recorded then, DOMAIN.md §3.7 and §5.5), the current Master Formasi. Pool positions
     * (Advisor, Diperbantukan) opened on a unit are valid placements on it too.
     *
     * @return array{decree: ?StructureDecree, units: array<string, list<string>>}
     */
    public function formasiOn(?string $date): array
    {
        // One source for "which structure applies on a date" and "the current structure" (StructureAsOfService,
        // StructureReader); read fresh every call, since this service may live across requests in a long-running worker.
        $decree = $date === null ? null : $this->asOf->decreeFor(CarbonImmutable::parse($date));

        return ['decree' => $decree, 'units' => $this->unitsOf($decree?->snapshot ?? $this->reader->current(), $this->poolPositionsByUnit())];
    }

    /**
     * Every structure the form needs to filter positions by unit on the client: one entry per applied structure SK
     * (oldest first) and the current master for dates before the first one.
     *
     * @return array{master: array<string, list<string>>, versions: list<array{from: string, sk: string, units: array<string, list<string>>}>}
     */
    public function formasiForForm(): array
    {
        $pools = $this->poolPositionsByUnit();

        return [
            'master' => $this->unitsOf($this->reader->current(), $pools),
            'versions' => collect($this->asOf->versions())->reverse()->values()
                ->map(fn (array $version) => ['from' => $version['from']->toDateString(), 'sk' => (string) $version['decree']->sk_number,
                    'units' => $this->unitsOf($version['decree']->snapshot ?? [], $pools)])->all(),
        ];
    }

    /**
     * Unit → position names of a structure (the shared shape of StructureReader and decree snapshots), plus the pool
     * positions opened on each unit, which are valid placements but not formasi seats.
     *
     * @param  array{units?: array<string, array{seats?: array<string, mixed>}>}  $structure
     * @param  array<string, list<string>>  $pools
     * @return array<string, list<string>>
     */
    private function unitsOf(array $structure, array $pools): array
    {
        return collect($structure['units'] ?? [])
            ->map(fn (array $unit, string $name) => array_values(array_unique([...array_keys($unit['seats'] ?? []), ...($pools[$name] ?? [])])))
            ->all();
    }

    /** @return array<string, list<string>> unit name → pool positions (Advisor, Diperbantukan) opened on it, which StructureReader leaves out */
    private function poolPositionsByUnit(): array
    {
        return DB::table('position_unit')
            ->join('units', 'units.id', '=', 'position_unit.unit_id')
            ->join('positions', 'positions.id', '=', 'position_unit.position_id')
            ->where(fn ($q) => $q->where('positions.is_overflow', true)->orWhere('positions.is_diperban', true))
            ->orderBy('positions.nama')
            ->get(['units.nama as unit', 'positions.nama as jabatan'])
            ->groupBy('unit')->map(fn ($rows) => $rows->pluck('jabatan')->all())->all();
    }

    /**
     * Position and unit are master data, not free text, and the position must belong to the unit's formasi on the start
     * date. Returns the names in the master's own spelling.
     *
     * - Unchanged pair: a row keeps the position and unit it already holds (also an old name HR decided to keep).
     * - Exception (`nama_di_luar_master`): only for a period before the first structure SK, when no structure is recorded;
     *   a name outside the master is accepted as written unless HR already mapped or skipped it, and is recorded as a
     *   "keep" decision in the same name dictionary the Bezetting rebuild uses (DOMAIN.md §5.5 rules 5–6).
     *
     * @return array{0: string, 1: string}
     */
    private function placement(string $jabatan, string $unit, string $start, ?JobHistory $row, bool $outsideMaster): array
    {
        $this->keptNames = [];
        $key = fn (?string $text) => BezettingNameMapping::normalize((string) $text);
        $findIn = fn (array $names, string $given) => collect($names)->first(fn ($name) => $key($name) === $key($given));
        $masterPositions = Position::query()->pluck('nama')->all();
        $masterUnits = Unit::query()->pluck('nama')->all();

        if ($row && $key($row->jabatan) === $key($jabatan) && $key($row->unit_kerja) === $key($unit)) {
            return [$findIn($masterPositions, $jabatan) ?? $row->jabatan, $findIn($masterUnits, $unit) ?? $row->unit_kerja];
        }

        $formasi = $this->formasiOn($start ?: null);
        $where = $formasi['decree'] ? 'struktur SK '.$formasi['decree']->sk_number : 'Master Formasi saat ini';

        if ($outsideMaster) {
            if ($formasi['decree']) {
                throw ValidationException::withMessages(['jabatan' => "Periode ini berada dalam {$where}, sehingga jabatan dan unit wajib sesuai strukturnya. Nama lama di luar Master Formasi hanya dapat dicatat untuk periode sebelum SK struktur pertama."]);
            }
            $names = [];
            foreach (['jabatan' => [$jabatan, $masterPositions, BezettingNameMapping::KIND_JABATAN], 'unit_kerja' => [$unit, $masterUnits, BezettingNameMapping::KIND_UNIT]] as $field => [$given, $master, $kind]) {
                $given = trim((string) preg_replace('/\s+/u', ' ', $given));
                if ($given === '') {
                    throw ValidationException::withMessages([$field => 'Isi nama '.($field === 'jabatan' ? 'jabatan' : 'unit kerja').' sesuai dokumen.']);
                }
                $names[$field] = $findIn($master, $given) ?? $this->keptOldName($field, $kind, $given);
            }
            if ($findIn($masterPositions, $names['jabatan']) && $findIn($masterUnits, $names['unit_kerja'])) {
                $this->assertInFormasi($formasi['units'], $names['jabatan'], $names['unit_kerja'], $where);
            }

            return [$names['jabatan'], $names['unit_kerja']];
        }

        $hint = ' Bila dokumen lama memakai nama yang tidak ada pada struktur saat ini, pilih nama resminya dan tulis nama lama pada Catatan, atau gunakan pilihan nama lama untuk periode sebelum SK struktur pertama.';
        $masterUnit = $findIn($masterUnits, $unit) ?? throw ValidationException::withMessages(['unit_kerja' => 'Pilih unit kerja dari Master Formasi.'.$hint]);
        $masterPosition = $findIn($masterPositions, $jabatan) ?? throw ValidationException::withMessages(['jabatan' => 'Pilih jabatan dari Master Formasi.'.$hint]);
        $this->assertInFormasi($formasi['units'], $masterPosition, $masterUnit, $where);

        return [$masterPosition, $masterUnit];
    }

    /** @param array<string, list<string>> $units */
    private function assertInFormasi(array $units, string $jabatan, string $unit, string $where): void
    {
        if (! isset($units[$unit])) {
            throw ValidationException::withMessages(['unit_kerja' => "Unit {$unit} tidak tercantum pada {$where} untuk tanggal mulai ini."]);
        }
        if (! in_array($jabatan, $units[$unit], true)) {
            throw ValidationException::withMessages(['jabatan' => "Jabatan {$jabatan} tidak termasuk formasi {$unit} menurut {$where}. Pilih jabatan yang ada pada formasi unit tersebut."]);
        }
    }

    /** An old name already decided by HR as "keep", or a new one to record as "keep"; a mapped or skipped name is refused. */
    private function keptOldName(string $field, string $kind, string $given): string
    {
        $decision = BezettingNameMapping::decisionFor($kind, $given);
        if ($decision?->decision === BezettingNameMapping::DECISION_MAP) {
            throw ValidationException::withMessages([$field => "Nama \"{$given}\" sudah diputuskan SDM sebagai nama lama dari \"{$decision->target_value}\". Pilih \"{$decision->target_value}\" dari daftar."]);
        }
        if ($decision?->decision === BezettingNameMapping::DECISION_SKIP) {
            throw ValidationException::withMessages([$field => "Nama \"{$given}\" sudah diputuskan SDM untuk dilewati, sehingga tidak dapat dipakai pada riwayat."]);
        }
        if ($decision === null) {
            $this->keptNames[] = [$kind, $given];
        }

        return $given;
    }

    /** Records the new "keep as written" name decisions of this correction, with its reason, in the same transaction. */
    private function recordKeptNames(User $actor, string $reason): void
    {
        foreach ($this->keptNames as [$kind, $text]) {
            $mapping = BezettingNameMapping::create(['kind' => $kind, 'source_text' => $text, 'decision' => BezettingNameMapping::DECISION_KEEP,
                'note' => 'Koreksi manual Riwayat Karir: '.mb_substr($reason, 0, 200), 'decided_by' => $actor->id]);
            MasterAuditLog::record($actor, 'bezetting.mapping_decided', 'bezetting_mapping', $mapping->id, "{$kind}: {$mapping->source_text}",
                null, ['keputusan' => BezettingNameMapping::DECISION_KEEP, 'tujuan' => null], $reason);
        }
        $this->keptNames = [];
    }

    /**
     * A record-only row is not a Board SK: a new SK number or date is refused (a stored value may be sent back as it is),
     * and a Bezetting row has no mutation type (DOMAIN.md §2.2, §5.5 rules 2 and 4).
     *
     * @param  array<string, mixed>  $data  raw input
     */
    private function assertRecordOnlyRules(JobHistory $row, array $data): void
    {
        if (! $row->isRecordOnly()) {
            return;
        }

        $errors = [];
        $stored = ['nomor_sk' => (string) $row->nomor_sk, 'tanggal_sk' => (string) $row->tanggal_sk?->format('Y-m-d')];
        foreach ($stored as $field => $current) {
            $given = trim((string) ($data[$field] ?? ''));
            if ($given !== '' && $given !== $current) {
                $errors[$field] = 'Riwayat ini adalah catatan data kepegawaian, bukan SK mutasi, sehingga SK tidak dapat diisi di sini. Catat SK lewat modul Mutasi.';
            }
        }
        if ($row->sumber === JobHistory::SUMBER_BEZETTING && trim((string) ($data['jenis_mutasi'] ?? '')) !== '') {
            $errors['jenis_mutasi'] = 'Riwayat dari Bezetting tidak memiliki jenis mutasi resmi.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $values */
    private function assertClosedPeriod(Employee $employee, array $values, ?JobHistory $row): void
    {
        if ($values['tmt_akhir'] > now()->toDateString()) {
            throw ValidationException::withMessages(['tmt_akhir' => 'TMT Akhir tidak boleh melewati hari ini. Jabatan yang masih berjalan dicatat melalui modul Mutasi.']);
        }
        if (($row?->status_penugasan ?? Employee::STATUS_DEFINITIF) !== Employee::STATUS_DEFINITIF) {
            return;
        }

        $overlap = JobHistory::query()->applied()
            ->where('employee_nik', $employee->nik)
            ->where('status_penugasan', Employee::STATUS_DEFINITIF)
            ->when($row, fn ($q) => $q->whereKeyNot($row->id))
            ->whereDate('tmt_awal', '<=', $values['tmt_akhir'])
            ->where(fn ($q) => $q->whereNull('tmt_akhir')->orWhereDate('tmt_akhir', '>=', $values['tmt_awal']))
            ->orderBy('tmt_awal')
            ->first();

        if ($overlap) {
            $period = $overlap->tmt_awal->format('d M Y').' s.d. '.($overlap->tmt_akhir?->format('d M Y') ?? 'sekarang');
            throw ValidationException::withMessages(['tmt_awal' => "Periode ini bertumpang tindih dengan riwayat definitif lain ({$overlap->jabatan}, {$period}). Sesuaikan tanggalnya; satu karyawan hanya memegang satu jabatan definitif pada satu waktu."]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function audited(array $values): array
    {
        return array_map(fn ($value) => $this->plain($value), array_intersect_key($values, array_flip(self::AUDITED_VALUES)));
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    /** No NIK or name on the audit log (UU PDP). */
    private function label(JobHistory $row): string
    {
        return 'Riwayat jabatan #'.$row->id;
    }
}
