-- Migration 0002 — store the rendered message body with each queued email so
-- cron/process-email-queue.php can send it without re-rendering.

ALTER TABLE email_log
    ADD COLUMN body_html MEDIUMTEXT NULL AFTER template,
    ADD COLUMN body_text TEXT NULL AFTER body_html,
    ADD COLUMN from_name VARCHAR(120) NULL AFTER body_text;
