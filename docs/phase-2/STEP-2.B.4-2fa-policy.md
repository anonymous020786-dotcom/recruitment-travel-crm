# Step 2.B.4 — 2FA policy: step-up + mandatory-2FA grace enforcement

**Status:** implemented; 341 tests green (+11). Track B.x of `docs/PLAN-ADDITIONS.md`.

## A. Files created

| File | Purpose |
|---|---|
| `app/Http/Middleware/RequireRecentAuth.php` | `confirm[:<minutes>]` — the session must have a full password / 2FA auth within `auth.password_confirm.timeout_minutes` (default 15). Web → redirect to `/confirm-password` (target stashed in `_confirm_intended`); JSON → `403 {error, confirm_required, confirm_url}`. A remember-me recall has no `_authenticated_at`, so it always has to confirm. |
| `app/Http/Middleware/Enforce2fa.php` | `enforce2fa` — for a user whose role is in `auth.two_factor.required_roles` and who has no second factor: `auth.two_factor.grace_logins` sign-ins of grace (counted from `login_history`, server-side), exposed as the `twofa_grace_left` request attribute; after that, every path except `/account/two-factor`, `/account/recovery-codes`, `/account/passkeys`, `/confirm-password`, `/logout` redirects to the setup screen (JSON → `403 {twofa_required, setup_url}`). A `passkey` login is exempt (already MFA). |
| `app/Controllers/Auth/PasswordConfirmController.php` | `/confirm-password` GET (form; bounces onward if already fresh) + POST (verifies the password, calls `Auth::recordIdentityConfirmation()`, audits `password_confirmed`, redirects to `_confirm_intended`). |
| `resources/views/auth/confirm-password.php` | "sudo mode" screen, app layout. |
| `tests/Unit/Http/Middleware/RequireRecentAuthTest.php` | pass / GET redirect+stash / POST referer stash / JSON 403 / `:minutes` arg. |
| `tests/Feature/Enforce2faTest.php` | no-op when role not required / within grace + countdown / after grace redirect / setup routes still allowed / JSON 403 / passkey exempt. |

## B. Files modified

- `app/Auth/Auth.php` — `login()` also stamps `_authenticated_at` for `via = 'passkey'` (passwordless passkey is full-strength); new `recordIdentityConfirmation()`.
- `app/Http/Kernel.php` — aliases `confirm` → RequireRecentAuth, `enforce2fa` → Enforce2fa.
- `routes/web.php` — `enforce2fa` added to the authenticated CRM group; `/confirm-password` routes; `confirm` on `/account/security`, `/account/two-factor/disable`, `/account/recovery-codes`, every `/account/passkeys*` mutation and the GET, and `/account/sessions/revoke-all`.
- `app/Controllers/Crm/AccountController.php` — dropped the inline `confirm_password` checks on 2FA-disable and recovery-code regen (the `confirm` middleware now owns step-up); removed the now-unused `confirmPassword()` helper.
- `resources/views/crm/account/security.php` — removed the per-form password inputs.
- `resources/views/layouts/app.php` — amber grace-countdown banner driven by `twofa_grace_left`.
- `resources/js/app.js` — `postJson` follows `confirm_required` / `twofa_required` responses to their URL.
- `config/auth.php` — `password_confirm.timeout_minutes`.
- `config/rate_limits.php` — `password_confirm` bucket (`user`+`ip`, 10 / 15 min).
- `.env` / deploy: `TWO_FACTOR_REQUIRED_ROLES` (already present) now actually enforces.

## C. Migration

None — grace is counted from the existing `login_history` table.

## F. Security

- Step-up windows are measured from `_authenticated_at`, set only by a password
  check, a passwordless passkey, or an explicit `/confirm-password`. Remember-me
  and (plain) session continuation never refresh it.
- The grace counter is server-side (`COUNT(*)` on `login_history`) — clearing
  cookies / using a new device does not reset it.
- `enforce2fa` runs inside the authenticated group, after `auth`, so it always
  sees a resolved user; the fast path (no required roles, or 2FA already on)
  touches only config + the User DTO.
- JSON endpoints fail closed: past grace, or without a fresh confirmation, they
  return 403 rather than proceeding.
- `password_confirmed` is written to `activity_logs`.

## H. Manual QA — verified

- [x] Fresh login → `/account/security` is **200** (recent auth satisfies `confirm`)
- [x] `/confirm-password` while already fresh → 302 onward
- [x] Unauthenticated `/account/security`, `/confirm-password`, `/account/passkeys` → 302 `/login`
- [x] Full suite **341 tests, 781 assertions** green
- [ ] Stale-session step-up prompt + mandatory-2FA lockout end to end on staging (needs a real >15-min-old session / a role in `TWO_FACTOR_REQUIRED_ROLES`)

## I. Performance

- `enforce2fa` fast path: one config read, no DB. Slow path (required role, no
  2FA): one indexed `COUNT(*)` per request until enrolled.
- `confirm`: one session read; the `/confirm-password` POST is one password hash
  verify, rate-limited.
