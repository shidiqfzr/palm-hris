{{--
    Panduan membaca tabel (DESIGN.md §7.5 dan §8.6a): satu bentuk untuk semua tab tinjauan, di dalam x-admin.table.card tepat
    di bawah toolbar dan di atas tabel. Judul, pengantar singkat, lalu istilah beserta artinya dan, bila SDM perlu bertindak,
    "Yang perlu dilakukan". Isinya berasal dari App\Support\BezettingReviewGuide; jangan menulis blok penjelasan sendiri.

    <x-admin.table.guide :guide="\App\Support\BezettingReviewGuide::names()" />
    <x-admin.table.guide :guide="\App\Support\BezettingReviewGuide::history($people)"> ...contoh tambahan... </x-admin.table.guide>

    guide : ['title' => string, 'intro' => string, 'items' => [['term', 'arti', 'tindakan'?]], 'note' => ?string]
    Slot  : isi tambahan opsional di bawah daftar istilah (mis. "Lihat contoh").
    open  : terbuka saat dimuat (bawaan tertutup, sama di semua tabel); admin membuka dan menutupnya lewat judul panduan.
--}}
@props(['guide', 'open' => false])

@php
    // Balanced rows: 4 → 2 columns, 8 → 4 columns, otherwise 3 (or 2 for a pair).
    $columns = match (true) {
        count($guide['items']) === 8 => 'lg:grid-cols-4',
        count($guide['items']) === 4 => 'lg:grid-cols-2',
        count($guide['items']) >= 3 => 'lg:grid-cols-3',
        default => 'lg:grid-cols-2',
    };
@endphp

<details {{ $attributes }} wire:ignore.self class="group px-5 py-3 border-b border-surface-200 bg-surface-50/60 text-xs text-surface-700 leading-relaxed" aria-label="{{ $guide['title'] }}" @if ($open) open @endif>
    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded font-semibold text-surface-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 [&::-webkit-details-marker]:hidden">
        <i class="fas fa-circle-info text-primary-600" aria-hidden="true"></i>
        <span>{{ $guide['title'] }}</span>
        <span class="ml-auto inline-flex items-center gap-1 font-medium text-primary-700"><span class="group-open:hidden">Tampilkan</span><span class="hidden group-open:inline">Sembunyikan</span><i class="fas fa-chevron-down text-[10px] transition-transform group-open:rotate-180" aria-hidden="true"></i></span>
    </summary>
    <p class="mt-1">{{ $guide['intro'] }}</p>

    @if ($guide['items'] !== [])
        <dl class="mt-2 grid gap-x-6 gap-y-2 {{ $columns }}">
            @foreach ($guide['items'] as $item)
                <div>
                    <dt class="font-bold text-surface-900">{{ $item['term'] }}</dt>
                    <dd>{{ $item['arti'] }}</dd>
                    @if (! empty($item['tindakan']))
                        <dd class="mt-0.5"><strong>Yang perlu dilakukan:</strong> {{ $item['tindakan'] }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    @endif

    @if (! empty($guide['note']))
        <p class="mt-2 text-surface-600">{{ $guide['note'] }}</p>
    @endif

    {{ $slot }}
</details>
