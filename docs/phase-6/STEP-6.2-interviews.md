# Phase 6 · Step 6.2 — Interviews

Interview rounds hang off an application. Every interview action also drives the application through `ApplicationService::advance()` **in one transaction**, so the two can never disagree.

## Lifecycle
| Action | Needs | Interview row | Application |
|---|---|---|---|
| Schedule | `interviews.create`; app shortlisted / no_show / interview_completed; no open interview | new row, `scheduled` | → `interview_scheduled` |
| Confirm | `interviews.edit` | `scheduled` → `confirmed` | – |
| Reschedule (reason required) | `interviews.edit`; open interview | old row → `rescheduled` (reason appended to notes), new row same round | `interview_scheduled` → `rescheduled` → `interview_scheduled` |
| Outcome *selected* | `interviews.record_outcome` | `selected` / result `selected` | → `interview_completed` → `selected` |
| Outcome *rejected* (feedback required) | same | `rejected` | → `interview_completed` → `rejected` |
| Outcome *hold* | same | `completed` / result `hold` | → `interview_completed` (next round can be scheduled) |
| Outcome *no_show* | same | `no_show` | → `no_show` (re-schedule keeps the same round) |

Rules: one open interview per application; round auto-increments (a no-show or reschedule repeats the round); a date in the past is refused when scheduling; an outcome can't be recorded before the interview date, or twice (guarded `UPDATE … WHERE status IN ('scheduled','confirmed')`). If the application was moved (e.g. cancelled) underneath, the outcome fails and the interview row rolls back.

Validation (`InterviewValidator`): type enum; 24-hour time; http(s) meeting link; video needs a link; in-person / client visit needs a location.

Branch scope comes from the owning application. `interviews.delete` is unused for now — a wrongly booked interview is fixed by rescheduling.

## UI & background
- `/interviews` board: search, when (today/upcoming/past), status, type; overdue open interviews are red.
- "Interviews" card on the application page: schedule form, confirm, record outcome, reschedule.
- Owner (`applications.assigned_to`) is notified on schedule / reschedule / outcome when someone else acted.
- `cron/interview-reminders.php` (hourly): today + tomorrow reminders, deduped per interview + bucket, only for applications still `interview_scheduled`.

## Verification
`InterviewServiceTest` (15 tests) — full suite 600 tests / 1371 assertions green. HTTP smoke: refused video-without-link, schedule, confirm, reschedule (with/without reason), reject-without-feedback refused, selected; DB rows and 7-row history checked; data cleaned up.
