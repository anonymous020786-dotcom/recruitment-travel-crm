# Phase 9 · Step 9.2 — Payments, allocations and receipts

Money in. A payment is received from a person, applied to one or more invoices, and always produces a numbered, immutable receipt. Refunds and the outstanding/aging views are Step 9.3. **No migration** — `payments`, `payment_allocations` and `receipts` already existed with their constraints (`amount > 0`, unique idempotency key, one allocation row per payment + invoice).

## What exists
| Layer | Files |
|---|---|
| Model | `Payment` (joined to person; `allocated`, `unallocated()`, method labels) |
| SQL | `PaymentRepository` (branch-scoped, optimistic-locked, search / filters / sort, idempotency lookup, `withCreditForPerson`, `hasOpenRefund`), `PaymentAllocationRepository`, `ReceiptRepository` (**no update/delete method** — receipts are append-only), `InvoiceRepository::lockForPayment / setPaymentState / findByNumber` |
| Rules | `PaymentService`, `PaymentValidator`, `PaymentPolicy`, `payment` state machine (`recorded → reversed`, final) |
| UI | `/payments` register, "Record payment" form per invoice, payment page (allocations, allocate credit, edit reference/notes, reverse), printable receipt, a **Payments** card on the invoice page |

## The integrity rules (all inside one transaction)
- **Idempotent.** The form carries a random token. Submitting the same token twice returns the *original* payment ("nothing was added twice") instead of a second one; if two identical requests race past the lookup, the unique key lets exactly one in and the loser is redirected to it.
- **The invoice is re-read under a row lock** (`SELECT … FOR UPDATE`) before anything is applied, so two payments arriving together are applied one after the other against the true outstanding amount — never against a stale copy.
- **Applied only up to what is owed.** `applied = min(amount, outstanding)`; the rest stays on the payment as **unallocated credit**. Nothing can push `amount_paid` above the invoice total. Allocation additionally requires the **same person** and the **same currency**, and an invoice that is `issued` or `partially_paid`.
- **Exact maths** in integer minor units (`Money`); invoice status follows the money: `net = paid − refunded`; `≤ 0 → issued`, `≥ total → paid`, otherwise `partially_paid`. Each status change is written to the invoice's append-only history with the payment number as the reason.
- **Numbers**: gap-free `PAY-YYYY-NNNNNN` and `RCT-YYYY-NNNNNN` from `number_sequences`, taken inside the transaction so a rolled-back payment never burns one.
- **Receipt = frozen snapshot** (JSON: customer, amount, method, reference, date, allocations with invoice numbers, branch, received-by). Editing a reference or reversing the payment later never rewrites it; the printable page only adds a live **REVERSED** banner.
- **Never edited, never deleted.** Only the reference and notes can be corrected (`payments.edit`; a UPI / card / bank / cheque payment must keep a reference). The amount, method and date are ledger facts.
- **Reversal** (`payments.reverse`, reason required, final): the payment becomes `reversed`; every invoice it had been applied to loses that amount and returns to whatever the remaining money justifies (paid → partially paid → issued), each step in the history. Allocation rows are kept (the ledger stays complete) and simply stop counting. Refused while a refund against the payment is pending / approved / paid — that check is in place now so Step 9.3 only adds the refunds. Once nothing is left paid, the invoice can be voided again.
- **Validation**: amount above zero (max fits DECIMAL(14,2)); method from the fixed list; reference mandatory for non-cash methods; date/time not in the future (furthest-ahead time-zone slack, as elsewhere); token shape checked.

## Permissions
`payments.view / create / edit / reverse`, `allocations.manage`, `receipts.view` — all branch-scoped through the payment. Recording needs `payments.create` and the invoice's branch in scope (not `invoices.view`). The tours desk (`travel` role) can record and allocate but not reverse; `accounts` has everything; `read_only` may view payments and receipts; `counselor` has none.

## Verification
- **15 new service tests** (`PaymentServiceTest`): full payment → paid with receipt snapshot and history; partial payments; overpayment capped with credit and register filter; idempotency; only issued invoices with a balance accept money (draft / paid / void refused); every validator rule; credit allocated to another invoice of the same customer; allocation limits (over the credit, over the balance, other currency, other customer, zero — with the failed attempts changing nothing); stale allocation refused; reversal restoring the invoice step by step and then allowing a void; reversal rules (reason, open refund blocks, final, no allocation after); receipt frozen after edit + reversal and the repository proven append-only by reflection; detail edits (reference kept for UPI, stale, reversed); permissions (`read_only`, `counselor`, the tours desk cannot reverse, another branch sees and can do nothing, receipt view rights, `accounts` end to end); register search by payment / receipt number, customer and reference, filters, sort, and invoice lookup by number.
- Full suite: **739 tests, 2112 assertions, all green.**
- **HTTP smoke test (35 checks)**: pages and prefilled form with a token; UPI without a reference and a zero amount bounce back and record nothing; a payment moves the invoice to partially paid; **re-posting the same token records nothing and credits nothing twice**; payment page, receipt page (reference, customer) and the invoice's Payments card; an over-payment pays the invoice and leaves the credit; allocating more than the credit is refused, allocating the remainder to the second invoice works and clears the credit filter; reversal without a reason refused, then reversal restores both invoices and stamps the receipt REVERSED; reference correction leaves the receipt untouched; search / filters / sort; 404; 419. This run found and fixed a real bug: the allocate endpoint called `InvoiceRepository::findByNumber`, which did not exist (the service tests bypass the controller) — now added and covered by a test.
- All smoke rows were removed afterwards.

## Not in this step
Refunds and their approval flow, aging / outstanding reports and payment-reminder cron (9.3); advance payments recorded without an invoice (today credit only arises from over-payment); payments spanning several invoices in one submission; PDF receipts; CSV export.
