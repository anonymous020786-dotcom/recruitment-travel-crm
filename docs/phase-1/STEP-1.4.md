# Phase 1 · Step 1.4 — Security headers, HTTPS, request-id, maintenance

**Status:** implemented; 79 tests green; headers + maintenance verified over HTTP.

## A. Files created

| File | Purpose |
|---|---|
| `app/Support/Ulid.php` | ULID generator (26-char Crockford base32, 48-bit time + 80-bit random, monotonic within a ms). Used for `public_id`, request ids, maintenance secrets |
| `app/Http/Middleware/RequestId.php` | Correlation id: accepts a well-formed inbound `X-Request-Id`, else generates one → request `request_id` attribute + `X-Request-Id` response header + attached to every log line |
| `app/Http/Middleware/SecurityHeaders.php` | Profiles `crm` / `public` / `api`. Per-request CSP **nonce** (`csp_nonce` request attr), CSP from `config/security.php` (no `unsafe-inline` for scripts in crm/api), HSTS (only https + production), `X-Frame-Options`, `X-Robots-Tag` (crm/api), `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-*`, profile cache headers |
| `app/Http/Middleware/EnforceHttps.php` | Production: 301 insecure → `https://<config app.url host>` + path/query. Never uses the `Host` header |
| `app/Http/Middleware/MaintenanceGuard.php` | 503 for everyone while `storage/framework/down` exists (or `config('app.maintenance.enabled')`); bypass via `?secret=` (sets short-lived cookie) or allow-listed IP |
| `app/Http/Middleware/ForceJson.php` | Marks `api` requests so errors render as JSON without an `Accept` header |
| `scripts/down.php`, `scripts/up.php` | Toggle maintenance mode (DB-free); `down.php` prints a bypass URL |
| `tests/Support/TestApp.php` | Minimal wired `Application` for middleware/service tests |
| `tests/Unit/Support/UlidTest.php` | Shape, validity, monotonicity, ordering, timestamp roundtrip, 5k-uniqueness |
| `tests/Unit/Http/Middleware/*` | `SecurityHeadersTest`, `RequestIdTest`, `EnforceHttpsTest`, `MaintenanceGuardTest` |

## B. Files modified

- `app/Http/Kernel.php` — `global` = [RequestId, EnforceHttps, MaintenanceGuard]; groups add `SecurityHeaders:<profile>` (+ `ForceJson` for api); alias `headers`.
- `app/Http/Request.php` — `attributes` bag (`setAttribute`/`attribute`/`hasAttribute`); `wantsJson()` honours `force_json`.
- `app/Http/Response.php` — `send(bool $withBody)` for HEAD / 204 / 304.
- `app/Support/Logger.php` — `withContext()` base context merged into every line.
- `app/Support/Container.php` — resolve container overrides by **type name** too.
- `app/Exceptions/Handler.php` — `withBaselineHeaders()`: every error response gets `nosniff`, `Referrer-Policy`, `X-Frame-Options: DENY`, `X-Robots-Tag: noindex`, `no-store` even if middleware never ran.
- `public/index.php` — `send(withBody: realMethod !== 'HEAD')`.
- `routes/web.php` — `/health*` get `headers:api`.

## C. Migration

None.

## F. Security

- **CSP**: crm/api = `default-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'`, `script-src 'self' 'nonce-…'` (no `unsafe-inline`). public relaxes `img-src https:` + `style-src 'unsafe-inline'` for OG/marketing only. Nonce regenerated per request.
- **HSTS** emitted only when the response is actually over HTTPS **and** `APP_ENV=production` — never poisons local/dev.
- **Clickjacking**: `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` on the CRM.
- **HTTPS**: production redirect target derived from `config('app.url')`, immune to `Host` spoofing.
- **Maintenance bypass**: constant-time `hash_equals` on the secret; bypass cookie stores `sha256(secret)`, `HttpOnly`, `Secure` when the request is secure.
- **Error responses** carry safe headers unconditionally (see Handler change).

## H. Manual QA — verified over HTTP

- [x] `/` (public): relaxed CSP, `X-Frame-Options: SAMEORIGIN`, **no** `X-Robots-Tag`, `Cache-Control: public, max-age=300`, `X-Request-Id` present
- [x] `/dashboard` (crm): strict CSP w/ nonce, `DENY`, `X-Robots-Tag: noindex…`, `Cache-Control: private, no-store`
- [x] `/health` (api): `X-Robots-Tag`, `no-store`, JSON
- [x] `HEAD /` → status line only, no body
- [x] `scripts/down.php` → `/dashboard` = 503 + `Retry-After: 45`; `?secret=<good>` = 200; `?secret=wrong` = 503; `scripts/up.php` → 200
- [x] 79 tests, 224 assertions green
- [ ] Real Apache/HTTPS: confirm HSTS header appears and `http→https` 301 works

## I. Performance

- One `random_bytes(16)` per request for the CSP nonce; negligible.
- Headers assembled from cached config; no I/O.
- `MaintenanceGuard` does one `is_file()` stat per request (only reads/parses the file when down).

## Follow-ups

- Role-based maintenance bypass (super_admin) — **Step 1.6** once auth exists.
- Session + CSRF middleware into `web.crm` / `api` groups — **Step 1.5** (next).
- Error views (`resources/views/errors/*`) replacing inline HTML — **Step 1.9**.
