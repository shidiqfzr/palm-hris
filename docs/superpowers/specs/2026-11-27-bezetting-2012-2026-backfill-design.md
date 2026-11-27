# Multi-Year Career History Backfill (2012–2026) — Design

Date: 2026-11-27 · Builds on: `docs/superpowers/specs/2026-11-23-career-history-backfill-design.md` · Size: project · Status: **approved by user 2026-11-27**

## 1. Intent and Why

The initial Bezetting Career History Backfill (2026-11-23) was scoped to **2025–2026 (22 monthly files)** because only those files were available at the time. HR now has the complete 15-year archive of **178 monthly Bezetting files from January 2012 to October 2026** (`Bezeting 2012 - 2026/`).

Goal: extend the existing Bezetting backfill module so HR can reconstruct Karpim career timelines back to **January 2012** safely, year-by-year (rolling backward from newest to oldest), without losing four-eyes governance, per-run rollback independence, 8-digit NIK integrity, or UU PDP data minimization.

## 2. Goals, Non-Goals, Anti-Goals

### Goals
- Support progressive **multi-batch backward reconstruction** (12–24 months per run, starting from 2026 down to 2012) where each older run fills strictly before an employee's earliest existing `job_histories.tmt_awal` (including `sumber = bezetting` rows from newer runs).
- Support all **4 historical template eras** observed across the 178 real files (2012–2026) in `BezettingWorkbookReader`.
- Resolve pre-October 2022 legacy PTPN XIII employee numbers (`999.9999.9999.9999`, 14 digits) to the current 8-digit `employees.nik` using a **privacy-safe hashed crosswalk** learned from the dual-ID files (Oct 2022 – Oct 2026, which cover 269 of 345 active employees with 0 conflicts).
- Allow HR to **review and revise already-decided unit/position/status mappings** on the **Nama** tab of an open run, since 15 years of legacy PTPN XIII names will accumulate hundreds of decisions across runs.
- Keep every run's written rows immutable after commit so per-run `rollback()` (`Batalkan Rekonstruksi`) remains independent and atomic.

### Non-Goals
- Uploading all 178 files in a single HTTP request (`max_files` remains `24` in `config/hris.php`).
- Storing raw legacy PTPN XIII numbers (`999.9999.9999.9999`) on `employees`, `job_histories`, audit logs, or UI screens.
- Backfilling employees who retired before the app went live and are not in `employees` (they remain excluded as `belum_ada_di_aplikasi` per `DOMAIN.md` §5.5 rule 7).
- Merging or mutating `job_histories` rows written by a previously committed run.

### Anti-Goals (failure even if it technically works)
- Breaking independent per-run rollback by modifying `tmt_awal`/`tmt_akhir` of rows written by an earlier run.
- Storing plaintext legacy PTPN XIII numbers in any permanent table (violates `SECURITY.md` §4.4 data minimization).
- Silently guessing employee identity by name matching when a legacy ID has no verified crosswalk entry (only exact 1-to-1 legacy ID $\rightarrow$ 8-digit NIK crosswalk matches are allowed; conflicts exclude the employee as `identitas_tidak_konsisten`).
- Creating units or positions in Master Formasi or editing `master_formasi.json` (`AGENTS.md` rule 8).
- Exposing internal doc filenames or `SSOT` in any user-facing Blade text (`AGENTS.md` rule 6).

## 3. Constraints

- **SSOT First (`AGENTS.md` Rule 1):** Update `DOMAIN.md` §5.5, `DESIGN.md` §8.6a, and `SECURITY.md` §4.4 & §5.1c before or alongside implementation.
- **Tech Stack & Quality (`ENGINEERING.md`):** Laravel 11, Livewire, PHPUnit 11, `DB::transaction()` for multi-table writes, 300 ms debounce on Livewire search, Laravel Pint (`./vendor/bin/pint`), One Test Class per SUT (`AGENTS.md` Rule 5).
- **Security & Privacy (`SECURITY.md`):** Permission `karyawan.import` via Gate on every action; mandatory four-eyes approval; NIK masked on screen (`12****78`); uploaded files deleted immediately after reading; staged payloads encrypted and purged on retention.
- **Terminology (`AGENTS.md` Rules 6–7):** Use official Indonesian business wording ("Karyawan", "Karyawan Pimpinan", "Nomor Induk Karyawan"); never expose internal filenames.

