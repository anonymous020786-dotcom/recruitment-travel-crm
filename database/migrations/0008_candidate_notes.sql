-- Migration 0008 — candidate notes, mirroring lead_notes. Candidates had no
-- free-text note trail of their own; the profile timeline needs one to merge
-- alongside activity_logs, the same 3-way merge LeadController::buildTimeline()
-- already does for leads (notes + communication log + audit trail).

CREATE TABLE candidate_notes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    body         TEXT            NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_candidate_notes_candidate (candidate_id, created_at),
    CONSTRAINT fk_candidate_notes_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_candidate_notes_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
