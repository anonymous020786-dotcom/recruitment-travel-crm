# Step 12.6 — Deployment dry-run (preflight)

A go-live checker so the Hostinger deployment is a checklist a machine runs, not a memory exercise.

- `App\Support\Preflight` + `php scripts/preflight.php [--url=https://…] [--strict]` — read-only; each result is
  `ok` / `WARN` / `FAIL`; exit 1 if anything failed (or warned, with `--strict`).
- Groups: **PHP** (8.2+, required extensions, GD/phar/intl as warnings, `display_errors` off, memory, upload/post limits vs the
  12 MB app cap, OPcache) · **Configuration** (production env, debug off, `APP_KEY`, https `APP_URL`, secure cookie, HSTS,
  cron secret, real mail, `.env` permissions and not under `public/`) · **Database** (connects, utf8mb4, every migration applied
  and unchanged — sha256 against the ledger, reference data seeded) · **Scheduled jobs** (cron ran in the last 30 min, a
  backup succeeded in 26 h, nothing failing) · **Accounts** (an active admin, 2FA enrolled, no dev/test addresses) ·
  **Filesystem** (writable storage dirs, `.htaccess` guards, built assets, `vendor` present, no dev packages, optimised autoloader) ·
  **From outside** with `--url` (`/health` and `/login` answer, CSP present, HSTS, no `X-Powered-By`, session cookie
  `Secure; HttpOnly`, and that `.env`, `.git`, `composer.json`, `vendor`, `config`, `app`, `database`, `docs`, `scripts`,
  `cron`, `storage` are **not served**, http → https redirect).

Real runs (dev machine): correctly **NOT ready** — 34 passed, 14 warnings, 2 failed (`APP_ENV=local`, `APP_URL=http://localhost`),
naming the exact fixes (GD off, OPcache off, cron not running, dev admin without 2FA, dev packages present…). Against a local
server `--url` verified the 11 sensitive paths return 404 and `X-Powered-By` is gone, and correctly failed the non-Secure dev cookie.

`docs/DEPLOYMENT-HOSTINGER.md` §10 rewritten (it predated the dispatcher and listed 10 of 15 jobs): **one cron line**
`*/5 * * * * php ~/crm/cron/dispatch.php $CRON_SECRET` covers everything; new §13 is the ordered dry-run (preflight on the
server → preflight from outside → backup + restore drill → cron → smoke test → clean up).

Tests — `tests/Feature/PreflightTest.php` (8): a correct production config has no failure; a dev config is not ready (env, key,
url fail; debug/cron/mail warn); debug + insecure cookie + `display_errors` are hard failures in production; PHP limits judged
from ini values (incl. unlimited memory); DB/migration checks against the real schema; a pending migration file is reported as
a FAIL with its name; filesystem checks pass for this layout; the failure counter. Full suite: **916 tests, 3 046 assertions**.

Cannot be done from here (needs the host): running it there. The dry-run doc is the procedure.
