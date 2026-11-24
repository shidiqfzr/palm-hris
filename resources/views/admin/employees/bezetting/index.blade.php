@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <x-admin.module-header
        title="Rekonstruksi Riwayat Karir"
        subtitle="Bangun riwayat jabatan Karyawan Pimpinan dari Bezetting bulanan. Riwayat ini adalah catatan data kepegawaian, bukan SK mutasi."
        :breadcrumbs="[
            'Core HR',
            ['label' => 'Karyawan', 'url' => route('admin.employees.index')],
            ['label' => 'Impor Data Spreadsheet', 'url' => route('admin.employees.import.page')],
            'Rekonstruksi Riwayat Karir',
        ]">
        <x-admin.button href="{{ route('admin.employees.import.batches.index') }}" variant="secondary" icon="fas fa-clock-rotate-left">Riwayat & Persetujuan Impor</x-admin.button>
    </x-admin.module-header>

    <livewire:admin.employees.bezetting-backfill-index />
</div>
@endsection
