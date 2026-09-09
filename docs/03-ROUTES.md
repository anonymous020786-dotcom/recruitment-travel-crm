# Phase 0 — Route Map (point 7)

## Conventions

- Clean URLs, no `.php`. All requests → `public/index.php` → Router.
- URL identifiers are **ULID `public_id`** (`{lead}`, `{candidate}`, …), resolved by `ResolvePublicId`. Numeric PKs never appear in URLs.
- **CRM group** middleware (all routes below unless noted): `RequestId, SecurityHeaders, EnforceHttps, MaintenanceGuard, StartSession, Authenticate, VerifyCsrf` (non-GET), `NoStoreCache` (adds `X-Robots-Tag: noindex`). Then per-route `Authorize:<perm>`, `BindBranchScope` (data routes), `RateLimit:<bucket>` (marked), `ValidateInput:<Validator>` (non-GET).
- **API group** (`/api/*`): same, but `Authenticate` returns `401` JSON, `VerifyCsrf` still required for cookie-auth mutations, all responses JSON, `Accept: application/json`.
- **Public group**: `RequestId, SecurityHeaders (relaxed CSP for OG), EnforceHttps, StartSession(anon), PublicCache`; forms add `VerifyCsrf`, `RateLimit`, honeypot/captcha. **No** auth, **no** `noindex`.
- Method column: real HTTP method. HTML forms use `POST` + `_method` override for `PUT/PATCH/DELETE`.
- List endpoints accept `?q=&page=&per_page=(25|50|100)&sort=<allowlist>&dir=(asc|desc)&<filters>`; `per_page` clamped, `sort` from a per-endpoint allowlist.

---

## 1. Auth & account

| Method | Path | Permission | Middleware extras | Purpose |
|---|---|---|---|---|
| GET | `/login` | guest | — | Login form |
| POST | `/login` | guest | `RateLimit:login`, `ValidateInput:LoginValidator` | Authenticate; regenerate session; record `login_attempts` |
| POST | `/logout` | auth | — | Destroy session row |
| GET | `/forgot-password` | guest | — | Request reset form |
| POST | `/forgot-password` | guest | `RateLimit:password_reset` | Create `password_resets` token, email link; generic response |
| GET | `/reset-password/{token}` | guest | — | Reset form (token validated) |
| POST | `/reset-password` | guest | `RateLimit:password_reset`, `ValidateInput:ResetPasswordValidator` | Set new hash, single-use token, invalidate other sessions |
| GET | `/account/profile` | `me.view` | — | Own profile |
| PATCH | `/account/profile` | `me.update_profile` | `ValidateInput:ProfileValidator` | Update name/phone |
| POST | `/account/password` | `me.change_password` | `ValidateInput:ChangePasswordValidator` | Change own password (verify current) |
| GET | `/health` | guest (or IP-allowlist) | — | Liveness JSON |
| GET | `/health/db` | `super_admin`/`admin` | — | DB connectivity + migration version |

---

## 2. Dashboard, search, notifications, tasks

| Method | Path | Permission | Extras | Purpose |
|---|---|---|---|---|
| GET | `/dashboard` | `dashboard.view` | `BindBranchScope` | KPI cards + charts (cached aggregates) |
| GET | `/api/dashboard/widgets` | `dashboard.view` | `BindBranchScope`, `RateLimit:dashboard` | JSON widget data (lazy) |
| GET | `/search` | `search.global` | `BindBranchScope`, `RateLimit:search` | Grouped global search results (authz-filtered) |
| GET | `/api/search` | `search.global` | `BindBranchScope`, `RateLimit:search` | JSON typeahead |
| GET | `/notifications` | `notifications.view` | — | Notification list |
| GET | `/api/notifications/unread-count` | `notifications.view` | — | Badge count |
| POST | `/notifications/{notification}/read` | `notifications.view` | — | Mark read |
| POST | `/notifications/read-all` | `notifications.view` | — | Mark all read |
| GET | `/tasks` | `tasks.view` / `tasks.view_all` | `BindBranchScope` | Today / Overdue / Upcoming |
| GET | `/tasks/{task}` | `tasks.view` | — | Detail |
| POST | `/tasks` | `tasks.create` | `ValidateInput:TaskValidator` | Create |
| PUT | `/tasks/{task}` | `tasks.edit` | `ValidateInput:TaskValidator` | Update |
| POST | `/tasks/{task}/complete` | `tasks.complete` | — | Complete |
| POST | `/tasks/{task}/assign` | `tasks.assign` | `ValidateInput:TaskAssignValidator` | Reassign |
| DELETE | `/tasks/{task}` | `tasks.delete` | — | Cancel/delete |

