# Phase 0 — Database Design (points 3, 4, 5)

Canonical DDL: **`database/schema/schema.sql`**. This document gives the ERD-style
relationship narrative, the index strategy rationale, and per-table notes. Where
this text and the SQL disagree, the SQL wins and this doc is fixed.

Global conventions: InnoDB, `utf8mb4_unicode_ci`, `BIGINT UNSIGNED` PKs, all
timestamps stored **UTC**, `DECIMAL(14,2)` money, `CHAR(3)` ISO-4217 currency,
`CHAR(2)` ISO-3166 country, `CHAR(26)` ULID `public_id` on URL-exposed entities,
`created_at`/`updated_at` on mutable tables, soft delete (`deleted_at`) only on
`persons, leads, candidates, employers, jobs, tour_packages, users`, optimistic
locking (`record_version`) on `leads, candidates, applications, visa_applications,
invoices, payments, refunds, tour_bookings, candidate_documents`.

---

## 1. ERD-style relationship description

### 1.1 Identity & org

```
branches 1───∞ users            (users.primary_branch_id, nullable)
branches ∞───∞ users            via user_branches (multi-branch operation)
roles    1───∞ users            (users.role_id, required)
roles    ∞───∞ permissions      via role_permissions
users    ∞───∞ permissions      via user_permissions (effect allow|deny; deny wins)
users    1───∞ sessions
users    1───∞ password_resets
```

