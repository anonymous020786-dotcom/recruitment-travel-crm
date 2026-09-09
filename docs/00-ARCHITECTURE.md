# Phase 0 — System Architecture

**Project:** Overseas Recruitment + Job Placement + Travel & Tour CRM
**Target host:** Hostinger Shared Hosting (Apache + PHP 8.2+ + MySQL 8+), migration-ready for VPS/cloud
**Status:** Architecture only. No application code until Phase 1 is explicitly approved.

This document answers points 1, 2, 8–19 of the Phase 0 brief. Companion documents:

| File | Contents |
|---|---|
| `docs/01-DATABASE.md` | ERD prose, table-by-table columns/types/indexes/FKs/constraints (points 3–5) |
| `database/schema/schema.sql` | Canonical DDL |
| `docs/02-RBAC.md` | Roles, permission catalogue, role→permission matrix, field-level rules (point 6) |
| `docs/03-ROUTES.md` | Full public + CRM + API route map with method, permission, middleware (point 7) |

---

## 1. Complete system architecture

### 1.1 Runtime topology (single shared-hosting account)

```
                          Internet (HTTPS only, HSTS)
                                    │
                     ┌──────────────┴───────────────┐
                     │      Apache + mod_rewrite     │
                     │  DocumentRoot → /public       │
                     └──────────────┬───────────────┘
                                    │  every request → public/index.php
             ┌──────────────────────┴───────────────────────┐
             │                Front Controller               │
             │  bootstrap → env → error/exception handler →   │
             │  session start → Router                        │
             └──────────────────────┬───────────────────────┘
                                    │
        ┌───────────── Middleware pipeline (ordered) ─────────────┐
        │ SecurityHeaders → RequestId → EnforceHttps → StartSession│
        │ → Authenticate → VerifyCsrf → Authorize(permission)      │
        │ → RateLimit → ValidateInput → BindBranchScope            │
        └──────────────────────┬────────────────────────────────┘
                               │
                        Controller (thin)
                               │
                        Service (business rules, transactions, audit)
                               │
                Repository / Model (PDO prepared statements)
                               │
                          MySQL 8 (InnoDB)
                               │
        ┌──────────────────────┴───────────────────────┐
        │  Storage (outside webroot): storage/private   │
        │  documents, exports, logs, cache, import tmp  │
        └──────────────────────────────────────────────┘

   Cron (Hostinger scheduler) → cron/*.php → same Service layer, CLI SAPI
```

### 1.2 Layers and responsibilities

| Layer | Responsibility | Must NOT |
|---|---|---|
| **Front controller** (`public/index.php`) | Bootstrap, load env, register handlers, hand off to Router | Contain business logic |
| **Router** (`routes/web.php`, `routes/api.php`) | Map method+path → `[Controller, action]` + middleware list; resolve public IDs | Query the DB |
| **Middleware** | Cross-cutting gate checks (auth, CSRF, RBAC, rate limit, headers, branch scope, validation dispatch) | Contain domain workflows |
| **Controllers** (`app/Controllers`) | Parse request DTO, call validator, call one service method, choose view/JSON + status | Contain SQL, transactions, or multi-step workflows |
| **Validators** (`app/Validators`) | Field-level + cross-field rules; return typed errors; define writable-field allowlist (mass-assignment guard) | Touch the DB except for existence/uniqueness checks via repository |
| **Policies** (`app/Policies`) | Answer `can<Verb><Entity>(user, record): bool` incl. branch scope and field visibility | Mutate anything |
| **Services** (`app/Services`) | Business rules, invariants, status-transition engine, DB transactions, audit writes, notification creation, orchestration across repositories | Emit HTML, read `$_POST`/`$_SESSION` directly |
| **Repositories** (`app/Repositories`) | All SQL for one aggregate; prepared statements; pagination; return arrays/DTOs | Contain business decisions or authorization |
| **Models / DTOs** (`app/Models`) | Plain data shapes, value objects (Money, DateRange, PublicId) | Active-record style global queries |
| **Views** (`resources/views`) | Presentation only; escape on output; include page-scoped assets | SQL, business logic, authorization decisions |
| **Helpers** (`app/Helpers`) | `e()` escaping, `csrf_field()`, `url()`, `money()`, `can()` | Hidden side effects |
| **Cron scripts** (`cron/`) | Thin CLI entrypoints → Service layer; locking; run ledger | Duplicate business logic |

### 1.3 Request flow — read

```
GET /candidates?country=AE&sort=created_at&page=2
 → Router matches → CandidateController@index
 → Middleware: SecurityHeaders, EnforceHttps, StartSession, Authenticate,
   Authorize('candidates.view'), RateLimit('list'), BindBranchScope
 → Controller builds ListQuery DTO (page clamp 25/50/100, sort allowlist)
 → CandidateService::paginate(ListQuery, actingUser)
     → CandidateRepository::paginate() — single indexed query + COUNT,
       branch predicate injected from actingUser scope
 → View renders table; row links use candidate.public_id
 → Response: 200 HTML, Cache-Control: private, no-store; X-Robots-Tag: noindex
```

### 1.4 Request flow — write (money example)

```
POST /payments  (CSRF token, Idempotency-Key)
 → Middleware: ... Authenticate, VerifyCsrf, Authorize('payments.create'),
   RateLimit('payments.write'), ValidateInput(PaymentValidator), BindBranchScope
 → PaymentController@store: build PaymentInput DTO (writable fields only)
 → PaymentService::recordPayment(PaymentInput, actingUser):
     BEGIN
       - re-check policy canCreatePayment(user, invoice)
       - check idempotency_key not seen  → else return existing receipt
       - validate: amount > 0, currency matches invoice, allocation ≤ invoice balance
       - insert payments row (record_version = 1)
       - insert payment_allocations (sum ≤ grand_total - amount_paid)
       - UPDATE invoices SET amount_paid = amount_paid + :alloc,
         status = <derived>, record_version = record_version + 1
         WHERE id = :id AND record_version = :expected   (optimistic lock)
       - generate gap-free receipt_number via number_sequences (SELECT ... FOR UPDATE)
       - insert receipts snapshot
       - AuditService::log('payment.created', old=null, new=payment)
     COMMIT   (any failure → ROLLBACK, no partial ledger)
 → 201 JSON { payment: {public_id, receipt_number, ...} }
```

### 1.5 Directory structure (final, adapted from brief section 4)

```
crm/
├── app/
│   ├── Controllers/      Public/  Auth/  Crm/  Api/
│   ├── Models/           value objects + DTOs
│   ├── Repositories/
│   ├── Services/
│   ├── Middleware/
│   ├── Validators/
│   ├── Policies/
│   ├── Support/          Router, Container, Request, Response, View, Db, Csrf,
│   │                     RateLimiter, Hash, Str/Ulid, Money, Clock, Mailer, Logger
│   ├── Domain/           StatusMachine definitions, MatchEngine config loader
│   └── Exceptions/       HttpException, ValidationException, AuthorizationException,
│                         StaleRecordException, DomainRuleException
├── config/               app, database, auth, permissions, upload, mail, cron, seo
├── routes/               web.php  api.php
├── database/
│   ├── migrations/       001_*.sql ... (tracked in schema_migrations)
│   ├── seeders/          roles, permissions, statuses, document_types, countries...
│   └── schema/           schema.sql (canonical reference)
├── public/
│   ├── index.php         front controller
│   ├── .htaccess         rewrite + header + deny rules
│   └── assets/           css/ js/ images/  (built, versioned filenames)
├── resources/
│   ├── views/            layouts/ components/ crm/ public/ errors/ pdf/
│   └── lang/             en/ hi/ ar/  (label files)
├── storage/              logs/ cache/ exports/ imports/ private/documents/  (0700, no web access)
├── cron/                 followups.php document-expiry.php passport-expiry.php
│                         visa-expiry.php payment-reminders.php daily-report.php
│                         process-email-queue.php process-exports.php cleanup.php
├── scripts/              migrate.php  seed.php  create-admin.php  backup.php
├── tests/                Unit/ Feature/ Security/
└── docs/                 this folder
```

