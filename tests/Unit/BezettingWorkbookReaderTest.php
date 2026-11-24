<?php

namespace Tests\Unit;

use App\Services\Bezetting\BezettingReadException;
use App\Services\Bezetting\BezettingWorkbookReader;
use App\Services\Import\ImportDateParser;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * SUT: BezettingWorkbookReader — header-name mapping across layouts, heading-row units, first sheet only, period from the
 * title, junk rows dropped, excluded personal columns never read (DOMAIN.md §5.5, SECURITY.md §4.4). Synthetic data only.
 */
class BezettingWorkbookReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function reader(): BezettingWorkbookReader
    {
        return new BezettingWorkbookReader(new ImportDateParser);
    }

    /**
     * @param  list<list<mixed>>  $rows  rows from row 1, written from column $startColumn
     * @param  list<list<mixed>>|null  $secondSheet
     */
    private function workbook(array $rows, int $startColumn = 1, ?array $secondSheet = null): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, $this->column($startColumn).'1', true);
        if ($secondSheet !== null) {
            $book->createSheet()->fromArray($secondSheet, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'bez').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->files[] = $path;

        return $path;
    }

    private function column(int $index): string
    {
        return chr(64 + $index);
    }

    private function serial(string $date): float
    {
        return ExcelDate::PHPToExcel(new \DateTime($date));
    }

    /** Layout with a Unit Kerja column (2026 style). */
    private function columnLayout(): array
    {
        return [
            ['', 'PT PERKEBUNAN NUSANTARA IV'],
            ['DAFTAR PERSONIL KARYAWAN PIMPINAN'],
            ['PER OKTOBER 2026'],
            [],
            ['NO', 'N A M A', 'NIK', '', 'JABATAN', 'Unit Kerja', 'Agama', "GOL\n2026", 'TGL. DALAM JABATAN', 'TMT DI BAGIAN/ UNIT KERJA', 'TGL. LAHIR', 'TGL PENSIUN', 'Pendidikan Terakhir'],
            ['1', '2', '3', '', '4', '', '', '5', '6', '7', '8', '9', '10'],
            ['I.', 'REGIONAL OFFICE'],
            [1, 'Karyawan Contoh Satu', 99000001, '000.0421.0394.0886', 'Region Head', '', 'Islam', 'IVD/02', $this->serial('2025-03-12'), $this->serial('2025-03-12'), $this->serial('1969-09-17'), $this->serial('2025-10-01'), 'S2'],
            [2, 'Karyawan Contoh Dua', '99000002', '', 'Asisten Afdeling V', 'Kebun Parindu', 'Katolik', 'IIIA/07', '01-07-2022', '01-07-2026', '12/11/1975', '', 'SLTA'],
        ];
    }

    public function test_reads_columns_by_header_name_and_falls_back_to_the_heading_when_the_unit_cell_is_empty(): void
    {
        $result = $this->reader()->read($this->workbook($this->columnLayout()), 'Bez Oktober 2026.xlsx');

        $this->assertSame('2026-10', $result['period']);
        $this->assertTrue($result['title_is_karpim']);
        $this->assertCount(2, $result['rows']);

        [$head, $assistant] = $result['rows'];
        $this->assertSame('99000001', $head['nik']);
        $this->assertSame('REGIONAL OFFICE', $head['unit']);
        $this->assertTrue($head['unit_dari_judul_bagian']);
        $this->assertSame('2025-03-12', $head['tanggal_dalam_jabatan']);
        $this->assertSame('IVD/02', $head['golongan']);

        $this->assertSame('Kebun Parindu', $assistant['unit']);
        $this->assertFalse($assistant['unit_dari_judul_bagian']);
        $this->assertSame('2022-07-01', $assistant['tanggal_dalam_jabatan']);
        $this->assertNull($assistant['tanggal_pensiun']);
    }

    public function test_personal_columns_history_does_not_need_are_never_read(): void
    {
        $row = $this->reader()->read($this->workbook($this->columnLayout()), 'Bez Oktober 2026.xlsx')['rows'][0];

        $this->assertSame(
            ['row', 'nik', 'nama', 'jabatan', 'unit', 'unit_dari_judul_bagian', 'golongan', 'tanggal_dalam_jabatan', 'tmt_unit_kerja', 'tanggal_lahir', 'tanggal_pensiun'],
            array_keys($row),
        );
        $this->assertNotContains('Islam', $row);
        $this->assertNotContains('S2', $row);
        $this->assertNotContains('000.0421.0394.0886', $row);
    }

    public function test_heading_layout_shifted_one_column_reads_units_from_headings_and_only_the_first_sheet(): void
    {
        $rows = [
            ['DAFTAR PERSONIL KARYAWAN PIMPINAN'],
            ['PER JANUARI 2025'],
            [],
            ['NO', 'N A M A', 'NIK', '', 'JABATAN', 'GOL 2024', 'TGL. DALAM JABATAN', 'TMT DI BAGIAN/ UNIT KERJA'],
            ['', '2', '3', '', '', '5', '6', '7'],
            ['', 'BAGIAN TANAMAN'],
            ['=B5+1', 'Karyawan Contoh Tiga', 9900272, '', 'Penugasan sebagai Kepala Bagian Tanaman', 'IVA/01', $this->serial('2024-12-01'), ''],
            ['', 'KEBUN INTI GUNUNG MELIAU (K.SAWIT)'],
            [2, 'Karyawan Contoh Empat', 'ABC', '', 'Asisten Afdeling I', 'IIIA/03', '', ''],
            [3, 'Karyawan Contoh Lima', 99000005, '', 'Asisten Afdeling II merangkap Plt. Asisten Afdeling III', 'IIIB/01', '', ''],
        ];
        $path = $this->workbook($rows, 2, [['NIK'], ['99000009']]);

        $result = $this->reader()->read($path, 'Bez Januari 2025.xls');

        $this->assertSame('2025-01', $result['period']);
        $this->assertSame(2, $result['sheet_count']);
        $this->assertContains('Berkas memuat 2 lembar; hanya lembar pertama yang dibaca.', $result['warnings']);
        $this->assertSame(['9900272', '99000005'], array_column($result['rows'], 'nik'), 'seven-digit NIK kept as written, never padded');
        $this->assertSame(['BAGIAN TANAMAN', 'KEBUN INTI GUNUNG MELIAU (K.SAWIT)'], array_column($result['rows'], 'unit'));
        $this->assertSame([['row' => 9, 'code' => 'nik_tidak_valid']], $result['issues']);
        $this->assertNull($result['rows'][1]['tanggal_dalam_jabatan']);
    }

    public function test_a_person_without_a_nik_is_reported_and_never_becomes_a_unit_heading(): void
    {
        $rows = [
            ['PER MARET 2025'],
            ['NO', 'N A M A', 'NIK', 'JABATAN', 'GOL 2025', 'TGL. DALAM JABATAN'],
            ['', 'BAGIAN TANAMAN'],
            [1, 'Karyawan Tanpa NIK', '', 'Asisten Tanaman', 'IIIA/01', ''],
            [2, 'Karyawan Contoh Tujuh', 99000007, 'Asisten Pemupukan', 'IIIA/02', ''],
        ];

        $result = $this->reader()->read($this->workbook($rows), 'Bez Maret 2025.xls');

        $this->assertSame([['row' => 4, 'code' => 'nik_kosong']], $result['issues']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('BAGIAN TANAMAN', $result['rows'][0]['unit']);
    }

    public function test_period_falls_back_to_the_file_name_with_a_warning(): void
    {
        $rows = $this->columnLayout();
        $rows[2] = [''];

        $result = $this->reader()->read($this->workbook($rows), 'Bez Oktober 2026.xls');

        $this->assertSame('2026-10', $result['period']);
        $this->assertContains('Judul tidak memuat periode; periode diambil dari nama berkas.', $result['warnings']);
    }

    public function test_title_and_file_name_that_disagree_are_rejected(): void
    {
        $this->expectException(BezettingReadException::class);
        $this->expectExceptionMessage('berbeda dengan nama berkasnya');

        $this->reader()->read($this->workbook($this->columnLayout()), 'Bez September 2026.xls');
    }

    public function test_a_workbook_without_the_required_columns_is_rejected_without_personal_data_in_the_message(): void
    {
        $rows = [['PER MEI 2025'], ['NO', 'N A M A', 'NIK', 'JABATAN'], [1, 'Karyawan Contoh Enam', 99000006, 'Asisten']];

        try {
            $this->reader()->read($this->workbook($rows), 'Bez Mei 2025.xls');
            $this->fail('Expected a read exception');
        } catch (BezettingReadException $e) {
            $this->assertStringContainsString('golongan, tanggal_dalam_jabatan', $e->getMessage());
            $this->assertStringNotContainsString('99000006', $e->getMessage());
            $this->assertStringNotContainsString('Karyawan Contoh', $e->getMessage());
        }
    }

    public function test_a_workbook_without_a_nik_header_is_rejected(): void
    {
        $this->expectException(BezettingReadException::class);
        $this->expectExceptionMessage('Baris judul kolom (NIK) tidak ditemukan');

        $this->reader()->read($this->workbook([['PER MEI 2025'], ['NAMA', 'JABATAN']]), 'Bez Mei 2025.xls');
    }

    public function test_period_is_read_from_indonesian_month_names(): void
    {
        $this->assertSame('2025-12', BezettingWorkbookReader::periodFromText('PER DESEMBER 2025'));
        $this->assertSame('2026-05', BezettingWorkbookReader::periodFromText('Bez Mei 2026.xls'));
        $this->assertNull(BezettingWorkbookReader::periodFromText('Bezetting terbaru'));
    }
}
