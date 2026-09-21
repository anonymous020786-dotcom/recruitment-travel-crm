# Step 3.5 — Candidate timeline & tasks (closes Phase 3: Candidates)

**Status:** implemented; 457 tests green (+16). Closes Phase 3.

**Scope note:** the timeline is the same 3-way-merge idea `LeadController::buildTimeline()` already established for leads — free-text notes plus the audit trail, sorted into one feed — simplified to two sources here since candidates have no communication log of their own yet. Tasks reuse the existing generic `tasks` table (`related_type='candidate'`); the model and repository are written generically on purpose so applications/visa/travel phases can reuse both later without duplicating the shape, but only the candidate-linked read/write paths are exercised so far.

## A. Files created

| File | Purpose |
|---|---|
| `database/migrations/0008_candidate_notes.sql` | New `candidate_notes` table — candidates had no free-text note trail of their own, unlike leads (`lead_notes`). Applied to `crm_dev`; `database/schema/schema.sql` updated to match. |
| `app/Models/Task.php` | Generic read model for `tasks` (polymorphic `related_type`/`related_id`). `isPending()`, `isOverdue()`. |
| `app/Repositories/TaskRepository.php` | `forRelated()` (pending first, soonest due), `findInScope()` (branch-scoped), `create()`, `markCompleted()`/`markCancelled()` (both guard `WHERE status = 'pending'`, so a double-complete or complete-after-cancel is a no-op the caller can detect via the affected-row count). |
| `app/Validators/TaskValidator.php` | title + assignee required; due date can't be in the past; due time validated against a 24-hour `HH:MM` regex passed as an **array-form rule** (`['nullable', 'regex:/.../']`) rather than the usual pipe-delimited string. |
| `tests/Unit/Validators/TaskValidatorTest.php` | Normalization, required-field rejection, past-due-date rejection, malformed-time rejection, blank-to-default/null handling. |

## B. Files modified

- `app/Services/CandidateService.php` — `addNote()` (mirrors `LeadService::addNote` exactly: authorize, trim, reject empty, insert, audit); `addTask()`/`completeTask()`/`cancelTask()` (authorize `manageTasks`, validate the assignee is active and in the candidate's branch, create/transition the task, audit). Extracted the branch-membership check both `reassignCounselor` and `addTask` need into one shared `assertUserInBranch()` helper (previously duplicated as `assertCounselorValid`'s inline body).
- `app/Policies/CandidatePolicy.php` — `addNote()` (view + `candidates.edit`, same shape as `LeadPolicy::addNote`) and `manageTasks()` (view + `tasks.create` or `tasks.edit` — no new permission catalogue entries needed, `tasks.*` was already seeded in Phase 1).
- `app/Repositories/CandidateRepository.php` — `notes()`, mirroring `LeadRepository::notes()`.
- `app/Controllers/Crm/CandidateController.php` — `addNote`/`storeTask`/`completeTask`/`cancelTask`; `show()` now also loads `timeline`, `canAddNote`, `tasks`, `canTasks`, `taskAssignees` (reuses `assignableCounselors()` — the same active-users-in-branch list already built for counselor assignment). New private `buildTimeline()`, a candidate-flavoured copy of `LeadController`'s (notes + a candidate-specific action→label map covering every audit action Steps 3.1–3.5 write: profile update, counselor change, education/experience/skill/preference/passport/task events).
- `resources/views/crm/candidates/show.php` — "Timeline" card (note form + merged feed) and "Tasks" card (add form with assignee/priority/due date-time, list with Complete/Cancel actions hidden once a task is closed, an overdue badge for pending tasks past their due date).
- `routes/web.php` — `POST /candidates/{candidate}/notes` (`can:candidates.edit`), `POST /candidates/{candidate}/tasks` (`can:tasks.create`), `POST /candidates/{candidate}/tasks/{task}/complete` (`can:tasks.complete`), `POST /candidates/{candidate}/tasks/{task}/cancel` (`can:tasks.edit`).

