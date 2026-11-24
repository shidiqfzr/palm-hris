<?php

namespace App\Services\Bezetting;

use App\Services\Import\ImportDateParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads one monthly Bezetting workbook (DOMAIN.md §5.5) into minimized rows.
 *
 * - Only the first sheet is read; sheet names are ignored (they are wrong in some files).
 * - Columns are found by header name, never by position, because the layout differs from month to month.
 * - Only the columns history needs are read (SECURITY.md §4.4): religion, gender, education, marital status,
 *   birthplace and the old SAP number are never read.
 * - Files without a Unit Kerja column carry the unit as a section heading row above its people; the nearest
 *   heading is used, and also when the Unit Kerja cell is empty.
 */
class BezettingWorkbookReader
{
    public const MONTHS = [
        'JANUARI' => 1, 'FEBRUARI' => 2, 'MARET' => 3, 'APRIL' => 4, 'MEI' => 5, 'JUNI' => 6, 'JULI' => 7,
        'AGUSTUS' => 8, 'SEPTEMBER' => 9, 'OKTOBER' => 10, 'NOVEMBER' => 11, 'DESEMBER' => 12,
    ];

    /** Header text (normalized) → field. A trailing `*` means "starts with". */
    private const HEADERS = [
        'NIK' => 'nik',
        'N A M A' => 'nama',
        'NAMA' => 'nama',
        'JABATAN' => 'jabatan',
        'UNIT KERJA' => 'unit',
        'GOL*' => 'golongan',
        'TGL. DALAM JABATAN*' => 'tanggal_dalam_jabatan',
        'TMT DI BAGIAN*' => 'tmt_unit_kerja',
        'TGL. LAHIR' => 'tanggal_lahir',
        'TGL PENSIUN' => 'tanggal_pensiun',
    ];

    private const REQUIRED = ['nik', 'nama', 'jabatan', 'golongan', 'tanggal_dalam_jabatan'];

    private const DATE_FIELDS = ['tanggal_dalam_jabatan', 'tmt_unit_kerja', 'tanggal_lahir', 'tanggal_pensiun'];

    private const HEADER_SEARCH_ROWS = 15;

    private const MAX_COLUMNS = 60;

    public function __construct(private ImportDateParser $dates) {}

