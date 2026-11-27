<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeletionReasonRequest;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Models\Position;
use App\Models\Unit;
use App\Services\JobHistoryCorrectionService;
use App\Services\Mutasi\FormationService;
use App\Support\ReasonRule;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Manual correction of Riwayat Karir. The rules live in JobHistoryCorrectionService: closed rows only (a running
 * position changes through Mutasi, AGENTS.md rule 8), standard golongan, level from the RM Band, no overlapping
 * definitive periods, and a reason recorded on master_audit_logs for every add, change and removal.
 */
class JobHistoryController extends Controller
{
    public function __construct(private JobHistoryCorrectionService $corrections, private FormationService $formations) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Employee $employee)
    {
        $histories = $employee->jobHistories()->paginate((int) config('hris.pagination.default'));

        return view('admin.employees.job-history.index', compact('employee', 'histories'));
    }

    public function create(Employee $employee)
    {
        return view('admin.employees.job-history.create', $this->formData($employee, null));
    }

    public function store(Request $request, Employee $employee)
    {
        $data = $this->validated($request, false);
        $this->corrections->create($employee, $data, $request->user(), $data['alasan']);

        return redirect()->to($this->profile($employee))
            ->with('success', 'Riwayat jabatan ditambahkan. Penambahan dan alasannya tercatat pada riwayat perubahan.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Employee $employee, JobHistory $job_history)
    {
        return view('admin.employees.job-history.show', compact('employee', 'job_history'));
    }

    public function edit(Employee $employee, JobHistory $job_history)
    {
        return view('admin.employees.job-history.edit', $this->formData($employee, $job_history));
    }

    public function update(Request $request, Employee $employee, JobHistory $job_history)
    {
        abort_unless($job_history->employee_nik === $employee->nik, 404);
        $data = $this->validated($request, $this->corrections->isRunning($employee, $job_history));
        $this->corrections->update($employee, $job_history, $data, $request->user(), $data['alasan']);

        return redirect()->to($this->profile($employee))
            ->with('success', 'Riwayat jabatan diperbarui. Perubahan dan alasannya tercatat pada riwayat perubahan.');
    }

    /**
     * The only way to remove a history row (the profile table opens the shared confirmation dialog that posts here).
     * Soft delete with who and why (DOMAIN.md §2.2: history is never deleted permanently; SECURITY.md item 6a: a reason
     * is required). The current position and open rows are refused: they change only through Mutasi.
     */
    public function destroy(DeletionReasonRequest $request, Employee $employee, JobHistory $job_history)
    {
        abort_unless($job_history->employee_nik === $employee->nik, 404);

        try {
            $this->corrections->delete($employee, $job_history, $request->user(), $request->validated('alasan'));
        } catch (ValidationException $e) {
            return redirect()->to($this->profile($employee))->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->to($this->profile($employee))
            ->with('success', 'Riwayat jabatan dihapus. Catatan dan alasannya tetap tersimpan sebagai jejak audit.');
    }

    /**
     * Input format checks only; business rules are in the service. A running row sends only its note and the reason.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $running): array
    {
        $reason = ['alasan' => ReasonRule::rules()];
        $messages = ReasonRule::messages('alasan', 'perubahan') + [
            'tmt_akhir.after_or_equal' => 'TMT Akhir tidak boleh lebih awal dari TMT Awal.',
            'level.in' => 'Level harus Karpim atau Karpel.',
            'jenis_mutasi.in' => 'Jenis mutasi tidak dikenal.',
        ];

        if ($running) {
            return $request->validate(['catatan' => 'nullable|string|max:2000'] + $reason, $messages);
        }

        $rules = [
            'jabatan' => 'required|string|max:255',
            'unit_kerja' => 'required|string|max:255',
            'level' => 'nullable|string|in:'.implode(',', JobHistoryCorrectionService::LEVELS),
            'golongan' => 'nullable|string|max:20',
            'tmt_awal' => 'required|date',
            'tmt_akhir' => 'nullable|date|after_or_equal:tmt_awal',
            'jenis_mutasi' => 'nullable|string|in:'.implode(',', JobHistory::MUTASI_TYPES),
            'nomor_sk' => 'nullable|string|max:100',
            'tanggal_sk' => 'nullable|date',
            'catatan' => 'nullable|string|max:2000',
            'nama_di_luar_master' => 'nullable|boolean',
        ] + $reason;

        return $request->validate($rules, $messages);
    }

    /** @return array<string, mixed> */
    private function formData(Employee $employee, ?JobHistory $row): array
    {
        abort_if($row !== null && $row->employee_nik !== $employee->nik, 404);
        $running = $row !== null && $this->corrections->isRunning($employee, $row);

        return [
            'employee' => $employee,
            'jobHistory' => $row,
            'running' => $running,
            'rmBand' => $row ? $this->corrections->rmBand((string) $row->jabatan) : null,
            'mutasiOptions' => collect(JobHistory::MUTASI_TYPES)->mapWithKeys(fn ($type) => [$type => JobHistory::labelFor($type)])->all(),
            'levelOptions' => array_combine(JobHistoryCorrectionService::LEVELS, JobHistoryCorrectionService::LEVELS),
            // Master Formasi pick lists (DESIGN.md §6: unit-combobox and searchable-select, no free text). Inactive units
            // and positions stay selectable for history, marked as such.
            'groupedUnits' => $this->groupedUnits(),
            'positionOptions' => Position::query()->orderByDesc('is_active')->orderBy('nama')->get(['nama', 'is_active'])
                ->mapWithKeys(fn ($p) => [$p->nama => $p->is_active ? $p->nama : $p->nama.' (nonaktif)'])
                ->when($row && ! Position::where('nama', $row->jabatan)->exists(), fn ($c) => $c->put($row->jabatan, $row->jabatan.' (nama lama, di luar Master Formasi)'))
                ->all(),
            'rmMap' => $this->formations->masterRmMap(),
            // Unit → positions per structure version, so the position list follows the unit and the start date.
            'formasi' => $this->corrections->formasiForForm(),
        ];
    }

    /** @return array<string, list<string>> wilayah → unit names; inactive units grouped last */
    private function groupedUnits(): array
    {
        $units = Unit::query()->orderBy('urutan')->orderBy('nama')->get(['nama', 'wilayah', 'is_active']);

        return $units->where('is_active', true)->groupBy(fn ($u) => $u->wilayah ?: 'Lainnya')
            ->map(fn ($group) => $group->pluck('nama')->all())
            ->put('Unit Nonaktif', $units->where('is_active', false)->pluck('nama')->all())
            ->filter()
            ->all();
    }

    private function profile(Employee $employee): string
    {
        return route('admin.employees.show', $employee->nik).'#riwayat-jabatan';
    }
}
