<?php

namespace Tests\Feature;

use App\Exports\BezettingVerificationExport;
use App\Models\MasterAuditLog;
use App\Models\User;
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
            $excluded = $export->sheets()[0]->array();
            $this->assertSame('99001234', $excluded[0][0]);
            $this->assertSame('Belum ada pada data karyawan', $excluded[0][2]);

            return true;
        });

        $log = MasterAuditLog::where('action', 'bezetting_access.export')->sole();
        $this->assertSame($run->id, $log->entity_id);
        $this->assertStringNotContainsString('99001234', json_encode($log->toArray()));
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
