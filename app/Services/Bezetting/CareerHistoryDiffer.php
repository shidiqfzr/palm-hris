<?php

namespace App\Services\Bezetting;

use DateTimeImmutable;

/**
 * Turns monthly snapshots into history segments per NIK (DOMAIN.md §5.5 rules 1 and 4). Pure: no database, no clock.
 *
 * Input per period ('Y-m', ascending) and NIK:
 *   main        => [jabatan, unit, status]   (names already resolved)
 *   concurrent  => list of [jabatan, unit, status, tmt?]
 *   golongan, tanggal_dalam_jabatan, tmt_unit_kerja (Y-m-d or null), teks_sumber
 *
 * Rules: a new main segment starts only when position, unit, status or golongan class changes (a periodic step
 * change such as IIIC/02 → IIIC/03 is not an event). The start date comes from the file when it falls between the
 * previous snapshot and the end of the month the change is seen; otherwise it is the first day of that month and is
 * marked estimated. A position no longer seen ends on the last day of the last month it was seen. Segments still
 * held in the last period stay open (`tmt_akhir` null); the planner closes them.
 */
class CareerHistoryDiffer
{
    public const LANE_MAIN = 'main';

    public const LANE_CONCURRENT = 'rangkap';

    /**
     * @param  list<string>  $periods
     * @param  array<string, array<string, array<string, mixed>>>  $snapshots  period => nik => observation
     * @return array<string, array{segments: list<array<string, mixed>>, gaps: list<array{dari: string, sampai: string}>,
     *     first_seen: string, last_seen: string, present_at_end: bool}>
     */
    public function diff(array $periods, array $snapshots): array
    {
        sort($periods);
        $niks = [];
        foreach ($periods as $period) {
            foreach (array_keys($snapshots[$period] ?? []) as $nik) {
                $niks[(string) $nik] = true;
            }
        }

        $result = [];
        foreach (array_keys($niks) as $nik) {
            $result[$nik] = $this->diffOne($periods, $snapshots, $nik);
        }

        return $result;
    }

    /** "IIIC/02" → "IIIC"; empty when unreadable. */
    public static function golonganClass(string $golongan): string
    {
        return preg_match('/^\s*([IVX]+)\s*([A-E])?/i', $golongan, $m) === 1
            ? mb_strtoupper($m[1].($m[2] ?? ''))
            : '';
    }

