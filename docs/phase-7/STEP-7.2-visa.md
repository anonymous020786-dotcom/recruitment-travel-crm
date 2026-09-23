# Phase 7 · Step 7.2 — Visa applications

`visa_applications` (optimistic `record_version`) + append-only `visa_status_history`. **Migration 0009** adds `is_override` to the history (`schema.sql` kept in sync).

## Pipeline (`config/statuses.php` → `visa`)
`not_started → documents_pending → submitted → under_processing → approved → expired`; `rejected` / `cancelled` reachable from the working states. `rejected`, `expired`, `cancelled` are closed; an actor with `visa.override_status` may reopen one (audited, reason mandatory, `is_override = 1`, audit action `status_overridden`). A legal move flagged as override is *not* recorded as one.

## Rules
- **Start** (`visa.create`): valid active country (`AE`/`ae` normalised); with an application it must belong to the candidate and be `medical_completed` or `visa_processing` — starting from `medical_completed` moves it to `visa_processing`. Sponsor defaults to the employer. One live visa per candidate + application; a rejected/cancelled/expired one can be redone.
- **Status change** (`visa.change_status`): `rejected`/`cancelled`/override need a reason; `submitted` stamps the submission date (default today); **`approved` needs an expiry date after the approval date** (approval date defaults to today) and moves a `visa_processing` application to `visa_approved` in the same transaction. Rejection only notifies the owner — re-apply vs. reject-the-candidate is a human call. Approved/rejected notify the application's owner (or the candidate's counselor).
- **Edit details** (`visa.edit`): only while not closed; stale `record_version` → `StaleRecordException` for details and status alike.
- **Delete** (`visa.delete`): only `not_started`; otherwise cancel.
- Branch scope via the candidate.

## UI
`/visa` register (search by name / CAN / visa no. / reference; status, country, expiry filters; sortable), `/visa/{id}` profile (details, edit form, history timeline, move form with dates, audited override), and a "Visa" card on the candidate profile with a start form.

## Still to come (Step 7.3)
`cron/visa-expiry.php`: expiry-window reminders (180/90/30 days) and flipping lapsed approved visas to `expired`, plus the medical-certificate equivalent, all idempotent via dedupe keys.

## Verification
`VisaServiceTest` (18 tests) — full suite 631 tests / 1485 assertions green. HTTP smoke: bad country refused, start (sponsor defaulted, application → `visa_processing`), illegal `not_started → approved` refused, approval without a valid expiry refused, approval → application `visa_approved`, history rows checked; data cleaned up.
