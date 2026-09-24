# Phase 10 · Step 10.1 — The dashboard

Phase 10 is split: **10.1 dashboard** (this step), 10.2 reports framework with recruitment / travel / tours reports, filters, print views and streamed CSV, 10.3 finance reports and register exports. No migration and no new dependency: the charts are plain CSS bars — the architecture allowed Chart.js on report pages only, and a bar chart of six numbers does not need a script.

## What the page shows
Everything is **branch-scoped** (the viewer's own branches) and **permission-gated per widget group** — a group's queries are not even run unless the viewer holds its permission:

| Group | Permission | Shows |
|---|---|---|
| leads | `leads.view` | open leads (not converted / lost / not interested), leads by status, new leads per month |
| candidates | `candidates.view` | total, new this month, new per month; passports expiring ≤ 30 days |
| pipeline | `applications.view` | live applications and the pipeline by status, in pipeline order, each bar linking to the filtered list |
| interviews | `interviews.view` | today and next 7 days |
| visa / medical | `visa.view` / `medical.view` | approved visas, visas and fit medical certificates expiring ≤ 30 days |
| travel | `travel.view` | candidates at each travel stage, placed this month / in total, placements per month |
| tours | `tours.bookings.view` | bookings by status, open tours travelling within 30 days |
| finance | `invoices.view` | per currency: outstanding, billed, collected in the last 30 days, overdue |
| refunds | `refunds.approve` | refunds waiting for approval |

Plus a **Needs attention** card listing only non-zero items with a link to the filtered screen (interviews today, expiring visas / medicals / passports, candidates waiting for a ticket, tours starting soon, refunds to approve, overdue invoices) — it is hidden when there is nothing to chase. The viewer's own follow-up queue stays live and personal.

Money is shown **per currency** and never summed across currencies (architecture assumption A2).

## How it stays cheap
- `DashboardRepository`: one grouped query per widget — never a query per row. Named placeholders cannot repeat inside a statement, so widgets are separate statements rather than one giant one; a fully-permissioned uncached load is about 14 queries.
- `DashboardService` caches the finished snapshot in `settings` (`dash:<sha1>`), **keyed by (branch scope, widget set) — not by user** — so everyone looking at the same slice of the business shares one computation, and no personal data is ever in it. TTL `app.dashboard_cache_seconds` (env `DASHBOARD_CACHE_SECONDS`, default 60; 0 = always live). The page prints "as of hh:mm UTC".
- `cron/cleanup.php` now also deletes `dash:%` snapshots older than a day.
- The dynamic table / column names in `DashboardRepository::monthly()` are interpolated (they cannot be bound), so they are restricted to plain identifiers.

## Verification
- **5 new tests** (`DashboardServiceTest`), against a realistic branch-A dataset (leads, converted candidates, applications at travel stages, interviews, an approved visa, a fit medical, a passport, a placement, a tour booking, an overdue partly-paid invoice, a pending refund) and a branch-B dataset the viewer must not see: every widget's number; the six-month series; branch isolation (branch B's manager sees only branch B); the attention list (and its absence for an empty branch); widget sets per role (`read_only`, `counselor`, `accounts`, `manager`); caching — reused within the TTL, refreshed after it, separate entries per scope and per widget set, shared by two people with the same slice, disabled at 0; and the identifier guard.
- Full suite: **760 tests, all green.**
- **HTTP smoke test (18 checks)** with a real admin and finance/pipeline data: the page renders every expected card, the receivables show `INR 700.00`, the attention card is correctly absent when there is nothing to do, a snapshot row appears in `settings`, a second request shows the same "as of" time (served from cache), and an anonymous request is redirected to login. Rows removed afterwards.

## Not in this step
Reports with filters / date ranges / CSV / print (10.2, 10.3), a scheduled `dashboard-cache` cron to pre-warm snapshots (Phase 11 with the other automation; on-demand caching already bounds the load), per-user "my tasks" widget, and click-through drill-downs beyond the existing list filters.
