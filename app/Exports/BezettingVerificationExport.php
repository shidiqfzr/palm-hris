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
 * Daftar verifikasi SDM untuk satu proses Rekonstruksi Riwayat dari Bezetting (SECURITY.md §4.4): karyawan yang
 * dikecualikan, yang keluar dari daftar, perpindahan yang belum tercatat, dan baris berkas yang tidak terbaca.
 * Memuat NIK utuh untuk pemeriksaan terhadap dokumen asli; setiap unduhan dicatat oleh pemanggil.
 *
 * @param  array<string, list<list<string>>>  $sheets  judul lembar → baris (tanpa judul kolom)
 */
class BezettingVerificationExport implements Export, WithMultipleSheets
{
    public const HEADINGS = [
        'Dikecualikan' => ['NIK', 'Nama', 'Alasan', 'Periode'],
        'Keluar dari Daftar' => ['NIK', 'Nama', 'Terakhir Terlihat'],
        'Perpindahan Belum Tercatat' => ['NIK', 'Nama', 'Periode'],
        'Baris Tidak Terbaca' => ['Periode', 'Baris pada Berkas', 'Kendala'],
    ];

    /** @param array<string, list<list<string>>> $sheets */
    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        $result = [];
        foreach (self::HEADINGS as $title => $headings) {
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
