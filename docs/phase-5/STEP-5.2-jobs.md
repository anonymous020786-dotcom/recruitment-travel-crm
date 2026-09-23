# Step 5.2 — Jobs, requirements, benefits & the job status machine

**Status:** implemented; 541 tests green (+37). Continues Phase 5 (Employers + Jobs).

**Scope note:** job postings with requirements and benefits, the enforced job
lifecycle, and the public flag/slug. The config-driven match engine (candidate ↔
job scoring with a matched/missing breakdown) is Step 5.3; requirements are
stored with `is_mandatory` + `weight` and linked to the skills catalogue where
the label matches, specifically so 5.3 can score them.

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/Job.php`, `app/Models/JobRequirement.php` | Read models (`Job::salaryLabel()`, `isDeadlinePassed()`). |
| `app/Repositories/JobRepository.php` | Branch-scoped reads (joined to the employer), `paginate` (status/country/employer filters, allowlisted sort, number/title search), scoped `update`, `softDelete`, and **`transition()`** — an `UPDATE … WHERE status = :from` so two people moving the same job concurrently get one winner. |
| `app/Repositories/JobRequirementRepository.php`, `JobBenefitRepository.php` | Child-row CRUD scoped by `job_id`. |
| `app/Validators/JobValidator.php`, `JobRequirementValidator.php` | Shape + cross-field rules (salary max ≥ min, currency required with a salary, age max ≥ min); the plain-text description is converted to escaped paragraph HTML before it reaches the service. |
| `app/Support/HtmlSanitizer.php`, `app/Support/Slug.php` | Allowlist sanitizer (script/style bodies removed, tags limited to basic formatting, **every attribute dropped**, no links/images) and a slug helper. |
| `app/Policies/JobPolicy.php` | `view`/`update`/`delete`/`changeStatus`/`publish`/`match` = permission + the job's own branch scope. |
| `app/Services/JobService.php` | create (draft, `JOB-YYYY-NNNNNN`, slug `<title>-<job-number>` so it is unique by construction), update, `changeStatus`, `setPublic`, delete, requirement/benefit management. |
| `app/Controllers/Crm/JobController.php`, `resources/views/crm/jobs/{index,create,edit,_form,show}.php` | Screens. |
| `tests/Feature/JobServiceTest.php`, `tests/Unit/Validators/JobValidatorTest.php`, `tests/Unit/Support/HtmlSanitizerTest.php` | 37 tests. |

## B. Files modified

- `config/statuses.php` — the `job` entity: `draft→open|cancelled`, `open→paused|interview|filled|closed|cancelled`, `paused→open|closed|cancelled`, `interview→open|filled|closed|cancelled`, `filled→closed`, `closed`/`cancelled` terminal. No override permission exists for jobs.
- `app/Repositories/EmployerRepository.php` (`options()`), `SkillRepository.php` (`findIdByName()` — lookup only, never creates catalogue rows).
- `app/Controllers/Crm/EmployerController.php` + `employers/show.php` — the employer profile now lists its jobs and has a "New job" shortcut (hidden for suspended/blacklisted/inactive employers).
- `routes/web.php`, `bootstrap/services.php` — routes, service singleton, `Job → JobPolicy` binding.

## C. Migration

None — `jobs`, `job_requirements`, `job_benefits` shipped in `0001_initial_schema.sql`.

## F. Security / correctness

- Every lifecycle move goes through `StatusMachine::assert('job', …)` *and* the from-status-guarded `UPDATE`; a stale second mover gets `StaleRecordException`, not a silent overwrite. Verified at the service level and over HTTP (`draft → filled` refused; row unchanged).
- **Public exposure rules:** a job can be public only while `open`; any move away from `open` clears `is_public` in the same statement, and soft-delete clears it too. Publishing needs `jobs.publish` (separate from `jobs.edit`). Verified over HTTP: publishing a draft is refused, pausing a public job flips it to "Not shown on the public site".
- Opening a job whose deadline has passed is refused; closing/cancelling requires a reason (audited as the entry's context); terminal jobs are read-only; only draft/closed/cancelled jobs can be deleted.
- Job descriptions are rendered as HTML, so they are never user markup: plain-text input is HTML-escaped into `<p>`/`<br>` at write time (verified over HTTP: `<b>two</b>` displays as text), and `HtmlSanitizer::clean()` exists for any future rich-text path, with tests covering script bodies, event-handler attributes, `javascript:` links and images.
- Posting for a suspended/blacklisted/inactive employer is refused; the job's branch comes from the employer (falling back to the actor's primary branch for a head-office employer) and must be inside the actor's scope.

## H. Manual QA — verified (over HTTP)

- [x] Create job for an employer → draft, `JOB-2026-000001`, salary/description rendered, HTML in description escaped
- [x] Add requirement (mandatory, weight 5) and benefit → rendered
- [x] Publish while draft → refused; `draft → filled` → refused; `draft → open` → OK; publish while open → "Public" badge + "Shown on the public site"
- [x] List filter (status + search) finds the job with the Public badge
- [x] `open → paused` → automatically unpublished
- [x] All synthetic employer/job rows and `job:*`/`employer:*` sequences cleaned; admin password re-randomized; **541 tests, 1197 assertions**

## I. Performance

- List: one joined query + `COUNT(*)` using `idx_jobs_status` / `idx_jobs_country_status` / `idx_jobs_employer`; requirements/benefits use `idx_job_req_job` / `idx_job_benefits_job`. A status move is a single indexed `UPDATE`.
