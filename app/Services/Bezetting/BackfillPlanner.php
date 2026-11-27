<?php

namespace App\Services\Bezetting;

use App\Models\BezettingLegacyNikMap;
use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\Position;
use App\Models\Unit;
use App\Services\Import\PositionNameMatcher;
use App\Support\Golongan;
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

    public const EXCLUDED_BEFORE_HIRE = 'sebelum_tanggal_bekerja';

    public const EXCLUDED_IDENTITY = 'identitas_tidak_konsisten';

    public const EXCLUDED_LEGACY_UNMAPPED = 'nik_lama_belum_terhubung';

    /** An application row counts as the record of a movement seen in the files when it starts within this many days before it. */
    private const RECORDED_TOLERANCE_DAYS = 62;

    public const EXCLUSION_LABELS = [
        self::EXCLUDED_NIK_FORMAT => 'NIK bukan 8 digit (perlu verifikasi SDM)',
        self::EXCLUDED_DUPLICATE => 'NIK ganda pada satu berkas',
        self::EXCLUDED_NOT_IN_APP => 'Belum ada pada data karyawan',
        self::EXCLUDED_NO_HISTORY => 'Belum memiliki riwayat jabatan',
        self::EXCLUDED_ALREADY_DONE => 'Riwayat sudah direkonstruksi',
        self::EXCLUDED_DATE_CONFLICT => 'Tanggal riwayat bertabrakan',
        self::EXCLUDED_BEFORE_HIRE => 'Riwayat mendahului tanggal bekerja',
        self::EXCLUDED_IDENTITY => 'Identitas tidak konsisten',
        self::EXCLUDED_LEGACY_UNMAPPED => 'Nomor induk lama belum terhubung ke NIK 8 digit',
    ];

    /**
     * What each reason means and what HR does about it, in plain words: one source for the Dikecualikan tab and the HR
     * download. Excluded people are not processed at all, and none of this blocks submitting the run.
     */
    public const EXCLUSION_HELP = [
        self::EXCLUDED_NOT_IN_APP => [
            'arti' => 'NIK ada pada berkas Bezetting, tetapi belum ada pada data karyawan di aplikasi.',
            'tindakan' => 'Bila ia karyawan aktif, tambahkan lebih dulu pada data karyawan lalu ulangi rekonstruksi. Bila sudah berhenti atau bukan Karyawan Pimpinan, tidak ada tindakan.',
        ],
        self::EXCLUDED_NIK_FORMAT => [
            'arti' => 'NIK pada berkas bukan 8 digit (misalnya hanya 7 digit), sehingga tidak dapat dicocokkan dengan aman.',
            'tindakan' => 'SDM memeriksa NIK yang benar pada dokumen asli (lihat Daftar Verifikasi SDM), lalu memperbaikinya pada berkas atau data karyawan.',
        ],
        self::EXCLUDED_DUPLICATE => [
            'arti' => 'NIK yang sama tercantum lebih dari sekali dalam satu berkas, sehingga tidak jelas baris mana yang benar.',
            'tindakan' => 'Periksa berkas pada bulan yang tercantum, perbaiki barisnya, lalu unggah ulang.',
        ],
        self::EXCLUDED_NO_HISTORY => [
            'arti' => 'Karyawan ada, tetapi belum memiliki riwayat jabatan di aplikasi, sehingga tidak ada titik awal untuk menyambungkan riwayat.',
            'tindakan' => 'Lengkapi data jabatan karyawan terlebih dahulu, lalu ulangi rekonstruksi.',
        ],
        self::EXCLUDED_ALREADY_DONE => [
            'arti' => 'Riwayat karyawan ini sudah pernah dibangun oleh proses rekonstruksi lain.',
            'tindakan' => 'Tidak ada tindakan. Untuk mengulang, batalkan proses sebelumnya lebih dulu.',
        ],
        self::EXCLUDED_DATE_CONFLICT => [
            'arti' => 'Tanggal riwayat hasil pembacaan berkas saling bertabrakan, sehingga tidak ditulis agar riwayat tetap konsisten.',
            'tindakan' => 'SDM memeriksa tanggal pada berkas karyawan ini terhadap dokumen asli.',
        ],
        self::EXCLUDED_IDENTITY => [
            'arti' => 'Nama atau tanggal lahir pada NIK ini berbeda antarbulan atau berbeda dengan data karyawan, sehingga berkas mungkin memuat dua orang pada satu NIK. Tidak ditulis agar riwayat dua orang tidak bercampur.',
            'tindakan' => 'SDM memeriksa NIK ini pada berkas dan data karyawan (kemungkinan salah ketik), lalu memperbaikinya pada sumbernya.',
        ],
        self::EXCLUDED_BEFORE_HIRE => [
            'arti' => 'Berkas memuat jabatan karyawan ini sebelum tanggal mulai bekerjanya pada data karyawan, sehingga riwayat tidak ditulis agar tidak bertentangan.',
            'tindakan' => 'SDM memeriksa tanggal mulai bekerja pada data karyawan (mungkin perlu dikoreksi); riwayatnya dapat ditambahkan sesudahnya.',
        ],
        self::EXCLUDED_LEGACY_UNMAPPED => [
            'arti' => 'Berkas hanya memuat nomor induk lama (sebelum Oktober 2022) dan belum ditemukan padanan NIK 8 digit-nya pada data karyawan aktif (umumnya karyawan sudah berhenti sebelum Oktober 2022, atau proses periode 2023–2026 belum disetujui).',
            'tindakan' => 'Setujui proses Bezetting periode 2023–2026 lebih dulu agar sistem menautkan nomor induk lama secara otomatis; bila karyawan sudah berhenti sebelum Oktober 2022, tidak perlu tindakan.',
        ],
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

    /** @var array<string, array{kind: string, text: string, count: int, decision: string, target: ?string}> */
    private array $decided = [];

    /** Times a name was met that HR has not decided yet (a skipped name is a decision and is not counted). */
    /** @var array<string, array{kind: string, text: string, target: string}> names matched to the master automatically (spelling variants only) */
    private array $autoMatched = [];

    private int $undecidedHits = 0;

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

        // 0. Resolve legacy-only PTPN XIII rows (pre-Oct 2022) via persisted + conflict-free in-run crosswalk.
        $excluded = [];
        $fileNames = [];
        $crosswalk = $this->buildCrosswalk($snapshots);
        foreach ($snapshots as &$snapshot) {
            $resolvedRows = [];
            foreach ($snapshot['rows'] as $row) {
                $rowName = trim((string) ($row['nama'] ?? ''));
                if (! empty($row['is_legacy_nik'])) {
                    $hash = (string) ($row['legacy_nik_hash'] ?? '');
                    if ($hash !== '' && isset($crosswalk[$hash])) {
                        $row['nik'] = $crosswalk[$hash];
                        if ($rowName !== '') {
                            $fileNames[$row['nik']] ??= $rowName;
                        }
                        $resolvedRows[] = $row;
                    } elseif ($hash !== '') {
                        $key = 'L:'.substr($hash, 0, 8);
                        if ($rowName !== '') {
                            $fileNames[$key] ??= $rowName;
                        }
                        $excluded[$key] ??= ['reason' => self::EXCLUDED_LEGACY_UNMAPPED, 'periode' => $snapshot['period'], 'nama' => $fileNames[$key] ?? ''];
                    }

                    continue;
                }
                if ($rowName !== '' && (string) ($row['nik'] ?? '') !== '') {
                    $fileNames[(string) $row['nik']] ??= $rowName;
                }
                $resolvedRows[] = $row;
            }
            $snapshot['rows'] = $resolvedRows;
        }
        unset($snapshot);

        // 1. People: format and duplicates per file first, then presence in the application. Names are resolved only for
        //    people who stay in the run, so HR is never asked to decide names that would not be written.
        $seen = [];
        $identity = [];
        foreach ($snapshots as $snapshot) {
            $counts = array_count_values(array_map('strval', array_column($snapshot['rows'], 'nik')));
            foreach ($snapshot['rows'] as $row) {
                $nik = (string) $row['nik'];
                $seen[$nik] = true;
                $identity[$nik]['names'][] = self::nameWords((string) ($row['nama'] ?? ''));
                if (! empty($row['tanggal_lahir'])) {
                    $identity[$nik]['births'][(string) $row['tanggal_lahir']] = true;
                }
                if (strlen($nik) !== 8) {
                    $excluded[$nik] ??= ['reason' => self::EXCLUDED_NIK_FORMAT, 'periode' => $snapshot['period']];
                } elseif ($counts[$nik] > 1) {
                    $excluded[$nik] ??= ['reason' => self::EXCLUDED_DUPLICATE, 'periode' => $snapshot['period']];
                }
            }
        }

        // One NIK must be one person: names that share no word from month to month, or two birth dates, mean the files hold two people under it.
        foreach ($identity as $nik => $facts) {
            if (! isset($excluded[$nik]) && (self::namesDisagree($facts['names']) || count($facts['births'] ?? []) > 1)) {
                $excluded[$nik] = ['reason' => self::EXCLUDED_IDENTITY, 'periode' => null];
            }
        }

        $niks = array_keys(array_diff_key($seen, $excluded));
        $hired = Employee::query()->whereIn('nik', $niks)->pluck('tmt_bekerja', 'nik')->mapWithKeys(fn ($date, $n) => [(string) $n => $date?->toDateString()]);
        $known = $hired->keys()->flip();
        $births = Employee::query()->whereIn('nik', $niks)->pluck('tanggal_lahir', 'nik')->mapWithKeys(fn ($date, $n) => [(string) $n => $date?->toDateString()]);
        $histories = JobHistory::query()->applied()->whereIn('employee_nik', $niks)
            ->get(['employee_nik', 'jabatan', 'unit_kerja', 'tmt_awal', 'tmt_akhir', 'sumber'])
            ->groupBy('employee_nik');

        foreach ($niks as $nik) {
            $own = $histories->get($nik);
            $earliestOwn = $own !== null && $own->isNotEmpty() ? $own->min(fn ($h) => $h->tmt_awal->toDateString()) : null;
            $reason = match (true) {
                ! $known->has($nik) => self::EXCLUDED_NOT_IN_APP,
                $own === null || $own->isEmpty() => self::EXCLUDED_NO_HISTORY,
                $own->contains('sumber', JobHistory::SUMBER_BEZETTING) && $periods !== [] && ($periods[0].'-01') >= $earliestOwn => self::EXCLUDED_ALREADY_DONE,
                default => null,
            };
            if ($reason === null && $births->get($nik) !== null && isset($identity[$nik]['births']) && ! isset($identity[$nik]['births'][$births->get($nik)])) {
                $reason = self::EXCLUDED_IDENTITY;
            }
            if ($reason !== null) {
                $excluded[$nik] = ['reason' => $reason, 'periode' => null];
            }
        }

        // 2. Names, for included people only. A person listed in a file is present that month whatever their names are, so a month is
        //    never a gap or an exit because of a name. A row whose name is skipped (HR said it is not valid, e.g. a typo) carries the
        //    previous month's state forward instead of breaking the history; a person with a name still undecided is left out of the
        //    history and follow-ups until HR decides (the preview says so), so nothing is built from half-read months.
        $observations = [];
        $present = [];
        $pending = [];
        $unreadable = [];
        foreach ($snapshots as $snapshot) {
            // A row the reader could not use only because its position text is invalid still proves the person is listed that month.
            foreach ($snapshot['issues'] ?? [] as $issue) {
                $issueNik = (string) ($issue['nik'] ?? '');
                if ($issueNik !== '' && ! isset($excluded[$issueNik]) && isset($seen[$issueNik])) {
                    $present[$issueNik][$snapshot['period']] = true;
                    $unreadable[$issueNik][] = $snapshot['period'];
                }
            }
            foreach ($snapshot['rows'] as $row) {
                $nik = (string) $row['nik'];
                if (isset($excluded[$nik])) {
                    continue;
                }
                $hitsBefore = $this->undecidedHits;
                $observation = $this->observe($row);
                $present[$nik][$snapshot['period']] = true;
                if ($this->undecidedHits > $hitsBefore) {
                    $pending[$nik] = true;
                }
                if ($observation !== null) {
                    $observations[$snapshot['period']][$nik] = $observation;
                } else {
                    $unreadable[$nik][] = $snapshot['period'];
                }
            }
        }
        foreach ($unreadable as $nik => $unreadablePeriods) {
            if (isset($pending[$nik])) {
                continue;
            }
            foreach ($periods as $index => $period) {
                $previous = $periods[$index - 1] ?? null;
                if (in_array($period, $unreadablePeriods, true) && $previous !== null && isset($observations[$previous][$nik])) {
                    $observations[$period][$nik] = $observations[$previous][$nik];
                }
            }
        }
        // 3. History segments, cut at each employee's earliest existing row.
        $rows = [];
        $info = ['diperkirakan' => [], 'celah' => [], 'keluar_daftar' => [], 'perpindahan_belum_tercatat' => []];
        foreach ($this->differ->diff($periods, $observations) as $nik => $result) {
            $nik = (string) $nik;
            if (isset($pending[$nik])) {
                continue;
            }
            $own = $histories->get($nik);
            $cutoff = $own->min(fn ($h) => $h->tmt_awal->toDateString());
            $personRows = [];
            $seenMain = false;
            $previousMain = null;

            foreach ($result['segments'] as $segment) {
                // A movement is only reported when the files themselves show a change: the first position a person is seen in is
                // their state, not a movement, so a name that merely differs from the application's row is not a follow-up.
                $changedInFiles = $seenMain && $segment['lane'] === CareerHistoryDiffer::LANE_MAIN;
                $seenMain = $seenMain || $segment['lane'] === CareerHistoryDiffer::LANE_MAIN;
                $before = $previousMain;
                if ($segment['lane'] === CareerHistoryDiffer::LANE_MAIN) {
                    $previousMain = $segment;
                }

                if ($segment['tmt_awal'] >= $cutoff) {
                    // Only a change of position or unit is a movement; a step in golongan class or a change of assignment status is not.
                    $isMovement = in_array($segment['perubahan'], ['PERUBAHAN_UNIT', 'PERUBAHAN_JABATAN'], true);
                    if ($changedInFiles && $isMovement && ! $this->recorded($own, $segment)) {
                        $info['perpindahan_belum_tercatat'][] = [
                            'nik' => $nik,
                            'periode' => $segment['dari_periode'],
                            'nama_disimpan' => $this->keptAsWritten($segment),
                            // What the files show before and after the change, and whether the application already holds the destination (its start date is then the question).
                            'dari' => $before === null ? '' : $before['jabatan'].' | '.$before['unit'],
                            'ke' => $segment['jabatan'].' | '.$segment['unit'],
                            'aplikasi_sudah' => $this->holdsPosition($own, $segment),
                        ];
                    }

                    continue;
                }
                $dayBeforeCutoff = (new DateTimeImmutable($cutoff))->modify('-1 day')->format('Y-m-d');
                $end = $segment['tmt_akhir'] === null || $segment['tmt_akhir'] >= $cutoff ? $dayBeforeCutoff : $segment['tmt_akhir'];
                $personRows[] = $this->row($nik, $segment, $end);
            }

            if ($this->startsBeforeHire($personRows, $hired->get($nik))) {
                $excluded[$nik] = ['reason' => self::EXCLUDED_BEFORE_HIRE, 'periode' => null, 'nama' => $fileNames[$nik] ?? ''];

                continue;
            }

            if (! $this->consistent($personRows)) {
                $excluded[$nik] = ['reason' => self::EXCLUDED_DATE_CONFLICT, 'periode' => null, 'nama' => $fileNames[$nik] ?? ''];

                continue;
            }

            // Mid-batch already-done: all segments fell >= $cutoff while overlapping an already-reconstructed bezetting period.
            if ($personRows === [] && $own->contains('sumber', JobHistory::SUMBER_BEZETTING) && ($result['first_seen'].'-01') >= $cutoff) {
                $excluded[$nik] = ['reason' => self::EXCLUDED_ALREADY_DONE, 'periode' => null, 'nama' => $fileNames[$nik] ?? ''];

                continue;
            }

            foreach ($personRows as $row) {
                if ($row['tmt_awal_diperkirakan']) {
                    $info['diperkirakan'][] = ['nik' => $nik, 'tmt_awal' => $row['tmt_awal']];
                }
            }
            foreach ($this->absentRanges($periods, $present[$nik] ?? [], $result['gaps']) as $gap) {
                $info['celah'][] = ['nik' => $nik, 'dari' => $gap['dari'], 'sampai' => $gap['sampai']];
            }
            if (! isset($present[$nik][end($periods)])) {
                $lastSeen = array_values(array_filter($periods, fn (string $period) => isset($present[$nik][$period])));
                $info['keluar_daftar'][] = ['nik' => $nik, 'terakhir_terlihat' => (string) end($lastSeen)];
            }
            array_push($rows, ...$personRows);
        }

        foreach ($excluded as $key => &$item) {
            $item['nama'] ??= $fileNames[(string) $key] ?? '';
        }
        unset($item);

        $blocking = array_values($this->blocking);
        usort($blocking, fn (array $a, array $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);
        $decided = array_values($this->decided);
        usort($decided, fn (array $a, array $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);

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
            'decided' => $decided,
            'excluded' => $excluded,
            'auto' => array_values($this->autoMatched),
            'info' => $info,
            'summary' => $summary,
            'digest' => hash('sha256', json_encode([$rows, $blocking], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Conflict-free legacy_nik_hash → 8-digit employee_nik pairs from dual-ID rows in $snapshots, restricted to
     * existing employees who are NOT excluded for identity/duplicate/format issues.
     *
     * @param  list<array{period: string, rows: list<array<string, mixed>>}>  $snapshots
     * @param  array<string, array{reason: string, periode: ?string}>  $excluded
     * @return array<string, string>
     */
    public function identitySafeLegacyPairs(array $snapshots, array $excluded = []): array
    {
        $unsafeReasons = [self::EXCLUDED_IDENTITY, self::EXCLUDED_DUPLICATE, self::EXCLUDED_NIK_FORMAT];
        $candidates = [];
        $conflicted = [];

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['rows'] as $row) {
                if (! empty($row['is_legacy_nik'])) {
                    continue;
                }
                $nik = (string) ($row['nik'] ?? '');
                $hash = (string) ($row['legacy_nik_hash'] ?? '');
                if ($hash === '' || strlen($nik) !== 8 || preg_match('/^\d{8}$/', $nik) !== 1) {
                    continue;
                }
                if (isset($excluded[$nik]) && in_array($excluded[$nik]['reason'], $unsafeReasons, true)) {
                    $conflicted[$hash] = true;

                    continue;
                }
                if (isset($candidates[$hash]) && $candidates[$hash] !== $nik) {
                    $conflicted[$hash] = true;

                    continue;
                }
                $candidates[$hash] = $nik;
            }
        }

        foreach (array_keys($conflicted) as $hash) {
            unset($candidates[$hash]);
        }

        if ($candidates === []) {
            return [];
        }

        $known = Employee::query()->whereIn('nik', array_values(array_unique($candidates)))->pluck('nik')->flip();

        return array_filter($candidates, fn (string $nik) => $known->has($nik));
    }

    /**
     * Combines persisted BezettingLegacyNikMap rows with conflict-free in-run dual-ID pairs.
     *
     * @param  list<array{period: string, rows: list<array<string, mixed>>}>  $snapshots
     * @return array<string, string>
     */
    private function buildCrosswalk(array $snapshots): array
    {
        $persisted = BezettingLegacyNikMap::query()->pluck('employee_nik', 'legacy_nik_hash')->map(fn ($n) => (string) $n)->all();
        $unsafeExcluded = $this->preDetectUnsafeDualIdNiks($snapshots);
        $inRun = $this->identitySafeLegacyPairs($snapshots, $unsafeExcluded);

        foreach ($inRun as $hash => $nik) {
            if (! isset($persisted[$hash])) {
                $persisted[$hash] = $nik;
            }
        }

        return $persisted;
    }

    /**
     * Detects duplicate-in-file or identity-mismatched 8-digit NIKs on non-legacy rows before Step 0 so suspect rows
     * never enter the in-run crosswalk.
     *
     * @param  list<array{period: string, rows: list<array<string, mixed>>}>  $snapshots
     * @return array<string, array{reason: string, periode: ?string}>
     */
    private function preDetectUnsafeDualIdNiks(array $snapshots): array
    {
        $unsafe = [];
        $identity = [];
        foreach ($snapshots as $snapshot) {
            $counts = [];
            foreach ($snapshot['rows'] as $row) {
                if (! empty($row['is_legacy_nik'])) {
                    continue;
                }
                $nik = (string) ($row['nik'] ?? '');
                if ($nik === '') {
                    continue;
                }
                $counts[$nik] = ($counts[$nik] ?? 0) + 1;
                $identity[$nik]['names'][] = self::nameWords((string) ($row['nama'] ?? ''));
                if (! empty($row['tanggal_lahir'])) {
                    $identity[$nik]['births'][(string) $row['tanggal_lahir']] = true;
                }
            }
            foreach ($counts as $nik => $c) {
                if (strlen($nik) !== 8) {
                    $unsafe[$nik] = ['reason' => self::EXCLUDED_NIK_FORMAT, 'periode' => $snapshot['period']];
                } elseif ($c > 1) {
                    $unsafe[$nik] = ['reason' => self::EXCLUDED_DUPLICATE, 'periode' => $snapshot['period']];
                }
            }
        }

        $births = $identity === [] ? collect() : Employee::query()->whereIn('nik', array_keys($identity))->pluck('tanggal_lahir', 'nik')->mapWithKeys(fn ($date, $n) => [(string) $n => $date?->toDateString()]);
        foreach ($identity as $nik => $facts) {
            if (isset($unsafe[$nik])) {
                continue;
            }
            $empBirth = $births->get($nik);
            if (self::namesDisagree($facts['names']) || count($facts['births'] ?? []) > 1
                || ($empBirth !== null && isset($facts['births']) && ! isset($facts['births'][$empBirth]))) {
                $unsafe[$nik] = ['reason' => self::EXCLUDED_IDENTITY, 'periode' => null];
            }
        }

        return $unsafe;
    }

    /**
     * The months of each difference-based gap in which the person really is missing from the file; months where the person is
     * listed but the names could not be read (undecided or skipped) are not gaps.
     *
     * @param  list<string>  $periods
     * @param  array<string, bool>  $present
     * @param  list<array{dari: string, sampai: string}>  $gaps
     * @return list<array{dari: string, sampai: string}>
     */
    private function absentRanges(array $periods, array $present, array $gaps): array
    {
        $ranges = [];
        foreach ($gaps as $gap) {
            $start = null;
            $last = null;
            foreach ($periods as $period) {
                $inGap = $period >= $gap['dari'] && $period <= $gap['sampai'];
                if ($inGap && ! isset($present[$period])) {
                    $start ??= $period;
                    $last = $period;
                } elseif ($start !== null) {
                    $ranges[] = ['dari' => $start, 'sampai' => $last];
                    $start = null;
                }
            }
            if ($start !== null) {
                $ranges[] = ['dari' => $start, 'sampai' => $last];
            }
        }

        return $ranges;
    }

    private function load(): void
    {
        $this->blocking = [];
        $this->decided = [];
        $this->autoMatched = [];
        $this->undecidedHits = 0;
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
            // As written in the file, in the file's own column order (JABATAN before Unit Kerja) and the same order as the
            // headline everywhere (Jabatan · Unit); an empty part is skipped rather than left as a dangling separator.
            'teks_sumber' => implode(' | ', array_filter([trim((string) $row['jabatan']), $unitText], fn (string $part) => $part !== '')),
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
        // Safe variants (case, punctuation, "&" versus "dan", doubled spaces) need no decision either: the name is the same one.
        // Anything that needs judgement (abbreviations, a "(K.SAWIT)" suffix) stays a decision for HR.
        if ($decision === null && $master !== []) {
            $target = PositionNameMatcher::match($text, array_values($master));
            if ($target !== null) {
                $this->autoMatched[$kind.'|'.$normalized] = ['kind' => $kind, 'text' => $normalized, 'target' => $target];

                return $target;
            }
        }
        // A status phrase can only be mapped to an official status; anything else is still undecided.
        if ($decision !== null && $kind === BezettingNameMapping::KIND_STATUS
            && ($decision->decision !== BezettingNameMapping::DECISION_MAP || ! in_array($decision->target_value, self::statuses(), true))) {
            $decision = null;
        }
        if ($decision === null) {
            $this->blocking[$kind.'|'.$normalized] ??= ['kind' => $kind, 'text' => $normalized, 'count' => 0];
            $this->blocking[$kind.'|'.$normalized]['count']++;
            $this->undecidedHits++;

            return null;
        }

        $dKey = $kind.'|'.$normalized;
        $this->decided[$dKey] ??= [
            'kind' => $kind,
            'text' => $normalized,
            'count' => 0,
            'decision' => $decision->decision,
            'target' => $decision->decision === BezettingNameMapping::DECISION_MAP ? (string) $decision->target_value : null,
        ];
        $this->decided[$dKey]['count']++;

        return match ($decision->decision) {
            BezettingNameMapping::DECISION_MAP => (string) $decision->target_value,
            BezettingNameMapping::DECISION_KEEP => trim((string) preg_replace('/\s+/u', ' ', $text)),
            default => null,
        };
    }

    /**
     * True when the application already records this position as of the segment start: the same position and unit, covering that date,
     * and starting no more than RECORDED_TOLERANCE_DAYS before it. A row that claims the position since long before the files show
     * the person entering it carries a stale start date, so the movement is still reported for HR to check.
     */
    private function recorded(Collection $own, array $segment): bool
    {
        $start = $segment['tmt_awal'];
        $earliest = (new DateTimeImmutable($start))->modify('-'.self::RECORDED_TOLERANCE_DAYS.' days')->format('Y-m-d');

        return $own->contains(fn ($h) => $h->tmt_awal->toDateString() <= $start
            && ($h->sumber === JobHistory::SUMBER_BEZETTING || $h->tmt_awal->toDateString() >= $earliest)
            && ($h->tmt_akhir === null || $h->tmt_akhir->toDateString() >= $start)
            && BezettingNameMapping::normalize((string) $h->jabatan) === BezettingNameMapping::normalize($segment['jabatan'])
            && BezettingNameMapping::normalize((string) $h->unit_kerja) === BezettingNameMapping::normalize($segment['unit']));
    }

    /** True when the application already has a history row for this position and unit, whatever its dates. */
    private function holdsPosition(Collection $own, array $segment): bool
    {
        return $own->contains(fn ($h) => BezettingNameMapping::normalize((string) $h->jabatan) === BezettingNameMapping::normalize($segment['jabatan'])
            && BezettingNameMapping::normalize((string) $h->unit_kerja) === BezettingNameMapping::normalize($segment['unit']));
    }

    /** True when the unit or position of a segment is a name kept as written (not a Master Formasi name), so a move to or from it may only be spelling. */
    private function keptAsWritten(array $segment): bool
    {
        return ! isset($this->units[BezettingNameMapping::normalize($segment['unit'])])
            || ! isset($this->positions[BezettingNameMapping::normalize($segment['jabatan'])]);
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
            'golongan' => Golongan::standard($segment['golongan']),
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

    /** Words of a name that identify a person: upper case letters, longer than two characters (initials and titles are ignored). @return list<string> */
    private static function nameWords(string $name): array
    {
        $words = preg_split('/[^A-Z]+/', mb_strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word) => strlen($word) > 2)));
    }

    /** True when two of the names met for one NIK share no word at all. @param  list<list<string>>  $names */
    private static function namesDisagree(array $names): bool
    {
        $names = array_values(array_filter($names, fn (array $words) => $words !== []));
        foreach ($names as $i => $first) {
            foreach (array_slice($names, $i + 1) as $second) {
                if (array_intersect($first, $second) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A position cannot start before the employee started working. An estimated start is only a month, so it is compared with the first day
     * of the hire month; an exact start is compared with the hire date itself.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function startsBeforeHire(array $rows, ?string $hired): bool
    {
        if ($hired === null) {
            return false;
        }
        foreach ($rows as $row) {
            $limit = $row['tmt_awal_diperkirakan'] ? substr($hired, 0, 7).'-01' : $hired;
            if ($row['tmt_awal'] < $limit) {
                return true;
            }
        }

        return false;
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