## C. Migration

`0008_candidate_notes.sql` — one new table, no changes to existing ones. Applied via `php scripts/migrate.php` (batch 8, 1 statement).

## F. Security / correctness

- **Bug found and fixed before it ever ran**: the task due-time rule started as `'nullable|regex:/^([01]\d|2[0-3]):[0-5]\d$/'` — a plain pipe-delimited rule string. This framework's `Validator` splits a rule string on `|` to get the individual rules, and the regex's own alternation (`01\d|2[0-3]`) contains a literal `|`, so the split shredded the pattern into `nullable`, `regex:/^([01]\d`, `2[0-3]):[0-5]\d$/` and validation broke on any valid time. Caught immediately by `TaskValidatorTest::test_accepts_and_normalises` (which uses a realistic non-empty fixture, unlike a test that only exercises the field with a blank value). Fixed by passing the rule as an array (`['nullable', 'regex:/.../']`) so the framework's `is_array($ruleset) ? $ruleset : explode('|', ...)` branch skips the destructive split entirely — the same escape hatch already available to any future rule whose regex needs a `|`.
- `TaskRepository::markCompleted`/`markCancelled` are guarded (`WHERE status = 'pending'`), so completing an already-cancelled task (or vice versa) affects zero rows; the service turns that into a `DomainRuleException` rather than silently double-processing.
- `addTask` re-validates the assignee against the candidate's branch with the same active/org-wide/branch-membership check used everywhere else in this module — a task can't be silently assigned to someone with no access to the branch.
- `candidate_notes` has no `record_version` (like every other Phase 3 child table) — it's an append-only log, not an editable aggregate.
- Every candidate-scoped audit action from Steps 3.1–3.5 is now visible in one place: the timeline label map lists all of them explicitly, so a future step that adds a new candidate action and forgets to add a label will just show the raw action string — visible immediately in QA, not silently dropped.

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Fresh candidate's Timeline shows "created the candidate" (the conversion audit entry) with no manual entries yet
- [x] Added a note → 302 to `#timeline`; rendered with the 📝 icon and the actor's name
- [x] Added a task (assignee, high priority, due date + time) → 302 to `#tasks`; rendered with assignee/due/priority meta
- [x] Completed the task → status flips to "Done", Complete/Cancel buttons disappear for that row, timeline gained a "completed a task" entry alongside the earlier "added a task"
- [x] Cleaned up all synthetic lead/candidate/person/task/note rows; re-ran the full suite to confirm no leftover state
- [x] Dev admin password re-randomized post-test
- [x] Full suite **457 tests, 1030 assertions** (+16 new: 10 service tests, 6 validator unit tests)

## I. Performance

- `TaskRepository::forRelated()` uses `idx_tasks_related`; `CandidateRepository::notes()` uses the new `idx_candidate_notes_candidate (candidate_id, created_at)` composite index — both single indexed lookups, no joins beyond a `LEFT JOIN users` for the assignee/author name.
- The timeline merge is two small, already-limited queries (notes capped at 200, activity log capped at 100) combined and sorted in PHP — the same shape proven at Lead scale in Phase 2, and candidates generate far fewer events per record than leads.

## Phase 3 exit criteria — status

- **360° profile screen**: identity/contact/recruitment fields, education, experience, skills, preferences, passports (with expiry), counselor, origin, timeline, and tasks all live on one page. ✅
- **Passport expiry windows computed**: `Passport::daysUntilExpiry()`/`isExpired()`/`isExpiringSoon()`, rendered as colour-coded badges. ✅ (Step 3.4)
- **Person shared with travel**: already true by construction — `persons` is the same non-branch-scoped identity table `leads.person_id` and `candidates.person_id` both point to; no candidate-specific person data was created, so travel/employer-contact modules in later phases reuse the identical row. ✅ (no new work needed this step)
