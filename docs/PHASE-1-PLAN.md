# Phase 1 — Foundation: step-by-step delivery

Phase 1 is built as 9 small units. Each unit is self-contained, testable, and
followed by a stop for review (implement → test → security check → review) before
the next begins. No unit breaks a previous one.

| Step | Unit | Delivers | Review focus |
|---|---|---|---|
| **1.1** | Skeleton + bootstrap + config | Directory tree, zero-dependency autoloader, `Env`/`Config`/`Container`/`Application`/`Logger`, config files, `.env.example`, front controller placeholder, `.htaccess` (public + root deny), exception/error handler skeleton, base exception classes | Docroot isolation, env never web-exposed, debug off in prod, no secrets committed |
| **1.2** | Database layer + migrations | `Db` (PDO wrapper: prepared statements, transactions, `transaction()` helper), `QueryException`, `scripts/migrate.php` runner + `schema_migrations`, split `database/migrations/001…` from `schema.sql`, `scripts/seed.php` harness | Prepared-statement-only API, no string interpolation, transaction rollback correctness |
| **1.3** | HTTP core + router | `Request`, `Response` (+ JSON/redirect/view), `Router` (method+path→handler, `{param}` binding, groups, named routes), `Pipeline`, 404/405 handling, `url()`/`route()` helpers | Route params never trusted, method override safe, no open redirect in `url()` |
| **1.4** | Middleware pipeline + security headers | Ordered middleware runner; `RequestId`, `SecurityHeaders` (CSP nonce, HSTS, X-CTO, Referrer-Policy, Permissions-Policy, frame-ancestors), `EnforceHttps`, `NoStoreCache`, `MaintenanceGuard` | CSP has no `unsafe-inline` for scripts; headers correct per group |
| **1.5** | Sessions + CSRF | DB-backed `SessionHandler` (`sessions` table), secure cookie flags, idle + absolute timeout, id regeneration; `Csrf` (per-session token, `hash_equals`, Origin/Referer check); `VerifyCsrf` middleware; `csrf_field()`/`csrf_token()` | Cookie flags, fixation defence, token in all non-GET incl. multipart |
| **1.6** | Rate limiting + auth | DB `RateLimiter` (`rate_limits`), `RateLimit` middleware + buckets; `Hash` (argon2id/bcrypt), `AuthService` (login/logout/reset), `login_attempts` + lockout, `password_resets` (hashed, single-use, generic response); `Authenticate` middleware; login/logout/forgot/reset routes + views | Brute-force throttle, enumeration-safe reset, session regen on login |
| **1.7** | RBAC + policies + audit foundation | `permissions`/`roles`/`role_permissions`/`user_permissions` seeders (catalogue from `docs/02-RBAC.md`), `PermissionService` (allow/deny resolution), `Authorize` middleware, `BindBranchScope` middleware, `Policy` base + `Gate`, `AuditService` + `activity_logs` writer (append-only), `RolePermissionSeeder` | Deny-wins, branch scoping in queries, server-side checks, audit immutability |
| **1.8** | View layer + base layout + components | `View` (PHP templates, auto-escaping helpers `e()/e_attr()/e_url()`, layout inheritance, section stack, per-view asset registration), CRM `layouts/app`, `layouts/auth`, sidebar/topbar, breadcrumb; component partials: button, input, select, textarea, checkbox/radio, alert, badge/status-pill, card, table, pagination, modal, drawer, tabs, toast, empty-state, skeleton, confirm-dialog | Output escaping by context, no inline event handlers, a11y (labels, focus, roles) |
| **1.9** | Tailwind build + error pages + health + first-admin | Tailwind config + input CSS + built/versioned output, asset versioning helper; error pages 403/404/419/429/409/422/500/503; `/health` + `/health/db`; `scripts/create-admin.php`; wire `Handler` to render error pages | Prod error pages leak nothing; `/health/db` admin-gated |

**Exit criteria for Phase 1:** log in as a seeded admin; a permission-denied
action returns 403; CSRF enforced on writes; security headers verified in
response; migrations reproducible from scratch; an audited test write appears in
`activity_logs`; storage + `.env` unreachable over HTTP.

Deliverables per step follow brief §109 sections A–J (files created/modified,
migration, backend, frontend, security, tests, QA checklist, performance,
deployment).
