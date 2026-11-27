<?php

namespace Tests\Feature;

use App\Models\BezettingBackfillRun;
use App\Models\Employee;
use App\Models\JobHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUT: the JobHistory model — row sources, record-only rows and the Bezetting provenance fields (DOMAIN.md §2.2, §5.5).
 */
class JobHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function bezettingRow(Employee $employee, array $overrides = []): JobHistory
    {
        return JobHistory::create(array_merge([
            'employee_nik' => $employee->nik,
            'jabatan' => 'Asisten Afdeling I',
            'unit_kerja' => 'Kebun Gunung Meliau',
            'status_penugasan' => 'Definitif',
            'level' => 'Karpim',
            'golongan' => 'IIIA/03',
            'tmt_awal' => '2025-01-01',
            'tmt_akhir' => '2025-06-30',
            'is_applied' => true,
            'sumber' => JobHistory::SUMBER_BEZETTING,
            'jenis_perubahan_terdeteksi' => 'PENEMPATAN_AWAL',
            'teks_sumber' => 'Asisten Afdeling I | KEBUN INTI GUNUNG MELIAU (K.SAWIT)',
        ], $overrides));
    }

    public function test_bezetting_rows_are_record_only_with_a_business_label_and_no_mutation_type(): void
    {
        $row = $this->bezettingRow(Employee::factory()->create());

        $this->assertTrue($row->isRecordOnly());
        $this->assertSame('Bezetting Historis', $row->sumber_label);
        $this->assertNull($row->jenis_mutasi);
        $this->assertNull($row->nomor_sk);
        $this->assertNull($row->tanggal_sk);
        $this->assertSame('Awal Data Bezetting', $row->jenis_perubahan_terdeteksi_label, 'first month seen in the files, not a hiring');
    }

    public function test_source_filter_selects_bezetting_rows_and_the_official_view_excludes_them(): void
    {
        $employee = Employee::factory()->create();
        $row = $this->bezettingRow($employee);

        $this->assertSame([$row->id], JobHistory::bySumber(JobHistory::SUMBER_BEZETTING)->pluck('id')->all());
        $this->assertFalse(JobHistory::bySumber('resmi')->whereKey($row->id)->exists());
        $this->assertTrue(JobHistory::bySumber('semua')->whereKey($row->id)->exists());
    }

    public function test_closed_bezetting_rows_are_never_active_holders(): void
    {
        $employee = Employee::factory()->create();
        $row = $this->bezettingRow($employee);

        $this->assertFalse(JobHistory::active()->whereKey($row->id)->exists());
        $this->assertTrue(JobHistory::applied()->whereKey($row->id)->exists());
    }

    public function test_provenance_fields_are_stored_cast_and_linked_to_the_backfill_run(): void
    {
        $run = BezettingBackfillRun::create([
            'status' => BezettingBackfillRun::STATUS_COMPLETED, 'files' => [], 'period_from' => '2025-01', 'period_to' => '2025-01',
        ]);
        $row = $this->bezettingRow(Employee::factory()->create(), [
            'backfill_run_id' => $run->id,
            'tmt_awal_diperkirakan' => 1,
        ])->fresh();

        $this->assertTrue($row->tmt_awal_diperkirakan);
        $this->assertTrue($row->backfillRun->is($run));
        $this->assertTrue($run->jobHistories()->whereKey($row->id)->exists());
        $this->assertSame('Asisten Afdeling I | KEBUN INTI GUNUNG MELIAU (K.SAWIT)', $row->teks_sumber);
    }

    public function test_other_sources_keep_their_defaults_for_the_new_fields(): void
    {
        $employee = Employee::factory()->create(['jabatan' => 'Asisten Afdeling', 'unit_kerja' => 'Kebun Danau Salak']);
        $initial = JobHistory::where('employee_nik', $employee->nik)->sole();

        $this->assertSame(JobHistory::SUMBER_DATA_AWAL, $initial->sumber);
        $this->assertFalse($initial->tmt_awal_diperkirakan);
        $this->assertNull($initial->backfill_run_id);
        $this->assertNull($initial->jenis_perubahan_terdeteksi_label);
    }

    public function test_every_detected_change_type_has_a_business_label_distinct_from_mutation_types(): void
    {
        foreach (JobHistory::JENIS_PERUBAHAN_LABELS as $code => $label) {
            $this->assertNotSame('', $label);
            $this->assertNotContains($code, JobHistory::MUTASI_TYPES, "{$code} must not look like an official mutation type");
        }
    }
}
