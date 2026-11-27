# Multi-Year Career History Backfill (2012–2026) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the Bezetting Career History Backfill to reconstruct Karpim career timelines across all 178 monthly Bezetting workbooks from January 2012 to October 2026 via rolling backward batches, a privacy-safe hashed legacy PTPN XIII NIK crosswalk, a multi-era workbook reader, and a revisable name dictionary UI.

**Architecture:** Keep `max_files = 24` so HR uploads 1–2 years per run from newest (`2026`) back to oldest (`2012`). Dual-ID files (Oct 2022–Oct 2026) populate a keyed HMAC-SHA256 crosswalk (`bezetting_legacy_nik_maps`) on four-eyes `approve()` so pre-Oct 2022 legacy PTPN XIII IDs (`999.9999.9999.9999`) resolve deterministically to 8-digit `employees.nik` without storing raw legacy IDs anywhere. `BackfillPlanner` uses progressive `$cutoff = min(tmt_awal)` across all applied rows (including prior `bezetting` runs), `BezettingBackfillService::rollback()` enforces LIFO rollback order and cleans up crosswalk rows from that run, and `BezettingBackfillDetail` exposes a `Belum diputuskan` / `Sudah diputuskan` filter on the Names tab.

**Tech Stack:** Laravel 11, Livewire 3, PhpSpreadsheet, PHPUnit 11, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-11-27-bezetting-2012-2026-backfill-design.md`

## Global Constraints

- Follow `AGENTS.md` 4-pillar SSOT: update `DOMAIN.md` §5.5, `DESIGN.md` §8.6a, and `SECURITY.md` §4.4 & §5.1c first (Rule 1).
- One test class per SUT (`AGENTS.md` Rule 5); all new tests added to each SUT's existing test class (only the new `BezettingLegacyNikMap` model gets its own test class).
- Clean Enterprise UI (`AGENTS.md` Rules 6–7): never expose internal filenames (`DOMAIN.md`, `SECURITY.md`, etc.) or `SSOT` in Blade views, guides, or exceptions; use official Indonesian HR terminology ("Karyawan", "Karyawan Pimpinan", "Nomor Induk Karyawan").
- Multi-table database writes wrapped in `DB::transaction()`.
- Privacy & UU PDP (`SECURITY.md` §4.4): never store raw legacy PTPN XIII numbers (`999.9999.9999.9999`) in `employees`, `job_histories`, `bezetting_legacy_nik_maps`, staged payloads, audit logs, or UI.
- Run `./vendor/bin/pint` and full relevant PHPUnit suite before claiming completion.

## Review Focus

1. **Duplicate/conflicting legacy IDs in the same dual-ID file:** If two different 8-digit NIKs claim the same legacy PTPN XIII ID (or an employee is excluded for `EXCLUDED_IDENTITY`/`EXCLUDED_DUPLICATE`), neither the in-run crosswalk nor `approve()` may persist or use that legacy ID. (Pinned in Task 3 & Task 4.)
2. **2012–Jan 2015 section headings in Col 2 (`Nomor Induk`) vs. blank-NIK person rows:** A row with text in `Nomor Induk`, empty `Nama`/`Jabatan`/`Gol` is a unit heading; a row with `Jabatan` or `Gol` and empty `Nomor Induk` must still be reported as `nik_kosong`, never as a unit heading. (Pinned in Task 2.)
3. **Period mismatch in `Bez September 2024.xls` vs. truly mismatched files:** When filename and first sheet name agree on `2024-09` while the title cell says `PER OKTOBER 2024`, accept `2024-09` with a warning; when filename and sheet name do *not* agree and title disagrees, still throw `BezettingReadException`. (Pinned in Task 2.)
4. **LIFO rollback guard when older runs exist:** Rolling back a newer committed run (e.g., `2026-01..2026-10`) must be refused while an older committed run (`2025-01..2025-12`) is still `completed`, and allowed once the older run is rolled back. (Pinned in Task 4.)
5. **Revising a previously decided name on `Sudah diputuskan` while `pending`:** Approver on a `pending` run can view `Sudah diputuskan` read-only, and cannot invoke `map`/`keep`/`skip` to mutate a submitted run's plan. (Pinned in Task 5.)

---

### Task 1: SSOT Updates & `BezettingLegacyNikMap` Model + Migration

**Files:**
- Modify: `DOMAIN.md:430-441`
- Modify: `DESIGN.md:639-644`
- Modify: `SECURITY.md:96,109`
- Create: `database/migrations/2026_11_27_100000_create_bezetting_legacy_nik_maps_table.php`
- Create: `app/Models/BezettingLegacyNikMap.php`
- Create: `tests/Feature/BezettingLegacyNikMapTest.php`

**Interfaces:**
- Produces:
  - Table `bezetting_legacy_nik_maps` (`id`, `legacy_nik_hash` string(64) unique, `employee_nik` string(8) FK $\rightarrow$ `employees.nik` cascadeOnDelete, `source_run_id` nullable FK $\rightarrow$ `bezetting_backfill_runs.id` nullOnDelete, `timestamps`).
  - `BezettingLegacyNikMap::canonicalize(string $raw): ?string` — accepts `999.9999.9999.9999` (with dots/spaces) or 14 bare digits, returns 14-digit string or `null`.
  - `BezettingLegacyNikMap::hash(string $canonical14Digits): string` — returns `hash_hmac('sha256', $canonical14Digits, (string) config('app.key'))`.
  - `BezettingLegacyNikMap::hashFromRaw(string $raw): ?string` — convenience wrapper returning `null` if `canonicalize()` is `null`.

- [ ] **Step 1: Update SSOT documents (`DOMAIN.md` §5.5, `DESIGN.md` §8.6a, `SECURITY.md` §4.4 & §5.1c)**
  - `DOMAIN.md` §5.5: Expand scope from `Januari 2025 s.d. Oktober 2026` to `Januari 2012 s.d. Oktober 2026`; document progressive backward multi-batch `$cutoff`, LIFO rollback order, hashed legacy PTPN XIII crosswalk (`nik_lama_belum_terhubung` when unmapped), optional `TGL. DALAM JABATAN` in pre-May 2015 files, and revisable saved name decisions.
  - `DESIGN.md` §8.6a: Document the `Status` filter (`Belum diputuskan` / `Sudah diputuskan`) on the **Nama** tab (editable for maker while staged/rejected; read-only for approver on pending).
  - `SECURITY.md` §4.4 & §5.1c: Document `bezetting_legacy_nik_maps` storing only keyed HMAC-SHA256 hash of legacy PTPN XIII ID + `employee_nik`, never plaintext legacy IDs, with `cascadeOnDelete` on `employees` and cleanup on rollback of `source_run_id`.

- [ ] **Step 2: Write the failing test in `tests/Feature/BezettingLegacyNikMapTest.php`**

```php
public function test_canonicalize_and_hash_legacy_ptpn13_numbers(): void
{
    $this->assertSame('00007160123456', BezettingLegacyNikMap::canonicalize('000.0716.0123.456')); // wait: 3+4+4+4 = 15 digits?
}
```
  *(Note: verify exact digit count from real files in `Bezeting 2012 - 2026`: `999.9999.9999.9999` has `3+4+4+4 = 15` digits, while 2021–2022 bare numbers had 14 digits without leading zero or 15 digits! Check `canonicalize` against `^\d{3}\.\d{4}\.\d{4}\.\d{4}$` (15 digits) and `^\d{14,15}$` — pad 14 digits? No, never pad silently; check actual digit length of `000.0716.xxxx.xxxx` in Task 1 step 2 before locking the regex!)*
  Assertions to include:
  - `canonicalize('000.0716.1234.5678')` returns `'000071612345678'` (15 digits) and `canonicalize('000 0716 1234 5678')` returns `'000071612345678'`.
  - `canonicalize('12345678')` (8-digit NIK) returns `null`; `canonicalize('abc')` returns `null`.
  - `hash()` returns 64-char hex HMAC-SHA256 deterministic for same input and never contains the raw digits.
  - `BezettingLegacyNikMap::create([...])` enforces unique `legacy_nik_hash` and cascades on `Employee::delete()`.

- [ ] **Step 3: Run test to verify it fails**
  Run: `php artisan test tests/Feature/BezettingLegacyNikMapTest.php`
  Expected: FAIL (`Class "App\Models\BezettingLegacyNikMap" not found`).

- [ ] **Step 4: Create migration `2026_11_27_100000_create_bezetting_legacy_nik_maps_table.php` and `app/Models/BezettingLegacyNikMap.php`**
  - Verify the exact digit length of legacy IDs in 2012–2026 (14 vs 15 digits) via a 1-second check on `Bez Oktober 2022.xls` and `Bez Januari 2022.xls`, then lock `canonicalize()` to match all observed legacy PTPN XIII formats (stripped of dots/spaces) without colliding with 7- or 8-digit NIKs.
  - Run `php artisan migrate` (or in test via `RefreshDatabase`).

- [ ] **Step 5: Run test to verify it passes**
  Run: `php artisan test tests/Feature/BezettingLegacyNikMapTest.php`
  Expected: PASS.

- [ ] **Step 6: Commit**
  `git add DOMAIN.md DESIGN.md SECURITY.md database/migrations/2026_11_27_100000_create_bezetting_legacy_nik_maps_table.php app/Models/BezettingLegacyNikMap.php tests/Feature/BezettingLegacyNikMapTest.php && git commit -m "feat(bezetting): update SSOT for 2012-2026 backfill and add hashed legacy NIK crosswalk"`

---

### Task 2: Multi-Era `BezettingWorkbookReader` (Eras 1–4)

**Files:**
- Modify: `app/Services/Bezetting/BezettingWorkbookReader.php`
- Test: `tests/Unit/BezettingWorkbookReaderTest.php`

**Interfaces:**
- Consumes: `BezettingLegacyNikMap::hashFromRaw(string $raw): ?string`
- Produces:
  - `BezettingWorkbookReader::read(string $path, string $originalName): array` — each row in `rows` now includes:
    - `'nik'` (string: 8-digit/7-digit NIK, or `''` when row only has a legacy PTPN XIII ID)
    - `'legacy_nik_hash'` (`?string`: 64-char HMAC hash when a legacy PTPN XIII ID is present, either alongside an 8-digit NIK or as the sole ID; `null` otherwise)
    - `'is_legacy_nik'` (`bool`: `true` when the row has only a legacy PTPN XIII ID and no 8-digit NIK)
    - `'tanggal_dalam_jabatan'` (`?string`: `null` when the file has no `TGL. DALAM JABATAN` column)
  - `BezettingWorkbookReader::periodFromText(string $text): ?string` — also recognizes typo `FEBUARI` as month 2.

- [ ] **Step 1: Write failing tests in `tests/Unit/BezettingWorkbookReaderTest.php`**
  Add tests for:
  - `test_reads_era_2_nik_sap_and_nik_ptpn13_dual_columns`: header `NIK SAP` + `NIK PTPN XIII` populates `nik` (8 digits), `legacy_nik_hash` (64-char hash, never raw legacy ID), `is_legacy_nik = false`.
  - `test_reads_era_1_unlabelled_adjacent_legacy_column`: header `NIK` + unlabelled Col 4 containing `000.0716.1234.5678` populates both `nik` and `legacy_nik_hash`.
  - `test_reads_era_3_legacy_only_nik_column`: header `NIK` containing `000.0716.1234.5678` produces `nik = ''`, `is_legacy_nik = true`, `legacy_nik_hash` set (no `nik_tidak_valid` issue).
  - `test_reads_era_4_2012_three_row_header_col2_unit_headings_and_missing_tgl_jabatan`: 3-row header (`Nomor Induk` / `Karyawan`, `N a m a`, `Gol`, `Jabatan`, `Masa Kerja / Di Unit Kerja / TMT`), unit heading in Col 2 (`Nomor Induk`), missing `TGL. DALAM JABATAN` emits warning and sets `tanggal_dalam_jabatan = null`; person row with `Jabatan` and empty `Nomor Induk` still emits `nik_kosong`.
  - `test_resolves_period_discrepancy_when_filename_and_sheet_name_agree_and_rejects_otherwise`: `Bez September 2024.xls` with sheet `Bez September 2024` and title `PER OKTOBER 2024` returns `period = '2024-09'` with warning; same title mismatch with sheet `Sheet1` still throws `BezettingReadException`. Also checks `Bez Febuari 2024.xls` parses `2024-02`.

- [ ] **Step 2: Run test to verify it fails**
  Run: `php artisan test tests/Unit/BezettingWorkbookReaderTest.php`
  Expected: FAIL on the 5 new tests.

- [ ] **Step 3: Implement multi-era support in `app/Services/Bezetting/BezettingWorkbookReader.php`**
  - Update `MONTHS` / `periodFromText()` to accept `FEBUARI` alias for `FEBRUARI`.
  - Update `findHeaderRow()` to match `NIK`, `NIK SAP`, `NOMOR INDUK`, `NOMOR INDUK KARYAWAN`.
  - Update `mapColumns()` to scan `$headerRow` plus up to 2 sub-header rows (combining vertical labels for `NOMOR INDUK / KARYAWAN`, `MASA KERJA / DI UNIT KERJA / TMT`, `TANGGAL / PENSIUN`), prioritizing `NIK SAP` over `NIK` / `NOMOR INDUK*`, capturing `legacy_nik` (`NIK PTPN XIII`), and removing `tanggal_dalam_jabatan` from `REQUIRED`.
  - Update `read()` period check: extract `$sheetPeriod = self::periodFromText($sheet->getTitle())`; if `$titlePeriod !== null && $namePeriod !== null && $titlePeriod !== $namePeriod`, accept `$namePeriod` with warning when `$sheetPeriod === $namePeriod`, else throw `BezettingReadException`. Emit warning when `tanggal_dalam_jabatan` column is absent.
  - Update `readRows()` to skip sub-header rows above column-number row, detect section headings in either `nama` or `nik` column when `jabatan` and `golongan` are empty and no numeric/legacy ID is present, hash any legacy ID via `BezettingLegacyNikMap::hashFromRaw()` (checking `legacy_nik` column or unlabelled column between `nik` and `jabatan`), and handle legacy-only rows (`is_legacy_nik = true`, `nik = ''`, `legacy_nik_hash = $hash`).

- [ ] **Step 4: Run test to verify it passes**
  Run: `php artisan test tests/Unit/BezettingWorkbookReaderTest.php`
  Expected: PASS (all existing + 5 new tests).

- [ ] **Step 5: Commit**
  `git add app/Services/Bezetting/BezettingWorkbookReader.php tests/Unit/BezettingWorkbookReaderTest.php && git commit -m "feat(bezetting): support 2012-2026 multi-era layouts and hashed legacy IDs in reader"`

---

### Task 3: Progressive Multi-Batch Cutoff, Crosswalk Resolution & `decided` List in `BackfillPlanner`

**Files:**
- Modify: `app/Services/Bezetting/BackfillPlanner.php`
- Test: `tests/Feature/BackfillPlannerTest.php`

**Interfaces:**
- Consumes: `BezettingLegacyNikMap`, `BezettingWorkbookReader` row fields (`nik`, `legacy_nik_hash`, `is_legacy_nik`).
- Produces:
  - `BackfillPlanner::EXCLUDED_LEGACY_UNMAPPED = 'nik_lama_belum_terhubung'` (added to `EXCLUSION_LABELS` and `EXCLUSION_HELP`).
  - `BackfillPlanner::plan(array $snapshots): array` — return array gains `'decided' => list<array{kind: string, text: string, count: int, decision: string, target: ?string}>` (distinct saved `BezettingNameMapping` entries actively applied to included rows in this run, sorted by `kind`, `text`).
  - `BackfillPlanner::identitySafeLegacyPairs(array $snapshots, array $excluded): array` (or public static/instance helper) — returns `array<string, string>` (`legacy_nik_hash => employee_nik`) for employees existing in `employees` who are NOT excluded for `EXCLUDED_IDENTITY`, `EXCLUDED_DUPLICATE`, or `EXCLUDED_NIK_FORMAT`, dropping any `legacy_nik_hash` that maps to > 1 distinct 8-digit NIK.

- [ ] **Step 1: Write failing tests in `tests/Feature/BackfillPlannerTest.php`**
  Add tests for:
  - `test_progressive_cutoff_fills_older_year_before_existing_bezetting_row`: employee has a `data_awal` row from `2026-07-01` and a committed `sumber = bezetting` row `2026-01-01..2026-06-30`; planning a `2025-01..2025-12` batch writes rows ending `2025-12-31` instead of excluding as `sudah_direkonstruksi`.
  - `test_excludes_already_done_only_when_batch_has_no_period_before_earliest_bezetting_row`: employee with committed `sumber = bezetting` row starting `2025-01-01` is excluded as `EXCLUDED_ALREADY_DONE` when planning `2025-01..2025-12` or `2026-01..2026-10`.
  - `test_resolves_legacy_only_rows_via_persisted_and_in_run_crosswalk_and_excludes_unmapped_or_conflicting`: verifies persisted `BezettingLegacyNikMap`, in-run Oct 2022 dual-ID bridge, `EXCLUDED_LEGACY_UNMAPPED` for unknown legacy hash, and conflict rejection when two 8-digit NIKs share one legacy hash.
  - `test_returns_decided_mappings_actively_applied_to_run`: verifies `$plan['decided']` lists saved `map`, `keep`, `skip` decisions used by included rows with accurate occurrence counts.

- [ ] **Step 2: Run test to verify it fails**
  Run: `php artisan test tests/Feature/BackfillPlannerTest.php`
  Expected: FAIL on the 4 new tests (and check if any existing test asserted the old blanket `EXCLUDED_ALREADY_DONE` behavior on an older batch — update only if it contradicts the approved spec).

- [ ] **Step 3: Implement progressive cutoff, crosswalk resolution, and `decided` tracking in `app/Services/Bezetting/BackfillPlanner.php`**
  - Add `EXCLUDED_LEGACY_UNMAPPED` constant + `EXCLUSION_LABELS` + `EXCLUSION_HELP` entries (clean business Indonesian, no internal doc names).
  - Resolve `is_legacy_nik` rows before Step 1 using persisted `BezettingLegacyNikMap` + conflict-free in-run dual-ID pairs (ignoring rows with invalid NIK format or duplicate NIK in a file). Unresolved legacy rows are keyed deterministically (e.g., `'L:' . substr($hash, 0, 8)`) in `$excluded` with `EXCLUDED_LEGACY_UNMAPPED` (never exposing raw legacy IDs).
  - Replace blanket `$own->contains('sumber', JobHistory::SUMBER_BEZETTING) => self::EXCLUDED_ALREADY_DONE`: compute `$earliestOwn = $own->min(fn ($h) => $h->tmt_awal->toDateString())`; exclude as `EXCLUDED_ALREADY_DONE` only when `$own->contains('sumber', JobHistory::SUMBER_BEZETTING)` AND `$periods[0].'-01' >= $earliestOwn`.
  - Track `$this->decided[$kind.'|'.$normalized]` whenever a saved `BezettingNameMapping` decision is applied in `resolve()`, and return sorted `'decided'` in `plan()`.

- [ ] **Step 4: Run test to verify it passes**
  Run: `php artisan test tests/Feature/BackfillPlannerTest.php tests/Feature/BezettingReviewGuideTest.php`
  Expected: PASS.

- [ ] **Step 5: Commit**
  `git add app/Services/Bezetting/BackfillPlanner.php tests/Feature/BackfillPlannerTest.php && git commit -m "feat(bezetting): support progressive multi-batch cutoff, legacy crosswalk resolution, and decided list"`

---

### Task 4: Crosswalk Persistence on `approve()` & LIFO Rollback Guard in `BezettingBackfillService`

**Files:**
- Modify: `app/Services/Bezetting/BezettingBackfillService.php`
- Test: `tests/Feature/BezettingBackfillServiceTest.php`

**Interfaces:**
- Consumes: `BezettingLegacyNikMap`, `BackfillPlanner::identitySafeLegacyPairs()`.
- Produces:
  - `BezettingBackfillService::approve()` persists non-conflicting `bezetting_legacy_nik_maps` rows (`source_run_id = $locked->id`) for identity-valid active employees (even if they had 0 writable rows in this run) inside the commit `DB::transaction()`.
  - `BezettingBackfillService::rollback()` refuses rollback with `ImportAbortedException` if any `STATUS_COMPLETED` run exists with `period_from < $locked->period_from`, and deletes `bezetting_legacy_nik_maps` rows where `source_run_id = $locked->id` inside the rollback `DB::transaction()`.

- [ ] **Step 1: Write failing tests in `tests/Feature/BezettingBackfillServiceTest.php`**
  Add tests for:
  - `test_approve_persists_legacy_crosswalk_for_identity_valid_employees_and_skips_identity_excluded`: approves a dual-ID run containing (a) a normal employee with writable rows, (b) an identity-valid employee with 0 writable rows (`tmt_awal >= cutoff`), and (c) an `EXCLUDED_IDENTITY` employee; asserts (a) and (b) are stored in `bezetting_legacy_nik_maps` with `source_run_id`, and (c) is NOT stored.
  - `test_rollback_cleans_up_legacy_crosswalk_rows_from_source_run`: rolls back the dual-ID run and asserts its `bezetting_legacy_nik_maps` rows are deleted.
  - `test_rollback_enforces_lifo_order_when_older_completed_run_exists`: commits `2026-01..2026-10`, then commits `2025-01..2025-12`; attempting to roll back `2026` throws `ImportAbortedException`; rolling back `2025` succeeds, and then rolling back `2026` succeeds.

- [ ] **Step 2: Run test to verify it fails**
  Run: `php artisan test tests/Feature/BezettingBackfillServiceTest.php`
  Expected: FAIL on the 3 new tests.

- [ ] **Step 3: Implement crosswalk persistence & LIFO rollback in `app/Services/Bezetting/BezettingBackfillService.php`**
  - In `approve()` transaction: collect `$pairs = $this->planner->identitySafeLegacyPairs($locked->payload['snapshots'], $plan['excluded'])`; insert each `$hash => $nik` into `bezetting_legacy_nik_maps` if `legacy_nik_hash` does not already exist (never overwrite a conflicting existing mapping).
  - In `rollback()` transaction: check `BezettingBackfillRun::query()->where('status', BezettingBackfillRun::STATUS_COMPLETED)->where('period_from', '<', $locked->period_from)->orderBy('period_from')->first()`; if found, throw `ImportAbortedException("Batalkan proses periode lebih lama lebih dulu ({$older->period_from} s.d. {$older->period_to}) sebelum membatalkan proses ini.")`. Delete `BezettingLegacyNikMap::query()->where('source_run_id', $locked->id)->delete()`.

- [ ] **Step 4: Run test to verify it passes**
  Run: `php artisan test tests/Feature/BezettingBackfillServiceTest.php`
  Expected: PASS.

- [ ] **Step 5: Commit**
  `git add app/Services/Bezetting/BezettingBackfillService.php tests/Feature/BezettingBackfillServiceTest.php && git commit -m "feat(bezetting): persist legacy NIK crosswalk on approve and enforce LIFO rollback"`

---

### Task 5: Revisable Name Dictionary UI (`Status` Filter on Names Tab), Upload Guide & 178-File Verification

**Files:**
- Modify: `app/Livewire/Admin/Employees/BezettingBackfillDetail.php`
- Modify: `resources/views/livewire/admin/employees/bezetting-backfill-detail.blade.php`
- Modify: `app/Support/BezettingReviewGuide.php`
- Test: `tests/Feature/BezettingBackfillDetailTest.php`
- Test: `tests/Feature/BezettingReviewGuideTest.php`

**Interfaces:**
- Consumes: `$plan['decided']` from Task 3, `BezettingBackfillService::decide()`.
- Produces:
  - `BezettingBackfillDetail::$decisionFilter` (`'undecided'` default | `'decided'`).
  - `decideByKey()` looks up the item in `$plan['blocking']` OR `$plan['decided']` (only when `$run` is editable: `staged` or `rejected`; refuses on `pending`), so the maker can revise any previously saved decision with full `bezetting.mapping_decided` audit logging.

- [ ] **Step 1: Write failing tests in `tests/Feature/BezettingBackfillDetailTest.php` and `tests/Feature/BezettingReviewGuideTest.php`**
  Add tests for:
  - `test_maker_can_switch_to_decided_filter_and_revise_saved_mapping`: switches `decisionFilter` to `'decided'`, sees previously kept name, maps it to a Master Formasi unit via `map($key)`, asserts `BezettingNameMapping` updated to `map` and `master_audit_logs` recorded `$old` (`keep`) $\rightarrow$ `$new` (`map`).
  - `test_approver_sees_decided_names_read_only_on_pending_run_and_cannot_mutate`: on a `pending` run, approver switches `decisionFilter` to `'decided'`, sees the saved decision badge, and calling `keep()`/`map()` is refused without mutating `BezettingNameMapping`.
  - Update `BezettingReviewGuideTest` if needed to verify `upload()` mentions newest-to-oldest order so older years connect automatically.

- [ ] **Step 2: Run test to verify it fails**
  Run: `php artisan test tests/Feature/BezettingBackfillDetailTest.php tests/Feature/BezettingReviewGuideTest.php`
  Expected: FAIL on the 2 new detail tests.

- [ ] **Step 3: Implement `decisionFilter` in `BezettingBackfillDetail`, Blade view, and `BezettingReviewGuide`**
  - In `BezettingBackfillDetail`: add `public string $decisionFilter = 'undecided';` with `updatedDecisionFilter()` resetting page; guard `decideByKey()` with editable status check (`staged` or `rejected`) and search both `blocking` and `decided`; pre-populate `$this->targets[$key]` with existing `target` for mapped decided items.
  - In `bezetting-backfill-detail.blade.php`: add a `Status` `<select wire:model.live="decisionFilter">` beside `Jenis` (`Belum diputuskan (N)` / `Sudah diputuskan (M)`), show a current decision badge on decided rows, and keep action buttons hidden when `! $editable`.
  - In `BezettingReviewGuide::upload()`: update *"Mulai dari yang terbaru"* to note that uploading 2023–2026 first automatically links legacy employee numbers for 2012–2022.

- [ ] **Step 4: Run full Bezetting test suite, Pint, and 178-file read-only verification**
  - Run: `./vendor/bin/pint --test` (or `./vendor/bin/pint` then `--test`)
  - Run: `php artisan test tests/Unit/BezettingWorkbookReaderTest.php tests/Unit/CareerHistoryDifferTest.php tests/Feature/BackfillPlannerTest.php tests/Feature/BezettingBackfillServiceTest.php tests/Feature/BezettingBackfillDetailTest.php tests/Feature/BezettingBackfillIndexTest.php tests/Feature/BezettingLegacyNikMapTest.php tests/Feature/BezettingReviewGuideTest.php tests/Feature/BezettingRunRecapTest.php tests/Feature/BezettingVerificationDownloadTest.php`
  - Run read-only PHP verification script over all 178 files in `Bezeting 2012 - 2026/` confirming **178 / 178 files read with 0 errors** (printing counts only, zero personal data).

- [ ] **Step 5: Commit**
  `git add app/Livewire/Admin/Employees/BezettingBackfillDetail.php resources/views/livewire/admin/employees/bezetting-backfill-detail.blade.php app/Support/BezettingReviewGuide.php tests/Feature/BezettingBackfillDetailTest.php tests/Feature/BezettingReviewGuideTest.php && git commit -m "feat(bezetting): allow reviewing and revising decided names on backfill detail"`
