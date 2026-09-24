# Step 12.4 — Web server, headers, cookies and secrets review

Scope: `.htaccess` files, response headers (normal and error pages), session cookie, `.env` handling, auth-flow
skim. Checked live with `curl` against `php -S` and pinned by `tests/Feature/WebServerConfigTest.php`.

## Findings and fixes

| # | Finding | Severity | Fix |
|---|---|---|---|
| 1 | **Fallback `.htaccess` (used when the host will not let you move the docroot) could never serve CSS/JS.** The `assets/` rule came *after* the catch-all that sends every non-file to `index.php`, so it was unreachable and every asset request ended at the app's 404 page. | high for that deployment mode | asset rule moved before the catch-all |
| 2 | Fallback `.htaccess` did not deny `.git/` (the dotfile rule only matches a file *named* `.something`, so `/.git/config` was readable when the site is deployed by `git clone` into `public_html`), nor `routes/`, `.claude/`, `.phpunit.cache/`. | high (source + history disclosure) | added to the deny list; the rule now runs first and also matches the bare directory name |
| 3 | `X-Powered-By: PHP/8.2.x` was sent on every response. | low | `header_remove()` in `Response::send()` + `Header always unset` in `public/.htaccess` (`expose_php` cannot be changed at runtime) |
| 4 | Error pages (404 and any exception thrown before the header middleware finished) had no `Content-Security-Policy`, `Permissions-Policy` or COOP/CORP. | low | the exception handler's baseline now adds them (CSP: no scripts, `frame-ancestors 'none'`, own stylesheet only) |
| 5 | 2FA recovery codes were hashed with `hash_hmac(…, config('app.key', 'k'))`: an unset key silently meant an empty/guessable HMAC key. | medium (misconfiguration → weak storage) | refuse (exception) when `APP_KEY` is missing or < 16 chars |

Checked and fine: HSTS only over HTTPS in production; strict nonce CSP for the CRM (no inline script); public site CSP
relaxes only styles and adds integration hosts only when configured; `X-Frame-Options` DENY/SAMEORIGIN; session
cookie `HttpOnly`, `SameSite=Lax`, `Secure` by default (`SESSION_SECURE` defaults to true; dev `.env` turns it off);
`.env` is git-ignored and never tracked; `.env.example` has production-safe defaults and no secrets; login failure
message is generic and timing-equalised, reset tokens are 256-bit, stored hashed, single-use, expire, and requests
are throttled and never reveal whether an address exists; `storage/**` denied by its own `.htaccess`, PHP engine off
in `storage/private`; CORS is not enabled anywhere.

## Tests — `tests/Feature/WebServerConfigTest.php` (12) + `TwoFactorTest`

- the fallback `.htaccess` denies **every top-level directory except `public/`** (a new folder without a rule fails the
  build), and its deny and asset rules precede the catch-all;
- forces HTTPS, blocks dotfiles/secrets; `public/.htaccess` serves only `index.php` as PHP, blocks sensitive
  extensions, unsets `X-Powered-By`; every `storage` directory has a deny file;
- `.env.example` is production-safe (`APP_ENV=production`, debug off, secure cookies, HSTS on, https URL, all secrets
  blank); `.env` is ignored; session cookie config;
- error responses (404 html/json, 403, 500 html/json) carry the security headers and never echo an exception message;
- recovery codes refuse to hash with a missing or short `APP_KEY`.

Full suite: **895 tests, 2909 assertions** (3 skipped without GD).

Live check: `X-Powered-By` gone; a 404 now returns the CSP / Permissions-Policy / COOP headers.
