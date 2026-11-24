{{--
    Lencana status proses Rekonstruksi Riwayat dari Bezetting. Label, warna, dan ikon mengikuti Tabel Status Induk
    (DESIGN.md §5.1); label diambil dari BezettingBackfillRun::STATUS_LABELS, dan setiap status memiliki ikon.

    <x-admin.bezetting-status :status="$run->status" />
--}}
@props(['status'])

@php
    $label = \App\Models\BezettingBackfillRun::STATUS_LABELS[$status] ?? $status;
    [$classes, $icon] = match ($status) {
        'staged' => ['bg-surface-100 text-surface-600 border-surface-300', 'fa-pen'],
        'pending_approval' => ['bg-warning-100 text-warning-800 border-transparent', 'fa-clock'],
        'completed' => ['bg-primary-100 text-primary-800 border-transparent', 'fa-check'],
        'rejected' => ['bg-surface-100 text-surface-600 border-surface-300', 'fa-ban'],
        'rolled_back' => ['bg-surface-100 text-surface-600 border-surface-300', 'fa-rotate-left'],
        'expired' => ['bg-surface-100 text-surface-600 border-surface-300', 'fa-hourglass-end'],
        default => ['bg-surface-100 text-surface-600 border-surface-300', 'fa-circle'],
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-semibold whitespace-nowrap {$classes}"]) }}>
    <i class="fas {{ $icon }} text-[10px]" aria-hidden="true"></i>{{ $label }}
</span>
