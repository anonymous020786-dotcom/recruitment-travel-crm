# Phase 0 — RBAC: Roles & Permission Matrix (point 6)

## 1. Model

- **Role** = a bundle of permissions (`roles` + `role_permissions`). Every user has exactly one role (`users.role_id`).
- **Permission** = an atomic action string `module.action` (`permissions`). The catalogue is fixed in code + seeder; adding one is a migration.
- **Per-user override** (`user_permissions`, `effect = allow | deny`) layers on top. **`deny` always wins**, then explicit `allow`, then the role grant, then implicit deny.
- **Branch scope** is orthogonal to permissions. A permission says *what action*; branch scope says *on which records*. Resolved by the `BindBranchScope` middleware + Policy:
  - `is_org_wide = 1` → all branches.
  - else → `user_branches` set (or `primary_branch_id` if that set is empty).
  - Every list/detail/report query is filtered to that set. Cross-branch access requires `is_org_wide` or an explicit `*.view_all` permission.
- **Field-level visibility** (sensitive data, section 14/T10) is enforced in Policies + view layer, keyed off the role/permissions — see §5.
- **Enforcement points (defence in depth):** route middleware `Authorize:<perm>` → service calls `Policy::authorize*()` (record + branch + field) → repository injects branch predicate. UI hides disallowed actions but that is **never** the security boundary.

## 2. Roles

| Role (`name`) | Branch scope | Intent |
|---|---|---|
| `super_admin` | org-wide, immutable | Full control incl. RBAC, settings, users, audit. Cannot be deleted. |
| `admin` | org-wide | Everything operational + user management + settings; **cannot** alter `super_admin` accounts or delete audit. |
| `manager` | assigned branch(es) | Full operational oversight of their branch(es): all modules read/write, reports, assign work, approve refunds up to a limit. No global settings, no user role changes. |
| `counselor` | assigned branch(es) | Leads + candidates + follow-ups + tasks + communication. Read jobs/applications; create applications; no verify/finance/visa-write. |
| `recruitment` | assigned branch(es) | Employers, jobs, applications, interviews, job matching. Read candidates/documents. No finance, no doc verification. |
| `documentation` | assigned branch(es) | Documents: upload, verify, reject, expiry; candidate read; checklist management. No finance, no status transitions beyond doc states. |
| `visa` | assigned branch(es) | Medical + visa + travel/ticket + departure. Candidate/application read. No finance, no lead/candidate create. |
| `accounts` | assigned branch(es) | Invoices, payments, allocations, receipts, refunds (create; approve if also granted), finance reports, outstanding. Read-only elsewhere. Sees financial fields; masked PII elsewhere. |
| `travel` | assigned branch(es) | Tour packages + tour bookings + travel enquiries + shared person read. No recruitment write, no recruitment finance beyond tour invoices. |
| `read_only` | assigned branch(es) | View across operational modules; **no** create/edit/delete/verify/transition/export-of-PII. Useful for auditors/observers. |

`manager` refund approval limit and a few similar thresholds live in
`settings` (`finance.refund_approval_limit`, per-role), not code.

## 3. Permission catalogue

Format `module.action`. `*` in the matrix = granted.

```
auth.login (implicit)          me.view  me.update_profile  me.change_password

dashboard.view

leads.view  leads.view_all  leads.create  leads.edit  leads.delete
leads.assign  leads.import  leads.export  leads.convert  leads.merge

followups.view  followups.create  followups.edit  followups.complete  followups.delete

persons.view  persons.merge

candidates.view  candidates.view_all  candidates.create  candidates.edit
candidates.delete  candidates.export
candidates.education.manage  candidates.experience.manage
candidates.skills.manage  candidates.preferences.manage  candidates.passport.manage

documents.view  documents.upload  documents.verify  documents.reject
documents.delete  documents.download  documents.checklist.manage

employers.view  employers.view_all  employers.create  employers.edit
employers.delete  employers.export  employers.contacts.manage

jobs.view  jobs.create  jobs.edit  jobs.delete  jobs.change_status
jobs.publish  jobs.export  jobs.match

applications.view  applications.view_all  applications.create  applications.edit
applications.change_status  applications.override_status  applications.delete  applications.export

interviews.view  interviews.create  interviews.edit  interviews.record_outcome  interviews.delete

medical.view  medical.create  medical.edit  medical.delete

visa.view  visa.create  visa.edit  visa.change_status  visa.override_status  visa.delete

travel.view  travel.profile.manage  travel.tickets.manage
travel.departure.manage  travel.placement.manage

tours.packages.view  tours.packages.create  tours.packages.edit
tours.packages.delete  tours.packages.publish
tours.bookings.view  tours.bookings.create  tours.bookings.edit
tours.bookings.change_status  tours.bookings.delete  tours.bookings.export

invoices.view  invoices.create  invoices.edit  invoices.void  invoices.export
payments.view  payments.create  payments.edit  payments.reverse
allocations.manage
refunds.view  refunds.create  refunds.approve  refunds.reject  refunds.mark_paid
receipts.view  receipts.issue

reports.view  reports.finance.view  reports.export

communication.view  communication.log

notifications.view

tasks.view  tasks.view_all  tasks.create  tasks.edit  tasks.complete  tasks.delete  tasks.assign

search.global

imports.run  exports.run

users.view  users.manage  roles.manage
settings.manage  settings.view
audit.view
public_enquiries.view  public_enquiries.convert
```

