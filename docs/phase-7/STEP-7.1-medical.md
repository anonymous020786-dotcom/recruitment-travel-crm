# Phase 7 · Step 7.1 — Medical records

A candidate's medical exam, optionally tied to an application. Table `medical_records` (no schema change).

## Lifecycle (`config/statuses.php` → `medical`)
`pending` → `scheduled` (an appointment date is set) → `completed` (attended, report awaited) → `fit` | `unfit` | `retest`.
A walk-in may go `pending → completed`. `fit` / `unfit` / `retest` are final for the record — a **retest is a new record**.

| Action | Rule |
|---|---|
| Book (`medical.create`) | one exam in progress per candidate + application (general medicals have no application); application must belong to the candidate and be open |
| Change appointment (`medical.edit`) | only before the exam; a scheduled medical must keep a date |
| Mark attended | date not in the future |
| Record result | from `scheduled`/`completed`; report date defaults to today, not future; **fit** gets `expires_at` (given, or report date + `MedicalService::DEFAULT_VALIDITY_DAYS` = 90); unfit/retest store no expiry |
| Delete (`medical.delete`) | only `pending`/`scheduled` |

**Application link:** *fit* on an application that is `medical_pending` moves it to `medical_completed` in the same transaction (`ApplicationService::advance`). *unfit* / *retest* leave the application alone and notify its owner — what to do with the candidate stays a human decision.

Writes to an in-progress exam are guarded (`UPDATE … WHERE status IN ('pending','scheduled','completed')`) so a concurrent verdict can't be overwritten. Branch scope comes from the candidate. Every action is audited (`medical` module).

## UI
- "Medical" card on the candidate profile: book form (optionally against an open application), appointment / attended / result forms, expiry badge (valid / expiring ≤ 30 days / expired).
- `/medical` register (new sidebar item): search (name, CAN-…, centre), status, certificate filter (expiring in 30 days / expired), sortable.

## Not yet
Expiry reminders (notify the owner as a certificate nears expiry) ship with the visa-expiry work in Step 7.3; attaching the certificate file (`certificate_document_id`) will reuse the documents pipeline.

## Verification
`MedicalServiceTest` (13 tests): booking states, one-open rule, foreign/closed application, happy path + default expiry + application advance, explicit expiry, unfit notification, retest finality, result-before-booking refused, reschedule rules, delete rules, permissions/branch scope, expiring/expired listing, validator rules. Full suite 613 tests green. HTTP smoke: book → attended → invalid result refused → fit → application `medical_completed`; data cleaned up.
