# Step 2.8 — WhatsApp / call communication logging

**Status:** implemented; 384 tests green (+8). Verified end to end over real
HTTP against a running MariaDB instance.

Logs a contact touchpoint (call / WhatsApp / SMS / email / meeting / other)
against a lead, merged into the same Timeline the notes and audit events
already populate — one chronological history, not three disconnected lists.
`communication_logs` is polymorphic (`related_type`/`related_id`) and shipped
in the original schema for exactly this; Phase 2 is the first writer
(`related_type = 'lead'`), and the `communication.view`/`communication.log`
permissions were already seeded into every role's matrix. No telephony/VOIP
integration — the `tel:`/`wa.me` buttons already on the lead page open the
native dialer/WhatsApp; this is the manual "log what happened after" step,
consistent with the phase list keeping real click-to-call integration out of
scope for now.

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/CommunicationLog.php` | Read model — `channelLabel()` / `directionLabel()` for display. |
| `app/Repositories/CommunicationLogRepository.php` | `create`, `findById`, `forRecord` (newest first, joined to the logging user's name), `countForRecord`. No update/delete — like `activity_logs`, a logged contact is a record of what happened. |
| `tests/Feature/LeadCommunicationServiceTest.php` | Logs + audits, direction defaults to outbound, channel/direction/summary validation, backdating (past OK, future rejected), missing-permission denial, cross-branch denial, ordering + user-name join. |
| `docs/phase-2/STEP-2.8-communication-log.md` | This file. |

## B. Files modified

- `app/Services/LeadService.php` — `logCommunication()`: requires `view` on the lead (branch-scoped) **and** the raw `communication.log` permission; validates channel/direction/summary; an explicit `occurred_at` (backdating a call logged late) is parsed and rejected only if it's in the future — otherwise the column's own `DEFAULT CURRENT_TIMESTAMP` (UTC session) is left to fire. One audit entry (`communication_logged`) per call, inside the same transaction as the insert.
- `app/Controllers/Crm/LeadController.php` — `logCommunication()` action; `show()` now loads communication logs and folds them into `buildTimeline()` (now a 3-source merge: notes, activity events, communications) via a `'communication'` timeline item type; `communication_logged` is excluded from the raw event feed the same way `note_added` already was, so it isn't shown twice.
- `resources/views/crm/leads/show.php` — a compact "Log a call/message" form (channel + direction + one-line summary) inline in the Timeline card, gated on `canLogCommunication`; a 📞 icon for communication timeline entries.
- `routes/web.php` — `POST /leads/{lead}/communications`, gated on `can:communication.log`.

## C. Migration

None — `communication_logs` ships in `0001_initial_schema.sql`; the permission
catalogue and role matrix already carried `communication.view`/`.log`.

## F. Security / correctness

- Authorization is two-part and both are required: `LeadPolicy::view()` (branch
  scope, so a user outside the lead's branch is denied regardless of their
  `communication.log` permission) **and** the raw `communication.log`
  permission (so a viewer without logging rights, e.g. `read_only`, is denied
  even though they can see the lead).
- `occurred_at` accepts a backdated time (staff catching up on unlogged calls)
  but never a future one — a communication can't be logged as "happening"
  later than now.
- Every summary is capped at 500 chars (matching the column) before insert;
  channel/direction are checked against the DB `ENUM` values before the query
  ever runs, so a bad value fails as a clean `ValidationException`, not a MySQL
  data-truncation error.
- No update/delete surface at all — a logged touchpoint, like an audit entry,
  is a record of what happened, not something to revise afterward.

## H. Manual QA — verified (over HTTP)

- [x] Lead page shows the "Log a call/message" form (channel/direction/summary)
- [x] Submitting it redirects to `#timeline`; the entry appears as "Outbound whatsapp: …" with a 📞 icon, correctly interleaved by time with notes and status-change events
- [x] `communication_logs` row and a single `communication_logged` audit entry both land in the DB with the right `related_id`
- [x] Full suite **384 tests, 900 assertions** green

## I. Performance

- `forRecord()` is one indexed query (`idx_comm_logs_related`) joined to
  `users`; capped at 500 rows like every other detail-page list in this app.
- Folding into the timeline costs nothing extra — the communications list was
  already being fetched for the count; no N+1.
