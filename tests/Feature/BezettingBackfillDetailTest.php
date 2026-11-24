<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\BezettingBackfillDetail;
use App\Models\BezettingBackfillRun;
use App\Models\BezettingNameMapping;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\User;
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
            ->assertSee('Saran: Kebun Parindu')
            ->assertDontSee('Ajukan untuk Persetujuan')
            ->call('map', $key)
            ->assertSee('Semua nama sudah diputuskan.')
            ->assertSee('Ajukan untuk Persetujuan');

        $this->assertSame('Kebun Parindu', BezettingNameMapping::sole()->target_value);
    }

    public function test_the_bulk_action_keeps_every_remaining_position_name_and_records_each_decision(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'Kebun Parindu', [['99000002', 'Asisten Lama Satu', 'Kebun Parindu', '01-04-2023']]);
        Employee::factory()->create(['nik' => '99000002', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2026-01-01']);

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('ASISTEN LAMA SATU')
            ->call('keepRemainingPositions');

        $decision = BezettingNameMapping::sole();
        $this->assertSame(BezettingNameMapping::DECISION_KEEP, $decision->decision);
        $this->assertSame('ASISTEN LAMA SATU', $decision->source_text);
    }

    public function test_excluded_people_are_shown_with_masked_nik_only(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'Kebun Parindu', [['99001234', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']]);

        Livewire::actingAs($this->maker)->test(BezettingBackfillDetail::class, ['run' => $run])
            ->assertSee('Belum ada pada data karyawan')
            ->assertSee('*****234')
            ->assertDontSee('99001234')
            ->assertDontSee('Karyawan Contoh');
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

    public function test_an_account_without_the_import_permission_cannot_open_a_run(): void
    {
        $run = $this->stagedBezettingRun($this->maker);

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.employees.bezetting.show', $run))
            ->assertForbidden();
    }
}
