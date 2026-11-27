{{-- Employee profile: job history on the shared data table layer (reference: tests/Fixtures/DataTable/FixtureTable.php). --}}
@php
    $rows = $this->rows;
    $columns = $this->columns();
    $bulkActions = $this->bulkActions();
    $selectable = $bulkActions !== [];
    $rowKey = $this->rowKey();
    $pageKeys = $this->tablePageKeys();
    $loadingTargets = $this->tableLoadingTargets();
    $columnCount = $this->tableColumnCount();
@endphp
<div>
    <x-admin.table.card :notice="$tableNotice">
        <x-admin.table.toolbar
            :table-id="$this->getId()"
            :filters="$this->filters()"
            :filter-values="$this->activeFilterValues()"
            :has-active-filters="$this->hasActiveFilters()"
            :search="$search"
            :search-shortcut="null"
            search-placeholder="Cari jabatan, unit, SK..." />
        <x-admin.table.header :paginator="$rows" noun="Riwayat Jabatan" />

        @if ($selectable)
            <x-admin.table.bulk-bar
                :actions="$bulkActions"
                :selected="$selected"
                :select-all-matching="$selectAllMatching"
                :selected-count="$this->selectedCount()"
                :matching-count="$rows->total()"
                :page-keys="$pageKeys" />
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs" wire:loading.attr="aria-busy" wire:target="{{ $loadingTargets }}">
                <x-admin.table.head
                    :columns="$columns"
                    :sort-field="$sortField"
                    :sort-direction="$sortDirection"
                    :selectable="$selectable"
                    :page-keys="$pageKeys"
                    :selected="$selected"
                    :select-all-matching="$selectAllMatching" />

                <tbody class="divide-y divide-surface-100 text-surface-800" wire:loading.delay.remove wire:target="{{ $loadingTargets }}">
                    @forelse ($rows as $history)
                        @php
                            $key = (string) $history->getAttribute($rowKey);
                            $isSelected = in_array($key, $selected, true);
                        @endphp
                        <tr wire:key="row-{{ $key }}" @class(['transition', 'bg-surface-50 border-l-4 border-l-primary-600' => $isSelected, 'hover:bg-surface-50/75' => ! $isSelected])>
                            @if ($selectable)
                                <x-admin.table.select-cell :key="$key" :label="$history->jabatan" />
                            @endif
                            {{-- Five data columns (DESIGN.md §7.5): unit under the position, SK under the mutation, so the table fits 1366px. --}}
                            <td class="px-3 py-3 text-surface-900 font-medium">
                                <span class="whitespace-nowrap">{{ $history->tmt_awal?->format('d M Y') ?? '-' }}</span>@if ($history->tmt_awal_diperkirakan) <span class="text-[11px] font-normal text-surface-600 whitespace-nowrap">(perkiraan)</span>@endif
                                @php($endsBeforeOwn = $history->sumber === \App\Models\JobHistory::SUMBER_BEZETTING && $history->tmt_akhir && $firstOwnStart && $history->tmt_akhir->copy()->addDay()->toDateString() === substr((string) $firstOwnStart, 0, 10))
                                <span class="block whitespace-nowrap {{ is_null($history->tmt_akhir) ? 'text-primary-600 font-semibold' : 'text-surface-600' }}"
                                    @if ($endsBeforeOwn) title="Berakhir sebelum riwayat aplikasi dimulai ({{ \Illuminate\Support\Carbon::parse($firstOwnStart)->format('d M Y') }})" @endif>
                                    s.d. {{ $history->tmt_akhir?->format('d M Y') ?? 'Sekarang' }}
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                <span class="block text-surface-900 font-semibold">{{ $history->jabatan }}</span>
                                <span class="block text-surface-600">{{ $history->unit_kerja }}</span>
                                @if ($history->sumber === \App\Models\JobHistory::SUMBER_BEZETTING && $history->teks_sumber)
                                    <span class="mt-0.5 block max-w-[22rem] truncate text-[11px] text-surface-500" title="Berkas {{ $history->berkas_bezetting_label }}: {{ $history->teks_sumber }}">{{ $history->berkas_bezetting_label ?? 'Bezetting' }}: {{ $history->teks_sumber_baris }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-surface-600">
                                @php($rm = $rmBands[$history->jabatan] ?? null)
                                @if ($rm)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold border whitespace-nowrap {{ \App\Models\Position::rmTheme($rm) }}"
                                        title="{{ \App\Models\Position::RM_LEVEL_LIST[$rm] ?? $rm }} (menurut Master Formasi saat ini)">{{ $rm }}</span>
                                @else
                                    <span class="block text-surface-600" title="RM Band tidak tersedia: jabatan ini tidak tercantum pada Master Formasi saat ini">{{ $history->level ?? '-' }}</span>
                                @endif
                                <span class="mt-0.5 block whitespace-nowrap text-surface-900">{{ $history->golongan ?? '-' }}</span>
                            </td>
                            <td class="px-3 py-3">
                                @if ($history->jenis_mutasi)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap bg-info-50 text-info-700 border border-info-200">
                                        {{ $history->jenis_mutasi_label }}
                                    </span>
                                @elseif ($history->isRecordOnly())
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap bg-info-50 text-info-800 border border-info-200"
                                        title="Catatan data kepegawaian, bukan SK mutasi resmi">
                                        <i class="fas fa-database text-[10px]" aria-hidden="true"></i> {{ $history->sumber_label }}
                                    </span>
                                    @if ($history->jenis_perubahan_terdeteksi_label)
                                        <span class="mt-1 block text-[11px] text-surface-600">{{ $history->jenis_perubahan_terdeteksi_label }}</span>
                                    @endif
                                @endif
                                @if ($history->nomor_sk || $history->tanggal_sk)
                                    <div class="mt-1 flex items-center gap-1.5 text-surface-700">
                                        <span class="break-all">{{ $history->nomor_sk ?? '—' }}</span>
                                        @if ($history->file_sk)
                                            <a href="{{ route('admin.mutasi.downloadSk', $history->id) }}" target="_blank" class="text-danger hover:text-danger-700 transition" title="Unduh Berkas SK PDF (Aman)">
                                                <i class="fas fa-file-pdf text-xs" aria-hidden="true"></i><span class="sr-only">Unduh berkas SK</span>
                                            </a>
                                        @endif
                                    </div>
                                    @if ($history->tanggal_sk)
                                        <div class="text-[11px] text-surface-500">{{ $history->tanggal_sk->format('d M Y') }}</div>
                                    @endif
                                @elseif (! $history->jenis_mutasi && ! $history->isRecordOnly())
                                    <span class="text-surface-500">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-surface-700">
                                @php($placement = $structure[$history->id] ?? ['structure_sk' => null, 'in_structure' => null])
                                @if ($placement['structure_sk'] === null)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap bg-surface-100 text-surface-700">Sebelum SK struktur pertama</span>
                                @else
                                    <span class="block text-surface-900">{{ $placement['structure_sk'] }}</span>
                                    @if ($placement['in_structure'] === false)
                                        <span class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap bg-warning-100 text-warning-900"><i class="fas fa-triangle-exclamation text-[10px]" aria-hidden="true"></i> Tidak tercantum pada struktur SK</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center whitespace-nowrap">
                                @include('admin.employees.partials.job-history-actions', ['row' => $history])
                            </td>
                        </tr>
                    @empty
                        @if ($this->hasActiveFilters())
                            <x-admin.table.empty-state :colspan="$columnCount" icon="fas fa-filter" message="Tidak ada data yang sesuai dengan pencarian atau filter.">
                                <x-admin.button variant="secondary" size="sm" icon="fas fa-rotate-left" wire:click="resetFilters" target="resetFilters">Reset filter</x-admin.button>
                            </x-admin.table.empty-state>
                        @else
                            <x-admin.table.empty-state :colspan="$columnCount" message="Belum ada data riwayat jabatan." icon="fas fa-briefcase" />
                        @endif
                    @endforelse
                </tbody>

                <x-admin.table.skeleton :columns="$columnCount" :target="$loadingTargets" />
            </table>
        </div>

        <x-admin.table.pagination :paginator="$rows" noun="riwayat jabatan" :per-page-options="$this->perPageOptions()" />
    </x-admin.table.card>

    {{-- One shared, reasoned delete dialog for every row (DESIGN.md §7.6; SECURITY.md item 6a). --}}
    <x-admin.confirm-delete-modal title="Hapus Riwayat Jabatan" noun="riwayat jabatan" reason
        consequence="Riwayat tidak lagi ditampilkan, tetapi tetap tersimpan bersama alasan dan nama penghapusnya sebagai jejak audit." />

</div>