On Hostinger, point the domain's document root at `.../crm/public`. If the plan
forbids changing the docroot, place `crm/` under `public_html/` and keep a
hardened `public_html/.htaccess` that (a) routes everything to `crm/public/index.php`
and (b) `Require all denied` on `crm/app`, `crm/config`, `crm/storage`,
`crm/database`, `crm/vendor`, `.env`, `*.sql`, `*.md`, `composer.*`.

---

## 2. Module dependency map

```
                         ┌───────────────┐
                         │  Core/Support │  Router, Container, Db(PDO), Session,
                         │  (no deps)    │  Csrf, RateLimiter, Hash, Ulid, Money,
                         └───────┬───────┘  Clock, Logger, View
                                 │
              ┌──────────────────┼─────────────────────┐
              │                  │                     │
        ┌─────▼─────┐      ┌─────▼──────┐        ┌──────▼──────┐
        │  Auth     │      │  RBAC /    │        │  Audit      │
        │  Sessions │◄────►│  Policies  │◄──────►│  Activity   │
        └─────┬─────┘      └─────┬──────┘        └──────┬──────┘
              │                  │                      │
        ┌─────▼──────────────────▼──────────────────────▼──────┐
        │             Person Identity (persons)                │
        │   shared by Candidates and Tour customers            │
        └───────┬───────────────────────────────┬─────────────┘
                │                               │
      ┌─────────▼─────────┐          ┌──────────▼───────────┐
      │  Leads            │          │  Tour & Travel       │
      │  (sources,        │          │  (packages, bookings)│
      │   statuses,       │          └──────────┬───────────┘
      │   followups)      │                     │
      └─────────┬─────────┘                     │
                │ conversion                    │
      ┌─────────▼───────────────────────────────┼──────────────┐
      │  Candidates (passport, education, exp,  │              │
      │  skills, preferences, timeline)         │              │
      └───┬───────────┬───────────┬─────────────┘              │
          │           │           │                            │
   ┌──────▼───┐  ┌────▼─────┐  ┌──▼───────────────┐            │
   │Documents │  │Employers │  │  Job Matching    │            │
   │(types,   │  │+ Jobs    │──►  (config-driven) │            │
   │ verify,  │  │(reqs,    │  └──┬───────────────┘            │
   │ expiry)  │  │ benefits)│     │                            │
   └────┬─────┘  └────┬─────┘     │                            │
        │             │           │                            │
        │        ┌────▼───────────▼──────┐                     │
        │        │  Applications         │                     │
        │        │  + Status Transition  │                     │
        │        │  Engine + History     │                     │
        │        └────┬─────────────┬────┘                     │
        │             │             │                          │
        │       ┌─────▼────┐   ┌────▼─────┐                    │
        │       │Interviews│   │ Medical  │                    │
        │       └──────────┘   └────┬─────┘                    │
        │                           │                          │
        │                      ┌────▼─────┐                    │
        │                      │  Visa    │                    │
        │                      └────┬─────┘                    │
        │                           │                          │
        │                   ┌───────▼────────┐                 │
        │                   │ Travel/Ticket  │                 │
        │                   │ Departure      │                 │
        │                   │ Placement      │                 │
        │                   └───────┬────────┘                 │
        │                           │                          │
   ┌────▼───────────────────────────▼──────────────────────────▼────┐
   │  Finance: invoices → payments → allocations → receipts/refunds  │
   │  (invoiceable = application | tour_booking)                     │
   └────────────────────────────────┬───────────────────────────────┘
                                    │
        ┌───────────────────────────┼────────────────────────────┐
        │                           │                            │
  ┌─────▼──────┐          ┌─────────▼────────┐          ┌────────▼────────┐
  │ Tasks /    │          │  Notifications   │          │  Dashboard /    │
  │ Follow-ups │          │  (idempotent)    │          │  Reports /      │
  │            │          │                  │          │  Global Search  │
  └─────┬──────┘          └─────────┬────────┘          └────────┬────────┘
        │                           │                            │
        └───────────────┬───────────┴────────────────────────────┘
                        │
              ┌─────────▼──────────┐        ┌────────────────────┐
              │  Cron / Automation │        │  Import / Export   │
              │  (calls services)  │        │  (CSV, streamed)   │
              └────────────────────┘        └────────────────────┘

        ┌───────────────────────────────────────────────────────┐
        │  Public site: Home, About, Contact, Jobs, Job detail,  │
        │  Travel packages, Blog, sitemap.xml, robots.txt        │
        │  Reads ONLY jobs.is_public / tour_packages.is_public;  │
        │  writes → public_enquiries (rate-limited, captcha)     │
        └───────────────────────────────────────────────────────┘
```

**Dependency rules**

- Arrows point from dependent → dependency. No cycles between feature modules.
- Feature modules never call each other's repositories — only each other's **services** (e.g. `ApplicationService` calls `NotificationService::notify()`, not `notifications` table directly).
- `persons` is the only shared write target across Recruitment and Travel; both keep their own profile tables.
- Audit + Notification services are leaf dependencies used everywhere; they must not depend on feature modules.
- Public site is read-mostly and isolated: it may read published jobs/packages and write `public_enquiries` only.

**Build order implied:** Support → Auth/RBAC/Audit → Persons → Leads → Candidates → Documents → Employers/Jobs → Applications/Interviews → Medical/Visa → Travel → Finance → Dashboard/Reports → Automation → Public/SEO → Hardening. (Matches Phase plan in section 18.)

---

## 8. Middleware architecture

Middleware is an ordered pipeline; each returns either "continue" or a `Response`
(short-circuit). Configured per-route and per-group in `routes/*.php`.

| # | Middleware | Applies to | Behaviour |
|---|---|---|---|
| 1 | `RequestId` | all | Generate ULID request id; attach to logger context and `X-Request-Id`. |
| 2 | `SecurityHeaders` | all | Emit CSP, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options: DENY` (CRM) / `SAMEORIGIN` (public), HSTS (prod). |
| 3 | `EnforceHttps` | all (prod) | 301 to `https://` if not already secure (respect `X-Forwarded-Proto`). |
| 4 | `MaintenanceGuard` | all | If `settings.maintenance_mode` and user not super_admin → 503 page. |
| 5 | `StartSession` | all | DB-backed session handler; cookie flags `HttpOnly; Secure; SameSite=Lax`; regenerate id on privilege change; idle timeout (`auth.idle_minutes`, default 30) + absolute lifetime (8h). |
| 6 | `Authenticate` | CRM + API | Require valid session user, `is_active=1`, not locked. Else redirect to `/login` (web) or `401` (API). Sets `Request::user()`. |
| 7 | `VerifyCsrf` | all non-GET web + AJAX mutations | Compare per-session token (`hash_equals`); token in `_token` field or `X-CSRF-Token` header. Reject → 419 page/JSON. Public forms also covered. |
| 8 | `Authorize:<permission>` | protected routes | `PermissionService::userCan(user, 'leads.edit')` using role_permissions + user_permissions overrides (deny wins). Fail → 403. |
| 9 | `BindBranchScope` | CRM data routes | Resolve acting user's visible branch id set (`org-wide` → all; else `user_branches`); store on request context so repositories add `WHERE branch_id IN (...)`. Prevents cross-branch leakage (IDOR at set level). |
| 10 | `ResolvePublicId` | routes with `{public_id}` | Look up numeric id from `public_id`; 404 if not found; attach model stub. Numeric ids never accepted from URL. |
| 11 | `RateLimit:<bucket>` | login, password reset, search, exports, public forms, financial writes | DB-backed fixed-window counter in `rate_limits`; `429` + `Retry-After` when exceeded. |
| 12 | `ValidateInput:<Validator>` | write routes | Instantiate named validator, run against request; on failure throw `ValidationException` → 422 JSON or re-render form with old input + inline errors. |
| 13 | `AuditContext` | write routes | Capture actor, ip, UA, request id into a scoped context the AuditService reads. |
| 14 | `NoStoreCache` | CRM + API | `Cache-Control: private, no-store`; `X-Robots-Tag: noindex, nofollow`. |

