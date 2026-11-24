{{-- Detail Rekonstruksi Riwayat dari Bezetting: pola layar Riwayat & Persetujuan Impor dan SK Struktur (DESIGN.md §8.5a). --}}
<div class="space-y-6" x-data="{ approveOpen: false, rejectOpen: false, rollbackOpen: false, bulkOpen: false, submitOpen: false }"
    @bezetting-action-finished.window="approveOpen = false; rejectOpen = false; rollbackOpen = false; bulkOpen = false; submitOpen = false">
    <x-admin.flash />

    @if ($run->payload_purged_at && $run->status !== 'completed' && $run->status !== 'rolled_back')
        <div role="status" class="flex items-start gap-3 rounded-xl border border-surface-200 bg-surface-50 px-4 py-3 text-sm text-surface-700">
            <i class="fas fa-circle-info mt-1 text-surface-500" aria-hidden="true"></i>
            <p>Data berkas proses ini sudah dihapus sesuai batas penyimpanan. Unggah ulang berkas Bezetting untuk memulai proses baru.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <div class="lg:col-span-8 space-y-6">
            <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs space-y-4" aria-labelledby="summary-title">
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
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3"><dt class="text-surface-600">Tanggal mulai perkiraan</dt><dd class="font-mono tabular-nums font-semibold text-surface-900">{{ $summary['diperkirakan'] ?? 0 }}</dd></div>
                        <div class="flex items-baseline justify-between gap-3"><dt class="text-surface-600">Keluar dari daftar Bezetting</dt><dd class="font-mono tabular-nums font-semibold text-surface-900">{{ $summary['keluar_daftar'] ?? 0 }}</dd></div>
                        <div class="flex items-baseline justify-between gap-3"><dt class="text-surface-600">Celah bulan (tidak tercantum lalu tercantum lagi)</dt><dd class="font-mono tabular-nums font-semibold text-surface-900">{{ $summary['celah'] ?? 0 }}</dd></div>
                        <div class="flex items-baseline justify-between gap-3"><dt class="text-surface-600">Perpindahan belum tercatat di aplikasi</dt><dd class="font-mono tabular-nums font-semibold {{ ($summary['perpindahan_belum_tercatat'] ?? 0) > 0 ? 'text-warning-800' : 'text-surface-900' }}">{{ $summary['perpindahan_belum_tercatat'] ?? 0 }}</dd></div>
                    </dl>
                    @if (($summary['perpindahan_belum_tercatat'] ?? 0) > 0)
                        <p class="text-xs text-surface-600">Perpindahan sesudah riwayat pertama di aplikasi tidak ditulis oleh rekonstruksi. Proses melalui modul Mutasi beserta SK-nya.</p>
                    @endif
                @endif
            </section>

            @if ($plan !== null)
                <x-admin.table.card>
                    <x-admin.table.header title="Nama yang Perlu Diputuskan" :paginator="$blocking" noun="Nama">
                        <x-slot:actions>
                            @if ($editable && ($blockingCounts['jabatan'] ?? 0) > 0)
                                <x-admin.button variant="secondary" size="sm" icon="fas fa-check-double" @click="bulkOpen = true">Simpan Jabatan Tersisa Apa Adanya</x-admin.button>
                            @endif
                        </x-slot:actions>
                    </x-admin.table.header>
                    <div class="px-5 py-3 border-b border-surface-200 flex flex-wrap items-center gap-3 text-xs">
                        <label for="kind-filter" class="font-semibold text-surface-700">Jenis</label>
                        <select id="kind-filter" wire:model.live="kindFilter" class="h-8 py-0 pl-2 pr-7 appearance-none bg-white select-chevron border border-surface-300 rounded-md text-xs text-surface-800 focus:outline-none focus:ring-2 focus:ring-primary-100 focus:border-primary-600">
                            <option value="">Semua ({{ array_sum($blockingCounts) }})</option>
                            <option value="unit">Unit kerja ({{ $blockingCounts['unit'] ?? 0 }})</option>
                            <option value="jabatan">Jabatan ({{ $blockingCounts['jabatan'] ?? 0 }})</option>
                            <option value="status">Frasa status ({{ $blockingCounts['status'] ?? 0 }})</option>
                        </select>
                        <p class="text-surface-600">Petakan ke Master Formasi, simpan apa adanya (di luar struktur saat ini), atau lewati. Tidak ada unit atau jabatan yang dibuat.</p>
                    </div>
                    <div class="relative overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead class="bg-surface-50">
                                <tr class="border-b border-surface-200">
                                    <x-admin.table.th>Jenis</x-admin.table.th>
                                    <x-admin.table.th>Nama pada Berkas</x-admin.table.th>
                                    <x-admin.table.th class="text-right">Baris</x-admin.table.th>
                                    <x-admin.table.th>Petakan ke</x-admin.table.th>
                                    <x-admin.table.th class="text-right">Keputusan</x-admin.table.th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-100 text-surface-800">
                                @forelse ($blocking as $item)
                                    <tr wire:key="name-{{ $item['key'] }}" class="hover:bg-surface-50/75 transition align-top">
                                        <td class="px-4 py-3 whitespace-nowrap">{{ ['unit' => 'Unit kerja', 'jabatan' => 'Jabatan', 'status' => 'Frasa status'][$item['kind']] ?? $item['kind'] }}</td>
                                        <td class="px-4 py-3 font-semibold text-surface-900">
                                            {{ $item['text'] }}
                                            @if ($item['suggestion'])
                                                <span class="mt-1 block text-[11px] font-normal text-surface-600">Saran: {{ $item['suggestion'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ $item['count'] }}</td>
                                        <td class="px-4 py-3 min-w-[14rem]">
                                            @if ($editable)
                                                <label for="target-{{ $item['key'] }}" class="sr-only">Petakan {{ $item['text'] }} ke</label>
                                                <select id="target-{{ $item['key'] }}" wire:model="targets.{{ $item['key'] }}" class="w-full h-8 py-0 pl-2 pr-7 appearance-none bg-white select-chevron border border-surface-300 rounded-md text-xs text-surface-800 focus:outline-none focus:ring-2 focus:ring-primary-100 focus:border-primary-600">
                                                    <option value="">Pilih nama resmi…</option>
                                                    @foreach ($options[$item['kind']] as $option)
                                                        <option value="{{ $option }}">{{ $option }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <span class="text-surface-500">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($editable)
                                                <div class="flex flex-wrap justify-end gap-1.5">
                                                    <x-admin.button size="sm" icon="fas fa-link" wire:click="map('{{ $item['key'] }}')" target="map">Petakan</x-admin.button>
                                                    @if ($item['kind'] !== 'status')
                                                        <x-admin.button size="sm" variant="secondary" wire:click="keep('{{ $item['key'] }}')" target="keep">Apa Adanya</x-admin.button>
                                                        <x-admin.button size="sm" variant="ghost" wire:click="skip('{{ $item['key'] }}')" target="skip">Lewati</x-admin.button>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="block text-right text-surface-500">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <x-admin.table.empty-state :colspan="5" icon="fas fa-circle-check" message="Semua nama sudah diputuskan." />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <x-admin.table.pagination :paginator="$blocking" noun="nama" />
                </x-admin.table.card>

                <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs space-y-3" aria-labelledby="excluded-title">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="excluded-title" class="text-base font-bold text-surface-900">Karyawan yang Dikecualikan</h2>
                        <x-admin.button href="{{ route('admin.employees.bezetting.verification', $run) }}" variant="secondary" size="sm" icon="fas fa-file-excel">Unduh Daftar Verifikasi SDM</x-admin.button>
                    </div>
                    <p class="text-sm text-surface-600">Karyawan berikut tidak ditulis pada proses ini. Daftar verifikasi (NIK utuh) hanya untuk pemeriksaan SDM terhadap dokumen asli; setiap unduhan tercatat.</p>
                    @forelse ($excludedGroups as $group)
                        <details class="rounded-xl border border-surface-200">
                            <summary class="flex items-center justify-between gap-3 px-4 py-2.5 cursor-pointer text-sm font-semibold text-surface-800">
                                <span>{{ $group['label'] }}</span>
                                <span class="font-mono tabular-nums">{{ $group['count'] }}</span>
                            </summary>
                            <ul class="px-4 pb-3 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 text-xs text-surface-700 font-mono">
                                @foreach ($group['people'] as $person)
                                    <li>{{ $person['nik'] }}@if ($person['periode']) <span class="text-surface-500">({{ $person['periode'] }})</span>@endif</li>
                                @endforeach
                                @if ($group['count'] > count($group['people']))
                                    <li class="col-span-full text-surface-500 font-sans">Selengkapnya pada daftar verifikasi.</li>
                                @endif
                            </ul>
                        </details>
                    @empty
                        <p class="text-sm text-surface-600">Tidak ada karyawan yang dikecualikan.</p>
                    @endforelse
                </section>
            @endif

            <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs space-y-3" aria-labelledby="decision-title">
                <h2 id="decision-title" class="text-base font-bold text-surface-900">Keputusan</h2>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($canSubmit)
                        <x-admin.button icon="fas fa-paper-plane" @click="submitOpen = true">Ajukan untuk Persetujuan</x-admin.button>
                    @elseif ($editable)
                        <p class="text-sm text-surface-600">Putuskan seluruh nama lebih dulu. Hanya pengaju proses ini yang dapat mengajukannya.</p>
                    @endif

                    @if ($canApprove)
                        <x-admin.button icon="fas fa-check" @click="approveOpen = true">Setujui dan Simpan Riwayat</x-admin.button>
                        <x-admin.button variant="secondary" icon="fas fa-ban" @click="rejectOpen = true">Kembalikan</x-admin.button>
                    @elseif ($blockedByFourEyes)
                        <p class="flex-1 min-w-[16rem] text-sm text-surface-600 leading-relaxed">
                            <i class="fas fa-user-lock mr-1 text-surface-500" aria-hidden="true"></i>
                            Anda adalah pengaju proses ini sehingga tidak dapat menyetujuinya sendiri (prinsip empat mata). Admin lain perlu meninjau dan menyetujui.
                        </p>
                    @endif
                </div>
                @if ($run->status === 'completed')
                    <p class="text-sm text-surface-600">Riwayat tersimpan sebagai catatan data kepegawaian (bukan SK mutasi) dan tampil pada Riwayat Karir dengan lencana Bezetting Historis.</p>
                @endif
            </section>

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
        </div>

        <aside class="lg:col-span-4 space-y-6">
            <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs" aria-labelledby="timeline-title">
                <h2 id="timeline-title" class="text-base font-bold text-surface-900 mb-4">Jejak Keputusan</h2>
                <x-admin.decision-timeline :steps="$steps" />
            </section>

            <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs space-y-3" aria-labelledby="files-title">
                <h2 id="files-title" class="text-base font-bold text-surface-900">Berkas Terbaca</h2>
                <p class="text-xs text-surface-600">Berkas sudah dihapus dari server; yang tersimpan hanya nama, periode, dan kode verifikasinya.</p>
                <ul class="divide-y divide-surface-100 text-xs">
                    @foreach ($run->files ?? [] as $file)
                        <li class="py-2" title="Kode verifikasi berkas (SHA-256): {{ $file['sha256'] }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-surface-900 truncate">{{ $file['name'] }}</span>
                                <span class="font-mono tabular-nums text-surface-600 shrink-0">{{ $file['rows'] }} baris</span>
                            </div>
                            <span class="block font-mono text-[11px] text-surface-500 break-all">{{ substr($file['sha256'], 0, 16) }}…</span>
                            @foreach ($file['warnings'] ?? [] as $warning)
                                <span class="mt-1 block text-[11px] text-warning-800"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>{{ $warning }}</span>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>

    {{-- Dialog konfirmasi (DESIGN.md §7.6) --}}
    @if ($canSubmit)
        <x-admin.modal open="submitOpen" title="Ajukan rekonstruksi ini?" description="Setelah diajukan, keputusan nama dikunci dan admin lain perlu menyetujui sebelum riwayat tersimpan.">
            <x-slot:footer>
                <x-admin.button icon="fas fa-paper-plane" wire:click="submit" target="submit">Ajukan</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($editable)
        <x-admin.modal open="bulkOpen" tone="warning" title="Simpan seluruh nama jabatan tersisa apa adanya?" description="{{ $blockingCounts['jabatan'] ?? 0 }} nama jabatan yang belum diputuskan akan disimpan sesuai teks berkas dan ditandai di luar struktur saat ini. Setiap keputusan tercatat.">
            <x-slot:footer>
                <x-admin.button icon="fas fa-check-double" wire:click="keepRemainingPositions" target="keepRemainingPositions">Simpan Apa Adanya</x-admin.button>
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
