# Career History Backfill from Monthly Bezetting Files — Design

Date: 2026-11-23 · Revised: 2026-11-24 (v2, gap review against the SSOT files and the code, see §12) · Status: **approved by the user 2026-11-24** · Phases 1–3 built on `feat/bezetting-career-backfill`; pilot on a restored production copy pending · Size: project (three phases)

## 1. Intent and why

HR holds monthly personnel lists ("Bezetting": one row per Karyawan Pimpinan, current position only). Riwayat Karir (`job_histories`) holds almost nothing before the app went live: usually the single `data_awal` row created when the employee was added.

Goal: rebuild each employee's career timeline for **January 2025 – October 2026** (22 monthly files, the only years that exist) by comparing consecutive monthly snapshots, in a way that is auditable, reversible, honest about uncertainty, and aligned with `AGENTS.md`, `DOMAIN.md`, `DESIGN.md`, `ENGINEERING.md` and `SECURITY.md`.

Why not a direct load: a snapshot says *where* someone was, not *why* they moved or under which SK. The app forbids invented SK data (`DOMAIN.md` §2.2, `SECURITY.md` §5.1), so backfilled rows are visibly "record only".

## 2. Goals, non-goals, anti-goals

**Goals**
- Derive rows only from visible changes between consecutive months (slowly-changing-dimension type 2).
- Stage, normalize, diff, review, then commit; nothing is written before a second admin approves.
- Atomic commit (`DB::transaction()`), file identity by SHA-256, undo that never hard-deletes.
- Existing app data always wins; the backfill only fills time before each employee's earliest existing row.
- Every uncertain value is marked, never silently trusted; the original text from the file is kept.
- Provenance on every row: backfill run, source period, acting user, approver.

**Non-goals**
- Karpel history (no files); history before 2025 (no files).
- SK numbers or dates on backfilled rows; attaching real SK evidence is a separate later feature.
- Changing Mutasi, `MutasiGovernanceGuard`, tenure or promotion rules.
- Reconstructing past organisation structures (no structure version exists before the first SK, `DOMAIN.md` §3.7).
- Creating, updating, moving or separating employees. The backfill only writes `job_histories` rows. Employee records and separations go through the existing, governed features (§5.6).

**Anti-goals (failure even if it technically works)**
- Fabricated `nomor_sk`/`tanggal_sk`, or a guessed promotion or demotion shown as an official mutation.
- Overwriting or overlapping any existing row, especially `sumber = mutasi`.
- Open historical rows that count as seat holders (`JobVacancyService`, MPP, Sebaran Formasi).
- Creating units or positions to make names fit, editing `master_formasi.json`, running a seeder (`AGENTS.md` rule 8).
- A concurrent duty ("merangkap Plt. …") or acting assignment recorded as a second definitive position.
- Personal data the history does not need (religion, education, marital status, birthplace, old SAP number) stored anywhere, or staged data kept after it is no longer needed.

## 3. Constraints

- Laravel 11, Livewire, PHPUnit 11, Pint, CI gate (`ENGINEERING.md` §3.7). Multi-table writes in `DB::transaction()`. Livewire search debounce 300 ms.
- One test class per system under test (`AGENTS.md` rule 5).
- UI text in official business terms, never internal document names or `SSOT` (rule 6); "Karyawan" terminology (rule 7). New code and docs in English, UI strings in Indonesian.
- UU PDP: staged data is encrypted, minimized (§5.9) and purged; screens and stored reports mask NIK (`12****78`).
- Reuse, do not duplicate (`ENGINEERING.md` §2.4): the `EmployeeImportService` / `EmployeeImportGovernanceService` pattern (token, SHA-256, maker-checker, undo, typed confirmation `BATALKAN`), `ConcurrentJobTextParser` for position text, `PensiunService::separate()` for separations, the shared data table for review lists.

## 4. Decisions made with the user

| # | Decision |
|---|---|
| 1 | Backfilled history is a reference timeline. Tenure and promotion checks keep reading the employee record (`MutasiGovernanceGuard` reads `tmt_unit_kerja` / `tanggal_dalam_jabatan`, verified in code); a test keeps it that way. |
| 2 | Existing app data wins. Conflicts go to a review list, never auto-resolved. |
| 3 | Use the exact `TGL. DALAM JABATAN`; flag the date as estimated when it disagrees with the month the change first appears, or when it is blank. |
| 4 | People who left the list are separated only per person with an HR-confirmed reason, through the existing separation feature (§5.6). |
| 5 | Karpim only. Scope 2025-01 to 2026-10. |
| 6 | Exceptions grouped by type with bulk decisions, reviewable over several sessions; HR decides business facts, IT handles format and mapping. |
| 7 | No SK numbers in this project. |
| 8 | Pilot on 2025 with an HR spot check of about 20 people, then 2026. |
| 9 | The old SAP number column is excluded and never stored. Only the first sheet of a workbook is read. |