---

## 3. Leads

| Method | Path | Permission | Extras | Purpose |
|---|---|---|---|---|
| GET | `/leads` | `leads.view` | `BindBranchScope` | List (keyset), filters: status, source, country, assignee, priority, date range |
| GET | `/leads/create` | `leads.create` | — | Form |
| POST | `/leads` | `leads.create` | `ValidateInput:LeadValidator`, `RateLimit:write` | Create (duplicate check → 409 with candidates unless `confirmed_not_duplicate`) |
| GET | `/leads/{lead}` | `leads.view` | policy | Detail + timeline |
| GET | `/leads/{lead}/edit` | `leads.edit` | policy | Form |
| PUT | `/leads/{lead}` | `leads.edit` | `ValidateInput:LeadValidator` | Update (optimistic lock) |
| DELETE | `/leads/{lead}` | `leads.delete` | policy | Soft delete |
| POST | `/leads/{lead}/assign` | `leads.assign` | `ValidateInput:AssignValidator` | Assign to user |
| POST | `/leads/bulk/assign` | `leads.assign` | `ValidateInput:BulkAssignValidator` | Bulk assign (per-record policy, capped) |
| POST | `/leads/{lead}/status` | `leads.edit` | `ValidateInput:LeadStatusValidator` | Transition (StatusMachine) |
| POST | `/leads/{lead}/notes` | `leads.edit` | `ValidateInput:NoteValidator` | Add note |
| POST | `/leads/{lead}/convert` | `leads.convert` | `ValidateInput:LeadConvertValidator` | → candidate (transaction) |
| POST | `/leads/merge` | `leads.merge` | `ValidateInput:LeadMergeValidator` | Merge duplicates |
| GET | `/leads/{lead}/followups` | `followups.view` | — | Follow-ups for lead |
| POST | `/leads/{lead}/followups` | `followups.create` | `ValidateInput:FollowupValidator` | Schedule |
| PUT | `/followups/{followup}` | `followups.edit` | `ValidateInput:FollowupValidator` | Edit |
| POST | `/followups/{followup}/complete` | `followups.complete` | `ValidateInput:FollowupCompleteValidator` | Complete + outcome |
| POST | `/leads/import` | `leads.import` + `imports.run` | `RateLimit:import`, `ValidateInput:ImportUploadValidator` | Upload CSV → batch |
| GET | `/leads/import/{batch}/preview` | `leads.import` | — | Mapping + validation preview |
| POST | `/leads/import/{batch}/commit` | `leads.import` | — | Run import (transactional, failed-row report) |
| GET | `/leads/export` | `leads.export` + `exports.run` | `RateLimit:export` | Queue CSV export (streamed) |

`GET /leads/{lead}/whatsapp` and `/call` are **client-side** `wa.me:` / `tel:` links built from templates — no server route needed beyond template fetch `GET /api/whatsapp-templates`.

---

