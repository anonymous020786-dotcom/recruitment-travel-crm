# Step 12.5 — Backup and restore drill

The design (docs §14) named `scripts/backup.php` and `docs/BACKUP-RESTORE.md`, but neither existed — there was **no
backup at all**. This step builds them, proves they restore, and fixes what the proof found. Runbook:
[`docs/BACKUP-RESTORE.md`](../BACKUP-RESTORE.md).

## Built

- `App\Support\DbBackup` — pure-PHP logical dump (works without `mysqldump`/`exec`): one consistent snapshot, streamed over an
  unbuffered connection (flat memory), one statement per line, binary columns as hex, transient tables structure-only,
  footer with table/row totals; `verify()` proves a file is whole (gzip intact, footer = content read).
- `App\Support\DbRestore` — replays a dump; verifies first; **refuses the live database** unless explicitly told.
- `App\Support\BackupManager` — daily run: verified DB dump (via `.partial` then rename), documents archive (weekly full +
  daily incrementals, each read back and SHA-256-compared with its source, 2 GB guard), retention (DB: 7 days + 4 Sundays +
  3 month-starts; documents: chain-aware — the 4 newest fulls plus every incremental newer than the 2nd-newest full).
- `cron/backup.php` (job `backup`, 04:00 UTC — appears on `/admin/cron`, failures alert admins), `scripts/backup.php`,
  `scripts/restore.php`, `scripts/restore-drill.php` (fresh dump → verify → restore into a scratch DB → row counts **and content
  checksums** of every table vs live → drop).

## What the drill caught (both would have produced backups that look fine and restore wrong)

1. **`Db` bound floats with `sprintf('%.8F')`**: everything past 8 decimals was silently cut (`-5.5E-10` stored as `-0`,
   `0.1+0.2` as `0.3`). A latent application bug, invisible to row counts. Now bound with the exact round-trip text
   (`var_export`); NaN/INF are refused. Regression tests in `DbTest` and `BackupTest`.
2. **`PharData::compress(Phar::GZ)` on PHP 8.2/Windows wrote a valid `.tar.gz` containing file names but no contents**
   (122 bytes for two files). The plain tar was fine. The archive is now gzipped with our own stream and every entry is read back
   and hash-compared before the file is kept.

## Drill results (dev, real runs)

| Database | Rows | Dump | Restore | Row counts | Content checksums |
|---|---|---|---|---|---|
| `crm_dev` | 1 087 | 0.2 s | 4 s | 76/76 equal | 0 differ |
| `crm_explain` (150k leads etc.) | 716 381 (11.4 MB) | 4.7 s | 48 s | 76/76 equal | 0 differ |

`scripts/backup.php` and `cron/backup.php` ran for real (exit 0, `cron_runs` row written); a dump taken by the script
was restored by the drill via `--file`. Backup files and rows from these runs were removed afterwards.

## Tests — `tests/Feature/BackupTest.php` (11) + `DbTest` (2)

Hostile values round-trip exactly (quotes, backslashes, newlines that look like SQL, emoji, NULL, VARBINARY with NUL, JSON,
DECIMAL, tiny/huge DOUBLE, big unsigned) with equal rows *and* equal `CHECKSUM TABLE`; transient tables keep structure but
no rows; totals reported; a truncated, corrupt, footer-lying, row-dropped or tiny dump is rejected; restore refuses the live
database and a foreign file before touching anything; a run writes a private verified dump with no `.partial` left over;
documents: full → nothing changed → incremental → new full a week later, with file contents verified inside the archive; the
size guard fails loudly; DB retention (7 days / 4 Sundays / 3 month-starts, newest of a day only); document retention never
breaks a chain; prune deletes exactly what policy allows and ignores foreign files. `DbTestCase` now exposes `$dbConfig`.

Full suite: **908 tests, 2 972 assertions** (3 skipped without GD).

## Still manual (cannot be automated from here)

The off-site copy (documented options) and the quarterly drill on the real host: create a scratch database in hPanel and run
`php scripts/restore-drill.php --into=<db>` after the first deployment.