## 4. Approaches Considered

| Approach | Summary | Trade-offs | Decision |
|---|---|---|---|
| **1. Rolling Backward Batches + Legacy NIK Crosswalk (Full 2012–2026)** | Keep 12–24 files/run; fix `BackfillPlanner` to use progressive `$cutoff`; add hashed legacy NIK crosswalk learned from Oct 2022–Oct 2026 dual-ID files; extend reader for all 4 eras; add "Sudah diputuskan" filter on Names tab. | Requires ~8–15 separate four-eyes runs (1–2 years each), which keeps every review fast, small, and independently reversible. | **Picked (Approved by user 2026-11-27)** |
| **2. May 2015 – Oct 2026 Only (138 files)** | Same crosswalk & progressive cutoff, but skip Jan 2012 – Apr 2015 because those 40 files lack `TGL. DALAM JABATAN`. | Drops 3.3 years of career history even though the app already supports `tmt_awal_diperkirakan` for missing start dates. | Rejected |
| **3. Oct 2022 – Oct 2026 Only (49 files)** | Only support files that already carry 8-digit `NIK` / `NIK SAP`. | Drops 10+ years (2012–Sep 2022) of history for 269 active Karpim employees. | Rejected |

## 5. Profiling Facts (178 Real Files in `Bezeting 2012 - 2026/`)

1. **Era 1 — Feb 2023 to Oct 2026 (45 files):**
   - Header `NIK` (8 digits) + unlabelled adjacent column holding legacy PTPN XIII ID (`999.9999.9999.9999`), plus `TGL. DALAM JABATAN` and `TMT DI BAGIAN/ UNIT KERJA`.
   - `Bez September 2024.xls` has filename `Bez September 2024.xls` and sheet name `Bez September 2024`, but cell `A4` says `PER OKTOBER 2024` (different SHA-256 and size from `Bez Oktober 2024.xls`).
2. **Era 2 — Oct 2022 to Jan 2023 (4 files):**
   - Dual ID columns explicitly labelled `NIK SAP` (8-digit NIK) and `NIK PTPN XIII` (`999.9999.9999.9999`), plus `TGL. DALAM JABATAN`.
3. **Era 3 — May 2015 to Sep 2022 (89 files):**
   - Header `NIK` contains the legacy PTPN XIII ID (`999.9999.9999.9999`, 14 digits with dots, or 14 bare digits), plus `TGL. DALAM JABATAN` and `TMT DI BAGIAN/ UNIT KERJA`. No 8-digit NIK exists.
4. **Era 4 — Jan 2012 to Apr 2015 (40 files):**
   - **Feb–Apr 2015 (3 files):** Header `NIK` (`999.9999.9999.9999`), `TMT DI BAGIAN/ KEBUN/ UNIT`, **no `TGL. DALAM JABATAN`**.
   - **Jan 2012–Jan 2015 (37 files):** 2–3 row merged header: `No.`, `Nomor Induk` / `Nomor Induk Karyawan` (`999.9999.9999.9999`), `N a m a`, `Gol`, `Jabatan`, `Masa Kerja / Di Unit Kerja / TMT`. **No `TGL. DALAM JABATAN`**. In 3 files (May, Jul, Nov 2012), Col 2 has sub-label `N I K` and Col 3 has `Nomor Induk / Karyawan`. **Section headings (unit names)** appear in the `Nomor Induk` column (Col 2) when `Nama`, `Jabatan`, and `Gol` are blank, whereas in Feb 2015–2026 they appear in the `Nama` column (Col 2).
