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
        return Employee::factory()->create(['tmt_bekerja' => '2000-01-01',
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

    public function test_the_file_text_keeps_the_jabatan_then_unit_order_and_skips_an_empty_part(): void
    {
        $this->employee('99000004', 'Manajer Kebun', '2025-03-01');

        $plan = $this->planner()->plan($this->snapshots(['2025-01' => [$this->row('99000004', 'Asisten Kepala')], '2025-02' => [$this->row('99000004', 'Asisten Kepala')]]));

        $this->assertSame('Asisten Kepala | Kebun Parindu', $plan['rows'][0]['teks_sumber'], 'same order as the headline (Jabatan · Unit) and the file columns');
    }

    public function test_golongan_is_written_in_the_standard_format_with_a_two_digit_step(): void
    {
        $this->employee('99000003', 'Manajer Kebun', '2025-03-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000003', 'Asisten Kepala', extra: ['golongan' => 'ivb / 3'])],
            '2025-02' => [$this->row('99000003', 'Asisten Kepala', extra: ['golongan' => 'IVB/3'])],
        ]));

        $this->assertSame('IVB/03', $plan['rows'][0]['golongan'], 'the shared Golongan normaliser is applied (tested in GolonganTest)');
    }

    public function test_a_movement_after_the_earliest_existing_row_is_reported_and_never_written(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2024-01-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-02' => [$this->row('99000002', 'Asisten Kepala', extra: ['tanggal_dalam_jabatan' => '2025-02-01'])],
        ]));

        $this->assertSame([], $plan['rows']);
        $this->assertSame([['nik' => '99000002', 'periode' => '2025-02', 'nama_disimpan' => false, 'dari' => 'Manajer Kebun | Kebun Parindu', 'ke' => 'Asisten Kepala | Kebun Parindu', 'aplikasi_sudah' => false]], $plan['info']['perpindahan_belum_tercatat']);
    }

    public function test_a_position_that_never_changes_in_the_files_is_not_a_movement_even_when_its_name_differs_from_the_application(): void
    {
        // The application holds a different wording of the same post, but the files show one unchanged position for every month.
        $this->employee('99000002', 'Manajer Kebun Lama', '2024-01-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun')],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun')],
            '2025-03' => [$this->row('99000002', 'Manajer Kebun')],
        ]));

        $this->assertSame([], $plan['info']['perpindahan_belum_tercatat']);
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
        $this->assertSame('Asisten Kepala | KEBUN INTI GUNUNG MELIAU (K.SAWIT)', $mapped['rows'][0]['teks_sumber']);
        $this->assertNotSame($blocked['digest'], $mapped['digest']);

        $decision->update(['decision' => BezettingNameMapping::DECISION_KEEP, 'target_value' => null]);
        $this->assertSame('KEBUN INTI GUNUNG MELIAU (K.SAWIT)', $this->planner()->plan($snapshots)['rows'][0]['unit_kerja']);

        $decision->update(['decision' => BezettingNameMapping::DECISION_SKIP]);
        $this->assertSame([], $this->planner()->plan($snapshots)['rows']);
    }

    public function test_a_person_listed_every_month_is_never_a_gap_or_an_exit_while_a_name_is_still_undecided(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2024-01-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun')],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun', 'KEBUN BARU (BELUM DIPUTUSKAN)')],
            '2025-03' => [$this->row('99000002', 'Manajer Kebun', 'KEBUN BARU (BELUM DIPUTUSKAN)')],
        ]));

        $this->assertNotSame([], $plan['blocking']);
        $this->assertSame([], $plan['info']['celah']);
        $this->assertSame([], $plan['info']['keluar_daftar']);
    }

    public function test_one_nik_carrying_two_different_people_is_excluded_instead_of_merging_their_histories(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-03-01');
        $this->employee('99000003', 'Manajer Kebun', '2025-03-01');
        $this->employee('99000004', 'Manajer Kebun', '2025-03-01');

        $plan = $this->planner()->plan($this->snapshots([
            // 2: the name changes completely in the second file. 3: same name, two different birth dates. 4: a name written with an initial and a title.
            '2025-01' => [
                $this->row('99000002', 'Manajer Kebun', extra: ['nama' => 'Budi Santoso']),
                $this->row('99000003', 'Manajer Kebun', extra: ['nama' => 'Sari Dewi', 'tanggal_lahir' => '1980-01-01']),
                $this->row('99000004', 'Manajer Kebun', extra: ['nama' => 'Ir. Agus Salim']),
            ],
            '2025-02' => [
                $this->row('99000002', 'Manajer Kebun', extra: ['nama' => 'Anton Ardiyanto']),
                $this->row('99000003', 'Manajer Kebun', extra: ['nama' => 'Sari Dewi', 'tanggal_lahir' => '1990-05-26']),
                $this->row('99000004', 'Manajer Kebun', extra: ['nama' => 'Agus Salim, S.T.']),
            ],
        ]));

        $this->assertSame(BackfillPlanner::EXCLUDED_IDENTITY, $plan['excluded']['99000002']['reason']);
        $this->assertSame(BackfillPlanner::EXCLUDED_IDENTITY, $plan['excluded']['99000003']['reason']);
        $this->assertArrayNotHasKey('99000004', $plan['excluded'], 'a title or an initial is not another person');
    }

    public function test_a_birth_date_that_differs_from_the_employee_record_excludes_the_nik(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-03-01')->update(['tanggal_lahir' => '1984-09-15']);

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_lahir' => '1990-05-26'])],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_lahir' => '1990-05-26'])],
        ]));

        $this->assertSame(BackfillPlanner::EXCLUDED_IDENTITY, $plan['excluded']['99000002']['reason']);
    }

    public function test_history_that_starts_before_the_employee_started_working_is_excluded(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-07-01')->update(['tmt_bekerja' => '2025-07-01']);
        $this->employee('99000003', 'Manajer Kebun', '2025-07-01')->update(['tmt_bekerja' => '2025-01-15']);

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => null]), $this->row('99000003', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => null])],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => null]), $this->row('99000003', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => null])],
        ]));

        $this->assertSame(BackfillPlanner::EXCLUDED_BEFORE_HIRE, $plan['excluded']['99000002']['reason']);
        // Hired mid-January: an estimated start (1 January) is only a month and is not held against the hire date.
        $this->assertArrayNotHasKey('99000003', $plan['excluded']);
        $this->assertContains('99000003', array_column($plan['rows'], 'employee_nik'));
    }

    public function test_a_step_in_golongan_or_a_change_of_status_is_not_reported_as_a_movement(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2024-01-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun', extra: ['golongan' => 'IIIC/02', 'tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun', extra: ['golongan' => 'IIID/01', 'tanggal_dalam_jabatan' => '2024-01-01'])],
        ]));

        $this->assertSame([], $plan['info']['perpindahan_belum_tercatat']);
    }

    public function test_a_skipped_typo_row_in_one_month_is_neither_a_gap_nor_a_break_in_the_history(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-06-01');
        // March reads "f" for the position; HR skipped that text as invalid. The person is listed every month.
        BezettingNameMapping::create(['kind' => BezettingNameMapping::KIND_JABATAN, 'source_text' => 'F', 'decision' => BezettingNameMapping::DECISION_SKIP, 'target_value' => null]);

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-03' => [$this->row('99000002', 'f', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-04' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
        ]));

        $this->assertSame([], $plan['info']['celah']);
        $this->assertSame([], $plan['info']['keluar_daftar']);
        $this->assertCount(1, $plan['rows'], 'one unbroken period, not two pieces around the skipped month');
        $this->assertSame('Manajer Kebun', $plan['rows'][0]['jabatan']);
    }

    public function test_a_person_with_a_name_still_undecided_is_left_out_of_history_and_follow_ups_until_decided(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-06-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun')],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun', 'KEBUN BARU (BELUM DIPUTUSKAN)')],
            '2025-03' => [$this->row('99000002', 'Manajer Kebun')],
        ]));

        $this->assertNotSame([], $plan['blocking']);
        $this->assertSame([], $plan['rows']);
        $this->assertSame([], $plan['info']['perpindahan_belum_tercatat']);
        $this->assertSame([], $plan['info']['celah']);
    }

    public function test_a_row_unreadable_only_for_its_position_text_keeps_the_person_present_that_month(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-06-01');
        $snapshots = $this->snapshots([
            '2025-01' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-02' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
            '2025-04' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2024-01-01'])],
        ]);
        // March: the reader dropped the row because its position was a single letter, but the NIK was valid.
        $snapshots[] = ['period' => '2025-03', 'rows' => [], 'issues' => [['row' => 12, 'code' => 'jabatan_tidak_valid', 'nik' => '99000002']]];

        $plan = $this->planner()->plan($snapshots);

        $this->assertSame([], $plan['info']['celah'], 'listed in March, so not a gap');
        $this->assertCount(1, $plan['rows']);
        $this->assertSame('2025-05-31', $plan['rows'][0]['tmt_akhir']);
    }

    public function test_a_movement_into_a_position_the_application_dates_long_before_is_still_reported(): void
    {
        // The application says "Manajer Kebun" since 2024-01-01, but the files show the person entering it only in March 2025.
        $this->employee('99000002', 'Manajer Kebun', '2024-01-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Asisten Kepala', extra: ['tanggal_dalam_jabatan' => '2023-04-01'])],
            '2025-02' => [$this->row('99000002', 'Asisten Kepala', extra: ['tanggal_dalam_jabatan' => '2023-04-01'])],
            '2025-03' => [$this->row('99000002', 'Manajer Kebun', extra: ['tanggal_dalam_jabatan' => '2025-03-01'])],
        ]));

        $this->assertSame([['nik' => '99000002', 'periode' => '2025-03', 'nama_disimpan' => false, 'dari' => 'Asisten Kepala | Kebun Parindu', 'ke' => 'Manajer Kebun | Kebun Parindu', 'aplikasi_sudah' => true]], $plan['info']['perpindahan_belum_tercatat']);
    }

    public function test_a_spelling_variant_of_a_master_name_is_matched_without_a_decision_but_an_abbreviation_is_not(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-03-01');

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'ASISTEN  KEPALA', 'KEBUN PARINDU')],
            '2025-02' => [$this->row('99000002', 'Asisten Kepala', 'Kebun Parindu')],
            '2025-03' => [$this->row('99000002', 'Manajer Kebun', 'Kebun Parindu', ['tanggal_dalam_jabatan' => '2025-03-01'])],
        ]));
        $this->assertSame([], $plan['blocking']);

        $abbreviated = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000002', 'Asisten Kepala', 'Kbn Parindu')],
        ]));
        $this->assertSame([['kind' => 'unit', 'text' => 'KBN PARINDU', 'count' => 1]], $abbreviated['blocking']);
    }

    public function test_a_movement_involving_a_name_kept_as_written_is_flagged_for_a_spelling_check(): void
    {
        $this->employee('99000002', 'Manajer Kebun', '2025-03-01');
        BezettingNameMapping::create(['kind' => 'unit', 'source_text' => 'Kbn Parindu', 'decision' => BezettingNameMapping::DECISION_KEEP]);

        $plan = $this->planner()->plan($this->snapshots([
            '2025-04' => [$this->row('99000002', 'Manajer Kebun', 'Kebun Parindu', ['tanggal_dalam_jabatan' => '2025-03-01'])],
            '2025-05' => [$this->row('99000002', 'Manajer Kebun', 'Kbn Parindu', ['tanggal_dalam_jabatan' => '2025-03-01', 'tmt_unit_kerja' => '2025-05-01'])],
        ]));

        $this->assertTrue($plan['info']['perpindahan_belum_tercatat'][0]['nama_disimpan']);
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

    public function test_progressive_cutoff_fills_older_year_before_existing_bezetting_row(): void
    {
        $emp = $this->employee('99000020', 'Manajer Kebun', '2026-07-01');
        JobHistory::create([
            'employee_nik' => $emp->nik, 'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'status_penugasan' => 'Definitif',
            'tmt_awal' => '2026-01-01', 'tmt_akhir' => '2026-06-30', 'is_applied' => true, 'sumber' => JobHistory::SUMBER_BEZETTING,
        ]);

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000020', 'Asisten Afdeling I', extra: ['tanggal_dalam_jabatan' => '2024-06-01'])],
            '2025-12' => [$this->row('99000020', 'Asisten Afdeling I', extra: ['tanggal_dalam_jabatan' => '2024-06-01'])],
        ]));

        $this->assertArrayNotHasKey('99000020', $plan['excluded']);
        $this->assertCount(1, $plan['rows']);
        $this->assertSame('2024-06-01', $plan['rows'][0]['tmt_awal']);
        $this->assertSame('2025-12-31', $plan['rows'][0]['tmt_akhir']);
    }

    public function test_excludes_already_done_only_when_batch_has_no_period_before_earliest_bezetting_row(): void
    {
        $emp = $this->employee('99000021', 'Manajer Kebun', '2026-07-01');
        JobHistory::create([
            'employee_nik' => $emp->nik, 'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'status_penugasan' => 'Definitif',
            'tmt_awal' => '2025-01-01', 'tmt_akhir' => '2026-06-30', 'is_applied' => true, 'sumber' => JobHistory::SUMBER_BEZETTING,
        ]);

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000021', 'Asisten Kepala')],
            '2025-12' => [$this->row('99000021', 'Asisten Kepala')],
        ]));

        $this->assertSame(BackfillPlanner::EXCLUDED_ALREADY_DONE, $plan['excluded']['99000021']['reason']);
        $this->assertSame([], $plan['rows']);
    }

    public function test_resolves_legacy_only_rows_via_persisted_and_in_run_crosswalk_and_excludes_unmapped_or_conflicting(): void
    {
        $persistedEmp = $this->employee('99000022', 'Manajer Kebun', '2026-01-01');
        $inRunEmp = $this->employee('99000023', 'Manajer Kebun', '2026-01-01');
        $conflictA = $this->employee('99000024', 'Manajer Kebun', '2026-01-01');
        $conflictB = $this->employee('99000025', 'Manajer Kebun', '2026-01-01');

        $hashPersisted = \App\Models\BezettingLegacyNikMap::hashFromRaw('000.0716.0001.0001');
        $hashInRun = \App\Models\BezettingLegacyNikMap::hashFromRaw('000.0716.0002.0002');
        $hashUnmapped = \App\Models\BezettingLegacyNikMap::hashFromRaw('000.0716.0003.0003');
        $hashConflict = \App\Models\BezettingLegacyNikMap::hashFromRaw('000.0716.0004.0004');

        \App\Models\BezettingLegacyNikMap::create([
            'legacy_nik_hash' => $hashPersisted,
            'employee_nik' => $persistedEmp->nik,
        ]);

        $plan = $this->planner()->plan($this->snapshots([
            '2022-09' => [
                $this->row('', 'Asisten Kepala', extra: ['is_legacy_nik' => true, 'legacy_nik_hash' => $hashPersisted, 'tanggal_dalam_jabatan' => '2021-01-01']),
                $this->row('', 'Asisten Afdeling I', extra: ['is_legacy_nik' => true, 'legacy_nik_hash' => $hashInRun, 'tanggal_dalam_jabatan' => '2021-02-01']),
                $this->row('', 'Asisten Kepala', extra: ['is_legacy_nik' => true, 'legacy_nik_hash' => $hashUnmapped, 'tanggal_dalam_jabatan' => '2021-03-01']),
                $this->row('', 'Asisten Kepala', extra: ['is_legacy_nik' => true, 'legacy_nik_hash' => $hashConflict, 'tanggal_dalam_jabatan' => '2021-04-01']),
            ],
            '2022-10' => [
                $this->row($inRunEmp->nik, 'Asisten Afdeling I', extra: ['is_legacy_nik' => false, 'legacy_nik_hash' => $hashInRun, 'tanggal_dalam_jabatan' => '2021-02-01']),
                $this->row($conflictA->nik, 'Asisten Kepala', extra: ['is_legacy_nik' => false, 'legacy_nik_hash' => $hashConflict, 'tanggal_dalam_jabatan' => '2021-04-01']),
                $this->row($conflictB->nik, 'Asisten Kepala', extra: ['is_legacy_nik' => false, 'legacy_nik_hash' => $hashConflict, 'tanggal_dalam_jabatan' => '2021-04-01']),
            ],
        ]));

        $writtenNiks = array_column($plan['rows'], 'employee_nik');
        $this->assertContains('99000022', $writtenNiks);
        $this->assertContains('99000023', $writtenNiks);
        $this->assertSame(BackfillPlanner::EXCLUDED_LEGACY_UNMAPPED, $plan['excluded']['L:'.substr($hashUnmapped, 0, 8)]['reason']);
        $this->assertSame(BackfillPlanner::EXCLUDED_LEGACY_UNMAPPED, $plan['excluded']['L:'.substr($hashConflict, 0, 8)]['reason']);
    }

    public function test_returns_decided_mappings_actively_applied_to_run(): void
    {
        $this->employee('99000026', 'Manajer Kebun', '2026-01-01');
        BezettingNameMapping::create(['kind' => 'unit', 'source_text' => 'KBN PARINDU', 'decision' => BezettingNameMapping::DECISION_MAP, 'target_value' => 'Kebun Parindu']);
        BezettingNameMapping::create(['kind' => 'jabatan', 'source_text' => 'ASISTEN LAMA', 'decision' => BezettingNameMapping::DECISION_KEEP]);
        // Unused decision should not appear in this run's decided list.
        BezettingNameMapping::create(['kind' => 'unit', 'source_text' => 'UNIT TIDAK MUNCUL', 'decision' => BezettingNameMapping::DECISION_KEEP]);

        $plan = $this->planner()->plan($this->snapshots([
            '2025-01' => [$this->row('99000026', 'Asisten Lama', 'Kbn Parindu')],
            '2025-02' => [$this->row('99000026', 'Asisten Lama', 'Kbn Parindu')],
        ]));

        $this->assertSame([], $plan['blocking']);
        $this->assertSame([
            ['kind' => 'jabatan', 'text' => 'ASISTEN LAMA', 'count' => 2, 'decision' => 'keep', 'target' => null],
            ['kind' => 'unit', 'text' => 'KBN PARINDU', 'count' => 2, 'decision' => 'map', 'target' => 'Kebun Parindu'],
        ], $plan['decided']);
    }
}