Public-site group uses: 1,2,3,4,5(anon),7(forms only),11,2→CSP relaxed for OG images, plus `PublicCache` (`Cache-Control: public, max-age=300, s-maxage=600`) and **no** `NoStoreCache`/`noindex`.

---

## 9. Controller → Service → Repository architecture

### 9.1 Contracts

```
Controller  (app/Controllers/Crm/LeadController.php)
    public function store(Request $r): Response
    - $input = LeadInput::fromRequest($r)          // DTO, writable fields only
    - $errors = (new LeadValidator)->validate($input, context: 'create')
    - if $errors -> return view/json 422
    - $lead = $this->leads->create($input, $r->user())   // service
    - return Response::created(route('leads.show', $lead->publicId), LeadResource::make($lead))

Service     (app/Services/LeadService.php)
    public function create(LeadInput $in, User $actor): LeadDTO
    - $this->policy->authorizeCreate($actor, $in->branchId)
    - $dupes = $this->leads->findLikelyDuplicates($in->phone, $in->altPhone, $in->email)
    - if $dupes and not $in->confirmedNotDuplicate -> throw DomainRuleException(DUPLICATE, $dupes)
    - $this->db->transaction(function () use ($in, $actor, &$lead) {
          $number = $this->sequences->next("lead:".date('Y'));
          $lead   = $this->leads->insert($in->toRow() + ['lead_number'=>$number, 'status_id'=>$this->statuses->default()]);
          $this->audit->log('lead.created', module:'leads', recordId:$lead->id, old:null, new:$lead->toArray(), actor:$actor);
          if ($lead->assignedTo) $this->notify->leadAssigned($lead);
      });
    - return $lead

Repository  (app/Repositories/LeadRepository.php)
    - insert(array $row): LeadDTO                       // prepared INSERT, returns hydrated row
    - findById(int $id): ?LeadDTO
    - findByPublicId(string $pid): ?LeadDTO
    - findLikelyDuplicates(?string $phone, ?string $alt, ?string $email): array
    - paginate(ListQuery $q, array $branchIds): Page     // one SELECT + one COUNT, sort allowlist
    - update(int $id, array $changes, int $expectedVersion): int  // optimistic where relevant
```

### 9.2 Rules

- **One public service method per use case.** Controllers call exactly one.
- **Transactions only in services.** Repositories never `BEGIN`.
- **Repositories are per-aggregate**, not generic. `LeadRepository`, `CandidateRepository`, `ApplicationRepository`, `PaymentRepository`, `InvoiceRepository`, `JobRepository`, `EmployerRepository`, `DocumentRepository`, `VisaRepository`, `TravelRepository`, `TourBookingRepository`, `TaskRepository`, `NotificationRepository`, `UserRepository`, `AuditRepository`, `ReportRepository` (read-only, cross-table), `SearchRepository` (read-only).
- **DTOs cross layer boundaries**, never raw `$_POST` or PDO row arrays beyond the repository.
- **Authorization is enforced in the service via a Policy**, in addition to the route middleware — defence in depth, and covers record-level checks the route cannot do.
- **Audit + notifications are side effects owned by the service**, inside the same transaction where the write happens (notifications may be enqueued as rows and delivered by cron).
- **The Domain layer** holds pure logic with no I/O: `StatusMachine` (allowed transitions), `MatchEngine` (scoring), `Money` arithmetic, expiry-window calculation. Fully unit-testable.

### 9.3 Status Transition Engine (shared mechanism)

`app/Domain/StatusMachine.php` loads a definition array (from `config/` + optional `settings` overrides):

```
applications:
  applied:              [shortlisted, documents_submitted, rejected, cancelled]
  documents_submitted:  [shortlisted, rejected, cancelled]
  shortlisted:          [interview_scheduled, rejected, cancelled]
  interview_scheduled:  [interview_completed, rescheduled, no_show, cancelled]
  interview_completed:  [selected, rejected, cancelled]
  selected:             [offer_received, cancelled]
  offer_received:       [offer_accepted, rejected, cancelled]
  offer_accepted:       [medical_pending, cancelled]
  medical_pending:      [medical_completed, cancelled]
  medical_completed:    [visa_processing, cancelled]
  visa_processing:      [visa_approved, rejected, cancelled]
  visa_approved:        [ticket_pending, cancelled]
  ticket_pending:       [ticket_booked, cancelled]
  ticket_booked:        [departed, cancelled]
  departed:             [placed]
  placed:               []           # terminal
  rejected:             []           # terminal (override to reopen -> audited)
  cancelled:            []           # terminal
```

- `StatusMachine::assert(entity, from, to)` → throws `DomainRuleException(INVALID_TRANSITION)` unless allowed **or** actor holds `<module>.override_status` (then flagged `is_override=1` in history + `activity_logs` with mandatory reason).
- Every transition writes a `*_status_history` row inside the transaction. Historical rows are never updated or deleted.
- Same engine drives `visa_applications`, `interviews`, `leads`, `tour_bookings`, `jobs`.

### 9.4 Job Match Engine

`app/Domain/MatchEngine.php` — configurable weighted rules (`config/matching.php`, overridable in `settings`):

| Factor | Source | Default weight | Rule |
|---|---|---|---|
| Country preference | `candidate_preferences.preferred_countries` vs `jobs.country` | 20 | contains → full |
| Job title similarity | preferred titles / experience titles vs `jobs.title` | 20 | normalized token overlap |
| Experience years | `candidates.total_experience_years` vs `jobs.experience_required` | 15 | ≥ required → full, else pro-rata |
| Qualification | `candidates.highest_qualification` vs `jobs.qualification` | 15 | rank map |
| Mandatory skills | `candidate_skills` vs `job_requirements` (mandatory) | 20 | all present → full; each missing subtracts |
| Salary expectation | `candidate_preferences.min_expected_salary` vs `jobs.salary_max` | 10 | within band → full |
| Age eligibility | DOB vs `age_min/age_max` | gate | outside → hard-excluded, not scored |

Output: `{score: 0-100, matched: [...], missing: [...]}` stored on `applications.match_breakdown`
and shown on the matching screen. **No** discriminatory factors (gender is a gate
only where the employer's role legally requires it and `jobs.gender_requirement`
is set; otherwise ignored). Structured `JobPosting` data on public pages omits any
such field.

---

## 10. Security threat model

Methodology: STRIDE per module + OWASP Top 10 2021 + brief section 83 checklist.
Trust boundaries: (a) Internet→Apache, (b) Apache→PHP app, (c) App→MySQL,
(d) App→filesystem/storage, (e) App→mail/WhatsApp, (f) Cron→App.

