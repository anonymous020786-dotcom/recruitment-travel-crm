# Phase 6 · Step 6.1 — Applications & pipeline

A candidate applies to a job; the application moves through a strict, audited pipeline.

## Data & rules
- `applications` (optimistic `record_version`) + append-only `application_status_history`.
- Number `APP-YYYY-NNNNNN` from `Sequences`; `branch_id` follows the candidate.
- Creating requires `applications.create`, view access to both candidate and job, an **active** candidate, an **open** job whose deadline has not passed, and no existing application for the pair.
- The MatchEngine score and full breakdown are **snapshotted** at apply time (`match_score`, `match_breakdown`) so later profile edits don't rewrite history.

## Pipeline (`config/statuses.php` → `application`)
applied → documents_submitted / shortlisted → interview_scheduled ⇄ rescheduled / no_show → interview_completed → selected → offer_received → offer_accepted → medical_pending → medical_completed → visa_processing → visa_approved → ticket_pending → ticket_booked → departed → placed.
`rejected` / `cancelled` are reachable from most states; `placed`, `rejected`, `cancelled` are terminal.

- `StatusMachine::assert` rejects anything not declared. `rejected`/`cancelled` need a reason (`cancel_reason` stored; `closed_at` set).
- **Override** (`applications.override_status`, managers): may make an undeclared move (e.g. reopen a rejected application). Reason is mandatory, history row has `is_override = 1`, audit action is `status_overridden`. Passing the override flag on a legal move is *not* recorded as an override.
- Stale `record_version` → `StaleRecordException`.
- `ApplicationService::advance()` is the system-driven path (no `applications.*` gate) used by interviews in Step 6.2, inside the caller's transaction.
- `candidates.stage` is a denormalised snapshot = most advanced live application status, or `registered` when none is live.

## UI
`/applications` (list, filters), `/applications/{id}` (history timeline, stored match breakdown, move form, audited override form), Apply buttons on the job "Find matches" page and the candidate "Suggested jobs" card, Applications cards on candidate and job profiles.

## Tests & verification
`ApplicationServiceTest` (18 tests): create/snapshot, duplicate, closed job, deadline, inactive candidate, permission/branch denials, ordered history, illegal transitions write nothing, reason rules, override rules, stale version, stage rollup, history is append-only, scope isolation. Full suite: 585 tests green. HTTP smoke: apply → shortlist → illegal move refused → reject w/o reason refused → reject → override w/o reason refused → override with reason; history and stage verified; data cleaned up.