    private function diffOne(array $periods, array $snapshots, string $nik): array
    {
        $segments = [];
        $gaps = [];
        $openMain = null;
        /** @var array<string, int> $openConcurrent */
        $openConcurrent = [];
        $lastSeenIndex = null;
        $firstSeen = null;
        $lastMain = null;

        foreach ($periods as $i => $period) {
            $obs = $snapshots[$period][$nik] ?? null;
            $monthStart = $period.'-01';
            $monthEnd = $this->monthEnd($period);

            if ($obs === null) {
                if ($lastSeenIndex === $i - 1 && $lastSeenIndex !== null) {
                    $end = $this->monthEnd($periods[$lastSeenIndex]);
                    if ($openMain !== null) {
                        $segments[$openMain]['tmt_akhir'] = $end;
                        $openMain = null;
                    }
                    foreach ($openConcurrent as $idx) {
                        $segments[$idx]['tmt_akhir'] = $end;
                    }
                    $openConcurrent = [];
                }

                continue;
            }

            $firstSeen ??= $period;
            $returning = $lastSeenIndex !== null && $lastSeenIndex < $i - 1;
            if ($returning) {
                $gaps[] = ['dari' => $periods[$lastSeenIndex + 1], 'sampai' => $periods[$i - 1]];
            }
            // Lower bound for a start date read from the file: after the previous snapshot, or after the person was last
            // seen when they return. The very first period of the window has no lower bound (the true start may be older).
            $lowerBound = match (true) {
                $returning => $this->monthEnd($periods[$lastSeenIndex]),
                $i > 0 => $periods[$i - 1].'-01',
                default => null,
            };

            $main = $obs['main'];
            $golClass = self::golonganClass((string) ($obs['golongan'] ?? ''));
            $key = $this->key($main);

            if ($openMain !== null) {
                $prev = $segments[$openMain];
                $unitChanged = $this->norm($prev['unit']) !== $this->norm($main['unit']);
                $jabatanChanged = $this->norm($prev['jabatan']) !== $this->norm($main['jabatan']);
                $statusChanged = $prev['status'] !== $main['status'];
                $golChanged = $prev['golongan_kelas'] !== $golClass;

                if ($unitChanged || $jabatanChanged || $statusChanged || $golChanged) {
                    $candidate = match (true) {
                        $unitChanged => $obs['tmt_unit_kerja'] ?? $obs['tanggal_dalam_jabatan'] ?? null,
                        $jabatanChanged || $statusChanged => $obs['tanggal_dalam_jabatan'] ?? null,
                        default => null,
                    };
                    [$start, $estimated] = $this->start($candidate, $lowerBound, $monthStart, $monthEnd);
                    if ($start <= $prev['tmt_awal']) {
                        [$start, $estimated] = [$monthStart > $prev['tmt_awal'] ? $monthStart : $this->addDays($prev['tmt_awal'], 1), true];
                    }
                    $segments[$openMain]['tmt_akhir'] = $this->addDays($start, -1);
                    $code = match (true) {
                        $unitChanged => 'PERUBAHAN_UNIT',
                        $jabatanChanged => 'PERUBAHAN_JABATAN',
                        $statusChanged => 'PERUBAHAN_STATUS',
                        default => 'PERUBAHAN_GOLONGAN',
                    };
                    $segments[] = $this->segment(self::LANE_MAIN, $main, $obs, $golClass, $start, $estimated, $code, $period);
                    $openMain = array_key_last($segments);
                }
            } else {
                $code = $lastMain === null || $this->key($lastMain) === $key ? 'PENEMPATAN_AWAL' : $this->changeCode($lastMain, $main);
                [$start, $estimated] = $this->start($obs['tanggal_dalam_jabatan'] ?? null, $lowerBound, $monthStart, $monthEnd);
                $segments[] = $this->segment(self::LANE_MAIN, $main, $obs, $golClass, $start, $estimated, $code, $period);
                $openMain = array_key_last($segments);
            }
            $lastMain = $main;

            // Concurrent duties: one lane each, opened when first seen and closed when no longer seen.
            $present = [];
            foreach ($obs['concurrent'] ?? [] as $duty) {
                $dutyKey = $this->key($duty);
                $present[$dutyKey] = true;
                if (! isset($openConcurrent[$dutyKey])) {
                    [$start, $estimated] = $this->start($duty['tmt'] ?? null, $lowerBound, $monthStart, $monthEnd);
                    $segments[] = $this->segment(self::LANE_CONCURRENT, $duty, $obs, $golClass, $start, $estimated, 'TUGAS_RANGKAP', $period);
                    $openConcurrent[$dutyKey] = array_key_last($segments);
                }
            }
            foreach ($openConcurrent as $dutyKey => $idx) {
                if (! isset($present[$dutyKey])) {
                    $segments[$idx]['tmt_akhir'] = $this->monthEnd($periods[$i - 1]);
                    unset($openConcurrent[$dutyKey]);
                }
            }

            $lastSeenIndex = $i;
        }

        $lastIndex = array_key_last($periods);

        return [
            'segments' => array_values($segments),
            'gaps' => $gaps,
            'first_seen' => (string) $firstSeen,
            'last_seen' => (string) $periods[$lastSeenIndex],
            'present_at_end' => $lastSeenIndex === $lastIndex,
        ];
    }

    /** @return array{0: string, 1: bool} start date and whether it is estimated */
    private function start(?string $candidate, ?string $lowerBound, string $monthStart, string $monthEnd): array
    {
        if ($candidate !== null && $candidate !== '' && $candidate <= $monthEnd && ($lowerBound === null || $candidate > $lowerBound)) {
            return [$candidate, false];
        }

        return [$monthStart, true];
    }

    private function segment(string $lane, array $assignment, array $obs, string $golClass, string $start, bool $estimated, string $code, string $period): array
    {
        return [
            'lane' => $lane,
            'jabatan' => (string) $assignment['jabatan'],
            'unit' => (string) $assignment['unit'],
            'status' => (string) $assignment['status'],
            'golongan' => (string) ($obs['golongan'] ?? ''),
            'golongan_kelas' => $golClass,
            'tmt_awal' => $start,
            'tmt_akhir' => null,
            'diperkirakan' => $estimated,
            'perubahan' => $code,
            'teks_sumber' => (string) ($obs['teks_sumber'] ?? ''),
            'dari_periode' => $period,
        ];
    }

    private function changeCode(array $from, array $to): string
    {
        return match (true) {
            $this->norm($from['unit']) !== $this->norm($to['unit']) => 'PERUBAHAN_UNIT',
            $this->norm($from['jabatan']) !== $this->norm($to['jabatan']) => 'PERUBAHAN_JABATAN',
            default => 'PERUBAHAN_STATUS',
        };
    }

    private function key(array $assignment): string
    {
        return $this->norm($assignment['jabatan']).'|'.$this->norm($assignment['unit']).'|'.$assignment['status'];
    }

    private function norm(string $text): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private function monthEnd(string $period): string
    {
        return (new DateTimeImmutable($period.'-01'))->format('Y-m-t');
    }

    private function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '').$days.' day')->format('Y-m-d');
    }
}
