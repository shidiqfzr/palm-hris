<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\BezettingRunRecap;
use App\Models\BezettingBackfillRun;
use App\Models\Employee;
use App\Models\User;
use App\Services\Bezetting\BezettingBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BuildsBezettingWorkbooks;
use Tests\TestCase;

/**
 * SUT: BezettingRunRecap — Rekap per Karyawan and Perlu Tindak Lanjut of one rebuild run (DOMAIN.md §5.5 rule 9):
 * preview before approval, reconciliation after saving, masked NIK, links to each Riwayat Karir. Synthetic data only.
 */
class BezettingRunRecapTest extends TestCase
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
        // In the application since 2024 and moving in March: a movement not recorded in the application.
        Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000002', 'nama' => 'Karyawan Tindak Lanjut', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu',
            'tanggal_dalam_jabatan' => '2024-01-01', 'tmt_unit_kerja' => '2024-01-01']);
    }

    private function stagedRun(): BezettingBackfillRun
    {
        $months = [
            1 => [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023'], ['99000002', 'Manajer Kebun', 'Kebun Parindu', '01-01-2024']],
            2 => [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023'], ['99000002', 'Manajer Kebun', 'Kebun Parindu', '01-01-2024']],
            3 => [['99000001', 'Manajer Kebun', 'Kebun Parindu', '01-03-2025'], ['99000002', 'Asisten Kepala', 'Kebun Parindu', '01-03-2025']],
        ];
        $files = [];
        foreach ($months as $month => $people) {
            $files[] = ['path' => $this->bezettingWorkbook($month, $people), 'name' => 'Bez '.self::BEZETTING_MONTHS[$month].' 2025.xlsx'];
        }

        return app(BezettingBackfillService::class)->stage($files, $this->maker);
    }

    public function test_the_preview_lists_each_employee_with_masked_nik_and_a_link_to_the_career_history(): void
    {
        $run = $this->stagedRun();
        $employee = Employee::find('99000001');

        Livewire::actingAs($this->checker)->test(BezettingRunRecap::class, ['runId' => $run->id])
            ->assertSee('Riwayat yang Akan Ditulis')
            ->assertSee($employee->nama)
            ->assertSee('*****001')
            ->assertDontSee('>99000001<', false)
            ->assertSee('Penanda bahwa tanggalnya belum pasti')
            // Each row explains itself: position, source month, origin of the start date, reason for the end date.
            ->assertSee('Asisten Kepala')
            ->assertDontSee('Bezetting Januari 2025')
            ->assertSee('diambil dari kolom Tgl. Dalam Jabatan pada berkas')
            ->assertSee('Mulai diperkirakan')
            ->assertSee('Selesai menurut berkas')
            // No examples block: the legend itself explains the labels, and every tab is built the same way.
            ->assertDontSee('Lihat contoh')
            ->assertSee('Penanda bahwa tanggalnya belum pasti')
            // Same third line as Riwayat Karir: short file label, then the file's own text (headline order Jabatan | Unit).
            ->assertSee('Bez Jan 2025: Asisten Kepala | Kebun Parindu')
            // The name is the link to Riwayat Karir; there is no action column or repeated button.
            ->assertDontSee('Lihat Riwayat</')
            ->assertDontSee('>Aksi<', false)
            ->assertSeeHtml('Selesai sehari sebelum riwayat pertama di aplikasi (mulai 01 Mar 2025)')->assertDontSeeHtml('title="Jabatan ini sudah berganti menurut berkas')->assertDontSeeHtml('title="Tanggal pastinya tidak ada pada berkas')
            ->assertDontSee('>Baris<', false)
            ->assertDontSee('>Perkiraan<', false)
            ->assertSeeHtml(route('admin.employees.show', '99000001').'#riwayat-jabatan')
            ->assertSee('Unduh Daftar Verifikasi SDM')
            ->assertDontSee('DOMAIN.md');
    }

    public function test_every_row_shows_why_it_exists_and_the_list_filters_by_kind_of_change(): void
    {
        $run = $this->stagedRun();
        $name = Employee::find('99000001')->nama;

        Livewire::actingAs($this->checker)->test(BezettingRunRecap::class, ['runId' => $run->id])
            ->assertSee('Jenis Perubahan')
            ->assertSee('Awal Data Bezetting')
            ->assertSee('Alasan baris ini ada')
            ->set('jenis', 'PENEMPATAN_AWAL')
            ->assertSee($name)
            ->set('jenis', 'PERUBAHAN_UNIT')
            ->assertDontSee($name)
            ->call('resetFilters')
            ->assertSet('jenis', '')
            ->assertSee($name)
            ->set('jenis', 'BUKAN_JENIS')
            ->assertSet('jenis', '')
            // Named filters replace the old generic one: start date and assignment status each have their own.
            ->assertSee('Tanggal Mulai')
            ->assertSee('Status Penugasan')
            ->assertDontSee('Ada perubahan antarbulan')
            ->assertDontSee('Bukan jabatan definitif')
            ->set('mulai', 'perkiraan')
            ->assertSet('mulai', 'perkiraan')
            ->set('mulai', 'bukan-pilihan')
            ->assertSet('mulai', '')
            ->set('penugasan', 'bukan_definitif')
            ->assertDontSee($name)
            ->call('resetFilters')
            ->assertSet('penugasan', '')
            ->assertSee($name);
    }

    public function test_the_follow_up_tab_opens_with_the_filter_a_summary_counter_asked_for(): void
    {
        $run = $this->stagedRun();

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id])
            ->assertDontSee('Karyawan Tindak Lanjut');

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id, 'view' => 'tindak_lanjut', 'saring' => 'perpindahan'])
            ->assertSet('view', 'tindak_lanjut')
            ->assertSet('saring', 'perpindahan')
            ->assertSee('Karyawan Tindak Lanjut')
            ->assertSee('*****002')
            ->assertSee('Perubahan perlu diperiksa')
            ->assertSee('Dari:')
            ->assertSee('Ke:')
            ->assertSee('catat melalui modul Mutasi beserta SK-nya')
            // The tab explains each type and what to do, and each row says in words what the files show.
            ->assertSee('diproses')
            ->assertSee('Yang perlu dilakukan:')
            ->assertSee('Berubah mulai 2025-03')
            ->assertSee('Yang terlihat pada berkas')
            ->assertSee('Ini tidak menghalangi pengajuan.')
            ->set('saring', 'celah')
            ->assertDontSee('Karyawan Tindak Lanjut')
            ->assertSee('Tidak ada data yang sesuai');
    }

    public function test_an_incomplete_preview_says_so_while_names_are_undecided(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'KEBUN PARINDU (K.SAWIT)');

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id])
            ->assertSee('Pratinjau belum lengkap.')
            ->assertSee('masih perlu diputuskan');
    }

    public function test_search_filters_and_tampered_values_fall_back_safely(): void
    {
        $run = $this->stagedRun();

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id])
            ->set('search', 'tidak ada nama ini')
            ->assertSee('Tidak ada data yang sesuai')
            ->call('resetFilters')
            ->assertSet('search', '')
            ->set('saring', 'bukan-saring')
            ->assertSet('saring', '');

        // The view is fixed by the run page's tab and cannot be changed from the browser; unknown values fall back.
        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id, 'view' => 'bukan-tampilan', 'saring' => 'bukan-saring'])
            ->assertSet('view', 'karyawan')->assertSet('saring', '');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id])->set('view', 'tindak_lanjut');
    }

    public function test_after_approval_the_recap_reconciles_what_was_written_and_keeps_the_follow_ups(): void
    {
        $run = $this->stagedRun();
        app(BezettingBackfillService::class)->submit($run, $this->maker);
        app(BezettingBackfillService::class)->approve($run, $this->checker);

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id])
            ->assertSee('Riwayat yang Ditulis')
            ->assertSee(Employee::find('99000001')->nama);

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id, 'view' => 'tindak_lanjut'])
            ->assertSee('Karyawan Tindak Lanjut')
            ->assertSee('Daftar ini disimpan 90 hari');
    }

    public function test_the_dikecualikan_view_is_the_same_table_with_masked_nik_a_reason_filter_and_a_guide(): void
    {
        $run = $this->stagedBezettingRun($this->maker, 'Kebun Parindu', [['99001234', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']]);

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id, 'view' => 'dikecualikan'])
            ->assertSee('Karyawan Contoh 1')
            ->assertSee('NIK *****234')
            ->assertDontSee('99001234')
            ->assertSee('Belum ada pada data karyawan')
            ->assertSee('Terlihat pada berkas')
            // The same guide as every tab: what the reason means and what to do, and that it does not block submitting.
            ->assertSee('Yang perlu dilakukan:')
            ->assertSee('Bila ia karyawan aktif, tambahkan lebih dulu pada data karyawan')
            ->assertSee('Ini tidak menghalangi pengajuan.')
            ->assertDontSee('Lihat Riwayat')
            ->set('saring', 'nik_bukan_8_digit')
            ->assertSee('Tidak ada data yang sesuai')
            ->set('saring', 'bukan-alasan')
            ->assertSet('saring', '')
            ->set('search', '99001234')
            ->assertSee('*****234');
    }

    public function test_excluded_people_are_known_only_while_the_run_is_open(): void
    {
        $run = $this->stagedRun();
        app(BezettingBackfillService::class)->submit($run, $this->maker);
        app(BezettingBackfillService::class)->approve($run, $this->checker);

        Livewire::actingAs($this->maker)->test(BezettingRunRecap::class, ['runId' => $run->id, 'view' => 'dikecualikan'])
            ->assertSee('hanya diketahui selama proses belum disimpan');
    }

    public function test_an_account_without_the_import_permission_cannot_open_the_recap(): void
    {
        $run = $this->stagedRun();

        Livewire::actingAs(User::factory()->create(['role' => 'user']))->test(BezettingRunRecap::class, ['runId' => $run->id])
            ->assertForbidden();
    }
}
