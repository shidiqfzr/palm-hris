<?php

namespace App\Http\Controllers\Admin;

use App\Exports\BezettingVerificationExport;
use App\Http\Controllers\Controller;
use App\Models\BezettingBackfillRun;
use App\Models\MasterAuditLog;
use App\Services\Bezetting\BackfillPlanner;
use App\Services\Bezetting\BezettingBackfillService;
use App\Services\Bezetting\BezettingWorkbookReader;
use App\Support\HrisPermission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduhan daftar verifikasi SDM untuk satu proses Rekonstruksi Riwayat (SECURITY.md §4.4). Memuat NIK utuh, sehingga:
 * izin `karyawan.import`, dibatasi lajunya pada rute, dan dicatat pada log aplikasi serta master_audit_logs
 * (pengguna dan jumlah baris, tanpa isi berkas).
 *
 * Proses terbuka: pratinjau per karyawan, tindak lanjut, yang dikecualikan, dan baris tidak terbaca (dari rencana).
 * Proses tersimpan: riwayat yang benar-benar ditulis dan daftar tindak lanjut yang disimpan saat disetujui; rencana tidak
 * dibangun ulang, karena sesudah riwayat ditulis rencana tidak lagi menggambarkan proses itu.
 */
class BezettingVerificationDownloadController extends Controller
{
    public function __invoke(BezettingBackfillRun $run, BezettingBackfillService $service): BinaryFileResponse
    {
        abort_unless(Gate::allows(HrisPermission::KARYAWAN_IMPORT), 403, 'Unauthorized.');

        $recap = $service->recap($run);
        abort_if($recap['source'] === null, 404, 'Data berkas proses ini sudah dihapus.');

        // Same wording as the recap screen, so the file and the screen explain each date the same way.
        $sheets = ['Riwayat yang ditulis' => array_map(fn (array $r) => [
            $r['nik'], $r['nama'], $r['jabatan'], $r['unit'], $r['status'], $r['dari'],
            $r['perkiraan'] ? 'Perkiraan: awal bulan pertama terlihat' : 'Tgl. Dalam Jabatan pada berkas',
            $r['sampai'],
            $r['selesai_karena'] === 'aplikasi' ? 'Sehari sebelum riwayat pertama di aplikasi ('.$r['aplikasi_mulai'].')' : 'Akhir bulan terakhir terlihat di Bezetting',
            (string) $r['perubahan'], $r['sumber'], $r['teks_sumber'],
        ], $recap['rows'])];
        foreach (BezettingBackfillService::FOLLOWUP_LABELS as $type => $title) {
            $sheets[$title] = array_values(array_map(fn (array $f) => $type === 'perpindahan'
                ? [$f['nik'], $f['nama'], $f['periode'], $f['dari'] ?? '', $f['ke'] ?? '',
                    implode(' ', array_filter([
                        ($f['aplikasi_sudah'] ?? false) ? 'Aplikasi sudah mencatat jabatan ini; periksa tanggal mulainya.' : '',
                        ($f['nama_disimpan'] ?? false) ? 'Salah satu nama disimpan apa adanya; periksa apakah hanya beda penulisan.' : '',
                    ])),
                    BezettingBackfillService::FOLLOWUP_HELP[$type]['tindakan']]
                : [$f['nik'], $f['nama'], $f['periode'], BezettingBackfillService::FOLLOWUP_HELP[$type]['tindakan']],
                array_filter($recap['followups'], fn (array $f) => $f['jenis'] === $type)));
        }

        if ($recap['source'] === 'plan') {
            $issues = [];
            foreach ($run->payload['snapshots'] as $snapshot) {
                foreach ($snapshot['issues'] ?? [] as $issue) {
                    $issues[] = [$snapshot['period'], (string) $issue['row'], BezettingWorkbookReader::ISSUE_LABELS[$issue['code']] ?? $issue['code']];
                }
            }
            $sheets['Dikecualikan'] = array_values(array_map(fn (array $item) => [
                $item['nik'], $item['nama'], $item['alasan'], (string) ($item['periode'] ?? ''), BackfillPlanner::EXCLUSION_HELP[$item['reason']]['tindakan'] ?? '',
            ], $recap['excluded']));
            $sheets['Baris tidak terbaca'] = $issues;
        }
        $count = array_sum(array_map('count', $sheets));

        MasterAuditLog::record(auth()->user(), 'bezetting_access.export', 'bezetting_run', $run->id,
            "Daftar verifikasi Bezetting {$run->period_from} s.d. {$run->period_to}", null, ['jumlah_baris' => $count]);
        Log::info('Bezetting verification list downloaded', ['user_id' => auth()->id(), 'run_id' => $run->id, 'rows' => $count]);

        return Excel::download(new BezettingVerificationExport($sheets), "verifikasi-bezetting-{$run->period_from}-{$run->period_to}.xlsx");
    }
}
