<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\BezettingBackfillDetail;
use App\Models\BezettingBackfillRun;
use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\MasterAuditLog;
use App\Models\User;
use App\Services\Bezetting\BezettingBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsBezettingWorkbooks;
use Tests\TestCase;

/**
 * SUT: BezettingBackfillDetail — name decisions, masked exclusions and the four-eyes actions of one run (DOMAIN.md §5.5).
 */
class BezettingBackfillDetailTest extends TestCase
{
    use BuildsBezettingWorkbooks;
    use RefreshDatabase;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maker = User::factory()->create(['role' => 'admin']);
        $this->checker = User::factory()->create(['role' => 'admin']);
        $this->bezettingScenario();
    }

    public function test_an_unmapped_unit_is_listed_with_a_suggestion_and_mapped_by_its_key(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'KEBUN PARINDU (K.SAWIT)');
        $key = BezettingBackfillDetail::key('unit', 'KEBUN PARINDU (K.SAWIT)');

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('KEBUN PARINDU (K.SAWIT)')
            // The suggestion is pre-selected (not a separate line), and the table says once that a filled pick is only a suggestion.
            ->assertSet('targets.'.$key, 'Kebun Parindu')
            ->assertSee('Pilihan yang sudah terisi adalah saran sistem')
            ->assertDontSee('Ajukan untuk Persetujuan')
            ->call('map', $key)
            ->assertSee('Semua nama sudah diputuskan.')
            ->assertSee('Ajukan untuk Persetujuan');

        $this->assertSame('Kebun Parindu', BezettingNameMapping::sole()->target_value);
    }

    public function test_the_bulk_action_keeps_every_name_without_a_look_alike_and_records_each_decision(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'Kebun Parindu', [['99000002', 'Asisten Lama Satu', 'Kebun Parindu', '01-04-2023']]);
        Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000002', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2026-01-01']);

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('ASISTEN LAMA SATU')
            ->call('keepUnmatched');

        $decision = BezettingNameMapping::sole();
        $this->assertSame(BezettingNameMapping::DECISION_KEEP, $decision->decision);
        $this->assertSame('ASISTEN LAMA SATU', $decision->source_text);
    }

    public function test_the_suggestion_bulk_action_maps_names_that_have_a_look_alike_and_leaves_the_rest(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'Kebun Parindu (K.SAWIT)', [['99000003', 'Asisten Lama Dua', 'Kebun Parindu (K.SAWIT)', '01-04-2023']]);
        Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000003', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2026-01-01']);

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('Petakan Sesuai Saran')
            ->call('applySuggestions');

        $unit = BezettingNameMapping::where('kind', 'unit')->first();
        $this->assertSame(BezettingNameMapping::DECISION_MAP, $unit->decision);
        $this->assertSame('Kebun Parindu', $unit->target_value);
        $this->assertNull(BezettingNameMapping::where('kind', 'jabatan')->first(), 'a name without a look-alike is left for HR');
    }

    public function test_excluded_people_have_their_own_tab_and_the_list_is_the_shared_recap_table(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'Kebun Parindu', [['99001234', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']]);

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('Dikecualikan (1)')
            ->call('setSection', 'dikecualikan')
            ->assertSet('section', 'dikecualikan')
            ->assertSee('NIK *****234')
            ->assertSee('Karyawan Contoh 1')
            ->assertDontSee('99001234');
    }

    public function test_the_maker_submits_sees_the_four_eyes_notice_and_another_admin_approves(): void
    {
        $run = $this->stagedBezettingRun($this->maker);

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->call('submit')
            ->assertSee('Menunggu Persetujuan')
            ->assertSee('tidak dapat menyetujuinya sendiri')
            ->assertDontSee('Setujui dan Simpan Riwayat');

        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run->fresh()])
            ->assertSee('Setujui dan Simpan Riwayat')
            ->call('approve')
            ->assertSee('Tersimpan');

        $this->assertSame(1, JobHistory::where('backfill_run_id', $run->id)->count());
    }

    public function test_another_admin_is_told_approval_opens_only_after_the_maker_submits(): void
    {
        $run = $this->stagedBezettingRun($this->maker);

        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee("Menunggu diajukan oleh {$this->maker->name}.")
            ->assertSee('Tombol persetujuan tampil setelah proses diajukan.')
            ->assertDontSee('Ajukan untuk Persetujuan')
            ->assertDontSee('Tutup Proses')
            ->assertDontSee('Putuskan seluruh nama lebih dulu');
    }

    public function test_a_single_month_run_explains_why_nothing_is_written_and_the_maker_can_close_it(): void
    {
        // March only: the employee already holds this post in the application from 2025-03-01, so there is nothing earlier to fill.
        $file = ['path' => $this->bezettingWorkbook(3, [['99000001', 'Manajer Kebun', 'Kebun Parindu', '01-03-2025']]), 'name' => 'Bez Maret 2025.xlsx'];
        $run = app(BezettingBackfillService::class)->stage([$file], $this->maker);

        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('tidak memuat riwayat yang perlu ditulis')
            ->assertDontSee('Tutup Proses');

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('Tidak ada riwayat yang perlu ditulis')
            ->assertSee('unggah seluruh bulan dalam satu proses')
            ->assertDontSee('Ajukan untuk Persetujuan')
            ->assertSee('Tutup Proses')
            ->call('withdraw')->assertHasErrors('reason')
            ->set('reason', 'Hanya satu bulan yang terunggah')
            ->call('withdraw')
            ->assertSee('Ditutup oleh pengaju')
            ->assertDontSee('Tutup Proses');

        $this->assertSame(BezettingBackfillRun::STATUS_WITHDRAWN, $run->fresh()->status);
    }

    public function test_reject_and_rollback_require_a_reason_and_rollback_the_typed_word_above_the_threshold(): void
    {
        config(['hris.import.typed_confirmation_threshold' => 1]);
        $run = $this->stagedBezettingRun($this->maker);
        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])->call('submit');

        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run->fresh()])
            ->call('reject')->assertHasErrors('reason')
            ->set('reason', 'Unit Parindu perlu dicek ulang')->call('reject');
        $this->assertSame(BezettingBackfillRun::STATUS_REJECTED, $run->fresh()->status);

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run->fresh()])->call('submit');
        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run->fresh()])->call('approve');

        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run->fresh()])
            ->assertSee('Batalkan Rekonstruksi')
            ->set('reason', 'Berkas Januari salah unggah')
            ->call('rollback')
            ->assertSee('BATALKAN');
        $this->assertSame(BezettingBackfillRun::STATUS_COMPLETED, $run->fresh()->status);

        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run->fresh()])
            ->set('reason', 'Berkas Januari salah unggah')->set('confirmation', 'BATALKAN')->call('rollback');
        $this->assertSame(BezettingBackfillRun::STATUS_ROLLED_BACK, $run->fresh()->status);
    }

    public function test_the_review_shows_one_section_at_a_time_in_work_order(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'KEBUN PARINDU (K.SAWIT)');

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('Bagian tinjauan', false)
            ->assertSee('Nama yang Perlu Diputuskan (1)')
            ->assertSee('KEBUN PARINDU (K.SAWIT)')
            ->assertDontSee('Belum ada pada data karyawan')
            ->assertSee('Riwayat yang Akan Ditulis')
            ->assertSee('Perlu Tindak Lanjut')
            ->dispatch('recap-filter', view: 'karyawan', saring: 'perkiraan')
            ->assertSet('section', 'riwayat')
            ->assertSet('recapFilter', 'perkiraan')
            ->dispatch('recap-filter', view: 'tindak_lanjut', saring: 'celah')
            ->assertSet('section', 'tindak_lanjut')
            ->call('setSection', 'dikecualikan')
            ->assertSet('recapFilter', '')
            ->call('setSection', 'bukan-bagian')
            ->assertSet('section', '');
    }

    public function test_deciding_the_last_name_keeps_the_admin_on_the_names_tab(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'KEBUN PARINDU (K.SAWIT)');

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->call('map', BezettingBackfillDetail::key('unit', 'KEBUN PARINDU (K.SAWIT)'))
            ->assertSet('section', 'nama')
            ->assertSee('Semua nama sudah diputuskan.');
    }

    public function test_an_account_without_the_import_permission_cannot_open_a_run(): void
    {
        $run = $this->stagedBezettingRun($this->maker);

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.employees.bezetting.show', $run))
            ->assertForbidden();
    }

    public function test_maker_can_switch_to_decided_filter_and_revise_saved_mapping(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'KEBUN PARINDU (K.SAWIT)');
        $key = BezettingBackfillDetail::key('unit', 'KEBUN PARINDU (K.SAWIT)');

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->call('keep', $key)
            ->assertSee('Semua nama sudah diputuskan.')
            ->set('decisionFilter', 'decided')
            ->assertSee('KEBUN PARINDU (K.SAWIT)')
            ->assertSee('Apa Adanya')
            ->set("targets.{$key}", 'Kebun Parindu')
            ->call('map', $key);

        $mapping = BezettingNameMapping::decisionFor('unit', 'KEBUN PARINDU (K.SAWIT)');
        $this->assertSame(BezettingNameMapping::DECISION_MAP, $mapping?->decision);
        $this->assertSame('Kebun Parindu', $mapping?->target_value);
        $this->assertTrue(MasterAuditLog::where('action', 'bezetting.mapping_decided')->count() >= 2);
    }

    public function test_approver_sees_decided_names_read_only_on_pending_run_and_cannot_mutate(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'KEBUN PARINDU (K.SAWIT)');
        $key = BezettingBackfillDetail::key('unit', 'KEBUN PARINDU (K.SAWIT)');

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->call('keep', $key)
            ->call('submit');

        Livewire::actingAs($this->checker)->test(BezettingBackfillDetail::class, ['run' => $run->fresh()])
            ->call('setSection', 'nama')
            ->set('decisionFilter', 'decided')
            ->assertSee('KEBUN PARINDU (K.SAWIT)')
            ->assertSee('Apa Adanya')
            ->call('skip', $key);

        $mapping = BezettingNameMapping::decisionFor('unit', 'KEBUN PARINDU (K.SAWIT)');
        $this->assertSame(BezettingNameMapping::DECISION_KEEP, $mapping?->decision);
    }
}
