<?php

namespace App\Livewire\Admin\Employees;

use App\Exceptions\ImportAbortedException;
use App\Livewire\Concerns\WithCollectionTable;
use App\Models\BezettingBackfillRun;
use App\Models\BezettingNameMapping;
use App\Models\Position;
use App\Models\Unit;
use App\Services\Bezetting\BackfillPlanner;
use App\Services\Bezetting\BezettingBackfillService;
use App\Services\Import\PositionNameMatcher;
use App\Support\HrisPermission;
use App\Support\NikMasker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Detail proses Rekonstruksi Riwayat dari Bezetting (DOMAIN.md §5.5): keputusan nama, karyawan yang dikecualikan, jejak
 * keputusan, dan aksi empat mata. Aturan ada pada BezettingBackfillService; layar hanya mencerminkannya (pola layar
 * Riwayat & Persetujuan Impor dan SK Struktur, DESIGN.md §8.5a). NIK hanya tampil tersamar (UU PDP).
 */
class BezettingBackfillDetail extends Component
{
    use WithCollectionTable;
    use WithPagination;

    public BezettingBackfillRun $run;

    public int $perPage = 25;

    /** '' (semua), unit, jabatan, status */
    public string $kindFilter = '';

    /** @var array<string, string> item key → nama tujuan terpilih */
    public array $targets = [];

    public string $reason = '';

    public string $confirmation = '';

    private ?array $plan = null;

    public function mount(BezettingBackfillRun $run): void
    {
        $this->authorizeImport();
        $this->run = $run;
    }

    public function updatedKindFilter(): void
    {
        $this->kindFilter = in_array($this->kindFilter, BezettingNameMapping::KINDS, true) ? $this->kindFilter : '';
        $this->resetPage();
    }

    public function map(string $key, BezettingBackfillService $service): void
    {
        $this->decideByKey($key, BezettingNameMapping::DECISION_MAP, 'Nama dipetakan.', $service);
    }

    public function keep(string $key, BezettingBackfillService $service): void
    {
        $this->decideByKey($key, BezettingNameMapping::DECISION_KEEP, 'Nama disimpan apa adanya.', $service);
    }

    public function skip(string $key, BezettingBackfillService $service): void
    {
        $this->decideByKey($key, BezettingNameMapping::DECISION_SKIP, 'Baris dengan nama ini tidak akan ditulis.', $service);
    }

    /** The name is looked up from the server-side plan by its key; the browser never sends the name itself. */
    private function decideByKey(string $key, string $decision, string $success, BezettingBackfillService $service): void
    {
        $item = collect($this->currentPlan()['blocking'] ?? [])->first(fn (array $i) => self::key($i['kind'], $i['text']) === $key);
        if ($item === null) {
            session()->flash('error', 'Nama ini sudah diputuskan atau tidak ada pada proses ini.');

            return;
        }
        $target = $decision === BezettingNameMapping::DECISION_MAP ? ($this->targets[$key] ?? null) : null;
        $this->act(fn () => $service->decide($item['kind'], $item['text'], $decision, $target, auth()->user()), $success);
    }

    /** Explicit bulk decision: every remaining undecided position name is kept as written (each one audited). */
    public function keepRemainingPositions(BezettingBackfillService $service): void
    {
        $this->act(function () use ($service) {
            $names = array_filter($this->currentPlan()['blocking'], fn (array $item) => $item['kind'] === BezettingNameMapping::KIND_JABATAN);
            foreach ($names as $item) {
                $service->decide($item['kind'], $item['text'], BezettingNameMapping::DECISION_KEEP, null, auth()->user(), 'Keputusan massal: nama jabatan tersisa disimpan apa adanya');
            }

            return count($names);
        }, 'Seluruh nama jabatan tersisa disimpan apa adanya.');
    }

    public function submit(BezettingBackfillService $service): void
    {
        $this->act(fn () => $service->submit($this->run, auth()->user()), 'Rekonstruksi diajukan untuk persetujuan admin lain.');
    }

    public function approve(BezettingBackfillService $service): void
    {
        $this->act(fn () => $service->approve($this->run, auth()->user()), 'Rekonstruksi disetujui dan riwayat tersimpan.');
    }

