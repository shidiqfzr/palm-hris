{{--
    Tab tinjauan Rekonstruksi (DOMAIN.md §5.5 butir 9; DESIGN.md §7.5 dan §8.6a). Satu komponen, tiga tampilan dengan susunan
    yang sama: judul dan hitungan (x-admin.table.header) → catatan bila ada → toolbar → panduan (x-admin.table.guide) → tabel →
    paginasi. Riwayat yang (Akan) Ditulis, Perlu Tindak Lanjut, dan Dikecualikan; NIK selalu tersamar.
--}}
@php
    $fmt = fn (?string $date) => $date ? \Illuminate\Support\Carbon::parse($date)->format('d M Y') : '—';
    $planned = $source === 'plan';
    $title = match ($view) {
        'karyawan' => $planned ? 'Riwayat yang Akan Ditulis' : 'Riwayat yang Ditulis',
        default => \App\Livewire\Admin\Employees\BezettingRunRecap::VIEWS[$view],
    };
    $noun = match ($view) { 'karyawan' => 'Baris Riwayat', 'tindak_lanjut' => 'Tindak Lanjut', default => 'Karyawan' };
    $emptyMessage = match ($view) {
        'karyawan' => 'Belum ada riwayat yang akan ditulis.',
        'tindak_lanjut' => 'Tidak ada yang perlu ditindaklanjuti.',
        default => 'Tidak ada karyawan yang dikecualikan.',
    };
