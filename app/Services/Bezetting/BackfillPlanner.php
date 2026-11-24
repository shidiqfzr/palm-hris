<?php

namespace App\Services\Bezetting;

use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\Position;
use App\Models\Unit;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the plan of a Bezetting backfill run without writing anything (DOMAIN.md §5.5).
 *
 * - Names: a unit or position that matches the current master exactly is used as is; any other name needs an HR decision
 *   (map, keep as written, skip) saved in BezettingNameMapping. Undecided names block the run.
 * - People: NIK not 8 digits, duplicate NIK in one file, not in the application, no current history, or already
 *   reconstructed are excluded and listed; they do not block the run.
 * - Existing data wins: only time before each employee's earliest existing row is written, every written row is closed,
 *   and later changes are only reported as movement not recorded in the application.
 *
 * The result holds NIKs in memory only; callers persist the summary counts, never the lists.
 */
class BackfillPlanner
{
    public const EXCLUDED_NIK_FORMAT = 'nik_bukan_8_digit';

    public const EXCLUDED_DUPLICATE = 'nik_ganda_dalam_berkas';

    public const EXCLUDED_NOT_IN_APP = 'belum_ada_di_aplikasi';

    public const EXCLUDED_NO_HISTORY = 'belum_ada_riwayat_jabatan';

    public const EXCLUDED_ALREADY_DONE = 'sudah_direkonstruksi';

    public const EXCLUDED_DATE_CONFLICT = 'tanggal_bertabrakan';

    public const EXCLUSION_LABELS = [
        self::EXCLUDED_NIK_FORMAT => 'NIK bukan 8 digit (perlu verifikasi SDM)',
        self::EXCLUDED_DUPLICATE => 'NIK ganda pada satu berkas',
        self::EXCLUDED_NOT_IN_APP => 'Belum ada pada data karyawan',
        self::EXCLUDED_NO_HISTORY => 'Belum memiliki riwayat jabatan',
        self::EXCLUDED_ALREADY_DONE => 'Riwayat sudah direkonstruksi',
        self::EXCLUDED_DATE_CONFLICT => 'Tanggal riwayat bertabrakan',
    ];

    private const LEVEL = 'Karpim';

    /** @var array<string, string> normalized → master name */
    private array $units = [];

    /** @var array<string, string> */
    private array $positions = [];

    /** @var array<string, BezettingNameMapping> kind|normalized → decision */
    private array $decisions = [];

    /** @var array<string, array{kind: string, text: string, count: int}> */
    private array $blocking = [];

    public function __construct(private PositionTextInterpreter $interpreter, private CareerHistoryDiffer $differ) {}

