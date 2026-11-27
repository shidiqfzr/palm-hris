{{--
    Formulir koreksi Riwayat Karir (tambah dan ubah), dibangun dari komponen formulir bersama (DESIGN.md §7.1).
    Aturan ada pada JobHistoryCorrectionService: hanya riwayat yang sudah selesai yang ditambah atau dikoreksi di sini;
    jabatan yang sedang berjalan hanya dapat diberi catatan, karena perpindahan jabatan melalui modul Mutasi.
    Variabel: $employee, $jobHistory (null saat tambah), $running, $rmBand, $mutasiOptions, $levelOptions,
    $positionNames, $unitNames.
--}}
@php
    $row = $jobHistory;
    $isBezetting = $row?->sumber === \App\Models\JobHistory::SUMBER_BEZETTING;
    $recordOnly = $row?->isRecordOnly() ?? false;
    $action = $row
        ? route('admin.employees.job-history.update', ['employee' => $employee->nik, 'job_history' => $row->id])
        : route('admin.employees.job-history.store', $employee->nik);
    $profileUrl = route('admin.employees.show', $employee->nik).'#riwayat-jabatan';
@endphp

@php
    // Client state for the placement pickers: the position list follows the chosen unit and the formasi in force on
    // TMT Awal (structure SK version, or the current Master Formasi before the first SK). The server checks the same.
    $placementState = [
        'tmtAwal' => old('tmt_awal', $row?->tmt_awal?->format('Y-m-d') ?? ''),
        'unit' => old('unit_kerja', $row->unit_kerja ?? ''),
        'jabatan' => old('jabatan', $row->jabatan ?? ''),
        'outside' => (bool) old('nama_di_luar_master', false),
        'formasi' => $formasi,
        'rmMap' => $rmMap,
        'rmLabels' => \App\Models\Position::RM_LEVEL_LIST,
        'positionLabels' => $positionOptions,
        'stored' => $row ? ['jabatan' => $row->jabatan, 'unit' => $row->unit_kerja] : null,
    ];
