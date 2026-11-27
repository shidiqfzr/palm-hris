<?php

namespace App\Support;

use App\Services\Bezetting\BackfillPlanner;
use App\Services\Bezetting\BezettingBackfillService;

/**
 * The guide shown at the top of each review tab of a Bezetting rebuild run (DESIGN.md §8.6a), one shape for all four so
 * every tab explains itself the same way: a title, a short intro, and terms each with their meaning and, where HR has
 * something to do, what to do. Rendered by x-admin.table.guide. Meanings and actions of follow-ups and exclusions come
 * from their own single sources (BezettingBackfillService::FOLLOWUP_HELP, BackfillPlanner::EXCLUSION_HELP).
 *
 * @phpstan-type Guide array{title: string, intro: string, items: list<array{term: string, arti: string, tindakan?: string}>, note: ?string}
 */
final class BezettingReviewGuide
{
    /** @return Guide */
    public static function names(): array
    {
        return [
            'title' => 'Cara memutuskan nama',
            'intro' => 'Nama unit, jabatan, atau status pada berkas yang belum sama dengan Master Formasi harus diputuskan sebelum proses diajukan. Pilihan yang sudah terisi adalah saran sistem: periksa, lalu tekan Petakan. Tidak ada unit atau jabatan yang dibuat.',
            'items' => [
                ['term' => 'Petakan', 'arti' => 'Nama itu sama dengan nama resmi pada Master Formasi, hanya ejaannya berbeda atau sudah berganti.', 'tindakan' => 'Pilih nama resminya, lalu tekan Petakan.'],
                ['term' => 'Apa Adanya', 'arti' => 'Jabatan atau unit itu memang ada dahulu, tetapi tidak ada pada struktur saat ini.', 'tindakan' => 'Simpan sesuai tulisan berkas; riwayat ditandai di luar struktur saat ini.'],
                ['term' => 'Lewati', 'arti' => 'Bukan nama yang sah, misalnya salah ketik atau teks catatan.', 'tindakan' => 'Baris dengan nama ini tidak ditulis.'],
            ],
            'note' => null,
        ];
    }

    /** The practical advice shown beside the upload field: how many files, in what order, and how to check the result. @return Guide */
    public static function upload(int $maxFiles): array
    {
        return [
            'title' => 'Sebelum mengunggah',
            'intro' => 'Hasil rekonstruksi hanya sebaik berkas yang diunggah. Siapkan berkas dengan cara berikut.',
            'items' => [
                ['term' => 'Banyaknya berkas', 'arti' => 'Unggah seluruh bulan yang ingin direkonstruksi sekaligus dalam satu proses, paling banyak '.$maxFiles.' berkas. Sistem membandingkan satu bulan dengan bulan berikutnya, sehingga satu bulan saja tidak menghasilkan riwayat.', 'tindakan' => 'Dua belas bulan berurutan adalah ukuran yang baik.'],
                ['term' => 'Bulan berurutan', 'arti' => 'Bulan yang terlewat di tengah terbaca sebagai karyawan keluar dari daftar lalu kembali.', 'tindakan' => 'Periksa bahwa tidak ada bulan yang hilang sebelum menekan Baca Berkas.'],
                ['term' => 'Mulai dari yang terbaru', 'arti' => 'Mulailah dengan tahun terakhir (2023–2026) agar sistem juga menautkan nomor induk lama ke NIK 8 digit secara otomatis. Proses berikutnya untuk tahun yang lebih lama (2012–2022) hanya mengisi masa sebelum riwayat yang sudah ada.', 'tindakan' => 'Periksa sekitar 20 karyawan terhadap dokumen asli sebelum mengajukan, lalu mundur ke tahun sebelumnya.'],
                ['term' => 'Berkas yang sesuai', 'arti' => 'Satu berkas untuk satu bulan, tanpa mengubah isi berkas asli. Berkas dengan susunan kolom yang berbeda, misalnya dari tahun yang lebih lama, dibaca dalam proses terpisah.', 'tindakan' => 'Unggah berkas apa adanya.'],
            ],
            'note' => null,
        ];
    }