`branches` is referenced by nearly every operational table as `branch_id`
(the row's owning office). Authorization narrows queries to the acting user's
branch set unless the user is `is_org_wide = 1`.

### 1.2 Shared person identity

```
persons 1───1 candidates        (candidates.person_id UNIQUE — one candidate profile per person)
persons 1───∞ leads             (leads.person_id nullable; set on/after conversion)
persons 1───∞ tour_bookings     (travel customer)
persons 1───∞ invoices
persons 1───∞ payments
persons 1───∞ refunds
```

A `person` is the single human identity. Recruitment detail hangs off
`candidates`; travel detail hangs off `tour_bookings`. A person can be both.

### 1.3 Lead pipeline

```
lead_sources   1───∞ leads
lead_statuses  1───∞ leads          (configurable; is_terminal / is_won flags)
branches       1───∞ leads
users          1───∞ leads          (leads.assigned_to, leads.created_by)
leads          1───∞ lead_notes
leads          1───∞ lead_followups
leads          1───1 candidates     (leads.converted_candidate_id ↔ candidates.origin_lead_id)
lead_followups ∞───1 users          (assigned_to)
```

Conversion (Phase 2) is a transaction: create/attach `persons`, create
`candidates`, set `leads.status → won`, `converted_at`, `converted_candidate_id`,
audit.

### 1.4 Candidate 360°

```
candidates 1───1 persons
candidates 1───∞ candidate_addresses          (permanent / current / emergency)
candidates 1───∞ candidate_education
candidates 1───∞ candidate_experience
candidates ∞───∞ skills  via candidate_skills  (proficiency, years)
candidates 1───1 candidate_preferences
candidates 1───∞ passports                     (multiple allowed; is_primary; held_by)
candidates 1───∞ candidate_documents
candidates 1───∞ candidate_document_checklist
candidates 1───∞ applications
candidates 1───∞ interviews          (denormalized candidate_id for fast filters)
candidates 1───∞ medical_records
candidates 1───∞ visa_applications
candidates 1───1 travel_profiles
candidates 1───∞ flight_bookings
candidates 1───∞ departure_records
candidates 1───∞ placements
candidates 1───∞ tasks               (via tasks.related_type='candidate')
```

### 1.5 Documents

```
document_types 1───∞ candidate_documents
document_types 1───∞ candidate_document_checklist
candidate_documents 1───∞ document_access_log
candidate_documents 0..1 ← medical_records.certificate_document_id
candidate_documents 0..1 ← flight_bookings.ticket_document_id
candidate_documents 0..1 ← candidates.profile_photo_document_id
```

`candidate_document_checklist` seeds from `document_types.is_required_default`
and may be extended per job/employer requirement; `satisfied_document_id` points
at the verified document that fulfils the requirement.

### 1.6 Employers & jobs

```
employers 1───∞ employer_contacts     (one is_primary)
employers 1───∞ jobs
employers 1───∞ applications           (denormalized employer_id)
employers 1───∞ interviews             (denormalized)
employers 1───∞ placements
branches  1───∞ employers              (account ownership; nullable)
users     1───∞ employers              (account_owner)
jobs      1───∞ job_requirements       (skill_id nullable; is_mandatory; weight)
jobs      1───∞ job_benefits
skills    1───∞ job_requirements
```

### 1.7 Applications, interviews, transitions

```
candidates 1──┐
jobs       1──┼─∞ applications          (UNIQUE (candidate_id, job_id))
employers  1──┘
branches   1───∞ applications
applications 1───∞ application_status_history   (append-only; is_override flag)
applications 1───∞ interviews
applications 0..1 ← medical_records.application_id
applications 0..1 ← visa_applications.application_id
applications 0..1 ← travel_profiles.application_id
applications 0..1 ← flight_bookings.application_id
applications 1───1 placements           (UNIQUE application_id)
```

`applications.status` is a transition-engine key (VARCHAR + app allowlist, not a
DB enum, so business can reconfigure). Every change writes
`application_status_history`. Same pattern: `visa_status_history` for
`visa_applications`.

### 1.8 Medical & visa

```
candidates      1───∞ medical_records
applications    0..1─∞ medical_records
candidate_documents 0..1 ← medical_records.certificate_document_id
candidates      1───∞ visa_applications
applications    0..1─∞ visa_applications
visa_applications 1───∞ visa_status_history
```

### 1.9 Travel, departure, placement, tours

```
candidates 1───1 travel_profiles
candidates 1───∞ flight_bookings ───0..1 departure_records
flight_bookings 0..1 ← departure_records.flight_booking_id
applications 1───1 placements
tour_packages 1───∞ tour_package_items
tour_packages 1───∞ tour_bookings
persons       1───∞ tour_bookings
branches      1───∞ tour_bookings
```

### 1.10 Finance ledger

```
persons   1───∞ invoices
branches  1───∞ invoices
invoices  1───∞ invoice_lines
invoices  polymorphic → applications | tour_bookings   (invoiceable_type + invoiceable_id;
                                                        no FK — validated in service +
                                                        nightly integrity check)
persons   1───∞ payments
payments  ∞───∞ invoices  via payment_allocations       (UNIQUE (payment_id, invoice_id))
payments  1───∞ receipts                                 (immutable snapshot)
payments  1───∞ refunds
invoices  0..1─∞ refunds
```

Derived money fields (`invoices.amount_paid`, `amount_refunded`, and the computed
`outstanding = grand_total - amount_paid - discount? ...`) are maintained
**only** by services inside transactions, guarded by `record_version`.
`CHECK` constraints forbid negatives and non-positive amounts.

### 1.11 Tasks, notifications, communication, audit

```
users    1───∞ tasks           (assigned_to)
branches 1───∞ tasks
tasks    polymorphic → related_type/related_id (lead|candidate|application|payment|
                                                invoice|visa|travel|employer|tour_booking)
users    1───∞ notifications                    (dedupe_key UNIQUE → idempotent automation)
users    1───∞ communication_logs
users    1───∞ activity_logs                    (user_id NULL = system/cron; append-only)
```

### 1.12 Supporting

```
settings              key/value JSON (is_public flag gates public-site exposure)
countries             ISO reference (is_gcc)
whatsapp_templates    wa.me message templates with {{placeholders}}
email_log             outbound mail queue + delivery status
import_batches 1───∞ import_rows
export_jobs           queued report exports (cron-drained; expiring files)
cron_runs / cron_locks  scheduler ledger + overlap guard
number_sequences      gap-free per-scope document numbering (SELECT ... FOR UPDATE)
public_enquiries      public contact / job-apply / travel-enquiry submissions
schema_migrations     applied migration versions
```

---

## 2. Referential-integrity policy (`ON DELETE`)

| Pattern | Behaviour | Examples |
|---|---|---|
| Child rows meaningless without parent | `ON DELETE CASCADE` | `lead_notes`, `lead_followups`, `candidate_*` sub-tables, `job_requirements`, `application_status_history`, `import_rows`, `document_access_log` |
| Historical / financial / audit records | **No cascade** — `RESTRICT` (default). Parent can't be hard-deleted while referenced | `applications ← placements`, `payments ← receipts`, `payments ← refunds`, `invoices ← payment_allocations`, anything ← `activity_logs` (logs keep `record_id` as a loose value, no FK) |
| Optional linkage | `ON DELETE SET NULL` | `medical_records.application_id`, `visa_applications.application_id`, `flight_bookings.application_id`, `candidate_document_checklist.satisfied_document_id`, `public_enquiries.job_id/lead_id` |
| Business entities | **Soft delete only** (`deleted_at`), never hard-deleted in normal flow | `candidates`, `employers`, `jobs`, `leads`, `tour_packages`, `persons`, `users` |

`activity_logs` deliberately has **no** foreign keys on `record_id` so the audit
trail survives even if a row it references is later purged under a retention
policy.

---

## 3. Index strategy

### 3.1 Principles

- Index every column used in `WHERE`, `JOIN`, `ORDER BY` on a list/search screen.
- Composite indexes ordered to serve the **most common** query: equality columns first, then range/sort column last — typically `(branch_id, status, created_at)`.
- Unique indexes enforce business identity: all `*_number`, all `public_id`, `applications (candidate_id, job_id)`, `payment_allocations (payment_id, invoice_id)`, `candidates.person_id`, `passports.passport_number`, `notifications.dedupe_key`, `tasks.dedupe_key`, `payments.idempotency_key`.
- Foreign-key columns are always indexed (MySQL requires it; we name them explicitly).
- Keyset-pagination tables carry `(created_at, id)` coverage via existing `idx_*_created` + PK.
- Avoid over-indexing write-heavy tables (`activity_logs`, `communication_logs`, `notifications`) — only the indexes actually queried.

### 3.2 Notable composite / functional indexes

| Table | Index | Serves |
|---|---|---|
| `leads` | `(branch_id, status_id)`, `idx_leads_created`, `idx_leads_phone`, `idx_leads_alt_phone`, `idx_leads_email`, `idx_leads_country` | list by branch+status with date sort; duplicate detection; country report; global search |
| `lead_followups` | `(assigned_to, status, due_date)`, `(branch_id, due_date)` | "my follow-ups today/overdue"; branch follow-up load; cron sweep |
| `candidates` | `(branch_id)`, `(assigned_counselor)`, `(stage)` | pipeline board, counselor workload |
| `candidate_documents` | `(candidate_id, document_type_id)`, `(status)`, `(expires_at)` | checklist render; verification queue; expiry cron |
| `passports` | `(expiry_date)`, `(candidate_id)` | expiry cron; profile tab |
| `jobs` | `(country, status)`, `(is_public, status)`, `(employer_id)` | job search, public listing, employer jobs |
| `applications` | `(job_id, status)`, `(branch_id, status)`, `(candidate_id)`, `(employer_id)` | pipeline per job, branch dashboard, candidate tab, employer report |
| `application_status_history` | `(application_id, changed_at)` | timeline |
| `interviews` | `(scheduled_date, status)`, `(application_id)`, `(candidate_id)` | calendar, reminders |
| `visa_applications` | `(status)`, `(expiry_date)`, `(candidate_id)` | visa queue, expiry cron |
| `invoices` | `(branch_id, status)`, `(due_on, status)`, `(invoiceable_type, invoiceable_id)`, `(person_id)` | outstanding report, reminder cron, per-application/booking, customer ledger |
| `payments` | `(branch_id, paid_at)`, `(person_id)` | daily collection report, customer ledger |
| `tasks` | `(assigned_to, status, due_date)`, `(branch_id, due_date)`, `(related_type, related_id)` | my tasks, branch board, entity tab |
| `notifications` | `(user_id, read_at, created_at)` | bell dropdown, unread count |
| `activity_logs` | `(record_type, record_id, created_at)`, `(user_id, created_at)`, `(module, created_at)` | record timeline, user activity, module audit |
| `communication_logs` | `(related_type, related_id, occurred_at)` | entity communication tab |
| `login_attempts` | `(email, attempted_at)`, `(ip_address, attempted_at)` | brute-force throttling |
| `sessions` | `(user_id)`, `(last_activity)` | session listing, GC |

### 3.3 Full-text / search

Global search (section 44) queries: candidate name, phone, email, candidate
number, passport number, lead number, job number, application number, employer
name. V1 uses **targeted indexed lookups** per entity (exact/prefix match on the
indexed columns above, `LIKE 'term%'` on name columns which uses the B-tree
prefix). If free-text relevance is later needed, add `FULLTEXT` indexes on
`persons.full_name`, `employers.company_name`, `jobs.title` — MySQL 8 InnoDB
supports it without extra infrastructure. Every search branch is
authorization-filtered before results are returned.

---

## 4. Table-by-table summary

Columns, exact types, every index and FK are in `schema.sql`. This is the
purpose-and-rules layer.

### Identity / RBAC / org

| Table | Purpose | Rules & notes |
|---|---|---|
| `branches` | Physical offices / cost centres | `code` unique; soft-inactive via `is_active`; every operational record carries `branch_id`. |
| `roles` | Named permission bundles | `is_system=1` rows (super_admin, admin, …) cannot be deleted or renamed via UI. |
| `permissions` | Atomic action strings (`module.action`) | Seeded, immutable set; adding one is a migration. |
| `role_permissions` | Role → permission grants | Edited only via `settings.manage` / users.manage; every change audited. |
| `users` | Staff accounts | `password_hash` (bcrypt/argon2id); `failed_login_count` + `locked_until` for lockout; `is_org_wide` bypasses branch scoping; soft delete keeps audit references valid. |
| `user_branches` | Multi-branch membership | If empty, user is limited to `primary_branch_id`. |
| `user_permissions` | Per-user override | `effect='deny'` always beats a role grant. |
| `sessions` | Server-side session store | Payload is opaque; row deletion = forced logout; GC by `cleanup.php`. |
| `password_resets` | One-time reset tokens | `token_hash` = sha256(random); single-use (`used_at`); expiry enforced; generic response prevents enumeration. |
| `login_attempts` | Auth telemetry | Feeds rate limiter + lockout; pruned after 30 days. |
| `rate_limits` | Generic fixed-window limiter | Key `bucket:scope:id`; `cleanup.php` drops stale windows. |

### Persons / leads

| Table | Purpose | Rules & notes |
|---|---|---|
| `persons` | Shared human identity | Duplicate signals: `primary_phone`, `alternate_phone`, `email` indexed; merge tool consolidates and repoints children. |
| `lead_sources` | Configurable acquisition channels | |
| `lead_statuses` | Configurable pipeline stages | `is_terminal`, `is_won` drive conversion + reporting; ordering via `sort_order`. |
| `leads` | Prospective candidate/customer | `lead_number` = `LEAD-<yyyy>-<seq>` (gap-free); `record_version` for concurrent edits; `converted_*` set transactionally on conversion; soft delete. |
| `lead_notes` | Free-text notes | Append-only in practice; cascade with lead. |
| `lead_followups` | Scheduled touchpoints | `status` pending/completed/cancelled; overdue is derived (due_date < today AND pending); cron notifies assignee. |

### Candidates

| Table | Purpose | Rules & notes |
|---|---|---|
| `candidates` | Recruitment master record | 1:1 with `persons`; `candidate_number` gap-free; `stage` is a denormalized pipeline snapshot for board views (source of truth = latest `application_status_history` / module states); soft delete. |
| `candidate_addresses` | permanent/current/emergency | |
| `candidate_education` / `candidate_experience` | History rows | Dates validated (`start <= end`, not future beyond today for `end` unless `is_current`). |
| `skills` / `candidate_skills` | Skill taxonomy + proficiency | Skills shared with `job_requirements` for matching. |
| `candidate_preferences` | 1:1 preferences | JSON arrays for countries/titles; used by match engine. |
| `passports` | One or more passports | `passport_number` globally unique; `expiry_date` indexed for cron; `held_by` tracks physical custody. |

### Documents

| Table | Purpose | Rules & notes |
|---|---|---|
| `document_types` | Configurable catalogue | Per-type `allowed_mime`, `max_size_kb`, `has_expiry`, `is_required_default`. |
| `candidate_documents` | Uploaded files (metadata only) | `mime_type` is **server-detected**; `storage_path` random ULID outside webroot; `status` lifecycle pending→uploaded→under_review→verified/rejected/expired; `record_version` prevents dual-reviewer clash; rejection requires reason. |
| `candidate_document_checklist` | Required-doc tracking | Unique `(candidate_id, document_type_id)`; `satisfied_document_id` links the verified file. |
| `document_access_log` | Who viewed/downloaded what | Written on every serve; never exposed to non-admins. |

### Employers / jobs

| Table | Purpose | Rules & notes |
|---|---|---|
| `employers` | Client companies | `employer_number` gap-free; `status` incl. `blacklisted`; `license_expiry` can feed reminders; soft delete. |
| `employer_contacts` | People at the employer | One `is_primary`. |
| `jobs` | Vacancies | `job_number` + `slug` unique; `is_public` + `status IN(open,interview)` gates public listing; `description_html` sanitized on write and render; soft delete; `deadline` → structured-data `validThrough`. |
| `job_requirements` | Skills/criteria with weight | `is_mandatory` feeds match gating; `weight` feeds score. |
| `job_benefits` | Display list | |

### Applications / interviews

| Table | Purpose | Rules & notes |
|---|---|---|
| `applications` | Candidate↔job↔employer link | Unique `(candidate_id, job_id)` (re-apply handled by relaxing to add `cycle_no` if business needs it); `status` = transition key; `match_score`/`match_breakdown` cached from engine; `record_version`. |
| `application_status_history` | Every transition | Append-only; `is_override=1` when transition wasn't in the allowlist (needs `*.override_status` + reason, also in `activity_logs`). |
| `interviews` | Rounds | Denormalized candidate/job/employer ids for fast filtering; status + result; feeds application status (selected/rejected). |

### Medical / visa

| Table | Purpose | Rules & notes |
|---|---|---|
| `medical_records` | Fitness processing | `result` fit/unfit/retest; `expires_at` → cron; optional certificate document link. |
| `visa_applications` | Visa processing | `status` machine + `visa_status_history`; `expiry_date` → cron windows {180,90,30}; `record_version`. |
| `visa_status_history` | Append-only transitions | |

### Travel / placement / tours

| Table | Purpose | Rules & notes |
|---|---|---|
| `travel_profiles` | 1:1 travel readiness per candidate | `readiness` mirrors the visa→departure flow. |
| `flight_bookings` | Tickets | `pnr`, airline, route, times; `status` planned→booked→issued→flown/cancelled; optional ticket document. |
| `departure_records` | Actual departure/arrival | `arrival_confirmed_by`, `placement_confirmed_at`. |
| `placements` | Successful placement | Unique per application; drives placement reports + (future) employer billing milestones. |
| `tour_packages` | Sellable travel products | `slug` unique; `is_public` + `status='active'` gates public page; rich-text fields sanitized; soft delete. |
| `tour_package_items` | Itinerary lines | Day-wise. |
| `tour_bookings` | Travel sales | `person_id` (shared identity); `booking_number` gap-free; `status` inquiry→…→completed; `record_version`; totals reconciled with finance ledger. |

### Finance

| Table | Purpose | Rules & notes |
|---|---|---|
| `invoices` | Amount owed | Polymorphic `invoiceable` (application/tour_booking/other) validated in service; `amount_paid`/`amount_refunded` maintained server-side only; `CHECK` non-negative; `status` draft→issued→partially_paid→paid→void; `record_version`. |
| `invoice_lines` | Line items | `line_total` computed server-side. |
| `payments` | Money received | `amount > 0` (`CHECK`); `idempotency_key` unique (double-submit guard); `payment_number` + `receipt_number` gap-free unique; `status` recorded/reversed (reversal is a new audited action, original row immutable). |
| `payment_allocations` | Payment → invoice apportionment | Unique `(payment_id, invoice_id)`; service enforces Σ allocations ≤ payment.amount and per-invoice ≤ outstanding. |
| `refunds` | Money returned | `amount > 0` and ≤ (payment.amount − already refunded); approval flow (`pending→approved→paid`/`rejected`); `refunds` permission distinct; `record_version`. |
| `receipts` | Immutable proof | `snapshot_json` frozen at issue; never updated. |
| `number_sequences` | Gap-free counters | `SELECT ... FOR UPDATE` inside the same transaction as the insert. |

### Tasks / notifications / comms / audit

| Table | Purpose | Rules & notes |
|---|---|---|
| `tasks` | Work items | Polymorphic `related_*`; `dedupe_key` unique for idempotent system tasks; `status` pending/completed/cancelled, overdue derived. |
| `notifications` | In-app alerts | `dedupe_key` unique → automated notifications are idempotent across cron runs; `link_type`/`link_id`/`link_fragment` = actionable deep link (e.g. candidate + `passport` tab). |
| `communication_logs` | Call/WhatsApp/SMS/email/meeting/note records | Store summary + metadata, **not** full sensitive message bodies. |
| `activity_logs` | Immutable audit trail | `user_id NULL` = system; `old_values`/`new_values` JSON; no FKs so it outlives purges; no UPDATE/DELETE at app level. |

### Supporting

| Table | Purpose | Rules & notes |
|---|---|---|
| `settings` | Runtime config | JSON values; `is_public=1` explicitly whitelists keys the public site may read. |
| `countries` | ISO reference + `is_gcc` | |
| `whatsapp_templates` | `wa.me` message bodies | `{{name}}`, `{{date}}` placeholders resolved server-side. |
| `email_log` | Outbound queue + status | Drained by `cron/process-email-queue.php` with capped retries. |
| `import_batches` / `import_rows` | CSV import pipeline | Preview → validate → transactional import; failed-row report path stored; every import audited. |
| `export_jobs` | Queued report exports | Cron streams to `storage/exports/<ulid>.csv`; `expires_at` → purged by cleanup. |
| `cron_runs` / `cron_locks` | Scheduler observability + overlap guard | |
| `public_enquiries` | Public form submissions | Rate-limited + honeypot/captcha at write; `status` new/reviewed/converted/spam; optional `lead_id` when converted. |
| `schema_migrations` | Applied migrations | Populated by `scripts/migrate.php`. |

---

## 5. Data-integrity checks that live outside FKs

Enforced by services + a nightly `cron/integrity-check.php` (report-only, alerts admin):

1. `invoices.invoiceable_id` actually exists in the table named by `invoiceable_type`.
2. `Σ payment_allocations.amount` for a payment ≤ `payments.amount`.
3. `Σ payment_allocations.amount` for an invoice ≤ `invoices.grand_total`.
4. `invoices.amount_paid` == `Σ allocations` for that invoice.
5. `Σ refunds.amount` (status paid) for a payment ≤ `payments.amount`.
6. No `applications` row whose `employer_id` ≠ its `job.employer_id`.
7. `candidate_documents.status='verified'` ⇒ `verified_by` and `verified_at` not null.
8. Exactly one `is_primary` per `employer_contacts` group (0 allowed, not 2+).
9. `passports`: at most one `is_primary=1` per candidate.
10. Orphan check: every `candidates.person_id` resolves; every soft-deleted parent has no active children in states that require a live parent.
