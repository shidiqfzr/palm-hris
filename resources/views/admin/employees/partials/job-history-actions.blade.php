{{--
    Aksi baris Riwayat Karir (DESIGN.md §7.5 dan §8.7 butir 3): satu menu kebab, Ubah lalu Hapus (danger, dipisah garis).
    Hapus membuka x-admin.confirm-delete-modal (beralasan) yang ada sekali pada tabel; riwayat yang masih berjalan tidak
    dapat dihapus karena hanya berubah melalui Mutasi (aturan yang sama ditegakkan JobHistoryController::destroy).
--}}
@php
    $label = $row->jabatan.' ('.($row->tmt_awal?->format('d M Y') ?? '-').')';
@endphp
<x-admin.row-menu :label="'riwayat '.$label">
    <x-admin.master.menu-item icon="fas fa-pen" :href="route('admin.employees.job-history.edit', ['employee' => $row->employee_nik, 'job_history' => $row->id])">Ubah Riwayat</x-admin.master.menu-item>
    @if ($row->tmt_akhir !== null)
        <x-admin.master.menu-item icon="fas fa-trash-can" tone="danger" separated
            @click="$dispatch('confirm-delete', { action: {{ \Illuminate\Support\Js::from(route('admin.employees.job-history.destroy', ['employee' => $row->employee_nik, 'job_history' => $row->id])) }}, name: {{ \Illuminate\Support\Js::from($label) }} })">Hapus Riwayat</x-admin.master.menu-item>
    @endif
</x-admin.row-menu>
