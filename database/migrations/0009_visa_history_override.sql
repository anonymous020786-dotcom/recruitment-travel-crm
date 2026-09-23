-- Migration 0009 — visa status history records overrides, like
-- application_status_history does. A manager holding visa.override_status may
-- make a move the transition table forbids (e.g. reopening a rejected visa);
-- the history row must say so.

ALTER TABLE visa_status_history
    ADD COLUMN is_override TINYINT(1) NOT NULL DEFAULT 0 AFTER to_status;
