# Step 11.3 — Scheduled-job health: admin screen, failure alerts, collection tasks

Completes Phase 11. The jobs from 11.1/11.2 now report on themselves, and overdue invoices produce work items.

## Cron health

`CronHealthService` reads the `cron_runs` ledger against the registry in `config/cron.php` and classifies each job
(first match wins):

| State | Meaning | Healthy |
|---|---|---|
| `disabled` | `enabled => false` in the registry | yes |
| `stuck` | latest run still `running` after the job's lock TTL — the process died | **no** |
| `failing` | latest run `failed` (a later success clears it) | **no** |
| `late` | the schedule fired more than 15 minutes (`GRACE_MINUTES`) ago and the job has not started since — includes a job that never ran | **no** |
| `running` | latest run in progress, inside its TTL | yes |
| `never` | no run yet, first slot still inside the grace period | yes |
| `ok` | otherwise | yes |

Per job it also gives the last run (start, finish, status, items, message), last success, next due time
(`CronSchedule::nextDueAt`, new) and failures in the last 24 hours. `history($job)` returns the latest 50 runs.

### Admin screen — `/admin/cron`, `/admin/cron/{job}`

Guarded by `system.console` (super_admin and admin; managers get 403, anonymous users go to login). The index lists
every job with a state badge, schedule, last run/result, last success, next due and 24 h failures, plus the last
stored data-integrity result (11.2) with its findings. Each job links to its run history. Error messages are
escaped. Read-only: there is deliberately no "run now" button on the web.

### Failure alerts — `cron/cron-health.php` (every 15 minutes)

Notifies every active super admin (`cron_alert`) about each unhealthy job, **once per job per state per day**
(`dedupe_key = cron:<job>:<state>:<date>:u<id>`); a job still failing the next day is reported again. It can only
notice what runs: if the cron line itself is dead this job is late too, and the screen is where that shows.

## Collection tasks from payment reminders

`PaymentReminderService::remindOverdue()` (daily `payment-reminders` job) now also opens one **"Collect payment"**
task per overdue invoice — related to the invoice, due today, `source = system`, assigned to the branch's first
accounts user (else the user who raised the invoice). Priority is `medium`, `high` from 7 days overdue, `urgent`
from 30. While a system task for the invoice is pending no second one is created; once it is completed, a new
one opens only after another 28-day span (`dedupe_key = invoice:<id>:overdue-task:<span>`), so re-running the job
is idempotent. New `TaskRepository::createOnce()` and `hasPendingSystemTask()`.

## Also changed

- `CronSchedule::lastDueAt` now converts its argument to UTC before reading the hour/minute (it read them in the
  caller's zone; harmless while every caller passed UTC).
- DI: `CronHealthService` factory (registry from `cron.jobs`); `PaymentReminderService` takes `TaskRepository`.
- Sidebar: "Scheduled jobs" for `system.console`.

## Tests

- `CronHealthTest` (7): every state incl. disabled/never/late/recovered, the grace boundary (14 vs 15 minutes),
  dates/counts/next-due, history order and limit, alert once per job/state/day and only to super admins, silence
  when healthy, and a registry check that every configured job has a script, a valid schedule and a TTL.
- `CronScheduleTest`: `nextDueAt` (strictly after, weekly, lookahead limit, non-UTC input).
- `RefundServiceTest`: one task per overdue invoice for accounts with the right priority/due date/branch and
  idempotence; creator fallback; reopening a month later only after completion.
- Full suite: **844 tests, 2732 assertions**.

## Verification

HTTP smoke against `php -S 127.0.0.1:8099` with a real super admin and manager (rows removed afterwards): the
index lists all registered jobs, shows the failing and stuck states seeded for the test, escapes the error
message, links each job, shows the integrity section and the sidebar link; the job page shows its history; an
unknown job 404s; a manager gets 403 on both pages and no sidebar link; anonymous is redirected to login.
`php cron/cron-health.php` run for real exits 0 and, correctly, flagged the dev database's never-scheduled
`daily-report` as late (the alert rows were removed afterwards).