| # | Threat | Vector | Mitigation | Owner layer |
|---|---|---|---|---|
| T1 | **Broken access control / IDOR** | Guessing `/candidates/1025`, editing another branch's record | Public ULIDs in URLs + `ResolvePublicId`; `Authorize` middleware per route; **Policy re-check in service** with record + branch scope; `BindBranchScope` adds `branch_id IN(...)` to every list/detail query; global search is authorization-filtered | Middleware + Policy + Repository |
| T2 | **Privilege escalation via mass assignment** | POSTing `role_id`, `branch_id`, `owner_id`, `financial_override`, `record_version`, audit fields | Input DTOs with explicit writable-field allowlist per context; validators reject unknown keys; sensitive fields only settable by dedicated admin endpoints with their own permission | Validator + DTO |
| T3 | **SQL injection** | Search terms, filters, `ORDER BY`, date ranges, CSV import cells | PDO prepared statements everywhere; **sort/dir from allowlist maps** (`['created_at'=>'l.created_at', ...]`); `LIMIT/OFFSET` cast to int and clamped; no string concatenation of input into SQL; identifiers never from input | Repository |
| T4 | **Stored/reflected XSS** | Names, notes, uploaded filenames, imported data, rich-text job/package descriptions, query echoes | Context-aware output escaping via `e()` (HTML), `e_attr()`, `e_js()`, `e_url()`; Twig-style auto-escaping in view layer; rich text sanitized server-side with a strict allowlist (HTMLPurifier-equivalent config) on write **and** on render; CSP without `unsafe-inline` for scripts (nonce-based) | View + Sanitizer + CSP |
| T5 | **CSRF** | Forged POST/PUT/PATCH/DELETE, AJAX mutations, file uploads, public forms | Per-session cryptographic token, `hash_equals` compare, `SameSite=Lax` cookie, `Origin`/`Referer` check for state-changing requests, token required on every non-GET incl. multipart | Middleware |
| T6 | **Authentication attacks** | Credential stuffing, brute force, session fixation | `password_hash`/`password_verify` (bcrypt/argon2id), per-IP + per-account rate limit via `login_attempts` + `rate_limits`, lockout (`locked_until`) after N failures, session id regeneration on login, generic error text, optional TOTP 2FA for admin roles (V2-ready) | Auth service |
| T7 | **Session hijacking / theft** | XSS token exfil, network sniffing, shared computer | `HttpOnly; Secure; SameSite=Lax`; HTTPS+HSTS; idle + absolute timeout; server-side session store (revocable); logout destroys row; bind session to UA hash (soft) | Middleware |
| T8 | **Malicious file upload** | PHP webshell renamed `.pdf`, polyglot, SVG with script, zip bomb, oversized | See section 11 (full design): finfo MIME + magic-byte signature + extension allowlist (all three must agree), size cap, random ULID storage name, stored outside webroot, served only via authenticated controller with `Content-Disposition: attachment` and correct `Content-Type`, `X-Content-Type-Options: nosniff`, images re-encoded, never executed (`php_flag engine off` + `Require all denied` on storage) | Upload service |
| T9 | **Financial tampering** | Negative amount, refund > paid, double-submit, allocation > balance, editing history | `CHECK` constraints (`amount > 0`), server-side derived totals only, refund ≤ (payment.amount − already refunded), allocation ≤ invoice outstanding, `idempotency_key` unique index, optimistic locking (`record_version`), immutable `receipts`/`*_status_history`, every mutation audited, `payments.refund` a distinct permission | Service + DB constraints |
| T10 | **Sensitive data exposure** | Passport/Aadhaar numbers in list views, logs, exports, error pages, backups | Field-level visibility by role (section: RBAC doc); masked in list/search (`•••• 3421`); documents never in public storage; logs scrub PII; production error pages generic; exports gated per field + per permission; backups stored private, not web-reachable | Policy + View + Logger |
| T11 | **CSV / formula injection** | Export opened in Excel runs `=cmd|...`, `@`, `+`, `-`, `\t`, `\r` | Prefix leading `= + - @ \t \r` with `'` in all CSV writers; quote all fields; UTF-8 BOM | Export service |
| T12 | **Rate-limit bypass / DoS on shared host** | Expensive report/search/export spam, public contact-form flood | DB-backed limiter keyed by ip+user+bucket; heavy reports queued to `export_jobs` (cron-processed), not synchronous; public forms get stricter limits + honeypot + optional hCaptcha; pagination hard-capped | Middleware + queue |
| T13 | **Parameter tampering on bulk actions** | Bulk-assign IDs outside branch, bulk-delete beyond permission | Validate every id exists + policy check **per record**; scope to acting branch set; transactional; audit each change; cap batch size | Service |
| T14 | **Insecure direct object ref in documents** | `/storage/private/...` guessed, or download handler missing authz | Storage `Require all denied`; download only through `DocumentController@download` → policy check → stream with `readfile`/`fpassthru`; `document_access_log` row per access | Controller + Apache |
| T15 | **Cron abuse / unauthorized trigger** | Hitting `cron/*.php` over HTTP | Cron scripts detect `PHP_SAPI==='cli'` and refuse HTTP; also `Require all denied` on `/cron`; optional `CRON_SECRET` arg check; advisory lock prevents overlap | Cron + Apache |
| T16 | **Open redirect / host header injection** | `?next=//evil.com`, forged `Host` for reset links | Redirect targets validated against app route allowlist / same-origin; canonical `APP_URL` used for all generated links, never `$_SERVER['HTTP_HOST']` | Support |
| T17 | **Account enumeration** | Login + password reset revealing which emails exist | Uniform response + timing for reset ("if the address exists, an email was sent"); login error always generic | Auth service |
| T18 | **Clickjacking** | CRM iframed by attacker | `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` on CRM | Middleware |
| T19 | **Dependency risk** | Vulnerable composer package | Minimal dependencies, `composer.lock` committed, periodic `composer audit`, no runtime package fetch | DevOps |
| T20 | **Supply of stale/again-processed data** | Concurrent edit silently overwrites colleague's change | `record_version` optimistic lock on payments, invoices, refunds, applications, visa_applications, documents (verification), candidates; stale update → 409 + reload prompt | Service |

**Logging for detection (section 67):** failed logins, authorization failures (403),
CSRF failures (419), rate-limit hits (429), upload rejections, cron failures,
DB exceptions, financial overrides, status overrides — all to `storage/logs`
(rotated) and security-relevant ones also to `activity_logs`.

---

## 11. File upload security design

**Applies to:** `candidate_documents`, receipt/ticket attachments, import CSVs,
public job-apply CV (stricter). Treat every upload as hostile.

### 11.1 Accept pipeline (all checks must pass — fail closed)

```
1.  Auth + permission (documents.upload) + policy (can upload for THIS candidate) + CSRF.
2.  Multipart size guard: PHP ini upload_max_filesize / post_max_size set low
    (e.g. 12M); app re-checks $_FILES['file']['size'] <= document_type.max_size_kb.
3.  Upload error check: $_FILES[...]['error'] === UPLOAD_ERR_OK; file is
    is_uploaded_file().
4.  Extension: strtolower(pathinfo), must be in per-type allowlist
    (pdf, jpg, jpeg, png; docx only where a type explicitly allows).
    Reject double extensions (file.php.pdf), null bytes, path separators.
5.  MIME by content: finfo_file(FILEINFO_MIME_TYPE) on the tmp path — must be in
    document_type.allowed_mime. Browser-supplied type is ignored/logged only.
6.  Magic-byte signature: read first N bytes, verify against expected signature
    for the detected type (%PDF-, \xFF\xD8\xFF for JPEG, \x89PNG). Extension +
    finfo + signature must all agree.
7.  Content-specific validation:
      - Images: re-decode and re-encode via GD/Imagick to strip metadata,
        EXIF, embedded scripts, and normalize; reject if decode fails; cap
        dimensions (e.g. 6000px).
      - PDF: verify header/trailer, reject if JavaScript/OpenAction/Launch
        tokens present (best-effort scan); optionally rasterize preview only.
      - CSV (imports): parse with strict settings, cap rows, treat every cell
        as text, never eval.
8.  Optional AV: if exec + clamdscan available on host, scan; otherwise rely on
    1–7 and never-execute controls.
9.  Compute sha256; optional dedupe per candidate+type.
```

### 11.2 Storage

