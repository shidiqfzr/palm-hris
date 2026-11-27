<?php

namespace Tests\Feature;

use App\Exports\BezettingVerificationExport;
use App\Models\MasterAuditLog;
use App\Models\User;
use App\Services\Bezetting\BezettingBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsBezettingWorkbooks;
use Tests\TestCase;

/**
 * SUT: BezettingVerificationDownloadController — the HR verification list with full NIKs (SECURITY.md §4.4): permission,
 * audit entry without content, and formula-safe cells.
 */
class BezettingVerificationDownloadTest extends TestCase
{
    use BuildsBezettingWorkbooks;
    use RefreshDatabase;

    public function test_the_download_lists_excluded_people_and_is_audited_without_their_data(): void
    {
        Excel::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->bezettingScenario();
        $run = $this->stagedBezettingRun($admin, 'Kebun Parindu', [['99001234', '=HYPERLINK("x")', 'Kebun Parindu', '01-04-2023']]);

        $this->actingAs($admin)->get(route('admin.employees.bezetting.verification', $run))->assertOk();

        Excel::assertDownloaded('verifikasi-bezetting-2025-01-2025-03.xlsx', function (BezettingVerificationExport $export) {
            $sheets = collect($export->sheets())->keyBy(fn ($sheet) => $sheet->title());
            $this->assertSame(['Riwayat yang ditulis', 'Perubahan perlu diperiksa', 'Celah bulan', 'Keluar dari daftar', 'Dikecualikan', 'Baris tidak terbaca'], $sheets->keys()->all());
            $excluded = $sheets['Dikecualikan']->array();
            $this->assertSame('99001234', $excluded[0][0]);
            $this->assertSame('Belum ada pada data karyawan', $excluded[0][2]);
            $this->assertStringContainsString('tambahkan lebih dulu pada data karyawan', $excluded[0][4], 'the HR list says what to do');
            $this->assertSame('99000001', $sheets['Riwayat yang ditulis']->array()[0][0]);

            return true;
        });

        $log = MasterAuditLog::where('action', 'bezetting_access.export')->sole();
        $this->assertSame($run->id, $log->entity_id);
        $this->assertStringNotContainsString('99001234', json_encode($log->toArray()));
    }

    public function test_after_approval_the_download_reports_what_was_written_not_a_rebuilt_plan(): void
    {
        Excel::fake();
        $maker = User::factory()->create(['role' => 'admin']);
        $checker = User::factory()->create(['role' => 'admin']);
        $this->bezettingScenario();
        $run = $this->stagedBezettingRun($maker);
        app(BezettingBackfillService::class)->submit($run, $maker);
        app(BezettingBackfillService::class)->approve($run, $checker);

        $this->actingAs($maker)->get(route('admin.employees.bezetting.verification', $run))->assertOk();

        Excel::assertDownloaded('verifikasi-bezetting-2025-01-2025-03.xlsx', function (BezettingVerificationExport $export) {
            $sheets = collect($export->sheets())->keyBy(fn ($sheet) => $sheet->title());
            $this->assertSame(['Riwayat yang ditulis', 'Perubahan perlu diperiksa', 'Celah bulan', 'Keluar dari daftar'], $sheets->keys()->all(),
                'no exclusion sheet: rebuilding the plan after the commit would wrongly report everyone as already done');
            $written = $sheets['Riwayat yang ditulis']->array()[0];
            $this->assertSame('99000001', $written[0]);
            $this->assertSame('Asisten Kepala', $written[2], 'the written position, as in Riwayat Karir');

            return true;
        });
    }

    public function test_spreadsheet_cells_are_neutralised_against_formula_injection(): void
    {
        $sheet = (new BezettingVerificationExport(['Dikecualikan' => [['99001234', '=HYPERLINK("x")', 'Alasan', '']]]))->sheets()[0];

        $this->assertSame("'=HYPERLINK(\"x\")", $sheet->array()[0][1]);
    }

    public function test_an_account_without_the_import_permission_cannot_download(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->bezettingScenario();
        $run = $this->stagedBezettingRun($admin);

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.employees.bezetting.verification', $run))
            ->assertForbidden();
        $this->assertSame(0, MasterAuditLog::where('action', 'bezetting_access.export')->count());
    }
}