5. **Crosswalk Coverage:** Sampling 6 post-Oct 2022 files yielded **392 unique `(legacy_id -> 8-digit NIK)` pairs** covering **269 / 345 active employees** with **0 conflicts**.

## 6. Detailed Design

### 6.1 Progressive Multi-Batch Cutoff (`BackfillPlanner`)
- **Remove the blanket `bezetting` exclusion:** Currently `BackfillPlanner::plan()` excludes any employee whose `$own` histories contain `sumber = bezetting` (`EXCLUDED_ALREADY_DONE`).
- **New rule:**
  - `$cutoff` remains `min(tmt_awal)` across **all** applied `job_histories` for that employee (both non-Bezetting and previously committed `sumber = bezetting` rows).
  - An employee is excluded as `EXCLUDED_ALREADY_DONE` (`sudah_direkonstruksi`) **only when**:
    1. They already have at least one `sumber = bezetting` row, **AND**
    2. The earliest period in the current uploaded batch (`$periods[0] . '-01'`) is **greater than or equal to** `$cutoff` (meaning the batch has no months earlier than their already-reconstructed timeline), **OR** all computed segments for this employee fall on or after `$cutoff` and produce 0 writable rows while overlapping an already-reconstructed period.
  - Otherwise, the employee is processed normally: segments starting strictly before `$cutoff` are written and the last one closes on `$cutoff - 1 day`, never touching rows written by newer runs.
- **Follow-up suppression for older batches:**
  - When a batch's latest period (`end($periods)`) is earlier than an employee's existing `bezetting` history (i.e. `$own` already has a `sumber = bezetting` row starting on or before `monthEnd(end($periods)) + 1 day`), do **not** emit a false `perpindahan_belum_tercatat` ("Perubahan perlu diperiksa") for segments that fall inside an already-reconstructed `bezetting` period (`recorded()` already checks `$own` including `bezetting` rows, and `keluar_daftar` only fires if they disappear *before* the end of the current batch).
- **Idempotence & LIFO Rollback (Builder-check call):**
  - Because older runs only insert new rows with `tmt_akhir < $cutoff` and never update previously committed rows, each run's rows are immutable.
  - Because an older run's closing dates (`tmt_akhir`) anchor to `$cutoff - 1 day` (the start of a newer committed run), `BezettingBackfillService::rollback()` enforces **LIFO rollback order** for overlapping/older runs: if another `STATUS_COMPLETED` run exists with `period_from < $locked->period_from` that shares at least one employee with `$locked` (or simply any `STATUS_COMPLETED` run with `period_from < $locked->period_from`), rollback is refused with a clear message instructing HR to cancel the older run first. This guarantees `$cutoff = min(tmt_awal)` never leaves an un-refillable mid-timeline hole.

### 6.2 Privacy-Safe Legacy NIK Crosswalk (`bezetting_legacy_nik_maps`)
- **Table `bezetting_legacy_nik_maps` (new migration):**
  - `id` (bigIncrements)
  - `legacy_nik_hash` (`string(64)`, unique) — `hash_hmac('sha256', $canonical14Digits, (string) config('app.key'))` where `$canonical14Digits` is the 14-digit legacy PTPN XIII number with dots/spaces stripped (`preg_replace('/\D+/', '', $raw)`). Storing only the keyed hash ensures the legacy PTPN XIII number is never stored in plaintext anywhere (`SECURITY.md` §4.4).
  - `employee_nik` (`string(8)`, indexed, FK $\rightarrow$ `employees.nik` `cascadeOnDelete`)
  - `source_run_id` (nullable FK $\rightarrow$ `bezetting_backfill_runs.id` `nullOnDelete`)
  - `timestamps`