    /**
     * @param  list<array{period: string, rows: list<array<string, mixed>>}>  $snapshots  reader output per file
     * @return array{rows: list<array<string, mixed>>, blocking: list<array{kind: string, text: string, count: int}>,
     *     excluded: array<string, array{reason: string, periode: ?string}>, info: array<string, list<array<string, string>>>,
     *     summary: array<string, int>, digest: string}
     */
    public function plan(array $snapshots): array
    {
        $this->load();
        usort($snapshots, fn (array $a, array $b) => strcmp($a['period'], $b['period']));
        $periods = array_column($snapshots, 'period');

        // 1. People: format and duplicates per file first, then presence in the application. Names are resolved only for
        //    people who stay in the run, so HR is never asked to decide names that would not be written.
        $excluded = [];
        $seen = [];
        foreach ($snapshots as $snapshot) {
            $counts = array_count_values(array_map('strval', array_column($snapshot['rows'], 'nik')));
            foreach ($snapshot['rows'] as $row) {
                $nik = (string) $row['nik'];
                $seen[$nik] = true;
                if (strlen($nik) !== 8) {
                    $excluded[$nik] ??= ['reason' => self::EXCLUDED_NIK_FORMAT, 'periode' => $snapshot['period']];
                } elseif ($counts[$nik] > 1) {
                    $excluded[$nik] ??= ['reason' => self::EXCLUDED_DUPLICATE, 'periode' => $snapshot['period']];
                }
            }
        }

        $niks = array_keys(array_diff_key($seen, $excluded));
        $known = Employee::query()->whereIn('nik', $niks)->pluck('nik')->map(fn ($n) => (string) $n)->flip();
        $histories = JobHistory::query()->applied()->whereIn('employee_nik', $niks)
            ->get(['employee_nik', 'jabatan', 'unit_kerja', 'tmt_awal', 'tmt_akhir', 'sumber'])
            ->groupBy('employee_nik');

        foreach ($niks as $nik) {
            $own = $histories->get($nik);
            $reason = match (true) {
                ! $known->has($nik) => self::EXCLUDED_NOT_IN_APP,
                $own === null || $own->isEmpty() => self::EXCLUDED_NO_HISTORY,
                $own->contains('sumber', JobHistory::SUMBER_BEZETTING) => self::EXCLUDED_ALREADY_DONE,
                default => null,
            };
            if ($reason !== null) {
                $excluded[$nik] = ['reason' => $reason, 'periode' => null];
            }
        }

        // 2. Names, for included people only.
        $observations = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['rows'] as $row) {
                $nik = (string) $row['nik'];
                if (isset($excluded[$nik])) {
                    continue;
                }
                $observation = $this->observe($row);
                if ($observation !== null) {
                    $observations[$snapshot['period']][$nik] = $observation;
                }
            }
        }

        // 3. History segments, cut at each employee's earliest existing row.
        $rows = [];
        $info = ['diperkirakan' => [], 'celah' => [], 'keluar_daftar' => [], 'perpindahan_belum_tercatat' => []];
        foreach ($this->differ->diff($periods, $observations) as $nik => $result) {
            $nik = (string) $nik;
            $own = $histories->get($nik);
            $cutoff = $own->min(fn ($h) => $h->tmt_awal->toDateString());
            $personRows = [];

            foreach ($result['segments'] as $segment) {
                if ($segment['tmt_awal'] >= $cutoff) {
                    if ($segment['lane'] === CareerHistoryDiffer::LANE_MAIN && ! $this->recorded($own, $segment)) {
                        $info['perpindahan_belum_tercatat'][] = ['nik' => $nik, 'periode' => $segment['dari_periode']];
                    }

                    continue;
                }
                $dayBeforeCutoff = (new DateTimeImmutable($cutoff))->modify('-1 day')->format('Y-m-d');
                $end = $segment['tmt_akhir'] === null || $segment['tmt_akhir'] >= $cutoff ? $dayBeforeCutoff : $segment['tmt_akhir'];
                $personRows[] = $this->row($nik, $segment, $end);
            }

            if (! $this->consistent($personRows)) {
                $excluded[$nik] = ['reason' => self::EXCLUDED_DATE_CONFLICT, 'periode' => null];

                continue;
            }

            foreach ($personRows as $row) {
                if ($row['tmt_awal_diperkirakan']) {
                    $info['diperkirakan'][] = ['nik' => $nik, 'tmt_awal' => $row['tmt_awal']];
                }
            }
            foreach ($result['gaps'] as $gap) {
                $info['celah'][] = ['nik' => $nik, 'dari' => $gap['dari'], 'sampai' => $gap['sampai']];
            }
            if (! $result['present_at_end']) {
                $info['keluar_daftar'][] = ['nik' => $nik, 'terakhir_terlihat' => $result['last_seen']];
            }
            array_push($rows, ...$personRows);
        }

        $blocking = array_values($this->blocking);
        usort($blocking, fn (array $a, array $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);

        $summary = [
            'berkas' => count($snapshots),
            'baris_riwayat' => count($rows),
            'karyawan' => count(array_unique(array_column($rows, 'employee_nik'))),
            'nama_belum_diputuskan' => count($blocking),
            'karyawan_dikecualikan' => count($excluded),
        ];
        foreach ($info as $key => $items) {
            $summary[$key] = count($items);
        }

        return [
            'rows' => $rows,
            'blocking' => $blocking,
            'excluded' => $excluded,
            'info' => $info,
            'summary' => $summary,
            'digest' => hash('sha256', json_encode([$rows, $blocking], JSON_THROW_ON_ERROR)),
        ];
    }

    private function load(): void
    {
        $this->blocking = [];
        $this->units = Unit::query()->pluck('nama')->mapWithKeys(fn ($n) => [BezettingNameMapping::normalize((string) $n) => (string) $n])->all();
        $this->positions = Position::query()->pluck('nama')->mapWithKeys(fn ($n) => [BezettingNameMapping::normalize((string) $n) => (string) $n])->all();
        $this->decisions = BezettingNameMapping::query()->get()->keyBy(fn ($d) => $d->kind.'|'.$d->source_text)->all();
    }

    /** One file row → an observation for the differ, or null when one of its names is skipped or undecided. */
    private function observe(array $row): ?array
    {
        $unitText = (string) $row['unit'];
        $parsed = $this->interpreter->interpret((string) $row['jabatan'], $unitText);

        $unit = $this->resolve(BezettingNameMapping::KIND_UNIT, $unitText, $this->units);
        $jabatan = $this->resolve(BezettingNameMapping::KIND_JABATAN, $parsed['main']['jabatan'], $this->positions);
        $status = $parsed['main']['status'] ?? $this->resolve(BezettingNameMapping::KIND_STATUS, (string) $parsed['main']['status_phrase'], []);

        $concurrent = [];
        foreach ($parsed['concurrent'] as $duty) {
            $dutyJabatan = $this->resolve(BezettingNameMapping::KIND_JABATAN, $duty['jabatan'], $this->positions);
            $dutyUnit = $this->resolve(BezettingNameMapping::KIND_UNIT, $duty['unit'], $this->units);
            if ($dutyJabatan === null || $dutyUnit === null) {
                return null;
            }
            $concurrent[] = ['jabatan' => $dutyJabatan, 'unit' => $dutyUnit, 'status' => $duty['status'], 'tmt' => $duty['tmt']];
        }

        if ($unit === null || $jabatan === null || $status === null) {
            return null;
        }

        return [
            'main' => ['jabatan' => $jabatan, 'unit' => $unit, 'status' => $status],
            'concurrent' => $concurrent,
            'golongan' => (string) $row['golongan'],
            'tanggal_dalam_jabatan' => $row['tanggal_dalam_jabatan'],
            'tmt_unit_kerja' => $row['tmt_unit_kerja'],
            'teks_sumber' => trim($unitText.' | '.$row['jabatan']),
        ];
    }

    /**
     * Name as written → name to store. An exact master match needs no decision; otherwise the saved HR decision applies.
     * Returns null for a skipped or undecided name (undecided names are counted as blocking).
     *
     * @param  array<string, string>  $master
     */
    private function resolve(string $kind, string $text, array $master): ?string
    {
        $normalized = BezettingNameMapping::normalize($text);
        if ($normalized === '') {
            return null;
        }
        if (isset($master[$normalized])) {
            return $master[$normalized];
        }

        $decision = $this->decisions[$kind.'|'.$normalized] ?? null;
        // A status phrase can only be mapped to an official status; anything else is still undecided.
        if ($decision !== null && $kind === BezettingNameMapping::KIND_STATUS
            && ($decision->decision !== BezettingNameMapping::DECISION_MAP || ! in_array($decision->target_value, self::statuses(), true))) {
            $decision = null;
        }
        if ($decision === null) {
            $this->blocking[$kind.'|'.$normalized] ??= ['kind' => $kind, 'text' => $normalized, 'count' => 0];
            $this->blocking[$kind.'|'.$normalized]['count']++;

            return null;
        }

        return match ($decision->decision) {
            BezettingNameMapping::DECISION_MAP => (string) $decision->target_value,
            BezettingNameMapping::DECISION_KEEP => trim((string) preg_replace('/\s+/u', ' ', $text)),
            default => null,
        };
    }

    /** True when the application already records this position at the segment start (same position and unit). */
    private function recorded(Collection $own, array $segment): bool
    {
        $start = $segment['tmt_awal'];

        return $own->contains(fn ($h) => $h->tmt_awal->toDateString() <= $start
            && ($h->tmt_akhir === null || $h->tmt_akhir->toDateString() >= $start)
            && BezettingNameMapping::normalize((string) $h->jabatan) === BezettingNameMapping::normalize($segment['jabatan'])
            && BezettingNameMapping::normalize((string) $h->unit_kerja) === BezettingNameMapping::normalize($segment['unit']));
    }

    private function row(string $nik, array $segment, string $end): array
    {
        [$year, $month] = explode('-', $segment['dari_periode']);
        $monthName = array_search((int) $month, BezettingWorkbookReader::MONTHS, true);

        return [
            'employee_nik' => $nik,
            'jabatan' => $segment['jabatan'],
            'unit_kerja' => $segment['unit'],
            'status_penugasan' => $segment['status'],
            'level' => self::LEVEL,
            'golongan' => $segment['golongan'] !== '' ? $segment['golongan'] : null,
            'tmt_awal' => $segment['tmt_awal'],
            'tmt_akhir' => $end,
            'tmt_awal_diperkirakan' => $segment['diperkirakan'],
            'jenis_mutasi' => null,
            'jenis_perubahan_terdeteksi' => $segment['perubahan'],
            'nomor_sk' => null,
            'tanggal_sk' => null,
            'sumber' => JobHistory::SUMBER_BEZETTING,
            'is_applied' => true,
            'teks_sumber' => mb_substr($segment['teks_sumber'], 0, 1000),
            'catatan' => 'Direkonstruksi dari Bezetting '.ucfirst(mb_strtolower((string) $monthName)).' '.$year.' (bukan SK mutasi)',
        ];
    }

    /** Every row ends on or after its start, and definitive periods of one person never overlap. */
    private function consistent(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['tmt_akhir'] < $row['tmt_awal']) {
                return false;
            }
        }
        $definitive = array_values(array_filter($rows, fn ($r) => $r['status_penugasan'] === Employee::STATUS_DEFINITIF));
        usort($definitive, fn ($a, $b) => strcmp($a['tmt_awal'], $b['tmt_awal']));
        for ($i = 1; $i < count($definitive); $i++) {
            if ($definitive[$i]['tmt_awal'] <= $definitive[$i - 1]['tmt_akhir']) {
                return false;
            }
        }

        return true;
    }

    /** Official statuses a status phrase may be mapped to. @return list<string> */
    public static function statuses(): array
    {
        return array_values(array_unique([...Employee::ASSIGNMENT_STATUSES, Employee::STATUS_PLT, Employee::STATUS_PJ]));
    }
}
