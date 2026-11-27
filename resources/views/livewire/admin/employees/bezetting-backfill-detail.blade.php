{{-- Detail Rekonstruksi Riwayat dari Bezetting: pola layar Riwayat & Persetujuan Impor dan SK Struktur (DESIGN.md §8.5a). --}}
<div class="space-y-6" x-data="{ approveOpen: false, rejectOpen: false, rollbackOpen: false, bulkOpen: false, suggestOpen: false, submitOpen: false, withdrawOpen: false }"
    @bezetting-action-finished.window="approveOpen = false; rejectOpen = false; rollbackOpen = false; bulkOpen = false; suggestOpen = false; submitOpen = false; withdrawOpen = false">
    <x-admin.flash />

    @if ($run->payload_purged_at && $run->status !== 'completed' && $run->status !== 'rolled_back')
        <div role="status" class="flex items-start gap-3 rounded-xl border border-surface-200 bg-surface-50 px-4 py-3 text-sm text-surface-700">
            <i class="fas fa-circle-info mt-1 text-surface-500" aria-hidden="true"></i>
            <p>Data berkas proses ini sudah dihapus sesuai batas penyimpanan. Unggah ulang berkas Bezetting untuk memulai proses baru.</p>
        </div>
    @endif

    {{-- Top overview: compact Ringkasan + Berkas Terbaca on the left, Jejak Keputusan on the right (no artificial vertical void inside Ringkasan; DESIGN.md §8.6a). --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <div class="lg:col-span-8 flex flex-col gap-6">
            <section class="flex flex-col gap-4 bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs" aria-labelledby="summary-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="summary-title" class="text-base font-bold text-surface-900">Ringkasan</h2>
                    <x-admin.bezetting-status :status="$run->status" />
                </div>
                @php $summary = $plan['summary'] ?? $run->summary ?? []; @endphp
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <x-admin.kpi-card title="Riwayat" :value="number_format($run->rows_written ?: ($summary['baris_riwayat'] ?? 0), 0, ',', '.')" :subtitle="$run->rows_written ? 'Baris tersimpan' : 'Baris akan ditulis'" icon="fas fa-timeline" theme="primary" />
                    <x-admin.kpi-card title="Karyawan" :value="number_format($summary['karyawan'] ?? 0, 0, ',', '.')" subtitle="Memperoleh riwayat" icon="fas fa-users" theme="neutral" />
                    <x-admin.kpi-card title="Nama" :value="$summary['nama_belum_diputuskan'] ?? 0" subtitle="Belum diputuskan" icon="fas fa-list-check" :theme="($summary['nama_belum_diputuskan'] ?? 0) > 0 ? 'warning' : 'neutral'" />
                    <x-admin.kpi-card title="Dikecualikan" :value="$summary['karyawan_dikecualikan'] ?? 0" subtitle="Tidak ditulis" icon="fas fa-user-slash" theme="neutral" />
                </div>
                @if ($plan !== null && $plan['blocking'] !== [])
                    <p class="text-xs text-surface-600"><i class="fas fa-circle-info mr-1 text-surface-500" aria-hidden="true"></i>Angka riwayat, tanggal perkiraan, dan celah baru akurat setelah seluruh nama diputuskan, karena baris dengan nama yang belum diputuskan belum dihitung.</p>
                @endif
                @if ($summary !== [])
                    {{-- Each counter opens the matching list in the recap below (same numbers, per person). --}}
                    @php
                        $counters = [
                            ['label' => 'Tanggal mulai perkiraan', 'value' => $summary['diperkirakan'] ?? 0, 'view' => 'karyawan', 'saring' => 'perkiraan', 'warn' => false],
                            ['label' => 'Keluar dari daftar Bezetting', 'value' => $summary['keluar_daftar'] ?? 0, 'view' => 'tindak_lanjut', 'saring' => 'keluar', 'warn' => false],
                            ['label' => 'Celah bulan (tidak tercantum lalu tercantum lagi)', 'value' => $summary['celah'] ?? 0, 'view' => 'tindak_lanjut', 'saring' => 'celah', 'warn' => false],
                            ['label' => 'Perubahan perlu diperiksa', 'value' => $summary['perpindahan_belum_tercatat'] ?? 0, 'view' => 'tindak_lanjut', 'saring' => 'perpindahan', 'warn' => true],
                        ];
                    @endphp
                    <div class="pt-3 border-t border-surface-100 space-y-2.5">
                        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                            @foreach ($counters as $counter)
                                <li>
                                    <button type="button"
                                        wire:click="$dispatch('recap-filter', { view: '{{ $counter['view'] }}', saring: '{{ $counter['saring'] }}' })"
                                        @click="$nextTick(() => document.getElementById('rekap')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                                        @disabled($counter['value'] === 0)
                                        class="w-full flex items-baseline justify-between gap-3 rounded-lg px-2 py-1 -mx-2 text-left transition enabled:hover:bg-surface-50 enabled:cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-default">
                                        <span class="text-surface-600">{{ $counter['label'] }}</span>
                                        <span class="flex items-baseline gap-2">
                                            <span class="font-mono tabular-nums font-semibold {{ $counter['warn'] && $counter['value'] > 0 ? 'text-warning-800' : 'text-surface-900' }}">{{ $counter['value'] }}</span>
                                            @if ($counter['value'] > 0)<span class="text-[11px] font-semibold text-primary-700 whitespace-nowrap">Lihat daftar<span class="sr-only"> {{ $counter['label'] }}</span></span>@endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        @if (($summary['perpindahan_belum_tercatat'] ?? 0) > 0)
                            <p class="text-xs text-surface-600">Perpindahan sesudah riwayat pertama di aplikasi tidak ditulis oleh rekonstruksi. Proses melalui modul Mutasi beserta SK-nya.</p>
                        @endif
                    </div>
                @endif
            </section>
        </div>

        <aside class="lg:col-span-4 flex flex-col gap-6">
            <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs" aria-labelledby="timeline-title">
                <h2 id="timeline-title" class="text-base font-bold text-surface-900 mb-4">Jejak Keputusan</h2>
                <x-admin.decision-timeline :steps="$steps" />
            </section>
        </aside>
    </div>

    {{-- Full-width compact file provenance bar: keeps Ringkasan and Jejak Keputusan balanced with zero empty column gaps. --}}
    <section class="bg-white rounded-2xl border border-surface-200/80 p-4 sm:px-5 shadow-xs" aria-labelledby="files-title">
        @php $warnings = collect($run->files ?? [])->sum(fn ($file) => count($file['warnings'] ?? [])); @endphp
        <details class="group" @if ($warnings > 0) open @endif>
            <summary class="flex flex-wrap items-center justify-between gap-3 cursor-pointer list-none focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 rounded-lg">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 id="files-title" class="text-sm font-bold text-surface-900">Berkas Terbaca</h2>
                    <span class="text-sm text-surface-800">
                        <span class="font-semibold">{{ $filesSummary['count'] }} berkas</span> · {{ $run->period_from }} s.d. {{ $run->period_to }} · {{ number_format($filesSummary['rows'], 0, ',', '.') }} baris
                    </span>
                    @if ($warnings > 0)
                        <span class="text-xs font-semibold text-warning-800"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>{{ $warnings }} peringatan</span>
                    @endif
                    <span class="text-xs text-surface-500">Berkas sudah dihapus dari server; tersimpan hanya nama, periode, dan kode verifikasinya.</span>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-surface-200 bg-surface-50 px-3 py-1.5 text-xs font-semibold text-surface-700 group-hover:bg-surface-100 transition shrink-0">
                    <span>Daftar berkas</span>
                    <i class="fas fa-chevron-down text-[10px] text-surface-500 transition group-open:rotate-180" aria-hidden="true"></i>
                </span>
            </summary>
            <ul class="mt-3 pt-2 border-t border-surface-100 divide-y divide-surface-100 text-xs max-h-48 overflow-y-auto scrollbar-soft" tabindex="0" aria-label="Daftar berkas terbaca">
                @foreach ($run->files ?? [] as $file)
                    <li class="py-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5" title="Kode verifikasi berkas (SHA-256): {{ $file['sha256'] }}">
                        <div class="flex items-baseline gap-2 min-w-0">
                            <span class="font-semibold text-surface-900 truncate">{{ $file['name'] }}</span>
                            <span class="font-mono text-[11px] text-surface-500">{{ substr($file['sha256'], 0, 16) }}…</span>
                        </div>
                        <span class="font-mono tabular-nums text-surface-600 shrink-0">{{ $file['rows'] }} baris</span>
                        @foreach ($file['warnings'] ?? [] as $warning)
                            <span class="w-full mt-1 block text-[11px] text-warning-800"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>{{ $warning }}</span>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </details>
    </section>

    {{-- Review workspace below the summary: one section at a time, full page width, in work order (DESIGN.md §8.6a). --}}
    <section class="space-y-4" aria-labelledby="review-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="review-title" class="text-base font-bold text-surface-900">Tinjauan</h2>
            @if (count($sections) > 1)
                <x-admin.segmented label="Bagian tinjauan" :options="$sections" :current="$currentSection" action="setSection" />
            @endif
        </div>

        @if ($plan !== null && $currentSection === 'nama')
            <x-admin.table.card>
                <x-admin.table.header title="Nama yang Perlu Diputuskan" :paginator="$blocking" noun="Nama">
                    <x-slot:actions>
                        @if ($editable && $suggestedCount > 0)
                            <x-admin.button variant="secondary" size="sm" icon="fas fa-wand-magic-sparkles" @click="suggestOpen = true">Petakan Sesuai Saran ({{ $suggestedCount }})</x-admin.button>
                        @endif
                        @if ($editable && $unmatchedCount > 0)
                            <x-admin.button variant="secondary" size="sm" icon="fas fa-check-double" @click="bulkOpen = true">Simpan Tanpa Padanan Apa Adanya ({{ $unmatchedCount }})</x-admin.button>
                        @endif
                    </x-slot:actions>
                </x-admin.table.header>
                @if ($autoCount > 0)
                    <p class="px-5 py-3 border-b border-surface-200 text-xs text-surface-600"><i class="fas fa-circle-check text-primary-600 mr-1" aria-hidden="true"></i>{{ $autoCount }} nama sudah dicocokkan otomatis dengan Master Formasi karena hanya berbeda huruf besar atau kecil, tanda baca, atau "&" dan "dan". Nama itu tidak perlu diputuskan.</p>
                @endif
                <div class="px-5 py-3 border-b border-surface-200 flex flex-wrap items-center gap-4 text-xs">
                    <div class="flex items-center gap-2">
                        <label for="decision-filter" class="font-semibold text-surface-700">Status</label>
                        <select id="decision-filter" wire:model.live="decisionFilter" class="h-8 py-0 pl-2 pr-7 appearance-none bg-white select-chevron border border-surface-300 rounded-md text-xs text-surface-800 focus:outline-none focus:ring-2 focus:ring-primary-100 focus:border-primary-600">
                            <option value="undecided">Belum diputuskan ({{ $undecidedTotal }})</option>
                            <option value="decided">Sudah diputuskan ({{ $decidedTotal }})</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <label for="kind-filter" class="font-semibold text-surface-700">Jenis</label>
                        <select id="kind-filter" wire:model.live="kindFilter" class="h-8 py-0 pl-2 pr-7 appearance-none bg-white select-chevron border border-surface-300 rounded-md text-xs text-surface-800 focus:outline-none focus:ring-2 focus:ring-primary-100 focus:border-primary-600">
                            <option value="">Semua ({{ array_sum($blockingCounts) }})</option>
                            <option value="unit">Unit kerja ({{ $blockingCounts['unit'] ?? 0 }})</option>
                            <option value="jabatan">Jabatan ({{ $blockingCounts['jabatan'] ?? 0 }})</option>
                            <option value="status">Frasa status ({{ $blockingCounts['status'] ?? 0 }})</option>
                        </select>
                    </div>
                </div>
                <x-admin.table.guide :guide="\App\Support\BezettingReviewGuide::names()" />
                <div class="relative overflow-x-auto">
                    {{-- Fixed layout on desktop: the Muncul and Keputusan columns keep the same width and position whatever the names are, so the pickers line up on every page and filter. --}}
                    <table class="w-full text-left border-collapse text-xs lg:table-fixed">
                        <thead class="bg-surface-50">
                            <tr class="border-b border-surface-200">
                                <x-admin.table.th>Nama pada Berkas</x-admin.table.th>
                                <x-admin.table.th class="text-right whitespace-nowrap lg:w-28">Muncul</x-admin.table.th>
                                <x-admin.table.th class="min-w-[34rem] lg:w-[36rem]">Keputusan</x-admin.table.th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 text-surface-800">
                            @forelse ($blocking as $item)
                                <tr wire:key="name-{{ $item['key'] }}-{{ $item['decision'] ?? 'open' }}" class="hover:bg-surface-50/75 transition">
                                    <td class="px-4 py-2.5">
                                        {{-- Fixed-width kind badge beside a text block, aligned on the text baseline so the badge sits level with the
                                             first line of the name and a wrapped name stays aligned with its first line. --}}
                                        <div class="flex items-baseline gap-3">
                                            <span class="inline-flex w-[5.5rem] shrink-0 items-center justify-center px-2 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap bg-surface-100 text-surface-700 border border-surface-200">{{ ['unit' => 'Unit kerja', 'jabatan' => 'Jabatan', 'status' => 'Frasa status'][$item['kind']] ?? $item['kind'] }}</span>
                                            <div class="min-w-0">
                                                <span class="font-semibold text-surface-900 break-words">{{ $item['text'] }}</span>
                                                @if ($showingDecided)
                                                    @php
                                                        $decisionLabel = match ($item['decision'] ?? '') {
                                                            'map' => 'Dipetakan ke '.($item['target'] ?? '—'),
                                                            'keep' => 'Apa Adanya',
                                                            'skip' => 'Dilewati',
                                                            default => '—',
                                                        };
                                                    @endphp
                                                    <div class="mt-0.5 text-[11px] text-primary-700 font-medium">{{ $decisionLabel }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums whitespace-nowrap" title="Jumlah baris berkas yang memakai nama ini">{{ $item['count'] }} baris</td>
                                    <td class="px-4 py-2.5">
                                        @if ($editable)
                                            {{-- One line per name: the official-name picker (searchable, the Master Formasi has far more than 15
                                                 names; DESIGN.md §6), then the three decisions as one equal set. The choice is held in the
                                                 browser until Petakan is pressed (no request per pick). --}}
                                            <div class="flex flex-wrap lg:flex-nowrap items-center gap-1.5"
                                                @change="if (typeof $event.detail === 'string') $wire.set('targets.{{ $item['key'] }}', $event.detail, false)">
                                                <div class="w-full lg:w-64 shrink-0">
                                                    <span class="sr-only">Petakan {{ $item['text'] }} ke</span>
                                                    <x-admin.searchable-select :options="$options[$item['kind']]" :selected="$targets[$item['key']] ?? ''" floating
                                                        placeholder="Pilih nama resmi…" search-placeholder="Cari nama resmi..." empty-text="Nama resmi tidak ditemukan" />
                                                </div>
                                                <x-admin.button size="sm" icon="fas fa-link" class="whitespace-nowrap" wire:click="map('{{ $item['key'] }}')" target="map">Petakan</x-admin.button>
                                                @if ($item['kind'] !== 'status')
                                                    <x-admin.button size="sm" variant="secondary" class="whitespace-nowrap" wire:click="keep('{{ $item['key'] }}')" target="keep">Apa Adanya</x-admin.button>
                                                    <x-admin.button size="sm" variant="secondary" class="whitespace-nowrap" wire:click="skip('{{ $item['key'] }}')" target="skip">Lewati</x-admin.button>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-surface-600">{{ $showingDecided ? $decisionLabel : '—' }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-admin.table.empty-state :colspan="3" icon="fas fa-circle-check" :message="$showingDecided ? 'Belum ada keputusan nama tersimpan pada berkas ini.' : 'Semua nama sudah diputuskan.'" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-admin.table.pagination :paginator="$blocking" noun="nama" />
            </x-admin.table.card>
        @endif

        @if (in_array($currentSection, ['riwayat', 'tindak_lanjut', 'dikecualikan'], true))
            {{-- One recap component per tab (no nested tabs); a summary counter opens it with its filter. --}}
            <livewire:admin.employees.bezetting-run-recap :run-id="$run->id" :view="['riwayat' => 'karyawan', 'tindak_lanjut' => 'tindak_lanjut', 'dikecualikan' => 'dikecualikan'][$currentSection]" :saring="$recapFilter"
                :key="'recap-'.$run->id.'-'.$run->status.'-'.$currentSection.'-'.$recapFilter" />
        @endif
    </section>

    {{-- The decision comes after the review (DESIGN.md §8.6a): what the bar cannot say in a line is explained just above it. --}}
    @if ($submitHint || $blockedByFourEyes || $run->status === 'completed')
        <div role="status" class="flex items-start gap-3 rounded-xl border border-surface-200 bg-white px-4 py-3 text-sm text-surface-700 leading-relaxed" data-testid="bezetting-submit-hint">
            <i class="fas {{ $blockedByFourEyes ? 'fa-user-lock' : 'fa-circle-info' }} mt-1 text-surface-500" aria-hidden="true"></i>
            <p>
                @if ($blockedByFourEyes)
                    Anda adalah pengaju proses ini sehingga tidak dapat menyetujuinya sendiri (prinsip empat mata). Admin lain perlu meninjau dan menyetujui.
                @elseif ($run->status === 'completed')
                    Riwayat tersimpan sebagai catatan data kepegawaian (bukan SK mutasi) dan tampil pada Riwayat Karir dengan lencana Bezetting Historis.
                @else
                    {{ $submitHint }}
                @endif
            </p>
        </div>
    @endif

    @if ($canRollback)
        <section class="bg-white rounded-2xl border border-danger-200 shadow-xs overflow-hidden" aria-labelledby="risk-title">
            <div class="px-5 py-3.5 bg-danger-50 border-b border-danger-200">
                <h2 id="risk-title" class="text-base font-bold text-danger-800"><i class="fas fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>Tindakan Berisiko: Batalkan Rekonstruksi</h2>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-sm text-surface-700">Pembatalan mungkin dalam {{ $undoWindowDays }} hari sejak disimpan, selama riwayatnya belum berubah. Riwayat tidak dihapus permanen; alasan pembatalan dicatat.</p>
                <x-admin.button variant="danger" icon="fas fa-rotate-left" @click="rollbackOpen = true">Batalkan Rekonstruksi</x-admin.button>
            </div>
        </section>
    @endif

    {{-- Decision bar: shown only while a decision is still open (staged, rejected, pending) so completed/closed runs keep the review table unobstructed. --}}
    @if ($editable || $run->status === \App\Models\BezettingBackfillRun::STATUS_PENDING)
    @php
        $barRows = number_format($run->rows_written ?: ($plan['summary']['baris_riwayat'] ?? $run->summary['baris_riwayat'] ?? 0), 0, ',', '.');
        $barPeople = number_format($plan['summary']['karyawan'] ?? $run->summary['karyawan'] ?? 0, 0, ',', '.');
        $undecidedCount = $plan !== null ? count($plan['blocking']) : 0;
    @endphp

        <x-admin.form.footer :cancel-url="route('admin.employees.bezetting.index')" cancel-label="Kembali ke Daftar Proses">
            <x-slot:status>
                <i class="fas fa-gavel text-primary-600" aria-hidden="true"></i>
                <span>
                    <strong class="font-semibold text-surface-800">Keputusan:</strong>
                    @if ($canSubmit)
                        siap diajukan, {{ $barRows }} baris riwayat untuk {{ $barPeople }} karyawan.
                    @elseif ($canApprove)
                        menunggu persetujuan Anda, {{ $barRows }} baris riwayat untuk {{ $barPeople }} karyawan.
                    @elseif ($blockedByFourEyes)
                        menunggu persetujuan admin lain.
                    @elseif ($undecidedCount > 0)
                        {{ number_format($undecidedCount, 0, ',', '.') }} nama belum diputuskan.
                    @else
                        belum ada riwayat yang dapat diajukan.
                    @endif
                </span>
            </x-slot:status>
            <x-slot:actions>
                @if ($plan !== null && $undecidedCount > 0 && $currentSection !== 'nama')
                    <x-admin.button variant="secondary" icon="fas fa-list-check" wire:click="setSection('nama')" target="setSection">Buka Daftar Nama</x-admin.button>
                @endif
                @if ($canWithdraw)
                    <x-admin.button variant="secondary" icon="fas fa-xmark" @click="withdrawOpen = true">Tutup Proses</x-admin.button>
                @endif
                @if ($canApprove)
                    <x-admin.button variant="secondary" icon="fas fa-ban" @click="rejectOpen = true">Kembalikan</x-admin.button>
                @endif
            </x-slot:actions>
            <x-slot:primary>
                @if ($canSubmit)
                    <x-admin.button icon="fas fa-paper-plane" @click="submitOpen = true">Ajukan untuk Persetujuan</x-admin.button>
                @elseif ($canApprove)
                    <x-admin.button icon="fas fa-check" @click="approveOpen = true">Setujui dan Simpan Riwayat</x-admin.button>
                @endif
            </x-slot:primary>
        </x-admin.form.footer>
    @endif


    {{-- Dialog konfirmasi (DESIGN.md §7.6) --}}
    @if ($canSubmit)
        <x-admin.modal open="submitOpen" title="Ajukan rekonstruksi ini?" description="Setelah diajukan, keputusan nama dikunci dan admin lain perlu menyetujui sebelum riwayat tersimpan.">
            <x-slot:footer>
                <x-admin.button icon="fas fa-paper-plane" wire:click="submit" target="submit">Ajukan</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($editable)
        <x-admin.modal open="bulkOpen" tone="warning" title="Simpan nama tanpa padanan apa adanya?" description="{{ $unmatchedCount }} nama unit atau jabatan yang tidak mirip nama mana pun pada Master Formasi akan disimpan sesuai teks berkas dan ditandai di luar struktur saat ini. Setiap keputusan tercatat.">
            <x-slot:footer>
                <x-admin.button icon="fas fa-check-double" wire:click="keepUnmatched" target="keepUnmatched">Simpan Apa Adanya</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>

        <x-admin.modal open="suggestOpen" title="Petakan seluruh nama sesuai saran?" description="{{ $suggestedCount }} nama unit atau jabatan dipetakan ke nama Master Formasi yang disarankan pada daftar. Periksa saran pada daftar lebih dulu; setiap keputusan tercatat.">
            <x-slot:footer>
                <x-admin.button icon="fas fa-wand-magic-sparkles" wire:click="applySuggestions" target="applySuggestions">Petakan Sesuai Saran</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($canWithdraw)
        <x-admin.modal open="withdrawOpen" tone="warning" title="Tutup proses ini?" description="Tidak ada riwayat yang ditulis. Data berkas proses ini dihapus dan proses tetap tercatat pada daftar. Keputusan nama yang sudah disimpan tetap berlaku untuk proses berikutnya.">
            <x-admin.form.reason-field id="withdraw-reason" name="reason" label="Alasan" wire:model="reason" :native-validation="false" hint="Alasan dicatat pada jejak keputusan (minimal 10 karakter)." />
            <x-slot:footer>
                <x-admin.button icon="fas fa-xmark" wire:click="withdraw" target="withdraw">Tutup Proses</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($canApprove)
        <x-admin.modal open="approveOpen" title="Setujui dan simpan riwayat?" description="Seluruh baris riwayat ditulis dalam satu proses. Data karyawan, SK, dan formasi tidak berubah.">
            <x-slot:footer>
                <x-admin.button icon="fas fa-check" wire:click="approve" target="approve">Setujui</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>

        <x-admin.modal open="rejectOpen" tone="danger" title="Kembalikan kepada pengaju?" description="Riwayat tidak ditulis. Alasan dicatat pada jejak keputusan.">
            <x-admin.form.reason-field id="reject-reason" name="reason" label="Alasan" wire:model="reason" :native-validation="false" hint="Alasan dicatat pada jejak keputusan (minimal 10 karakter)." />
            <x-slot:footer>
                <x-admin.button variant="danger" icon="fas fa-ban" wire:click="reject" target="reject">Kembalikan</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($canRollback)
        <x-admin.modal open="rollbackOpen" tone="danger" title="Batalkan rekonstruksi ini?" description="Riwayat dari proses ini tidak lagi ditampilkan, tetapi tidak dihapus permanen.">
            <x-admin.form.reason-field id="rollback-reason" name="reason" label="Alasan" wire:model="reason" :native-validation="false" hint="Alasan dicatat pada jejak keputusan (minimal 10 karakter)." />
            @if ($needsTypedWord)
                <div class="mt-3">
                    <x-admin.form.input name="confirmation" label="Ketik BATALKAN untuk mengonfirmasi" wire:model="confirmation" autocomplete="off" />
                </div>
            @endif
            <x-slot:footer>
                <x-admin.button variant="danger" icon="fas fa-rotate-left" wire:click="rollback" target="rollback">Batalkan Rekonstruksi</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
