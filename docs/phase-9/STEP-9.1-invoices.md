# Phase 9 · Step 9.1 — Invoices

First slice of Finance. Phase 9 is split: **9.1 invoices** (this step), 9.2 payments + allocations + receipts, 9.3 refunds + outstanding/aging + payment reminders. Nothing here moves money yet — an invoice is the claim; payments (9.2) settle it.

## What exists
| Layer | Files |
|---|---|
| Money | `App\Support\Money` — exact arithmetic on integer minor units (paise/cents). No float error, and no dependency on `bcmath` (which shared hosting may lack). Amounts travel as 2-decimal strings. |
| Model | `Invoice` (joined to the person and to its application / booking; `outstanding()`, `isOverdue()`) |
| SQL | `InvoiceRepository` (branch-scoped, optimistic-locked, search / filters / sort, `summary()` per currency), `InvoiceLineRepository`, `InvoiceHistoryRepository` (append-only — no update/delete method) |
| Rules | `InvoiceService`, `InvoiceValidator`, `InvoicePolicy`, `invoice` state machine |
| UI | `/invoices` register with a money summary, create / edit draft, invoice page, issue and void; an **Invoices** card on the application page and the tour-booking page |

**Migration 0013** adds `invoice_status_history` (mirrored in `schema.sql`). The `invoices` / `invoice_lines` tables already existed with their `CHECK` constraints.

## What an invoice is
Billed to a **person** for an **application** (recruitment service fee), a **tour booking**, or "other" (the polymorphic link from the architecture: `invoiceable_type` / `invoiceable_id`, validated in the service). An application or booking can carry several invoices (instalments). Rejected/cancelled applications and cancelled bookings cannot be invoiced. The invoice inherits the target's branch and person; the currency defaults to the booking's, else INR.

**Totals are never trusted from the form.** They are derived server-side from the lines, in minor units:
`line = round_half_up(quantity × unit price)`, `subtotal = Σ lines`, `grand = subtotal − discount + tax`. The discount cannot exceed the subtotal. Limits (quantity ≤ 100 000, unit price ≤ 99 999 999, ≤ 40 lines, grand ≤ DECIMAL(14,2)) keep every product inside a 64-bit int.

## Lifecycle
```
draft ─▶ issued ─▶ partially_paid ─▶ paid        (payments move it back and forth in 9.2)
  │         │            │
  └─────────┴────────────┴──▶ void  (final)
```
- **Draft** — lines, discount, tax, due date, currency, notes are editable (optimistic-locked; a stale form changes nothing, not even the lines). A draft may be empty.
- **Issue** — needs at least one line and a total above zero; sets `issued_on` = today and `due_on` (given, else the draft's, else `DEFAULT_TERMS_DAYS` = 15 days; never in the past). From here the amounts are frozen.
- **Void** — needs a reason (kept in the history); refused once any money has been received (`amount_paid > 0`) — reverse the payments first. Final.
- Every move is asserted against the machine, written with `WHERE record_version = :seen`, appended to the history and audited in one transaction.
- **Numbers** (`INV-YYYY-NNNNNN`) come from the gap-free `number_sequences` counter *at creation*, so a voided draft still owns its number and the series has no holes.

`Invoice::outstanding()` = grand − (paid − refunded), never negative; `isOverdue()` = collectible status, due date before today, and something still owed. The register's summary is grouped **per currency** (adding INR to AED would be meaningless): billed, collected, outstanding, overdue — issued invoices only.

## Permissions
`invoices.view` / `create` / `edit` / `void`; issuing needs `create` (it creates a binding document). Branch-scoped through the invoice's branch. The `accounts` role can raise invoices for any application or booking in its branch **without** holding `applications.view` / `tours.bookings.view`: the create form asks for the application/booking *number* and resolves it within the branch scope, and the booking/application pages link there pre-filled (a booking's trip and total arrive as the first line).

## Verification
- **15 unit tests** (`MoneyTest`): rounding half up, round-trips, the `0.1 + 0.2` trap, rejection of non-numeric input, line totals.
- **16 feature tests** (`InvoiceServiceTest`): totals from lines (with discount and tax), per-line rounding, tour-booking person/currency, closed targets refused, empty drafts, every validator rule, discount cap, update replacing lines, stale edits (lines untouched too), issue freezing and dating, issue preconditions, void with reason / finality / refusal after money, outstanding and overdue maths (including refunds and paid), permissions (`read_only`, `counselor` denied; another branch denied and cannot load; `accounts` works end to end), and the register (search by number / customer, status / type / overdue filters, per-currency summary, lookups, branch scoping).
- Full suite: **724 tests, 1988 assertions, all green.**
- HTTP smoke test (32 checks): pages and sidebar; create form prefilled from a booking; an unknown reference and a price-less line bounce back and create nothing; create → totals derived (54,501.25) → edit → stale edit refused; cards appear on the application and booking pages; issue → frozen (edit redirects) → void refused without a reason, accepted with one → three history rows; search / filters / sort; 404; 419. Smoke rows removed.

## Not in this step
Recording payments, allocations and receipts (9.2), refunds and the outstanding / aging views and payment-reminder cron (9.3), print / PDF invoice view, CSV export (`invoices.export` exists as a permission), tax as a percentage, and invoice numbering per branch.