## 5. Design

### 5.1 Pipeline

0. **Prerequisite.** Everyone in the latest file (October 2026) who is still employed exists in `employees`, loaded through the existing Employee Import. The backfill never creates active employees.
1. **Stage.** Up to 22 files in one run (`bezetting_backfill_runs`, its own table so the Employee Import batch list and undo never touch it). Per file: MIME type and size check, SHA-256, period read from the title row ("PER <BULAN> <TAHUN>") and checked against the file name; sheet names are ignored (they are wrong in two files). Only the columns in §5.9 are read; they are stored encrypted on the run and **the uploaded file is deleted right after reading**.
2. **Normalize.** Columns are found by header name, never position. Dates from Excel serials or `dd-mm-yyyy` text. NIK: 8 digits; 7-digit values are flagged for HR, never padded. Column-number rows, formula row numbers and text outside the table are dropped. A duplicate NIK in one file is an exception. In files without a Unit Kerja column, the unit is the nearest section heading above the row.
3. **Resolve names** (§5.2).
4. **Parse position text** (§5.3).
5. **Diff.** A pure class compares month N with N-1 per NIK and emits: first appearance in the window, change of position, unit, status or golongan class, a concurrent duty starting or ending, and leaving the list. A step-only golongan change (`/02` to `/03`, the July increment) is not an event. Leaving the list is not a separation (§5.6). Someone who leaves and reappears produces a gap, not two careers.
6. **Plan (dry run).** Rows to write, plus exceptions (§5.7). Nothing is written.
7. **Approve and commit.** A second admin approves, **always**, regardless of `hris.import.require_approval`, which defaults to off. The maker cannot approve their own submission. One atomic transaction. The commit refuses if the files or the review decisions changed since the plan was approved (SHA-256 of files plus plan).
8. **Undo.** Within the undo window, per run: soft delete with a reason; typed confirmation above the threshold (`hris.import.typed_confirmation_threshold`); refused if later changes touched the rows.

### 5.2 Name resolution (units and positions)

- No structure version exists before the first SK (1 September 2026). `StructureAsOfService` returns null for earlier dates by design. So 2025 names are **not** resolved "as of date". They are mapped to the current master structure only when HR confirms the rename.
- One grouped decision per distinct name ("KEBUN INTI GUNUNG MELIAU (K.SAWIT)" is unit "Kebun Gunung Meliau") applies to every row with that name. Decisions are saved and reused in later sessions.
- A name with no current equivalent (a dissolved unit or team) is stored as written and flagged as outside the current structure. Nothing is created in the master.
- The original text from the file is always kept (`teks_sumber`, §5.4), so a mapping can be audited or corrected later.

### 5.3 Position text

Position cells carry more than a title: "Penugasan sebagai Kepala Bagian Tanaman", "Asisten Manajemen Risiko merangkap Plt. Asisten Penggajian".
- Parsed with the existing `ConcurrentJobTextParser` rules (status tokens Plt, Pj, Pjs, Diperbantukan; "merangkap"), not a second parser.
- The main position becomes one row with its `status_penugasan`. Each concurrent duty becomes its own non-definitive row, with the same open and close rules.
- The meaning of "Penugasan sebagai …" (which status it maps to) is a business fact. HR confirms it once as a grouped decision; until then those rows are exceptions.

### 5.4 Data model changes (migrations, nullable, backward compatible)

- `job_histories.sumber` gains `bezetting` ("Bezetting Historis"). `impor` keeps its meaning (concurrent duties from Employee Import).
- `job_histories.backfill_run_id`: nullable FK to `bezetting_backfill_runs`, the audit and undo link. `batch_id` stays reserved for SK groups.
- `job_histories.tmt_awal_diperkirakan`: boolean, default false (decision 3).
- `job_histories.jenis_perubahan_terdeteksi`: nullable string, descriptive only (`PENEMPATAN_AWAL`, `PERUBAHAN_JABATAN`, `PERUBAHAN_UNIT`, `PERUBAHAN_STATUS`, `PERUBAHAN_GOLONGAN`, `TUGAS_RANGKAP`). `jenis_mutasi` stays NULL.
- `job_histories.teks_sumber`: nullable string, the unit and position text as written in the file (no personal data).
- A table for saved name and status mapping decisions (no personal data), so review work is not lost between sessions.
- Integrity, always in the service: `tmt_akhir >= tmt_awal`; no overlapping definitive periods; at most one open definitive row per employee. A database CHECK for the date order is added where the engine supports it (MySQL 8.0.16+ and SQLite do; the production engine and version are confirmed in phase 1).