- **Model `App\Models\BezettingLegacyNikMap`:** explicit `$fillable`, helper `canonicalize(string $raw): ?string` (returns 14 digits if input matches `^\d{3}\.\d{4}\.\d{4}\.\d{4}$` or `^\d{14}$`, else `null`) and `hash(string $canonical): string`.
- **How the crosswalk is populated (automatic, zero extra admin work):**
  - Whenever `BezettingWorkbookReader` reads a row that has **both** an 8-digit `nik` and a legacy 14-digit PTPN XIII ID (in `NIK PTPN XIII` or the unlabelled column between `NIK` and `JABATAN` in Oct 2022–Oct 2026 files), the reader includes `'legacy_nik_hash' => BezettingLegacyNikMap::hash($canonical)` (never the plaintext legacy ID) in the minimized staged row.
  - During `BezettingBackfillService::approve()` (inside the commit `DB::transaction()`), for every staged row with a valid 8-digit `nik` that exists in `employees` and a non-null `legacy_nik_hash`:
    - **Eligibility filter (Builder-check call):** Skip any employee excluded for `EXCLUDED_IDENTITY`, `EXCLUDED_DUPLICATE`, or `EXCLUDED_NIK_FORMAT` so suspect/ambiguous rows never pollute the crosswalk. **Still learn** from identity-valid employees who exist in `employees` even if they had 0 writable rows in that run (e.g., their existing app history already covers 2025–2026, so they still need their legacy ID linked for 2012–2024). Apply the same eligibility filter to in-run dual-ID pairs in `BackfillPlanner`.
    - If `legacy_nik_hash` is not yet in `bezetting_legacy_nik_maps`, insert it with `source_run_id = $locked->id`.
    - If it already exists pointing to the same `employee_nik`, keep it.
    - If it conflicts with a different `employee_nik` (never observed in the 178 real files, 0 conflicts), skip inserting and log a warning (never overwrite silently).
  - **Rollback cleanup (Builder-check call):** Inside `BezettingBackfillService::rollback()`, delete `bezetting_legacy_nik_maps` rows where `source_run_id = $locked->id` in the same transaction, so a mistaken dual-ID run never poisons the crosswalk and re-approving the fixed run cleanly re-populates it. (Already-committed older runs are unaffected because their `job_histories` rows already store the 8-digit `employee_nik`.)
  - Also, **within the same run**, if a run spans Oct 2022 (e.g., `2022-01..2022-12`), `BackfillPlanner` combines persisted `bezetting_legacy_nik_maps` with in-run dual-ID pairs from Oct–Dec 2022 (only for identity-valid employees existing in `employees` and without conflicts) so a full-year `2022` upload resolves Jan–Sep 2022 automatically even on the first try.
- **How pre-Oct 2022 rows are resolved:**
  - When `BezettingWorkbookReader` reads a row whose ID column holds a 14-digit legacy PTPN XIII ID (with or without dots), it stores `'nik' => ''` (or a synthetic sentinel? No — cleaner: `'nik' => ''`, `'legacy_nik_hash' => $hash`, `'is_legacy_nik' => true`) so plaintext legacy IDs never even enter the encrypted staging payload.
  - In `BackfillPlanner::plan()`, before Step 1 (exclusion checks), any row with `is_legacy_nik = true` looks up `legacy_nik_hash` in the crosswalk (`$crosswalk[$hash] ?? null`):
    - If found: sets `$row['nik'] = $resolvedNik8` and proceeds through normal identity checks (including birth date & name consistency against `employees`!).
    - If not found: excludes the person as `EXCLUDED_LEGACY_UNMAPPED` (`nik_lama_belum_terhubung`, label: `"Nomor induk lama belum terhubung ke NIK 8 digit"`). Meaning: employee only has a legacy PTPN XIII number and was not seen in any 2022–2026 dual-ID file (typically retired before Oct 2022, or 2023–2026 has not been approved yet). Does not block the run.