## 4. Persons

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/persons/{person}` | `persons.view` | Identity summary + linked candidate/bookings |
| POST | `/persons/merge` | `persons.merge` | Merge two person identities (repoint children, audit) |

---

## 5. Candidates (+ sub-resources / tabs)

| Method | Path | Permission | Extras | Purpose |
|---|---|---|---|---|
| GET | `/candidates` | `candidates.view`/`view_all` | `BindBranchScope` | List (keyset), filters: stage, country, counselor, skill, passport status |
| GET | `/candidates/create` | `candidates.create` | — | Form |
| POST | `/candidates` | `candidates.create` | `ValidateInput:CandidateValidator` | Create (+person link/create) |
| GET | `/candidates/{candidate}` | `candidates.view` | policy | 360° profile (default tab: Personal) |
| GET | `/candidates/{candidate}/{tab}` | `candidates.view` (+ tab perm) | policy | tab ∈ personal, passport, education, experience, skills, preferences, documents, applications, interviews, medical, visa, travel, payments, tasks, communication, timeline |
| PUT | `/candidates/{candidate}` | `candidates.edit` | `ValidateInput:CandidateValidator` | Update (optimistic lock) |
| DELETE | `/candidates/{candidate}` | `candidates.delete` | policy | Soft delete (dependency check) |
| POST | `/candidates/{candidate}/passports` | `candidates.passport.manage` | `ValidateInput:PassportValidator` | Add passport |
| PUT | `/passports/{passport}` | `candidates.passport.manage` | `ValidateInput:PassportValidator` | Edit |
| POST/PUT/DELETE | `/candidates/{candidate}/education[/{id}]` | `candidates.education.manage` | `ValidateInput:EducationValidator` | CRUD education |
| POST/PUT/DELETE | `/candidates/{candidate}/experience[/{id}]` | `candidates.experience.manage` | `ValidateInput:ExperienceValidator` | CRUD experience |
| PUT | `/candidates/{candidate}/skills` | `candidates.skills.manage` | `ValidateInput:SkillsValidator` | Set skills |
| PUT | `/candidates/{candidate}/preferences` | `candidates.preferences.manage` | `ValidateInput:PreferencesValidator` | Update preferences |
| GET | `/candidates/export` | `candidates.export` + `exports.run` | `RateLimit:export` | Queue export (field-gated) |
| POST | `/candidates/import` … | `candidates.create` + `imports.run` | as Leads import | CSV import |

---

## 6. Documents

| Method | Path | Permission | Extras | Purpose |
|---|---|---|---|---|
| GET | `/candidates/{candidate}/documents` | `documents.view` | policy | Checklist + uploaded list |
| POST | `/candidates/{candidate}/documents` | `documents.upload` | `RateLimit:upload`, `ValidateInput:DocumentUploadValidator` | Upload (full §11 pipeline) |
| GET | `/documents/{document}/download` | `documents.download` | policy, `document_access_log` | Stream as attachment |
| GET | `/documents/{document}/preview` | `documents.download` | policy, `document_access_log` | Inline preview (image/pdf only, re-encoded) |
| POST | `/documents/{document}/verify` | `documents.verify` | `ValidateInput:DocVerifyValidator` (optimistic lock) | → verified |
| POST | `/documents/{document}/reject` | `documents.reject` | `ValidateInput:DocRejectValidator` (reason required) | → rejected |
| DELETE | `/documents/{document}` | `documents.delete` | policy, audit | Remove (rare; audited; file shredded) |
| PUT | `/candidates/{candidate}/documents/checklist` | `documents.checklist.manage` | `ValidateInput:ChecklistValidator` | Adjust required set |

---

## 7. Employers & jobs

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/employers` | `employers.view`/`view_all` | List |
| GET/POST | `/employers/create`, `/employers` | `employers.create` | Create (`EmployerValidator`) |
| GET | `/employers/{employer}` | `employers.view` | Profile + jobs + applications + placements + history |
| PUT | `/employers/{employer}` | `employers.edit` | Update |
| DELETE | `/employers/{employer}` | `employers.delete` | Soft delete |
| POST/PUT/DELETE | `/employers/{employer}/contacts[/{id}]` | `employers.contacts.manage` | CRUD contacts |
| GET | `/employers/{employer}/report` | `reports.view` | Employer-specific report |
| GET | `/employers/export` | `employers.export` + `exports.run` | Export |
| GET | `/jobs` | `jobs.view` | List, filters: country, status, employer, salary band |
| GET/POST | `/jobs/create`, `/jobs` | `jobs.create` | Create (`JobValidator`; sanitize `description_html`) |
| GET | `/jobs/{job}` | `jobs.view` | Detail + requirements + benefits + applications |
| PUT | `/jobs/{job}` | `jobs.edit` | Update |
| POST | `/jobs/{job}/status` | `jobs.change_status` | Transition (StatusMachine) |
| POST | `/jobs/{job}/publish` | `jobs.publish` | Toggle `is_public` (+ slug) |
| DELETE | `/jobs/{job}` | `jobs.delete` | Soft delete |
| GET | `/jobs/{job}/matches` | `jobs.match` | Ranked candidate matches (explainable) |
| GET | `/candidates/{candidate}/matches` | `jobs.match` | Ranked job matches for a candidate |
| GET | `/api/jobs/{job}/match/{candidate}` | `jobs.match` | Single match breakdown JSON |

---

