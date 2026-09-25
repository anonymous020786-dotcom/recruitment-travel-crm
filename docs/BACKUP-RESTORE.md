# Backup and restore

Everything here works on shared hosting: no `mysqldump`, no `exec`, no root. It needs only PHP with `zlib` (and
`phar` for the documents archive) and a database user.

## What is backed up

| Asset | How | When | Kept |
|---|---|---|---|
| **Database** | `cron/backup.php` → `storage/private/backups/db-YYYYmmdd-HHMMSS.sql.gz`, a consistent-snapshot dump (`START TRANSACTION WITH CONSISTENT SNAPSHOT`), verified before it is kept (whole gzip, footer totals = content read) | daily 04:00 UTC (dispatcher/cron job `backup`) | newest 7 days + 4 Sundays + 3 first-of-month |
| **Uploaded documents** | `files-YYYYmmdd-HHMMSS-full.tar.gz` (everything, at least weekly) and `…-inc.tar.gz` (only files changed since the previous archive), each read back and compared file-by-file (SHA-256) before it is kept | same run | the 4 newest fulls + every incremental newer than the 2nd-newest full (chains are never broken) |
| **Configuration** | `.env` — copy it to your password manager after any change; it is deliberately *not* in a backup file or git | on change | — |
| **Code** | git | every deploy | — |

Transient tables (`sessions`, `rate_limits`, `cron_locks`) are dumped without rows.

A failed backup is a failed cron job: it appears red on **/admin/cron** and the `cron-health` job notifies the
super admins. If uploads outgrow the PHP archiver (`2 GB` per archive) the job fails loudly — switch uploads to the
host's file backup or `rsync`; the database dump keeps working.

## Off-site copy (required)

A backup that only lives in the account it protects is not a backup. Pick one and do it **daily**, after 04:30 UTC:

- hPanel › Files › download `storage/private/backups/*` (manual, weekly at minimum), or
- `rsync`/`scp` from another machine that pulls `storage/private/backups/` over SSH (`--ignore-existing`), or
- an hPanel cron line that uploads to your object storage (rclone/`curl`) if the plan allows it.

Also keep hPanel's own weekly backup switched on as the secondary.

## Take a backup by hand

```
php scripts/backup.php            # database + documents
php scripts/backup.php --db-only
php scripts/backup.php --full     # force a full documents archive
```

## Restore procedure (real disaster)

1. **Maintenance mode**: `php scripts/down.php`.
2. **Database**: create an empty database (or reuse the old one) and restore:
   ```
   php scripts/restore.php --file=storage/private/backups/db-YYYYmmdd-HHMMSS.sql.gz --into=<database> [--overwrite-live]
   ```
   `--overwrite-live` is required when `<database>` is the one in `.env` — it replaces every table. The file is verified
   first; a truncated or corrupt dump is refused before anything is touched. (Plain `gunzip < file | mysql <db>` also
   works: every statement is a single line.)
3. **Documents**: restore the newest `…-full.tar.gz`, then each newer `…-inc.tar.gz` in date order, into
   `storage/private/documents` (`tar -xzf`, or PHP `new PharData(...)->extractTo(...)`). Later archives overwrite earlier.
4. **`.env`** from the password manager (must be the same `APP_KEY`, or 2FA recovery codes and signed links stop working).
5. `php scripts/migrate.php --status` — the schema version must match the code; run `php scripts/migrate.php` if code is newer.
6. **Smoke test**: `/health`, sign in, open a candidate, download a document, open `/reports/collections`, `/admin/cron`.
7. `php scripts/up.php`.

Recovery objectives: RPO ≤ 24 h (run `scripts/backup.php --db-only` from an hourly cron line if finance needs ≤ 1 h),
RTO ≤ 2 h.

## Restore drill (quarterly, and after any change to the backup code)

```
php scripts/restore-drill.php [--file=<db-….sql.gz>] [--into=<scratch database>] [--keep]
```

Takes a fresh dump (or uses `--file`), verifies it, restores it into a scratch database, and compares the copy with the
live database: **row counts of every table and a content checksum of every table**, plus the migration ledger. It never
touches the live database and drops the scratch one afterwards. On shared hosting create an empty scratch database in
hPanel first and pass it with `--into` (the app user usually cannot `CREATE DATABASE`). Exit 0 = the backup restores
completely. Do it while the site is quiet: writes during the drill make the live checksums move.

Last drill (2026-09-24, dev): 79 tables / 1 087 rows restored in 4 s, 0 differences; 716 381 rows (11.4 MB dump)
dumped in 4.7 s, restored in 48 s, 0 differences.

## Why the drill exists — what it already caught

Writing it found two real defects that no other test could see: (1) the `Db` layer bound floats with `sprintf('%.8F')`,
silently turning `-5.5E-10` into `-0` (fixed at the source, now round-trip exact); (2) `PharData::compress()` on this
PHP build produced a "valid" `.tar.gz` that held the file names but none of the contents (replaced by our own gzip
stream plus a byte-for-byte read-back). Both would have produced backups that looked fine and restored wrong.

## Off-site copy to S3 / Cloudflare R2 (automatic)

When a bucket is configured and chosen under **Admin → Integrations → Document storage**, `cron/backup.php` also uploads each night's
database dump and documents archive to the bucket under `backups/`, verifies each copy (size read back with a HEAD) and deletes remote
backups older than *Keep off-site backups for (days)* (default 30). A failed copy is logged and never fails the local backup.
Apply the bucket lifecycle rules from **Admin → Storage → Apply cost-saving rules** as well so the bucket enforces the same retention.

Documents that live in the bucket are **not** in the local documents archive (they are not on the server). Protect them with the
provider's own features: enable bucket **versioning** (S3) / object versioning + Bucket Locks (R2) if you need point-in-time recovery.
