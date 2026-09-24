# Phase 11 · Step 11.1 — The single-entry cron dispatcher

Phase 11 (Automation) is split: **11.1 dispatcher** (this step), 11.2 the three registered-but-missing jobs (`daily-report`, `dashboard-cache`, `integrity-check`), 11.3 automation visibility — an admin screen for job health, failure alerts, and follow-up tasks from payment reminders. **No migration.**

## Why
Some shared-hosting plans give one cron slot. `cron/dispatch.php` is that one line (`*` every 5 minutes, `php cron/dispatch.php <CRON_SECRET>`): it reads the job registry in `config/cron.php`, works out which jobs are due, and runs them one after another inside a time budget. Dedicated cron lines per job keep working exactly as before, and the two can coexist.

## How a job is judged due
`CronSchedule` parses a standard 5-field expression (UTC): `*`, lists, ranges, steps (`*/15`, `10-30/10`), Sunday as 0 or 7, and classic cron's rule that when both day-of-month and day-of-week are restricted a day matches if **either** does. Bad expressions (wrong field count, out of range, reversed range, zero step, names, macros) are rejected. `lastDueAt(now)` returns the latest scheduled minute at or before now (8-day lookback — every job we run fires at least weekly).

`CronDispatcher::due()` — a job is due when its schedule has fired **since the job last started** (any outcome, from `cron_runs`). That gives the behaviour you want from a 5-minute tick:
- a **missed slot is caught up** on the next tick;
- a slow or repeated tick **never runs a job twice for one slot**;
- a **failed** job is retried at its **next scheduled time**, not every five minutes (a permanently broken job does not hammer the system; alerting arrives in 11.3);
- a job with no history is due immediately (so a fresh deploy runs everything once);
- disabled jobs (`enabled => false`) and jobs with a missing or invalid schedule are skipped and logged.

`run()` executes due jobs in registry order and stops **starting** new ones after `cron.dispatch_budget_seconds` (default 240, env `CRON_DISPATCH_BUDGET`); the rest are reported `deferred` and picked up next tick. A job that throws is recorded `failed` and does not stop the ones after it. Outcomes: `ran`, `locked` (another process holds that job's lock), `missing` (script not written yet — reported honestly, not as a run), `failed`, `deferred`. The dispatcher exits 1 if any job failed.

## Running in-process
Each job takes its own lock and writes its own `cron_runs` row (`CronRunner`), exactly as when run alone. To let the dispatcher run them without spawning processes (which shared hosts often forbid), every `cron/*.php` now ends with `return CronRunner::finish(...)` instead of `exit(...)`: alone, it exits with the job's code as before; under the dispatcher (`CRON_IN_PROCESS`) it returns the code. `cron/_bootstrap.php` reuses the dispatcher's already-booted container and skips re-checking the secret.

**Bug fixed on the way:** `cron_runs.started_at` was written in the server's local time while `finished_at` used UTC. Both are UTC now — the dispatcher compares them to schedule times, so a mixed clock would have made jobs due at the wrong moments.

## Verification
- **46 new tests**: `CronScheduleTest` (22 matching cases including step/range/list/Sunday-as-7/the day-of-month-OR-day-of-week rule/time-zone normalisation; `lastDueAt` for daily, quarter-hourly and weekly schedules, the lookback limit; 14 rejected expressions; and the **real registry** — every schedule in `config/cron.php` parses and fires) and `CronDispatcherTest` (due vs already-run vs missed slot; registry-order execution and a second tick doing nothing while a 5-minute job comes due again; failed run retried at the next slot only; every outcome, with a crashing job not stopping the rest; the time budget deferring the remainder; disabled / invalid / schedule-less jobs skipped; the real registry dispatchable).
- Full suite: **817 tests, all green.**
- **Real run against the dev database**: `php cron/dispatch.php <secret>` ran all ten existing jobs in-process (`followups`, the four expiry jobs, interview and payment reminders, email queue, exports, cleanup), each writing its `cron_runs` row; the three not-yet-built jobs were reported `missing`; a second tick immediately after ran nothing but reported those same three; exit code 0.

## Not in this step
The three missing job scripts (11.2), job-health screen and failure alerts (11.3), and running jobs from the web (deliberately not offered: cron stays CLI-only).
