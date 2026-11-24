<?php

namespace Tests\Feature;

use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\Position;
use App\Models\Unit;
use App\Services\Bezetting\BackfillPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUT: BackfillPlanner — names, exclusions, existing-data-wins and closed rows (DOMAIN.md §5.5). Synthetic data only.
 */
class BackfillPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Kebun Parindu', 'Kebun Gunung Meliau', 'Bagian Tanaman'] as $unit) {
            Unit::factory()->create(['nama' => $unit]);
        }
        foreach (['Asisten Afdeling I', 'Asisten Kepala', 'Manajer Kebun', 'Kepala Bagian Tanaman', 'Asisten Penggajian'] as $position) {
            Position::factory()->create(['nama' => $position]);
        }
    }

    private function planner(): BackfillPlanner
    {
        return app(BackfillPlanner::class);
    }

    /** An employee whose application history starts on $since in $jabatan at $unit (the `data_awal` row). */
    private function employee(string $nik, string $jabatan, string $since, string $unit = 'Kebun Parindu'): Employee
    {
        return Employee::factory()->create([
            'nik' => $nik, 'jabatan' => $jabatan, 'unit_kerja' => $unit,
            'tanggal_dalam_jabatan' => $since, 'tmt_unit_kerja' => $since,
        ]);
    }

    private function row(string $nik, string $jabatan, string $unit = 'Kebun Parindu', array $extra = []): array
    {
        return array_merge([
            'row' => 10, 'nik' => $nik, 'nama' => 'Karyawan Contoh', 'jabatan' => $jabatan, 'unit' => $unit,
            'unit_dari_judul_bagian' => false, 'golongan' => 'IIIC/02', 'tanggal_dalam_jabatan' => '2023-04-01',
            'tmt_unit_kerja' => '2023-04-01', 'tanggal_lahir' => null, 'tanggal_pensiun' => null,
        ], $extra);
    }

    /** @param array<string, list<array>> $byPeriod */
    private function snapshots(array $byPeriod): array
    {
        return array_map(fn ($period, $rows) => ['period' => $period, 'rows' => $rows], array_keys($byPeriod), $byPeriod);
    }

    public function test_only_time_before_the_earliest_existing_row_is_written_and_every_row_is_closed(): void
    {
        $this->employee('99000001', 'Manajer Kebun', '2025-03-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000001', 'Asisten Kepala')],
            '2025-02' => [$this->row('99000001', 'Asisten Kepala')],
            '2025-03' => [$this->row('99000001', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2025-03-01'])],
        ]));

        $this->assertSame([], $plan['blocking']);
        $this->assertCount(1, $plan['rows']);
        $row = $plan['rows'][0];
        $this->assertSame('Asisten Kepala', $row['jabatan']);
        $this->assertSame('2023-04-01', $row['tmt_awal']);
        $this->assertSame('2025-02-28', $row['tmt_akhir'], 'closes the day before the existing data_awal row');
        $this->assertSame(JobHistory::SUMBER_BEZETTING, $row['sumber']);
        $this->assertNull($row['nomor_sk']);
        $this->assertNull($row['tanggal_sk']);
        $this->assertNull($row['jenis_mutasi']);
        $this->assertSame('Karpim', $row['level']);
        $this->assertSame([], $plan['info']['perpindahan_belum_tercatat'], 'March matches what the application records');
    }

    public function test_a_movement_after_the_earliest_existing_row_is_reported_and_never_written(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2024-01-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-02' => [$this->row('99000002', 'Asisten Kepala', extra: ['tanggal_dalam_jabatan' => '2025-02-01'])],
        ]));

        $this->assertSame([], $plan['rows']);
        $this->assertSame([['nik' => '99000002', 'periode' => '2025-02']], $plan['info']['perpindahan_belum_tercatat']);
    }

    public function test_undecided_names_block_until_hr_maps_keeps_or_skips_them(): void
    {
        $this->employee('99000003', 'Manajer Kebun', '2026-01-01', 'Kebun Gunung Meliau');
        $snapshots = $this->snapshots(['2025-01' => [$this->row('99000003', 'Asisten Kepala', 'KEBUN INTI GUNUNG MELIAU (K.SAWIT)')]]);

        $blocked = $this->planner()->plan($snapshots);
        $this->assertSame([['kind' => 'unit', 'text' => 'KEBUN INTI GUNUNG MELIAU (K.SAWIT)', 'count' => 1]], $blocked['blocking']);
        $this->assertSame([], $blocked['rows']);

        $decision = BezettingNameMapping::create([
            'kind' => BezettingNameMapping::KIND_UNIT, 'source_text' => 'KEBUN INTI GUNUNG MELIAU (K.SAWIT)',
            'decision' => BezettingNameMapping::DECISION_MAP, 'target_value' => 'Kebun Gunung Meliau',
        ]);
        $mapped = $this->planner()->plan($snapshots);
        $this->assertSame([], $mapped['blocking']);
        $this->assertSame('Kebun Gunung Meliau', $mapped['rows'][0]['unit_kerja']);
        $this->assertSame('KEBUN INTI GUNUNG MELIAU (K.SAWIT) | Asisten Kepala', $mapped['rows'][0]['teks_sumber']);
        $this->assertNotSame($blocked['digest'], $mapped['digest']);

        $decision->update(['decision' => BezettingNameMapping::DECISION_KEEP, 'target_value' => null]);
        $this->assertSame('KEBUN INTI GUNUNG MELIAU (K.SAWIT)', $this->planner()->plan($snapshots)['rows'][0]['unit_kerja']);

        $decision->update(['decision' => BezettingNameMapping::DECISION_SKIP]);
        $this->assertSame([], $this->planner()->plan($snapshots)['rows']);
    }

    public function test_person_level_problems_exclude_the_person_without_blocking_the_run(): void
    {
        $this->employee('99000004', 'Manajer Kebun', '2026-01-01');
        $done = $this->employee('99000005', 'Manajer Kebun', '2026-01-01');
        JobHistory::create([
            'employee_nik' => $done->nik, 'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'status_penugasan' => 'Definitif',
            'tmt_awal' => '2023-01-01', 'tmt_akhir' => '2025-12-31', 'is_applied' => true, 'sumber' => JobHistory::SUMBER_BEZETTING,
        ]);

        $plan = $this->planner()->plan($this->snapshots(['2025-01' => [
            $this->row('9900272', 'Asisten Kepala', 'UNIT TIDAK DIKENAL A'),
            $this->row('99000004', 'Asisten Kepala'),
            $this->row('99000004', 'Asisten Afdeling I'),
            $this->row('99000006', 'Asisten Kepala', 'UNIT TIDAK DIKENAL B'),
            $this->row('99000005', 'Asisten Kepala'),
        ]]));

        $this->assertSame([], $plan['blocking'], 'names of excluded people are never asked');
        $this->assertSame([], $plan['rows']);
        $this->assertSame(BackfillPlanner::EXCLUDED_NIK_FORMAT, $plan['excluded']['9900272']['reason']);
        $this->assertSame(BackfillPlanner::EXCLUDED_DUPLICATE, $plan['excluded']['99000004']['reason']);
        $this->assertSame(BackfillPlanner::EXCLUDED_NOT_IN_APP, $plan['excluded']['99000006']['reason']);
        $this->assertSame(BackfillPlanner::EXCLUDED_ALREADY_DONE, $plan['excluded']['99000005']['reason']);
        $this->assertSame(4, $plan['summary']['karyawan_dikecualikan']);
    }

    public function test_a_status_phrase_is_written_only_after_it_is_mapped_to_an_official_status(): void
    {
        $this->employee('99000007', 'Kepala Bagian Tanaman', '2026-01-01', 'Bagian Tanaman');
        $snapshots = $this->snapshots(['2025-01' => [$this->row('99000007', 'Penugasan sebagai Kepala Bagian Tanaman', 'Bagian Tanaman')]]);

        $this->assertSame('status', $this->planner()->plan($snapshots)['blocking'][0]['kind']);

        $decision = BezettingNameMapping::create([
            'kind' => BezettingNameMapping::KIND_STATUS, 'source_text' => 'PENUGASAN SEBAGAI', 'decision' => BezettingNameMapping::DECISION_KEEP,
        ]);
        $this->assertCount(1, $this->planner()->plan($snapshots)['blocking'], 'a status phrase cannot be kept as written');

        $decision->update(['decision' => BezettingNameMapping::DECISION_MAP, 'target_value' => Employee::STATUS_PJS]);
        $plan = $this->planner()->plan($snapshots);
        $this->assertSame([], $plan['blocking']);
        $this->assertSame(Employee::STATUS_PJS, $plan['rows'][0]['status_penugasan']);
        $this->assertSame('Kepala Bagian Tanaman', $plan['rows'][0]['jabatan']);
    }

    public function test_concurrent_duties_are_separate_closed_rows_and_leaving_the_list_is_reported(): void
    {
        $this->employee('99000008', 'Asisten Kepala', '2026-01-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000008', 'Asisten Afdeling I merangkap Plt. Asisten Penggajian')],
            '2025-02' => [$this->row('99000008', 'Asisten Afdeling I')],
            '2025-03' => [],
        ]));

        $this->assertSame([], $plan['blocking']);
        $this->assertCount(2, $plan['rows']);
        [$main, $duty] = $plan['rows'];
        $this->assertSame('Definitif', $main['status_penugasan']);
        $this->assertSame('2025-02-28', $main['tmt_akhir']);
        $this->assertSame('Asisten Penggajian', $duty['jabatan']);
        $this->assertSame(Employee::STATUS_PLT, $duty['status_penugasan']);
        $this->assertSame('TUGAS_RANGKAP', $duty['jenis_perubahan_terdeteksi']);
        $this->assertSame('2025-01-31', $duty['tmt_akhir']);
        $this->assertSame([['nik' => '99000008', 'terakhir_terlihat' => '2025-02']], $plan['info']['keluar_daftar']);
    }

    public function test_the_digest_is_stable_for_the_same_input(): void
    {
        $this->employee('99000009', 'Manajer Kebun', '2026-01-01');
        $snapshots = $this->snapshots(['2025-01' => [$this->row('99000009', 'Asisten Kepala')]]);

        $this->assertSame($this->planner()->plan($snapshots)['digest'], $this->planner()->plan($snapshots)['digest']);
    }
}
