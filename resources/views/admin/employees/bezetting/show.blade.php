@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <x-admin.module-header
        :title="'Rekonstruksi '.$run->period_from.' s.d. '.$run->period_to"
        subtitle="Tinjau nama unit dan jabatan, karyawan yang dikecualikan, lalu ajukan untuk disetujui admin lain."
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
