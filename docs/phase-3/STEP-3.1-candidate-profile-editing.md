# Step 3.1 — Candidate profile editing

**Status:** implemented; 392 tests green (+8). Opens Phase 3 (Candidates).

**Scope note:** this step covers editing the identity/contact/recruitment
fields the candidate already carries (from `persons` + `candidates`) and
counselor assignment. It deliberately does **not** add a way to edit
`candidates.stage` — `docs/01-DATABASE.md` documents `stage` as a denormalized
pipeline snapshot the system derives from other modules' state (applications,
documents, visa progress), not a field a user sets directly. Education,
experience, skills, preferences, passport(s) and the candidate timeline are
follow-up slices of Phase 3.

## A. Files created

| File | Purpose |
|---|---|
| `app/Policies/CandidatePolicy.php` | `view`/`update`/`delete`/`export`, each requiring the base permission plus branch scope (or `candidates.view_all` for cross-branch read). `manageEducation`/`manageExperience`/`manageSkills`/`managePreferences`/`managePassport` are forward-declared for the next Phase 3 slices — their permissions were already seeded in Phase 1. |
| `app/Validators/CandidateValidator.php` | Validates the combined person+candidate field set (name, gender, DOB in the past, phone format, email, ISO-2 country codes uppercased, marital status, qualification, experience years 0–60). |
| `app/Services/CandidateService.php` | `updateProfile()` — one transaction across `persons` (identity) and `candidates` (recruitment fields), optimistically locked on `candidates.record_version` since a person row has no version of its own. `reassignCounselor()` — validates the counselor is active and in-branch (or org-wide) before the optimistic-locked update. Both audit-log before/after snapshots. |
| `tests/Feature/CandidateServiceTest.php` | Builds a real candidate via `LeadService::convert()` (candidates have no direct-creation path by design), then covers: successful two-table update, stale-version rejection, permission denial, cross-branch denial, counselor reassignment (valid, unassign via null, out-of-branch rejection, stale-version rejection). |
| `resources/views/crm/candidates/edit.php` | Identity / Contact / Recruitment cards, same `component('field', ...)` pattern as the lead edit form, `record_version` carried as a hidden field for optimistic-lock feedback. |

## B. Files modified

- `app/Repositories/CandidateRepository.php` — `updateFields()` (optimistic `UPDATE ... WHERE record_version = :ver`, mirrors `LeadRepository::update`); `assignableCounselors()` (active users in scope, org-wide-aware — same shape as `LeadRepository::assignableUsers`).
- `app/Repositories/PersonRepository.php` — `update()` (plain keyed update; `persons` has no version column, so it's protected only by being called inside the candidate's locked transaction).
- `app/Controllers/Crm/CandidateController.php` — `edit()`, `update()`, `reassignCounselor()`; `show()` now also passes `counselors` and `canEdit`.
- `resources/views/crm/candidates/show.php` — "Edit" button (gated on `canEdit`), a "Counselor" card mirroring the lead show page's "Assignment" card.
- `routes/web.php` — `GET /candidates/{candidate}/edit`, `PUT /candidates/{candidate}`, `POST /candidates/{candidate}/counselor`.
- `bootstrap/services.php` — registers `CandidateService` and the `Candidate` → `CandidatePolicy` gate binding.

## C. Migration

None — every touched column and the full `candidates.*`/`persons.*` permission catalogue already existed.

## F. Security / correctness

- `CandidateService::updateProfile`/`reassignCounselor` both call `Gate::allows('update', $candidate)` before touching the database — permission (`candidates.edit`) and branch scope are checked together, not just the raw permission string.
- Two-table write is one DB transaction; the candidate row is the sole version-of-record (`record_version`), so a concurrent edit anywhere in the request is caught by the same `WHERE record_version = :ver` pattern used everywhere else in the app — a losing writer gets `StaleRecordException`, not a silent overwrite.
- `reassignCounselor` re-validates the target user server-side (`is_active`, `is_org_wide`, `primary_branch_id`, or `user_branches` membership) — the `<select>` options are filtered client-side too, but the server never trusts the submitted id.
- Both controller actions only accept the allowlisted field set (`fieldKeys()`); no mass assignment from the request body.
- HTTP-level check: a bad `record_version` and an invalid submission (empty name, malformed phone) both redirect back to the **edit** form, not the show page, and neither corrupted the stored profile (verified below).

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Created a lead, converted it to a candidate, opened `/candidates/{id}` (200) and `/candidates/{id}/edit` (200)
- [x] Submitted a profile update (name, email, marital status, qualification, experience) → 302 to the show page; all fields persisted; `record_version` 1 → 2
- [x] Counselor reassignment → 302 back to show; "Currently: Dev Admin" rendered, `record_version` 2 → 3
- [x] Stale `record_version` on a subsequent edit submit → redirected back to `/edit`, profile **unchanged**
- [x] Invalid submission (blank name, malformed phone) → redirected back to `/edit`, profile **unchanged**
- [x] Cleaned up all synthetic lead/candidate/person rows afterward; dev admin password re-randomized post-test
- [x] Full suite **392 tests, 914 assertions** (+8 new)

## I. Performance

- `updateProfile` is two single-row writes (`persons` by PK, `candidates` by PK + version) inside one transaction, plus the same re-fetch every other service does after a write — no new query shapes, no joins added to the hot paths.
- `assignableCounselors()` reuses the exact indexed lookup (`is_active`, `primary_branch_id`, `user_branches`) already proven at scale for lead assignees in Step 2.9.