### 6.3 Multi-Era `BezettingWorkbookReader`
1. **Header Detection (`findHeaderRow` & `mapColumns`):**
   - Detect header row when any cell in rows 1–15, cols 1–15 matches `NIK`, `NIK SAP`, `NOMOR INDUK`, or `NOMOR INDUK KARYAWAN`.
   - Map columns by scanning the header row **plus up to 2 sub-header rows** (`$headerRow`, `$headerRow + 1`, `$headerRow + 2`, stopping before the column-number row `1, 2, 3...`):
     - `nik`: exact `NIK SAP` (highest priority if present), then `NIK`, then `NOMOR INDUK*`.
     - `legacy_nik`: exact `NIK PTPN XIII` if present; otherwise, in files with an 8-digit `nik` column, scan the unlabelled column(s) immediately adjacent to `nik` (between `nik` and `jabatan`) when reading rows for `^\d{3}\.\d{4}\.\d{4}\.\d{4}$`.
     - `nama`: `N A M A` or `NAMA` (normalizing spaces inside single-letter spaced headers like `N A M A` $\rightarrow$ `NAMA`).
     - `jabatan`: `JABATAN`.
     - `unit`: `UNIT KERJA`.
     - `golongan`: `GOL*`.
     - `tanggal_dalam_jabatan`: `TGL. DALAM JABATAN*` (**now optional** — no longer in `REQUIRED`! When absent, `tanggal_dalam_jabatan` is `null` and a reader warning is added: `"Berkas tidak memuat kolom Tgl. Dalam Jabatan; tanggal mulai perubahan jabatan akan ditandai perkiraan."`).
     - `tmt_unit_kerja`: `TMT DI BAGIAN*` OR a multi-row header containing `DI UNIT KERJA` with sub-header `TMT` directly underneath it.
     - `tanggal_lahir`: `TGL. LAHIR`.
     - `tanggal_pensiun`: `TGL PENSIUN` or `PENSIUN` under `TANGGAL`.
2. **Section Heading Detection Across All Eras:**
   - In Feb 2015–2026 (`Nama` in Col 2, `NIK` in Col 3), section headings sit in the `nama` column with empty `nik`, `jabatan`, `golongan`.
   - In Jan 2012–Jan 2015 (`Nomor Induk` in Col 2, `Nama` in Col 3 or 4), section headings sit in the `nik` column (`Nomor Induk`) with empty `nama`, `jabatan`, `golongan` (and are non-numeric text, e.g., `"BAGIAN TANAMAN"`).
   - Rule: if a row has empty `jabatan` and empty `golongan` and no valid numeric/legacy ID, and either `nama` or `nik` holds non-numeric heading text (excluding pure Roman numerals like `"I."` alone when paired with text in the next column, and excluding column-number rows), update `$heading` to that heading text.
   - Skip the 1–3 sub-header rows between `$headerRow` and the first data/heading row (rows containing only header keywords or sequential column numbers `1, 2, 3...`).
3. **Period Discrepancy Handling (`Bez September 2024.xls`):**
   - Extract period from: (a) title row (`PER <BULAN> <TAHUN>`), (b) filename (`Bez September 2024.xls`, also accepting common typo `FEBUARI` $\rightarrow$ `FEBRUARI`), and (c) first sheet name (`Bez September 2024`).
   - If title period differs from filename period **AND** the first sheet name's period matches the filename period (as in `Bez September 2024.xls`), accept the filename/sheet period (`2024-09`) and attach a clear warning: `"Judul berkas menyebut periode {titlePeriod}, tetapi nama berkas dan nama lembar menyebut {namePeriod}; periode {namePeriod} yang digunakan."`
   - If all three disagree (or sheet name does not corroborate filename), keep throwing `BezettingReadException` as before.