### 5.5 Row rules

- `sumber = bezetting`, `nomor_sk`/`tanggal_sk` NULL, `jenis_mutasi` NULL, `is_applied = true`, `level` from the file (Karpim), `golongan` as at the start of the row, `created_by` = maker, `backfill_run_id` set.
- Every backfilled row is closed (`tmt_akhir` set). The last one closes the day before the employee's earliest existing row. Backfilled rows are never open, so they never count as seat holders.
- An employee with no existing row at all gets no open row from the backfill. The current open row remains the job of the employee record and its observer (`data_awal`). A mismatch between the last snapshot and the employee record is an exception.
- Changes seen in the files after the employee's earliest existing row are **not written**. They are reported as "movement not recorded in the application", for HR to process through Mutasi with its SK.
- Writes go straight to `job_histories` and do not touch `employees`, so `EmployeeObserver` adds no `pembaruan_data` rows.
- Idempotent: a row is identified by NIK, `tmt_awal`, unit, position, status and `sumber`. Re-running with the same files and decisions changes nothing.

### 5.6 People who left the list, and people not in the application

- **Still in `employees`** (for example moved to Karpel or another entity, but still recorded): history is written up to the last month seen. No separation is recorded by the backfill.
- **Not in `employees`** (left before the app had them): nothing is written in this run, because `job_histories` needs an employee record. They are listed for HR with the last month seen.
- **Separation:** only when HR confirms the reason for a person, through the existing employee separation feature (`PensiunService::separate()`, which closes open rows, writes `master_audit_logs` and enforces the official reasons). For someone not yet in the app, HR first adds them through the existing Employee Import. Then the separation is recorded. Then the backfill is re-run, and because it is idempotent it adds only their history. A test must prove that creating such an employee does not leave an open `data_awal` row counting as a seat holder.

### 5.7 Review experience (`DESIGN.md`)

- Exception groups: unmapped unit name, unmapped position text, status phrase to confirm, invalid or 7-digit NIK, duplicate NIK in a file, date conflict or blank date, conflict with an existing row, movement not recorded in the application, gap, person not in the application, last snapshot differs from the employee record.
- Three classes. **Name decisions** (unit, position text, status phrase) block the commit until HR maps, keeps as written, or skips each name; decisions are saved and resumable. **Person exclusions** (NIK not 8 digits, duplicate NIK in a file, not in the application, no current history, already reconstructed) do not block: the person is left out of this run and listed. **Information** (estimated dates, gaps, leaving the list, movement not recorded in the application) is reported only.
- Built on the shared data table (`DESIGN.md` §7.5), with blocking versus warning rules (§7.2), confirmation modals for commit and undo (§7.6), explicit loading states (§7.7). Desktop-first, since this is an admin screen.

### 5.8 Display and side effects

- Riwayat Karir shows a "Bezetting Historis" badge, the original text on demand and the estimated-date mark, in business wording, using the existing Sumber filter. The Riwayat & Audit Log screen keeps showing `mutasi` by default.
- The audit log export (22 columns) follows the Sumber filter and states "Bukan SK Mutasi (Catatan Data Kepegawaian)".
- **Intended side effect:** closed historical rows feed "Kosong sejak" and the last occupant on the vacancy monitoring (`JobVacancyService` reads the latest closed row per formasi). These dates become more accurate. It is tested and announced to HR before the run. Seat-holder counts are unchanged, because no backfilled row is open.

### 5.9 Security, retention and access (`SECURITY.md`)

- **Staged columns only:** NIK, name and birth date (for HR verification only), position text, unit text, level, golongan, `TGL. DALAM JABATAN`, `TMT DI BAGIAN/UNIT KERJA`, `TGL PENSIUN`. Not staged: religion, gender, education, school, marital status, birthplace, old SAP number, grades not used by history.
- Staged data is encrypted on the run, hidden from serialization, readable only through the service, and cleared after commit plus the undo window, or after at most 30 days for a run that is not completed, by a daily scheduled purge. Uploaded files are deleted as soon as they are read; only their names and SHA-256 are kept.
- Permission `karyawan.import` for every step (Gate, not role). Session token is a UUID. Report downloads are rate-limited.
- The HR verification lists (7-digit NIKs; people who left the list) carry full NIK. They are an authorized download only, logged on `master_audit_logs` with user and row count, never the content (like `SECURITY.md` §5.4).
- Commit and undo are recorded on the run with maker, approver, times, file hashes and row counts, and on `master_audit_logs` with counts only.

## 6. SSOT updates (phase 1, before code, `AGENTS.md` rule 1)

