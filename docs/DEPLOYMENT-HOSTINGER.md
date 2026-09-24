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

Add each job (adjust paths). If the plan allows only one entry, use the single
dispatcher.

```
*/15 * * * *   php ~/crm/cron/followups.php $CRON_SECRET
0 2 * * *      php ~/crm/cron/document-expiry.php $CRON_SECRET
10 2 * * *     php ~/crm/cron/passport-expiry.php $CRON_SECRET
20 2 * * *     php ~/crm/cron/visa-expiry.php $CRON_SECRET
0 * * * *      php ~/crm/cron/interview-reminders.php $CRON_SECRET
0 9 * * *      php ~/crm/cron/payment-reminders.php $CRON_SECRET
*/5 * * * *    php ~/crm/cron/process-email-queue.php $CRON_SECRET
*/5 * * * *    php ~/crm/cron/process-exports.php $CRON_SECRET
*/10 * * * *   php ~/crm/cron/dashboard-cache.php $CRON_SECRET
0 3 * * *      php ~/crm/cron/cleanup.php $CRON_SECRET
```
*(Cron scripts are delivered in Phase 1.11 — this is the target schedule.)*

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