@endphp
<div id="rekap" class="scroll-mt-24">
    @if ($source === null || ($view === 'dikecualikan' && ! $planned))
        <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs" aria-labelledby="recap-title">
            <h3 id="recap-title" class="text-base font-bold text-surface-900">{{ $title }}</h3>
            <p class="mt-2 text-sm text-surface-600">
                {{ $source === null ? 'Rekap tidak tersedia karena data berkas proses ini sudah dihapus sesuai batas penyimpanan.' : 'Karyawan yang dikecualikan hanya diketahui selama proses belum disimpan; sesudah disimpan, yang tercatat adalah riwayat yang ditulis dan daftar tindak lanjut.' }}
            </p>
        </section>
    @else
        <x-admin.table.card>
            <x-admin.table.header :title="$title" :paginator="$rows" :noun="$noun">
                <x-slot:actions>
                    <x-admin.button href="{{ route('admin.employees.bezetting.verification', $runId) }}" variant="secondary" size="sm" icon="fas fa-file-excel"
                        title="NIK utuh, hanya untuk pemeriksaan SDM terhadap dokumen asli; setiap unduhan tercatat">Unduh Daftar Verifikasi SDM</x-admin.button>
                </x-slot:actions>
            </x-admin.table.header>

            @if ($planned && $undecided > 0 && $view === 'karyawan')
                <div class="mx-5 my-3 flex items-start gap-2 rounded-xl border border-warning-200 bg-warning-50 px-3.5 py-2.5 text-xs text-warning-900" role="status">
                    <i class="fas fa-triangle-exclamation mt-0.5" aria-hidden="true"></i>
                    <p><strong>Pratinjau belum lengkap.</strong> {{ number_format($undecided, 0, ',', '.') }} nama unit, jabatan, atau status masih perlu diputuskan pada "Nama yang Perlu Diputuskan". Karyawan dengan nama itu belum tampil di sini dan akan muncul setelah namanya diputuskan.</p>
                </div>
            @endif
            @if ($view === 'tindak_lanjut' && ! $planned && ! $followupsAvailable)
                <div class="mx-5 my-3 flex items-start gap-2 rounded-xl border border-surface-200 bg-surface-50 px-3.5 py-2.5 text-xs text-surface-700" role="status">
                    <i class="fas fa-circle-info mt-0.5" aria-hidden="true"></i>
                    <p>Daftar tindak lanjut proses ini sudah dihapus sesuai batas penyimpanan ({{ $retentionDays }} hari sesudah disimpan).</p>
                </div>
            @endif

            <x-admin.table.toolbar
                :table-id="'rekap-'.$this->getId()"
                :filters="$this->filters()"
                :filter-values="['saring' => $saring, 'jenis' => $jenis, 'mulai' => $mulai, 'penugasan' => $penugasan]"
                :has-active-filters="$hasActiveFilters"
                :search="$search"
                :search-shortcut="null"
                :search-placeholder="$view === 'dikecualikan' ? 'Cari nama atau NIK...' : 'Cari nama, NIK, jabatan, atau unit...'" />

            <x-admin.table.guide :guide="$guide" wire:key="guide-{{ $view }}" />

            {{-- The history tab has a fixed layout so it never scrolls sideways on a desktop screen; the other tabs size to content. --}}
            <div class="overflow-x-auto {{ $view === 'karyawan' ? 'lg:overflow-visible' : '' }}">
                <table class="w-full text-left border-collapse text-xs {{ $view === 'karyawan' ? 'lg:table-fixed' : '' }}">
                    @if ($view === 'karyawan')
                        <colgroup>
                            <col class="lg:w-[21%]">
                            <col class="lg:w-[45%]">
                            <col class="lg:w-[18%]">
                            <col class="lg:w-[16%]">
                        </colgroup>
                    @endif
                    <thead class="bg-surface-50">
                        <tr class="border-b border-surface-200">
                            @if ($view === 'dikecualikan')
                                <x-admin.table.th>Karyawan</x-admin.table.th>
                                <x-admin.table.th>Alasan</x-admin.table.th>
                                <x-admin.table.th>Terlihat pada berkas</x-admin.table.th>
                            @else
                                <x-admin.table.th>Karyawan</x-admin.table.th>
                                @if ($view === 'karyawan')
                                    <x-admin.table.th>{{ $planned ? 'Riwayat yang Akan Ditulis' : 'Riwayat yang Ditulis' }}</x-admin.table.th>
                                    <x-admin.table.th>Jenis Perubahan</x-admin.table.th>
                                    <x-admin.table.th>Periode</x-admin.table.th>
                                @else
                                    <x-admin.table.th>Jenis</x-admin.table.th>
                                    <x-admin.table.th>Yang terlihat pada berkas</x-admin.table.th>
                                @endif
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 text-surface-800" wire:loading.class="opacity-60" wire:target="search, saring, jenis, mulai, penugasan, gotoPage, nextPage, previousPage, perPage, resetFilters">
                        @forelse ($rows as $item)
                            <tr wire:key="recap-{{ $view }}-{{ $loop->index }}-{{ $item['nik_tersamar'] }}" class="hover:bg-surface-50/75 transition align-top">
                                @if ($view === 'dikecualikan')
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-surface-900">{{ $item['nama'] !== '' ? $item['nama'] : 'Tanpa nama' }}</span>
                                        <span class="block font-mono text-[11px] text-surface-500 whitespace-nowrap">NIK {{ $item['nik_tersamar'] }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-surface-900">{{ $item['alasan'] }}</td>
                                    <td class="px-4 py-3 tabular-nums">{{ $item['periode'] ? 'Bulan '.$item['periode'] : '—' }}</td>
                                @else
                                    {{-- The name opens the employee's Riwayat Karir: no separate action column or repeated button. --}}
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.employees.show', $item['nik']) }}#riwayat-jabatan" aria-label="Lihat Riwayat Karir {{ $item['nama'] }}" title="Lihat Riwayat Karir"
                                            class="block font-semibold text-primary-700 hover:text-primary-800 hover:underline underline-offset-2 rounded focus-visible:outline-2 focus-visible:outline-primary-600">{{ $item['nama'] !== '' ? $item['nama'] : 'Tanpa nama' }}</a>
                                        <span class="block font-mono text-[11px] text-surface-500 whitespace-nowrap">NIK {{ $item['nik_tersamar'] }}</span>
                                    </td>
                                    @if ($view === 'karyawan')
                                        <td class="px-4 py-2.5">
                                            {{-- Same three lines as Riwayat Karir: position, unit, file reference. --}}
                                            <span class="block font-semibold text-surface-900">{{ $item['jabatan'] }}@if ($item['status'] !== '' && $item['status'] !== 'Definitif') <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap align-middle bg-surface-100 text-surface-700 border border-surface-200">{{ $item['status'] }}</span>@endif</span>
                                            <span class="block text-surface-600">{{ $item['unit'] }}</span>
                                            {{-- File reference as on Riwayat Karir: "Bez Jan 2026: <file text>"; the full cell text is the hover text. --}}
                                            <span class="mt-0.5 block truncate text-[11px] text-surface-500" title="{{ $item['sumber_singkat'] }}: {{ $item['teks_sumber'] }}">{{ $item['sumber_singkat'] }}@if ($item['teks_sumber_baris'] !== ''): {{ $item['teks_sumber_baris'] }}@endif</span>
                                        </td>
                                        {{-- Why the row exists: first month seen in the files, or what changed (same labels as Riwayat Karir). --}}
                                        <td class="px-4 py-2.5">
                                            @if ($item['perubahan'])
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap border {{ $item['perubahan'] === \App\Models\JobHistory::JENIS_PERUBAHAN_LABELS['PENEMPATAN_AWAL'] ? 'bg-surface-100 text-surface-700 border-surface-200' : 'bg-info-50 text-info-800 border-info-200' }}">{{ $item['perubahan'] }}</span>
                                            @else
                                                <span class="text-surface-500">—</span>
                                            @endif
                                        </td>
                                        {{-- Dates on one line; labels only for the exceptions explained in the guide above. --}}
                                        <td class="px-4 py-2.5">
                                            <span class="whitespace-nowrap font-medium text-surface-900"
                                                title="{{ $item['selesai_karena'] === 'aplikasi' ? 'Selesai sehari sebelum riwayat pertama di aplikasi (mulai '.$fmt($item['aplikasi_mulai']).')' : 'Selesai pada akhir bulan terakhir jabatan ini terlihat di Bezetting' }}">{{ $fmt($item['dari']) }} – {{ $fmt($item['sampai']) }}</span>
                                            @if ($item['perkiraan'] || $item['selesai_karena'] !== 'aplikasi')
                                                <span class="mt-1 flex flex-wrap gap-1">
                                                    @if ($item['perkiraan'])
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap bg-warning-100 text-warning-900" title="Tanggal pastinya tidak ada pada berkas, jadi dipakai tanggal 1 pada bulan pertama jabatan ini terlihat. Koreksi dari dokumen bila perlu."><i class="fas fa-circle-question text-[10px]" aria-hidden="true"></i> Mulai diperkirakan</span>
                                                    @endif
                                                    @if ($item['selesai_karena'] !== 'aplikasi')
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap bg-surface-100 text-surface-700 border border-surface-200" title="Jabatan ini sudah berganti menurut berkas sebelum riwayat pertama di aplikasi, sehingga tanggal selesai mengikuti berkas.">Selesai menurut berkas</span>
                                                    @endif
                                                </span>
                                            @endif
                                        </td>
                                    @else
                                        <td class="px-4 py-3 whitespace-nowrap">{{ \App\Services\Bezetting\BezettingBackfillService::FOLLOWUP_LABELS[$item['jenis']] ?? $item['jenis'] }}</td>
                                        <td class="px-4 py-3 tabular-nums">{{ sprintf(\App\Services\Bezetting\BezettingBackfillService::FOLLOWUP_HELP[$item['jenis']]['detail'] ?? '%s', $item['periode']) }}
                                            @if (($item['ke'] ?? '') !== '')
                                                <span class="mt-1 block text-[11px] font-normal text-surface-600"><span class="font-semibold text-surface-700">Dari:</span> {{ ($item['dari'] ?? '') !== '' ? $item['dari'] : '—' }}</span>
                                                <span class="block text-[11px] font-normal text-surface-600"><span class="font-semibold text-surface-700">Ke:</span> {{ $item['ke'] }}</span>
                                            @endif
                                            @if ($item['aplikasi_sudah'] ?? false)
                                                <span class="mt-1 block text-[11px] font-normal text-info-800">Aplikasi sudah mencatat jabatan ini; periksa tanggal mulainya.</span>
                                            @endif
                                            @if ($item['nama_disimpan'] ?? false)
                                                <span class="mt-1 block text-[11px] font-normal text-warning-900">Salah satu nama disimpan apa adanya. Periksa dulu apakah hanya beda penulisan unit atau jabatan yang sama.</span>
                                            @endif
                                        </td>
                                    @endif
                                @endif
                            </tr>
                        @empty
                            <x-admin.table.empty-state :colspan="$view === 'karyawan' ? 4 : 3" :icon="$hasActiveFilters ? 'fas fa-filter' : 'fas fa-circle-check'"
                                :message="$hasActiveFilters ? 'Tidak ada data yang sesuai dengan pencarian atau filter.' : $emptyMessage" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-admin.table.pagination :paginator="$rows" :noun="$view === 'karyawan' ? 'baris riwayat' : ($view === 'tindak_lanjut' ? 'tindak lanjut' : 'karyawan')" />
        </x-admin.table.card>
    @endif
</div>
