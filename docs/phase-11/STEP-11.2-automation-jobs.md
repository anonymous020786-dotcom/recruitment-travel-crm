# Step 11.2 — Automation jobs: integrity check, daily report, dashboard warm-up

Implements the three jobs that `config/cron.php` already scheduled but had no script for. The dispatcher from
11.1 runs them; each is also runnable by hand (`php cron/<job>.php`).

| Job | Schedule (UTC) | Script | Service |
|---|---|---|---|
| `daily-report` | 07:00 daily | `cron/daily-report.php` | `DailyReportService` |
| `integrity-check` | daily, quiet hours | `cron/integrity-check.php` | `IntegrityService` |
| `dashboard-cache` | every 10 min | `cron/dashboard-cache.php` | `DashboardService::warm()` |

## Integrity check

`IntegrityRepository` holds ten **read-only** probes (a test asserts by reflection that none writes). Each
returns up to 50 `['ref', 'detail']` rows:

1. `invoicePaidMismatch` — `invoices.paid_minor` ≠ sum of live allocations
2. `invoiceRefundedMismatch` — `refunded_minor` ≠ sum of paid refunds
3. `invoiceStatusMismatch` — stored status ≠ `Invoice::statusFor()` (draft/void ignored)
4. `invoiceTotalsMismatch` — `grand_minor` ≠ lines + tax − discount
5. `paymentOverAllocated` — allocations exceed the payment amount
6. `refundsExceedPayment` — reserved/paid refunds exceed what was paid
7. `paymentReceiptMismatch` — completed payment without exactly one receipt
8. `orphanInvoiceables` — polymorphic link pointing at a missing row
9. `applicationHistoryMismatch` — application status ≠ latest history row
10. `sequencesBehind` — a `number_sequences` counter lower than the highest issued number

`IntegrityService::run()` stores the result in `settings` key `integrity:last` (for the Step 11.3 admin
screen) and notifies every active super admin with an `integrity_alert`, **once per check per day**
(`dedupe_key = integrity:<code>:<date>:u<id>`). Findings are reported, not thrown: the job only fails if it
could not run. The job's exit value is the number of findings.

## Daily report

Yesterday's counts (leads, conversions, applications, interviews held, visas, placements, bookings) and money
(invoiced / collected / refunded, per currency, never summed). Org-wide report → super admins; per-branch
report → that branch's managers (admins excluded so nobody gets two). Each (day, audience) is **claimed**
before sending (`INSERT IGNORE` on `settings` key `daily-report:<date>:<all|branchId>`), so a re-run never
emails twice. Quiet days (all zeros) are stored, not emailed. Emailing can be switched off with
`MAIL_DAILY_REPORT=false`; the digest is still stored.

## Dashboard warm-up

`DashboardService::warm(iterable $viewers)` builds one snapshot per distinct (branch scope, widget set) among
active users, instead of one per user. It does nothing when `app.dashboard_cache_seconds` is 0.

> A snapshot only stays warm for `DASHBOARD_CACHE_SECONDS`. The default (60) is shorter than the 10-minute
> job interval, so on a busy install raise it to about `600`.

## Also changed

- `UserRepository::activeIdsByRole()` and `recipientsByRole(role, ?branchId)`.
- DI: `IntegrityService` singleton; `DailyReportService` factory (scalar `mail.daily_report` flag).
- `resources/views/mail/daily-report.php`.

## Tests

`tests/Feature/AutomationJobsTest.php` — 17 tests: a clean ledger passes; eight corruptions (data provider)
are each caught; refunds > payment and lagging counters; alerts go once per check per day and only to super
admins; draft/void invoices are not mistaken for mismatches; daily report counts, branch scoping, claim
idempotency and emailing; digest-off still stored; warm-up builds one snapshot per distinct scope+widget set;
probes are read-only. Full suite: **834 tests, 2634 assertions**.

## Verification

All three scripts run for real against the dev DB (exit 0): `daily-report` produced 2 reports (quiet day, no
email), `integrity-check` 0 findings, `dashboard-cache` built 1 snapshot. Dev rows from those runs were
removed afterwards.
