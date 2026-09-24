# Phase 9 · Step 9.3 — Refunds, receivables ageing and payment reminders

Closes Phase 9 (Finance). Three parts: money going **out** (refunds with an approval flow), the **outstanding** picture (ageing + biggest debtors), and the **overdue-invoice reminder** cron.

## Refunds
`pending → approved → paid`, or `rejected` from pending / approved (`config/statuses.php` → `refund`; `paid` and `rejected` are final). **Migration 0014** adds `refund_status_history` (append-only, mirrored in `schema.sql`) — a rejection's reason lives there because `refunds.reason` is the reason the refund was *requested*.

**A refund can never exceed what is actually there.** Requested from a payment, it is taken either from an **invoice the payment was applied to** or from the payment's **unallocated credit**:
- invoice refund cap = what that payment applied to that invoice − refunds already reserved against that pair;
- credit refund cap = payment amount − allocated − credit refunds already reserved.
Pending, approved and paid refunds all *reserve* their amount (a rejected one gives it back). The payment row is locked while the cap is checked, so two requests cannot both take the last of the money. A pending credit refund also immediately shrinks what can still be allocated (`Payment::unallocated()` now subtracts it).

**Separation of duties.** Whoever requested a refund cannot approve it. A one-person office can switch this off with `FINANCE_ALLOW_SELF_APPROVAL=1` (`config/finance.php`); approvals are audited either way. `accounts` may request and pay out; approval (`refunds.approve`) is the manager's.

**Money moves only at `paid`.** Marking a refund paid raises the invoice's `amount_refunded`; because outstanding = total − (paid − refunded), the debt reopens, and the invoice steps back from paid → partially paid → issued (each step in the invoice history: "Refund RF-… paid"). `amount_paid` is left alone — the ledger keeps what was received and the refund sits beside it. A credit refund touches no invoice. Refunding more than an invoice ever received is refused.

**Interplay with payments.** A payment with a pending / approved / paid refund cannot be reversed (the check added in 9.2 now has data behind it); reject the refund first and the reversal is allowed again. Both status rules now live in one place, `Invoice::statusFor()`, used by payments and refunds.

Notifications: managers of the branch are told when a refund needs approval; the requester is told when it is approved, rejected or paid (never the person who did it). UI: `/refunds` register with status tiles, a refund page with approve / reject / mark-paid forms and history, and on each payment page a **Refunds** list plus a **Request a refund** form whose "take it from" list offers exactly the invoices the payment was applied to (and the credit, when there is some).

## Receivables ageing (`/invoices/aging`)
`InvoiceRepository::aging()` — per currency (never mixed), issued / partly-paid invoices with a balance, bucketed by days past due: **not yet due · 1–30 · 31–60 · 61–90 · 90+**, with counts and totals. `topDebtors()` lists who owes most (owes / of which overdue / oldest due date). Both are branch-scoped; the invoices register gets an **Ageing** button and its existing *Overdue* filter is the drill-down.

## Overdue reminders (`cron/payment-reminders.php`, daily 09:00 in `config/cron.php`)
`PaymentReminderService` reminds the **person who raised the invoice** and the **accounts team of its branch** about every issued / partly-paid invoice past due that still owes money — then **again each further week** (bucket = whole weeks overdue, capped at `finance.reminder_max_repeats`, default 8), never more often. The dedupe key carries invoice + bucket + recipient (notification keys are global), so the job can run as often as you like: same-day re-runs and the days between weeks add nothing. Paid, void, draft and not-yet-due invoices are ignored. (The architecture doc specified a single reminder per invoice; weekly repeats are a deliberate improvement — an unpaid invoice should not go quiet after one day. Creating a follow-up *task* is left for Phase 11 with the other automation.)

## Verification
- **16 new tests** (`RefundServiceTest`): request → pending with history, audit and manager notification; the caps (invoice reservation, exact remainder, rejection frees it); credit refunds reserving credit and limiting allocation; validators; reversed payment refused; requester ≠ approver, and the self-approval switch; paying a refund reopens the invoice (paid → partially paid, refund-everything → issued and payable again) with invoice history; credit refunds leave invoices alone; rejection needs a reason and is final; stale versions; an open refund blocks payment reversal until rejected; permissions (`read_only`, `counselor`, `accounts` cannot approve, other branch); register search / filters / counts; ageing buckets and top debtors (with a part-paid, a fully paid and a draft invoice that must not be miscounted); reminder recipients, per-invoice notification content and link, same-day idempotency and the weekly repeat.
- Full suite: **755 tests, 2225 assertions, all green.**
- **HTTP smoke test (34 checks)** with two admins: zero and over-cap refunds refused; a valid one goes pending and moves no money; a second request that would exceed what is left is refused; the requester cannot approve, the other admin can; the payment cannot be reversed while the refund is open; mark paid reopens the invoice (partially paid, 400.00 refunded) with three history rows; a rejection needs a reason and moves no money; register, filters, 404; ageing page with buckets and debtor; overdue filter; **the real cron script** exits 0, creates its reminder, creates no duplicate on a second run, and is recorded in `cron_runs`; 419 unauthenticated. Rows removed afterwards.

## Phase 9 in one line
Invoices (9.1) → payments, allocations, receipts (9.2) → refunds, ageing, reminders (9.3): exact integer money, row-locked and idempotent writes, immutable receipts, append-only histories, optimistic locking, and every mutation audited.

## Not in this step
Finance *reports* and CSV/print exports (Phase 10 — `reports.finance.view`, `invoices.export`), advance payments without an invoice, payments spanning several invoices in one submission, PDF invoices / receipts, refund-to-original-method reconciliation against a bank feed, and the reminder's follow-up task.
