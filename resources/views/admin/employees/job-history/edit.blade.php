@extends('layouts.admin')

@section('content')
<div class="space-y-6 max-w-5xl mx-auto">
    <x-admin.page-header
        title="Ubah Riwayat Jabatan"
        subtitle="{{ $employee->nama }} · Koreksi data riwayat karir"
        icon="fas fa-briefcase"
        :breadcrumbs="[
            ['label' => 'Core HR'],
            ['label' => 'Karyawan', 'url' => route('admin.employees.index')],
            ['label' => $employee->nama, 'url' => route('admin.employees.show', $employee->nik)],
            ['label' => 'Ubah Riwayat Jabatan'],
        ]"
        back-url="{{ route('admin.employees.show', $employee->nik) }}#riwayat-jabatan"
        back-label="Kembali ke Profil"
    />

    @include('admin.employees.job-history.form')
</div>
@endsection
