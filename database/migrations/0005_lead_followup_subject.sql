-- Migration 0005 — a short "what is this about" line on a scheduled follow-up.
-- The lead_followups table itself ships with 0001; this only adds the subject.

ALTER TABLE lead_followups
    ADD COLUMN subject VARCHAR(200) NULL AFTER channel;
