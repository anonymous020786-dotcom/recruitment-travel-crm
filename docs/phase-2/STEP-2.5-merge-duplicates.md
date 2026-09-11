# Step 2.5 — Merge duplicate leads

**Status:** implemented; 359 tests green (+8). Phase 2 (Leads) continues.

## A. Files created

| File | Purpose |
|---|---|
| `database/migrations/0006_lead_merge.sql` | `leads.merged_into_id BIGINT UNSIGNED NULL` + FK to `leads.id` + index. Lets a merged (loser) lead point at the survivor it was folded into. |
| `resources/views/crm/leads/merge.php` | Merge picker: one `<details>` per likely duplicate (from the existing `findLikelyDuplicates`), each with its own field-by-field comparison table and a scoped "merge this lead in" form — no JS required. |
| `tests/Feature/LeadMergeTest.php` | children moved + audited + loser soft-deleted, empty-field backfill, `take[]` override, self-merge / cross-branch / permission / stale-version rejections, `mergeTargetPublicId` redirect resolution. |

## B. Files modified

- `app/Services/LeadService.php` — `mergeLeads(Lead $survivor, string $loserPublicId, array $take, User $actor, int $expectedVersion): Lead`. Transactional: optimistic-locked update on the survivor (backfills empty fields from the loser, applies `$take` overrides, concatenates notes), `reassignChildren` (moves `lead_notes` + `lead_followups` onto the survivor), optimistic-locked `markMerged` on the loser (soft-delete + `merged_into_id`), a summary note on the survivor, and an audit entry on **both** leads (`merged` / `merged_into`). Refuses: merge-into-self, cross-branch, a converted or already-merged party, a role without `leads.merge`. `duplicatesFor()` enriches the existing duplicate-finder with the full record for comparison.
- `app/Repositories/LeadRepository.php` — `reassignChildren`, `markMerged` (optimistic on `record_version`; refuses a loser that is already merged or converted), `mergeTargetPublicId` (one-hop redirect lookup for stale links); `DETAIL_COLUMNS` gains `merged_into_id`.
- `app/Models/Lead.php` — `mergedIntoId`, `isMerged()`; `isEditable()` now also excludes a merged lead.
- `app/Policies/LeadPolicy.php` — `merge(User, ?Lead)` checks branch scope + editability when a target lead is given (previously a bare permission check).
- `app/Controllers/Crm/LeadController.php` — `mergeForm` / `merge`; `show()` now resolves the lead itself (rather than the old private `find()`) so a 404 on a merged lead's old URL can redirect to the survivor via `mergeTargetPublicId`; timeline labels for `merged` / `followup_*`; "Merge" button in the page header (`canMerge`).
- `routes/web.php` — `GET/POST /leads/{lead}/merge`, gated on `can:leads.merge` (route) + the policy (controller).
- `database/schema/schema.sql` — canonical DDL gains `merged_into_id` + FK + index.

## C. Migration

`0006_lead_merge.sql` — applied to `crm_dev`.

## F. Security / correctness

- Every step re-resolves the actor's `BranchScope`; the loser is loaded **through it**, so a lead outside scope reads as "pick a lead you can see", never as a permission leak.
- Both leads must be in the **same branch** — merging never moves data across a branch boundary.
- Converted and already-merged leads are rejected on either side.
- Both the survivor's update and the loser's `markMerged` are optimistic-locked on `record_version`; a stale read on either side raises `StaleRecordException` and the whole transaction rolls back — no partial merge.
- `activity_logs` stays append-only: the loser's history is left in place (queryable by its old id); the merge itself is logged on both records.
- A merged lead is excluded from `isEditable()`, so it can no longer be edited, followed up on, or re-merged even before the soft-delete filter would catch it.

## H. Manual QA — verified (over HTTP)

- [x] Two leads with the same phone → `/leads/{keeper}/merge` lists the duplicate with a comparison table
- [x] Submitting the merge (taking the loser's email) → **302 to the survivor**, not back to the form
- [x] Survivor page shows the backfilled email, the "Merged in LEAD-…" summary note, and a "merged another lead" timeline entry
- [x] The loser's own URL now **302-redirects to the survivor**
- [x] Full suite **359 tests, 822 assertions**

## I. Performance

- The merge picker enriches at most 20 candidates (the existing duplicate-finder's cap) with one `findById` each — bounded, no N+1 risk in practice.
- The merge transaction itself is 3 statements (survivor update, two child re-assignments, loser update) plus one note insert and two audit inserts — one round trip's worth of work, not per-row.