Notes:
- `*.view_all` = ignore branch scope for that module (read across branches). Without it, `*.view` is branch-scoped.
- `*.override_status` = perform a transition not in the allowlist (always audited + reason required).
- `payments.reverse` and `invoices.void` do **not** delete — they create audited reversing state.
- There is **no** `audit.edit` / `audit.delete` — the audit trail is append-only for everyone including `super_admin` (enforced by not granting UPDATE/DELETE on `activity_logs` to the app DB user for those operations at the service level).

## 4. Role → permission matrix

`F` = full module (all actions listed for it); `R` = read/view only; `—` = none;
otherwise the specific actions granted.

| Module / group | super_admin | admin | manager | counselor | recruitment | documentation | visa | accounts | travel | read_only |
|---|---|---|---|---|---|---|---|---|---|---|
| dashboard.view | * | * | * | * | * | * | * | * | * | * |
| me.* | * | * | * | * | * | * | * | * | * | * |
| notifications.view | * | * | * | * | * | * | * | * | * | * |
| search.global | * | * | * | * | * | * | * | * | * | * |
| **leads** | F + view_all | F + view_all | F (branch) | view, create, edit, assign, convert, import, export | R | — | — | R | — | R |
| followups | F | F | F (branch) | F (branch) | create/edit/complete | — | — | — | create/edit/complete (own) | R |
| persons | view, merge | view, merge | view, merge (branch) | view | view | view | view | view | view | view |
| **candidates** | F + view_all | F + view_all | F (branch) | view, create, edit, export?, sub-tab manage | R + sub-tab manage? (R) | R | R | R (masked) | R | R |
| candidates.passport.manage | * | * | * (branch) | * | — | * | * | — | — | — |
| **documents** | F | F | F (branch) | view, upload, download | view, download | view, upload, verify, reject, download, checklist.manage | view, upload, download | view (masked list), download (finance docs) | view, download (travel docs) | view |
| **employers** | F + view_all | F + view_all | F (branch) | R | F (branch) | — | — | R | — | R |
| **jobs** | F | F | F (branch) | R | F (branch) incl. change_status, match | — | — | R | — | R |
| jobs.publish | * | * | * | — | * | — | — | — | — | — |
| **applications** | F + view_all + override | F + view_all + override | F (branch) + override | view, create, edit | view, create, edit, change_status | R | view, change_status (medical/visa-adjacent only) | R | — | R |
| interviews | F | F | F (branch) | view | F (branch) | — | view | — | — | R |
| medical | F | F | F (branch) | R | R | R | F (branch) | R | — | R |
| visa | F + override | F + override | F (branch) + override | R | R | R | F (branch) incl. change_status | R | R | R |
| travel (profile/tickets/departure/placement) | F | F | F (branch) | R | R | — | F (branch) | R | R | R |
| **tours.packages** | F | F | F (branch) | — | — | — | — | R | F (branch) | R |
| tours.packages.publish | * | * | * | — | — | — | — | — | * | — |
| **tours.bookings** | F | F | F (branch) | — | — | — | — | R | F (branch) | R |
| **invoices** | F | F | F (branch) | R | — | — | — | F (branch) | create/edit (tour only) | R |
| **payments** | F | F | view, create, reverse (branch, ≤ limit) | R | — | — | — | F (branch) | create (tour only) | R |
| allocations.manage | * | * | * (branch) | — | — | — | — | * | * (tour) | — |
| **refunds** | F | F | view, create, approve (≤ limit), reject (branch) | — | — | — | — | create, view, mark_paid | — | R |
| refunds.approve | * | * | * (≤ `settings.finance.refund_approval_limit`) | — | — | — | — | — (unless granted) | — | — |
| receipts | F | F | view, issue | R | — | — | — | view, issue | view, issue (tour) | R |
| reports.view | * | * | * (branch) | own leads/candidates | recruitment reports (branch) | doc reports (branch) | visa reports (branch) | * (branch) | travel reports (branch) | * (branch, no export of PII) |
| reports.finance.view | * | * | * (branch) | — | — | — | — | * (branch) | tour finance (branch) | — |
| reports.export / exports.run | * | * | * (branch) | leads/candidates (no sensitive fields) | recruitment | — | — | finance | travel | — |
| imports.run | * | * | * (branch) | leads, candidates | jobs, employers | — | — | — | — | — |
| communication.* | F | F | F (branch) | F (branch) | log | log | log | log | log | view |
| tasks | F + view_all + assign | F + view_all + assign | F (branch) + assign | F (branch) | F (branch) | F (branch) | F (branch) | F (branch) | F (branch) | R |
| public_enquiries | view, convert | view, convert | view, convert (branch) | view, convert | view, convert | — | — | — | view, convert (travel) | view |
| **users.view** | * | * | * (branch, read) | — | — | — | — | — | — | — |
| users.manage | * | * | — | — | — | — | — | — | — | — |
| roles.manage | * | — | — | — | — | — | — | — | — | — |
| settings.view | * | * | * | — | — | — | — | — | — | — |
| settings.manage | * | * | — | — | — | — | — | — | — | — |
| audit.view | * | * | * (branch) | — | — | — | — | — | — | — (or R if "auditor" variant) |

