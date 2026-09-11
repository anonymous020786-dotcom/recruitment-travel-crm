# Step 2.9 — Phase 2 hardening (closes core Phase 2: Leads)

**Status:** implemented; 384 tests green (no new tests — this step verifies and
fixes, it doesn't add features). EXPLAIN plans verified against a real 20,000-row
synthetic dataset; every Phase 2 view re-audited for accessibility; every
Phase 2 route/service re-audited for authorization, IDOR, SQL-injection, mass
assignment and CSRF.

This closes out core Phase 2 (2.1–2.8: lead CRUD, follow-ups, merge, convert,
import/export, communication log) before moving to Phase 3.

## Performance — EXPLAIN plans against 20,000 synthetic leads

Seeded ~20,000 synthetic leads (and 5,000 follow-ups) directly into `crm_dev`
and ran `EXPLAIN` — with `ANALYZE TABLE` first so the optimizer's row estimates
were current — against the exact SQL `LeadRepository`/`LeadFollowupRepository`
generate (captured via `Db::listen()`, not hand-approximated).

**Finding:** `LeadRepository::paginate()`'s row-fetch query (`JOIN
lead_statuses` + `LEFT JOIN` sources/users, `ORDER BY … LIMIT … OFFSET`) — when
the `WHERE` clause wasn't selective on `leads` itself (the common case: an
org-wide role browsing the default unfiltered list, or filtering only by
`status`), MySQL's optimizer chose to **join-order from `lead_statuses`
outward** (8 rows) instead of walking `leads` via `idx_leads_created` in
already-sorted order. Result: `Using temporary; Using filesort` — the engine
materialized and sorted a large chunk of `leads` before applying `LIMIT 25`,
rather than stopping after 25 index-ordered rows.

**Fix:** `SELECT STRAIGHT_JOIN` on that one query — pins the join order to
`leads` first, so the optimizer can use `idx_leads_created` (or whichever
index best serves `WHERE` + `ORDER BY` together) and stop at the page size.
Verified end to end with the real `LeadRepository::paginate()` call: the
row-fetch query dropped from a full-table materialize+sort to **1.7ms**,
`rows` estimate exactly `25`, no filesort.

**Deliberately NOT applied** to the `COUNT(*)` query in the same method, nor to
`cursorForExport()`/`countForExport()` — both read the *entire* matching set
regardless (no `LIMIT` to exploit index order for), so forcing `leads` first
there is actively worse: tested, it turned a selective status-filtered export
scan (`type=ref key=fk_leads_status`) into a full table scan
(`type=ALL rows=19879`). Letting the optimizer pick its own join order for an
unbounded read is correct; forcing it is only a win when a `LIMIT` lets an
index-ordered scan stop early.

**Also checked, no fix needed:**
- `LeadFollowupRepository::pendingForUser()`/`countsForUser()` — the composite
  `idx_lead_fu_due (assigned_to, status, due_date)` already matches the WHERE
  clause exactly (`type=ref`); a small residual filesort on `due_time` over the
  already-narrowed ~3ms result set is expected and cheap.
- `findLikelyDuplicates()` (phone/email lookup) — `index_merge` across
  `idx_leads_phone`/`idx_leads_alt_phone`, exactly as designed.
- Sorting the lead list by **priority** (`ORDER BY FIELD(priority, …)`) always
  filesorts — `FIELD()` is a computed expression, no index can serve it, with
  or without `STRAIGHT_JOIN`. Accepted trade-off: at realistic per-branch
  volumes (thousands, not millions) a filesort here costs single-digit
  milliseconds; denormalizing a numeric `priority_rank` column purely to index
  this one sort isn't justified yet.

Synthetic data (and the throwaway seed/EXPLAIN scripts used to generate and
inspect it) were removed afterward — nothing beyond the `STRAIGHT_JOIN` line
itself is part of this commit.

## Accessibility — every Phase 2 form control has an accessible name

Audited every view shipped in 2.4–2.8 for WCAG 4.1.2 (Name, Role, Value):
compact multi-field rows (follow-up scheduling, communication logging, note /
outcome quick-add, the CSV column-mapping table, bulk-reassign, the merge
field-picker) used bare `<select>`/`<input>` relying only on a `placeholder`
or nothing at all — invisible to sighted users navigating by context, but a
screen reader has no name for the control. Since these are deliberately
compact single-line forms (adding a visible `<label>` per field would break
the layout the pages were designed around), the fix is `aria-label` on each —
the standard WCAG-endorsed pattern for a visually-obvious-from-context control
with no room for a visible label.

Fixed (all in `resources/views/crm/leads/{show,index,merge,import-preview}.php`,
`resources/views/crm/followups/index.php`):
- Lead page: status-change reason, follow-up due date/time/channel/assignee/subject,
  follow-up outcome, note body, communication channel/direction/summary,
  assignment select.
- Leads index: bulk-reassign select.
- Merge picker: each "take theirs" checkbox now names the field it takes.
- Import mapping table: each column's "maps to" select names its CSV column.
- Follow-ups queue: per-row outcome input; the "WA" link now announces the
  lead's name, not just two letters.
- Timeline icons (📝/📞) marked `aria-hidden="true"` — decorative, the
  adjacent text already carries the meaning.

Not changed: table headers, status/priority badges (already text **and**
color), the `data-check-all` checkbox (already had `aria-label`), file upload
label on the import form (already a proper `<label for="file">`) — all
already correct.

## Security review — no defects found, verified systematically

- **Route-level authorization:** every Phase 2 route (leads CRUD, merge,
  convert, follow-ups, import/export, communications) confirmed inside the
  `['web.crm', 'auth', 'branch', 'enforce2fa']` group with a matching `can:`
  middleware; none reachable unauthenticated or unscoped.
- **Record-level / IDOR:** every lookup by a public identifier is scoped —
  leads and merge targets by `BranchScope`, follow-ups by `findInScope()`,
  import batches by `created_by`, export jobs by `requested_by`. All
  identifiers are ULIDs (unguessable); ownership/scope is still checked
  independently, so even a leaked id doesn't cross a boundary.
- **SQL injection:** every interpolated `{$var}` across
  `LeadRepository`/`LeadFollowupRepository`/`CommunicationLogRepository`/
  `ImportRepository`/`ExportRepository` traced to one of: `BranchScope`'s
  fixed-shape clause, `buildWhere()`'s allowlist-filter output (values always
  bound), a `SORT`/`FIELDS` allowlist lookup, a cast `int` (`LIMIT`/`OFFSET`),
  or a `match` expression with a safe default. No user-controlled string ever
  reaches SQL unbound.
- **Mass assignment:** `LeadImportService::confirm()`'s per-row field
  extraction is allowlisted three times over — CSV-column mapping is filtered
  to `LeadImportService::FIELDS`, then `LeadValidator`, then
  `LeadService`'s own `onlyColumns()` — before anything reaches an `INSERT`.
  `LeadService::mergeLeads()`'s `take[]` input is `array_intersect()`'d
  against `MERGE_FIELDS` before use.
- **CSRF:** every `<form method="post">` across the Phase 2 view set carries
  exactly one `csrf_field()` (checked 1:1 per file); the only GET-only
  mutually-safe actions (export/report downloads) correctly have none.
- **CSV injection:** re-confirmed from Step 2.7 — `App\Support\Csv` sanitises
  every export cell; no new export/report surface bypasses it.

## H. Manual QA

- [x] `LeadRepository::paginate()` row-fetch query verified via `EXPLAIN` at
      20k rows: `rows=25`, no filesort, 1.7ms (was a multi-row filesort)
- [x] Export/count queries confirmed to correctly keep the optimizer's own
      join-order choice (no regression from the `STRAIGHT_JOIN` change)
- [x] All Phase 2 form controls have an accessible name (`aria-label` or
      `<label for>`); decorative icons hidden from assistive tech
- [x] Full suite **384 tests, 900 assertions** green after both the query
      change and every view edit

## I. Performance

Covered above — the one concrete finding (`paginate()`'s join order) is fixed
and verified; everything else checked out already correctly indexed for the
access patterns Phase 2 introduced.
