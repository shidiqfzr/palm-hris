<?php

namespace App\Livewire\Admin\Employees;

use App\Livewire\Concerns\WithCollectionTable;
use App\Models\BezettingBackfillRun;
use App\Models\Employee;
use App\Models\JobHistory;
use App\Services\Bezetting\BackfillPlanner;
use App\Services\Bezetting\BezettingBackfillService;
use App\Support\BezettingReviewGuide;
use App\Support\DataTable\Filter;
use App\Support\HrisPermission;
use App\Support\NikMasker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Rekap per Karyawan dan daftar Perlu Tindak Lanjut satu proses Rekonstruksi Riwayat (DOMAIN.md §5.5 butir 9): pratinjau
 * sebelum disetujui dan rekonsiliasi sesudah tersimpan. Datanya dari BezettingBackfillService::recap(); layar hanya
 * menyaring dan menampilkan. NIK selalu tersamar (UU PDP); nama tampil agar SDM dapat memeriksa per orang.
 */
class BezettingRunRecap extends Component
{
    use WithCollectionTable;
    use WithPagination;

    public const VIEWS = ['karyawan' => 'Riwayat yang Ditulis', 'tindak_lanjut' => 'Perlu Tindak Lanjut', 'dikecualikan' => 'Dikecualikan'];

    private const FILTERS = [
        // The history tab has its own named filters (Tanggal Mulai, Status Penugasan, Jenis Perubahan), not a generic one.
        'karyawan' => [],
        'tindak_lanjut' => BezettingBackfillService::FOLLOWUP_LABELS,
        'dikecualikan' => BackfillPlanner::EXCLUSION_LABELS,
    ];

    private const PAGE = 'rekap';

    #[Locked]
    public int $runId;

    /** Fixed per tab by the run page (one flat tab bar: no tabs inside this component). */
    #[Locked]
    public string $view = 'karyawan';

    public string $search = '';

    public string $saring = '';

    /** Kind of change (a JobHistory::JENIS_PERUBAHAN_LABELS code) on the history tab; '' = all. */
    public string $jenis = '';

    /** Whether the start date is exact or estimated ('pasti' | 'perkiraan') on the history tab; '' = all. */
    public string $mulai = '';

    /** Assignment status on the history tab ('definitif' | 'bukan_definitif'); '' = all. */
    public string $penugasan = '';

    private const MULAI = ['pasti' => 'Pasti (dari berkas)', 'perkiraan' => 'Diperkirakan'];

    private const PENUGASAN = ['definitif' => 'Definitif', 'bukan_definitif' => 'Bukan definitif (Plt, Pjs, dan sejenisnya)'];

    public int $perPage = 25;

    public function boot(): void
    {
        $this->authorizeImport();
    }

