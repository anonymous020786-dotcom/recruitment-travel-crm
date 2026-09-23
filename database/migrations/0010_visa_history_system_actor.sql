-- Migration 0010 — visa status history may be written by the system.
-- cron/visa-expiry.php flips approved visas whose expiry date has passed to
-- `expired`; there is no user behind that change, so changed_by must be NULL-able
-- (history readers already LEFT JOIN users).

ALTER TABLE visa_status_history
    MODIFY COLUMN changed_by BIGINT UNSIGNED NULL;
