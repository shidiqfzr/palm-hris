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