## 8. Applications & interviews

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/applications` | `applications.view`/`view_all` | List/pipeline, filters: status, job, employer, branch, assignee |
| POST | `/applications` | `applications.create` | Create (`ApplicationValidator`; unique candidate+job; snapshot match score) |
| GET | `/applications/{application}` | `applications.view` | Detail + status history + interviews + linked medical/visa/travel |
| PUT | `/applications/{application}` | `applications.edit` | Update non-status fields (optimistic lock) |
| POST | `/applications/{application}/status` | `applications.change_status` | Transition via StatusMachine → writes history |
| POST | `/applications/{application}/status/override` | `applications.override_status` | Non-allowlisted transition (reason required, `is_override=1`, audit) |
| DELETE | `/applications/{application}` | `applications.delete` | Cancel (soft; keeps history) |
| GET | `/applications/export` | `applications.export` + `exports.run` | Export |
| GET | `/interviews` | `interviews.view` | List / calendar view, filter by date/status |
| POST | `/applications/{application}/interviews` | `interviews.create` | Schedule (`InterviewValidator`) |
| PUT | `/interviews/{interview}` | `interviews.edit` | Reschedule/edit |
| POST | `/interviews/{interview}/outcome` | `interviews.record_outcome` | Result + feedback → may drive application status |
| DELETE | `/interviews/{interview}` | `interviews.delete` | Remove |

---

## 9. Medical & visa

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/candidates/{candidate}/medical` | `medical.view` | Medical records |
| POST | `/candidates/{candidate}/medical` | `medical.create` | Add (`MedicalValidator`) |
| PUT | `/medical/{record}` | `medical.edit` | Update result/dates |
| DELETE | `/medical/{record}` | `medical.delete` | Remove |
| GET | `/visa` | `visa.view` | Visa queue, filters: status, country, expiry window |
| GET | `/candidates/{candidate}/visa` | `visa.view` | Candidate visa applications |
| POST | `/candidates/{candidate}/visa` | `visa.create` | Create (`VisaValidator`) |
| PUT | `/visa/{visa}` | `visa.edit` | Update fields (optimistic lock) |
| POST | `/visa/{visa}/status` | `visa.change_status` | Transition → `visa_status_history` |
| POST | `/visa/{visa}/status/override` | `visa.override_status` | Non-allowlisted (reason, audit) |
| DELETE | `/visa/{visa}` | `visa.delete` | Remove |

---