    public function reject(BezettingBackfillService $service): void
    {
        $this->validateReason();
        $this->act(fn () => $service->reject($this->run, auth()->user(), $this->reason), 'Rekonstruksi dikembalikan kepada pengaju.');
    }

    public function rollback(BezettingBackfillService $service): void
    {
        $this->validateReason();
        $this->act(fn () => $service->rollback($this->run, auth()->user(), $this->reason, $this->confirmation), 'Rekonstruksi dibatalkan; riwayatnya tidak lagi ditampilkan.');
    }

    public function render(): View
    {
        $this->authorizeImport();
        $this->run->refresh();
        $run = $this->run;
        $user = auth()->user();
        $plan = $this->currentPlan();

        $blocking = collect($plan['blocking'] ?? [])
            ->when($this->kindFilter !== '', fn ($items) => $items->where('kind', $this->kindFilter))
            ->values();
        $units = Unit::query()->orderBy('nama')->pluck('nama')->all();
        $positions = Position::query()->orderBy('nama')->pluck('nama')->all();
        $paged = $this->paginateCollection($blocking)->through(fn (array $item) => $item + [
            'key' => self::key($item['kind'], $item['text']),
            'suggestion' => match ($item['kind']) {
                BezettingNameMapping::KIND_UNIT => self::suggestUnit($item['text'], $units),
                BezettingNameMapping::KIND_JABATAN => PositionNameMatcher::match($item['text'], $positions),
                default => null,
            },
        ]);
        foreach ($paged as $item) {
            if ($item['suggestion'] !== null && ! isset($this->targets[$item['key']])) {
                $this->targets[$item['key']] = $item['suggestion'];
            }
        }

        $editable = in_array($run->status, [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED], true);
        $isMaker = $run->maker_id === $user->id;
        $undoOpen = $run->status === BezettingBackfillRun::STATUS_COMPLETED
            && $run->committed_at?->gte(now()->subDays((int) config('hris.import.undo_window_days', 7)));

        return view('livewire.admin.employees.bezetting-backfill-detail', [
            'plan' => $plan,
            'blocking' => $paged,
            'blockingCounts' => collect($plan['blocking'] ?? [])->countBy('kind')->all(),
            'excludedGroups' => $this->excludedGroups($plan),
            'options' => [
                BezettingNameMapping::KIND_UNIT => $units,
                BezettingNameMapping::KIND_JABATAN => $positions,
                BezettingNameMapping::KIND_STATUS => BackfillPlanner::statuses(),
            ],
            'steps' => $this->timelineSteps($run),
            'editable' => $editable && $plan !== null,
            'canSubmit' => $editable && $isMaker && $plan !== null && $plan['blocking'] === [] && $plan['rows'] !== [],
            'canApprove' => $run->status === BezettingBackfillRun::STATUS_PENDING && ! $isMaker,
            'blockedByFourEyes' => $run->status === BezettingBackfillRun::STATUS_PENDING && $isMaker,
            'canRollback' => (bool) $undoOpen,
            'needsTypedWord' => $run->rows_written >= (int) config('hris.import.typed_confirmation_threshold', 50),
            'undoWindowDays' => (int) config('hris.import.undo_window_days', 7),
        ]);
    }

    /**
     * Suggestion only (HR still decides): the shared position matcher, then again without the parts old Bezetting files
     * add to unit names, such as "(K.SAWIT)", "(60 TON)" or "INTI".
     *
     * @param  list<string>  $units
     */
    public static function suggestUnit(string $text, array $units): ?string
    {
        $stripped = trim((string) preg_replace(['/\([^)]*\)/u', '/\bINTI\b/iu', '/\s+/u'], ['', '', ' '], $text));

        return PositionNameMatcher::match($text, $units) ?? ($stripped !== '' ? PositionNameMatcher::match($stripped, $units) : null);
    }

    public static function key(string $kind, string $text): string
    {
        return substr(hash('sha256', $kind.'|'.$text), 0, 16);
    }

