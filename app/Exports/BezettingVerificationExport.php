<?php

namespace App\Exports;

use App\Support\SpreadsheetCell;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Daftar verifikasi SDM untuk satu proses Rekonstruksi Riwayat dari Bezetting (SECURITY.md §4.4): riwayat per karyawan,
 * perpindahan yang belum tercatat, celah bulan, yang keluar dari daftar, serta (selama proses terbuka) karyawan yang
 * dikecualikan dan baris berkas yang tidak terbaca.
 * Memuat NIK utuh untuk pemeriksaan terhadap dokumen asli; setiap unduhan dicatat oleh pemanggil.
 *
 * @param  array<string, list<list<string>>>  $sheets  judul lembar → baris (tanpa judul kolom)
 */
class BezettingVerificationExport implements Export, WithMultipleSheets
{
    /** Sheet order and headings; only the sheets the caller passes are written (a saved run has no exclusions left to read). */
    /** Follow-up sheet titles are the follow-up labels of BezettingBackfillService (one source). */
    public const HEADINGS = [
        'Riwayat yang ditulis' => ['NIK', 'Nama', 'Jabatan', 'Unit Kerja', 'Status Penugasan', 'Mulai', 'Asal Tanggal Mulai', 'Selesai', 'Alasan Tanggal Selesai', 'Jenis Perubahan', 'Sumber', 'Teks Berkas'],
        'Perubahan perlu diperiksa' => ['NIK', 'Nama', 'Bulan pada Bezetting', 'Dari', 'Ke', 'Catatan', 'Yang Perlu Dilakukan'],
        'Celah bulan' => ['NIK', 'Nama', 'Bulan pada Bezetting', 'Yang Perlu Dilakukan'],
        'Keluar dari daftar' => ['NIK', 'Nama', 'Terakhir Terlihat', 'Yang Perlu Dilakukan'],
        'Dikecualikan' => ['NIK', 'Nama', 'Alasan', 'Periode', 'Yang Perlu Dilakukan'],
        'Baris tidak terbaca' => ['Periode', 'Baris pada Berkas', 'Kendala'],
    ];

    /** @param array<string, list<list<string>>> $sheets */
    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        $result = [];
        foreach (self::HEADINGS as $title => $headings) {
            if (! array_key_exists($title, $this->sheets)) {
                continue;
            }
            $rows = array_map(fn (array $row) => array_map([SpreadsheetCell::class, 'neutralise'], $row), $this->sheets[$title] ?? []);
            $result[] = new class($title, $headings, $rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
            {
                public function __construct(private string $title, private array $headings, private array $rows) {}

                public function array(): array
                {
                    return $this->rows;
                }

                public function headings(): array
                {
                    return $this->headings;
                }

                public function title(): string
                {
                    return $this->title;
                }
            };
        }

        return $result;
    }
}
