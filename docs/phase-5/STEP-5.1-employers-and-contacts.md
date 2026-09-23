# Step 5.1 — Employer profile & contacts

**Status:** implemented; 504 tests green (+16). Opens Phase 5 (Employers + Jobs).

**Scope note:** employer CRUD (create, list/filter/sort/search, profile, edit,
soft delete) plus contact management. Jobs, requirements, the job status
machine and the match engine are Steps 5.2–5.3. `employers` carries no
`record_version` (docs/00-ARCHITECTURE.md T20 lists only money, application,
document and candidate aggregates for optimistic locking), so updates are
plain last-write-wins, as the schema intends.

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/Employer.php`, `app/Models/EmployerContact.php` | Read models. |
| `app/Repositories/EmployerRepository.php` | Branch-scoped `findById`/`findByPublicId`, `paginate` (allowlisted sort, status/country filters, search on number/company/industry), `create`, scoped `update`, `softDelete`. |
| `app/Repositories/EmployerContactRepository.php` | Contact CRUD scoped by `employer_id`, plus `clearPrimaryExcept`. |
| `app/Validators/EmployerValidator.php`, `EmployerContactValidator.php` | Shape validation with blank→null normalisation (the empty-string-vs-`??` lesson from Phase 3 applied up front). |
| `app/Policies/EmployerPolicy.php` | `view`/`update`/`delete`/`manageContacts` = permission + branch scope; `employers.view_all` lifts scope for reads. |
| `app/Services/EmployerService.php` | Transactional create (`EMP-YYYY-NNNNNN` via `Sequences`), update, soft-delete, contacts (single-primary invariant enforced in one transaction), account-owner validation, audit on every write. |
| `app/Controllers/Crm/EmployerController.php`, `resources/views/crm/employers/{index,create,edit,_form,show}.php` | Screens; contacts add/edit/delete inline on the profile. |
| `tests/Feature/EmployerServiceTest.php`, `tests/Unit/Validators/EmployerValidatorTest.php` | 16 tests. |

## B. Files modified

- `routes/web.php` — employer routes (literals declared before the `{employer}` wildcard) and contact routes.
- `bootstrap/services.php` — `EmployerService` singleton and `Employer → EmployerPolicy` Gate binding.

## C. Migration

None — `employers`/`employer_contacts` shipped in `0001_initial_schema.sql`; permissions and the sidebar item already existed.

## F. Security / correctness

- `employers.branch_id` is nullable. `BranchScope` treats a null-branch record as out of scope for everyone except org-wide users (both in SQL and in `contains()`), so a head-office employer is invisible to branch staff by design; new employers default to the creator's primary branch and must be within their scope.
- Every write re-resolves the employer through the scoped repository (404 outside scope, no existence leak), then authorizes the specific ability; contact queries additionally filter by `employer_id`.
- Search uses two distinct named placeholders (native prepares forbid reusing one) and escapes LIKE wildcards.
- Website is validated as a URL and rendered with `rel="noopener noreferrer"`; all output escaped.

## H. Manual QA — verified (over HTTP)

- [x] Create → 302 to profile, `EMP-2026-000001` assigned
- [x] Add primary contact → rendered with Primary badge
- [x] Edit form loads; update (rename + status → suspended) persists
- [x] List search + status filter finds the row with the Suspended badge
- [x] Delete → soft-deleted, gone from list
- [x] Cleaned all synthetic rows and the `employer:*` sequence (a leftover `EMP-…-000001` would collide with the test suite's reset sequence — same trap as Step 3.2); admin password re-randomized
- [x] Full suite **504 tests, 1123 assertions**

## I. Performance

- List is one joined query + `COUNT(*)`, using `idx_employers_country`/`idx_employers_status`/`idx_employers_name`. Contacts use `idx_employer_contacts_employer`.
