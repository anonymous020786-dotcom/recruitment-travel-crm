# Deploying to Hostinger shared hosting

Target: Apache + PHP 8.2+ + MySQL/MariaDB, cron, SSL. No root, no Node guaranteed
on the server (build assets locally and commit them — they already are).

## 0. One-time, on your machine

```bash
composer install --no-dev --optimize-autoloader   # optional — app runs without vendor/
npm install && npm run build                       # regenerates public/assets/build/*
git push                                            # (or make a release zip)
```

The app runs with **zero Composer runtime dependencies**; `vendor/` is only
needed for PHPUnit. The built CSS/JS + `manifest.json` are committed.

## 1. Database (hPanel → Databases → MySQL)

1. Create database `uXXXX_crm`.
2. Create user `uXXXX_crm`, strong password, grant **all privileges** on that DB.

## 2. Upload the application

**Preferred (SSH):**
```bash
cd ~ && git clone <your-repo> crm && cd crm
composer install --no-dev --optimize-autoloader     # optional
```

**File Manager:** upload a zip of the project to `~/crm`, extract.

Layout:
```
~/crm/                 ← application (NOT web-accessible)
~/crm/public/          ← the ONLY web-exposed directory
```

## 3. Document root

- hPanel → your domain → **Advanced / Change document root** → set it to `crm/public`.
- **If the host won't let you change it:** put `crm/` inside `public_html/` and the
  provided `crm/.htaccess` (already written) routes traffic into `public/` and
  hard-denies every other directory.

## 4. Environment

```bash
cp .env.example .env
php -r "echo 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # paste into .env
```

Set in `.env`:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com          # MUST match the real host (CSRF origin check)
APP_TIMEZONE=Asia/Kolkata
DB_HOST=127.0.0.1
DB_NAME=uXXXX_crm
DB_USER=uXXXX_crm
DB_PASSWORD=...
SESSION_SECURE=true
CRON_SECRET=<random string>
MAIL_HOST=smtp.hostinger.com  MAIL_PORT=465  MAIL_ENCRYPTION=ssl
MAIL_USERNAME=...  MAIL_PASSWORD=...  MAIL_FROM_ADDRESS=no-reply@your-domain.com
```

```bash
chmod 600 .env
```

## 5. PHP version & extensions (hPanel → PHP Configuration)

- PHP **8.2** or newer.
- Enable: `pdo_mysql, mbstring, openssl, fileinfo, gd` (or `imagick`), `intl`, `zip`, `json`.

## 6. Permissions

```bash
chmod -R 755 storage bootstrap/cache
# storage/ must be writable by PHP; everything else stays read-only.
```

## 7. Schema + seed data

**With SSH:**
```bash
php scripts/migrate.php            # creates all tables, records versions
php scripts/seed.php               # countries, roles, permissions, role matrix
php scripts/create-admin.php --email=you@your-domain.com --name="Your Name"
```

**Without SSH (phpMyAdmin only):**
1. phpMyAdmin → import `database/schema/schema.sql`.
2. Import the seed data, or ask your developer for a seed SQL dump.
3. Later, when SSH becomes available: `php scripts/migrate.php --baseline` so the
   migration runner knows `0001`/`0002` are already applied.

## 8. HTTPS

- hPanel → SSL → install **Let's Encrypt / AutoSSL**.
- Force HTTPS on. The app also 301-redirects HTTP→HTTPS in production and sends
  HSTS once it sees a real HTTPS request.

## 9. Optimise (each deploy, after `.env` is set)

```bash
php scripts/optimize.php      # caches config to bootstrap/cache/config.php
```
Run `php scripts/clear.php` after any `.env`/`config/` change, then re-optimise.

## 10. Cron (hPanel → Cron Jobs)

**One line is enough.** The dispatcher reads the schedule of every job in `config/cron.php`, runs what is due (catching
up anything missed) and records each run, so it works on plans that allow a single cron entry:

```
*/5 * * * *   php ~/crm/cron/dispatch.php $CRON_SECRET
```

That covers all 15 jobs: follow-ups, document/passport/visa/medical expiry, interview and payment reminders, the email
queue, exports, dashboard cache, daily report, integrity check, **cron-health** (alerts admins when a job fails, sticks or
runs late), **backup** (04:00 UTC — see `docs/BACKUP-RESTORE.md`) and cleanup. Watch them on **/admin/cron**.

If you prefer one line per job, use the `schedule` shown in `config/cron.php` for each `php ~/crm/cron/<job>.php $CRON_SECRET`
(do not run both styles for the same job; the per-job lock makes it harmless but pointless).

Set `DASHBOARD_CACHE_SECONDS=600` in `.env` so the dashboard warm-up job actually keeps the cache warm.
## 11. Verify (do all of these)

| Check | Expected |
|---|---|
| `curl -I https://your-domain.com/health` | `200`, JSON |
| `curl -I http://your-domain.com/` | `301` → `https://` |
| `curl https://your-domain.com/../.env` and `/storage/private/…` | `403` |
| `curl -I https://your-domain.com/assets/build/app.<hash>.css` | `200`, `Cache-Control: immutable` |
| Sign in at `/login` with the admin account | reaches `/dashboard` |
| Response headers on `/dashboard` | `X-Robots-Tag: noindex`, `Cache-Control: no-store`, strict CSP, `Strict-Transport-Security` |
| Trigger a 404 | styled error page, **no** stack trace / paths |
| `/robots.txt`, `/sitemap.xml` | served, correct content type |
| Upload a document (once Phase 4 ships) | lands in `storage/private`, not web-reachable |

