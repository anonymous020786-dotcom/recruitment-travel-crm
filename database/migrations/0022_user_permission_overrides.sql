-- Migration 0022 — per-user permission overrides get an expiry, a note and an author (Admin → Users → Permissions).
--
-- `user_permissions` already held allow/deny overrides that PermissionService honours. A temporary grant ("cover for leave until
-- Friday") now expires by itself: an expired row is ignored by PermissionRepository::overridesForUser and cleared after 30 days.

ALTER TABLE user_permissions
    ADD COLUMN expires_at DATETIME        NULL AFTER effect,
    ADD COLUMN note       VARCHAR(200)    NULL AFTER expires_at,
    ADD COLUMN granted_by BIGINT UNSIGNED NULL AFTER note,
    ADD COLUMN created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER granted_by,
    ADD KEY idx_up_expiry (expires_at),
    ADD CONSTRAINT fk_up_granted_by FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL;