    /** The whole process from upload to undo, in the order an admin works through it. @return Guide */
    public static function workflow(int $approvalDays, int $undoDays): array
    {
        return [
            'title' => 'Alur kerja Rekonstruksi Riwayat Karir',
            'intro' => 'Setiap proses melewati langkah berikut. Tidak ada riwayat yang tersimpan sebelum disetujui admin lain.',
            'items' => [
                ['term' => '1. Unggah dan baca berkas', 'arti' => 'Sistem membaca berkas Bezetting, lalu menghapus berkasnya dari server.', 'tindakan' => 'Pilih berkas, lalu tekan Baca Berkas.'],
                ['term' => '2. Putuskan nama', 'arti' => 'Nama unit, jabatan, atau status yang berbeda dari Master Formasi diputuskan satu per satu: Petakan, Apa Adanya, atau Lewati.', 'tindakan' => 'Periksa saran sistem, lalu putuskan seluruh nama.'],
                ['term' => '3. Tinjau hasil', 'arti' => 'Tinjau Riwayat yang Akan Ditulis, Perlu Tindak Lanjut, dan Dikecualikan. Tiap tab menjelaskan cara membacanya.', 'tindakan' => 'Cocokkan beberapa karyawan dengan dokumen asli; unduh Daftar Verifikasi SDM bila perlu.'],
                ['term' => '4. Ajukan', 'arti' => 'Pengaju mengajukan proses untuk persetujuan setelah seluruh nama diputuskan.', 'tindakan' => 'Tekan Ajukan untuk Persetujuan.'],
                ['term' => '5. Persetujuan admin lain', 'arti' => 'Admin lain menyetujui atau mengembalikan dengan alasan; pengaju tidak dapat menyetujui sendiri. Pengajuan yang tidak diputuskan dalam '.$approvalDays.' hari kedaluwarsa.', 'tindakan' => 'Admin kedua memeriksa ringkasan dan tinjauan, lalu menyetujui atau mengembalikan.'],
                ['term' => '6. Tindak lanjut', 'arti' => 'Perpindahan setelah riwayat pertama di aplikasi tidak ditulis oleh rekonstruksi.', 'tindakan' => 'Catat perpindahan yang benar melalui modul Mutasi beserta SK-nya.'],
                ['term' => '7. Koreksi tanggal', 'arti' => 'Tanggal yang masih perkiraan dikoreksi dari dokumen asli lewat Ubah Riwayat.', 'tindakan' => 'Buka Riwayat Karir karyawan, lalu pilih Ubah Riwayat.'],
                ['term' => '8. Batalkan bila perlu', 'arti' => 'Seluruh proses dapat dibatalkan dalam '.$undoDays.' hari selama riwayatnya belum berubah.', 'tindakan' => 'Pembatalan memerlukan alasan, dan untuk proses besar mengetik kata BATALKAN.'],
            ],
            'note' => 'Riwayat hasil rekonstruksi berlabel sumber Bezetting dan bukan SK mutasi. Rekonstruksi tidak mengubah jabatan atau unit karyawan saat ini.',
        ];
    }

    /** @return Guide */
    public static function history(int $people): array
    {
        return [
            'title' => 'Cara membaca',
            'intro' => number_format($people, 0, ',', '.').' karyawan memperoleh riwayat. Satu baris adalah satu masa jabatan; hanya masa sebelum riwayat pertama karyawan di aplikasi yang ditulis, sehingga umumnya satu baris per karyawan.',
            'items' => [
                ['term' => 'Jenis Perubahan', 'arti' => 'Alasan baris ini ada, dengan istilah yang sama seperti pada Riwayat Karir: Awal Data Bezetting (bulan pertama karyawan terlihat pada berkas, bukan tanggal pengangkatan), Perubahan Unit Kerja, Perubahan Jabatan, Perubahan Status Penugasan, Perubahan Golongan, atau Tugas Rangkap (jabatan tambahan yang berjalan bersamaan).', 'tindakan' => 'Gunakan penyaring Jenis Perubahan, Tanggal Mulai (pasti atau diperkirakan), dan Status Penugasan untuk memeriksa satu kelompok sekaligus; ketiganya dapat digabungkan.'],
                ['term' => 'Mulai', 'arti' => 'Tanggal mulai menjabat, diambil dari kolom Tgl. Dalam Jabatan pada berkas. Bila kolom itu kosong atau tidak cocok, dipakai tanggal 1 pada bulan jabatan itu pertama kali terlihat, dengan label "Mulai diperkirakan".'],
                ['term' => 'Selesai', 'arti' => 'Sehari sebelum riwayat pertama karyawan di aplikasi. Bila menurut berkas jabatan itu sudah berganti lebih dulu, tanggal selesai mengikuti berkas, dengan label "Selesai menurut berkas".'],
                ['term' => 'Label', 'arti' => 'Penanda bahwa tanggalnya belum pasti. Bukan kesalahan: riwayat tetap dapat disimpan.', 'tindakan' => 'Tanggal pasti dapat dikoreksi dari dokumen kepegawaian lewat Ubah Riwayat.'],
            ],
            'note' => null,
        ];
    }

    /** @return Guide */
    public static function followUps(bool $saved, int $retentionDays): array
    {
        $items = [];
        foreach (BezettingBackfillService::FOLLOWUP_HELP as $type => $help) {
            $items[] = ['term' => BezettingBackfillService::FOLLOWUP_LABELS[$type], 'arti' => $help['arti'], 'tindakan' => $help['tindakan']];
        }

        return [
            'title' => 'Cara membaca',
            'intro' => 'Karyawan di bawah diproses (ada pada data karyawan), tetapi ada hal pada berkas yang sengaja tidak ditulis oleh rekonstruksi dan perlu dilihat SDM. Ini tidak menghalangi pengajuan.',
            'items' => $items,
            'note' => $saved ? "Daftar ini disimpan {$retentionDays} hari sesudah rekonstruksi disimpan." : null,
        ];
    }

    /**
     * @param  list<string>|null  $reasons  only the reasons that occur in this run (null: every reason)
     * @return Guide
     */
    public static function exclusions(?array $reasons = null): array
    {
        $items = [];
        foreach (BackfillPlanner::EXCLUSION_HELP as $reason => $help) {
            if ($reasons === null || in_array($reason, $reasons, true)) {
                $items[] = ['term' => BackfillPlanner::EXCLUSION_LABELS[$reason], 'arti' => $help['arti'], 'tindakan' => $help['tindakan']];
            }
        }

        return [
            'title' => 'Cara membaca',
            'intro' => 'Karyawan di bawah ada pada berkas Bezetting, tetapi tidak diproses: tidak ada riwayat yang ditulis untuk mereka pada proses ini. Ini tidak menghalangi pengajuan. Nama tampil seperti pada berkas dan NIK tampil tersamar; NIK utuh hanya ada pada Daftar Verifikasi SDM, untuk pemeriksaan SDM terhadap dokumen asli.',
            'items' => $items,
            'note' => null,
        ];
    }
}
