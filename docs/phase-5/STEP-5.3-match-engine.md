# Step 5.3 — Match engine with explainable scoring (closes Phase 5: Employers + Jobs)

**Status:** implemented; 567 tests green (+26). Closes Phase 5.

**Scope note:** on-demand candidate ↔ job scoring, config-driven, with a
per-criterion matched/partial/missing breakdown. Nothing is persisted here —
Phase 6 stores the breakdown on `applications.match_score`/`match_breakdown`
(the engine's `MatchResult::toArray()` is already that JSON shape).

## A. Files created

| File | Purpose |
|---|---|
| `config/matching.php` | The rules: per-criterion weights (skills 40, experience 20, qualification 10, country 10, age 5, gender 5, salary 5, passport 5), the experience partial-credit floor, passport minimum validity, whether a missing mandatory requirement makes a candidate ineligible, pool/result limits. A weight of 0 switches a criterion off. |
| `app/Domain/Matching/MatchEngine.php` | Pure, I/O-free scorer (same idea as `StatusMachine`): plain arrays + config in, `MatchResult` out. |
| `app/Domain/Matching/MatchResult.php` | Score, eligibility, missing-mandatory list, and the criteria rows (key, label, weight, ratio, state, human-readable detail) with `matched()`/`missing()` helpers and `toArray()`. |
| `app/Repositories/MatchProfileRepository.php` | Loads the candidate pool and everything the engine needs in **four queries total** (candidates, skills, preferences, latest passport expiry) — no N+1 on shared hosting. |
| `app/Services/MatchService.php` | Authorization + pools: `rankCandidatesForJob()` and `rankJobsForCandidate()`; eligible candidates rank before ineligible ones, then by score. |
| `app/Controllers/Crm/MatchController.php`, `resources/views/crm/jobs/matches.php`, `resources/views/crm/_match_breakdown.php` | "Matching candidates" page with a "Why this score?" breakdown per candidate. |
| `tests/Unit/Domain/MatchEngineTest.php`, `tests/Feature/MatchServiceTest.php` | 17 pure engine tests + 9 feature tests. |

## B. Files modified

- `bootstrap/services.php` — `MatchEngine` and `MatchService` are built from `config('matching')` (the service takes a config array, so it is registered with a factory rather than left to autowiring, which would silently give it an empty config).
- `app/Repositories/JobRepository.php` (`openJobs()` — open, not past deadline, in scope), `JobRequirementRepository.php` (`forJobs()` — bulk load).
- `routes/web.php` (`GET /jobs/{job}/matches`, `can:jobs.match`), `JobController` + `jobs/show.php` ("Find matches" button), `CandidateController` + `candidates/show.php` (a "Suggested jobs" card, top 5, shown only to users who may match).

## C. Migration

None.

## F. Security / correctness

- **Missing data never distorts a score.** A criterion that cannot be judged for a pair (job states no age range, candidate's date of birth unknown, currencies differ, no requirements listed…) is `na` and removed from *both* numerator and denominator; weights redistribute over what could be judged. Covered by explicit tests (perfect match with an empty requirement list still scores 100). Conversely a criterion that *can* be judged against missing candidate data — no passport on file, experience not recorded when the job needs some — counts as missing, and says so in its detail text.
- Every criterion returns a sentence explaining itself ("Has: Forklift. Missing: Welding (mandatory).", "Passport expires in 9 days (needs 180+)."), and a test asserts none is blank — the "explainable" exit criterion is enforced, not assumed.
- Ranking puts *eligible* candidates (no unmet mandatory requirement) ahead of higher-scoring ineligible ones; a test proves a lower-scoring eligible candidate outranks a higher-scoring ineligible one. Ineligibility can be turned off in config, and the missing-mandatory list is still reported.
- Authorization: matching a job needs `jobs.match` on that job (its branch scope) *and* `candidates.view`; matching a candidate needs `jobs.match`, `jobs.view` and candidate visibility. The candidate pool is branch-scoped in SQL (a candidate in another branch never enters the ranking — tested) and limited to active candidates.
- Age uses completed years (a one-day-short birthday is not counted) — boundary tested.
- The pool is capped (`pool_limit`, default 300, hard max 1000) so a request stays inside shared-host time limits.

## H. Manual QA — verified (over HTTP)

- [x] Open job with a mandatory "Welding" requirement; two candidates (one with the skill + 5 years' experience, one with nothing)
- [x] `/jobs/{id}/matches` → 200; Strong candidate ranked first at **92.3 / 100**, Weak candidate **0.0 / 100** with a red "Missing mandatory: Welding" badge; each has a "Why this score?" breakdown
- [x] Weak candidate's profile shows the "Suggested jobs" card with the job and "missing mandatory: Welding"
- [x] Job page shows "Find matches"
- [x] All synthetic rows/sequences/skill cleaned; admin password re-randomized; **567 tests, 1270 assertions**

## I. Performance

- A ranking is O(pool) in PHP after four bulk queries; candidate-side ranking uses one query for open jobs plus one `IN (…)` query for all their requirements. Nothing is stored, so there is no write cost or cache-invalidation to manage.

## Phase 5 exit criteria — status

- **Match score with matched/missing breakdown:** ✅ (`MatchResult` + the "Why this score?" view; verified end to end)
- **Job status machine enforced:** ✅ (Step 5.2 — illegal moves refused at service level and over HTTP; concurrent movers get one winner)
- Also delivered: employer profile + contacts (5.1), requirements/benefits, public flag + slug (auto-unpublish rules) (5.2).
