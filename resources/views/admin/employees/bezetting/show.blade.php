@extends('layouts.admin')

@section('content')
@php
    $subtitle = match ($run->status) {
        \App\Models\BezettingBackfillRun::STATUS_COMPLETED => 'Rekonstruksi telah disetujui dan tersimpan. Tinjau riwayat yang ditulis dan daftar tindak lanjut di bawah.',
        \App\Models\BezettingBackfillRun::STATUS_PENDING => 'Menunggu peninjauan dan persetujuan admin lain (prinsip empat mata).',
        \App\Models\BezettingBackfillRun::STATUS_ROLLED_BACK => 'Rekonstruksi ini telah dibatalkan; riwayatnya tidak lagi ditampilkan.',
        \App\Models\BezettingBackfillRun::STATUS_WITHDRAWN => 'Proses ini telah ditutup oleh pengaju; data berkasnya sudah dihapus.',
        \App\Models\BezettingBackfillRun::STATUS_EXPIRED => 'Proses ini telah kedaluwarsa sesuai batas waktu penyimpanan.',
        default => 'Tinjau nama unit dan jabatan, karyawan yang dikecualikan, lalu ajukan untuk disetujui admin lain.',
    };
@endphp
<div class="space-y-6">
    <x-admin.module-header
        :title="'Rekonstruksi '.$run->period_from.' s.d. '.$run->period_to"
        :subtitle="$subtitle"
        :breadcrumbs="[
            'Core HR',
            ['label' => 'Karyawan', 'url' => route('admin.employees.index')],
            ['label' => 'Rekonstruksi Riwayat Karir', 'url' => route('admin.employees.bezetting.index')],
            $run->period_from.' s.d. '.$run->period_to,
        ]">
        <x-admin.button href="{{ route('admin.employees.bezetting.index') }}" variant="secondary" icon="fas fa-arrow-left">Kembali ke Daftar Proses</x-admin.button>
    </x-admin.module-header>

    <livewire:admin.employees.bezetting-backfill-detail :run="$run" />
</div>
@endsection
