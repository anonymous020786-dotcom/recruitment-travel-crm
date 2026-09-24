-- Migration 0015 — composite indexes that match the default ORDER BY of the big list screens.
-- Found by the Phase 12 EXPLAIN review against 150k leads / 100k applications / 50k candidates: a branch-scoped
-- list filtered on branch_id and sorted by date had to read and sort every matching row. With these, MariaDB
-- walks the index in order and stops after the page. No data change; safe to run on a live database.

ALTER TABLE leads
    ADD INDEX idx_leads_branch_created (branch_id, deleted_at, created_at, id);

ALTER TABLE applications
    ADD INDEX idx_applications_branch_applied (branch_id, applied_at, id),
    ADD INDEX idx_applications_applied (applied_at, id);

ALTER TABLE candidates
    ADD INDEX idx_candidates_branch_created (branch_id, created_at, id);
