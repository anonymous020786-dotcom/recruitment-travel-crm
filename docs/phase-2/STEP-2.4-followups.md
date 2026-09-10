# Step 2.4 — Lead follow-ups + dashboard queue

**Status:** implemented; 351 tests green (+10). Core Phase 2 (Leads) resumes here
after the auth-enhancement detour.

## A. Files created

| File | Purpose |
|---|---|
| `database/migrations/0005_lead_followup_subject.sql` | `lead_followups.subject VARCHAR(200) NULL` — a short "what is this about". The table itself ships in 0001. |
| `app/Models/Followup.php` | Read model: `isPending()` / `isOverdue()` / `isDueToday()` / `dueLabel()` / `channelLabel()`; carries joined lead name/number/public_id/phone for list rendering. |
| `app/Repositories/LeadFollowupRepository.php` | `create`, `findInScope` (branch-scoped on `f.branch_id`), `forLead`, `pendingForUser($bucket=overdue\|today\|upcoming)`, `countsForUser`, `markCompleted` / `markCancelled` (guarded to `pending`), `hasOpenForLead`, `dueForReminder` (system-wide, cron feed). |
| `app/Controllers/Crm/FollowupController.php` | `/followups` personal queue + `complete` / `cancel` actions (keyed by follow-up id; the service re-checks scope + permission). |
| `app/Controllers/Crm/DashboardController.php` | Replaces the closure route — scope-aware follow-up counts + the overdue/today list + open-lead count. |
| `resources/views/crm/followups/index.php` | Overdue / due-today / next-7-days sections with an inline complete form (+ optional "log as note") and cancel. |
| `resources/views/mail/followup-due.php` | Daily digest email body. |
| `cron/followups.php` | Fills the reserved `followups` job slot: a deduped in-app reminder per due/overdue follow-up on every run; one digest email per assignee once a day at `cron.followup_digest_hour` (UTC). |
| `tests/Feature/LeadFollowupServiceTest.php` | schedule (defaults, past-date / bad-time / bad-channel / permission), complete (+note +next, empty outcome, double-close), cancel, cross-branch denial, overdue/today/upcoming counts. |

## B. Files modified

- `app/Services/LeadService.php` — `scheduleFollowup` / `completeFollowup` / `cancelFollowup`. All transactional + audited (`followup_scheduled` / `_completed` / `_cancelled`). Completing can log the outcome as a lead note and schedule the next follow-up in one call. Assignee defaults to the lead owner, then the actor; validated against the branch with the existing `assertAssigneeValid`. Past due-dates rejected.
- `app/Controllers/Crm/LeadController.php` — `scheduleFollowup`; `show()` now lists `Followup` models and passes `canFollowup`.
- `resources/views/crm/leads/show.php` — Follow-ups card: schedule form + per-item complete / cancel, overdue highlighting, `#followups` anchor.
- `resources/views/crm/dashboard.php` — real widget (was placeholder).
- `app/Notifications/NotificationService.php` — `followupReminder()` scalar variant (cron works from raw rows); `leadFollowupDue()` delegates to it. Dedupe key `lead_followup:{leadId}:{dueDate}`.
- `routes/web.php` — `GET /followups`, `POST /followups/{id}/complete|cancel`, `POST /leads/{lead}/followups`; `/dashboard` → `DashboardController`.
- `config/navigation.php` — "Follow-ups" item (perm `followups.view`); `config/permissions.php` already carried `followups.*` + the role matrix.
- `resources/views/components/icon.php` — `followups` (clock) glyph.
- `config/cron.php` — `followup_digest_hour`; `config/mail.php` — `followup_reminders` toggle (`MAIL_FOLLOWUP_REMINDERS`).
- `database/schema/schema.sql` — canonical DDL gains `subject`.

## C. Migration

`0005_lead_followup_subject.sql` — applied to `crm_dev`. Reversible (drop column).

## F. Security / correctness

- Every follow-up action re-resolves the actor's `BranchScope` and loads the
  follow-up **through it** — a user in another branch gets "not found", never a
  permission error that leaks existence.
- Permissions: `followups.create` to schedule, `followups.complete` to close,
  `followups.edit` to cancel — each **and** `leads.view` on the parent lead.
- `markCompleted` / `markCancelled` only touch a `pending` row, so a double
  submit is a no-op that surfaces as "already closed".
- Converted leads reject new follow-ups.
- The cron is system-scoped by design (it must see every branch); it only reads
  + notifies + queues mail, never mutates follow-ups.

## H. Manual QA — verified

- [x] Login → `/dashboard` renders the three follow-up stat tiles + the "needing action" card
- [x] `/followups` → 200
- [x] Create lead → schedule a WhatsApp follow-up → lead page shows it "Pending" under `#followups`
- [x] `php cron/followups.php <secret>` → exit 0, `cron_runs` success, 1 in-app notification created (deduped on re-run)
- [x] Full suite **351 tests, 805 assertions**

## I. Performance

- List/detail reads are single queries; `countsForUser` is one row of
  conditional `SUM`s. `lead_followups` is indexed `(assigned_to, status,
  due_date)` and `(branch_id, due_date)`.
- The cron caps the reminder feed at 2000 rows and the per-user email list at 25.
