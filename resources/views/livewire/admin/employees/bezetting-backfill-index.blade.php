{{-- Rekonstruksi Riwayat Karir: unggah Bezetting bulanan dan daftar proses (DOMAIN.md §5.5, DESIGN.md §8.5a). --}}
<div class="space-y-6">
    <x-admin.flash />

    <x-admin.approval-policy-notice :active="true">
        <x-slot:enabled>Riwayat hasil rekonstruksi baru tersimpan setelah disetujui admin lain, dan pengajuan yang tidak diputuskan dalam {{ $approvalTtlDays }} hari kedaluwarsa otomatis.</x-slot:enabled>
        Riwayat yang sudah tersimpan dapat dibatalkan dalam {{ $undoWindowDays }} hari, selama riwayatnya belum berubah.
    </x-admin.approval-policy-notice>

    <section class="bg-white rounded-2xl border border-surface-200/80 p-5 shadow-xs space-y-4" aria-labelledby="upload-title">
        <div>
            <h2 id="upload-title" class="text-base font-bold text-surface-900">Unggah Bezetting Bulanan</h2>
            <p class="mt-1 text-sm text-surface-600 leading-relaxed">
                Unggah berkas asli apa adanya, satu berkas per bulan (paling banyak {{ $maxFiles }} berkas). Hanya kolom yang diperlukan riwayat yang dibaca;
                agama, pendidikan, status keluarga, dan NIK SAP lama tidak dibaca. Berkas dihapus dari server segera setelah dibaca.
            </p>
        </div>
        <form wire:submit="stage" class="space-y-4">
            <x-admin.file-upload wire-model="files" id="bezetting-files" :file="$files" :multiple="true"
                label="Berkas Bezetting" helper="(.xls atau .xlsx, maks. 10 MB per berkas)" accept=".xls,.xlsx"
                error-key="files" icon="fas fa-file-excel" icon-active="text-primary-600"
                empty-text="Belum ada berkas dipilih" button-text="Pilih Berkas" change-button-text="Ganti Berkas" />
            <div class="flex justify-end">
                <x-admin.button type="submit" icon="fas fa-magnifying-glass-chart" target="stage">Baca Berkas</x-admin.button>
            </div>
        </form>
    </section>

    <x-admin.table.card>
        <x-admin.table.header title="Proses Rekonstruksi" :paginator="$runs" noun="Proses" />
        <div class="relative overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-surface-50">
                    <tr class="border-b border-surface-200">
                        <x-admin.table.th>Periode</x-admin.table.th>
                        <x-admin.table.th>Berkas</x-admin.table.th>
                        <x-admin.table.th>Status</x-admin.table.th>
                        <x-admin.table.th>Pengaju</x-admin.table.th>
                        <x-admin.table.th>Penyetuju</x-admin.table.th>
                        <x-admin.table.th class="text-right">Baris Riwayat</x-admin.table.th>
                        <x-admin.table.th class="text-right">Aksi</x-admin.table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-100 text-surface-800">
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}" class="hover:bg-surface-50/75 transition">
                            <td class="px-4 py-3 font-semibold text-surface-900 whitespace-nowrap">{{ $run->period_from }} s.d. {{ $run->period_to }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ count($run->files ?? []) }}</td>
                            <td class="px-4 py-3"><x-admin.bezetting-status :status="$run->status" /></td>
                            <td class="px-4 py-3">{{ $run->maker_name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $run->checker_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($run->rows_written, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-admin.button href="{{ route('admin.employees.bezetting.show', $run) }}" variant="secondary" size="sm" icon="fas fa-eye" wire:navigate>Buka</x-admin.button>
                            </td>
                        </tr>
                    @empty
                        <x-admin.table.empty-state :colspan="7" icon="fas fa-timeline" message="Belum ada proses rekonstruksi. Unggah berkas Bezetting untuk memulai." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.table.pagination :paginator="$runs" noun="proses" />
    </x-admin.table.card>
</div>
