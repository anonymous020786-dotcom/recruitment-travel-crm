# Step 12.2 — SQL review: injection audit and EXPLAIN / performance review

## A. Injection audit

Values are always bound (PDO native prepares). The only things ever interpolated into SQL text are fragments the
code builds itself. Every interpolation in the data-access layer was reviewed and the reasoning is now enforced by
`tests/Unit/SqlSafetyTest.php`:

| Interpolated | Where it comes from |
|---|---|
| `{$branchSql}` (98 uses) | `BranchScope::whereClause()`; ids bound as `:bs0…` |
| `{$order}` | allow-list lookup (`SORT`) plus a fixed `ASC`/`DESC` — an unknown `?sort=` falls back to the default |
| `{$limit}` / `{$offset}` | `int`-typed parameters; `per_page` is restricted to a fixed list |
| `{$where}`, `{$condition}`, `{$window}` | implode of literal predicates built in the repository |
| `{$in}` | a list of `:placeholders` |
| `{$table}`, `{$column}`, `{$dateColumn}` … | constants or config, never request data |

The test fails when (1) a **new** variable is interpolated into SQL that is not on the reviewed list, (2) a repository
reads the request (`$_GET`, `$request->`), or (3) a dynamic `SET` list bypasses the identifier-checked helper.

Findings and fixes:

1. **Identifier regexes accepted a trailing newline** (`"a\n"`): PHP's `$` also matches before a final newline.
   Fixed with the `D` modifier in `Db::wrapColumn`, `DashboardRepository`, `DailyReportRepository` and the new
   `Sql::identifier`. Not exploitable (identifiers never came from users) but it was a hole in the guard.
2. **Dynamic `UPDATE … SET` column lists** (`` `{$col}` = :c_{$col} ``, 17 sites in 15 repositories) interpolated array
   keys with only backtick quoting. They now go through `Sql::assign()`, which rejects anything but a plain
   identifier. Column names always came from service code, so this is defence in depth.
3. `LIKE` wildcards in user search are escaped in every list repository (checked all 17).

## B. EXPLAIN / performance review

Method: `scripts/perf-volume.sql` loads realistic volume into a scratch database (150k leads, 100k applications,
60k invoices, 50k payments, 50k candidates, 200k notifications); `scripts/perf-audit.php` runs 28 hot paths as a
branch-scoped manager and reports wall time against a 300 ms budget (5× for the cron-run integrity probes and the
cold dashboard); the MariaDB slow log (`long_query_time=0`, `log_output=TABLE`) gave `rows_examined` per statement.

**Measurement pitfall worth remembering:** XAMPP's default InnoDB buffer pool is 16 MB. With it, every number was
IO-bound and the audit reported a dozen "slow" screens (leads 1.9 s, applications 6 s). With a 1 GB pool the same
queries were mostly fine. Always size the pool before judging a plan.

Real problems found (warm, 1 GB pool) and fixed:

| Screen | Before | After | Cause → fix |
|---|---|---|---|
| Applications list | 394 ms | 11 ms | optimizer drove from `jobs`, then filesorted every matching application after five joins → `STRAIGHT_JOIN` from `applications` + index `(branch_id, applied_at, id)`; count no longer joins candidates/persons unless the search reads them |
| Leads list | 74 ms | 16 ms | no index matching `branch_id` + `ORDER BY created_at` → `(branch_id, deleted_at, created_at, id)`; count joined `lead_statuses` and `lead_sources` for nothing |
| Candidates list | 120 ms | 17 ms | `(branch_id, created_at, id)`; conditional person join in the count |
| Invoices / payments lists | 80 / 36 ms | 41 / 6 ms | conditional person join in the count |
| Org-wide (no branch filter) ordering | — | — | `(applied_at, id)` on applications |

Migration `0015_list_ordering_indexes.sql` (mirrored in `schema.sql`; additive, no data change, safe on a live DB).
`Sql::references($where, 'alias')` decides whether a count needs a join: the joined tables are all foreign-key
guaranteed, so leaving them out never changes the number.

Final numbers (all within budget): lead / candidate / application / payment first pages 6–17 ms, deep page (500)
37 ms, invoices 41 ms, reminder scan 37 ms, aging 46 ms, integrity probes (10 checks) 181 ms, cold dashboard
205 ms, largest report (invoices register) 132 ms. Remaining slower paths are inherent: substring search on a name
(`LIKE '%x%'`, ≈190 ms over 100k leads) and phone/name `OR` searches (≈90–110 ms).

Not changed on purpose: the `%term%` name search (a FULLTEXT index would change matching semantics — decide if
needed); exact `COUNT(*)` on every list (now cheap enough).

## Deployment note

Shared hosting cannot tune `innodb_buffer_pool_size`. If list screens feel slow on the host, that — not the
queries — is the first thing to ask the provider about. Run `php scripts/migrate.php` to pick up migration 0015.

859 tests, 2770 assertions.