### 6.4 Revisable Name Dictionary UI (`BezettingBackfillDetail`)
- Keep global persistence in `bezetting_name_mappings` unchanged.
- In `BackfillPlanner::plan()`, also return `'decided'` — the list of distinct saved `BezettingNameMapping` entries actively applied to the current run's included rows (each with `kind`, `text`, `count`, `decision`, `target_value`).
- On the **Nama** tab of `BezettingBackfillDetail`:
  - Add a **Status** filter next to the existing **Jenis** filter:
    - `Belum diputuskan (N)` (default) — shows `$plan['blocking']` exactly as today.
    - `Sudah diputuskan (M)` — shows `$plan['decided']` (the saved decisions applied to this run's files), displaying the current decision badge (`Dipetakan ke …` / `Apa Adanya` / `Dilewati`) and, while the run is editable (`staged` / `rejected`), allowing the maker to change any decision via the existing `Petakan` / `Apa Adanya` / `Lewati` actions (`BezettingBackfillService::decide()`, which already audits `$old` $\rightarrow$ `$new` on `master_audit_logs`).
  - When the run is `pending`, the second admin (Approver) can switch to `Sudah diputuskan` in read-only mode to verify how legacy unit/position names were resolved before approving.
- Update `BezettingReviewGuide::upload()` to explain the newest-to-oldest order explicitly for 2012–2026: upload 2023–2026 first (so the system learns the 8-digit NIK link for 2012–2022), then work backward year-by-year down to 2012.

### 6.5 SSOT Updates (Phase 1)
- `DOMAIN.md` §5.5: Expand scope from `2025–2026` to `2012–2026`; document progressive multi-batch `$cutoff` (removing blanket `bezetting` exclusion), the hashed legacy PTPN XIII crosswalk (`nik_lama_belum_terhubung` exclusion when unmapped), optional `TGL. DALAM JABATAN` in pre-May 2015 files, and revisable saved name decisions.
- `DESIGN.md` §8.6a: Document the `Status` filter (`Belum diputuskan` / `Sudah diputuskan`) on the **Nama** tab (editable for maker while staged/rejected, read-only for approver).
- `SECURITY.md` §4.4 & §5.1c: Document `bezetting_legacy_nik_maps` (stores only keyed HMAC-SHA256 hash of legacy PTPN XIII ID + `employee_nik`, never plaintext legacy IDs; `cascadeOnDelete` with `employees`).

### 6.6 Testing Strategy (One Test Class per SUT)
- `BezettingWorkbookReaderTest`: Era 1–4 fixtures (including `NIK SAP`/`NIK PTPN XIII`, 3-row 2012–Jan 2015 headers with Col-2 section headings, missing `TGL. DALAM JABATAN`, `FEBUARI` filename typo, `Bez September 2024` title-vs-sheet/filename warning, and confirming plaintext legacy IDs are never returned in staged rows).
- `BackfillPlannerTest`: Progressive `$cutoff` across consecutive backward runs (`2026` committed, then `2025`, then `2022` with legacy crosswalk), `EXCLUDED_ALREADY_DONE` only when batch is not older than `$cutoff`, `EXCLUDED_LEGACY_UNMAPPED`, conflict rejection, and `decided` list output.
- `BezettingBackfillServiceTest`: Crosswalk persistence on `approve()`, independent rollback of older vs. newer runs, revising a previously decided mapping and verifying `master_audit_logs` records `$old` $\rightarrow$ `$new`.
- `BezettingBackfillDetailTest`: Filtering `Belum diputuskan` vs. `Sudah diputuskan`, revising a saved decision while editable, read-only view for approver on `pending`.
- Smoke-test against the real 178 files in `Bezeting 2012 - 2026/` via a read-only artisan/script verification (counts only, zero personal data output) confirming 178/178 files parse cleanly.

## 7. What's Left to the Builder
- Exact Blade markup/spacing for the `Status` filter dropdown alongside `Jenis` on the Names tab (must follow `DESIGN.md` §7.5 & §8.6a).
- Exact helper method names inside `BezettingWorkbookReader` for multi-row header scanning and legacy ID detection.
- Migration filename and index naming conventions.
