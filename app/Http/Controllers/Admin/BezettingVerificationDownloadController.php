<?php

namespace App\Http\Controllers\Admin;

use App\Exports\BezettingVerificationExport;
use App\Http\Controllers\Controller;
use App\Models\BezettingBackfillRun;
use App\Models\MasterAuditLog;
use App\Services\Bezetting\BackfillPlanner;
use App\Services\Bezetting\BezettingBackfillService;
use App\Support\HrisPermission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduhan daftar verifikasi SDM untuk satu proses Rekonstruksi Riwayat (SECURITY.md §4.4). Memuat NIK utuh, sehingga:
 * izin `karyawan.import`, dibatasi lajunya pada rute, dan dicatat pada log aplikasi serta master_audit_logs
 * (pengguna dan jumlah baris, tanpa isi berkas).
 */
class BezettingVerificationDownloadController extends Controller
{
    private const ISSUE_LABELS = [
        'nik_kosong' => 'Nama dan jabatan tanpa NIK',
        'nik_tidak_valid' => 'NIK bukan angka',
    ];

    public function __invoke(BezettingBackfillRun $run, BezettingBackfillService $service): BinaryFileResponse
    {
        abort_unless(Gate::allows(HrisPermission::KARYAWAN_IMPORT), 403, 'Unauthorized.');
        abort_if($run->payload === null, 404, 'Data berkas proses ini sudah dihapus.');

        $plan = $service->plan($run);
        $names = [];
        $issues = [];
        foreach ($run->payload['snapshots'] as $snapshot) {
            foreach ($snapshot['rows'] as $row) {
                $names[(string) $row['nik']] = (string) $row['nama'];
            }
            foreach ($snapshot['issues'] ?? [] as $issue) {
                $issues[] = [$snapshot['period'], (string) $issue['row'], self::ISSUE_LABELS[$issue['code']] ?? $issue['code']];
            }
        }
        $name = fn ($nik) => $names[(string) $nik] ?? '';

        $sheets = [
            'Dikecualikan' => collect($plan['excluded'])->map(fn (array $item, $nik) => [
                (string) $nik, $name($nik), BackfillPlanner::EXCLUSION_LABELS[$item['reason']] ?? $item['reason'], (string) ($item['periode'] ?? ''),
            ])->values()->all(),
            'Keluar dari Daftar' => array_map(fn (array $item) => [$item['nik'], $name($item['nik']), $item['terakhir_terlihat']], $plan['info']['keluar_daftar']),
            'Perpindahan Belum Tercatat' => array_map(fn (array $item) => [$item['nik'], $name($item['nik']), $item['periode']], $plan['info']['perpindahan_belum_tercatat']),
            'Baris Tidak Terbaca' => $issues,
        ];
        $count = array_sum(array_map('count', $sheets));

        MasterAuditLog::record(auth()->user(), 'bezetting_access.export', 'bezetting_run', $run->id,
            "Daftar verifikasi Bezetting {$run->period_from} s.d. {$run->period_to}", null, ['jumlah_baris' => $count]);
        Log::info('Bezetting verification list downloaded', ['user_id' => auth()->id(), 'run_id' => $run->id, 'rows' => $count]);

        return Excel::download(new BezettingVerificationExport($sheets), "verifikasi-bezetting-{$run->period_from}-{$run->period_to}.xlsx");
    }
}
