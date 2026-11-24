<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUT: Admin JobHistoryController — manual edits of one history row. Covers the guard that keeps Bezetting rows closed and
 * without SK data (DOMAIN.md §5.5 rules 2 and 4).
 */
class JobHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bezettingRow(Employee $employee): JobHistory
    {
        return JobHistory::create([
            'employee_nik' => $employee->nik, 'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'status_penugasan' => 'Definitif',
            'tmt_awal' => '2024-01-01', 'tmt_akhir' => '2025-02-28', 'is_applied' => true, 'sumber' => JobHistory::SUMBER_BEZETTING,
        ]);
    }

    private function update(Employee $employee, JobHistory $row, array $data)
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('admin.employees.job-history.update', ['employee' => $employee->nik, 'job_history' => $row->id]), array_merge([
                'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'tmt_awal' => '2024-01-01', 'tmt_akhir' => '2025-02-28',
            ], $data));
    }

    public function test_a_bezetting_row_cannot_be_reopened_or_given_sk_data(): void
    {
        $employee = Employee::factory()->create();
        $row = $this->bezettingRow($employee);

        $this->update($employee, $row, ['tmt_akhir' => ''])->assertSessionHasErrors('tmt_akhir');
        $this->update($employee, $row, ['nomor_sk' => 'SK/123/2025'])->assertSessionHasErrors('nomor_sk');
        $this->update($employee, $row, ['jenis_mutasi' => 'PROMOSI'])->assertSessionHasErrors('jenis_mutasi');

        $row->refresh();
        $this->assertNotNull($row->tmt_akhir);
        $this->assertNull($row->nomor_sk);
        $this->assertNull($row->jenis_mutasi);
    }

    public function test_no_record_only_row_can_be_given_a_new_sk_number_or_date(): void
    {
        $employee = Employee::factory()->create(['jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu']);
        $initial = JobHistory::where('employee_nik', $employee->nik)->sole();
        $this->assertSame(JobHistory::SUMBER_DATA_AWAL, $initial->sumber);

        $this->update($employee, $initial, ['tmt_akhir' => '2025-02-28', 'nomor_sk' => 'SK/BARU/2025'])->assertSessionHasErrors('nomor_sk');
        $this->update($employee, $initial, ['tmt_akhir' => '2025-02-28', 'tanggal_sk' => '2025-01-15'])->assertSessionHasErrors('tanggal_sk');

        $this->assertNull($initial->fresh()->nomor_sk);
        $this->assertNull($initial->fresh()->tanggal_sk);
    }

    public function test_a_legacy_sk_value_on_a_record_only_row_is_kept_and_the_row_stays_editable(): void
    {
        $employee = Employee::factory()->create();
        $row = JobHistory::create([
            'employee_nik' => $employee->nik, 'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'status_penugasan' => 'Definitif',
            'tmt_awal' => '2024-01-01', 'tmt_akhir' => '2024-12-31', 'is_applied' => true, 'sumber' => JobHistory::SUMBER_IMPOR,
            'nomor_sk' => 'SK/LAMA/2024', 'tanggal_sk' => '2024-01-01',
        ]);

        $this->update($employee, $row, ['tmt_akhir' => '2024-12-31', 'nomor_sk' => 'SK/LAMA/2024', 'tanggal_sk' => '2024-01-01', 'catatan' => 'Koreksi catatan'])
            ->assertSessionHasNoErrors();
        $this->assertSame('SK/LAMA/2024', $row->fresh()->nomor_sk);
        $this->assertSame('Koreksi catatan', $row->fresh()->catatan);

        $this->update($employee, $row, ['tmt_akhir' => '2024-12-31', 'catatan' => 'Tanpa isian SK'])->assertSessionHasNoErrors();
        $this->assertSame('SK/LAMA/2024', $row->fresh()->nomor_sk, 'a form without SK inputs never erases the stored value');
    }

    public function test_an_official_mutation_row_can_still_record_its_sk(): void
    {
        $employee = Employee::factory()->create();
        $row = JobHistory::create([
            'employee_nik' => $employee->nik, 'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'status_penugasan' => 'Definitif',
            'tmt_awal' => '2024-01-01', 'tmt_akhir' => '2024-12-31', 'is_applied' => true, 'jenis_mutasi' => 'ROTASI',
        ]);

        $this->update($employee, $row, ['tmt_akhir' => '2024-12-31', 'jenis_mutasi' => 'ROTASI', 'nomor_sk' => 'SK/MUT/2024', 'tanggal_sk' => '2023-12-20'])
            ->assertSessionHasNoErrors();
        $this->assertSame('SK/MUT/2024', $row->fresh()->nomor_sk);
    }

    public function test_the_edit_form_explains_instead_of_offering_sk_fields_on_a_record_only_row(): void
    {
        $employee = Employee::factory()->create();
        $row = $this->bezettingRow($employee);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.employees.job-history.edit', ['employee' => $employee->nik, 'job_history' => $row->id]))
            ->assertOk()
            ->assertSee('Bezetting Historis: catatan data kepegawaian, bukan SK mutasi.')
            ->assertDontSee('name="nomor_sk"', false)
            ->assertDontSee('name="jenis_mutasi"', false)
            ->assertDontSee('DOMAIN.md');
    }

    public function test_a_bezetting_row_can_still_be_corrected_within_the_rules(): void
    {
        $employee = Employee::factory()->create();
        $row = $this->bezettingRow($employee);

        $this->update($employee, $row, ['jabatan' => 'Asisten Kepala Tanaman', 'catatan' => 'Koreksi nama jabatan oleh SDM'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Asisten Kepala Tanaman', $row->fresh()->jabatan);
        $this->assertSame(JobHistory::SUMBER_BEZETTING, $row->fresh()->sumber);
    }
}
