# Step 2.6 — Convert a lead to a candidate

**Status:** implemented; 364 tests green (+5). Phase 2 (Leads) continues.

**Scope note:** this step creates the minimal candidate record the conversion
needs — identity, number, stage, origin link — and just enough UI to see it.
The full candidate profile (education, experience, skills, passport,
documents, job pipeline) already has its permission catalogue
(`candidates.education.manage` etc., seeded since Phase 1) but is real module
work for a later phase; the candidate show page says so explicitly.

## A. Files created

| File | Purpose |
|---|---|
| `app/Models/Candidate.php` | Read model — `candidates` joined to `persons` (shared identity) and, optionally, the origin lead. `stageLabel()`. |
| `app/Repositories/PersonRepository.php` | `findOrCreate()` — matches an existing person by phone or email before inserting a new one, so the same individual never accumulates duplicate identities across leads/candidates/employer contacts. |
| `app/Repositories/CandidateRepository.php` | Branch-scoped `findById`/`findByPublicId`, `findIdByPersonId` (enforces the schema's one-candidate-per-person constraint at the app layer), `create`, `paginate` + `stageOptions` for the list screen. |
| `app/Controllers/Crm/CandidateController.php` | `/candidates` index + `/candidates/{candidate}` show. |
| `resources/views/crm/candidates/{index,show}.php` | List (search/stage filter, sortable) and detail (profile fields + origin lead link + a placeholder card naming what's still to come). |
| `tests/Feature/LeadConvertTest.php` | creates person+candidate and marks the lead converted+audited; a second lead for the same phone links the *existing* candidate instead of erroring on the DB's uniqueness constraint; rejects re-conversion, missing permission, and a stale version. |

## B. Files modified

- `app/Services/LeadService.php` — `convert(Lead, User, int $expectedVersion): Candidate`. Transactional: `PersonRepository::findOrCreate`, then either reuse an existing candidate for that person (a repeat lead for someone already in the pipeline — logged as a lead note) or create one via `Sequences::next('candidate', 'CAND', 6)`, then `LeadRepository::markConverted` (optimistic-locked, moves the lead to the `converted` status and links `converted_candidate_id`). Audits `converted` on the lead and `created` on the candidate (skipped when linking to an existing one).
- `app/Repositories/LeadRepository.php` — `markConverted()` (optimistic on `record_version`; refuses an already-converted or merged lead).
- `app/Policies/LeadPolicy.php` — `convert()` now uses `isEditable()` (also excludes a merged lead, not just a converted one).
- `app/Controllers/Crm/LeadController.php` — `convert()` action; `show()` resolves the linked candidate (if any) for the "View candidate" link and passes `canConvert`; new timeline label for `converted`.
- `resources/views/crm/leads/show.php` — "Convert to candidate" action (its own confirm form, since it's a POST) and the converted-lead → candidate link.
- `routes/web.php` — `POST /leads/{lead}/convert`; `GET /candidates`, `GET /candidates/{candidate}`.
- `config/navigation.php`'s existing "Candidates" nav item now resolves instead of 404ing.

## C. Migration

None — `persons` and `candidates` shipped in `0001_initial_schema.sql`.

## F. Security / correctness

- `LeadPolicy::convert` still gates on `leads.convert` + branch scope + an editable, not-already-won lead; the candidate is created in the **lead's branch**, never user-supplied.
- The person/candidate match-and-reuse path exists specifically so the schema's `UNIQUE (person_id)` constraint on `candidates` is handled as a clean business outcome ("this person already has a candidate record") rather than surfacing as an unhandled DB integrity error.
- `LeadRepository::markConverted` is optimistic-locked and refuses a lead that is already converted or merged — no double conversion, no racing writers.
- `candidates.view` gates both new routes; reads are branch-scoped exactly like leads (`findByPublicId` returns nothing outside scope → 404, no existence leak).

## H. Manual QA — verified (over HTTP)

- [x] Lead page shows "Convert to candidate" when `leads.convert` is held and the lead is open
- [x] Converting → **302 straight to the new candidate's page**, not back to the lead
- [x] Candidate page: name, `CAND-2026-000001`, "Converted from lead" → link back
- [x] Lead page now shows "View candidate CAND-2026-000001" and a "converted this lead to a candidate" timeline entry
- [x] `/candidates` list shows the new row
- [x] Full suite **364 tests, 837 assertions**

## I. Performance

- Conversion is one transaction: a person lookup (indexed on phone/email), at most one candidate insert, one lead update, one note insert, two audit inserts.
- The candidate list mirrors the lead list's query shape (single joined query + a separate `COUNT(*)`), indexed the same way.