@endphp
<form action="{{ $action }}" method="POST" class="space-y-6"
    x-data="{
        ...{{ \Illuminate\Support\Js::from($placementState) }},
        get version() { return this.formasi.versions.filter(v => this.tmtAwal && v.from <= this.tmtAwal).pop() ?? null },
        get beforeFirstDecree() { return this.version === null },
        get structureLabel() { return this.version ? 'struktur SK ' + this.version.sk : 'Master Formasi saat ini' },
        get unitKnown() { return Object.prototype.hasOwnProperty.call(this.version ? this.version.units : this.formasi.master, this.unit) },
        get allowed() {
            const list = [...((this.version ? this.version.units : this.formasi.master)[this.unit] ?? [])];
            if (this.stored && this.stored.unit === this.unit && ! list.includes(this.stored.jabatan)) list.unshift(this.stored.jabatan);
            return list;
        },
        get rm() { return this.rmMap[this.jabatan] ?? null },
        publish() { this.$dispatch('job-history-positions', { options: this.allowed.map(n => ({ value: n, label: this.positionLabels[n] ?? n })) }) },
        init() { this.$watch('unit', () => this.publish()); this.$watch('tmtAwal', () => this.publish()); this.$nextTick(() => this.publish()) },
    }"
    @unit-selected="unit = $event.detail.unit"
    @change="if (typeof $event.detail === 'string' && $event.target.closest('[data-field=jabatan]')) jabatan = $event.detail">
    @csrf
    @if ($row)
        @method('PUT')
    @endif

    <x-admin.form.error-summary />

    @if ($running)
        {{-- Jabatan berjalan: tidak dipindahkan di luar modul Mutasi (AGENTS.md aturan 8). --}}
        <x-admin.form.section number="1" title="Jabatan yang Sedang Berjalan" description="Jabatan, unit kerja, dan tanggal hanya berubah melalui modul Mutasi">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div><dt class="text-xs font-bold text-surface-700">Jabatan</dt><dd class="text-surface-900">{{ $row->jabatan }}</dd></div>
                <div><dt class="text-xs font-bold text-surface-700">Unit Kerja</dt><dd class="text-surface-900">{{ $row->unit_kerja }}</dd></div>
                <div><dt class="text-xs font-bold text-surface-700">RM Band / Golongan</dt><dd class="text-surface-900">{{ $rmBand ?? ($row->level ?? '-') }} / {{ $row->golongan ?? '-' }}</dd></div>
                <div><dt class="text-xs font-bold text-surface-700">Periode</dt><dd class="text-surface-900">{{ $row->tmt_awal?->format('d M Y') }} s.d. {{ $row->tmt_akhir?->format('d M Y') ?? 'Sekarang' }}</dd></div>
            </dl>
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 rounded-xl border border-info-200 bg-info-50 px-4 py-3 text-sm text-info-800" role="note">
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                <p class="flex-1">Perpindahan, promosi, rotasi, atau penugasan baru dicatat melalui modul Mutasi beserta SK-nya, sehingga data karyawan, formasi, dan riwayat tetap sejalan. Di sini hanya catatan yang dapat diubah.</p>
                <x-admin.button href="{{ route('admin.mutasi.index') }}" variant="secondary" size="sm" icon="fas fa-right-left">Buka Mutasi</x-admin.button>
            </div>
        </x-admin.form.section>
    @else
        <x-admin.form.section number="1" title="Jabatan & Unit Kerja" description="Nama jabatan dan unit kerja pada periode tersebut, beserta golongan karyawan saat itu" legend>
            {{-- Master data, not free text (DESIGN.md §6): unit first, then a position from that unit's formasi on TMT Awal.
                 Old names outside the master are a controlled exception before the first structure SK only. --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <template x-if="! outside">
                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label id="unit-kerja-label" class="block text-xs font-bold text-surface-700 mb-1.5">Unit Kerja <span class="text-danger">*</span></label>
                            <x-admin.unit-combobox :grouped-units="$groupedUnits" :selected="old('unit_kerja', $row->unit_kerja ?? '')" id="unit_kerja" name="unit_kerja" label="Unit Kerja" />
                            <p class="text-[10px] text-surface-500 mt-1">Pilih unit lebih dulu; daftar jabatan mengikuti formasi unit ini.</p>
                        </div>
                        <div data-field="jabatan">
                            <label for="jabatan" class="block text-xs font-bold text-surface-700 mb-1.5">Jabatan <span class="text-danger">*</span></label>
                            <x-admin.searchable-select name="jabatan" id="jabatan" size="form" icon="fas fa-briefcase" :options="$positionOptions" options-event="job-history-positions"
                                :selected="old('jabatan', $row->jabatan ?? '')" placeholder="-- Pilih jabatan pada formasi unit --" search-placeholder="Cari jabatan..." empty-text="Unit ini tidak memiliki formasi jabatan" />
                            <p class="text-[10px] text-surface-500 mt-1" aria-live="polite">
                                <span x-show="unit && unitKnown">Formasi <span x-text="unit"></span> menurut <span x-text="structureLabel"></span>.</span>
                                <span x-show="unit && ! unitKnown">Unit ini tidak tercantum pada <span x-text="structureLabel"></span>; jabatannya hanya dapat dipertahankan. Pilih unit resmi untuk memilih jabatan lain.</span>
                                <span x-show="! unit">Pilih unit kerja untuk menampilkan formasi jabatannya.</span>
                            </p>
                        </div>
                    </div>
                </template>
                <template x-if="outside">
                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <x-admin.form.input name="unit_kerja" label="Nama Unit Kerja Lama" maxlength="255" :value="old('unit_kerja', '')" required
                            placeholder="Tulis sesuai dokumen" hint="Nama unit yang sudah ada pada Master Formasi otomatis disimpan dengan ejaan resminya." />
                        <x-admin.form.input name="jabatan" label="Nama Jabatan Lama" maxlength="255" :value="old('jabatan', '')" required x-model="jabatan"
                            placeholder="Tulis sesuai dokumen" hint="Dicatat sebagai nama lama (di luar struktur saat ini) pada kamus nama yang sama dengan Rekonstruksi Bezetting." />
                    </div>
                </template>
                @error('unit_kerja') <p id="unit_kerja-error" class="md:col-span-2 text-danger-700 text-sm -mt-3">{{ $message }}</p> @enderror
                @error('jabatan') <p id="jabatan-error" class="md:col-span-2 text-danger-700 text-sm -mt-3">{{ $message }}</p> @enderror

                {{-- Controlled exception: only for a period before the first structure SK, when no structure is recorded. --}}
                <div class="md:col-span-2 rounded-xl border border-surface-200 bg-surface-50 px-4 py-3" x-show="beforeFirstDecree">
                    <label class="flex items-start gap-3 text-sm text-surface-800 cursor-pointer">
                        <input type="checkbox" name="nama_di_luar_master" value="1" x-model="outside" class="mt-0.5 rounded border-surface-300 text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500">
                        <span>
                            <span class="font-semibold">Jabatan atau unit lama tidak ada pada Master Formasi</span>
                            <span class="block text-xs text-surface-600">Hanya untuk periode sebelum SK struktur pertama, saat struktur belum tercatat. Bila nama lama itu sama dengan jabatan atau unit yang kini ada, pilih nama resminya dari daftar dan tulis nama lama pada Catatan.</span>
                        </span>
                    </label>
                </div>

                <div x-show="rm">
                    <span class="block text-xs font-bold text-surface-700 mb-1.5">RM Band</span>
                    <p class="w-full px-3.5 py-2.5 bg-surface-100 border border-surface-200 rounded-lg text-xs sm:text-sm font-medium text-surface-600" x-text="rmLabels[rm] ?? rm" aria-live="polite"></p>
                    <p class="text-[10px] text-surface-500 mt-1">Mengikuti Master Formasi; Level (Karpim/Karpel) ditentukan otomatis dari RM Band.</p>
                </div>
                <div x-show="!rm" x-cloak>
                    <x-admin.form.select name="level" label="Level" :options="$levelOptions" :selected="$row->level ?? ''" placeholder="-- Pilih Level --"
                        hint="Hanya untuk nama jabatan lama di luar Master Formasi; jabatan pada master mengikuti RM Band-nya." />
                </div>
                <x-admin.form.input name="golongan" label="Golongan" maxlength="20" :value="$row->golongan ?? ''" placeholder="Contoh: IIIA/00 atau IVB/03"
                    hint="Format baku [Gol]/[Berkala] dengan berkala dua digit." />
            </div>
        </x-admin.form.section>

        <x-admin.form.section number="2" title="Periode & Dasar Penempatan" description="Masa menjabat dan dasar resminya bila ada">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-admin.form.input type="date" name="tmt_awal" label="TMT Awal" :value="$row?->tmt_awal?->format('Y-m-d') ?? ''" required x-model="tmtAwal"
                    hint="Menentukan struktur yang berlaku untuk daftar jabatan." />
                <x-admin.form.input type="date" name="tmt_akhir" label="TMT Akhir" :value="$row?->tmt_akhir?->format('Y-m-d') ?? ''" required max="{{ now()->toDateString() }}"
                    hint="Riwayat di sini adalah jabatan yang sudah selesai. Jabatan yang sedang berjalan dicatat melalui modul Mutasi." />

                @if ($recordOnly)
                    {{-- Baris selain mutasi resmi bukan SK Direksi: SK tidak diisi di sini (DOMAIN.md §2.2, §5.5). --}}
                    <div class="md:col-span-2 flex items-start gap-3 rounded-xl border border-info-200 bg-info-50 px-4 py-3 text-sm text-info-800" role="note">
                        <i class="fas fa-database mt-1" aria-hidden="true"></i>
                        <p>
                            <span class="font-semibold">{{ $row->sumber_label }}: catatan data kepegawaian, bukan SK mutasi.</span>
                            Nomor dan tanggal SK tidak diisi di sini; SK dicatat lewat modul Mutasi.
                            @if ($row->nomor_sk) Nomor SK yang sudah tersimpan ({{ $row->nomor_sk }}) dipertahankan. @endif
                            @if ($isBezetting) Riwayat dari Bezetting wajib memiliki tanggal berakhir dan tidak memiliki jenis mutasi resmi. @endif
                        </p>
                    </div>
                @endif

                @unless ($isBezetting)
                    <x-admin.form.select name="jenis_mutasi" label="Jenis Mutasi" :options="$mutasiOptions" :selected="$row->jenis_mutasi ?? ''" placeholder="-- Tanpa jenis mutasi --" />
                @endunless

                @unless ($recordOnly)
                    <x-admin.form.input name="nomor_sk" label="Nomor SK" maxlength="100" :value="$row->nomor_sk ?? ''" placeholder="Contoh: SK-123/HRD/2025" />
                    <x-admin.form.input type="date" name="tanggal_sk" label="Tanggal SK" :value="$row?->tanggal_sk?->format('Y-m-d') ?? ''" />
                @endunless
            </div>
        </x-admin.form.section>
    @endif

    <x-admin.form.section :number="$running ? 2 : 3" title="Catatan & Alasan" description="Alasan koreksi tercatat pada riwayat perubahan data beserta nama Anda">
        <div class="space-y-6">
            <x-admin.form.textarea name="catatan" label="Catatan" maxlength="2000" rows="3" placeholder="Catatan tambahan (opsional)" :value="$row->catatan ?? ''" />
            <x-admin.form.reason-field id="alasan" name="alasan" label="Alasan perubahan" :value="old('alasan', '')"
                placeholder="Contoh: koreksi nama unit sesuai SK, atau melengkapi riwayat dari dokumen kepegawaian (minimal 10 karakter)"
                hint="Wajib. Dicatat pada riwayat perubahan data." />
        </div>
    </x-admin.form.section>

    <x-admin.form.footer :cancel-url="$profileUrl" :submit-label="$row ? 'Simpan Perubahan' : 'Simpan Riwayat'"
        note="Penambahan, perubahan, dan alasannya tercatat pada riwayat perubahan data." />
</form>
