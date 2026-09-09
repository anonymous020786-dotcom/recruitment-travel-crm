# Phase 1 · Step 1.5 — Sessions + CSRF

**Status:** implemented; 114 tests green (incl. real MariaDB integration + full CSRF round-trip).

## A. Files created

| File | Purpose |
|---|---|
| `app/Session/Session.php` | Session data + behaviour, independent of PHP's `session` extension (unit-testable): `get/put/has/forget/pull/flush`, flash bag (`flash/now/reflash/keep/ageFlashData`), `token/regenerateToken`, `regenerate/invalidate` with old-id migration, previous-url, id validation |
| `app/Session/SessionStore.php` | Backing-store interface (`read/write/destroy/gc`) |
| `app/Session/DatabaseSessionStore.php` | `sessions` table; **JSON** payload (no PHP-unserialize / object-injection surface); upsert write with `user_id/ip/ua` meta; `gc()` for cron |
| `app/Session/ArraySessionStore.php` | In-memory store for tests |
| `app/Http/Middleware/StartSession.php` | cookie ↔ store ↔ `Session`; validates cookie id format; enforces **absolute lifetime + idle timeout** (either → fresh session, old destroyed); rotates id on a cadence (fixation defence) with data carried over; ages flash; exposes `session` request attribute + container instance; writes back; queues `HttpOnly; Secure; SameSite=Lax` cookie |
| `app/Http/Middleware/VerifyCsrf.php` | non-read methods require a valid per-session token (`_token` field **or** `X-CSRF-Token` header, `hash_equals`) **and** same-origin `Origin`/`Referer` when present; `config('security.csrf.except')` path patterns skipped → `419` |
| `app/Support/Signer.php` | HMAC-SHA256 with `APP_KEY`: `sign/unsign` (tamper-evident), `timedToken/verifyTimedToken` (stateless, for public-form CSRF later); hard error if key missing/short |
| `tests/Unit/Session/SessionTest.php`, `tests/Unit/Support/SignerTest.php`, `tests/Unit/Http/Middleware/StartSessionTest.php`, `tests/Unit/Http/Middleware/VerifyCsrfTest.php` | unit coverage |
| `tests/Feature/DatabaseSessionStoreTest.php` | real MySQL/MariaDB `sessions` table (auto-skips if no DB) |
| `tests/Feature/HttpKernelTest.php` | full `web.crm` stack: session issue, headers, CSRF 419, valid-token round-trip, foreign-origin 419 |

## B. Files modified

- `app/Http/Kernel.php` — `web.crm` and `api` groups → `StartSession` + `VerifyCsrf`. `web.public` deliberately **session-free** so public pages stay proxy-cacheable.
- `bootstrap/app.php` — bind `Signer` + `SessionStore` (DatabaseSessionStore) singletons.
- `app/Support/helpers.php` — `session()`, `csrf_token()`, `csrf_field()`, `old()`.
- `app/Support/Container.php` — `bound()` (explicit bindings/instances only, unlike `has()`).
- `app/Exceptions/Handler.php` — treat `/api/*` paths as JSON clients so routing-time 404/405 render JSON (group middleware hasn't run yet at that point).

## C. Migration

None — uses the `sessions` table from `0001_initial_schema`.

## F. Security

- **Session fixation**: id rotated on a fixed cadence and on every privilege change (login/logout land in 1.6); old id destroyed in the store.
- **Idle + absolute timeout**: `session.idle_minutes` (30) and `session.lifetime_minutes` (480) both enforced server-side; expiry wipes all data.
- **Cookie**: `HttpOnly` always; `Secure` from `config('session.secure')` (true in prod); `SameSite=Lax`; value is the opaque 64-hex id only — no app data in the cookie.
- **CSRF**: token bound to the session, compared with `hash_equals`; additionally a same-origin check on `Origin`/`Referer` (when present) blocks cross-site POSTs even if a token leaked. `419` on any failure.
- **Payload storage**: JSON, not `serialize()` — no object-injection / POP-chain surface.
- **Signer**: constant-time verification; refuses to construct without a real `APP_KEY`.

## H. Manual QA — verified

- [x] `GET /dashboard` → `Set-Cookie: crm_session=<64hex>; expires=…; Max-Age=28800; path=/; HttpOnly; SameSite=Lax`; row appears in `sessions`
- [x] `GET /` (public) → **no** `Set-Cookie` (stays cacheable)
- [x] Feature test: `POST` with no token → 419; with cookie + valid token + same origin → 200; with token + foreign `Origin` → 419
- [x] Idle-timeout / absolute-timeout / id-rotation unit tests (data preserved on rotation, wiped on timeout, old id destroyed)
- [x] Real MariaDB: write/read/upsert/destroy/gc on `sessions`
- [x] 114 tests, 295 assertions
- [ ] Real Apache + HTTPS: confirm `Secure` flag present on the cookie

## I. Performance

- 2 DB round-trips per CRM request (session read + write). Acceptable; `web.public` GETs do **zero** (no session).
- Session GC runs from `cron/cleanup.php`, never per-request (no lottery).
- Payload is small JSON; `user_id` column indexed for admin "active sessions" views later.

## Follow-ups

- Login/logout call `session()->regenerate()` / `invalidate()` — **Step 1.6**.
- `_old_input` populated by the validation layer so `old()` works in forms — **Step 1.6**.
- Public-form CSRF via `Signer::timedToken` (stateless, no session on public pages) — when public forms are built.
- Add session write only when the session is dirty (skip the write on pure reads) — optimisation, later.
