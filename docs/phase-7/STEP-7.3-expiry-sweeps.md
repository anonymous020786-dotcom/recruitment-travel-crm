# Phase 7 · Step 7.3 — Expiry sweeps (visa, medical, passport)

Closes Phase 7. `ExpiryService` does the work; `cron/visa-expiry.php`, `cron/medical-expiry.php` and `cron/passport-expiry.php` are thin `CronRunner` wrappers (registered in `config/cron.php`; the last two are new/added).

## Reminder windows
Config `cron.reminder_windows`: visa `[180, 90, 30]`, medical `[30, 15, 7]` (new), passport `[180, 90, 30]`.
A record is put in the **smallest window that still contains it**; the notification's dedupe key carries that window (`expiry:visa:<id>:<window>:u<user>`), so:
- a re-run, or running several times a day, never duplicates;
- a record first seen 25 days out gets the "30 days" reminder only — not 180 and 90 as well;
- a missed cron day loses nothing;
- an already-expired record uses a final "expired" bucket (`-1`) and fires once.
The dedupe key is global in `notifications`, hence the per-user suffix.

## Recipients
- **Visa** (approved, expiry set): the application's owner (or the candidate's counselor when there is no application) **+ the visa team of the candidate's branch** (active users with role `visa` attached to that branch — other branches are not told).
- **Medical** (status `fit`): same recipients, but only for candidates who still have a live (not placed/rejected/cancelled) application — old certificates of finished candidates are noise.
- **Passport**: the candidate's counselor, same live-application filter.

## Visa auto-expiry
`expireVisas()` flips approved visas whose expiry date has passed to `expired`. It is a **system write**: no user, so **migration 0010** makes `visa_status_history.changed_by` nullable (history readers already LEFT JOIN users); the row carries the reason "Expired automatically…" and an audit entry. The UPDATE is `WHERE status='approved' AND record_version = :read` so a visa someone touched between read and write is skipped and picked up next run. Idempotent (only matches `approved`).

## Also fixed
`MedicalRepository` search reused the named placeholder `:s_name` twice (PDO native prepares reject that — same bug found earlier in the employer search). Search tests for the medical, visa and interview lists now guard against it.

## Verification
`ExpiryServiceTest` (7 tests): owner + branch visa team once per window, other-branch team not told, new window / expired bucket fire again then stay quiet, out-of-window / non-approved ignored, counselor fallback, system expiry (once, history `changed_by` NULL, version bump, audit), medical and passport live-application filtering + dedupe. Plus 3 listing-search tests. Full suite 641 tests / 1527 assertions green; all four cron scripts execute with exit 0 against the dev DB.
