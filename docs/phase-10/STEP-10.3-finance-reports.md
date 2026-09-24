# Phase 10 · Step 10.3 — Finance reports and register exports

Closes Phase 10. Five finance reports join the framework from 10.2 — same screen / print / streamed-CSV behaviour, same filters and caps, same branch scoping — behind a stricter permission. **No migration.**

## Reports
All need `reports.finance.view` (in addition to the base `reports.view`), so `manager` and `accounts` see a **Finance** group; `read_only`, `counselor` and the other roles do not (`definition()` returns null → 404, and the group is absent from the catalogue). CSV needs `reports.export`.

| Report | Shows |
|---|---|
| Collections | Money received per **month × currency × method**: payment count and amount. Only `recorded` payments count — a reversed payment is not money — and refunds are deliberately **not** netted in silently (they have their own report). Currencies are never added together. |
| Payments register | Every payment in the period: received time, payment and receipt numbers, customer, method, reference, currency, amount, **how much has been applied to invoices**, and status (reversed payments stay in the register, flagged). |
| Invoices register | Every invoice raised in the period: number, customer, what it is for and its APP-/TB- reference, issued / due dates, total, **paid, refunded, outstanding**, status. |
| Refunds | Every refund requested in the period: number, customer, payment, invoice (or "credit"), amount, method, status, who requested, who approved, and the reason. |
| Overdue invoices | A **live** list (no date range) of invoices past due that still owe money, oldest first, with days overdue, outstanding and the customer's **phone** — meant for chasing. Named for today's date on download. |

Money is written as plain two-decimal strings (`1250.50`) in screen and CSV so a spreadsheet can sum it. A report with no filter (`filter: none`) hides the filter form, ignores stray `from` / `to`, and prints "as of <date>".

## Links from the registers
The Invoices, Payments and Refunds pages get a **Report / export** button (shown only to viewers who hold `reports.finance.view`) that opens the matching register report, where the period can be chosen and the CSV downloaded. The Invoices page also keeps its **Ageing** view from 9.3.

## Verification
- **5 new tests** (`FinanceReportTest`): who may run the finance reports (manager / accounts yes; read_only / counselor no, and no Finance group); collections per month / currency / method, with a **reversed cash payment excluded** and an out-of-range period empty; the three registers with exact money (a 1,000 invoice part-paid by UPI shows paid 400 / outstanding 600 / Partially Paid; a fully-paid AED invoice; the reversed payment still listed as Reversed; receipt and payment number formats; the refund's currency, method, status and reason); the overdue list as a live snapshot (garbage dates ignored, 10 days overdue, phone present); branch isolation across collections, registers and the overdue list, and a CSV export of the invoices register (header, every row, `600.00`).
- The catalogue test in `ReportServiceTest` now expects all eleven reports for a manager.
- Full suite: **771 tests, all green.**
- **HTTP smoke test (16 checks)**: Finance appears in the catalogue; collections shows the cash receipt by method; the payments and invoices registers list the records with the correct outstanding (700.00); the overdue list shows the past-due invoice and has no date filter; an empty refunds report says so; invoices CSV header and rows, overdue CSV named for today; finance print view; the three registers link to their exports. Rows removed afterwards.

## Phase 10 in one line
The dashboard (10.1) gives the live, cached, permission-aware overview; the report framework (10.2) and the finance reports (10.3) give filterable, printable, streamed-CSV reports — eleven in all — every one branch-scoped, permission-gated, capped, and audited on export.

## Not in this step
Saved / scheduled reports and emailing them (Phase 11 automation), charts on report pages, PDF output, and queuing very large exports to `export_jobs` (the streamed 50 000-row cap covers expected volumes).
