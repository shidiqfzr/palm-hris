<?php

namespace Tests\Feature;

use App\Exceptions\ImportAbortedException;
use App\Models\BezettingBackfillRun;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\MasterAuditLog;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use App\Services\Bezetting\BackfillPlanner;
use App\Services\Bezetting\BezettingBackfillService;
use App\Services\Mutasi\MutasiGovernanceGuard;
use App\Services\PensiunService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * SUT: BezettingBackfillService — stage, decide, submit, approve by another admin, undo and purge (DOMAIN.md §5.5,
 * SECURITY.md §4.4 and §5 item 1c). Synthetic workbooks and employees only.
 */
class BezettingBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHS = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret'];

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maker = User::factory()->create(['role' => 'admin']);
        $this->checker = User::factory()->create(['role' => 'admin']);
        foreach (['Kebun Parindu', 'Kebun Gunung Meliau'] as $unit) {
            Unit::factory()->create(['nama' => $unit]);
        }
        foreach (['Asisten Kepala', 'Manajer Kebun'] as $position) {
            Position::factory()->create(['nama' => $position]);
        }
    }

    private function service(): BezettingBackfillService
    {
        return app(BezettingBackfillService::class);
    }

    /** One synthetic monthly workbook for 2025-$month. @param list<array{0: string, 1: string, 2: string, 3: string}> $people nik, jabatan, unit, tgl */
    private function file(int $month, array $people): array
    {
        $rows = [['DAFTAR PERSONIL KARYAWAN PIMPINAN'], ['PER '.strtoupper(self::MONTHS[$month]).' 2025'], [],
            ['NO', 'N A M A', 'NIK', 'JABATAN', 'Unit Kerja', 'GOL 2025', 'TGL. DALAM JABATAN', 'TMT DI BAGIAN/ UNIT KERJA']];
        foreach ($people as $i => [$nik, $jabatan, $unit, $tgl]) {
            $rows[] = [$i + 1, 'Karyawan Contoh '.$i, $nik, $jabatan, $unit, 'IIIC/02', $tgl, $tgl];
        }
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bez').'.xlsx';
        (new Xlsx($book))->save($path);

        return ['path' => $path, 'name' => 'Bez '.self::MONTHS[$month].' 2025.xlsx'];
    }

    /** Employee whose application history (data_awal) starts 2025-03-01 as Manajer Kebun; Bezetting shows the earlier post. */
    private function scenario(string $unitJan = 'Kebun Parindu'): array
    {
        $employee = Employee::factory()->create([
            'nik' => '99000001', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu',
            'tanggal_dalam_jabatan' => '2025-03-01', 'tmt_unit_kerja' => '2025-03-01',
        ]);

        return [$employee, [
            $this->file(1, [['99000001', 'Asisten Kepala', $unitJan, '01-04-2023']]),
            $this->file(2, [['99000001', 'Asisten Kepala', $unitJan, '01-04-2023']]),
            $this->file(3, [['99000001', 'Manajer Kebun', 'Kebun Parindu', '01-03-2025']]),
        ]];
    }

    private function committedRun(): BezettingBackfillRun
    {
        [, $files] = $this->scenario();
        $run = $this->service()->stage($files, $this->maker);
        $this->service()->submit($run, $this->maker);

        return $this->service()->approve($run, $this->checker);
    }

    public function test_the_full_flow_writes_closed_record_only_rows_linked_to_the_run(): void
    {
        [$employee, $files] = $this->scenario();

        $run = $this->service()->stage($files, $this->maker);
        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file['path'], 'uploaded files are deleted right after reading');
        }
        $this->assertSame(['2025-01', '2025-02', '2025-03'], array_column($run->files, 'period'));
        $this->assertSame(64, strlen($run->files[0]['sha256']));
        $this->assertStringNotContainsString('99000001', (string) DB::table('bezetting_backfill_runs')->value('payload'), 'payload is encrypted at rest');
        $this->assertArrayNotHasKey('payload', $run->toArray(), 'payload is never serialized');

        $this->service()->submit($run, $this->maker);
        $this->assertSame(BezettingBackfillRun::STATUS_PENDING, $run->fresh()->status);
        $this->assertSame(0, JobHistory::where('sumber', JobHistory::SUMBER_BEZETTING)->count(), 'nothing is written before approval');

        $done = $this->service()->approve($run, $this->checker);

        $this->assertSame(BezettingBackfillRun::STATUS_COMPLETED, $done->status);
        $this->assertSame(1, $done->rows_written);
        $row = JobHistory::where('sumber', JobHistory::SUMBER_BEZETTING)->sole();
        $this->assertSame($employee->nik, $row->employee_nik);
        $this->assertSame('Asisten Kepala', $row->jabatan);
        $this->assertSame('2023-04-01', $row->tmt_awal->toDateString());
        $this->assertSame('2025-02-28', $row->tmt_akhir->toDateString());
        $this->assertSame($run->id, $row->backfill_run_id);
        $this->assertSame($this->maker->id, $row->created_by);
        $this->assertNull($row->nomor_sk);
        $this->assertNull($row->jenis_mutasi);
        $this->assertTrue($row->isRecordOnly());
        $this->assertTrue(MasterAuditLog::where('action', 'bezetting.committed')->where('entity_id', $run->id)->exists());
        $this->assertStringNotContainsString('99000001', json_encode(MasterAuditLog::where('action', 'bezetting.committed')->sole()->toArray()));
    }

    public function test_a_second_run_over_the_same_files_writes_nothing_more(): void
    {
        $this->committedRun();
        [, $files] = [null, [$this->file(1, [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']])]];

        $plan = $this->service()->plan($this->service()->stage($files, $this->maker));

        $this->assertSame([], $plan['rows']);
        $this->assertSame(BackfillPlanner::EXCLUDED_ALREADY_DONE, $plan['excluded']['99000001']['reason']);
    }

    public function test_the_maker_cannot_approve_and_an_account_without_the_permission_cannot_act(): void
    {
        [, $files] = $this->scenario();
        $run = $this->service()->stage($files, $this->maker);
        $this->service()->submit($run, $this->maker);

        try {
            $this->service()->approve($run, $this->maker);
            $this->fail('The maker approved their own run');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('admin lain', $e->getMessage());
        }

        $this->expectException(AuthorizationException::class);
        $this->service()->approve($run, User::factory()->create(['role' => 'user']));
    }

    public function test_the_second_approver_is_required_even_when_the_import_approval_policy_is_off(): void
    {
        config(['hris.import.require_approval' => false]);
        [, $files] = $this->scenario();
        $run = $this->service()->stage($files, $this->maker);
        $this->service()->submit($run, $this->maker);

        $this->assertSame(0, JobHistory::where('sumber', JobHistory::SUMBER_BEZETTING)->count());
        $this->expectException(ImportAbortedException::class);
        $this->service()->approve($run, $this->maker);
    }

    public function test_undecided_names_block_submission_and_decisions_are_validated_and_audited(): void
    {
        [, $files] = $this->scenario('KEBUN INTI PARINDU (K.SAWIT)');
        $run = $this->service()->stage($files, $this->maker);

        try {
            $this->service()->submit($run, $this->maker);
            $this->fail('A run with undecided names was submitted');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('1 nama unit, jabatan, atau status belum diputuskan', $e->getMessage());
        }

        try {
            $this->service()->decide('unit', 'KEBUN INTI PARINDU (K.SAWIT)', 'map', 'Kebun Yang Tidak Ada', $this->maker);
            $this->fail('A mapping to a unit outside the master was accepted');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('tidak ada pada Master Formasi', $e->getMessage());
        }
        $this->assertSame(0, Unit::where('nama', 'Kebun Yang Tidak Ada')->count(), 'nothing is created in the master');

        $this->service()->decide('unit', 'KEBUN INTI PARINDU (K.SAWIT)', 'map', 'Kebun Parindu', $this->maker);
        $this->assertTrue(MasterAuditLog::where('action', 'bezetting.mapping_decided')->exists());
        $this->assertSame('Kebun Parindu', $this->service()->plan($run)['rows'][0]['unit_kerja']);
        $this->service()->submit($run, $this->maker);
        $this->assertSame(BezettingBackfillRun::STATUS_PENDING, $run->fresh()->status);
    }

    public function test_a_decision_changed_after_submission_blocks_approval(): void
    {
        [, $files] = $this->scenario('KEBUN INTI PARINDU (K.SAWIT)');
        $run = $this->service()->stage($files, $this->maker);
        $this->service()->decide('unit', 'KEBUN INTI PARINDU (K.SAWIT)', 'map', 'Kebun Parindu', $this->maker);
        $this->service()->submit($run, $this->maker);

        $this->service()->decide('unit', 'KEBUN INTI PARINDU (K.SAWIT)', 'map', 'Kebun Gunung Meliau', $this->checker);

        $this->expectExceptionMessage('berubah sejak diajukan');
        $this->service()->approve($run, $this->checker);
    }

    public function test_a_rejected_run_returns_to_the_maker_with_a_reason(): void
    {
        [, $files] = $this->scenario();
        $run = $this->service()->stage($files, $this->maker);
        $this->service()->submit($run, $this->maker);

        $rejected = $this->service()->reject($run, $this->checker, 'Unit Parindu perlu dicek ulang oleh SDM');

        $this->assertSame(BezettingBackfillRun::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Unit Parindu perlu dicek ulang oleh SDM', $rejected->decision_note);
        $this->assertSame(BezettingBackfillRun::STATUS_PENDING, $this->service()->submit($rejected, $this->maker)->status);
    }

    public function test_rollback_soft_deletes_with_a_reason_and_needs_the_typed_word_above_the_threshold(): void
    {
        config(['hris.import.typed_confirmation_threshold' => 1]);
        $run = $this->committedRun();

        try {
            $this->service()->rollback($run, $this->checker, 'Berkas Januari salah unggah');
            $this->fail('Rollback above the threshold without the typed word');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('BATALKAN', $e->getMessage());
        }

        $undone = $this->service()->rollback($run, $this->checker, 'Berkas Januari salah unggah', 'batalkan');

        $this->assertSame(BezettingBackfillRun::STATUS_ROLLED_BACK, $undone->status);
        $this->assertSame(0, JobHistory::where('sumber', JobHistory::SUMBER_BEZETTING)->count());
        $trashed = JobHistory::withTrashed()->where('sumber', JobHistory::SUMBER_BEZETTING)->sole();
        $this->assertNotNull($trashed->deleted_at, 'soft delete, never a hard delete');
        $this->assertSame($this->checker->id, $trashed->cancelled_by);
        $this->assertStringContainsString('Berkas Januari salah unggah', $trashed->alasan_pembatalan);
    }

    public function test_rollback_is_refused_when_a_row_changed_after_the_commit_or_the_window_ended(): void
    {
        $run = $this->committedRun();
        $this->travel(5)->seconds();
        JobHistory::where('backfill_run_id', $run->id)->first()->update(['catatan' => 'Dikoreksi SDM']);

        try {
            $this->service()->rollback($run, $this->checker, 'Berkas Januari salah unggah');
            $this->fail('Rollback over a changed row');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('sudah berubah', $e->getMessage());
        }

        $this->travel(8)->days();
        $this->expectExceptionMessage('Masa pembatalan');
        $this->service()->rollback($run, $this->checker, 'Berkas Januari salah unggah');
    }

    public function test_purge_expires_stale_submissions_and_clears_data_no_longer_needed(): void
    {
        [, $files] = $this->scenario();
        $pending = $this->service()->stage($files, $this->maker);
        $this->service()->submit($pending, $this->maker);
        $completed = $this->committedRunFromFreshFiles();

        $this->travel(31)->days();
        $result = $this->service()->purge();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(2, $result['purged']);
        $this->assertSame(BezettingBackfillRun::STATUS_EXPIRED, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->payload);
        $this->assertNull($completed->fresh()->payload);
        $this->assertNotNull($completed->fresh()->payload_purged_at);
        $this->assertSame(1, JobHistory::where('backfill_run_id', $completed->id)->count(), 'purging data never touches history');

        $this->expectExceptionMessage('sudah dihapus');
        $this->service()->plan($pending->fresh());
    }

    public function test_backfilled_rows_change_neither_tenure_checks_nor_seat_holders_nor_separation(): void
    {
        [$employee, $files] = $this->scenario();
        $guard = app(MutasiGovernanceGuard::class);
        $tenureBefore = $guard->checkTenureCompliance($employee->fresh(), 'Kebun Gunung Meliau');
        $activeBefore = JobHistory::active()->count();

        $run = $this->service()->stage($files, $this->maker);
        $this->service()->submit($run, $this->maker);
        $this->service()->approve($run, $this->checker);

        $this->assertEquals($tenureBefore, $guard->checkTenureCompliance($employee->fresh(), 'Kebun Gunung Meliau'));
        $this->assertSame($activeBefore, JobHistory::active()->count(), 'no backfilled row is open');

        app(PensiunService::class)->separate($employee->fresh(), Employee::ALASAN_PENSIUN, Carbon::today());
        $backfilled = JobHistory::where('sumber', JobHistory::SUMBER_BEZETTING)->sole();
        $this->assertSame('2025-02-28', $backfilled->tmt_akhir->toDateString(), 'separation closes only open rows');
        $this->assertStringNotContainsString('Berakhir karena', (string) $backfilled->catatan);
    }

    public function test_stage_rejects_a_duplicate_period_and_a_file_that_is_not_excel(): void
    {
        try {
            $this->service()->stage([$this->file(1, []), $this->file(1, [])], $this->maker);
            $this->fail('Duplicate period accepted');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('lebih dari sekali', $e->getMessage());
        }

        $text = tempnam(sys_get_temp_dir(), 'bez');
        file_put_contents($text, 'bukan excel');
        $this->expectExceptionMessage('bukan berkas Excel');
        $this->service()->stage([['path' => $text, 'name' => 'Bez Januari 2025.csv']], $this->maker);
    }

    private function committedRunFromFreshFiles(): BezettingBackfillRun
    {
        $employee = Employee::factory()->create([
            'nik' => '99000002', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu',
            'tanggal_dalam_jabatan' => '2025-03-01', 'tmt_unit_kerja' => '2025-03-01',
        ]);
        $run = $this->service()->stage([$this->file(1, [[$employee->nik, 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']])], $this->maker);
        $this->service()->submit($run, $this->maker);

        return $this->service()->approve($run, $this->checker);
    }
}