## 12. Backups

- Set up hPanel automatic backups **and** enable the `backup` cron job (04:00 UTC — a verified PHP dump, no `mysqldump` needed) writing to
  `storage/private/backups/` with an **off-site copy** (see `docs/BACKUP-RESTORE.md`).
- Test a restore into a staging database quarterly.

## 13. Dry-run before go-live

Do this once on the real host with the real `.env`, in order. Stop at the first FAIL.

1. **Preflight on the server** — `php scripts/preflight.php` checks PHP version/extensions/limits, production settings
   (`APP_ENV`, `APP_DEBUG`, `APP_KEY`, https `APP_URL`, secure cookies, HSTS, cron secret, real mail), the database
   (connects, utf8mb4, every migration applied and unchanged, reference data seeded), the cron ledger, the accounts
   (an active admin, 2FA enrolled, no dev/test accounts) and the filesystem (writable storage, `.htaccess` guards,
   built assets, no dev packages, optimised autoloader). Exit 0 = nothing failed; add `--strict` to make warnings fail too.
2. **Preflight from outside** — from your own machine: `php scripts/preflight.php --url=https://your-domain` also proves
   the internet cannot reach `.env`, `.git`, `vendor`, `config`, `app`, `database`, `docs`, `scripts`, `storage`, that the
   session cookie is `Secure; HttpOnly`, the CSP is present, `X-Powered-By` is gone, and http redirects to https.
3. **Backup + restore drill** — `php scripts/backup.php`, then create an empty scratch database in hPanel and
   `php scripts/restore-drill.php --into=<scratch db>` (must say `DRILL PASSED`). Copy the backup file off the server.
4. **Cron** — add the line in §10, wait 10 minutes, open **/admin/cron**: every job `ok` (or `never` only for jobs not yet
   due), `cron-health` and `dashboard-cache` have run.
5. **Smoke test** — the table in §11, plus: sign in as an admin, enrol 2FA, create a lead → convert → candidate → upload a
   passport PDF and download it → invoice → record a payment → open the dashboard and a report → sign out.
6. **Clean up** — delete any test data and test accounts, run `php scripts/preflight.php` once more, then announce.

A failed check is never "probably fine": each one is there because skipping it caused (or would cause) an outage or a leak.
