<?php

// Data uji E2E: satu proses Rekonstruksi Riwayat dari Bezetting (sintetis, tanpa data pribadi) dengan satu nama unit lama
// yang belum diputuskan. Dibuat lewat BezettingBackfillService, sama dengan alur layar.
use App\Models\Employee;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use App\Services\Bezetting\BezettingBackfillService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$unit = Unit::query()->orderBy('id')->value('nama');
$jabatan = Position::query()->orderBy('id')->value('nama');

Employee::factory()->create([
    'nik' => '99000077', 'nama' => 'Karyawan Contoh Bezetting', 'jabatan' => $jabatan, 'unit_kerja' => $unit,
    'tanggal_dalam_jabatan' => '2025-03-01', 'tmt_unit_kerja' => '2025-03-01',
]);

$files = [];
foreach ([1 => 'JANUARI', 2 => 'FEBRUARI'] as $month => $name) {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([
        ['DAFTAR PERSONIL KARYAWAN PIMPINAN'], ["PER {$name} 2025"], [],
        ['NO', 'N A M A', 'NIK', 'JABATAN', 'Unit Kerja', 'GOL 2025', 'TGL. DALAM JABATAN'],
        [1, 'Karyawan Contoh Bezetting', '99000077', $jabatan, 'KEBUN UJI LAMA (K.SAWIT)', 'IIIC/02', '01-04-2023'],
    ], null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'bez').'.xlsx';
    (new Xlsx($book))->save($path);
    $files[] = ['path' => $path, 'name' => 'Bez '.ucfirst(strtolower($name)).' 2025.xlsx'];
}

app(BezettingBackfillService::class)->stage($files, User::query()->where('role', 'admin')->orderBy('id')->firstOrFail());