    /** $saring: a filter asked for by a summary counter on the run page; anything unknown falls back to none. */
    public function mount(int $runId, string $view = 'karyawan', string $saring = ''): void
    {
        $this->authorizeImport();
        $this->runId = $runId;
        $this->view = array_key_exists($view, self::VIEWS) ? $view : 'karyawan';
        $this->saring = isset(self::FILTERS[$this->view][$saring]) ? $saring : '';
        // The summary counter "Tanggal mulai perkiraan" opens the history tab with that filter.
        $this->mulai = $this->view === 'karyawan' && $saring === 'perkiraan' ? 'perkiraan' : '';
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 100);
        $this->resetPage(self::PAGE);
    }

    public function updatedSaring(): void
    {
        $this->saring = isset(self::FILTERS[$this->view][$this->saring]) ? $this->saring : '';
        $this->resetPage(self::PAGE);
    }

    public function updatedMulai(): void
    {
        $this->mulai = $this->view === 'karyawan' && isset(self::MULAI[$this->mulai]) ? $this->mulai : '';
        $this->resetPage(self::PAGE);
    }

    public function updatedPenugasan(): void
    {
        $this->penugasan = $this->view === 'karyawan' && isset(self::PENUGASAN[$this->penugasan]) ? $this->penugasan : '';
        $this->resetPage(self::PAGE);
    }

    public function updatedJenis(): void
    {
        $this->jenis = $this->view === 'karyawan' && isset(JobHistory::JENIS_PERUBAHAN_LABELS[$this->jenis]) ? $this->jenis : '';
        $this->resetPage(self::PAGE);
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalisedPerPage();
        $this->resetPage(self::PAGE);
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->saring = '';
        $this->jenis = '';
        $this->mulai = '';
        $this->penugasan = '';
        $this->resetPage(self::PAGE);
    }

    /** @return list<Filter> */
    public function filters(): array
    {
        return match ($this->view) {
            'karyawan' => [
                Filter::select('jenis', 'Jenis Perubahan', JobHistory::JENIS_PERUBAHAN_LABELS)->icon('fas fa-code-compare')->allLabel('Semua jenis'),
                Filter::select('mulai', 'Tanggal Mulai', self::MULAI)->icon('fas fa-calendar-day')->allLabel('Semua'),
                Filter::select('penugasan', 'Status Penugasan', self::PENUGASAN)->icon('fas fa-user-tag')->allLabel('Semua'),
            ],
            'tindak_lanjut' => [Filter::select('saring', 'Jenis', self::FILTERS['tindak_lanjut'])->icon('fas fa-filter')->allLabel('Semua')],
            default => [Filter::select('saring', 'Alasan', self::FILTERS['dikecualikan'])->icon('fas fa-filter')->allLabel('Semua')],
        };
    }

    public function render(BezettingBackfillService $service): View
    {
        $this->authorizeImport();
        $run = BezettingBackfillRun::findOrFail($this->runId);
        $recap = $service->recap($run);

        $term = mb_strtolower($this->search);
        $matches = fn (array $item) => $term === '' || collect([$item['nama'], $item['nik'], $item['jabatan'] ?? '', $item['unit'] ?? ''])
            ->contains(fn ($text) => str_contains(mb_strtolower((string) $text), $term));

        $source = match ($this->view) {
            'karyawan' => $recap['rows'],
            'tindak_lanjut' => $recap['followups'],
            'dikecualikan' => $recap['excluded'],
        };
        $items = collect($source)
            ->filter($matches)
            ->filter(fn (array $item) => match (true) {
                $this->saring === '' => true,
                $this->view === 'dikecualikan' => $item['reason'] === $this->saring,
                default => ($item['jenis'] ?? null) === $this->saring,
            })
            ->when($this->view === 'karyawan' && $this->jenis !== '', fn ($rows) => $rows->filter(fn (array $item) => $item['perubahan'] === JobHistory::JENIS_PERUBAHAN_LABELS[$this->jenis]))
            ->when($this->view === 'karyawan' && $this->mulai !== '', fn ($rows) => $rows->filter(fn (array $item) => $item['perkiraan'] === ($this->mulai === 'perkiraan')))
            ->when($this->view === 'karyawan' && $this->penugasan !== '', fn ($rows) => $rows->filter(fn (array $item) => ($item['status'] === '' || $item['status'] === Employee::STATUS_DEFINITIF) === ($this->penugasan === 'definitif')))
            ->map(fn (array $item) => ['nik_tersamar' => NikMasker::mask($item['nik'])] + $item)
            ->values();

        return view('livewire.admin.employees.bezetting-run-recap', [
            'source' => $recap['source'],
            'rows' => $this->paginateCollection($items, self::PAGE),
            'guide' => match ($this->view) {
                'karyawan' => BezettingReviewGuide::history($recap['karyawan']),
                'tindak_lanjut' => BezettingReviewGuide::followUps($recap['source'] === 'saved', (int) config('hris.bezetting.followup_retention_days', 90)),
                'dikecualikan' => BezettingReviewGuide::exclusions(array_values(array_unique(array_column($recap['excluded'], 'reason')))),
            },
            'undecided' => $recap['belum_diputuskan'],
            'followupsAvailable' => $recap['followups_available'],
            'retentionDays' => (int) config('hris.bezetting.followup_retention_days', 90),
            'hasActiveFilters' => $this->search !== '' || $this->saring !== '' || $this->jenis !== '' || $this->mulai !== '' || $this->penugasan !== '',
        ]);
    }

    private function authorizeImport(): void
    {
        abort_unless(Gate::allows(HrisPermission::KARYAWAN_IMPORT), 403, 'Unauthorized.');
    }
}
