<?php

namespace Tests\Concerns;

use App\Models\BezettingBackfillRun;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use App\Services\Bezetting\BezettingBackfillService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Synthetic monthly Bezetting workbooks and a ready scenario for UI tests (no real personnel data).
 */
trait BuildsBezettingWorkbooks
{
    private const BEZETTING_MONTHS = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret'];

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $people nik, jabatan, unit, tanggal dalam jabatan */
    protected function bezettingWorkbook(int $month, array $people): string
    {
        $rows = [['DAFTAR PERSONIL KARYAWAN PIMPINAN'], ['PER '.strtoupper(self::BEZETTING_MONTHS[$month]).' 2025'], [],
            ['NO', 'N A M A', 'NIK', 'JABATAN', 'Unit Kerja', 'GOL 2025', 'TGL. DALAM JABATAN', 'TMT DI BAGIAN/ UNIT KERJA']];
        foreach ($people as $i => [$nik, $jabatan, $unit, $tgl]) {
            $rows[] = [$i + 1, 'Karyawan Contoh '.$i, $nik, $jabatan, $unit, 'IIIC/02', $tgl, $tgl];
        }
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bez').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    protected function bezettingUpload(int $month, array $people): UploadedFile
    {
        $path = $this->bezettingWorkbook($month, $people);
        $upload = UploadedFile::fake()->createWithContent('Bez '.self::BEZETTING_MONTHS[$month].' 2025.xlsx', (string) file_get_contents($path));
        @unlink($path);

        return $upload;
    }

    /** Master units and positions plus one employee whose application history starts 2025-03-01 as Manajer Kebun. */
    protected function bezettingScenario(): Employee
    {
        foreach (['Kebun Parindu', 'Kebun Gunung Meliau'] as $unit) {
            Unit::factory()->create(['nama' => $unit]);
        }
        foreach (['Asisten Kepala', 'Manajer Kebun'] as $position) {
            Position::factory()->create(['nama' => $position]);
        }

        return Employee::factory()->create([
            'nik' => '99000001', 'jabatan' => 'Manajer Kebun', 'unit_kerja' => 'Kebun Parindu',
            'tanggal_dalam_jabatan' => '2025-03-01', 'tmt_unit_kerja' => '2025-03-01',
        ]);
    }

    /** A staged run over January–March 2025; $janUnit lets a test introduce an unmapped unit name. */
    protected function stagedBezettingRun(User $maker, string $janUnit = 'Kebun Parindu', array $extraJanuary = []): BezettingBackfillRun
    {
        $files = [];
        foreach ([1 => $janUnit, 2 => $janUnit] as $month => $unit) {
            $people = [['99000001', 'Asisten Kepala', $unit, '01-04-2023']];
            if ($month === 1) {
                array_push($people, ...$extraJanuary);
            }
            $files[] = ['path' => $this->bezettingWorkbook($month, $people), 'name' => 'Bez '.self::BEZETTING_MONTHS[$month].' 2025.xlsx'];
        }
        $files[] = ['path' => $this->bezettingWorkbook(3, [['99000001', 'Manajer Kebun', 'Kebun Parindu', '01-03-2025']]), 'name' => 'Bez Maret 2025.xlsx'];

        return app(BezettingBackfillService::class)->stage($files, $maker);
    }
}