The matrix is the **seed default** (`database/seeders/RolePermissionSeeder`).
Agencies tune it live via `roles.manage`; every change is audited. System
invariants (append-only audit, `amount > 0`, transition allowlist, branch
isolation) are **not** overridable through RBAC.

## 5. Field-level / row-level visibility rules

| Data | Who sees full value | Who sees masked / hidden |
|---|---|---|
| Passport number, Aadhaar/PAN, DOB (full) | super_admin, admin, manager, counselor (own branch), documentation, visa | Others: masked `•••• 1234` in lists; hidden in exports unless `candidates.export` + field grant |
| Payment amounts, invoice totals, outstanding, refund values | super_admin, admin, manager, accounts; travel (tour scope only) | counselor/recruitment/documentation/visa/read_only: hidden on candidate profile "Payments" tab (show "contact accounts") |
| Employer contract terms, commercial rates | super_admin, admin, manager, recruitment (own branch) | Others: hidden |
| Document file contents | roles with `documents.download` + policy (same branch / assigned / relevant team) | Everyone else: cannot open; access attempts logged |
| Candidate contact phone/email in list views | branch members with `candidates.view` | read_only sees name + status only until opening the record (configurable) |
| Audit log entries | `audit.view` (branch-scoped for manager) | Others: no access |
| Other branches' records | `is_org_wide` or `*.view_all` | Everyone else: not returned by any query, not found by search, 404 on direct id |

Masking is applied in the **view/resource layer** based on a
`Policy::visibleFields(user, entity)` result; the repository still selects the
column (so authorized drill-down works) but unauthorized responses never include
it.

## 6. Policy classes (Phase 1 skeleton)

`LeadPolicy, CandidatePolicy, DocumentPolicy, EmployerPolicy, JobPolicy,
ApplicationPolicy, InterviewPolicy, MedicalPolicy, VisaPolicy, TravelPolicy,
TourPackagePolicy, TourBookingPolicy, InvoicePolicy, PaymentPolicy, RefundPolicy,
TaskPolicy, ReportPolicy, UserPolicy, SettingPolicy, AuditPolicy`.

Each exposes `viewAny(user)`, `view(user, record)`, `create(user, input)`,
`update(user, record)`, `delete(user, record)`, plus module-specific verbs
(`changeStatus`, `overrideStatus`, `verify`, `reject`, `approve`, `refund`,
`assign`, `export`, `publish`) and `visibleFields(user, record)`. Every method
checks: permission → branch scope → record state → field grants.
