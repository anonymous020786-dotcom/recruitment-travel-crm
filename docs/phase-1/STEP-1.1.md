# Phase 1 · Step 1.1 — Skeleton + bootstrap + configuration

**Status:** implemented, linted, smoke-tested. Awaiting review before Step 1.2.

No framework, zero runtime Composer dependencies (Composer is dev-only for
PHPUnit). Runs on PHP 8.2+.

---

## A. Files created

| File | Purpose |
|---|---|
| `.gitignore`, `.editorconfig` | Repo hygiene; `.env`, `vendor/`, built assets, runtime storage ignored |
| `composer.json` | PSR-4 `App\` → `app/`, dev-only PHPUnit, helper scripts |
| `.env.example` | Documented environment template (copy to `.env`, never committed) |
| `bootstrap/autoload.php` | Zero-dependency PSR-4 autoloader (+ picks up `vendor/autoload.php` if present) |
| `bootstrap/app.php` | Composition root — loads env, builds container, sets runtime posture, returns booted `Application` |
| `bootstrap/handlers.php` | `set_error_handler` / `set_exception_handler` / shutdown fatal catcher; safe render (generic in prod, detailed only outside prod) |
| `app/Support/Env.php` | `.env` parser + typed accessors (`get/required/bool/int/list`); never overrides real server env |
| `app/Support/Config.php` | Loads `config/*.php`, dot-notation `get/set/has` |
| `app/Support/Container.php` | DI container with constructor autowiring, singletons, `call()` |
| `app/Support/Application.php` | Kernel: base paths, `environment()`, `isProduction()`, `isDebug()`, `runningInConsole()`, `boot()` |
| `app/Support/Logger.php` | Daily-file PSR-3-lite logger with secret redaction + `Throwable` formatting |
| `app/Exceptions/HttpException.php` | Carries HTTP status + headers; named constructors (`notFound`, `forbidden`, `tooManyRequests`, `pageExpired`, `conflict`, …) |
| `app/Exceptions/ValidationException.php` | Field-error bag → 422 |
| `app/Exceptions/AuthorizationException.php` | Policy/gate denial → 403 (non-specific message) |
| `app/Exceptions/StaleRecordException.php` | Optimistic-lock failure → 409 |
| `app/Exceptions/DomainRuleException.php` | Business-rule violation with machine code + context |
| `config/app.php` | name, env, debug, url, timezone, locale, key, maintenance, trusted proxies |
| `config/database.php` | MySQL connection + hardened PDO options (real prepares, exceptions, `+00:00`) |
| `config/session.php` | Cookie flags, absolute + idle lifetime, regeneration cadence, DB table |
| `config/security.php` | Hash params, login throttle, CSRF, response headers, CSP (crm/public), masking |
| `config/auth.php` | Session keys, routes, reset token policy, org-wide roles, system roles, UA binding |
| `config/upload.php` | Disks, size cap, MIME/extension allowlists, magic signatures, blocked extensions, image re-encode |
| `config/mail.php` | SMTP/log driver, from address, queue policy |
| `config/cron.php` | CRON secret, lock table, full job registry + reminder windows |
| `config/rate_limits.php` | Bucket definitions (login, search, write, payments.write, upload, import, export, public_form) |
| `config/logging.php` | Log path, level, redaction keys, retention |
| `config/seo.php` | Public defaults, CRM robots directive, robots.txt disallow list, cache TTLs |
| `public/index.php` | Front controller — bootstrap + temporary `/health` (Router lands in Step 1.3) |
| `public/.htaccess` | HTTPS redirect, front-controller rewrite, `-Indexes`, deny dotfiles, deny all `.php` except `index.php`, asset caching, deflate |
| `.htaccess` (root) | **Fallback** for hosts that can't move docroot — routes into `public/`, hard-denies `app/bootstrap/config/database/cron/resources/scripts/storage/tests/docs/vendor` + dotfiles/`.env`/`.sql` |
| `storage/.htaccess`, `storage/private/.htaccess` | `Require all denied` + `engine off` + handler stripping (defence in depth) |
| directory skeleton | `app/**`, `routes/`, `database/{migrations,seeders}`, `resources/{views,lang}`, `cron/`, `scripts/`, `tests/**`, `storage/**` with `.gitkeep` |

## B. Files modified

None (new project).

## C. Database migration

None in this step. Migration **runner** + split migration files are Step 1.2.
The canonical `database/schema/schema.sql` from Phase 0 is unchanged.

## D. Backend implementation notes

- **Bootstrap order:** autoload → `Env::load(.env)` → `Application` → bind `Config` (singleton) → bind `Logger` (singleton) → set `error_reporting`/`display_errors` by env → harden `session.*` ini → `boot()` (timezone) → register handlers.
- **Debug is forced off in production** regardless of `.env` (`Application::isDebug()` returns false whenever `env === production`).
- **Container** autowires constructor dependencies by type-hint; supports `singleton()`, `instance()`, `call()` for controller/closure invocation later.
- **Logger** redacts `password/token/secret/authorization/api_key/cookie` keys and formats `Throwable` context; writes `storage/logs/app-YYYY-MM-DD.log` with `LOCK_EX`.
- **CLI vs web:** handlers detect `PHP_SAPI` — CLI errors go to STDERR + exit 1; web errors render HTML.

## E. Frontend implementation

None yet (view layer is Step 1.8). `public/index.php` returns plain text +
`/health` JSON only.

## F. Security implementation in this step

- `.env` and all application directories are unreachable over HTTP (public `.htaccess` + fallback root `.htaccess` + `storage/.htaccess`).
- Only `public/index.php` is executable as PHP inside the web root.
- Production: `display_errors=0`, `log_errors=1`, generic error pages — no stack traces, SQL, paths, or env values leaked. A short non-reversible reference string is shown for support correlation.
- Session ini hardened at bootstrap: `use_strict_mode`, `use_only_cookies`, `cookie_httponly`, `cookie_samesite`, `cookie_secure` (when configured).
- `Env` never overrides real server-set environment variables (prevents `.env` shadowing hardened server config).
- Secret redaction in logs.
- HSTS/HTTPS: redirect in `.htaccess` + (authoritative header in Step 1.4).

## G. Test cases (to add as automated tests in Step 1.2 once PHPUnit is wired)

| # | Test | Expected |
|---|---|---|
| 1 | `Env::bool` parses `true/1/yes/on` / `false/0/no/off` | correct booleans; unknown → default |
| 2 | `Env::get` returns server env over `.env` value | server wins |
| 3 | `Config::get('app.debug')` dot access + default | value / default |
| 4 | `Application::isDebug()` with `env=production, debug=true` | `false` |
| 5 | `Application::isDebug()` with `env=local, debug=true` | `true` |
| 6 | `Container` autowires nested type-hinted deps | instance built |
| 7 | `Container::singleton` returns same instance twice | identity equal |
| 8 | `Logger` redacts `password` key in context | `[redacted]` on disk |
| 9 | Exception handler in prod renders generic 500, no trace | body has no `getTraceAsString` output |
| 10 | `HttpException::tooManyRequests(30)` carries `Retry-After: 30` | header present |

## H. Manual QA checklist — run now

- [x] `find . -name '*.php' | xargs -n1 php -l` → all "No syntax errors" *(passed)*
- [x] `php -S 127.0.0.1:8899 -t public` then `GET /health` → `200 {"status":"ok",...}` *(passed)*
- [x] `GET /` → `200` text + `X-Robots-Tag: noindex` *(passed)*
- [x] CLI: `require bootstrap/app.php`, read config, log a line with a `password` key → line written, value `[redacted]` *(passed)*
- [ ] On a real Apache host: `curl https://<domain>/../.env` and `/storage/private/documents/x` → `403`
- [ ] On a real Apache host: `curl http://<domain>/health` → `301` to `https`
- [ ] Set `.env` `APP_ENV=production APP_DEBUG=true`, trigger an error → generic page, **no** trace (verifies prod lock)

## I. Performance considerations

- No autoloader classmap scan; direct path mapping.
- `Config` reads ~11 small files once per request; candidate for a single cached
  array file in production (added in Step 1.9 via `scripts/optimize.php`).
- Logger appends with `LOCK_EX`; negligible. No per-request session GC (cron owns it).
- Zero external HTTP, zero DB calls in bootstrap.

## J. Deployment considerations

- Upload the tree so that **only `public/` is the document root**. If not possible, place under `public_html/` and rely on the root `.htaccess` (already written).
- `cp .env.example .env`, fill DB + `APP_URL` + generate `APP_KEY`
  (`php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`), `chmod 600 .env`.
- Ensure PHP 8.2+ with `pdo_mysql, mbstring, openssl, fileinfo, gd, json`.
- `storage/` must be writable by PHP; everything else read-only.
- `composer install --no-dev` is **optional** (only needed for the optimized
  autoloader / PHPUnit); the app runs without `vendor/`.

---

## Review asks

1. Confirm the **config surface** (keys/defaults) — especially `security.php` CSP and header policy, `auth.php` roles, `cron.php` job list.
2. Confirm **zero-Composer-runtime** is acceptable (keeps shared hosting simple; PHPUnit stays dev-only).
3. Confirm docroot strategy: primary = docroot at `public/`; fallback root `.htaccess` provided.

On approval → **Step 1.2: Database layer (`Db` PDO wrapper) + migration runner + split migrations + seed harness.**
