# Phase 1 · Step 1.2 — Database layer + migrations + seeders

**Status:** implemented; 36 unit tests green; verified end-to-end on MariaDB 10.4.

## A. Files created

| File | Purpose |
|---|---|
| `app/Support/Db.php` | PDO wrapper — the only path to the database. `select/selectOne/selectValue/cursor/exists`, `insert/affectingStatement/statement/unprepared`, `insertRow/updateRow`, `transaction()` (savepoint nesting + deadlock retry), identifier guard, value normalisation (bool→int, `DateTimeInterface`→UTC string, float→string) |
| `app/Support/SqlScript.php` | Comment/quote/backtick-aware SQL statement splitter (used by the migrator; unit-tested against the real baseline) |
| `app/Support/Seeder.php` | Base seeder with idempotent `upsert()` helper |
| `app/Exceptions/QueryException.php` | Wraps `PDOException`; keeps SQL+bindings for logs only; `isDuplicateKey()`, `isDeadlock()` |
| `scripts/migrate.php` | Migration runner: `--status` (with checksum drift), `--pretend`, `--step=N`, `--fresh`, `--force`; production guard; per-statement failure reporting |
| `scripts/seed.php` | Seed runner: all or `--class=`, `--force`, transactional, production-safe |
| `database/migrations/0001_initial_schema.sql` | Baseline schema (generated from `schema.sql`), MySQL 8 / MariaDB 10.4 portable |
| `database/seeders/DatabaseSeeder.php` | Master seeder (ordered list) |
| `database/seeders/CountriesSeeder.php` | ISO-3166 reference data, `is_gcc` flag, idempotent |
| `phpunit.xml.dist`, `tests/bootstrap.php` | Test harness (sqlite in-memory for DB tests) |
| `tests/Unit/Support/*` | `EnvTest, ConfigTest, ContainerTest, ApplicationTest, LoggerTest, SqlScriptTest, DbTest` |
| `.gitattributes` | Force LF |

## B. Files modified

- `bootstrap/autoload.php` — added `Database\Seeders\` → `database/seeders/` mapping.
- `bootstrap/app.php` — bound `Db` singleton (builds connection from `config('database')`).
- `database/schema/schema.sql`, `config/database.php`, `docs/00/01` — collation `utf8mb4_0900_ai_ci` → **`utf8mb4_unicode_ci`** (portable across MySQL 8 **and** MariaDB, which is what Hostinger shared hosting actually runs).

## C. Database migration

`0001_initial_schema.sql` — the full Phase 0 schema as the baseline. Future
changes are new numbered files; `schema.sql` stays as the readable canonical copy.
Runner tracks `schema_migrations(version, filename, checksum, batch, applied_at)`.

## D–E. Backend / Frontend

Backend only this step. No UI.

## F. Security

- **Prepared statements only.** `Db` has no method that interpolates a value; callers pass placeholders + bindings. Identifiers (table/column) pass a strict `^[A-Za-z_][A-Za-z0-9_.]*$` guard — test `test_identifier_guard_rejects_injection` proves `widgets\`;DROP TABLE…` is rejected.
- `sql_mode` forced to `STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO` on connect.
- Session time zone pinned to UTC on connect.
- `QueryException` never exposes SQL to output — only to the logger (which itself redacts secret keys).
- Migration runner refuses to run in `APP_ENV=production` without `--force` (forces a "do you have a backup" checkpoint); `--fresh` doubly guarded.
- Cron/script files refuse non-CLI SAPI.

## G / H. Tests + Manual QA — run now

- [x] `phpunit` → **36 tests, 139 assertions, OK**
- [x] `php -l` on all non-vendor PHP → clean
- [x] `migrate.php --status` (empty DB) → `0 applied, 1 pending`
- [x] `migrate.php` on MariaDB 10.4 → **70 statements applied**, 0 errors
- [x] Post-migration: **68 app tables, 103 foreign keys, 16 CHECK constraints**, every table `utf8mb4_unicode_ci`
- [x] `migrate.php` again → `Nothing to migrate` (idempotent)
- [x] `migrate.php --pretend` (new file) → prints SQL, does not execute
- [x] Tamper an applied file → `--status` shows `!! FILE CHANGED SINCE APPLIED`
- [x] `APP_ENV=production migrate.php` → refused without `--force`
- [x] `seed.php` → 30 countries (6 GCC); re-run → still 30 (idempotent)
- [ ] On Hostinger: import via phpMyAdmin **or** run `php scripts/migrate.php` over SSH; confirm same table count

## I. Performance

- `Db` connects lazily (no connection during bootstrap or for static routes).
- Persistent connections **off** (shared-hosting connection-limit safety).
- `cursor()` yields rows one at a time for large exports — constant memory.
- `transaction()` retries only on deadlock/lock-timeout, with jittered backoff.

## J. Deployment

- App still runs without `vendor/`. PHPUnit is dev-only; installed locally with
  `--ignore-platform-req=ext-gd` (local CLI PHP lacks gd; Hostinger has it).
- Deploy DB: either import `database/schema/schema.sql` in phpMyAdmin, then
  `php scripts/migrate.php` will record `0001` as already-applied only if the
  `schema_migrations` row is inserted — **preferred path is `php scripts/migrate.php`
  over SSH** so tracking is correct from the start. (A `--baseline` flag to mark
  0001 applied without running it will be added if a host truly has no SSH.)
- `crm_dev` / `crm_dev`@`localhost` created on local MariaDB for integration tests.

## Follow-ups noted for later steps

- `scripts/migrate.php --baseline` (mark a migration applied without executing) — for SSH-less hosts that import via phpMyAdmin. *(Step 1.9 or 12.)*
- Move `ext-gd` from hard `require` to a runtime capability check + `suggest` so
  local dev without gd is frictionless. *(Step 4, when image processing lands.)*
- Split future schema changes as discrete numbered migrations (never edit 0001).
