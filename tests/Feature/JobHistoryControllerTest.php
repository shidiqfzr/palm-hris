<?php

namespace Tests\Feature;

use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\MasterAuditLog;
use App\Models\Position;
use App\Models\StructureDecree;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUT: Admin JobHistoryController — manual correction of Riwayat Karir through JobHistoryCorrectionService: closed rows
 * only (moving an employee is Mutasi's job, AGENTS.md rule 8), standard golongan, level from the RM Band, no overlapping
 * definitive periods, a reason on every change recorded on master_audit_logs, and record-only rows never given an SK
 * (DOMAIN.md §2.2, §5.5). Employees start their current position on 2025-03-01 so periods are predictable.
 */
class JobHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Koreksi sesuai dokumen kepegawaian';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        // Master Formasi the form picks from: two positions in Kebun Parindu's formasi, one Regional Office position that
        // is not. 'Asisten Kepala' is left out on purpose (an old name rows may hold).
        $kebun = Unit::factory()->create(['nama' => 'Kebun Parindu']);
        $office = Unit::factory()->create(['nama' => 'Bagian SDM']);
        foreach (['Asisten Kepala Tanaman', 'Asisten Afdeling I'] as $position) {
            $kebun->positions()->attach(Position::factory()->create(['nama' => $position, 'rm_level' => null]), ['kuota' => 1]);
        }
        $office->positions()->attach(Position::factory()->create(['nama' => 'Kepala Sub Bagian SDM', 'rm_level' => null]), ['kuota' => 1]);
    }

    public function test_the_position_must_belong_to_the_unit_formasi_on_the_start_date(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

        $this->update($employee, $row, ['jabatan' => 'Kepala Sub Bagian SDM'])->assertSessionHasErrors('jabatan');
        $this->assertSame('Asisten Kepala', $row->fresh()->jabatan);
        $this->update($employee, $row, ['jabatan' => 'Kepala Sub Bagian SDM', 'unit_kerja' => 'Bagian SDM'])->assertSessionHasNoErrors();
        $this->assertSame('Bagian SDM', $row->fresh()->unit_kerja);
    }

    public function test_an_old_name_outside_the_master_is_a_recorded_exception_before_the_first_structure_sk_only(): void
    {
        $employee = $this->employee();

        $this->store($employee, ['nama_di_luar_master' => '1', 'jabatan' => 'Sinder Kebun', 'unit_kerja' => 'Kebun Parindu'])->assertSessionHasNoErrors();
        $row = JobHistory::where('jabatan', 'Sinder Kebun')->sole();
        $this->assertSame('Kebun Parindu', $row->unit_kerja);
        $decision = BezettingNameMapping::decisionFor('jabatan', 'Sinder Kebun');
        $this->assertSame('keep', $decision->decision, 'recorded in the shared old-name dictionary');
        $this->assertSame($this->admin->id, $decision->decided_by);

        // A name HR already mapped must be used in its official form.
        BezettingNameMapping::create(['kind' => 'jabatan', 'source_text' => 'Ast. Afdeling', 'decision' => 'map', 'target_value' => 'Asisten Afdeling I', 'decided_by' => $this->admin->id]);
        $this->store($employee, ['nama_di_luar_master' => '1', 'jabatan' => 'Ast. Afdeling', 'tmt_awal' => '2020-01-01', 'tmt_akhir' => '2020-12-31'])->assertSessionHasErrors('jabatan');

        // After the first structure SK the structure is known, so no old names.
        StructureDecree::create([
            'sk_number' => 'SK/STR/1', 'title' => 'Struktur Awal', 'sk_date' => '2019-01-01', 'effective_date' => '2019-01-01',
            'status' => StructureDecree::STATUS_APPLIED, 'source_path' => 'decrees/1.xlsx', 'source_sha256' => str_repeat('a', 64), 'applied_at' => now(),
            'snapshot' => ['units' => ['Kebun Parindu' => ['seats' => ['Asisten Afdeling I' => ['rm_level' => 'RM-3', 'level' => 'Karpim', 'bidang' => 'TAN', 'kuota' => 1]]]]],
        ]);
        $this->store($employee, ['nama_di_luar_master' => '1', 'jabatan' => 'Mandor Besar', 'tmt_awal' => '2020-01-01', 'tmt_akhir' => '2020-12-31'])->assertSessionHasErrors('jabatan');
        $this->store($employee, ['tmt_awal' => '2020-01-01', 'tmt_akhir' => '2020-12-31'])->assertSessionHasNoErrors();
    }

    public function test_position_and_unit_come_from_the_master_not_free_text(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

        $this->update($employee, $row, ['jabatan' => 'Jabatan Karangan'])->assertSessionHasErrors('jabatan');
        $this->update($employee, $row, ['unit_kerja' => 'Kebun Karangan'])->assertSessionHasErrors('unit_kerja');
        $this->store($employee, ['jabatan' => 'Asisten Kepala'])->assertSessionHasErrors('jabatan');

        // The old name a row already holds stays as it is; a master name is stored in the master's own spelling.
        $this->update($employee, $row, ['catatan' => 'Nama lama tetap'])->assertSessionHasNoErrors();
        $this->assertSame('Asisten Kepala', $row->fresh()->jabatan);
        $this->update($employee, $row, ['jabatan' => '  asisten  afdeling i ', 'unit_kerja' => 'KEBUN PARINDU'])->assertSessionHasNoErrors();
        $this->assertSame('Asisten Afdeling I', $row->fresh()->jabatan);
        $this->assertSame('Kebun Parindu', $row->fresh()->unit_kerja);
    }

    public function test_the_form_offers_master_pick_lists_with_the_row_old_name_marked(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

        $this->actingAs($this->admin)->get(route('admin.employees.job-history.edit', ['employee' => $employee->nik, 'job_history' => $row->id]))
            ->assertOk()
            ->assertSee('Cari atau pilih unit kerja...')
            ->assertSee('Asisten Kepala (nama lama, di luar Master Formasi)')
            ->assertSee('Asisten Afdeling I')
            ->assertDontSee('<datalist', false);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create(['jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2025-03-01', 'tmt_unit_kerja' => '2025-03-01']);
    }

    private function closedRow(Employee $employee, array $attributes = []): JobHistory
    {
        return JobHistory::create($attributes + [
            'employee_nik' => $employee->nik, 'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'status_penugasan' => 'Definitif',
            'tmt_awal' => '2024-01-01', 'tmt_akhir' => '2025-02-28', 'is_applied' => true, 'sumber' => JobHistory::SUMBER_BEZETTING,
        ]);
    }

    private function update(Employee $employee, JobHistory $row, array $data)
    {
        return $this->actingAs($this->admin)
            ->put(route('admin.employees.job-history.update', ['employee' => $employee->nik, 'job_history' => $row->id]), array_merge([
                'jabatan' => 'Asisten Kepala', 'unit_kerja' => 'Kebun Parindu', 'tmt_awal' => '2024-01-01', 'tmt_akhir' => '2025-02-28', 'alasan' => self::REASON,
            ], $data));
    }

    private function store(Employee $employee, array $data)
    {
        return $this->actingAs($this->admin)->post(route('admin.employees.job-history.store', $employee->nik), array_merge([
            'jabatan' => 'Asisten Afdeling I', 'unit_kerja' => 'Kebun Parindu', 'tmt_awal' => '2022-01-01', 'tmt_akhir' => '2023-12-31', 'alasan' => self::REASON,
        ], $data));
    }

    private function destroy(Employee $employee, JobHistory $row, ?string $alasan)
    {
        return $this->actingAs($this->admin)
            ->delete(route('admin.employees.job-history.destroy', ['employee' => $employee->nik, 'job_history' => $row->id]), array_filter(['alasan' => $alasan]));
    }

    public function test_a_closed_row_is_corrected_with_a_reason_and_the_change_is_audited_without_personal_data(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

        $this->update($employee, $row, ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->update($employee, $row, ['jabatan' => 'Asisten Kepala Tanaman', 'golongan' => 'ivb / 3'])->assertSessionHasNoErrors()->assertRedirect();

        $row->refresh();
        $this->assertSame('Asisten Kepala Tanaman', $row->jabatan);
        $this->assertSame('IVB/03', $row->golongan, 'golongan is stored in the standard format');
        $this->assertSame(JobHistory::SUMBER_BEZETTING, $row->sumber);

        $log = MasterAuditLog::where('action', 'job_history.updated')->sole();
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame('Asisten Kepala Tanaman', $log->new_values['jabatan']);
        $this->assertStringNotContainsString($employee->nik, json_encode($log->toArray()));
        $this->assertStringNotContainsString($employee->nama, json_encode($log->toArray()));
    }

    public function test_correcting_an_estimated_start_date_clears_the_estimate_flag_but_other_edits_do_not(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee, ['tmt_awal_diperkirakan' => true]);

        $this->update($employee, $row, ['catatan' => 'Hanya catatan'])->assertSessionHasNoErrors();
        $this->assertTrue($row->fresh()->tmt_awal_diperkirakan, 'a note edit leaves the estimate flag');

        $this->update($employee, $row, ['tmt_awal' => '2023-11-15', 'catatan' => 'Tanggal dari dokumen kepegawaian'])->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame('2023-11-15', $row->tmt_awal->format('Y-m-d'));
        $this->assertFalse($row->tmt_awal_diperkirakan, 'a start date set by HR is no longer an estimate');
    }

    public function test_golongan_outside_the_standard_format_is_refused(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

        $this->update($employee, $row, ['golongan' => 'CKP'])->assertSessionHasErrors('golongan');
        $this->assertNull($row->fresh()->golongan);
    }

    public function test_the_level_follows_the_rm_band_of_a_master_position(): void
    {
        Position::factory()->create(['nama' => 'Asisten Kepala', 'rm_level' => 'RM-3']);
        $employee = $this->employee();
        $row = $this->closedRow($employee, ['level' => null]);

        $this->update($employee, $row, ['level' => 'Karpel'])->assertSessionHasNoErrors();

        $this->assertSame('Karpim', $row->fresh()->level, 'RM-3 is Karpim whatever was chosen');
        $this->update($employee, $row, ['level' => 'Supervisor'])->assertSessionHasErrors('level');
    }

    public function test_a_running_position_keeps_its_position_and_only_its_note_changes(): void
    {
        $employee = $this->employee();
        $current = JobHistory::where('employee_nik', $employee->nik)->sole();

        $this->update($employee, $current, ['jabatan' => 'Direktur', 'unit_kerja' => 'Kantor Pusat', 'catatan' => 'Catatan baru'])->assertSessionHasNoErrors();

        $current->refresh();
        $this->assertSame('Manajer Kebun', $current->jabatan, 'moving an employee is done through Mutasi');
        $this->assertNull($current->tmt_akhir);
        $this->assertSame('Catatan baru', $current->catatan);
        $this->assertSame('Manajer Kebun', $employee->fresh()->jabatan);

        $this->actingAs($this->admin)->get(route('admin.employees.job-history.edit', ['employee' => $employee->nik, 'job_history' => $current->id]))
            ->assertOk()->assertSee('Jabatan yang Sedang Berjalan')->assertSee('Buka Mutasi')->assertDontSee('name="jabatan"', false);
    }

    public function test_adding_history_needs_a_closed_period_that_overlaps_no_definitive_row(): void
    {
        $employee = $this->employee();

        $this->store($employee, ['tmt_akhir' => ''])->assertSessionHasErrors('tmt_akhir');
        $this->store($employee, ['tmt_akhir' => now()->addMonth()->toDateString()])->assertSessionHasErrors('tmt_akhir');
        $this->store($employee, ['tmt_awal' => '2024-06-01', 'tmt_akhir' => '2025-06-30'])->assertSessionHasErrors('tmt_awal');
        $this->assertSame(1, JobHistory::where('employee_nik', $employee->nik)->count(), 'nothing written, the current position untouched');

        $this->store($employee, [])->assertSessionHasNoErrors()->assertRedirect();

        $added = JobHistory::where('employee_nik', $employee->nik)->where('jabatan', 'Asisten Afdeling I')->sole();
        $this->assertSame('2023-12-31', $added->tmt_akhir->format('Y-m-d'));
        $this->assertSame('Manajer Kebun', $employee->fresh()->jabatan, 'adding history never moves the employee');
        $this->assertTrue(MasterAuditLog::where('action', 'job_history.created')->where('entity_id', $added->id)->exists());
        $this->assertSame(JobHistory::SUMBER_PEMBARUAN_DATA, $added->sumber, 'a row without an SK is never labelled an official mutation');
    }

    public function test_an_added_official_mutation_needs_its_type_and_full_sk(): void
    {
        $employee = $this->employee();

        $this->store($employee, ['jenis_mutasi' => 'ROTASI'])->assertSessionHasErrors(['nomor_sk', 'tanggal_sk']);
        $this->store($employee, ['nomor_sk' => 'SK/ROT/2022'])->assertSessionHasErrors(['jenis_mutasi', 'tanggal_sk']);

        $this->store($employee, ['jenis_mutasi' => 'ROTASI', 'nomor_sk' => 'SK/ROT/2022', 'tanggal_sk' => '2021-12-20'])->assertSessionHasNoErrors();
        $this->assertSame(JobHistory::SUMBER_MUTASI, JobHistory::where('nomor_sk', 'SK/ROT/2022')->sole()->sumber);
    }

    public function test_a_bezetting_row_cannot_be_reopened_or_given_sk_data(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

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
        $employee = $this->employee();
        $row = $this->closedRow($employee, ['sumber' => JobHistory::SUMBER_DATA_AWAL]);

        $this->update($employee, $row, ['nomor_sk' => 'SK/BARU/2025'])->assertSessionHasErrors('nomor_sk');
        $this->update($employee, $row, ['tanggal_sk' => '2025-01-15'])->assertSessionHasErrors('tanggal_sk');

        $this->assertNull($row->fresh()->nomor_sk);
        $this->assertNull($row->fresh()->tanggal_sk);
    }

    public function test_a_legacy_sk_value_on_a_record_only_row_is_kept_and_the_row_stays_editable(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee, ['sumber' => JobHistory::SUMBER_IMPOR, 'tmt_akhir' => '2024-12-31', 'nomor_sk' => 'SK/LAMA/2024', 'tanggal_sk' => '2024-01-01']);

        $this->update($employee, $row, ['tmt_akhir' => '2024-12-31', 'nomor_sk' => 'SK/LAMA/2024', 'tanggal_sk' => '2024-01-01', 'catatan' => 'Koreksi catatan'])
            ->assertSessionHasNoErrors();
        $this->assertSame('SK/LAMA/2024', $row->fresh()->nomor_sk);
        $this->assertSame('Koreksi catatan', $row->fresh()->catatan);

        $this->update($employee, $row, ['tmt_akhir' => '2024-12-31', 'catatan' => 'Tanpa isian SK'])->assertSessionHasNoErrors();
        $this->assertSame('SK/LAMA/2024', $row->fresh()->nomor_sk, 'a form without SK inputs never erases the stored value');
    }

    public function test_an_official_mutation_row_can_still_record_its_sk(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee, ['sumber' => JobHistory::SUMBER_MUTASI, 'jenis_mutasi' => 'ROTASI', 'tmt_akhir' => '2024-12-31']);

        $this->update($employee, $row, ['tmt_akhir' => '2024-12-31', 'jenis_mutasi' => 'ROTASI', 'nomor_sk' => 'SK/MUT/2024', 'tanggal_sk' => '2023-12-20'])
            ->assertSessionHasNoErrors();
        $this->assertSame('SK/MUT/2024', $row->fresh()->nomor_sk);
    }

    public function test_the_form_uses_the_shared_components_business_terms_and_explains_record_only_rows(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

        $this->actingAs($this->admin)
            ->get(route('admin.employees.job-history.edit', ['employee' => $employee->nik, 'job_history' => $row->id]))
            ->assertOk()
            ->assertSee('Ubah Riwayat Jabatan')
            ->assertSee('Simpan Perubahan')
            ->assertSee('Alasan perubahan')
            ->assertSee('Format baku [Gol]/[Berkala]')
            ->assertSee('Bezetting Historis: catatan data kepegawaian, bukan SK mutasi.')
            ->assertDontSee('name="nomor_sk"', false)
            ->assertDontSee('name="jenis_mutasi"', false)
            ->assertDontSee('>Update<', false)
            ->assertDontSee('Edit Riwayat Jabatan')
            ->assertDontSee('DOMAIN.md');
    }

    public function test_deleting_a_closed_row_needs_a_reason_and_keeps_who_and_why(): void
    {
        $employee = $this->employee();
        $row = $this->closedRow($employee);

        $this->destroy($employee, $row, null)->assertSessionHas('error');
        $this->destroy($employee, $row, 'singkat')->assertSessionHas('error');
        $this->assertNotNull(JobHistory::find($row->id));

        $this->destroy($employee, $row, 'Baris ganda dari berkas Bezetting')->assertSessionHas('success');

        $this->assertNull(JobHistory::find($row->id));
        $trashed = JobHistory::withTrashed()->findOrFail($row->id);
        $this->assertNotNull($trashed->deleted_at, 'soft delete, never permanent');
        $this->assertSame($this->admin->id, $trashed->cancelled_by);
        $this->assertSame('Baris ganda dari berkas Bezetting', $trashed->alasan_pembatalan);
        $this->assertTrue(MasterAuditLog::where('action', 'job_history.deleted')->where('entity_id', $row->id)->exists());
    }

    public function test_the_running_position_and_another_employees_row_cannot_be_deleted_or_edited(): void
    {
        $employee = $this->employee();
        $current = JobHistory::where('employee_nik', $employee->nik)->sole();
        $foreign = $this->closedRow($this->employee());

        $this->destroy($employee, $current, 'Mencoba menghapus jabatan berjalan')->assertSessionHas('error');
        $this->assertNotNull(JobHistory::find($current->id));

        $this->destroy($employee, $foreign, 'Mencoba menghapus milik orang lain')->assertNotFound();
        $this->update($employee, $foreign, [])->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.employees.job-history.edit', ['employee' => $employee->nik, 'job_history' => $foreign->id]))->assertNotFound();
        $this->assertNotNull(JobHistory::find($foreign->id));
    }
}
