<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Services\MutasiService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobHistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Employee $employee)
    {
        $histories = $employee->jobHistories()->paginate((int) config('hris.pagination.default'));

        return view('admin.employees.job-history.index', compact('employee', 'histories'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Employee $employee)
    {
        $mutasiOptions = JobHistory::MUTASI_TYPES;

        return view('admin.employees.job-history.create', compact('employee', 'mutasiOptions'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Employee $employee, MutasiService $service)
    {
        $data = $request->validate([
            'jabatan' => 'required|string|max:255',
            'unit_kerja' => 'required|string|max:255',
            'level' => 'nullable|string|max:255',
            'golongan' => 'nullable|string|max:50',
            'tmt_awal' => 'required|date',
            'tmt_akhir' => 'nullable|date|after_or_equal:tmt_awal',
            'jenis_mutasi' => 'nullable|string|in:'.implode(',', JobHistory::MUTASI_TYPES),
            'nomor_sk' => 'nullable|string|max:100',
            'tanggal_sk' => 'nullable|date',
            'catatan' => 'nullable|string|max:2000',
        ]);

        $data['employee_nik'] = $employee->nik;
        $data['created_by'] = auth()->id();

        DB::transaction(function () use ($data, $employee, $service) {
            // Only one active definitive row — close the current one (scheduled & Pj/Plt rows untouched)
            if (empty($data['tmt_akhir'])) {
                $employee->currentJob?->update([
                    'tmt_akhir' => Carbon::parse($data['tmt_awal'])->subDay()->toDateString(),
                ]);
            }

            JobHistory::create($data);

            if (empty($data['tmt_akhir'])) {
                $this->syncEmployeeProfile($employee, $data, $service);
            }
        });

        return redirect()
            ->to(route('admin.employees.show', $employee->nik).'#riwayat-jabatan')
            ->with('success', 'Riwayat jabatan ditambahkan.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Employee $employee, JobHistory $job_history)
    {
        return view('admin.employees.job-history.show', compact('employee', 'job_history'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Employee $employee, JobHistory $job_history)
    {
        $mutasiOptions = JobHistory::MUTASI_TYPES;

        return view('admin.employees.job-history.edit', [
            'employee' => $employee,
            'jobHistory' => $job_history,
            'mutasiOptions' => $mutasiOptions,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Employee $employee, JobHistory $job_history, MutasiService $service)
    {
        $data = $request->validate([
            'jabatan' => 'required|string|max:255',
            'unit_kerja' => 'required|string|max:255',
            'level' => 'nullable|string|max:255',
            'golongan' => 'nullable|string|max:50',
            'tmt_awal' => 'required|date',
            'tmt_akhir' => 'nullable|date|after_or_equal:tmt_awal',
            'jenis_mutasi' => 'nullable|string|in:'.implode(',', JobHistory::MUTASI_TYPES),
            'nomor_sk' => 'nullable|string|max:100',
            'tanggal_sk' => 'nullable|date',
            'catatan' => 'nullable|string|max:2000',
        ]);

        // Riwayat hasil rekonstruksi Bezetting tetap catatan tertutup tanpa SK (DOMAIN.md §5.5 butir 2 dan 4): baris
        // terbuka akan dihitung sebagai pemegang formasi, dan SK hanya berasal dari modul Mutasi.
        if ($job_history->sumber === JobHistory::SUMBER_BEZETTING) {
            $request->validate(
                ['tmt_akhir' => 'required', 'nomor_sk' => 'prohibited', 'tanggal_sk' => 'prohibited', 'jenis_mutasi' => 'prohibited'],
                [
                    'tmt_akhir.required' => 'Riwayat dari Bezetting wajib memiliki tanggal berakhir.',
                    'nomor_sk.prohibited' => 'Riwayat dari Bezetting bukan SK mutasi, sehingga nomor SK tidak dapat diisi. Catat SK lewat modul Mutasi.',
                    'tanggal_sk.prohibited' => 'Riwayat dari Bezetting bukan SK mutasi, sehingga tanggal SK tidak dapat diisi.',
                    'jenis_mutasi.prohibited' => 'Riwayat dari Bezetting tidak memiliki jenis mutasi resmi.',
                ],
            );
        }

        DB::transaction(function () use ($data, $employee, $job_history, $service) {
            $job_history->update($data);

            // Sync master profile only for the applied, definitive, active row
            if (empty($data['tmt_akhir']) && $job_history->is_applied && $job_history->status_penugasan === 'Definitif') {
                $this->syncEmployeeProfile($employee, $data, $service);
            }
        });

        return redirect()
            ->to(route('admin.employees.show', $employee->nik).'#riwayat-jabatan')
            ->with('success', 'Riwayat jabatan diperbarui.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Employee $employee, JobHistory $job_history)
    {
        // The active definitive row mirrors the master profile; removing it would orphan the current position
        if ($employee->currentJob?->is($job_history)) {
            return redirect()
                ->to(route('admin.employees.show', $employee->nik).'#riwayat-jabatan')
                ->with('error', 'Riwayat jabatan aktif tidak dapat dihapus. Gunakan modul Mutasi & Penempatan untuk mengubah jabatan karyawan.');
        }

        // Soft delete — the record is retained for audit trail purposes
        $job_history->delete();

        return redirect()
            ->to(route('admin.employees.show', $employee->nik).'#riwayat-jabatan')
            ->with('success', 'Riwayat jabatan dihapus.');
    }

    /**
     * Mirror the active job row onto the employee master record through the shared
     * MutasiService write path (no duplicate history row, RM band & Karpim/Karpel per DOMAIN.md §4.1).
     */
    private function syncEmployeeProfile(Employee $employee, array $data, MutasiService $service): void
    {
        $rmLevel = $service->resolvePositionRm($data['jabatan']);

        $service->applyPlacementToEmployee($employee, $data['jabatan'], $data['unit_kerja'], $data['tmt_awal'], [
            'rm_level' => $rmLevel,
            'level' => $data['level'] ?? $service->deriveLevelForRm($rmLevel, $employee->level),
            'golongan' => $data['golongan'] ?? $employee->golongan,
        ]);
    }
}
