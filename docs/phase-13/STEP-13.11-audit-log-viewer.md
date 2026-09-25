# Step 13.11 — Audit log viewer

The CRM has written an append-only audit trail (`activity_logs`) since Phase 1 but nobody could read it without SQL. **Admin → Audit log** (`/admin/audit`, permission `audit.view` — super admin, admin and manager by default) makes it readable.

## What it shows
Newest first, 50 per page: when (UTC), who (or "System" for cron), what (action + module), which record, and a **View** disclosure with the note, the *before* and *after* values (pretty-printed JSON, capped at 6,000 characters each) and the IP and browser it came from. A record id links to **that record's whole history** (`record_type` + `record_id`, all dates). Everything is HTML-escaped.

Filters: search (person name/email, action, record type, note), module, date range, record type + id. The default view is the **last 30 days**; a bad filter is explained and the default view is shown under the message.

## Rules
- **Branch scoping** (docs/02-RBAC.md: "branch-scoped for manager"): the log has no branch column, so a viewer who is not organisation-wide sees the actions taken by people who share one of their branches — never system rows and never people in other branches. Admins/super admins are organisation-wide and see everything.
- **Read-only**: there is no write route, and `ActivityLogRepository` (the writer) is untouched; the viewer uses a separate read-only `AuditLogRepository`. A POST/PUT/DELETE/PATCH to the URL is 404/405.
- **Cheap on a big table**: migration `0017` adds `idx_activity_created (created_at)` for the default date-window query; the row count is capped at 10,000 ("More than 10,000 entries — narrow the dates"); dates are validated (real, in order, ≤ 366 days apart); module/record type must be plain identifiers and the record id numeric; the sort is fixed; `%`/`_` typed in a search are literals; the route is throttled like the dashboard.

## Tests
`tests/Feature/AuditLogTest.php` (9): filter defaults and normalisation; each bad filter rejected; organisation-wide vs branch-scoped visibility (other branch and system rows hidden from a branch manager); every filter narrows correctly (module, person, note, record, date window, literal `%`); newest-first and paging; IP decoded (v4 and v6) and before/after decoded and truncated, raw binary not passed on; access (counselor 403, anonymous redirected, manager/admin 200); escaping of hostile names/notes/values and record-history links; the log cannot be written through the viewer. `SqlSafetyTest` reviewed-variable list gained `cap`. Live smoke (php -S): 5/5.
Full suite: **1086 tests, 3 913 assertions** (3 skipped: GD-only image tests).

## Deployment
Run `php scripts/migrate.php` (adds the index; on a very large table the `ALTER` takes a moment — do it at a quiet time).
