<?php

namespace App\Services\Bezetting;

use App\Models\BezettingLegacyNikMap;
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

    private const MONTH_ALIASES = [
        'FEBUARI' => 2,
    ];

    /** Header text (normalized) → field. A trailing `*` means "starts with". */
    private const HEADERS = [
        'NIK SAP' => 'nik',
        'NIK' => 'nik',
        'NOMOR INDUK*' => 'nik',
        'NIK PTPN XIII' => 'legacy_nik',
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

    /** Rows the reader cannot use, by issue code, with the wording HR sees (one source for the reader and the HR download). */
    public const ISSUE_LABELS = [
        'nik_kosong' => 'Nama dan jabatan tanpa NIK',
        'nik_tidak_valid' => 'NIK bukan angka',
        'jabatan_tidak_valid' => 'Jabatan terlalu pendek untuk dibaca (periksa isian)',
    ];

    /** A position text this short is a data-entry slip (for example a single letter), not a name HR could decide. */
    private const MIN_JABATAN_LENGTH = 3;

    private const REQUIRED = ['nik', 'nama', 'jabatan', 'golongan'];

    private const DATE_FIELDS = ['tanggal_dalam_jabatan', 'tmt_unit_kerja', 'tanggal_lahir', 'tanggal_pensiun'];

    private const HEADER_SEARCH_ROWS = 15;

    private const MAX_COLUMNS = 60;

    private const SUB_HEADER_KEYWORDS = ['KARYAWAN', 'N I K', 'NIK', 'LAMA', 'TMT', 'THN', 'BLN', '(TAHUN)', '(THN)', 'MASUK', 'KERJA', 'DIANGKAT', 'STAF', 'MBT', 'PENSIUN', 'JABATAN'];

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
            $sheetPeriod = self::periodFromText($sheet->getTitle());
            $warnings = [];

            if ($titlePeriod !== null && $namePeriod !== null && $titlePeriod !== $namePeriod) {
                if ($sheetPeriod === $namePeriod) {
                    $period = $namePeriod;
                    $warnings[] = "Judul berkas menyebut periode {$titlePeriod}, tetapi nama berkas dan nama lembar menyebut {$namePeriod}; periode {$namePeriod} yang digunakan.";
                } else {
                    throw new BezettingReadException("Periode pada judul {$originalName} ({$titlePeriod}) berbeda dengan nama berkasnya ({$namePeriod}).");
                }
            } else {
                $period = $titlePeriod ?? $namePeriod;
            }
            if ($period === null) {
                throw new BezettingReadException("Periode (bulan dan tahun) {$originalName} tidak dapat ditentukan.");
            }

            if ($sheetCount > 1) {
                $warnings[] = "Berkas memuat {$sheetCount} lembar; hanya lembar pertama yang dibaca.";
            }
            if ($titlePeriod === null) {
                $warnings[] = 'Judul tidak memuat periode; periode diambil dari nama berkas.';
            }
            if (! isset($columns['tanggal_dalam_jabatan'])) {
                $warnings[] = 'Berkas tidak memuat kolom Tgl. Dalam Jabatan; tanggal mulai perubahan jabatan akan ditandai perkiraan.';
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
        $months = self::MONTHS + self::MONTH_ALIASES;
        $pattern = '/\b('.implode('|', array_keys($months)).')\s+(\d{4})\b/';
        if (preg_match($pattern, mb_strtoupper($text), $m) !== 1) {
            return null;
        }

        return sprintf('%04d-%02d', (int) $m[2], $months[$m[1]]);
    }

    private static function normalize(mixed $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    private function findHeaderRow(Worksheet $sheet): ?int
    {
        for ($row = 1; $row <= self::HEADER_SEARCH_ROWS; $row++) {
            for ($col = 1; $col <= self::MAX_COLUMNS; $col++) {
                $header = self::normalize($sheet->getCell([$col, $row])->getValue());
                if (in_array($header, ['NIK', 'NIK SAP', 'NOMOR INDUK', 'NOMOR INDUK KARYAWAN'], true)) {
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
            $sub1 = self::normalize($sheet->getCell([$col, $headerRow + 1])->getValue());
            $sub2 = self::normalize($sheet->getCell([$col, $headerRow + 2])->getValue());
            $combined = trim(implode(' ', array_filter([$header, $sub1, $sub2], fn (string $s) => $s !== '' && preg_match('/^\d+$/', $s) !== 1)));

            if ($header === '' && $combined === '') {
                continue;
            }

            if ($header === 'NIK SAP') {
                $columns['nik'] = $col;

                continue;
            }

            $matched = false;
            foreach (self::HEADERS as $pattern => $field) {
                $matches = str_ends_with($pattern, '*')
                    ? str_starts_with($header, rtrim($pattern, '*'))
                    : $header === $pattern;
                if ($matches && ! isset($columns[$field])) {
                    $columns[$field] = $col;
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            // Era 4 (Jan 2012 – Jan 2015) multi-row headers: "Masa Kerja / Di Unit Kerja / TMT" and "Tanggal / Pensiun".
            if (! isset($columns['tmt_unit_kerja']) && str_contains($combined, 'DI UNIT KERJA') && str_contains($combined, 'TMT')) {
                $columns['tmt_unit_kerja'] = $col;
            }
            if (! isset($columns['tanggal_pensiun']) && ($sub1 === 'PENSIUN' || (str_contains($combined, 'TANGGAL') && str_contains($combined, 'PENSIUN')))) {
                $columns['tanggal_pensiun'] = $col;
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
            $rawNikValue = $sheet->getCell([$columns['nik'], $row])->getValue();
            $rawNik = $this->rawIdString($rawNikValue);
            $jabatan = trim((string) preg_replace('/\s+/u', ' ', (string) $sheet->getCell([$columns['jabatan'], $row])->getValue()));
            $golonganCell = trim((string) $sheet->getCell([$columns['golongan'], $row])->getValue());

            // Empty rows and column-number rows (1, 2, 3 …).
            if (($nama === '' || preg_match('/^\d+$/', $nama) === 1) && ($rawNik === '' || preg_match('/^\d+$/', $rawNik) === 1)) {
                continue;
            }

            // Skip multi-row sub-headers immediately below $headerRow (e.g. "Karyawan", "2012", "TMT", "Lama").
            if ($row <= $headerRow + 3 && $jabatan === '' && ($golonganCell === '' || preg_match('/^\d{4}$/', $golonganCell) === 1)
                && ($nama === '' || in_array(self::normalize($nama), self::SUB_HEADER_KEYWORDS, true))
                && ($rawNik === '' || in_array(self::normalize($rawNik), self::SUB_HEADER_KEYWORDS, true))) {
                continue;
            }

            $legacyFromNikCol = BezettingLegacyNikMap::hashFromRaw($rawNik);
            $nikCandidate = $this->nik($rawNikValue);

            // A section heading has no position, no golongan, and no numeric/legacy ID. In Feb 2015–2026 it sits in the
            // Nama column; in Jan 2012–Jan 2015 (where Nomor Induk is Col 2) it sits in the Nomor Induk column.
            if ($jabatan === '' && $golonganCell === '' && $legacyFromNikCol === null && ($nikCandidate === '' || preg_match('/^\d+$/', $nikCandidate) !== 1)) {
                $headingText = $nama !== '' ? $nama : $rawNik;
                if ($headingText !== '' && preg_match('/^[IVX]+\.?$/i', $headingText) !== 1) {
                    $heading = $headingText;
                }

                continue;
            }

            $isLegacyNik = false;
            $legacyHash = null;
            if ($legacyFromNikCol !== null) {
                $nik = '';
                $isLegacyNik = true;
                $legacyHash = $legacyFromNikCol;
            } else {
                $nik = $nikCandidate;
                if ($nik === '') {
                    $issues[] = ['row' => $row, 'code' => 'nik_kosong'];

                    continue;
                }
                if (preg_match('/^\d+$/', $nik) !== 1) {
                    $issues[] = ['row' => $row, 'code' => 'nik_tidak_valid'];

                    continue;
                }
                $legacyHash = $this->extractCompanionLegacyHash($sheet, $row, $columns);
            }

            $unitCell = isset($columns['unit'])
                ? trim((string) preg_replace('/\s+/u', ' ', (string) $sheet->getCell([$columns['unit'], $row])->getValue()))
                : '';

            if ($jabatan !== '' && mb_strlen($jabatan) < self::MIN_JABATAN_LENGTH) {
                // The NIK is valid, so the person IS listed this month; the planner keeps them present and carries their last state forward.
                $issue = ['row' => $row, 'code' => 'jabatan_tidak_valid'];
                if ($nik !== '') {
                    $issue['nik'] = $nik;
                }
                $issues[] = $issue;

                continue;
            }

            $record = [
                'row' => $row,
                'nik' => $nik,
                'legacy_nik_hash' => $legacyHash,
                'is_legacy_nik' => $isLegacyNik,
                'nama' => $nama,
                'jabatan' => $jabatan,
                'unit' => $unitCell !== '' ? $unitCell : $heading,
                'unit_dari_judul_bagian' => $unitCell === '',
                'golongan' => self::normalize($golonganCell),
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

    /**
     * Extracts the keyed HMAC-SHA256 hash of a companion legacy PTPN XIII ID (14–15 digits) on dual-ID rows
     * (Oct 2022–Oct 2026): from the labelled "NIK PTPN XIII" column or an unlabelled column between NIK and JABATAN.
     *
     * @param  array<string, int>  $columns
     */
    private function extractCompanionLegacyHash(Worksheet $sheet, int $row, array $columns): ?string
    {
        if (isset($columns['legacy_nik'])) {
            return BezettingLegacyNikMap::hashFromRaw($this->rawIdString($sheet->getCell([$columns['legacy_nik'], $row])->getValue()));
        }

        $nikCol = $columns['nik'];
        $jabatanCol = $columns['jabatan'];
        for ($col = $nikCol + 1; $col < $jabatanCol; $col++) {
            $hash = BezettingLegacyNikMap::hashFromRaw($this->rawIdString($sheet->getCell([$col, $row])->getValue()));
            if ($hash !== null) {
                return $hash;
            }
        }

        return null;
    }

    /** Formats numeric cell values (including 14–15 digit floats >= 1e14) without scientific notation. */
    private function rawIdString(mixed $value): string
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return sprintf('%.0f', $value);
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
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
