# Overseas Recruitment + Job Placement + Travel & Tour CRM

Production-grade operational CRM. Stack: **PHP 8.2+, MySQL 8+, PDO, Tailwind,
vanilla JS**, Apache/`.htaccess`, PHP sessions, cron. No framework, no SPA, no
Redis/Node/Docker. Hostinger shared-hosting compatible, migration-ready for VPS.

## Status: Phase 0 — Architecture (no application code yet)

Review these in priority order, then instruct whether to begin **Phase 1**:

| Doc | Covers |
|---|---|
| [`database/schema/schema.sql`](database/schema/schema.sql) | Canonical MySQL DDL — every table, type, index, FK, constraint |
| [`docs/01-DATABASE.md`](docs/01-DATABASE.md) | ERD relationship narrative, `ON DELETE` policy, index strategy, per-table rules, non-FK integrity checks |
| [`docs/02-RBAC.md`](docs/02-RBAC.md) | 10 roles, permission catalogue, full role→permission matrix, field/row visibility, policy classes |
| [`docs/00-ARCHITECTURE.md`](docs/00-ARCHITECTURE.md) | System architecture, module dependency map, middleware pipeline, Controller→Service→Repository contracts, **status-transition engine**, match engine, security threat model (T1–T20), file-upload design, Hostinger deployment, cron design, backup/recovery, performance strategy, SEO architecture, responsive UI, 12 development phases, risks & assumptions |
| [`docs/03-ROUTES.md`](docs/03-ROUTES.md) | Full route map: public + CRM + API, method, permission, middleware, validators, rate-limit buckets, error routes |

The brief said to check four things first: **database schema + relationships +
RBAC + status-transition model**. Those are, respectively, `schema.sql` +
`01-DATABASE.md` §1, `02-RBAC.md`, and `00-ARCHITECTURE.md` §9.3.

## Awaiting instruction

No `app/`, `public/`, `routes/`, or migration files have been written yet. On
approval, Phase 1 (Foundation) begins and each phase runs the cycle
**implement → test → security audit → performance audit → code review**.