- Path: `storage/private/documents/<yy>/<mm>/<shard2>/<ULID>.<canonical-ext>` — random name, no user input in path.
- `storage/` is **outside** the web root (or, if impossible, `public_html/storage/.htaccess` with `Require all denied`, `php_flag engine off`, `RemoveHandler`/`RemoveType` for executables, `Options -ExecCGI -Indexes`).
- Directory perms `0700`, files `0600` where the host allows.
- DB row records `storage_path`, `original_name`, server `mime_type`, `extension`, `size_bytes`, `sha256`, `uploaded_by`, `status`.

### 11.3 Serve / download

- Only via `GET /documents/{public_id}/download` and `/documents/{public_id}/preview`:
  1. Authenticate + `documents.view` + Policy (same branch / assigned / role field access).
  2. Load row; 404 if soft-deleted or missing.
  3. Stream with `fpassthru`/`readfile` in chunks (no full load into memory).
  4. Headers: `Content-Type: <stored mime>`, `Content-Disposition: attachment; filename="<sanitized original>"` (inline only for image/pdf preview), `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Content-Length`.
  5. Insert `document_access_log` row.
- Never `include`/`require` an uploaded file. Never pass a stored path to a shell.

### 11.4 Verification workflow

`pending → uploaded → under_review → verified | rejected`; `verified/rejected`
require `documents.verify` / `documents.reject`; rejection needs a reason; each
step audited; `expired` set by cron when `expires_at < today` (idempotent).
Verification uses `record_version` to avoid two reviewers clashing.

---

## 12. Hostinger shared-hosting deployment architecture

### 12.1 Assumptions

