# Phase 1 · Step 1.6 — Authentication + rate limiting

**Status:** implemented; 140 tests green (incl. real-MariaDB integration); full login flow verified over HTTP.

## A. Files created

| Area | Files |
|---|---|
| Hashing | `app/Support/Hash.php` — argon2id (bcrypt fallback), `needsRehash`, timing-safe empty-hash path |
| Rate limiting | `app/Support/RateLimiter.php` (DB fixed-window on `rate_limits`), `app/Http/Middleware/RateLimit.php` (`throttle:<bucket>`, keys by ip/user/email, 429 + `Retry-After` + `X-RateLimit-*`) |
| Validation | `app/Validators/Validator.php` — rule engine (`required, email, string, integer, numeric, boolean, in, min, max, between, size, regex, confirmed, same, nullable, sometimes, ulid, …`), custom rules, one message per field → `ValidationException` |
| Views | `app/View/View.php` (`$this`-bound PHP templates, `layout/start/stop/yield/partial`), `resources/views/layouts/guest.php`, `resources/views/auth/{login,forgot-password,reset-password}.php` |
| User data | `app/Models/User.php`, `app/Repositories/{UserRepository,LoginAttemptRepository,PasswordResetRepository}.php` |
| Auth | `app/Auth/Auth.php` (per-request state), `app/Auth/AuthService.php` (login/lockout/reset) |
| Middleware | `app/Http/Middleware/Authenticate.php` (`auth`), `app/Http/Middleware/RedirectIfAuthenticated.php` (`guest`) |
| Mail | `app/Mail/Mailer.php`, `app/Mail/QueueMailer.php` (→ `email_log`) |
| Controllers | `app/Controllers/Auth/{LoginController,PasswordResetController}.php` |
| Seed / ops | `database/seeders/RolesSeeder.php`, `scripts/create-admin.php` |
| Migration | `database/migrations/0002_email_log_body.sql` (adds `body_html`, `body_text`, `from_name` to `email_log`) |
| Tests | `tests/Unit/Support/HashTest.php`, `tests/Unit/Validators/ValidatorTest.php`, `tests/Feature/{RateLimiterTest,AuthServiceTest}.php`, `tests/Support/DbTestCase.php` |

## B. Files modified

- `bootstrap/app.php` — bind `Hash`, `RateLimiter`, `Mailer` (QueueMailer), `View`, `Auth`, `AuthService`, `Signer`.
- `app/Http/Kernel.php` — aliases `auth` → `Authenticate`, `guest` → `RedirectIfAuthenticated`, `throttle` → `RateLimit` (were pass-throughs).
- `app/Http/Middleware/SecurityHeaders.php` — share the CSP nonce with the `View` so templates can nonce their inline `<style>`.
- `app/Support/helpers.php` — `auth()`, `user()`, `view()`, `view_response()`, `errors()`, `error()`, `flash()`, `back()`, `redirect_with_errors()`.
- `routes/web.php` — guest group (`/login`, `/forgot-password`, `/reset-password/{token}`) + `/logout`; `/dashboard` now truly gated.
- `database/seeders/DatabaseSeeder.php` — add `RolesSeeder`.

## C. Migration

`0002_email_log_body` — applied cleanly on MariaDB 10.4 (idempotent runner, batch 2).

## F. Security

- **Passwords**: `password_hash`/`password_verify`; rehashed on login when params change; a missing stored hash still runs a dummy `password_verify` so "user not found" and "wrong password" are timing-indistinguishable.
- **Account lockout**: 5 failed attempts → `locked_until = now + 15 min`; a locked account is refused **even with the correct password** (429 + `Retry-After`).
- **Velocity limiting**: `throttle:login` (5 / 15 min per ip+email) and `throttle:password_reset` (3 / hour per ip) on the POST routes — verified: 6th rapid bad login → **429**.
- **Enumeration-safe**: login error is always the generic *"These credentials do not match our records."*; password-reset always responds *"if that email is in our system…"* and does nothing for unknown/inactive users (test-covered).
- **Reset tokens**: 32 random bytes; only the **sha256** is stored; single-use (`used_at`); 60-min expiry; 2-min issue throttle; one live token per user; **completing a reset deletes every session row for that user**.
- **Session**: `Auth::login()` calls `session->regenerate()` (fixation defence); `logout()` calls `session->invalidate()`.
- **UA binding**: session stores `sha256(user-agent)`; a mismatch silently de-authenticates.
- **CSRF**: all auth POSTs run through `VerifyCsrf` (token + same-origin).
- Old input is redacted (`password`, `password_confirmation`, `current_password`, `_token`) before being flashed for form repopulation.

## H. Manual QA — verified over HTTP (`php -S localhost:8870 public/index.php`)

| Step | Result |
|---|---|
| `GET /dashboard` unauthenticated | 302 → `/login` (intended url stashed) |
| `POST /login` wrong password | 302 → `/login`; page shows generic error; `failed_login_count` +1 |
| `POST /login` correct | 302 → `/dashboard`; session row carries `user_id` |
| `GET /dashboard` authenticated | 200 |
| `GET /login` while authenticated | 302 → `/dashboard` (guest guard) |
| `POST /logout` | 302 → `/login`; session invalidated |
| `GET /dashboard` after logout | 302 → `/login` |
| 6 rapid bad logins | `302 302 302 302 302 429` |
| `scripts/create-admin.php` | creates/updates an `is_org_wide` super_admin |

Automated: **140 tests, 356 assertions** — incl. real-DB `AuthServiceTest` (success clears failures, wrong password generic + increments, unknown-email indistinguishable, lockout after 5, reset queues hashed token + email_log row, reset is single-use, wrong-email-for-token rejected) and `RateLimiterTest` (window count, limit flip, expiry reset, availableIn, clear).

## I. Performance

- Login path: ~4 short queries (find user, hash fetch, record success, record attempt) + 2 session queries. Rate-limit adds 1 upsert + 1 select.
- `RateLimiter` SQL uses a single upsert with unique placeholders (native prepared statements — no `EMULATE_PREPARES`).
- `Auth::user()` loads the User DTO at most once per request and caches it.
- `login_attempts` and `rate_limits` are pruned by `cron/cleanup.php`.

## J. Deployment

- After deploy: `php scripts/migrate.php` → `php scripts/seed.php` (roles + countries) → `php scripts/create-admin.php --email=… --name="…"`.
- `MAIL_DRIVER` may stay unset (QueueMailer just writes `email_log`); `cron/process-email-queue.php` (Step 1.11) does delivery. In non-production the reset link is also written to `storage/logs` for testing.
- Local `.env` `APP_URL` set to `http://localhost:8870` for dev; **production `APP_URL` must match the real host** or the CSRF same-origin check will reject form posts.

## Follow-ups

- `can` / `branch` middleware still pass-through → **Step 1.7** (RBAC + policies + audit).
- Account area (`/account/profile`, `/account/password`) + "must change password" enforcement → Step 1.7 or Phase 2.
- Real SMTP `Mailer` driver + `cron/process-email-queue.php` → Step 1.11.
- `2FA (TOTP)` for admin roles — architecture allows it; not in Phase 1.