## 10. Travel, departure, placement, tours

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/candidates/{candidate}/travel` | `travel.view` | Travel profile + bookings + departure |
| PUT | `/candidates/{candidate}/travel/profile` | `travel.profile.manage` | Update readiness |
| POST | `/candidates/{candidate}/flights` | `travel.tickets.manage` | Add flight booking (`FlightBookingValidator`) |
| PUT | `/flights/{booking}` | `travel.tickets.manage` | Update PNR/status |
| POST | `/candidates/{candidate}/departure` | `travel.departure.manage` | Record departure/arrival |
| POST | `/applications/{application}/placement` | `travel.placement.manage` | Create placement (unique per application) |
| PUT | `/placements/{placement}` | `travel.placement.manage` | Update status (active/completed/terminated/absconded) |
| GET | `/tours/packages` | `tours.packages.view` | List |
| GET/POST | `/tours/packages/create`, `/tours/packages` | `tours.packages.create` | Create (`TourPackageValidator`; sanitize HTML) |
| GET | `/tours/packages/{package}` | `tours.packages.view` | Detail + items |
| PUT | `/tours/packages/{package}` | `tours.packages.edit` | Update |
| POST | `/tours/packages/{package}/publish` | `tours.packages.publish` | Toggle `is_public` |
| DELETE | `/tours/packages/{package}` | `tours.packages.delete` | Soft delete |
| POST/PUT/DELETE | `/tours/packages/{package}/items[/{id}]` | `tours.packages.edit` | CRUD itinerary |
| GET | `/tours/bookings` | `tours.bookings.view` | List, filters: status, travel date, package |
| POST | `/tours/bookings` | `tours.bookings.create` | Create (`TourBookingValidator`; person link) |
| GET | `/tours/bookings/{booking}` | `tours.bookings.view` | Detail + payments |
| PUT | `/tours/bookings/{booking}` | `tours.bookings.edit` | Update (optimistic lock) |
| POST | `/tours/bookings/{booking}/status` | `tours.bookings.change_status` | Transition |
| DELETE | `/tours/bookings/{booking}` | `tours.bookings.delete` | Cancel |
| GET | `/tours/bookings/export` | `tours.bookings.export` + `exports.run` | Export |

---

## 11. Finance

| Method | Path | Permission | Extras | Purpose |
|---|---|---|---|---|
| GET | `/invoices` | `invoices.view` | `BindBranchScope` | List, filters: status, due, person, invoiceable type |
| POST | `/invoices` | `invoices.create` | `ValidateInput:InvoiceValidator` | Create + lines (transaction) |
| GET | `/invoices/{invoice}` | `invoices.view` | policy | Detail + allocations + payments |
| PUT | `/invoices/{invoice}` | `invoices.edit` | optimistic lock | Edit draft only |
| POST | `/invoices/{invoice}/void` | `invoices.void` | reason, audit | Void (no delete) |
| GET | `/invoices/{invoice}/pdf` | `invoices.view` | — | Rendered PDF |
| GET | `/payments` | `payments.view` | `BindBranchScope` | List |
| POST | `/payments` | `payments.create` | `RateLimit:payments.write`, `ValidateInput:PaymentValidator`, `Idempotency-Key` | Record payment + allocations + receipt (transaction, §1.4 of ARCHITECTURE) |
| GET | `/payments/{payment}` | `payments.view` | policy | Detail |
| PUT | `/payments/{payment}` | `payments.edit` | optimistic lock, audit | Correct metadata (not amount) |
| POST | `/payments/{payment}/reverse` | `payments.reverse` | reason, audit | Reversing entry (original immutable) |
| POST | `/payments/{payment}/allocations` | `allocations.manage` | `ValidateInput:AllocationValidator` | Allocate to invoice(s) (≤ balances) |
| GET | `/payments/{payment}/receipt` | `receipts.view` | — | Receipt view |
| GET | `/payments/{payment}/receipt/pdf` | `receipts.view` | — | Receipt PDF |
| POST | `/payments/{payment}/receipt` | `receipts.issue` | — | (Re)issue receipt with new number |
| GET | `/refunds` | `refunds.view` | `BindBranchScope` | List |
| POST | `/refunds` | `refunds.create` | `ValidateInput:RefundValidator` | Request (≤ payment − already refunded) |
| POST | `/refunds/{refund}/approve` | `refunds.approve` | limit check, audit | Approve (≤ `settings.finance.refund_approval_limit`) |
| POST | `/refunds/{refund}/reject` | `refunds.reject` | reason | Reject |
| POST | `/refunds/{refund}/mark-paid` | `refunds.mark_paid` | audit | Mark disbursed |

---

## 12. Reports & exports

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/reports` | `reports.view` | Report index |
| GET | `/reports/{report}` | `reports.view` (+ `reports.finance.view` for finance reports) | report ∈ leads, lead-source, counselor-performance, candidates, jobs, employers, applications, interviews, selections, visa, placements, payments, outstanding, refunds, travel-bookings — all with filters + date range + pagination + print view |
| POST | `/reports/{report}/export` | `reports.export` + `exports.run` | Queue streamed CSV → `export_jobs` |
| GET | `/exports` | `exports.run` | My export jobs |
| GET | `/exports/{job}/download` | `exports.run` (owner) | Download completed CSV (before `expires_at`) |

---

## 13. Communication

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/{entity}/{id}/communication` | `communication.view` | Timeline for lead/candidate/employer/application/tour_booking |
| POST | `/{entity}/{id}/communication` | `communication.log` | Log a call/WhatsApp/SMS/email/meeting/note (summary only) |
| GET | `/api/whatsapp-templates` | auth | Templates for client-side `wa.me` links |

---

## 14. Admin

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/admin/users` | `users.view` | User list (branch-scoped for manager, read) |
| GET/POST | `/admin/users/create`, `/admin/users` | `users.manage` | Create user (`UserValidator`; role/branch assignment) |
| PUT | `/admin/users/{user}` | `users.manage` | Update (role, branches, active) — cannot elevate above own role |
| POST | `/admin/users/{user}/deactivate` | `users.manage` | Disable + kill sessions |
| POST | `/admin/users/{user}/reset-password` | `users.manage` | Force reset |
| GET/PUT | `/admin/roles` | `roles.manage` | Role → permission matrix editor (audited) |
| GET/PUT | `/admin/settings` | `settings.manage` | App settings (statuses, countries, doc types, priorities, notification rules, finance limits, timezone, mail, SEO defaults) |
| GET | `/admin/audit` | `audit.view` | Audit log viewer (filter by user/module/record/date); **read only** |
| GET | `/admin/public-enquiries` | `public_enquiries.view` | Inbound contact/job-apply/travel enquiries |
| POST | `/admin/public-enquiries/{enquiry}/convert` | `public_enquiries.convert` | → lead |
| POST | `/admin/public-enquiries/{enquiry}/spam` | `public_enquiries.view` | Mark spam |
| GET | `/admin/cron` | `super_admin`/`admin` | `cron_runs` history + last status |
| GET | `/admin/system` | `super_admin` | Version, migration status, storage usage, queue depths |

