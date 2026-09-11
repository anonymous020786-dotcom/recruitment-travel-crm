# Step 2.7 — CSV import / export

**Status:** implemented; 376 tests green (+12). Verified end to end over real
HTTP (upload → mapping → confirm → report → error-CSV download; queue export →
cron → panel → download) against a running MariaDB instance.

Import is processed **synchronously** within the request, bounded by
`import_export.leads.import.max_rows` (default 500) so it never runs long
enough to risk a shared-host execution-time limit — every row goes through the
exact same `LeadValidator` + `LeadService::create()` a manual entry does, so an
imported lead can never bypass a business rule a hand-typed one is subject to.
Export is **queued** (`export_jobs`, already reserved in the schema) and
drained by `cron/process-exports.php` — the same request-queues/cron-drains
shape as outbound mail — because a full-branch export can be tens of thousands
of rows, too slow for a web request.

## A. Files created

| File | Purpose |
|---|---|
| `database/migrations/0007_import_export_counters.sql` | `import_batches.skipped_rows` — a distinct counter for "likely duplicate, not imported by choice", separate from `failed_rows`. `import_batches`/`import_rows`/`export_jobs` themselves ship in 0001. |
| `app/Support/Csv.php` | `writeRow()` — every cell sanitised against CSV/formula injection (CWE-1236): a value starting `=`, `+`, `-`, `@`, tab, or CR gets a leading apostrophe before `fputcsv`. |
| `app/Models/ImportBatch.php`, `app/Models/ExportJob.php` | Read models. `ImportBatch` parses `mapping_json` into `headers` (original CSV header row) + `mapping` (column index → lead field). `ExportJob::isReady()`/`isExpired()`. |
| `app/Repositories/ImportRepository.php` | Batch CRUD, bulk row insert, `cursorRows()` (generator), `markRow()`, `problemRows()` (for the error report), `pruneOlderThan()` (returns paths to unlink). Batches are looked up by `(public_id, created_by)` only — ownership is the right check; branch was already validated at stage time and is re-checked per row at confirm time. |
| `app/Repositories/ExportRepository.php` | Job CRUD, `claimPending()` (atomic `pending`→`processing` claim per row — safe because the cron itself is single-instance via `CronRunner`'s advisory lock), `pruneExpired()` (returns paths to unlink). |
| `app/Services/LeadImportService.php` | `stage()` (parse CSV, auto-suggest a column mapping from header synonyms, store the file, insert `import_rows`) and `confirm()` (validate + `LeadService::create()` per row; unresolved `source`/`assignee_email` lookups are dropped silently rather than failing the row; duplicates are skipped unless `import_duplicates` is set; writes a per-row error-report CSV when anything wasn't imported clean). |
| `app/Services/LeadExportService.php` | `request()` (counts first, refuses empty/over-cap) and `process()` (cron-side: re-resolves the **requester's current** `BranchScope` — not a stale one captured at request time — streams via `LeadRepository::cursorForExport()`, a generator, so memory stays flat regardless of row count). |
| `app/Controllers/Crm/LeadImportController.php`, `LeadExportController.php` | Upload/preview/confirm/report/download-report; export queue/index/download. All ownership-checked (`created_by` / `requested_by` == current user). |
| `resources/views/crm/leads/import-{create,preview,report}.php`, `resources/views/crm/exports/index.php` | Upload form; per-column mapping table with a live sample value + a first-rows preview; counts + report download; "My exports" panel with status badges. |
| `cron/process-exports.php` | Fills the already-reserved `process-exports` job slot. One job's failure never loses the run — each is caught and marked `failed` individually. |
| `config/import_export.php` | `leads.import.{max_rows,max_kb}`, `leads.export.{max_rows,retention_days}` — all env-overridable. |
| `tests/Feature/LeadImportServiceTest.php`, `LeadExportServiceTest.php` | Mapping suggestion, valid/invalid rows, duplicate skip vs. import-anyway, source/assignee lookup resolution + graceful drop, required-mapping guard, double-confirm rejection, row cap; export empty/over-cap guards, end-to-end queue→process→download, branch-scope isolation, CSV formula-injection sanitisation. |

## B. Files modified

- `app/Repositories/LeadRepository.php` — `countForExport()` + `cursorForExport()`, both reusing the existing private `buildWhere()` so export sees **exactly** the same filter semantics as the Leads list/pagination.
- `bootstrap/services.php` — `LeadImportService` / `LeadExportService` singletons.
- `app/Notifications/NotificationService` was already generic; `LeadExportService` calls it directly with `type: 'export_ready'` (no import notification — the import UI shows the result immediately since it's synchronous).
- `cron/cleanup.php` — prunes import batches older than 30 days and expired export files, unlinking the actual files (not just the DB rows).
- `resources/views/crm/leads/index.php` — Import / Export (respects current filters) / "My exports" actions, permission-gated.
- `routes/web.php` — `/leads/import*` is declared **before** `/leads/{lead}` (documented inline: a literal path vs. a same-shape wildcard route is order-dependent in this router); `/leads/export`, `/exports*`.
- `.gitignore` — `storage/{private,imports,exports}/.htaccess` were being silently excluded by the existing wildcard ignore (`!/storage/**/.gitkeep` had no `.htaccess` counterpart), so `storage/private/.htaccess` was **never actually committed** — a real gap on a fresh clone/deploy. Fixed alongside adding the same guard to the two new directories.

## C. Migration

`0007_import_export_counters.sql` — applied to `crm_dev`. Reversible (drop column).

## F. Security / correctness

- **CSV formula injection (CWE-1236):** every export cell is sanitised (`App\Support\Csv`) before `fputcsv` — a lead's name, notes, or campaign field is free text a staff member typed, never trusted unescaped into a file that will later be opened in Excel/Sheets.
- **Row-level parity with manual entry:** import never bypasses `LeadValidator` or `LeadService::create()` — the same permission check (`leads.create`), duplicate detection, and branch-containment check apply to every row.
- **Ownership, not just permission:** an import batch or export job is visible only to the user who created it (`created_by` / `requested_by`), independent of the `leads.import`/`imports.run`/`leads.export`/`exports.run` permission checks on the routes.
- **Scope re-checked at the latest possible moment:** export re-resolves the requester's `BranchScope` when the cron actually runs, not what it was when they clicked "Export" — if their access has narrowed since, the file reflects that.
- **Idempotent confirm:** `import_batches.status` gates `confirm()` — `previewed` → `processing` → `completed`; a repeated confirm (double POST, back-button) on an already-processed batch is rejected, not re-run.
- **Bounded everything:** import rows (`max_rows`, default 500), export rows (`max_rows`, default 50,000, counted before any file is written), sample preview rows (10), error-report rows (all problem rows, but the batch itself is already capped).
- **File storage:** both `storage/imports` and `storage/exports` got the same `Require all denied` + engine-off `.htaccess` as `storage/private` (and that file's absence from git — see above — is fixed).
- Every completed import batch is audited once (`import_completed`, with counts) — not per row, which would flood `activity_logs` for a 500-row file.

## H. Manual QA — verified (over HTTP)

- [x] Upload a 3-row CSV (2 valid, 1 missing phone) → mapping auto-suggested from headers → confirm → report shows **2 imported / 0 skipped / 1 failed**; downloaded error-report CSV lists the failed row with its reason
- [x] Both imported leads land in the DB with `source_id` resolved from a free-text "Website" column
- [x] Queue an export with a search filter → `/exports` shows **Queued** → `php cron/process-exports.php` → job **completed**, in-app notification created → `/exports` shows **Ready** → download returns the CSV with only the matching rows
- [x] `GET /leads/import` (literal path) resolves to the upload form, not `LeadController::show` with `$lead = "import"`
- [x] Full suite **376 tests, 880 assertions** green

## I. Performance

- Export streams via `Db::cursor()` (a generator) — memory stays flat from 1 row to the 50,000-row cap.
- Import processes row-by-row inside one transaction-per-row (via `LeadService::create()`), not one giant transaction — a mid-batch failure never rolls back rows already committed, and locks are held briefly.
- `claimPending()` flips `pending`→`processing` per job before any work starts, so a slow job never blocks the next cron tick from claiming others (up to `batch` at a time, default 5).
