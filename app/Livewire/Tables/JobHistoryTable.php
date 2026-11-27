<?php

namespace App\Livewire\Tables;

use App\Livewire\Concerns\WithDataTable;
use App\Models\JobHistory;
use App\Services\Mutasi\FormationService;
use App\Services\Structure\PlacementStructureService;
use App\Support\DataTable\Column;
use App\Support\DataTable\QueryPipeline;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Job (position) history of one employee, embedded on the employee profile page.
 */
class JobHistoryTable extends Component
{
    use WithDataTable;

    /** NIK of the profiled employee; locked so the client cannot repoint the table to another employee. */
    #[Locked]
    public string $employeeId;

    public function mount(string $employeeId): void
    {
        $this->employeeId = $employeeId;
    }

    protected function baseQuery(): Builder
    {
        return JobHistory::query()->where('employee_nik', $this->employeeId);
    }

    /**
     * Five data columns plus actions so the table fits the profile at 1366px without scrolling sideways (DESIGN.md §7.5:
     * at most six data columns, secondary data on a sub-line). Unit sits under the position, the SK under the mutation.
     */
    protected function columns(): array
    {
        return [
            Column::make('tmt_awal', 'Periode')->sortable()->width('w-[19%]'),
            Column::make('jabatan', 'Jabatan & Unit')->sortable()
                ->searchable(fn (Builder $q, string $term) => QueryPipeline::whereLikeAny($q, ['jabatan', 'unit_kerja', 'teks_sumber'], $term)),
            Column::make('golongan', 'RM Band / Golongan')->width('w-[13%]'),
            Column::make('jenis_mutasi', 'Mutasi & SK')
                ->searchable(fn (Builder $q, string $term) => QueryPipeline::whereLikeAny($q, ['jenis_mutasi', 'nomor_sk'], $term))->width('w-[19%]'),
            Column::make('structure', 'Struktur yang Berlaku'),
            Column::make('aksi', 'Aksi')->align('center')->width('w-16'),
        ];
    }

    protected function defaultSort(): array
    {
        return ['tmt_awal', 'desc'];
    }

    protected function tableLabel(): string
    {
        return 'Riwayat Jabatan';
    }

    protected function exportName(): ?string
    {
        return null;
    }

    protected function syncsUrl(): bool
    {
        return false;
    }

    protected function paginationName(): string
    {
        return 'riwayat_jabatan_page';
    }

    public function render(): View
    {
        $formations = app(FormationService::class);

        return view('livewire.tables.job-history-table', [
            // Versi struktur yang berlaku pada tanggal mulai setiap penempatan di halaman ini (DOMAIN.md §3.7).
            'structure' => app(PlacementStructureService::class)->annotate($this->rows->getCollection()),
            // First day of the employee's own (non-Bezetting) history: a rebuilt row ends the day before it.
            'firstOwnStart' => JobHistory::query()->applied()->where('employee_nik', $this->employeeId)
                ->where('sumber', '!=', JobHistory::SUMBER_BEZETTING)->min('tmt_awal'),
            // RM Band of each position per the Master Formasi (DESIGN.md §7.5: the structural anchor); none for old names.
            'rmBands' => $this->rows->getCollection()->pluck('jabatan')->unique()
                ->mapWithKeys(fn ($jabatan) => [$jabatan => $formations->masterPositionRm((string) $jabatan)])->all(),
        ]);
    }
}
