# Phase 1 · Step 1.7 — RBAC + policies + audit foundation

**Status:** implemented; 168 tests green (RBAC verified through the real middleware stack); role_permissions seeded on MariaDB.

## A. Files created

| Area | Files |
|---|---|
| Catalogue | `config/permissions.php` — 133-permission catalogue (module ⇒ action ⇒ label) + role → permission matrix with `module.*` wildcards and `!token` revokes |
| Seeders | `database/seeders/PermissionsSeeder.php` (syncs `permissions`, prunes stale + their links), `database/seeders/RolePermissionsSeeder.php` (idempotent sync of `role_permissions` from the matrix) |
| Resolution | `app/Repositories/PermissionRepository.php`, `app/Auth/PermissionService.php` (deny-override → allow-override → role grant → deny; `super_admin` bypass; per-request memo; `effectivePermissions`) |
| Branch scope | `app/Auth/BranchScope.php` (value object: `contains()`, `whereClause()`), `app/Auth/BranchScopeResolver.php` (org-wide role / `user_branches` / `primary_branch_id`) |
| Gate / policies | `app/Auth/Gate.php` (raw-permission check, policy dispatch, closure abilities, `forUser`, `authorize`), `app/Policies/Policy.php` (base: `can`, `canAny`, `inBranchScope`, `canInBranch`) |
| Middleware | `app/Http/Middleware/Authorize.php` (`can:<ability>[,ModelClass]` → 403), `app/Http/Middleware/BindBranchScope.php` (`branch` → request attr + container instance) |
| Audit | `app/Repositories/ActivityLogRepository.php` (**append-only** — `insert` + reads, no mutation methods), `app/Audit/AuditService.php` (actor/ip/ua/request-id context, sensitive-key scrubbing, failure never breaks the business op) |
| Tests | `tests/Unit/Auth/{BranchScopeTest,PermissionServiceTest,GateTest}.php`, `tests/Feature/{AuditServiceTest,RbacHttpTest}.php` |

## B. Files modified

- `bootstrap/app.php` — bind `PermissionService`, `BranchScopeResolver`, `AuditService`, `Gate` (with a policy-registration hook).
- `app/Http/Kernel.php` — `can` → `Authorize`, `branch` → `BindBranchScope` (were pass-throughs); new aliases `session`, `csrf`, `json` for routes outside the standard groups.
- `app/Auth/AuthService.php` — audit `login` and `password_reset`; `AuditService` dependency added.
- `app/Auth/Auth.php`, `app/Repositories/PermissionRepository.php` — dropped `final` (test doubles).
- `app/Exceptions/Handler.php` — 401 title/message.
- `routes/web.php` — `/health/db` = `headers:api, json, session, auth, can:system.health`; authenticated CRM group gains `branch`.
- `database/seeders/DatabaseSeeder.php` — add Permissions + RolePermissions seeders.

## C. Migration

None (uses existing `permissions` / `role_permissions` / `user_permissions` / `activity_logs`).

## F. Security

- **Defence in depth** for authorization: route middleware `can:` (this step) → Policy in the service (feature phases) → repository branch predicate (`BranchScope::whereClause`). UI hiding is never the boundary.
- **Deny-wins**: a `user_permissions` row with `effect='deny'` overrides any role grant — verified end-to-end (`RbacHttpTest::test_deny_override_blocks_a_role_grant`).
- **`super_admin`** short-circuits to allowed everywhere (still granted every permission explicitly for transparency).
- **Branch isolation**: `BranchScope` yields `1 = 0` for a user with an empty scope (sees nothing) and `1 = 1` only for org-wide — repositories bind the `IN (...)` list, never interpolate.
- **Audit trail is append-only by construction**: `ActivityLogRepository` exposes no update/delete/purge method (asserted by a reflection test); `AuditService` scrubs `password`, `password_hash`, `token_hash`, `_token`, `remember_token` from value snapshots; a write failure is logged, never thrown.
- Login and password-reset now leave an `activity_logs` entry with the actor, ip, UA and request-id.

## H. Manual QA — verified

- [x] `php scripts/seed.php` → 133 permissions; role_permissions: super_admin 133, admin 132 (no `roles.manage`), manager 125, recruitment 45, counselor 42, accounts 34, travel 32, visa 29, read_only 24, documentation 23. Re-run → `+0 -0` (idempotent).
- [x] HTTP: logged-in super_admin → `GET /health/db` = `200 {"status":"ok","migration":"…"}`; anonymous → `401` JSON.
- [x] `activity_logs` after login: `login / auth / user / 1 / "password sign-in [req:01M…]" / user_id=1`.
- [x] **168 tests, 406 assertions** — incl. `RbacHttpTest` (counselor: leads 200 / finance 403; accounts: finance 200; read_only: finance 403 / leads 200; super_admin: all 200; deny-override: 403), `PermissionServiceTest` (precedence, memo), `GateTest` (policy dispatch, closure abilities, authorize throws), `AuditServiceTest` (diff row, secret scrub, null actor, append-only).

## I. Performance

- `PermissionService` loads a role's grants + a user's overrides **once per request** (2 small queries), then answers every `can()` from memory.
- `BranchScopeResolver` — 1 query per request (or 0 for org-wide roles), memoised.
- `Gate` policy lookup is an array hit; no reflection on the permission path.
- Audit write is 1 INSERT inside the caller's existing transaction.

## J. Deployment

- Deploy order unchanged: `migrate` → `seed` (now also seeds permissions + matrix) → `create-admin`.
- **Re-seeding `RolePermissionsSeeder` resets `role_permissions` to the matrix** — any bespoke grants an admin made via the (future) `roles.manage` UI would be reverted. Gate re-seeding in production, or move custom grants to `user_permissions`. Documented here and in the seeder.
- `config/permissions.php` is the single source for the catalogue; adding a permission = edit config + `php scripts/seed.php --class=PermissionsSeeder --class=RolePermissionsSeeder`.

## Follow-ups

- Concrete policies (`LeadPolicy`, `CandidatePolicy`, …) + `Gate::policy()` registrations — land with each feature phase.
- `BindBranchScope` currently only publishes the scope; repositories consume it starting in Phase 2.
- Audit `record_id` is `BIGINT` (integer PKs). If auditing by ULID/public-id is wanted later, widen to `VARCHAR(40)` via a migration.
- `/admin/audit` viewer UI — Phase 1.8/Phase 12.
- Role-based maintenance-mode bypass (super_admin) now possible — wire in a later pass.