- `DOMAIN.md` §2.2: `bezetting`, `backfill_run_id`, `tmt_awal_diperkirakan`, `jenis_perubahan_terdeteksi`, `teks_sumber`; fix `status_jabatan` vs `status_penugasan`. New subsection with §5.1–§5.6 rules: existing data wins, closed rows only, no structure reconstruction, separations only through the separation feature.
- `SECURITY.md` §4–5: staged columns, retention, mandatory second approver, logged verification downloads.
- `DESIGN.md`: the "Bezetting Historis" badge and the estimated-date mark in the status and category tables (§5).
- `ENGINEERING.md`: the pilot procedure and environments (§8).

## 7. Testing (one class per system under test, `ENGINEERING.md` §3)

- Bezetting reader (unit): header-name mapping across the observed layouts, heading-row units, first sheet only, period from the title row, dropped junk rows, excluded columns never read into staging.
- Diff engine (unit): each event type, step-only golongan ignored, gaps, leaving versus separation, estimated dates, concurrent duties.
- Backfill service (feature): plan, mandatory second approver, atomic commit, idempotence, existing data wins, no SK values, all rows closed, movement after the first app row only reported, undo, NIK masking, staged-data purge.
- Guards (feature): tenure and promotion checks ignore `bezetting` rows; seat-holder counts unchanged; "Kosong sejak" uses the backfilled departures; creating a separated employee leaves no open row.
- Review component and Riwayat Karir display (feature), plus one Playwright flow for the review screen (`ENGINEERING.md` §3.6).
- Pint and the full CI gate.

## 8. Rollout and environments

Follows the five SDLC stages of `ENGINEERING.md` §1.
1. **Phase 1:** SSOT updates, migrations, models, constants.
2. **Phase 2:** reader, diff engine, service, governance, tests.
3. **Phase 3:** review UI, display, security and audit verification (stage 5).
4. **Pilot:** first on a local database, then on a restored copy of production made from the nightly backup, with HR checking about 20 people from 2025.
5. **Production:** run by the HR admin through the application, approved by a second admin, right after a fresh backup. Never run by the developer directly on the server.

## 9. Open items (do not block phase 1)

- Production database engine and version (CHECK support).
- What "Penugasan sebagai …" means as a status, confirmed by HR during review.
- Named HR verifier and approver (roles are set in §5.9 and §5.1 step 7).
- Reviewer capacity; grouped bulk decisions are the mitigation.
- **Pre-existing, outside this project (found in phase 3):** the manual edit of a history row (`JobHistoryController::update`) lets an admin type an SK number or mutation type on any record-only row (`data_awal`, `pembaruan_data`, `impor`), although `DOMAIN.md` §2.2 says those rows must not carry SK data. Closed after the user's approval: no record-only row can be given a new SK number or date (a stored legacy value is kept so the row stays editable), the edit form explains this instead of showing the SK inputs, and `bezetting` rows additionally keep an end date and no mutation type.

## 10. Profiling findings (read-only, 22 files, 2026-11-24)

- **Files:** 22, complete monthly from 2025-01 to 2026-10. Years 2022–2024 do not exist (confirmed by the user).
- **Layout drift:** 15 header layouts. The table starts in column 1 or 2. `GOL` is titled `GOL 2024`, `GOL 2025`, `GOL 2026` or `GOL`.
- **Unit Kerja column absent in 12 files** (Jan–May 2025, Jul–Dec 2025, Jan 2026). There the unit is a section heading row ("BAGIAN TANAMAN", "KEBUN … (K.SAWIT)"). Names differ from the later column ("Bagian Tanaman", "Kebun Parindu"). About 230–245 apparent unit changes in each layout-switch month are naming only.
- **Golongan:** about 346–348 values change every July, which matches the yearly periodic step, not promotions.
- **Real movement:** about 1 to 70 position-text changes per month (68 in July 2026). 96 arrivals and 82 departures across 21 steps; these are movements in and out of the Karpim list, not hires and separations.
- **NIK:** 17 distinct 7-digit values, none with a zero-padded twin, so HR must verify them. Name or birth-date text varies for roughly 18 people, so NIK is the only key. 17 NIKs leave and later reappear. The May 2026 file has one duplicate NIK.
- **File hygiene:** the May and June 2026 sheets are titled "Bez April 2026". December 2025's second sheet "(2)" is a working copy whose `JABATAN` column holds the unit instead of the position (all other columns identical for all 370 people). Every file has a column-number row. Row numbers are formulas. October 2026 has stray text below the table ("… PT AGRINAS PALMA NUSANTARA (PERSERO)").
- **Position text** contains statuses and concurrent duties ("Penugasan sebagai …", "merangkap Plt. …").
- The unlabelled `000.0716.…` column is the old SAP employee number, no longer used (user), and is excluded.
- Raw files stay outside git (`*.xls` is ignored). Profiling scripts live only in the session scratchpad and print counts.

