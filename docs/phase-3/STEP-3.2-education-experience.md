# Step 3.2 — Candidate education & experience

**Status:** implemented; 411 tests green (+19). Continues Phase 3 (Candidates).

**Scope note:** full CRUD (add/edit/delete) for `candidate_education` and
`candidate_experience`, rendered inline on the candidate show page. Neither
table carries its own `record_version` — like `lead_followups`, these are
child rows of an aggregate root (the candidate), so ownership and access
control are enforced by resolving the branch-scoped parent candidate first,
then scoping every child-row query additionally by `candidate_id`. No
optimistic locking is needed at the row level; the candidate's own lock
already protects the aggregate from being deleted/re-branched mid-edit.

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/CandidateEducation.php`, `app/Models/CandidateExperience.php` | Immutable read models for the two child tables. `CandidateExperience::durationLabel()` renders "start – end/Present". |
| `app/Repositories/CandidateEducationRepository.php`, `app/Repositories/CandidateExperienceRepository.php` | `forCandidate()`, `findInCandidate()` (ownership-checked read), `create()`, `update()`, `delete()` — every write is `WHERE id = :id AND candidate_id = :cid`. |
| `app/Validators/CandidateEducationValidator.php` | level required; institution/board/field/grade optional strings; start/end year 1950–2100; end year can't precede start year. |
| `app/Validators/CandidateExperienceValidator.php` | employer/job title required; ISO-2 country uppercased; is_current forces end_date to null; end date can't precede start date. |
| `tests/Unit/Validators/CandidateEducationValidatorTest.php`, `tests/Unit/Validators/CandidateExperienceValidatorTest.php` | Normalization (trimming, year casting, country uppercasing), required-field rejection, cross-field date/year ordering. |

## B. Files modified

- `app/Services/CandidateService.php` — `addEducation`/`updateEducation`/`removeEducation` and the experience equivalents. Each authorizes via `CandidatePolicy::manageEducation`/`manageExperience` (branch-scoped), then the repository call, then an audit entry (`education_added`/`education_updated`/`education_removed`, same for experience).
- `app/Controllers/Crm/CandidateController.php` — `storeEducation`/`updateEducation`/`destroyEducation` + experience equivalents; `show()` now also loads both lists, `canEducation`/`canExperience`, and `countries` (for the experience country `<select>`).
- `resources/views/crm/candidates/show.php` — replaced the "coming next" placeholder with real Education and Experience cards: a compact add-form (gated on the manage permission), a list of existing rows with an inline `<details>` edit form and a confirm-guarded delete form per row.
- `routes/web.php` — `POST/PUT/DELETE /candidates/{candidate}/education[/{education}]` and the same shape for `/experience`, each gated on `candidates.education.manage` / `candidates.experience.manage`.

## C. Migration

None — both tables shipped in `0001_initial_schema.sql`.

## F. Security / correctness

- Every education/experience mutation re-resolves the candidate through the branch-scoped `CandidateController::find()` first (404 outside scope, no existence leak), then authorizes the specific ability against that candidate via `Gate` — permission alone is never enough, branch scope is always re-checked.
- Every repository read/write additionally filters by `candidate_id`, so a row id can never be used to touch another candidate's data even if a request forged a valid education/experience id from a different candidate.
- **Bug found and fixed during manual QA** (not by the automated suite, which always exercises the full field set): `CandidateEducationValidator::validate()` indexed `$clean['start_year']`/`$clean['end_year']` directly in the cross-field year-order check. When an update omits both fields (a real HTTP round-trip against `PUT /candidates/{id}/education/{id}` with only `level`/`institution`/`grade` in the body — a case the validator unit tests didn't cover, since they always pass the full fixture), PHP's undefined-array-key warning is promoted to an `ErrorException` by this app's error handler, producing a 500. Fixed with `?? null` on both reads; confirmed by re-running the same request (302, and the previously-set `start_year`/`end_year` were correctly preserved since `Db::updateRow` only sets the columns present in the validated array).
- Delete forms use the existing `data-confirm` JS convention (already used elsewhere, e.g. passkey removal) — no new client-side code.

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Created a lead, converted to a candidate, opened its show page — Education/Experience cards render empty states
- [x] Added one education row and one experience row via their add-forms → 302 back to `#education`/`#experience`; both rendered with correct fields (institution, board/university, field of study, year range, grade / employer, title, country, duration, responsibilities)
- [x] Edited the education row with a partial field set → **hit the bug above**, fixed, re-verified: 302, fields updated, untouched fields preserved
- [x] Edited the experience row (title change + `is_current`) → 302, updated, "Present" duration label
- [x] Deleted both rows → 302, empty-state text reappeared
- [x] Cleaned up all synthetic lead/candidate/person rows; re-ran the full suite to confirm the leftover smoke-test lead's number (`LEAD-2026-000001`) wasn't left colliding with the test suite's own sequence counter (it briefly did — the suite's `tearDown` resets `number_sequences`, so a stray real lead sharing the first generated number causes a duplicate-key error in the next test run; caught immediately, cleaned up, suite green again)
- [x] Dev admin password re-randomized post-test
- [x] Full suite **411 tests, 944 assertions** (+19 new: 8 service, 4+4 validator unit, plus assertions gained elsewhere)

## I. Performance

- Both child tables are read with a single indexed query per list (`idx_cand_edu_candidate` / `idx_cand_exp_candidate`) — no joins, no pagination needed at realistic per-candidate row counts.
- Every write is a single-row insert/update/delete by primary key; no new query shapes introduced.
