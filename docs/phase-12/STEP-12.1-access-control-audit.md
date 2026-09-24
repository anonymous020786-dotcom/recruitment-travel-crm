# Step 12.1 — Access-control audit

First Phase 12 audit. Instead of a one-off review, the findings are locked in as `tests/Feature/RouteAuditTest.php`
so a future route cannot quietly regress them. It loads the real route table (`Router::routes()`, new) and the
real middleware expansion (`Kernel::expand`) and checks 202 routes:

| Rule | Result |
|---|---|
| Only 21 audited routes (public pages, login/reset/2FA challenge, contact, health, API ping) are reachable without a session; both directions are asserted, so a stale entry fails too | pass |
| Every signed-in route has a `can:` gate, except 23 listed with the reason (own-account pages, logout, dashboard filtered by permission, document *review* which the service gates on verify-or-reject) | pass |
| Every POST/PUT/PATCH/DELETE runs CSRF verification | pass |
| No GET path reads like an action (delete/approve/revoke/…); the merge *form* is listed | pass |
| Every signed-in write is rate-limited (only `POST /logout` exempt) | **fixed** — 14 were not |
| Every `can:` names a permission that exists in the catalogue (a typo would silently lock everyone but super admin out) | pass |
| Every handler is a real public controller method | pass |
| No duplicate route and no literal route shadowed by an earlier wildcard | pass |
| Every repository `findByPublicId()` takes a `BranchScope` (record-level / IDOR protection); exceptions audited: `ExportRepository` (controller checks the requester, 404 otherwise) and `TourPackageRepository` (organisation-wide catalogue) | pass |

## Fixed

Fourteen signed-in write routes had no `throttle:write`: lead delete / assign / status / notes, and the account
actions 2FA disable, recovery-code regeneration, passkey rename / delete / second-factor toggle, trusted-device
and session revoke (single and all). They are now limited like every other write (120 per user per minute).

## Notes for later phases

- A new route must be added with `auth`, a `can:` gate and `throttle:write`, or be added to the lists at the top of
  the test with a reason. That is the intended friction.
- `GET /dashboard` has no gate by design; its widgets are filtered by the viewer's permissions and branch scope.
- 854 tests, 2745 assertions.
