# Step 4.2 — Document verify / reject / expire workflow

**Status:** implemented; 482 tests green (+11). Continues Phase 4 (Documents).

**Scope note:** covers §11.4 — `pending → uploaded → under_review → verified |
rejected`, `verified → expired` (cron only). Reuses the existing
`App\Domain\StatusMachine` engine (already driving lead status transitions)
rather than hand-rolling a second transition checker, so the same
`DomainRuleException` shape and override semantics apply everywhere in the
app. The per-candidate document checklist is the next Phase 4 slice.

## A. Files created

| File | Purpose |
|---|---|
| `cron/document-expiry.php` | Daily sweep (matches the schedule already reserved for it in `config/cron.php`): notifies the candidate's counselor when a verified document's expiry lands on one of `config('cron.reminder_windows.document')` = `[30, 15, 7, 1]` days out (deduped per document+bucket via `NotificationRepository`'s dedupe key, so each window fires exactly once), then flips documents already past expiry to `expired`. |
| (test coverage added to) `tests/Feature/DocumentServiceTest.php` | `startReview`, `verify` (from `uploaded` and from `under_review`), `reject` (empty-reason rejection, reason persisted), invalid-transition rejection (verifying an already-verified or already-rejected document), stale-version rejection, permission denial, `expireDue()` (only touches past+verified, second run is a no-op). |

## B. Files modified

- `config/statuses.php` — added the `document` entity's transition table.
- `app/Services/DocumentService.php` — `startReview()` (either `documents.verify` or `documents.reject` may pick a document up for review), `verify()` (sets `verified_by`/`verified_at`, clears any prior `rejection_reason`), `reject()` (requires a non-empty reason, clears `verified_by`/`verified_at`), `expireDue()` (cron entry point). All three share a private `transitionTo()` that asserts the move via `StatusMachine`, then does the same optimistic-locked update + audit + re-fetch shape every other write in this app uses.
- `app/Repositories/CandidateDocumentRepository.php` — `markExpiredBefore()` (idempotent: only ever matches `status = 'verified'`) and `dueForExpiryReminder()` (verified documents due within the widest configured window, joined to the candidate's assigned counselor for the notification target).
- `app/Controllers/Crm/DocumentController.php` — `startReview`/`verify`/`reject` actions. `startReview`'s route carries no `can:` middleware (the ability is "verify OR reject", which the single-permission middleware can't express) — the real check happens inside `DocumentService::startReview()`, same defense-in-depth split the rest of the app uses between a coarse route filter and the service being the actual authority.
- `app/Controllers/Crm/CandidateController.php` — `show()` now also passes `canVerifyDocument`/`canRejectDocument`; timeline label map gained `document_review_started`/`document_verified`/`document_rejected`.
- `resources/views/crm/candidates/show.php` — per-document "Start review"/"Verify" buttons and a "Reject" form (reason field required client-side, enforced server-side), all conditioned on the document still being in `uploaded`/`under_review`.
- `routes/web.php` — `POST /candidates/{candidate}/documents/{document}/{review,verify,reject}`.

## C. Migration

None — the verification columns (`verified_by`, `verified_at`, `rejection_reason`, `record_version`) shipped with `candidate_documents` in `0001_initial_schema.sql`.

## F. Security / correctness

- Every transition goes through `StatusMachine::assert('document', $from, $to)` before any write — verifying a `rejected` document, or re-verifying an already-`verified` one, throws `DomainRuleException` and never reaches the database. Verified both at the unit level and over HTTP: attempting to verify a document already in `rejected` status left its status untouched.
- `verify()`/`reject()`/`startReview()` are all optimistic-locked on `record_version`, exactly like every other write in this app — two reviewers racing to close the same document get one winner and one `StaleRecordException`, never a silent overwrite (T20 in the threat model explicitly calls out `documents (verification)` for this).
- `reject()` requires a non-empty, trimmed reason — enforced in the service, not just the view, so a request built by hand still can't skip it.
- The cron notification path is fully deduped by `docexp:<document_id>:<days>` — re-running the job the same day (or after a crash/retry) never double-notifies, and each of the four configured windows fires exactly once per document as its expiry approaches, matching the exact idempotency-key shape docs/00-ARCHITECTURE.md's cron table specifies for this job.
- `expireDue()` only ever matches `status = 'verified'` — an already-`expired` row, a `rejected` one, or one still `uploaded`, is never touched by the cron, so running it twice (or hourly by mistake) is a safe no-op after the first pass.

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Uploaded a document → "Start review" → status flips to "Under review"
- [x] "Verify" from `under_review` → status flips to "Verified"
- [x] Uploaded a second document, attempted "Reject" with a blank reason → rejected client-and-server-side, document stayed `uploaded`; resubmitted with a reason → status flips to "Rejected", reason rendered on the card
- [x] Attempted to verify the now-`rejected` document → silently refused (redirect, no status change) — confirmed the row's status stayed `rejected` in the database, not just that the response looked like success
- [x] Full timeline for the candidate showed all five events in order: uploaded → started reviewing → verified (first doc), uploaded → rejected (second doc)
- [x] `php cron/document-expiry.php` runs cleanly (exit 0) against the real dev database with no due documents
- [x] Mid-session MySQL crash (a known instability of this sandboxed dev environment, unrelated to this step's code) recovered cleanly: restarted `mysqld`, re-verified the in-flight test data was intact via InnoDB's crash recovery, and continued the same manual QA session without any data loss
- [x] Cleaned up all synthetic lead/candidate/person/document/access-log rows; re-ran the full suite to confirm no leftover state
- [x] Dev admin password re-randomized post-test
- [x] Full suite **482 tests, 1070 assertions** (+11 new)

## I. Performance

- All three transitions are a single indexed `UPDATE ... WHERE id = ? AND record_version = ?` plus one re-fetch by primary key — identical shape to every other optimistic-locked write in the app.
- `dueForExpiryReminder()` and `markExpiredBefore()` both use `idx_cand_docs_expiry` (on `expires_at`) combined with the `status = 'verified'` filter — no new indexes needed.
