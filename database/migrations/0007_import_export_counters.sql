-- Migration 0007 — a distinct counter for import rows skipped as likely
-- duplicates (not imported by choice), separate from rows that failed
-- validation. import_batches / import_rows / export_jobs ship with 0001.

ALTER TABLE import_batches
    ADD COLUMN skipped_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER imported_rows;
