# Step 3.4 — Candidate passports & expiry

**Status:** implemented; 441 tests green (+12). Continues Phase 3 (Candidates).

**Scope note:** full CRUD for `passports`, plus computed expiry status
(expired / expiring soon / valid) rendered as a badge — the "passport expiry
windows computed" line from Phase 3's exit criteria. A candidate may hold more
than one passport record (renewal in progress, dual nationality); the schema
allows several rows per candidate but only ever wants one flagged
`is_primary` — that invariant is enforced by the service, not a DB constraint.

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/Passport.php` | Read model with `daysUntilExpiry()`, `isExpired()`, `isExpiringSoon(int $withinDays = 180)` — pure date arithmetic against `expiry_date`, no query needed. |
| `app/Repositories/PassportRepository.php` | `forCandidate()` (primary first, then soonest expiry), `findInCandidate()`, `numberTaken()` (checks the *global* `uq_passports_number` constraint pre-write so a collision surfaces as a friendly validation error, not a raw DB integrity exception), `clearPrimaryExcept()` (the single-primary enforcement). |
| `app/Validators/PassportValidator.php` | passport number required, alphanumeric 4–30 chars, uppercased; expiry can't precede issue date; `held_by` one of candidate/agency/employer/embassy (defaults to candidate). |
| `tests/Unit/Validators/PassportValidatorTest.php` | Normalization, required/format rejection, date-order rejection, blank-to-null/default handling — written after the Step 3.2/3.3 empty-string lesson, so this validator had no such bug on first run. |

## B. Files modified

- `app/Services/CandidateService.php` — `addPassport`/`updatePassport`/`removePassport`. Each authorizes `managePassport`, pre-checks the passport-number uniqueness (friendly `ValidationException`, not a caught `PDOException`), and — inside the same transaction as the write — calls `clearPrimaryExcept()` first when the incoming row is flagged primary, so "add a new primary" and "the old primary loses the flag" happen atomically.
- `app/Controllers/Crm/CandidateController.php` — `storePassport`/`updatePassport`/`destroyPassport`; `show()` now also loads `passports` and `canPassport`.
- `resources/views/crm/candidates/show.php` — a "Passports" card: add-form, list with a colour badge per row (`red`/"Expired", `amber`/"Expiring soon" within 180 days, `green`/"Valid") plus an `indigo` "Primary" badge, and the same inline edit/delete pattern as education/experience.
- `routes/web.php` — `POST/PUT/DELETE /candidates/{candidate}/passports[/{passport}]`, gated on `candidates.passport.manage`.

## C. Migration

None — `passports` shipped in `0001_initial_schema.sql`.

## F. Security / correctness

- Same ownership model as the rest of Phase 3: the branch-scoped candidate is resolved first, `managePassport` is authorized against it, then every repository call additionally scopes by `candidate_id` — except the uniqueness check, which deliberately spans the whole table (a passport number is a real-world unique document, not scoped to one candidate).
- The primary-passport invariant is enforced transactionally: `clearPrimaryExcept()` and the insert/update happen inside one `Db::transaction()` call, so a crash between the two steps can't leave two passports simultaneously marked primary. Verified over HTTP: added a primary passport, added a second as primary, confirmed the first's checkbox flipped to unchecked and exactly one row had `is_primary = 1` in the database.
- Verified the duplicate-number path end-to-end: submitting an already-used passport number for a different candidate is rejected with a friendly error and does **not** insert a row (checked via a direct row count, not just the redirect status), for both the service-level test and a real HTTP round-trip.
- No validator bugs surfaced this time — `PassportValidatorTest` explicitly covers the blank-string-vs-null case that bit `CandidateSkillValidator` and `CandidatePreferencesValidator` in Steps 3.2/3.3, and it passed on the first run.

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Added a primary passport with an expiry ~90 days out → 302, rendered with "Primary" + "Expiring soon" badges
- [x] Added a second passport also flagged primary → 302; the first's primary flag cleared (checkbox unchecked on reload), exactly one `is_primary = 1` row in the database; second passport's past expiry date rendered "Expired"
- [x] Attempted to add a passport reusing the first passport's number → rejected, row count unchanged (still 2, not 3)
- [x] Deleted both passports → empty-state text reappeared
- [x] Cleaned up all synthetic lead/candidate/person/passport rows; re-ran the full suite to confirm no leftover state
- [x] Dev admin password re-randomized post-test
- [x] Full suite **441 tests, 1004 assertions** (+12 new: 6 service tests, 6 validator unit tests)

## I. Performance

- List query uses `idx_passports_candidate`; the global uniqueness pre-check uses `uq_passports_number`, both existing indexes — no new query shapes.
- `clearPrimaryExcept()` is a single indexed `UPDATE`, only run when the incoming row is actually flagged primary (no wasted write on every save).
