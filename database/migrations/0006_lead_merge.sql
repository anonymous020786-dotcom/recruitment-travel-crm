-- Migration 0006 — lead de-duplication: point a merged (loser) lead at the
-- survivor it was folded into. The loser is also soft-deleted; this column lets
-- old links / bookmarks redirect and keeps the lineage auditable.

ALTER TABLE leads
    ADD COLUMN merged_into_id BIGINT UNSIGNED NULL AFTER converted_candidate_id,
    ADD KEY idx_leads_merged_into (merged_into_id),
    ADD CONSTRAINT fk_leads_merged_into FOREIGN KEY (merged_into_id) REFERENCES leads (id);
