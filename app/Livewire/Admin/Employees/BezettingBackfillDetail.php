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
use App\Support\ReasonRule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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

    #[Locked]
    public BezettingBackfillRun $run;

    public int $perPage = 25;

    /** '' (semua), unit, jabatan, status */
    public string $kindFilter = '';

    /** 'undecided' (Belum diputuskan) | 'decided' (Sudah diputuskan pada berkas ini) */
    public string $decisionFilter = 'undecided';

    /** @var array<string, string> item key → nama tujuan terpilih */
    public array $targets = [];

    public string $reason = '';

    public string $confirmation = '';

    /** Review tab below the summary ('' = default by state). One flat tab bar, one section at a time, in work order. */
    public string $section = '';

    /** Filter a summary counter asked for (e.g. "perkiraan"); the recap tab opens with it. */
    public string $recapFilter = '';

    /** Incremented on every summary counter click so re-clicking the same counter after a recap reset remounts cleanly. */
    public int $recapNonce = 0;

    public const SECTIONS = [
        'nama' => 'Nama yang Perlu Diputuskan',
        'riwayat' => 'Riwayat yang Akan Ditulis',
        'tindak_lanjut' => 'Perlu Tindak Lanjut',
        'dikecualikan' => 'Dikecualikan',
    ];

    private ?array $plan = null;

    public function boot(): void
    {
        $this->authorizeImport();
    }

    public function mount(BezettingBackfillRun $run): void
    {
        $this->authorizeImport();
        $this->run = $run;
    }

    public function setSection(string $section): void
    {
        $this->section = array_key_exists($section, self::SECTIONS) ? $section : '';
        $this->recapFilter = '';
    }

    /** A summary counter opens the matching tab with its filter (the recap component validates the filter). */
    #[On('recap-filter')]
    public function openRecap(string $view = 'karyawan', string $saring = ''): void
    {
        $this->section = $view === 'tindak_lanjut' ? 'tindak_lanjut' : 'riwayat';
        $this->recapFilter = $saring;
        $this->recapNonce++;
    }

    public function updatedKindFilter(): void
    {
        $this->kindFilter = in_array($this->kindFilter, BezettingNameMapping::KINDS, true) ? $this->kindFilter : '';
        $this->resetPage();
    }

    public function updatedDecisionFilter(): void
    {
        $this->decisionFilter = in_array($this->decisionFilter, ['undecided', 'decided'], true) ? $this->decisionFilter : 'undecided';
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
        $this->authorizeImport();
        $this->section = 'nama'; // stay on the names tab while deciding, also after the last one
        $this->run->refresh();
        if (! in_array($this->run->status, [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED], true)) {
            session()->flash('error', 'Keputusan nama tidak dapat diubah setelah proses diajukan.');

            return;
        }
        $plan = $this->currentPlan();
        $item = collect([...($plan['blocking'] ?? []), ...($plan['decided'] ?? [])])
            ->first(fn (array $i) => self::key($i['kind'], $i['text']) === $key);
        if ($item === null) {
            session()->flash('error', 'Nama ini sudah diputuskan atau tidak ada pada proses ini.');

            return;
        }
        $target = $decision === BezettingNameMapping::DECISION_MAP ? ($this->targets[$key] ?? null) : null;
        if ($decision !== BezettingNameMapping::DECISION_MAP) {
            unset($this->targets[$key]);
        }
        $this->act(fn () => $service->decide($item['kind'], $item['text'], $decision, $target, auth()->user()), $success);
    }

    /**
     * Explicit bulk decision: every undecided unit or position name that has no look-alike in the Master Formasi is kept as
     * written (each one audited). Names with a suggestion are left for the mapping decision, so a spelling variant is never kept apart.
     */
    public function keepUnmatched(BezettingBackfillService $service): void
    {
        $this->authorizeImport();
        $this->section = 'nama';
        $this->run->refresh();
        if (! in_array($this->run->status, [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED], true)) {
            session()->flash('error', 'Keputusan nama tidak dapat diubah setelah proses diajukan.');

            return;
        }
        $this->act(fn () => DB::transaction(function () use ($service) {
            $names = $this->bulkCandidates(false);
            foreach ($names as $item) {
                $service->decide($item['kind'], $item['text'], BezettingNameMapping::DECISION_KEEP, null, auth()->user(), 'Keputusan massal: nama tanpa padanan pada Master Formasi disimpan apa adanya');
            }

            return count($names);
        }), 'Nama tanpa padanan pada Master Formasi disimpan apa adanya.');
    }

    /** Explicit bulk decision: every undecided unit or position name that has a suggestion is mapped to it (each one audited). */
    public function applySuggestions(BezettingBackfillService $service): void
    {
        $this->authorizeImport();
        $this->section = 'nama';
        $this->run->refresh();
        if (! in_array($this->run->status, [BezettingBackfillRun::STATUS_STAGED, BezettingBackfillRun::STATUS_REJECTED], true)) {
            session()->flash('error', 'Keputusan nama tidak dapat diubah setelah proses diajukan.');

            return;
        }
        $this->act(fn () => DB::transaction(function () use ($service) {
            $names = $this->bulkCandidates(true);
            foreach ($names as $item) {
                $key = self::key($item['kind'], $item['text']);
                $target = filled($this->targets[$key] ?? null) ? $this->targets[$key] : $item['suggestion'];
                $service->decide($item['kind'], $item['text'], BezettingNameMapping::DECISION_MAP, $target, auth()->user(), 'Keputusan massal: nama dipetakan sesuai saran Master Formasi');
            }

            return count($names);
        }), 'Nama dipetakan sesuai saran Master Formasi.');
    }

    /**
     * Undecided unit and position names with (or without) a look-alike in the Master Formasi, each with its suggestion.
     *
     * @param  list<string>|null  $units
     * @param  list<string>|null  $positions
     * @return list<array{kind: string, text: string, count: int, suggestion: ?string}>
     */
    private function bulkCandidates(bool $withSuggestion, ?array $units = null, ?array $positions = null): array
    {
        $units ??= Unit::query()->orderBy('nama')->pluck('nama')->all();
        $positions ??= Position::query()->orderBy('nama')->pluck('nama')->all();
        $items = [];
        foreach ($this->currentPlan()['blocking'] ?? [] as $item) {
            $suggestion = match ($item['kind']) {
                BezettingNameMapping::KIND_UNIT => self::suggestUnit($item['text'], $units),
                BezettingNameMapping::KIND_JABATAN => PositionNameMatcher::match($item['text'], $positions),
                default => null,
            };
            if ($item['kind'] === BezettingNameMapping::KIND_STATUS) {
                continue;
            }
            if (($suggestion !== null) === $withSuggestion) {
                $items[] = $item + ['suggestion' => $suggestion];
            }
        }

        return $items;
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

    public function withdraw(BezettingBackfillService $service): void
    {
        $this->validateReason();
        $this->act(fn () => $service->withdraw($this->run, auth()->user(), $this->reason), 'Proses ditutup; data berkasnya sudah dihapus.');
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

        $showingDecided = $this->decisionFilter === 'decided';
        $activeSource = $showingDecided ? ($plan['decided'] ?? []) : ($plan['blocking'] ?? []);
        $blocking = collect($activeSource)
            ->when($this->kindFilter !== '', fn ($items) => $items->where('kind', $this->kindFilter))
            ->values();
        $units = Unit::query()->orderBy('nama')->pluck('nama')->all();
        $positions = Position::query()->orderBy('nama')->pluck('nama')->all();
        $paged = $this->paginateCollection($blocking)->through(fn (array $item) => $item + [
            'key' => self::key($item['kind'], $item['text']),
            'suggestion' => $showingDecided ? ($item['target'] ?? null) : match ($item['kind']) {
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
        $canSubmit = $editable && $isMaker && $plan !== null && $plan['blocking'] === [] && $plan['rows'] !== [];

        // Work order: names to decide first while any are open, otherwise the recap. Saved runs only have the recap.
        $count = fn (int $n) => ' ('.number_format($n, 0, ',', '.').')';
        $followups = $plan !== null ? BezettingBackfillService::followupsFrom($plan) : ($run->followups ?? []);
        $followupCount = array_sum(array_map('count', $followups));
        $saved = in_array($run->status, [BezettingBackfillRun::STATUS_COMPLETED, BezettingBackfillRun::STATUS_ROLLED_BACK], true);
        $sections = $plan === null
            ? ($saved ? ['riwayat' => 'Riwayat yang Ditulis'.$count((int) $run->rows_written), 'tindak_lanjut' => self::SECTIONS['tindak_lanjut'].$count($followupCount)] : [])
            : [
                'nama' => self::SECTIONS['nama'].$count(count($plan['blocking'])),
                'riwayat' => self::SECTIONS['riwayat'].$count(count($plan['rows'])),
                'tindak_lanjut' => self::SECTIONS['tindak_lanjut'].$count($followupCount),
                'dikecualikan' => self::SECTIONS['dikecualikan'].$count(count($plan['excluded'])),
            ];
        $current = array_key_exists($this->section, $sections) ? $this->section : ($plan !== null && $plan['blocking'] !== [] ? 'nama' : 'riwayat');

        return view('livewire.admin.employees.bezetting-backfill-detail', [
            'plan' => $plan,
            'blocking' => $paged,
            'showingDecided' => $showingDecided,
            'undecidedTotal' => count($plan['blocking'] ?? []),
            'decidedTotal' => count($plan['decided'] ?? []),
            'blockingCounts' => collect($activeSource)->countBy('kind')->all(),
            'suggestedCount' => $editable && ! $showingDecided && $plan !== null ? count($this->bulkCandidates(true, $units, $positions)) : 0,
            'unmatchedCount' => $editable && ! $showingDecided && $plan !== null ? count($this->bulkCandidates(false, $units, $positions)) : 0,
            'autoCount' => count($plan['auto'] ?? []),
            'options' => [
                BezettingNameMapping::KIND_UNIT => $units,
                BezettingNameMapping::KIND_JABATAN => $positions,
                BezettingNameMapping::KIND_STATUS => BackfillPlanner::statuses(),
            ],
            'steps' => $this->timelineSteps($run),
            'sections' => $sections,
            'currentSection' => $current,
            'recapFilter' => $this->recapFilter,
            'filesSummary' => [
                'count' => count($run->files ?? []),
                'rows' => array_sum(array_column($run->files ?? [], 'rows')),
            ],
            'editable' => $editable && $plan !== null,
            'canSubmit' => $canSubmit,
            'submitHint' => $editable && ! $canSubmit ? $this->submitHint($run, $plan, $isMaker) : null,
            'canWithdraw' => $editable && $isMaker,
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

    /**
     * Why the run cannot be submitted yet, written for whoever is looking: the maker learns what to do next, another admin
     * learns that approval only opens after the maker submits.
     */
    private function submitHint(BezettingBackfillRun $run, ?array $plan, bool $isMaker): string
    {
        if ($plan === null) {
            return 'Data berkas proses ini sudah dihapus. Unggah ulang berkasnya untuk memulai proses baru.';
        }

        $undecided = count($plan['blocking']);
        $nothingToWrite = $plan['rows'] === [];
        $why = 'Riwayat hanya ditulis bila jabatan, unit, status penugasan, atau kelas golongan berubah dari satu bulan ke bulan berikutnya, dan hanya untuk masa sebelum riwayat pertama karyawan di aplikasi.';

        if (! $isMaker) {
            return match (true) {
                $undecided > 0 => "Menunggu keputusan nama dan pengajuan oleh {$run->maker_name}. Tombol persetujuan tampil setelah proses diajukan.",
                $nothingToWrite => "Proses ini tidak memuat riwayat yang perlu ditulis, sehingga tidak akan diajukan untuk persetujuan. {$run->maker_name} dapat menutupnya.",
                default => "Menunggu diajukan oleh {$run->maker_name}. Tombol persetujuan tampil setelah proses diajukan.",
            };
        }

        return match (true) {
            $undecided > 0 => "Putuskan seluruh nama lebih dulu ({$undecided} belum diputuskan), lalu ajukan untuk persetujuan admin lain.",
            $nothingToWrite && count($run->files ?? []) < 2 => "Tidak ada riwayat yang perlu ditulis, sehingga proses ini tidak dapat diajukan. Perubahan hanya terlihat dengan membandingkan beberapa bulan: unggah seluruh bulan dalam satu proses (misalnya Januari 2025 s.d. Oktober 2026), lalu tutup proses ini. {$why}",
            default => "Tidak ada riwayat yang perlu ditulis, sehingga proses ini tidak dapat diajukan. {$why} Tutup proses ini bila tidak diperlukan.",
        };
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
            BezettingBackfillRun::STATUS_WITHDRAWN => ['icon' => 'fa-xmark', 'tone' => 'closed', 'title' => 'Ditutup oleh pengaju', 'who' => $run->maker_name, 'at' => $run->decided_at, 'note' => $run->decision_note],
            default => ['icon' => 'fa-user-check', 'tone' => 'done', 'title' => 'Disetujui dan disimpan', 'who' => $run->checker_name, 'at' => $run->committed_at, 'note' => number_format($run->rows_written, 0, ',', '.').' baris riwayat'],
        };

        if ($run->rolled_back_at) {
            $steps[] = ['icon' => 'fa-rotate-left', 'tone' => 'closed', 'title' => 'Dibatalkan', 'who' => null, 'at' => $run->rolled_back_at, 'note' => $run->rollback_reason];
        }

        return $steps;
    }

    private function validateReason(): void
    {
        $this->validate(['reason' => ReasonRule::rules()], ReasonRule::messages('reason', 'keputusan'));
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
