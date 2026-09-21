# Step 3.3 — Candidate skills & preferences

**Status:** implemented; 429 tests green (+18). Continues Phase 3 (Candidates).

**Scope note:** skills use a shared global catalogue (`skills`) behind a
many-to-many pivot (`candidate_skills`), matching how `persons` is shared
across leads/candidates — the same skill name is never duplicated in the
catalogue. Preferences are a single row per candidate (`candidate_preferences`,
PK = `candidate_id`), so "add" and "edit" are the same upsert operation, unlike
education/experience/skills which are proper lists.

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/CandidateSkill.php` | Read model for one `candidate_skills` row joined to `skills` (name, category, proficiency, years). |
| `app/Models/CandidatePreferences.php` | Read model for the single preferences row; decodes the two JSON list columns defensively (`decodeList()` copes with a null/empty/malformed value by returning `[]`). |
| `app/Repositories/SkillRepository.php` | `findOrCreateByName()` — dedupes the global catalogue by name, same pattern as `PersonRepository::findOrCreate()`. `search()` for future typeahead use. |
| `app/Repositories/CandidateSkillRepository.php` | `forCandidate()`, `attach()` (`INSERT ... ON DUPLICATE KEY UPDATE` on the composite `(candidate_id, skill_id)` key — re-adding the same skill name updates proficiency/years instead of erroring), `detach()`. |
| `app/Repositories/CandidatePreferencesRepository.php` | `find()`, `upsert()` (`INSERT ... ON DUPLICATE KEY UPDATE` built generically from the data array, same idiom `Seeder` already uses for idempotent seeding). |
| `app/Validators/CandidateSkillValidator.php` | skill name required; proficiency one of basic/intermediate/advanced/expert (defaults to intermediate); years 0–60. |
| `app/Validators/CandidatePreferencesValidator.php` | `preferred_countries`/`preferred_job_titles` arrive as comma-separated text (no multi-select widget in this framework) and are split/validated/deduped/capped at 20 entries here. |
| `tests/Unit/Validators/CandidateSkillValidatorTest.php`, `tests/Unit/Validators/CandidatePreferencesValidatorTest.php` | Normalization, required-field and malformed-country-code rejection, blank-to-null/empty-array defaults, checkbox semantics. |

## B. Files modified

- `app/Services/CandidateService.php` — `addSkill`/`removeSkill` (authorize → find-or-create the catalog entry → attach/detach → audit) and `savePreferences` (authorize → JSON-encode the two list columns → upsert → audit). Both follow the same authorize-then-mutate-then-audit shape as the education/experience methods.
- `app/Controllers/Crm/CandidateController.php` — `storeSkill`/`destroySkill`/`savePreferences`; `show()` now also loads `skills`, `canSkills`, `preferences`, `canPreferences`.
- `resources/views/crm/candidates/show.php` — a "Skills" card (chip list with an inline add-form and a per-chip remove button) under Experience, and a "Preferences" card (single form, read-only `<dl>` when the viewer lacks `candidates.preferences.manage`) under Origin.
- `routes/web.php` — `POST/DELETE /candidates/{candidate}/skills[/{skill}]`, `PUT /candidates/{candidate}/preferences`.

## C. Migration

None — all three tables shipped in `0001_initial_schema.sql`.

## F. Security / correctness

- Same ownership model as Step 3.2: every skill/preferences mutation re-resolves the branch-scoped candidate first, then authorizes `manageSkills`/`managePreferences` against it, then scopes the write by `candidate_id`.
- **Two validator bugs found and fixed during manual QA** (both the same root cause as Step 3.2's — a `??` null-coalesce is a no-op against an empty string, only against `null`/unset):
  - `CandidateSkillValidator`: `$clean['proficiency'] ?? 'intermediate'` never fired for a submitted-but-blank `<select>`, since the field is present with value `''`, not absent. Same for `years`. Fixed by testing `!== ''` explicitly (matching the already-correct `category` line right below it) — caught by a new unit test (`test_defaults_proficiency_to_intermediate_when_blank`) before it ever reached the browser.
  - `CandidatePreferencesValidator`: same pattern for `salary_currency`, `min_expected_salary`, `available_from`, `notes` — a cleared field would have stored `''` instead of `NULL`. Fixed the same way.
- Checkbox semantics (`willing_to_relocate`, `passport_ready`): a plain unchecked HTML checkbox sends nothing at all, which is indistinguishable from "field not in this form." Used the standard hidden-field-before-checkbox trick (`<input type=hidden value=0>` immediately followed by `<input type=checkbox value=1>`, same `name`) so an edit that unchecks a previously-true preference is submitted as an explicit `0`, not silently dropped back to its default. Verified over HTTP: saved `willing_to_relocate=1`, then re-saved with the box unchecked, confirmed the stored value flipped to false and the checkbox rendered unchecked on reload.
- `findOrCreateByName` + `attach()`'s `ON DUPLICATE KEY UPDATE` together mean re-submitting the same skill name for a candidate is a safe update, not a duplicate-row error or a second catalogue entry — verified by both a unit-level service test and an HTTP round-trip.

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Added a skill ("MS Excel", advanced, 5y) → 302 to `#skills`; rendered as a chip with proficiency and years
- [x] Saved preferences (countries, job titles, salary, currency, relocate, passport-ready, available-from, notes) → 302 to `#preferences`; every field round-tripped into the form correctly
- [x] Re-saved preferences with "Willing to relocate" unchecked → confirmed it flipped to unchecked/false while "Passport ready" (still checked) stayed true — proves the hidden-field trick disambiguates "unchecked" from "field omitted"
- [x] Deleted the skill → empty-state text reappeared
- [x] Cleaned up all synthetic lead/candidate/person/preferences/skill rows immediately after testing (including the global `skills` catalog row) and re-ran the full suite to confirm no leftover state
- [x] Dev admin password re-randomized post-test
- [x] Full suite **429 tests, 984 assertions** (+18 new: 8 service tests, 5+5 validator unit tests)

## I. Performance

- Skills list is one indexed join (`idx_cand_skills_skill` / PK on `candidate_skills`); preferences is a single-row PK lookup. No new query shapes.
- `attach()`'s single `INSERT ... ON DUPLICATE KEY UPDATE` avoids a separate exists-check-then-insert-or-update round trip.
