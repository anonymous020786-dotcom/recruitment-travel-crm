# Phase 1 · Step 1.3 — HTTP core + router

**Status:** implemented; 58 unit tests green; smoke-tested (local + production).

## A. Files created

| File | Purpose |
|---|---|
| `app/Http/Request.php` | Immutable request view built once from superglobals. Method (+ `_method`/`X-HTTP-Method-Override`, POST-only), path normalisation, `input()` precedence (post → json → query), `only/has/filled/boolean/integer`, JSON body, `wantsJson()`, headers, cookies, normalised files, `ip()` (trusted-proxy aware — ignores `X-Forwarded-For` unless the peer is a configured proxy), `ipBinary()`, route params |
| `app/Http/Response.php` | Response value object. `html/text/json/noContent/redirect/stream` factories; **open-redirect guard** (external URLs rejected unless opted in); header-injection stripping on `Location`; header-name normalisation; cookie queue; `send()` |
| `app/Http/Route.php` | One route: segment-wise regex compile (`{param}` / `{param?}`), `match()`, `matchesPathOnly()` (for 405), `url()` generation with missing-param guard |
| `app/Http/Router.php` | `get/post/put/patch/delete/any`, `group()` (prefix + middleware + name, nestable), named routes, `route()` URL gen, `dispatch()` → 404 / 405 (`Allow` header) / pipeline → container-resolved handler; return-type normalisation (Response \| array/obj → JSON \| string → HTML \| null → 204) |
| `app/Http/Pipeline.php` | Onion middleware runner; resolves entries as class \| instance \| `Class:arg,arg` |
| `app/Http/Middleware/Middleware.php` | `handle(Request, Closure $next): Response` interface |
| `app/Http/Middleware/Passthrough.php` | Placeholder for aliases whose real impl lands in later steps |
| `app/Http/Kernel.php` | `global` / `groups` (`web.public`, `web.crm`, `api`) / `aliases` (`auth`, `guest`, `can`, `branch`, `throttle`, `verified`) + `expand()` |
| `app/Exceptions/Handler.php` | `report()` (logs, skips expected control-flow exceptions) + `render()` (Throwable → safe Response; JSON vs HTML negotiation; debug page only for 5xx outside production; error-view files honoured if present) |
| `app/Support/helpers.php` | `app/config/logger/base_path/storage_path/e/e_attr/e_url/response/json_response/redirect/route/redirect_route/url/abort/abort_unless/abort_if` |
| `app/Controllers/Controller.php` | Thin base controller |
| `app/Controllers/HealthController.php` | `/health` (liveness), `/health/db` (readiness + migration version) |
| `app/Controllers/Public/HomeController.php` | Public home placeholder (cacheable) |
| `routes/web.php`, `routes/api.php` | Route definitions with intended middleware names |
| `tests/Unit/Http/*` | `RequestTest, ResponseTest, RouterTest` (+ `StopMiddleware` fixture) |

## B. Files modified

- `bootstrap/app.php` — require `helpers.php`; bind `Router` + `Kernel` singletons.
- `bootstrap/handlers.php` — delegate to `App\Exceptions\Handler`; bind it.
- `app/Support/Application.php` — static `getInstance()` for helpers.
- `app/Support/Container.php` — resolve overrides by **type name** as well as parameter name (so `Request $anything` injects the current request).
- `public/index.php` — real front controller: capture Request → load routes → dispatch → render exceptions via Handler → send.

## C. Database migration

None.

## D. Backend

Request → `Router::dispatch` → `Kernel::expand(route middleware)` → `Pipeline`
(onion) → container-resolved controller method (route params + `Request`
injected by name or type) → return normalised to `Response` → `send()`.
Any `Throwable` → `Handler::report()` + `Handler::render()`.

## E. Frontend

Public home placeholder + a dashboard placeholder only. Real views = Step 1.8.

## F. Security

- **Open-redirect guard**: `Response::redirect()` rewrites `//host` / `https://host` targets to `/` unless `allowExternal: true`; strips CR/LF/control chars from `Location` (no header injection). Test-covered.
- **Host header not trusted for links**: `url()` / `route()` build from `config('app.url')`, never `Host`.
- **Proxy spoofing**: `X-Forwarded-For` / `-Proto` honoured only when `REMOTE_ADDR` is in `config('app.trusted_proxies')`. Test-covered.
- **Method override** only from a real POST (not GET), and only to PUT/PATCH/DELETE. Test-covered.
- **Error responses leak nothing**: 4xx show generic titles (the internal "No route for GET /x" string stays in logs only — only 419/422/429 surface their message); stack traces only for 5xx and only outside production; every error page carries `<meta robots noindex>` + a random non-reversible reference id.
- **Route param regex** restricts each segment to `[A-Za-z0-9._~-]+` (one segment; no slashes, no traversal).
- `Db::identifier` guard already blocks SQL-identifier injection (Step 1.2).

## G / H. Tests + Manual QA — done

- [x] `phpunit` → **58 tests, 183 assertions, OK**
- [x] `php -l` all new files → clean
- [x] `php -S 127.0.0.1:PORT public/index.php` (router-script mode = mimics Apache rewrite):
  - `/` → 200 HTML (`Cache-Control: public, max-age=300`)
  - `/health` → 200 JSON, `Cache-Control: no-store`
  - `/health/db` → 200 JSON `{status:ok, migration:0001_initial_schema}`
  - `/api/ping` → 200 JSON
  - `/dashboard` → 200 (placeholder)
  - `/leads/01H`, `/nope` → 404
  - `POST /health` → 405 with `Allow: GET, HEAD`
  - `/nope` + `Accept: application/json` → `{"message":"The page you are looking for could not be found."}` (no path, no class)
- [x] `APP_ENV=production APP_DEBUG=false`: 404 page is generic + reference id, **no** file paths / trace
- [ ] On real Apache: confirm `.htaccess` rewrite delivers unknown paths to `index.php` (built-in server needs the router-script form; Apache does it via `.htaccess`)

## I. Performance

- Routes are plain objects; matching is a linear scan of compiled regexes (fine at this route count; a static prefix map can be added if the table grows large).
- No reflection on the hot path except container autowiring of the controller (one class) and its constructor deps.
- `Request` reads `php://input` once, caches JSON decode.
- Middleware pipeline builds closures once per request.

## J. Deployment

- Front controller unchanged in shape; `.htaccess` from Step 1.1 already routes to it.
- `HEAD` is routed alongside `GET`; `Response::send()` currently also writes the body for `HEAD` (harmless via Apache which strips it; a `HEAD` body-suppression guard is noted for Step 1.4's `SecurityHeaders`/output stage).
- `.env` recreated locally for dev (gitignored). Local `crm_dev` DB from Step 1.2 still in use.

## Follow-ups for later steps

- Real `SecurityHeaders`, `EnforceHttps`, `RequestId`, `MaintenanceGuard`, `PublicCache`, `NoStoreCache` → **Step 1.4** (fill `Kernel::$global` and `$groups`).
- Real `StartSession` + `VerifyCsrf` → **Step 1.5**.
- Real `Authenticate` (`auth`), `RateLimit` (`throttle`) → **Step 1.6**.
- Real `Authorize` (`can`), `BindBranchScope` (`branch`) → **Step 1.7**.
- `HEAD` response body suppression in the output stage — Step 1.4.