Apache + mod_rewrite, PHP 8.2/8.3 (selectable in hPanel), MySQL 8 / MariAeDB
equivalent, one database + user via hPanel, cron via hPanel (min interval
typically 5–15 min, some plans hourly), SSL (free Let's Encrypt / AutoSSL),
File Manager + SSH (on some plans) + phpMyAdmin, no root, limited memory
(`memory_limit` ~256–512M), execution time caps, **no persistent workers**.

### 12.2 Layout on the account

```
/home/uXXXX/
├── domains/<domain>/            (or public_html/)
│   └── public_html/   → symlink or docroot to ../../crm/public   [preferred]
├── crm/                         application (outside web root)
│   ├── public/                  the ONLY web-exposed dir
│   ├── app/ config/ routes/ resources/ database/ cron/ vendor/
│   └── .env                     0600, never in public/
└── crm-storage/  (optional)     bind storage here if extra isolation wanted
```

If docroot can't be moved: keep `crm/` inside `public_html/`, set
`public_html/.htaccess` to route to `crm/public/index.php` and hard-deny the rest
(see §1.5).

### 12.3 `public/.htaccess` (essentials)

```
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Front controller
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]

# No directory listing, no dotfiles
Options -Indexes
<FilesMatch "^\.">
  Require all denied
</FilesMatch>

# Static asset caching (versioned filenames)
<IfModule mod_headers.c>
  <FilesMatch "\.(css|js|woff2|png|jpg|jpeg|webp|avif|svg)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
```

Root/app-level `.htaccess` (one directory above public, if app is under
`public_html`): `Require all denied` for `app config database storage cron vendor tests docs`, and `.env *.sql *.md composer.*`.

### 12.4 Deployment procedure (matches brief section 99)

1. **hPanel → Databases → MySQL:** create DB `uXXXX_crm`, create user, strong password, grant all on that DB.
2. **Import schema:** phpMyAdmin → import `database/schema/schema.sql`; then run seeders via `php scripts/seed.php --prod` over SSH, or import seed SQL.
3. **Upload app:** git clone over SSH into `~/crm` (preferred) or upload a release zip via File Manager and extract; run `composer install --no-dev --optimize-autoloader` (SSH) or upload a prebuilt `vendor/`.
4. **Docroot:** point domain to `~/crm/public` (hPanel → domain → advanced), or place under `public_html` with routing `.htaccess`.
5. **`.env`:** copy `.env.example` → `.env`; set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, DB creds, `SESSION_SECURE=true`, mail creds, `CRON_SECRET`, `APP_KEY` (generated). `chmod 600 .env`.
6. **PHP version:** hPanel → PHP Configuration → 8.2+; enable extensions `pdo_mysql, mbstring, openssl, fileinfo, gd` (or `imagick`), `intl`, `zip`.
7. **Permissions:** `storage/` and subdirs writable (`0755`/`0775` per host), `.env` `0600`, no world-writable app files; `public/assets` readable.
8. **HTTPS:** enable AutoSSL/Let's Encrypt; force-HTTPS on; confirm HSTS header after verifying cert.
9. **Test DB:** hit `/health/db` (admin-only) → expects `{db:"ok"}`.
10. **Test auth:** create first admin via `php scripts/create-admin.php`; log in; verify session cookie flags, logout.
11. **Cron:** see section 13; add jobs in hPanel with `php ~/crm/cron/<job>.php`.
12. **Test uploads:** upload a sample PDF as a document; verify it lands in `storage/private`, is not web-reachable (`curl https://<domain>/../storage/...` → 403), downloads via handler.
13. **Verify production:** `APP_DEBUG=false`, error pages generic, `X-Robots-Tag: noindex` on CRM, `robots.txt` disallows `/dashboard` etc., no demo data, `/health` OK.

### 12.5 Migration-readiness to VPS/cloud

- All host-specific knobs live in `.env` / `config/*`; no hard-coded paths.
- Storage access goes through a `Storage` interface (`LocalDisk` now; `S3Disk` later) — swap without touching services.
- Sessions via a handler interface (`DbSessionHandler` now; `RedisSessionHandler` later).
- Queue abstraction: today `export_jobs`/`notifications` rows drained by cron; later a real queue driver behind the same `Queue` interface.
- Mail behind `Mailer` interface (SMTP now; API provider later).
- No feature depends on a long-running process; adding workers is additive.

---

## 13. Cron architecture

### 13.1 Principles (brief section 46)

CLI-only (`if (PHP_SAPI !== 'cli') exit;`), idempotent, self-logging
(`cron_runs`), lock-guarded (`cron_locks` row with `expires_at` + `GET_LOCK()`
fallback), bounded work per run (process max N items, resumable), no infinite
loops, safe to run more often than scheduled.

### 13.2 Jobs

| Script | Schedule (hPanel) | Work | Idempotency key |
|---|---|---|---|
| `cron/followups.php` | every 15 min | Find `lead_followups`/`tasks` due today or overdue → create `notifications` for assignee; mark overdue tasks `status='overdue'` (derived, not destructive) | `notif:followup:<followup_id>:<due_date>` |
| `cron/document-expiry.php` | daily 02:00 | `candidate_documents.expires_at` within {30,15,7,1} days or past → notify doc team + counselor; set `status='expired'` when past | `notif:docexp:<doc_id>:<bucket>` |
| `cron/passport-expiry.php` | daily 02:10 | `passports.expiry_date` within {180,90,30} days or expired → notify; deep-link to candidate passport tab | `notif:passport:<passport_id>:<bucket>` |
| `cron/visa-expiry.php` | daily 02:20 | `visa_applications.expiry_date` windows → notify visa team | `notif:visa:<visa_id>:<bucket>` |
| `cron/interview-reminders.php` | daily 18:00 + hourly | Interviews tomorrow / in 2h → notify interviewer + counselor | `notif:intv:<interview_id>:<bucket>` |
| `cron/payment-reminders.php` | daily 09:00 | Invoices `due_on` past and `status != paid/void` → notify accounts + create task | `notif:pay:<invoice_id>:<due_on>` |
| `cron/daily-report.php` | daily 07:00 | Build yesterday's KPI snapshot → store in `settings`/cache table → email managers | run-date guarded |
| `cron/process-email-queue.php` | every 5 min | Send up to N `email_log` rows `status='queued'`; retry with backoff; cap attempts | per-row status |
| `cron/process-exports.php` | every 5 min | Pick `export_jobs status='pending'` → stream CSV to `storage/exports/<ulid>.csv` → mark completed + `expires_at` | per-row status |
| `cron/dashboard-cache.php` | every 10 min | Refresh non-sensitive aggregate cache (leads by source/country, app-by-status counts) | overwrite |
| `cron/cleanup.php` | daily 03:00 | Delete expired `export_jobs` files + rows, old `sessions`, old `rate_limits` windows, stale `cron_locks`, `password_resets` past expiry, tmp import files | naturally idempotent |

Each script wraps: acquire lock → `cron_runs` start → work in a try/catch →
`cron_runs` finish (success/failed + count) → release lock. Failures logged and
(for critical jobs) alert an admin.

### 13.3 Where a plan only allows one cron entry

Use a single `cron/dispatch.php` run every 5 min that internally decides which
jobs are due (cron expression table in `config/cron.php`) and runs them
sequentially within the time budget.

---

## 14. Backup and recovery strategy

| Asset | Method | Frequency | Retention | Location |
|---|---|---|---|---|
| **Database** | `mysqldump --single-transaction --routines --triggers` → gzip → `storage/private/backups/db-<date>.sql.gz` via `scripts/backup.php` (cron daily 04:00) | Daily; hourly for finance tables optional | 7 daily + 4 weekly + 3 monthly | Private storage + **off-site copy** pushed to external object storage / downloaded; Hostinger's own weekly backup as secondary |
| **Uploaded documents** | Incremental `tar` of `storage/private/documents` changed in last 24h → `backups/files-<date>.tar.gz` | Daily | 7 daily + 4 weekly | Same off-site target |
| **Configuration** | `.env` (encrypted with `APP_KEY`/GPG), `config/`, `.htaccess`, cron list documented | On change + weekly | Last 10 | Private repo / password manager |
| **Code** | Git remote (private) | On every deploy (tagged release) | full history | GitHub/GitLab private |

**Rules:** backups stored under `storage/private/backups` (never web-reachable);
`scripts/backup.php` verifies dump size > threshold and gzip integrity, logs to
`cron_runs`, alerts on failure. Off-site copy is mandatory — a backup only on the
same account is not a backup.

**Restore procedure (documented in `docs/BACKUP-RESTORE.md`):**
1. Put app in maintenance mode (`settings.maintenance_mode=1`).
2. Create fresh DB or drop/recreate; `gunzip < db-<date>.sql.gz | mysql uXXXX_crm`.
3. Extract latest `files-*.tar.gz` into `storage/private/documents`.
4. Restore `.env` (decrypt).
5. Run `php scripts/migrate.php --status` to confirm schema version matches code.
6. Smoke test: `/health`, login, open a candidate, download a document, view a report.
7. Exit maintenance mode.

**Recovery objectives:** RPO ≤ 24h (≤ 1h for finance if hourly dumps enabled),
RTO ≤ 2h. **Restore drill quarterly** into a staging DB — an untested backup is
assumed broken (brief section 66).

---

## 15. Performance strategy

### 15.1 Database

- Every list/search/filter column indexed; composite indexes ordered
  `(branch_id, status, created_at)` to match common `WHERE` + `ORDER BY`.
- No `SELECT *` — repositories select explicit column lists.
- No N+1: list queries `JOIN` or batch-load related rows (`WHERE id IN (...)`).
- `EXPLAIN` every list query in review; require `ref`/`range`/`eq_ref`, not `ALL`, at 100k-row scale.
- Server-side pagination only; default 25, options 25/50/100, hard cap 100; keyset pagination for the biggest tables (leads, candidates, applications, activity_logs, communication_logs) using `(created_at, id)`.
- Money/aggregate totals computed in SQL, not PHP loops.
- Dashboard: **one** aggregate query per widget group, or a cached snapshot table refreshed by `cron/dashboard-cache.php` every 10 min (non-sensitive counts only; financial figures rendered live with a "as of" timestamp or behind `reports.view`).
- Transactions kept short; no user interaction inside a transaction.
- `activity_logs`, `communication_logs`, `notifications` are append-heavy — partition-friendly design, periodic archival job (V2) moves rows > 18 months to `*_archive`.

### 15.2 Application

- Autoloader optimized (`composer dump-autoload -o`), OPcache on (host default).
- Lightweight DI container; no heavy framework bootstrap.
- Page-scoped assets: each view declares its JS/CSS; Chart.js loaded **only** on dashboard/report pages.
- Views stream where large (reports) rather than building a giant string.
- Config cached to a single PHP array file in production.
- Rate-limit + session tables kept small by `cleanup.php`.

### 15.3 Frontend

- Tailwind built + purged → one small CSS file, versioned filename, `immutable` cache.
- Vanilla JS, ES modules, no SPA framework; progressive enhancement (forms work without JS).
- Images: responsive `srcset`, WebP/AVIF with fallback, `loading="lazy"`, explicit width/height (no layout shift).
- Skeleton loaders for async table/detail loads; `fetch` with abort on filter change.
- DOM kept small: virtualize nothing, just paginate.
- No polling, no auto-reload; notifications badge refreshes on navigation or a single 60s `fetch` (opt-in).

### 15.4 Performance budget (brief section 89)

Initial CRM page: ≤ 60 KB JS, ≤ 30 KB CSS (gzipped), server response TTFB
< 300 ms on warm cache, ≤ 8 SQL queries per list page, ≤ 12 per dashboard.
Public pages: LCP < 2.5s on 3G-fast, ≤ 1 render-blocking resource.

---

## 16. SEO architecture for public pages

### 16.1 Separation

Public site and CRM are different route groups, different layouts, different
middleware. CRM emits `X-Robots-Tag: noindex, nofollow` + `Cache-Control:
private, no-store` on every response. `robots.txt` disallows `/dashboard`,
`/leads`, `/candidates`, `/applications`, `/admin`, `/api`, `/login`, `/documents`.
No candidate/employer/financial data is ever rendered on a public route.

### 16.2 Public routes

`/`, `/about`, `/contact`, `/jobs`, `/jobs/{country}/{slug}`, `/travel-packages`,
`/travel-packages/{slug}`, `/blog`, `/blog/{slug}`, `/sitemap.xml`, `/robots.txt`.
Only `jobs.is_public=1 AND status IN (open,interview)` and
`tour_packages.is_public=1 AND status='active'` are exposed.

### 16.3 On-page SEO

- Clean URLs, one canonical per page (`<link rel="canonical">` from `APP_URL` + path).
- Unique `<title>` and meta description per page, generated from entity data with sane length limits and fallbacks.
- Open Graph + Twitter Card tags; OG image per job/package (from a public image field, re-encoded, sized 1200×630).
- Semantic HTML5 landmarks, one `<h1>`, logical heading order, descriptive link text, `alt` on every image.
- Structured data (JSON-LD):
  - `JobPosting` on job detail **only when the posting genuinely qualifies** — real employer, real location, valid `datePosted`/`validThrough` (from `jobs.deadline`), `employmentType`, `hiringOrganization`, and `baseSalary` only if `salary_min/max` present and accurate. Never emit fabricated salary or fake `applicantLocationRequirements`. If data is insufficient, omit the block.
  - `TouristTrip`/`Product`+`Offer` on package pages where price and itinerary are real.
  - `Organization` + `BreadcrumbList` sitewide.
  - `BlogPosting` on blog articles.
- `sitemap.xml` generated (cached, regenerated by cron or on publish) listing home, static pages, public jobs, public packages, blog posts, each with `lastmod`.
- Internal linking: jobs link to country hub pages and related jobs; packages link to destination hubs; blog links to relevant jobs/packages.
- Performance = ranking factor: see §15.3.
- 301 redirects for changed slugs (keep old slug → new mapping); 404 page is helpful and links back to `/jobs`.
- `hreflang` reserved for future Hindi/Arabic public content (§ i18n).

### 16.4 Anti-patterns explicitly avoided

No cloaking, no doorway pages, no keyword-stuffed hidden text, no false
structured data, no indexable private URLs, no duplicate content across country
slugs (canonical + unique intro text per page).

---

## 17. Responsive UI architecture

### 17.1 Approach

Mobile-first, Tailwind breakpoints (`sm 640 / md 768 / lg 1024 / xl 1280`).
Single design system (brief sections 52–55) with reusable server-rendered
partials in `resources/views/components/`.

### 17.2 Layout behaviour

| Region | Mobile (< md) | Tablet (md–lg) | Desktop (≥ lg) |
|---|---|---|---|
| Navigation | Off-canvas drawer, hamburger; bottom quick-action bar (Call / WhatsApp / Follow-up / Task) | Collapsible sidebar | Persistent sidebar + top bar |
| Lists / tables | **Card per record** with key fields + row actions in a menu; filters in a bottom sheet | Reduced-column table, horizontal scroll only for the data-dense center columns inside an `overflow-x:auto` wrapper | Full table, sticky header, inline filters, bulk-select |
| Detail page (candidate) | Header summary card → status chips → primary action → **tabs become an accordion or swipeable segmented control** → timeline | Tabs, single column | Tabs, two-column (main + related/quick-actions rail) |
| Forms | Single column, large touch targets (min 44px), native inputs, sticky save bar | Single/two column | Two column, inline validation |
| Dashboard | Stacked cards, charts full-width, lazy-loaded below the fold | 2-col grid | 3–4-col grid |
| Modals | Full-screen sheet | Centered dialog | Centered dialog |

### 17.3 Component library (server-rendered partials + minimal JS behaviours)

Button, Input, Textarea, Select, Checkbox/Radio, DatePicker (native + enhancement),
Modal/Drawer/Sheet, Tabs/Accordion, Dropdown, Tooltip, Badge/StatusPill, Card,
Table (+ sticky header, sort headers, bulk select), Pagination, Breadcrumb,
Alert, Toast, EmptyState, SkeletonLoader, ConfirmDialog.
Consistent tokens: spacing scale, radius, type scale, focus ring, status colour +
**icon/text pair** (never colour alone — accessibility).

### 17.4 Accessibility baked in

Semantic elements, `<label for>` on every field, visible focus, keyboard-operable
menus/dialogs (focus trap + `Esc`), `aria-live` for toasts and async results,
tables with `<caption>`/`<th scope>`, contrast ≥ WCAG AA, status conveyed by text
+ icon, forms announce errors and move focus to the first invalid field.

### 17.5 Every page contract (brief section 54)

Title, breadcrumb (where useful), primary action, search/filters (where
applicable), explicit loading state, empty state with a next action, error state
with guidance, success feedback (toast), destructive actions behind a
ConfirmDialog, form values preserved on validation failure with inline errors.

---

## 18. Development phases

Phase 0 = this document set. Each subsequent phase delivers sections A–J of brief
section 109 (files to create/modify, migration, backend, frontend, security,
tests, QA checklist, performance notes, deployment notes), followed by the review
cycle **implementation → tests → security audit → performance audit → code review**
before the next phase starts.

| Phase | Scope | Key exit criteria |
|---|---|---|
| **1 — Foundation** | Skeleton, container, router, PDO wrapper, config/env, DB-backed sessions, auth (login/logout/reset), RBAC (roles/permissions/policies/middleware), CSRF, security headers, error handling + error pages, base Tailwind layout + component library, audit service, migrations + seeders runner, `/health`, first-admin script | Log in as seeded admin; permission denial returns 403; CSRF enforced; headers verified; migrations reproducible; audit row on a test write |
| **2 — Leads** | Lead CRUD, lead_number sequence, duplicate detection + merge, assignment + bulk assignment, statuses (configurable) + transition rules, notes, follow-ups, timeline, filters/search/sort/pagination (keyset), import/export, WhatsApp/call shortcuts, conversion → candidate (transactional) | All section-106 "definition of done" items pass for Leads |
| **3 — Candidates** | Person identity link, candidate profile + tabs, passport(s) + expiry calc, education/experience/skills/preferences, timeline, tasks | 360° profile screen; passport expiry windows computed; person shared with travel |
| **4 — Documents** | Document types, upload pipeline (§11), private storage, verify/reject/expire workflow, secure download/preview, per-candidate checklist, access log | Web-unreachable storage proven; all upload checks enforced; verification audited |
| **5 — Employers + Jobs** | Employer profile + contacts, jobs + requirements + benefits, job statuses + transitions, match engine (config-driven) + explainable result, public flag/slug | Match score with matched/missing breakdown; job status machine enforced |
| **6 — Applications + Interviews** | Applications linking candidate/job/employer, status transition engine + full history (immutable), overrides audited, interview scheduling + outcomes feeding application status | Arbitrary transitions rejected; every transition creates history; override needs reason |
| **7 — Medical + Visa** | Medical records + results, visa applications + status history + expiry, notifications wired | Visa/medical expiry windows notify correct teams idempotently |
| **8 — Travel** | Travel profile, flight bookings, departure/arrival records, placements, tour packages + items + bookings (shared person) | Visa-approved → travel workflow; tour booking independent of recruitment |
| **9 — Finance** | Invoices + lines, payments + idempotency, allocations, receipts (immutable + numbered), refunds (≤ paid, approval flow), outstanding calc, optimistic locking, finance reports | All section-36 integrity rules enforced by constraints + service; concurrent edit → 409 |
| **10 — Dashboard + Reports** | KPI dashboard (optimized/cached queries), charts, operational + recruitment + finance + travel reports, filters/date ranges/pagination, streamed CSV export, print views | Dashboard ≤ 12 queries or served from cache; large export streamed, memory flat |
| **11 — Automation** | All cron jobs (§13), notification system (idempotent, deep-linked), reminders, expiry logic, payment reminders, follow-up automation, email queue | Re-running any cron produces no duplicate notifications |
| **12 — Production hardening** | Full security audit, SQL/`EXPLAIN` review, access-control review, upload review, performance review, responsive + a11y + SEO audit, deployment dry-run on Hostinger, backup/restore drill | All audits in brief section 112 pass |

Public marketing site (Home/About/Contact/Jobs/Packages/Blog + SEO) is built
incrementally: shell in Phase 1, job pages after Phase 5, package pages after
Phase 8, blog + full SEO polish in Phase 12 (or as a parallel track once Jobs
exist).

---

## 19. Risks, trade-offs, assumptions

### 19.1 Assumptions (to confirm before Phase 1)

| # | Assumption | Impact if wrong |
|---|---|---|
| A1 | One agency, multiple **branches** in a single DB (not multi-tenant SaaS). Branch scoping via `branch_id` + user branch set. | Multi-tenant would need a `tenant_id` on every table + stricter isolation — larger change, better decided now. |
| A2 | Base currency is **INR**; foreign salaries/fees stored with their own currency code but **no FX conversion in V1** (amounts not summed across currencies without an explicit rate). | If cross-currency reporting is needed in V1, add `exchange_rates` + conversion service. |
| A3 | Hostinger plan allows **cron at ≤ 15 min** intervals and **SSH + Composer**. If only hourly cron / no SSH: use `cron/dispatch.php` single-entry pattern and ship a prebuilt `vendor/`. | Reminder latency increases; deploy process changes. |
| A4 | Document storage can live **outside `public_html`** (docroot movable or symlink allowed). | Fallback: `.htaccess` hard-deny inside `public_html/storage` — slightly weaker, still acceptable. |
| A5 | Expected data volume within a few years: leads 100k–300k, candidates 50k, applications 100k, documents 200k files / ~200 GB. Shared hosting disk quota must cover documents + backups, else object storage sooner. | May force earlier VPS/object-storage migration (architecture already supports it). |
| A6 | Rich text is needed only for **job descriptions, package inclusions, blog** — sanitized allowlist, no file embeds. | If users need richer content/media, expand sanitizer + media library. |
| A7 | WhatsApp V1 = `wa.me` links + templates; **no** WhatsApp Business API, no inbound message capture. | API integration is additive (adapter behind a `Messaging` interface). |
| A8 | Email via **Hostinger SMTP** in V1. | Swap `Mailer` driver for a transactional provider later; no service changes. |
| A9 | Payments are **recorded** (offline/manual reconciliation) — no online payment gateway in V1. | Gateway adds a `payment_intents` flow + webhook endpoint; ledger model already fits. |
| A10 | Single language (English) UI in V1; label files structured for Hindi/Arabic later, **RTL** not styled yet. | Arabic RTL needs a CSS pass + bidi review. |
| A11 | Legal/compliance specifics of overseas recruitment (e.g. India eMigrate/POE, destination labour rules) are **out of scope for the software layer** beyond storing the relevant documents/fields. | If eMigrate integration or mandated workflows are required, that's a dedicated module. |
| A12 | "Manager/Counselor" see only their branch(es); Super Admin/Admin org-wide. Read-Only role exists. | RBAC matrix (doc 02) must be signed off — changing it later means re-testing every policy. |

### 19.2 Key risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Shared-hosting resource limits** (CPU/memory/execution time) hit by reports, exports, imports, or cron under real data volume | Med | High | Queue heavy work to `export_jobs`/cron; bounded batch sizes; keyset pagination; `EXPLAIN` gate; early load-test with seeded 100k-row dataset; VPS migration path ready |
| **Cron interval too coarse** for "interview in 2h" / payment-day reminders | Med | Med | Accept best-effort SLAs documented per notification; `dispatch.php` every 5 min where allowed; critical same-day reminders also surfaced live on dashboard |
| **Sensitive PII exposure** (passport/Aadhaar) via export, log, backup, or a missed policy check | Med | Very High | Field-level RBAC + masking; export field gating; log scrubbing; private encrypted backups; security audit each phase; `document_access_log`; least-privilege roles |
| **Financial data integrity bug** (race, rounding, bad refund) | Med | Very High | DB `CHECK` constraints + unique idempotency + optimistic locking + immutable receipts/history + transactional services + dedicated tests for every rule in section 36 + `payments.refund` gated |
| **Status-model churn** — business changes allowed transitions mid-build | High | Med | Transition rules in config/`settings`, not code paths; override mechanism with audit; history append-only so past data stays valid |
| **File upload bypass** (polyglot, host misconfig serving `storage`) | Low | Very High | Triple-check (ext+finfo+magic) + image re-encode + never-execute + deploy test #12 verifies storage is 403 over HTTP |
| **Data model too rigid** for real ops (e.g. re-applying to same job, multiple passports, agency-held documents) | Med | Med | `applications` unique `(candidate,job)` can be relaxed to allow a `cycle_no`; `passports` already supports multiple + `held_by`; review with agency before Phase 5/6 |
| **Duplicate person records** across leads/candidates/travel | Med | Med | Duplicate detection on lead create; `persons` shared identity; merge tool (Phase 2) with audit; unique-ish indexes on phone/email as soft signals |
| **Scope creep from "future features" list** (portals, API, OCR, AI) into V1 | High | Med | Explicitly deferred (section 103); service boundaries keep them additive; no infra added prematurely |
| **Backup never tested** | Med | Very High | Quarterly restore drill into staging is a hard requirement; `backup.php` self-verifies and alerts |
| **Single DB user / no read replica** on shared hosting | High | Low–Med | Query optimization + caching absorbs it at expected scale; replica is a VPS-era addition |
| **Timezone confusion** (server vs DB vs browser) | Med | Med | Store UTC everywhere; `config('app.timezone')` for display; `Clock` service is the only time source; no `NOW()` mixed with PHP `date()` |

### 19.3 Trade-offs taken

| Decision | Alternative | Why this choice |
|---|---|---|
| No framework (custom thin Support layer) | Laravel/Symfony | Brief mandates it; smaller footprint on shared hosting, full control, no version-churn surprises. Cost: we build router/DI/validation/ORM-lite ourselves and must test them. |
| Server-rendered views + progressive JS | SPA (React/Vue) | Brief forbids SPA frameworks; better SEO for public pages, works without JS, lighter. Cost: richer interactions need careful vanilla-JS components. |
| DB-backed sessions/queue/rate-limit | Redis | Brief forbids Redis; shared-hosting compatible. Cost: more DB writes (mitigated by `cleanup.php` + indexes). |
| Polymorphic `invoiceable` (application \| tour_booking) | Separate invoice tables per module | One ledger, one numbering scheme, one reporting path. Cost: FK can't enforce the polymorphic target — validated in the service + a periodic integrity check. |
| Shared `persons` identity, module-specific profiles | Fully separate candidate/customer tables | Matches section 41; a person can be both without duplication. Cost: slight join overhead, careful merge logic. |
| Public ULIDs + numeric PKs | UUID PKs everywhere | Keeps fast integer joins/indexes; hides enumerable ids from URLs. Cost: two identifiers per exposed entity. |
| Config-driven status machine & match engine | Hard-coded | Business rules change; keeps them out of controllers/views. Cost: a small engine to build and test. |
| Cron `dispatch.php` fallback | Require fine-grained cron | Works on the most limited plans. Cost: jobs run sequentially within one time budget. |

---

## Final recommended architecture (summary)

1. **Custom layered PHP 8.2 app**, front controller in `public/`, everything else outside the web root, deployed on Hostinger shared hosting with a movable docroot (fallback: hard-deny `.htaccess`).
2. **Strict pipeline:** Router → ordered Middleware (security headers, HTTPS, session, auth, CSRF, RBAC permission, branch scope, public-id resolve, rate limit, validation, audit context, no-store) → thin Controller → Service (business rules + transactions + audit + notifications) → per-aggregate Repository (PDO prepared statements) → MySQL 8 InnoDB.
3. **Domain layer** holds the pure, tested logic: status transition engine, match engine, money, expiry windows.
4. **Security is structural:** public ULIDs, defence-in-depth authorization (middleware + policy + query scoping), triple-validated uploads served only through an authenticated streaming handler, immutable audit + financial history, DB `CHECK` constraints + optimistic locking, DB-backed rate limiting, generated links only from `APP_URL`.
5. **Shared `persons` identity** underpins both the recruitment pipeline (Lead→…→Placement) and the travel pipeline (Inquiry→…→Completion); **one ledger** (`invoices`/`payments`/`allocations`/`receipts`/`refunds`) serves both via a polymorphic `invoiceable`.
6. **Automation via idempotent, locked, logged cron scripts** that call the same service layer; heavy work (exports, emails) is queued to tables and drained by cron — no persistent workers.
7. **Public marketing site is a separate, cache-friendly, SEO-optimized route group** that only ever reads published jobs/packages and writes rate-limited `public_enquiries`; the CRM is `noindex` + `no-store` everywhere.
8. **Migration-ready:** storage, sessions, queue, and mail are all behind interfaces; no path or infra is hard-coded; moving to a VPS with object storage / Redis / a real queue is additive, not a rewrite.
9. **Delivery in 12 reviewed phases** after this one, each meeting the section-106 definition of done and passing implementation → test → security → performance → code-review gates.

**Awaiting explicit instruction to begin Phase 1.** Please review, in priority
order: `database/schema/schema.sql` + `docs/01-DATABASE.md` (schema &
relationships), `docs/02-RBAC.md` (roles & permissions), and §9.3 above
(status-transition model).
