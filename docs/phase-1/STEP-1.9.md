# Phase 1 · Step 1.9 — Error pages, config cache, robots/sitemap, deploy polish

**Status:** implemented; 186 tests green. **Phase 1 (Foundation) is complete.**

## A. Files created

| File | Purpose |
|---|---|
| `resources/views/layouts/error.php` | Shared styled error layout (built `app.css`, centered card, reference id, `actions` section) |
| `resources/views/errors/{401,403,404,409,419,422,429,500,503}.php` | One view per status, helpful message + next-step actions |
| `app/Controllers/Public/SeoController.php` | `robots.txt` (disallow list from `config('seo.robots_disallow')` + sitemap link) and `sitemap.xml` (static pages; feature phases append job/package/blog URLs) |
| `scripts/optimize.php` | Caches the resolved config tree → `bootstrap/cache/config.php`; runs `composer dump-autoload -o` when composer is present; verifies the cache parses |
| `scripts/clear.php` | Removes generated caches |
| `docs/DEPLOYMENT-HOSTINGER.md` | 12-step shared-hosting deployment runbook |

## B. Files modified

- `app/Support/Config.php` — constructor now accepts a pre-built array (cache) **or** a dir; `dumpTo()` writes a `var_export` cache file.
- `bootstrap/app.php` — load `bootstrap/cache/config.php` when present, else scan `config/`.
- `app/Exceptions/Handler.php` — `htmlError()` renders `errors.{status}` through the `View` engine when available (styled, uses the layout); the self-contained inline page remains the fallback for very early failures.
- `scripts/migrate.php` — `--baseline` marks all pending migrations applied **without running them** (SSH-less hosts that import `schema.sql` via phpMyAdmin).
- `routes/web.php` — `/robots.txt`, `/sitemap.xml` in the public group.
- `.gitignore` — `bootstrap/cache/*`, `.phpunit.cache/`.

## C. Migration

None.

## F. Security

- Error pages: `noindex` meta + baseline safe headers (`Handler::withBaselineHeaders`), a non-reversible 12-char reference id, and **never** a stack trace / SQL / path in production (debug page only for 5xx outside production).
- Error views link `app.css` from `'self'` — no inline `<style>`, so the strict CSP stays intact on error responses.
- `robots.txt` disallows every private path (`/dashboard`, `/leads`, `/admin`, `/api`, `/login`, `/documents`, …).
- Config cache is a plain PHP array of already-resolved scalars — no closures, no new secret exposure (`.env` values are frozen at cache time — re-run `optimize` after any `.env` change; documented).

## H. Manual QA — verified

- [x] `GET /nonexistent` → **404**, styled "Page not found" page + reference id, no leakage
- [x] `GET /robots.txt` → full `Disallow:` list + `Sitemap:` line, `Cache-Control: public, max-age=86400`
- [x] `GET /sitemap.xml` → valid `<urlset>`, `200`, `application/xml; charset=UTF-8`
- [x] `php scripts/optimize.php` → `bootstrap/cache/config.php` written (13 groups, ~31 KB); app still serves 200 with the cache active; `php scripts/clear.php` removes it
- [x] `php scripts/migrate.php --baseline` → on a populated DB: "Nothing to baseline"
- [x] **186 tests, 447 assertions** green

## I. Performance

- Config: with the cache, one `require` of a single file per request instead of 13 `require`s + a `glob`.
- Error pages: one view render, zero DB.
- `robots.txt` / `sitemap.xml` carry long public cache headers.
- Route caching is **not** implemented — routes are defined in closures (not serialisable) and the route table is small. Accepted trade-off, documented.

## J. Deployment

`docs/DEPLOYMENT-HOSTINGER.md` is the authoritative runbook. Sequence: upload →
docroot → `public/` → `.env` (+ `APP_KEY`, `chmod 600`) → PHP 8.2 + extensions →
`chmod 755 storage bootstrap/cache` → `migrate` → `seed` → `create-admin` → SSL →
`optimize` → cron → verify. SSH-less path documented (`phpMyAdmin` import +
`migrate --baseline`).

---

# Phase 1 — Foundation: COMPLETE

| Step | Delivered |
|---|---|
| 1.1 | Skeleton, zero-dep bootstrap, config, container, logger, exceptions, `.htaccess` hardening |
| 1.2 | `Db` PDO wrapper, migration runner, seeders — schema portable across MySQL 8 / MariaDB 10.4 |
| 1.3 | Request / Response / Router / Pipeline / Kernel / `Handler` |
| 1.4 | RequestId, SecurityHeaders (CSP nonce, HSTS), EnforceHttps, MaintenanceGuard, `Ulid` |
| 1.5 | `Session` + DB store, `StartSession` (idle/absolute timeout, id rotation), `VerifyCsrf`, `Signer` |
| 1.6 | `Hash`, `RateLimiter` + `throttle:`, `Validator`, `View`, `Auth` + `AuthService` (lockout, enumeration-safe reset), login/logout/reset, `Mailer` |
| 1.7 | Permission catalogue + matrix, `PermissionService` (deny-wins), `BranchScope`, `Gate` + `Policy`, `Authorize`/`BindBranchScope`, `AuditService` (append-only) |
| 1.8 | Tailwind build (`npm run build`), `Assets` manifest, component library, CRM app layout, `resources/js/app.js` |
| 1.9 | Error pages, config cache, `robots.txt`/`sitemap.xml`, `optimize`/`clear`, `migrate --baseline`, deployment guide |

**Exit criteria — all met:**
- [x] Log in as a seeded admin → reach `/dashboard`
- [x] A permission-denied action → 403 (`RbacHttpTest`)
- [x] CSRF enforced on writes → 419 without a valid token (`HttpKernelTest`)
- [x] Security headers verified in the response (CSP, HSTS, X-Frame-Options, noindex, no-store)
- [x] Migrations reproducible from scratch on MySQL/MariaDB (`--fresh` + `migrate`)
- [x] An audited write appears in `activity_logs` (`AuditServiceTest`, login event)
- [x] `storage/` + `.env` unreachable over HTTP (`.htaccess` — verify on real Apache)

**Tally:** 186 automated tests (447 assertions); ~13 commits; 9 step reports.

## Next: Phase 2 — Leads

Lead CRUD + `lead_number` sequence, duplicate detection + merge, assignment + bulk
assignment, configurable statuses + transition rules, notes, follow-ups, timeline,
keyset-paginated filters/search, import/export, WhatsApp/call shortcuts,
transactional conversion → candidate. First feature phase: introduces
`LeadRepository` (consuming `BranchScope`), `LeadService`, `LeadPolicy` +
`Gate::policy()` registration, `LeadValidator`, and the first real CRUD screens
built on the Phase 1.8 component library.