## 11. Going forward (recommendation, out of scope)

- The 22 historical files are never edited; they are evidence.
- No `Jenis Mutasi` or SK column in the Bezetting template. Mutation type is governed by `MutasiGovernanceGuard` (`DOMAIN.md` §5.2), and SK data comes from the Mutasi module.
- After the backfill, movements are recorded only through Mutasi. The monthly Bezetting should be generated from the application (employee export, `DOMAIN.md` §9.4).
- Until then, one frozen template: fixed header names, NIK as 8-digit text, one sheet named by period, Unit Kerja as a column, golongan class and step in separate columns, no SAP column, no text outside the table. An optional dropdown for the reason someone leaves the list is informational only.

## 12. Gap review (v2)

| # | Gap in v1 | Fix in v2 |
|---|---|---|
| 1 | Names resolved "as of date", but no structure exists before the first SK (`StructureAsOfService` returns null) | HR-confirmed mapping to the current master, original text kept, no reconstruction (§5.2) |
| 2 | Status and concurrent duties inside position text ignored; would create false definitive rows | Parsed with `ConcurrentJobTextParser`; separate non-definitive rows (§5.3) |
| 3 | Unconfirmed leavers "keep history", but `job_histories` needs an employee record | People not in the app are listed, not written (§5.6) |
| 4 | Creating separated employees inside the backfill would fire the observer and bypass the separation rules | Backfill never creates or separates employees; existing features do (§5.6) |
| 5 | Four-eyes depended on a policy that is off by default | Second approver always required (§5.1 step 7) |
| 6 | "Existing data wins" silently hid movements after go-live | Reported as "movement not recorded in the application" (§5.5) |
| 7 | Effect on "Kosong sejak" not considered | Stated, tested, announced (§5.8) |
| 8 | Retention and staged columns undefined; resumable review conflicted with the 2-hour purge | Column list, 30-day ceiling, existing purge (§5.9) |
| 9 | HR verification downloads not logged | Logged on `master_audit_logs` (§5.9) |
| 10 | Idempotence key collided for concurrent duties | Key includes unit, position, status (§5.5) |
| 11 | `DESIGN.md` and the SDLC stages not addressed; no environments | §5.7, §8 |
| 12 | Stale scope (2022, 58 files) | 2025-01 to 2026-10, 22 files |
| 13 | (found in phase 2) Reusing `employee_import_logs` would put runs in the Employee Import batch list, whose undo restores employees and would leave backfilled rows in place | Own table `bezetting_backfill_runs`; link renamed `backfill_run_id` (§5.1) |
| 14 | (found in phase 2) Keeping uploaded files for the review period keeps every column, including religion and education | Files deleted right after reading; only the minimized, encrypted data stays (§5.9) |
| 15 | (found in phase 2) "Blocked while anything is undecided" made person-level problems block everyone | Three exception classes (§5.7) |
| 16 | (found in phase 2) End date of a position that is no longer seen was undefined | End of the last month it was seen (`DOMAIN.md` §5.5 rule 4) |
| 17 | (found in phase 2, real files) A person row with an empty NIK was read as a section heading, so a person's name became a unit name | A row without NIK is a heading only when it has no position and no golongan; otherwise it is reported (`nik_kosong`) |
| 18 | (found in phase 3) The manual edit form could reopen a `bezetting` row (empty end date, counted as a seat holder and synced to the employee) or give it an SK number or mutation type | `JobHistoryController::update` requires an end date and refuses SK fields and mutation type on `bezetting` rows |
| 19 | (found in phase 3) The shared upload component accepted one file only | Backward-compatible `multiple` option, no hand-written file input |
| 20 | (found in the first local trial) A run with nothing to write could not be submitted, but the screen told both admins to "decide all names first", and the second admin saw no explanation of why there was no approve button | The screen states the actual reason for each party (names undecided, nothing to write, or waiting for the maker to submit) |
| 21 | (found in the first local trial) A run never submitted stayed "Ditinjau" forever; the purge only cleared its data, contrary to the documented lifecycle | The maker can close an unsubmitted or returned run with a reason (`withdrawn`, data cleared at once, audited); the daily purge marks runs not submitted within the 30-day retention as `expired` |
| 22 | (found in the first local trial) Golongan was stored as written in the file ("IVB/3"), not in the standard `[Gol]/[Berkala]` format with a two-digit step | `BackfillPlanner::standardGolongan` writes "IVB/03"; text that is not a golongan is not stored |
| 23 | (found in the first local trial, pre-existing) The Riwayat Karir table had seven data columns and scrolled sideways at 1366px, against DESIGN §7.5 | Five data columns with unit and SK on sub-lines; a browser test checks the fit at 1366px |
| 24 | (found in the first local trial, pre-existing) The table's delete skipped the controller's rules: it could remove the running position, asked for no reason, recorded no `cancelled_by` / `alasan_pembatalan`, and used coloured buttons and the browser `confirm()` | One path, `JobHistoryController::destroy`: reason required (SECURITY item 6a), soft delete with who and why, running rows and another employee's rows refused; a kebab menu with the shared reasoned dialog |
| 25 | (found in the first local trial) The table showed the strata (Karpim) where DESIGN §7.5 makes the RM Band the structural anchor | RM Band per the Master Formasi (`FormationService::masterPositionRm`, never guessed from old names); the level only when the position is not in the master |
| 26 | (found in the first local trial, pre-existing) The Tambah/Ubah Riwayat Jabatan form could move an employee outside Mutasi (an open row or an edit of the running row synced the employee profile), accepted any golongan text ("CKP"), free-text level, overlapping periods, recorded no reason or audit, labelled manual rows without an SK as "Mutasi Resmi", and did not use the shared form components ("Edit", "Update") | `JobHistoryCorrectionService`: closed rows only (running row: note only, link to Mutasi), standard golongan, level from the RM Band, no overlapping definitive periods, official mutation only with type and full SK otherwise `pembaruan_data`, reason and `job_history.*` audit; rebuilt form from the shared components |
| 27 | (found in the first local trial) The rebuilt form still took Jabatan and Unit Kerja as free text (with suggestions), so typos and unofficial names could enter history and break the RM Band lookup and reporting; the RM Band label also repeated itself | Master pick lists (`searchable-select`, `unit-combobox`), server-side master-name check with the master's spelling, an old name kept only when the row already holds it; live RM Band label from `Position::RM_LEVEL_LIST` |
| 28 | (found in the first local trial) Position and unit were chosen independently, so a pair outside any formasi (e.g. a Kasubag SDM in a Kebun) could be saved; and a genuinely old position outside today's master had no governed path | Position list filtered by the unit's formasi on TMT Awal (structure SK version, or current master before the first SK), checked again on the server; a name outside the master is a reasoned exception for periods before the first structure SK only, recorded as a "keep" decision in the shared old-name dictionary, refusing names HR mapped or skipped |
| 29 | (found in the first local trial) A run showed only totals: HR could not see who received history and how much, the approver approved without seeing the rows, the 220 movements to follow up existed only as a number, and the HR download after approval rebuilt the plan against the written rows (reporting everyone as already done) | `BezettingRunRecap`: per-employee recap (preview before approval, reconciliation from the written rows after) and a Perlu Tindak Lanjut list; follow-ups captured at approval (NIK and month, encrypted, cleared on undo and after 90 days); clickable summary counters; the download reads the recap for saved runs |
| 30 | (DRY review) Logic written twice: the structure-SK lookup and current-structure query (already in `StructureAsOfService`, `StructureReader`), the golongan normaliser behind a planner wrapper, follow-up labels in the screen and the download, and the 10–500 reason rule and wording in four places | `JobHistoryCorrectionService` reuses `StructureAsOfService` and `StructureReader`; `App\Support\Golongan` called directly (own test class); `BezettingBackfillService::FOLLOWUP_LABELS` is the one list; `App\Support\ReasonRule` gives one rule and wording to the delete request, history correction, and the rebuild screen and service. "Baca Berkas" moved onto the upload field's row through a new `action` slot on the shared `file-upload` component |
| 31 | (found in the first local trial) The recap confused admins: a "Baris" column that was almost always 1, a bare "Perkiraan" count, the label "Penempatan Awal" read as a hiring, no source file, no reason for the dates, a silently partial preview while names were undecided, and a table that scrolled sideways in the run page column | One row per written period with position, unit, source Bezetting month, file text, and a Periode column explaining the start (file date or "Perkiraan") and the end (day before the first row in the application, or last month seen); label "Awal Data Bezetting"; "Pratinjau belum lengkap" warning; the download uses the same wording; a browser test checks the table fits at 1366px |
| 32 | (found in the first local trial) The run page was long and untidy: names, recap and exclusions stacked (128 names before the recap), name rows three or four lines tall with stacked buttons, and the recap repeating the same explanations on every row | A "Tinjauan" area with tabs and counts showing one section at a time in work order; one-line name rows (picker and three decisions on one line); the recap explains its terms once in a legend and labels only exceptions |
| 33 | (found in the first local trial) Two levels of tabs (the review tabs and a second tab bar inside the recap), and a right column that listed all 10 uploaded files in full, leaving a large empty area under the decision card | One flat tab bar (Nama / Riwayat / Perlu Tindak Lanjut / Dikecualikan) with the recap component driven by the tab; Berkas Terbaca is a one-line summary with an on-request file list, open by default when a file has a warning |
| 34 | (found in the first local trial; DESIGN §6) The official-name choice was a native `<select>` carrying hundreds of options per row (the rule is searchable-select above 15 options); "Apa Adanya" wrapped onto two lines at a different height from "Petakan"; "Lewati" looked like plain text; an opened file list stretched the right column; the Keputusan card named the names to decide but offered no way to reach them | `searchable-select` with a new `floating` mode (fixed panel, not clipped by the table) and the choice held in the browser until Petakan; the three decisions equal, non-wrapping buttons; the file list scrolls inside its card; a "Buka Daftar Nama" button on the Keputusan card |
| 35 | (found in the first local trial) The Keputusan card sat between the summary and the review, so "Ajukan" was visible before anything had been reviewed and the admin had to scroll back up after reviewing 128 names | The decision comes after the review as the shared sticky form footer (a short status line plus the actions, always in reach); explanations that do not fit a line sit just above it; the risk card (undo) precedes it. A browser test checks the order and that the bar is in view, which also caught that a sticky element must be a direct child of the page |
| 36 | (found in the first local trial) The Ringkasan card and the right column (Jejak Keputusan, Berkas Terbaca) ended at different heights, most visibly with the file list open (the left card ended ~230px above the right column) | The grid stretches both columns; the summary card fills the left column and pins its secondary counters under one divider, the last right-hand card fills the right column, and the file list scrolls inside its card; the browser test requires the two bottoms to match within 1px with the list closed and open |
| 37 | (found by checking the real files, 341 distinct position texts) "(merangkap …)" left a dangling "(" on 28 position names (e.g. "Asisten Personalia (") that HR was asked to decide as separate names, a duty written as "(Plt. … tmt …)" was one long position name instead of a concurrent duty, one position text was a single letter, and the floating picker closed whenever its own option list was scrolled | `PositionTextInterpreter`: the bracket belongs to the separator, a trailing "(Plt/Pj/Pjs …)" is the same concurrent duty as "merangkap Plt …", brackets that belong to the post ("(Rayon Utara)") are kept, a "merangkap" inside a longer note is left whole; names with bracket text dropped from 88 to 44 on the real files and unbalanced ones from 28 to 0; positions of 1–2 characters are unreadable rows (`jabatan_tidak_valid`) reported on the HR list, with the issue labels now shared by the reader and the download; the picker ignores scrolling inside its own panel; wrapped names stay aligned beside a fixed-width badge and the separate "Saran:" line is gone (the suggestion is pre-selected) |
| 38 | (found in the first local trial) The labels "Mulai perkiraan" and "Terakhir terlihat" were jargon admins could not read ("last seen" did not say what it referred to) and the legend explained them abstractly; also, correcting an estimated start date from a document left the row flagged "(perkiraan)" | Plain labels "Mulai diperkirakan" and "Selesai menurut berkas" with a legend that says where each date comes from, that the label is only a precision marker (nothing blocks saving) and that Ubah Riwayat corrects it; changing the start date of an estimated row clears the flag |
| 39 | (found in the first local trial) A recap row read "Jabatan · Unit" in its headline but "Unit \| Jabatan" in the file text beneath it, and the legend had no example | The file text is stored and shown as "Jabatan \| Unit" (the file's column order and the headline's order), an empty part is skipped; the legend has a collapsed "Lihat contoh" with one start-date and one end-date example (real patterns from the files: 133 position changes had a usable date and 130 did not) |
| 40 | (found in the first local trial) The Perlu Tindak Lanjut and Dikecualikan tabs were bare counts and masked NIK lists: admins could not tell the two apart (one is people who are processed but have something the rebuild does not write; the other is people who are not processed at all), what each reason meant, whether it blocks submitting, or what to do | Plain-language meaning and "Yang perlu dilakukan" per type and per reason, from single sources (`FOLLOWUP_HELP`, `EXCLUSION_HELP`) also used as a column on the HR download; the follow-up month reads in words ("Berubah mulai 2026-10"); both tabs say they do not block submitting; a test requires every exclusion reason to have a meaning and an action |
| 41 | (found in the first local trial) The four review tabs each explained themselves in a different shape (a grey line, a legend with bullets, a beige three-column block, cards without a table), so admins had to relearn each screen | Closed. One shared guide: `App\Support\BezettingReviewGuide` holds the wording for the four tabs (names, history, follow-ups, exclusions) and the follow-up and exclusion texts stay single-sourced in the service and planner; `x-admin.table.guide` renders it identically. Every tab now reads header, notice, toolbar, guide, table, pagination, and Dikecualikan is a normal table with masked NIK, a reason filter and search. Saved runs say that excluded people are known only while the run is open | `BezettingReviewGuideTest`, `BezettingRunRecapTest`, `BezettingBackfillDetailTest`, e2e `riwayat-bezetting` |
| 42 | (found in the first local trial) On the Nama yang Perlu Diputuskan tab the Keputusan column shifted from page to page with the length of the names, and the guide always took space | Closed. The table uses a fixed desktop layout (fixed Muncul and Keputusan widths) so pickers line up on every row; the shared guide is collapsible (open on arrival, toggled from its title) | `BezettingReviewGuideTest`, e2e `riwayat-bezetting` |
| 43 | (found in the first local trial) The history tab had a "Lihat contoh" block that no other tab has, and it made the legend heavier | Closed. The example block is removed so all four tabs carry only the shared legend; the Mulai, Selesai and Label entries were reworded in plainer terms | `BezettingRunRecapTest` |
| 44 | (found in the first local trial, checked by hand against the Bezetting files) Perlu Tindak Lanjut listed 78 people, most of them wrongly: a position that never changed in the files was reported as a movement because its wording differed from the application, and people listed every month appeared as gaps or exits while one of their names was still undecided | Closed. A movement is reported only when the files themselves show a change; presence is tracked separately from names, so a listed person is never a gap or an exit. On the trial run the list fell from 78 to 8 while 108 names were still undecided; the rest settle once names are decided | `BackfillPlannerTest` |
| 45 | Full data review of a 10-month trial run (3,891 file rows, 422 people), recomputed independently from the raw rows with every name decision rolled back | Closed. Fixed: a golongan step or a status change was reported as a "movement" (now only a unit or position change is); rows starting before the employee's start-work date were written (now the person is excluded with a reason); one NIK could carry two people (e.g. a name overwritten in the last two months) and mix their histories (now excluded: names with no shared word, two birth dates, or a birth date unlike the employee record); people present in a file were shown as gaps or exits while a name was undecided (presence is now tracked apart from names, only a skipped name leaves a gap). Verified clean: no row overlaps existing history or itself, every row is closed before the earliest application row, every row's source text equals the file text of its source month, dates are valid and not in the future, no file-vs-employee name or birth-date mismatch, results are deterministic | `BackfillPlannerTest` |
| 46 | Admins and approvers had no in-app guidance on how many files to upload, in what order, or what the whole process is | Closed. The list page carries two collapsible guides from the shared guide source: advice before uploading (number of files up to the limit, consecutive months, newest year first with a 20-person spot check, one file per month) and the seven-step workflow from upload to undo, including four-eyes approval, follow-up through Mutasi and the undo window | `BezettingBackfillIndexTest`, `BezettingReviewGuideTest`, e2e `riwayat-bezetting` |
| 47 | (found in the first local trial) Some legends were open and some closed, and the open ones pushed the table off screen | Closed. Every legend (all review tabs, the upload advice and the workflow) is closed by default through the one shared component, and opens from its title | `BezettingReviewGuideTest`, e2e `riwayat-bezetting` |
| 48 | (found in the first local trial) A person listed in the March file with a one-letter position was shown as "Celah bulan: tidak tercantum 2026-03", and follow-ups for people with names still undecided showed wrong movement months | Closed. The reader keeps the NIK of a row it cannot use only because of its position text, so the planner counts the person as present and carries the previous month forward; a person with an undecided name is left out of rows and follow-ups until decided, so nothing is built from half-read months. Re-upload is needed for the NIK to be kept on already-staged runs | `BackfillPlannerTest`, `BezettingWorkbookReaderTest` |
| 49 | Row-by-row check of what the screen showed against the raw files and application history (4 people) | All 3 planned rows correct (position, unit, status, exact or estimated start, end = day before the earliest application row). One gap closed: an application row for the right position but dated years before the files show the person entering it (stale start date) was treated as "already recorded"; it is now reported as a movement for HR to check (62-day tolerance) | `BackfillPlannerTest` |

## 13. Review workload measured on the real files (phase 2, counts only)

Against the seeded master structure (47 units, 274 positions): 87 distinct unit names, of which 36 match the master and 51 need an HR decision; 305 distinct position names, of which 115 match and 190 need a decision; one status phrase ("Penugasan sebagai", 74 rows); 225 concurrent-duty cells. Phase 3 should therefore offer grouped suggestions (reusing `PositionNameMatcher`) and an explicit bulk "keep the remaining position names as written" decision, so the 190 position names are one reviewed action rather than 190 clicks.
