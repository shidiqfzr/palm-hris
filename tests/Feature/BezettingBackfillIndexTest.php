<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\BezettingBackfillIndex;
use App\Models\BezettingBackfillRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsBezettingWorkbooks;
use Tests\TestCase;

/**
 * SUT: BezettingBackfillIndex — upload of monthly Bezetting workbooks and the list of runs (DOMAIN.md §5.5).
 */
class BezettingBackfillIndexTest extends TestCase
{
    use BuildsBezettingWorkbooks;
    use RefreshDatabase;

    public function test_the_page_lists_runs_in_business_wording_without_internal_terms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->bezettingScenario();
        $this->stagedBezettingRun($admin);

        $this->actingAs($admin)->get(route('admin.employees.bezetting.index'))
            ->assertOk()
            ->assertSee('Rekonstruksi Riwayat Karir')
            ->assertSee('2025-01 s.d. 2025-03')
            ->assertSee('Ditinjau')
            ->assertDontSee('DOMAIN.md')
            ->assertDontSee('SSOT');
    }

    public function test_the_page_teaches_how_many_files_to_upload_and_the_whole_workflow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.employees.bezetting.index'))
            ->assertOk()
            ->assertSee('Sebelum mengunggah')
            ->assertSee('paling banyak '.config('hris.bezetting.max_files').' berkas')
            ->assertSee('Bulan berurutan')
            ->assertSee('Mulai dari yang terbaru')
            ->assertSee('Alur kerja Rekonstruksi Riwayat Karir')
            ->assertSee('Persetujuan admin lain')
            ->assertSee('bukan SK mutasi')
            ->assertDontSee('DOMAIN.md')
            ->assertDontSee('SSOT');
    }

    public function test_uploading_workbooks_stages_a_run_and_opens_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->bezettingScenario();

        Livewire::actingAs($admin)->test(BezettingBackfillIndex::class)
            ->set('files', [
                $this->bezettingUpload(1, [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']]),
                $this->bezettingUpload(2, [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']]),
            ])
            ->call('stage')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.employees.bezetting.show', BezettingBackfillRun::sole()));

        $run = BezettingBackfillRun::sole();
        $this->assertSame(['2025-01', '2025-02'], array_column($run->files, 'period'));
        $this->assertSame($admin->id, $run->maker_id);
    }

    public function test_an_old_format_xls_workbook_is_accepted(): void
    {
        // Real Bezetting files are binary .xls (OLE container). Livewire detects their type from the first bytes as
        // application/x-ole-storage, which the plain "mimes:xls" rule rejected.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->bezettingScenario();

        // In tests Livewire uses the fake's declared type instead of reading the file, so declare what it detects in production.
        $upload = $this->bezettingUpload(1, [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']], 'xls')->mimeType('application/x-ole-storage');

        Livewire::actingAs($admin)->test(BezettingBackfillIndex::class)
            ->set('files', [$upload])
            ->call('stage')
            ->assertHasNoErrors();

        $this->assertSame(['2025-01'], array_column(BezettingBackfillRun::sole()->files, 'period'));
    }

    public function test_a_word_document_renamed_to_xls_is_refused_by_the_content_check(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(BezettingBackfillIndex::class)
            ->set('files', [UploadedFile::fake()->createWithContent('Bez Januari 2025.xls', 'bukan lembar kerja')])
            ->call('stage')
            ->assertHasErrors('files');

        $this->assertSame(0, BezettingBackfillRun::count());
    }

    public function test_a_file_that_is_not_excel_or_a_duplicate_period_is_refused_with_a_safe_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(BezettingBackfillIndex::class)
            ->set('files', [UploadedFile::fake()->create('Bez Januari 2025.pdf', 10, 'application/pdf')])
            ->call('stage')
            ->assertHasErrors('files.0');

        Livewire::actingAs($admin)->test(BezettingBackfillIndex::class)
            ->set('files', [$this->bezettingUpload(1, []), $this->bezettingUpload(1, [])])
            ->call('stage')
            ->assertHasErrors('files');

        $this->assertSame(0, BezettingBackfillRun::count());
    }

    public function test_an_account_without_the_import_permission_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))->get(route('admin.employees.bezetting.index'))->assertForbidden();
    }

    public function test_uploading_more_than_12_files_is_rejected_and_upload_guidance_is_visible(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertSame(12, config('hris.bezetting.max_files'));

        $this->actingAs($admin)->get(route('admin.employees.bezetting.index'))
            ->assertOk()
            ->assertSee('maksimal 12 berkas (1 tahun per proses)')
            ->assertSee('Mulailah dari tahun terbaru');

        $thirteen = array_map(fn (int $i) => UploadedFile::fake()->create("Bez {$i}.xlsx", 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'), range(1, 13));

        Livewire::actingAs($admin)->test(BezettingBackfillIndex::class)
            ->set('files', $thirteen)
            ->call('stage')
            ->assertHasErrors(['files' => 'max']);
    }
}