---

## 15. Public site (no auth, indexable, cached)

| Method | Path | Middleware | Purpose / SEO |
|---|---|---|---|
| GET | `/` | Public | Home; `Organization` + `WebSite` JSON-LD |
| GET | `/about` | Public | About |
| GET | `/contact` | Public | Contact info + form |
| POST | `/contact` | Public + `VerifyCsrf` + `RateLimit:public_form` + honeypot/captcha | → `public_enquiries` (type=contact) |
| GET | `/jobs` | Public + `PublicCache` | Public job listing (only `is_public`, `status IN(open,interview)`); filters by country/title; paginated; canonical |
| GET | `/jobs/{country}/{slug}` | Public + `PublicCache` | Job detail; unique title/meta/canonical/OG; `JobPosting` JSON-LD **only if data qualifies** |
| POST | `/jobs/{country}/{slug}/apply` | Public + `VerifyCsrf` + `RateLimit:public_form` + upload pipeline (CV) | → `public_enquiries` (type=job_apply, job_id) |
| GET | `/travel-packages` | Public + `PublicCache` | Public packages (`is_public`, `status=active`) |
| GET | `/travel-packages/{slug}` | Public + `PublicCache` | Package detail; `Product`/`TouristTrip` + `Offer` JSON-LD if price real |
| POST | `/travel-packages/{slug}/enquire` | Public + `VerifyCsrf` + `RateLimit:public_form` | → `public_enquiries` (type=travel_enquiry) |
| GET | `/blog` | Public + `PublicCache` | Blog index |
| GET | `/blog/{slug}` | Public + `PublicCache` | Article; `BlogPosting` JSON-LD |
| GET | `/sitemap.xml` | Public + cache | Generated sitemap (static + public jobs + public packages + blog) |
| GET | `/robots.txt` | Public | Allows public paths; disallows `/dashboard`, `/leads`, `/candidates`, `/applications`, `/admin`, `/api`, `/login`, `/documents`, `/reports`, `/exports` |
| GET | `/{old-slug}` (job/package) | Public | 301 → current slug (slug-change map) |

---

## 16. Error routes (rendered by exception handler, not routed)

| Status | Trigger | Page |
|---|---|---|
| 403 | Authorization/policy failure | "You don't have access to this." + back link |
| 404 | Unknown route / unresolved `public_id` / soft-deleted | Helpful 404 (public: links to `/jobs`) |
| 419 | CSRF token missing/invalid | "Your session expired, please retry." + re-login |
| 429 | Rate limit exceeded | "Too many attempts, try again in N seconds." (`Retry-After`) |
| 409 | Stale record (optimistic lock) / duplicate | "This record changed since you loaded it — reload and reapply." |
| 422 | Validation failure | Re-render form with old input + inline errors (JSON for API) |
| 503 | Maintenance mode | Branded maintenance page |
| 500 | Unhandled | Generic apology + request id; full detail only in logs |

---

## 17. Rate-limit buckets (`config/rate_limits.php`)

| Bucket | Key | Limit (default) |
|---|---|---|
| `login` | `login:ip:<ip>` + `login:email:<email>` | 5 / 15 min, then lockout |
| `password_reset` | `pwreset:ip:<ip>` | 3 / hour |
| `search` | `search:user:<id>` | 30 / min |
| `dashboard` | `dash:user:<id>` | 60 / min |
| `write` | `write:user:<id>` | 120 / min |
| `payments.write` | `pay:user:<id>` | 20 / min (+ idempotency key) |
| `upload` | `upload:user:<id>` | 30 / 10 min |
| `import` | `import:user:<id>` | 5 / hour |
| `export` | `export:user:<id>` | 10 / hour |
| `public_form` | `pubform:ip:<ip>` | 5 / hour + honeypot + captcha |
