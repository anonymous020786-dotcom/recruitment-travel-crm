# Step 4.3 — Per-candidate document checklist (closes Phase 4: Documents)

**Status:** implemented; 488 tests green (+6). Closes Phase 4.

**Scope note:** covers the last item in Phase 4's scope line —
`candidate_document_checklist` seeded from `document_types.is_required_default`,
"adjustable per job/employer requirement" per the schema comment (the
job/employer-driven adjustment itself is a Phase 5+ concern; this step ships
the manual per-candidate override an admin/documentation-team member can make
today).

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/ChecklistItem.php` | Read model for one checklist row joined to its document type and (if satisfied) the winning document's status. `isSatisfied()` requires both a linked document *and* that document being in `verified` status. |
| `app/Repositories/ChecklistRepository.php` | `ensureSeeded()` — an idempotent `INSERT IGNORE ... SELECT` from `document_types WHERE is_required_default = 1`, run lazily inside `forCandidate()` rather than at candidate-creation time. This means a document type added *after* a candidate already exists still backfills onto their checklist the next time it's viewed — no migration/backfill script needed, and no coupling from `LeadService::convert()` (a different module) into Documents-phase concerns. `markSatisfied()`/`setRequired()` are both `INSERT ... ON DUPLICATE KEY UPDATE` upserts on the `(candidate_id, document_type_id)` unique key. `clearSatisfiedForExpiredDocuments()` is a join-based bulk clear for the cron. |

## B. Files modified

- `app/Services/DocumentService.php` — `verify()` now also calls `markSatisfied()` for the checklist item after a successful transition; `expireDue()` calls `clearSatisfiedForExpiredDocuments()` whenever it actually expired something (a checklist item satisfied by a document that just expired stops counting as satisfied — an expired passport shouldn't silently still read as "done"). New `toggleChecklistRequirement()` (authorize `documents.checklist.manage`, upsert, audit).
- `app/Controllers/Crm/DocumentController.php` — `toggleChecklist()` action.
- `app/Controllers/Crm/CandidateController.php` — `show()` now also loads `checklist` and `canManageChecklist`; timeline label map gained `checklist_updated`.
- `resources/views/crm/candidates/show.php` — a "Document checklist" card above Documents: ✅/⬜/➖ per type, a "(not required)" tag and Waive/Require toggle for anyone holding `documents.checklist.manage` (non-admins only see the still-required rows).
- `routes/web.php` — `POST /candidates/{candidate}/checklist/{type}`.

## C. Migration

None — `candidate_document_checklist` shipped in `0001_initial_schema.sql`.

## F. Security / correctness

- The FK `satisfied_document_id → candidate_documents(id) ON DELETE SET NULL` already handles the delete case for free — deleting a document that was satisfying a checklist item clears that link at the database level with no application code needed. Verified over the full delete test already in place from Step 4.1 continuing to pass unchanged.
- The *reject* transition needs no checklist cleanup: `verified` is the only status that ever sets `satisfied_document_id`, and the `StatusMachine`'s `document` rules don't allow `verified → rejected` — a document can only be rejected while still `uploaded`/`under_review`, before it ever touched the checklist.
- `expireDue()`'s checklist clear only runs when at least one document actually expired (`if ($expired > 0)`), avoiding an unconditional join-scan on every cron tick when there's nothing to do.
- `toggleChecklistRequirement()` is gated on the real `documents.checklist.manage` permission already seeded in Phase 1's catalogue (no new permission needed) — verified against the role matrix that only `manager`/`documentation`/`admin`/`super_admin` (all via their `documents.*` wildcard) can toggle it, while `counselor` (upload/view/download only) is denied.

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Opened a brand-new candidate's profile → checklist auto-seeded with all 4 default-required document types (photo, passport, national ID, CV), all shown unsatisfied (⬜)
- [x] Uploaded and verified a passport document → checklist's "Passport copy" row flipped to ✅ with no page-specific action needed beyond the normal verify flow
- [x] Waived "CV / Resume" → row shows "(not required)" and stops counting toward the required set
- [x] Cleaned up all synthetic lead/candidate/person/document/checklist rows; re-ran the full suite to confirm no leftover state
- [x] Dev admin password re-randomized post-test
- [x] Full suite **488 tests, 1091 assertions** (+6 new)

## I. Performance

- `ensureSeeded()`'s `INSERT IGNORE ... SELECT` is a single statement bounded by the (small, config-table-sized) `document_types` row count — no per-type round trip.
- `forCandidate()` is one three-way join on indexed foreign keys (`candidate_document_checklist`'s own PK/unique key, `document_types` PK, `candidate_documents` PK) — no new indexes needed.

## Phase 4 exit criteria — status

- **Web-unreachable storage proven**: `storage/private/documents/` behind `Require all denied` + `php_flag engine off`, files never served except through the authorize-then-stream controller; verified a real file downloaded byte-for-byte correctly through that path and is otherwise inaccessible. ✅ (Step 4.1)
- **All upload checks enforced**: fail-closed pipeline (error/size/MIME/signature/active-content-scan/re-encode) — verified a forged `Content-Type` + renamed extension was still rejected by server-side detection. ✅ (Step 4.1)
- **Verification audited**: every transition (`uploaded → under_review → verified/rejected`, and the cron's `verified → expired`) is `record_version`-locked and written to `activity_logs`, visible in the candidate timeline. ✅ (Step 4.2)
