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
        $employee = Employee::factory()->create(['tmt_bekerja' => '2000-01-01',
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

    /** Scenario plus a second employee (in the application since 2024) who moves in March: a movement not recorded. */
    private function scenarioWithFollowUp(): array
    {
        [$employee] = $this->scenario();
        Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000002', 'nama' => 'Karyawan Tindak Lanjut', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu',
            'tanggal_dalam_jabatan' => '2024-01-01', 'tmt_unit_kerja' => '2024-01-01']);

        return [$employee, $this->filesWithFollowUp()];
    }

    private function filesWithFollowUp(): array
    {
        return [
            $this->file(1, [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023'], ['99000002', 'Manajer Kebun', 'Kebun Parindu', '01-01-2024']]),
            $this->file(2, [['99000001', 'Asisten Kepala', 'Kebun Parindu', '01-04-2023'], ['99000002', 'Manajer Kebun', 'Kebun Parindu', '01-01-2024']]),
            $this->file(3, [['99000001', 'Manajer Kebun', 'Kebun Parindu', '01-03-2025'], ['99000002', 'Asisten Kepala', 'Kebun Parindu', '01-03-2025']]),
        ];
    }

    private function approved(array $files): BezettingBackfillRun
    {
        $run = $this->service()->stage($files, $this->maker);
        $this->service()->submit($run, $this->maker);

        return $this->service()->approve($run, $this->checker);
    }

    public function test_the_recap_previews_per_employee_before_approval_and_reconciles_what_was_written_after(): void
    {
        [$employee, $files] = $this->scenarioWithFollowUp();
        $run = $this->service()->stage($files, $this->maker);

        $preview = $this->service()->recap($run);
        $this->assertSame('plan', $preview['source']);
        // Each written row says what and why: the position, its source month, where the start date comes from, why it ends.
        $this->assertSame([[
            'nik' => '99000001', 'nama' => $employee->nama, 'jabatan' => 'Asisten Kepala', 'unit' => 'Kebun Parindu', 'status' => 'Definitif',
            'dari' => '2023-04-01', 'sampai' => '2025-02-28', 'perkiraan' => false, 'perubahan' => 'Awal Data Bezetting',
            'sumber' => 'Bezetting Januari 2025', 'sumber_singkat' => 'Bez Jan 2025', 'teks_sumber' => 'Asisten Kepala | Kebun Parindu', 'teks_sumber_baris' => 'Asisten Kepala | Kebun Parindu',
            'selesai_karena' => 'aplikasi', 'aplikasi_mulai' => '2025-03-01',
        ]], $preview['rows']);
        $this->assertSame(1, $preview['karyawan']);
        $this->assertSame(0, $preview['belum_diputuskan']);
        $this->assertSame([['jenis' => 'perpindahan', 'nik' => '99000002', 'periode' => '2025-03', 'nama_disimpan' => false, 'dari' => 'Manajer Kebun | Kebun Parindu', 'ke' => 'Asisten Kepala | Kebun Parindu', 'aplikasi_sudah' => false, 'nama' => 'Karyawan Tindak Lanjut']], $preview['followups']);

        $this->service()->submit($run, $this->maker);
        $done = $this->service()->approve($run, $this->checker);

        $this->assertStringNotContainsString('99000002', (string) DB::table('bezetting_backfill_runs')->value('followups'), 'follow-ups are encrypted at rest');
        $this->assertArrayNotHasKey('followups', $done->toArray(), 'follow-ups are never serialized');
        $saved = $this->service()->recap($done->fresh());
        $this->assertSame('saved', $saved['source']);
        $this->assertSame($preview['rows'], $saved['rows'], 'what was written matches the preview, with the same reasons');
        $this->assertSame($preview['followups'], $saved['followups'], 'the follow-up list survives the commit');
    }

    public function test_follow_ups_are_cleared_on_undo_and_after_the_retention_period(): void
    {
        [, $files] = $this->scenarioWithFollowUp();
        $undone = $this->approved($files);
        $this->assertNotNull($undone->fresh()->followups);
        $this->service()->rollback($undone, $this->checker, 'Berkas Januari salah unggah');
        $this->assertNull($undone->fresh()->followups, 'an undone run asks for no follow-up');
        $this->assertNotNull($undone->fresh()->followups_purged_at);

        $done = $this->approved($this->filesWithFollowUp());
        $this->travel(89)->days();
        $this->assertSame(0, $this->service()->purge()['followups_purged']);
        $this->assertNotNull($done->fresh()->followups);

        $this->travel(2)->days();
        $this->assertSame(1, $this->service()->purge()['followups_purged']);
        $recap = $this->service()->recap($done->fresh());
        $this->assertSame([], $recap['followups']);
        $this->assertFalse($recap['followups_available']);
        $this->assertCount(1, $recap['rows'], 'the recap reads the written history, which stays');
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

    public function test_only_the_maker_closes_an_unsubmitted_run_with_a_reason_and_its_data_is_cleared_at_once(): void
    {
        [, $files] = $this->scenario();
        $run = $this->service()->stage($files, $this->maker);

        try {
            $this->service()->withdraw($run, $this->checker, 'Berkas hanya satu bulan saja');
            $this->fail('Another admin closed the maker\'s run');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('Hanya pengaju', $e->getMessage());
        }

        $closed = $this->service()->withdraw($run, $this->maker, 'Berkas hanya satu bulan saja');

        $this->assertSame(BezettingBackfillRun::STATUS_WITHDRAWN, $closed->status);
        $this->assertSame('Ditutup', $closed->status_label);
        $this->assertNull($closed->fresh()->payload);
        $this->assertNotNull($closed->fresh()->payload_purged_at);
        $this->assertSame(0, JobHistory::where('sumber', JobHistory::SUMBER_BEZETTING)->count());
        $this->assertTrue(MasterAuditLog::where('action', 'bezetting.withdrawn')->where('entity_id', $run->id)->exists());

        $this->expectException(ImportAbortedException::class);
        $this->service()->submit($closed, $this->maker);
    }

    public function test_a_submitted_run_cannot_be_closed_by_its_maker(): void
    {
        [, $files] = $this->scenario();
        $run = $this->service()->stage($files, $this->maker);
        $this->service()->submit($run, $this->maker);

        $this->expectException(ImportAbortedException::class);
        $this->service()->withdraw($run, $this->maker, 'Ingin menarik pengajuan ini');
    }

    public function test_purge_expires_runs_never_submitted_within_the_retention_period(): void
    {
        [, $files] = $this->scenario();
        $staged = $this->service()->stage($files, $this->maker);

        $this->travel(29)->days();
        $this->assertSame(0, $this->service()->purge()['expired']);
        $this->assertSame(BezettingBackfillRun::STATUS_STAGED, $staged->fresh()->status);

        $this->travel(2)->days();
        $result = $this->service()->purge();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(BezettingBackfillRun::STATUS_EXPIRED, $staged->fresh()->status);
        $this->assertNull($staged->fresh()->payload);
        $this->assertTrue(MasterAuditLog::where('action', 'bezetting.expired')->where('entity_id', $staged->id)->exists());
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

    public function test_approve_persists_legacy_crosswalk_for_identity_valid_employees_and_skips_identity_excluded(): void
    {
        $writtenEmp = Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000030', 'nama' => 'Budi Santoso', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2026-07-01', 'tmt_unit_kerja' => '2026-07-01']);
        $noRowsEmp = Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000031', 'nama' => 'Siti Rahma', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2020-01-01', 'tmt_unit_kerja' => '2020-01-01']);
        $suspectEmp = Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000032', 'nama' => 'Agus Salim', 'tanggal_lahir' => '1980-05-05', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2026-07-01', 'tmt_unit_kerja' => '2026-07-01']);

        $hashWritten = \App\Models\BezettingLegacyNikMap::hashFromRaw('000.0716.0030.0030');
        $hashNoRows = \App\Models\BezettingLegacyNikMap::hashFromRaw('000.0716.0031.0031');
        $hashSuspect = \App\Models\BezettingLegacyNikMap::hashFromRaw('000.0716.0032.0032');

        $run = BezettingBackfillRun::create([
            'status' => BezettingBackfillRun::STATUS_STAGED,
            'maker_id' => $this->maker->id,
            'maker_name' => $this->maker->name,
            'files' => [['name' => 'Bez Januari 2026.xls', 'period' => '2026-01', 'sha256' => str_repeat('a', 64), 'rows' => 3, 'issues' => 0, 'warnings' => []]],
            'period_from' => '2026-01',
            'period_to' => '2026-01',
            'payload' => ['snapshots' => [[
                'period' => '2026-01',
                'rows' => [
                    ['row' => 1, 'nik' => $writtenEmp->nik, 'legacy_nik_hash' => $hashWritten, 'is_legacy_nik' => false, 'nama' => 'Budi Santoso', 'jabatan' => 'Asisten Kepala', 'unit' => 'Kebun Parindu', 'golongan' => 'IIIC/02', 'tanggal_dalam_jabatan' => '2025-01-01', 'tmt_unit_kerja' => '2025-01-01', 'tanggal_lahir' => null, 'tanggal_pensiun' => null],
                    ['row' => 2, 'nik' => $noRowsEmp->nik, 'legacy_nik_hash' => $hashNoRows, 'is_legacy_nik' => false, 'nama' => 'Siti Rahma', 'jabatan' => 'Manajer Kebun', 'unit' => 'Kebun Parindu', 'golongan' => 'IVA/02', 'tanggal_dalam_jabatan' => '2020-01-01', 'tmt_unit_kerja' => '2020-01-01', 'tanggal_lahir' => null, 'tanggal_pensiun' => null],
                    ['row' => 3, 'nik' => $suspectEmp->nik, 'legacy_nik_hash' => $hashSuspect, 'is_legacy_nik' => false, 'nama' => 'Agus Salim', 'jabatan' => 'Asisten Kepala', 'unit' => 'Kebun Parindu', 'golongan' => 'IIIC/02', 'tanggal_dalam_jabatan' => '2025-01-01', 'tmt_unit_kerja' => '2025-01-01', 'tanggal_lahir' => '1999-12-31', 'tanggal_pensiun' => null],
                ],
                'issues' => [],
            ]]],
        ]);

        $this->service()->submit($run, $this->maker);
        $approved = $this->service()->approve($run, $this->checker);

        $this->assertDatabaseHas('bezetting_legacy_nik_maps', ['legacy_nik_hash' => $hashWritten, 'employee_nik' => $writtenEmp->nik, 'source_run_id' => $approved->id]);
        $this->assertDatabaseHas('bezetting_legacy_nik_maps', ['legacy_nik_hash' => $hashNoRows, 'employee_nik' => $noRowsEmp->nik, 'source_run_id' => $approved->id]);
        $this->assertDatabaseMissing('bezetting_legacy_nik_maps', ['legacy_nik_hash' => $hashSuspect]);

        $this->service()->rollback($approved, $this->checker, 'Pembatalan uji coba crosswalk');
        $this->assertSame(0, \App\Models\BezettingLegacyNikMap::count());
    }

    public function test_rollback_enforces_lifo_order_when_older_completed_run_exists(): void
    {
        $emp = Employee::factory()->create(['tmt_bekerja' => '2000-01-01', 'nik' => '99000033', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu', 'tanggal_dalam_jabatan' => '2026-07-01', 'tmt_unit_kerja' => '2026-07-01']);

        $run2026 = BezettingBackfillRun::create([
            'status' => BezettingBackfillRun::STATUS_STAGED, 'maker_id' => $this->maker->id, 'maker_name' => $this->maker->name,
            'files' => [['name' => 'Bez Januari 2026.xls', 'period' => '2026-01', 'sha256' => str_repeat('b', 64), 'rows' => 1, 'issues' => 0, 'warnings' => []]],
            'period_from' => '2026-01', 'period_to' => '2026-01',
            'payload' => ['snapshots' => [['period' => '2026-01', 'rows' => [['row' => 1, 'nik' => $emp->nik, 'legacy_nik_hash' => null, 'is_legacy_nik' => false, 'nama' => $emp->nama, 'jabatan' => 'Asisten Kepala', 'unit' => 'Kebun Parindu', 'golongan' => 'IIIC/02', 'tanggal_dalam_jabatan' => '2026-01-01', 'tmt_unit_kerja' => '2026-01-01', 'tanggal_lahir' => null, 'tanggal_pensiun' => null]], 'issues' => []]]],
        ]);
        $this->service()->submit($run2026, $this->maker);
        $run2026 = $this->service()->approve($run2026, $this->checker);

        $run2025 = BezettingBackfillRun::create([
            'status' => BezettingBackfillRun::STATUS_STAGED, 'maker_id' => $this->maker->id, 'maker_name' => $this->maker->name,
            'files' => [['name' => 'Bez Januari 2025.xls', 'period' => '2025-01', 'sha256' => str_repeat('c', 64), 'rows' => 1, 'issues' => 0, 'warnings' => []]],
            'period_from' => '2025-01', 'period_to' => '2025-01',
            'payload' => ['snapshots' => [['period' => '2025-01', 'rows' => [['row' => 1, 'nik' => $emp->nik, 'legacy_nik_hash' => null, 'is_legacy_nik' => false, 'nama' => $emp->nama, 'jabatan' => 'Asisten Kepala', 'unit' => 'Kebun Gunung Meliau', 'golongan' => 'IIIB/02', 'tanggal_dalam_jabatan' => '2025-01-01', 'tmt_unit_kerja' => '2025-01-01', 'tanggal_lahir' => null, 'tanggal_pensiun' => null]], 'issues' => []]]],
        ]);
        $this->service()->submit($run2025, $this->maker);
        $run2025 = $this->service()->approve($run2025, $this->checker);

        try {
            $this->service()->rollback($run2026, $this->checker, 'Mencoba membatalkan 2026 lebih dulu');
            $this->fail('Expected LIFO rollback guard to refuse cancelling newer run while older run is completed.');
        } catch (ImportAbortedException $e) {
            $this->assertStringContainsString('periode lebih lama lebih dulu', $e->getMessage());
        }

        $this->service()->rollback($run2025, $this->checker, 'Batalkan 2025 lebih dulu');
        $this->service()->rollback($run2026, $this->checker, 'Baru batalkan 2026');

        $this->assertSame(BezettingBackfillRun::STATUS_ROLLED_BACK, $run2025->fresh()->status);
        $this->assertSame(BezettingBackfillRun::STATUS_ROLLED_BACK, $run2026->fresh()->status);
    }

    private function committedRunFromFreshFiles(): BezettingBackfillRun
    {
        $employee = Employee::factory()->create(['tmt_bekerja' => '2000-01-01',
            'nik' => '99000002', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu',
            'tanggal_dalam_jabatan' => '2025-03-01', 'tmt_unit_kerja' => '2025-03-01',
        ]);
        $run = $this->service()->stage([$this->file(1, [[$employee->nik, 'Asisten Kepala', 'Kebun Parindu', '01-04-2023']])], $this->maker);
        $this->service()->submit($run, $this->maker);

        return $this->service()->approve($run, $this->checker);
    }
}
