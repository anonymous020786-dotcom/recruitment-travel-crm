# Phase 10 · Step 10.2 — Reports (recruitment, compliance, travel, tours)

The reporting framework and the first six reports. Finance reports and the register exports follow in 10.3. **No migration.**

## Framework
`ReportService` owns a small catalogue — each report is a title, a group, the permission needed, the filter it takes (`range` = from / to dates, `days` = an expiry window), its column headings and which columns are numeric — and a **streaming row source** in `ReportRepository` (`Db::cursor` → `Generator`, so nothing is buffered). The screen, the print view and the CSV all read the same rows, so what you see is exactly what you download.

- **Permissions**: the catalogue only lists — and the report route only opens — reports the viewer's role allows (`definition()` returns null otherwise → 404). Everything is **branch-scoped**. CSV additionally needs `reports.export` and is rate-limited by the existing `export` bucket (10 per hour per user); screens use the `dashboard` bucket.
- **Filters**: a range defaults to the last 90 days; dates must be real and in order, and may span at most five years. An expiry window is 1–365 days (default 60). A bad value redirects back with a message instead of failing. A report that takes a window ignores stray `from`/`to`.
- **Screen**: first 500 rows (`VIEW_ROWS`), with a clear "the list is cut off" notice that points at the CSV (or at narrowing the dates when the viewer cannot export); exactly 500 is not reported as truncated.
- **Print** (`?print=1`): a standalone, navigation-free page with the period, who generated it and when, and a Print button that disappears on paper.
- **CSV** (`/reports/{report}/csv`): streamed straight to the response (`Response::stream`), header first, capped at 50 000 rows (`EXPORT_ROWS`), `Cache-Control: private, no-store`, downloaded as `report-<key>-<from>-<to>.csv`. Every cell goes through `Csv::writeRow`, which neutralises spreadsheet formula injection (a lead source named `=…` is written as text). Each export is written to the audit log with the report, filters and row count.

## The reports
| Report | Needs | Answers |
|---|---|---|
| Lead sources | `leads.view` | Per source: leads created in the period, how many converted, conversion % |
| Applications by employer | `applications.view` | Per employer: applications made in the period and whether they are live / placed / rejected / cancelled |
| Placements | `travel.view` | Each candidate placed in the period: employer, job, country, salary, contract end, status |
| Expiring visas, medicals and passports | `candidates.view` | What expires within N days, soonest first, with days left |
| Flights | `travel.view` | Flights departing in the period: route, airline, PNR, ticket status |
| Tour bookings by package | `tours.bookings.view` | Per package **and currency**: bookings by outcome and the revenue of those going ahead (confirmed / travelling / completed) |

The expiry report is assembled from three tables with a `UNION ALL` (each part with its own placeholder names, since a named placeholder cannot repeat in one statement) and only queries the kinds the viewer may see: visas need `visa.view`, medical certificates `medical.view`, passports `candidates.view`. Money is never summed across currencies.

## Verification
- **6 new tests** (`ReportServiceTest`): catalogue by role (manager sees all six; accounts does not see tours or flights; unknown / not-allowed → null); filter defaults and every rejection (bad date, reversed range, > 5 years, bad / out-of-range window, ignored dates on a window report); each report against a realistic branch-A dataset with branch-B rows that must not appear — lead-source conversion (incl. a source named `=…`), employer split, placements (with the date range excluding them), flights, expiring documents ordered soonest-first and narrowed by the window, tour revenue (2 travellers × 1,000 confirmed, second booking still in the pipeline); the expiry report's per-role kinds checked across **every** role; the 500-row cap semantics; and the CSV — header, all rows, the `'=` neutralisation, the cap, and refusal of an unknown report.
- Full suite: **766 tests, all green.**
- **HTTP smoke test (22 checks)**: catalogue grouping; screen with pre-filled filters and the print / CSV buttons; the days filter on the expiry report; an empty period says so; unknown report 404; reversed range and garbage date redirect cleanly; print view renders the same rows with no navigation; the CSV returns 200 as `text/csv`, named attachment, `no-store`, header then data rows; the export appears in the audit log; an empty report still returns its header; an anonymous request is refused. Rows removed afterwards.

## Not in this step
Finance reports (collections by month and method, refunds, ageing export) and CSV exports of the invoice / payment registers (10.3); queuing very large reports to `export_jobs` (the 50 000-row streamed cap covers the sizes this system expects — the queue path already exists for leads if a bigger report ever needs it); saved report filters; charts on report pages.
