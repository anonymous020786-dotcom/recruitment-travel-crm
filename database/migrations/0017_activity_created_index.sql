-- Migration 0017 — index for the audit-log viewer.
-- The viewer's default view is "the last 30 days, newest first" with no module or user chosen; without a
-- created_at index that scans the whole (append-only, ever-growing) table.

ALTER TABLE activity_logs ADD KEY idx_activity_created (created_at);