    /**
     * @return array{period: string, title_is_karpim: bool, sheet_count: int, warnings: list<string>,
     *     issues: list<array{row: int, code: string}>, rows: list<array<string, mixed>>}
     *
     * @throws BezettingReadException
     */
    public function read(string $path, string $originalName): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new BezettingReadException("Berkas {$originalName} tidak dapat dibaca sebagai lembar kerja Excel.");
        }

        try {
            $sheet = $book->getSheet(0);
            $sheetCount = $book->getSheetCount();
            $headerRow = $this->findHeaderRow($sheet);
            if ($headerRow === null) {
                throw new BezettingReadException("Baris judul kolom (NIK) tidak ditemukan pada {$originalName}.");
            }

            $columns = $this->mapColumns($sheet, $headerRow);
            $missing = array_diff(self::REQUIRED, array_keys($columns));
            if ($missing !== []) {
                throw new BezettingReadException("Kolom wajib tidak ditemukan pada {$originalName}: ".implode(', ', $missing).'.');
            }

            [$titlePeriod, $isKarpim] = $this->readTitle($sheet, $headerRow);
            $namePeriod = self::periodFromText($originalName);
            if ($titlePeriod !== null && $namePeriod !== null && $titlePeriod !== $namePeriod) {
                throw new BezettingReadException("Periode pada judul {$originalName} ({$titlePeriod}) berbeda dengan nama berkasnya ({$namePeriod}).");
            }
            $period = $titlePeriod ?? $namePeriod;
            if ($period === null) {
                throw new BezettingReadException("Periode (bulan dan tahun) {$originalName} tidak dapat ditentukan.");
            }

            $warnings = [];
            if ($sheetCount > 1) {
                $warnings[] = "Berkas memuat {$sheetCount} lembar; hanya lembar pertama yang dibaca.";
            }
            if ($titlePeriod === null) {
                $warnings[] = 'Judul tidak memuat periode; periode diambil dari nama berkas.';
            }
            if (! $isKarpim) {
                $warnings[] = 'Judul tidak menyebut Karyawan Pimpinan.';
            }

            [$rows, $issues] = $this->readRows($sheet, $headerRow, $columns);
        } finally {
            $book->disconnectWorksheets();
        }

        return [
            'period' => $period,
            'title_is_karpim' => $isKarpim,
            'sheet_count' => $sheetCount,
            'warnings' => $warnings,
            'issues' => $issues,
            'rows' => $rows,
        ];
    }

    /** "PER OKTOBER 2026", "Bez Oktober 2026.xls" → "2026-10"; null when no month and year are found. */
    public static function periodFromText(string $text): ?string
    {
        $pattern = '/\b('.implode('|', array_keys(self::MONTHS)).')\s+(\d{4})\b/';
        if (preg_match($pattern, mb_strtoupper($text), $m) !== 1) {
            return null;
        }

        return sprintf('%04d-%02d', (int) $m[2], self::MONTHS[$m[1]]);
    }

    private static function normalize(mixed $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    private function findHeaderRow(Worksheet $sheet): ?int
    {
        for ($row = 1; $row <= self::HEADER_SEARCH_ROWS; $row++) {
            for ($col = 1; $col <= self::MAX_COLUMNS; $col++) {
                if (self::normalize($sheet->getCell([$col, $row])->getValue()) === 'NIK') {
                    return $row;
                }
            }
        }

        return null;
    }

    /** @return array<string, int> field → column */
    private function mapColumns(Worksheet $sheet, int $headerRow): array
    {
        $columns = [];
        for ($col = 1; $col <= self::MAX_COLUMNS; $col++) {
            $header = self::normalize($sheet->getCell([$col, $headerRow])->getValue());
            if ($header === '') {
                continue;
            }
            foreach (self::HEADERS as $pattern => $field) {
                $matches = str_ends_with($pattern, '*')
                    ? str_starts_with($header, rtrim($pattern, '*'))
                    : $header === $pattern;
                if ($matches && ! isset($columns[$field])) {
                    $columns[$field] = $col;
                    break;
                }
            }
        }

        return $columns;
    }

    /** @return array{0: ?string, 1: bool} period from "PER <BULAN> <TAHUN>" and whether the title says Karyawan Pimpinan */
    private function readTitle(Worksheet $sheet, int $headerRow): array
    {
        $period = null;
        $isKarpim = false;
        for ($row = 1; $row < $headerRow; $row++) {
            for ($col = 1; $col <= 6; $col++) {
                $text = self::normalize($sheet->getCell([$col, $row])->getValue());
                if ($text === '') {
                    continue;
                }
                if (str_contains($text, 'KARYAWAN PIMPINAN')) {
                    $isKarpim = true;
                }
                if ($period === null && str_starts_with($text, 'PER ')) {
                    $period = self::periodFromText($text);
                }
            }
        }

        return [$period, $isKarpim];
    }

    /**
     * @param  array<string, int>  $columns
     * @return array{0: list<array<string, mixed>>, 1: list<array{row: int, code: string}>}
     */
    private function readRows(Worksheet $sheet, int $headerRow, array $columns): array
    {
        $rows = [];
        $issues = [];
        $heading = '';
        $lastRow = $sheet->getHighestDataRow();

        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $nama = trim((string) preg_replace('/\s+/u', ' ', (string) $sheet->getCell([$columns['nama'], $row])->getValue()));
            $nik = $this->nik($sheet->getCell([$columns['nik'], $row])->getValue());

            // Column-number row (1, 2, 3 …) and empty rows.
            if ($nama === '' || preg_match('/^\d+$/', $nama) === 1) {
                continue;
            }

            if ($nik === '') {
                // A section heading has no position and no golongan. A row that has either is a person whose NIK is
                // missing: it is reported, and never becomes a unit name (a person's name must not end up there).
                $jabatanCell = trim((string) $sheet->getCell([$columns['jabatan'], $row])->getValue());
                $golonganCell = trim((string) $sheet->getCell([$columns['golongan'], $row])->getValue());
                if ($jabatanCell === '' && $golonganCell === '') {
                    $heading = $nama;
                } else {
                    $issues[] = ['row' => $row, 'code' => 'nik_kosong'];
                }

                continue;
            }

            if (preg_match('/^\d+$/', $nik) !== 1) {
                $issues[] = ['row' => $row, 'code' => 'nik_tidak_valid'];

                continue;
            }

            $unitCell = isset($columns['unit'])
                ? trim((string) preg_replace('/\s+/u', ' ', (string) $sheet->getCell([$columns['unit'], $row])->getValue()))
                : '';

            $record = [
                'row' => $row,
                'nik' => $nik,
                'nama' => $nama,
                'jabatan' => trim((string) preg_replace('/\s+/u', ' ', (string) $sheet->getCell([$columns['jabatan'], $row])->getValue())),
                'unit' => $unitCell !== '' ? $unitCell : $heading,
                'unit_dari_judul_bagian' => $unitCell === '',
                'golongan' => self::normalize($sheet->getCell([$columns['golongan'], $row])->getValue()),
            ];

            foreach (self::DATE_FIELDS as $field) {
                $record[$field] = isset($columns[$field])
                    ? $this->dates->normalize(trim((string) $sheet->getCell([$columns[$field], $row])->getValue()))
                    : null;
            }

            $rows[] = $record;
        }

        return [$rows, $issues];
    }

    /** NIK as text: numbers lose no digits but keep no decimals; spaces are removed. */
    private function nik(mixed $value): string
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return sprintf('%d', $value);
        }

        return (string) preg_replace('/\s+/', '', (string) $value);
    }
}