    /** The plan of an open run (rebuilt each request; never cached because it holds NIKs). */
    private function currentPlan(): ?array
    {
        $open = in_array($this->run->status, [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED, BezettingBackfillRun::STATUS_PENDING], true);
        if (! $open || $this->run->payload === null) {
            return null;
        }

        return $this->plan ??= app(BezettingBackfillService::class)->plan($this->run);
    }

    /** @return list<array{label: string, count: int, people: list<array{nik: string, periode: ?string}>}> masked NIKs only */
    private function excludedGroups(?array $plan): array
    {
        if ($plan === null) {
            return [];
        }

        return collect($plan['excluded'])
            ->map(fn (array $item, $nik) => ['nik' => NikMasker::mask((string) $nik), 'periode' => $item['periode'], 'reason' => $item['reason']])
            ->groupBy('reason')
            ->map(fn ($people, $reason) => [
                'label' => BackfillPlanner::EXCLUSION_LABELS[$reason] ?? $reason,
                'count' => $people->count(),
                'people' => $people->take(50)->map(fn ($p) => ['nik' => $p['nik'], 'periode' => $p['periode']])->values()->all(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @return list<array{icon: string, tone: string, title: string, who: ?string, at: mixed, note: ?string}> */
    private function timelineSteps(BezettingBackfillRun $run): array
    {
        $steps = [['icon' => 'fa-file-arrow-up', 'tone' => 'done', 'title' => 'Berkas dibaca', 'who' => $run->maker_name, 'at' => $run->created_at, 'note' => count($run->files ?? []).' berkas, '.$run->period_from.' s.d. '.$run->period_to]];

        if ($run->submitted_at) {
            $steps[] = ['icon' => 'fa-paper-plane', 'tone' => 'done', 'title' => 'Diajukan', 'who' => $run->maker_name, 'at' => $run->submitted_at, 'note' => null];
        }

        $steps[] = match ($run->status) {
            BezettingBackfillRun::STATUS_STAGED => ['icon' => 'fa-pen', 'tone' => 'current', 'title' => 'Menunggu keputusan nama dan pengajuan', 'who' => null, 'at' => null, 'note' => null],
            BezettingBackfillRun::STATUS_PENDING => ['icon' => 'fa-clock', 'tone' => 'current', 'title' => 'Menunggu persetujuan admin lain', 'who' => null, 'at' => null, 'note' => null],
            BezettingBackfillRun::STATUS_REJECTED => ['icon' => 'fa-ban', 'tone' => 'closed', 'title' => 'Dikembalikan', 'who' => $run->checker_name, 'at' => $run->decided_at, 'note' => $run->decision_note],
            BezettingBackfillRun::STATUS_EXPIRED => ['icon' => 'fa-hourglass-end', 'tone' => 'closed', 'title' => 'Kedaluwarsa', 'who' => null, 'at' => $run->updated_at, 'note' => null],
            default => ['icon' => 'fa-user-check', 'tone' => 'done', 'title' => 'Disetujui dan disimpan', 'who' => $run->checker_name, 'at' => $run->committed_at, 'note' => number_format($run->rows_written, 0, ',', '.').' baris riwayat'],
        };

        if ($run->rolled_back_at) {
            $steps[] = ['icon' => 'fa-rotate-left', 'tone' => 'closed', 'title' => 'Dibatalkan', 'who' => null, 'at' => $run->rolled_back_at, 'note' => $run->rollback_reason];
        }

        return $steps;
    }

    private function validateReason(): void
    {
        $this->validate(
            ['reason' => 'required|string|min:10|max:500'],
            ['reason.required' => 'Alasan wajib diisi minimal 10 karakter.', 'reason.min' => 'Alasan wajib diisi minimal 10 karakter.', 'reason.max' => 'Alasan maksimal 500 karakter.'],
        );
    }

    private function act(callable $action, string $success): void
    {
        $this->authorizeImport();

        try {
            $action();
            session()->flash('success', $success);
        } catch (ImportAbortedException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->plan = null;
        $this->reason = '';
        $this->confirmation = '';
        $this->resetErrorBag();
        $this->run->refresh();
        $this->dispatch('bezetting-action-finished');
    }

    private function authorizeImport(): void
    {
        abort_unless(Gate::allows(HrisPermission::KARYAWAN_IMPORT), 403, 'Unauthorized.');
    }
}
