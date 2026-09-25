-- Migration 0019 — credentials and switches for third-party services, managed from Admin → Integrations.
--
-- One row per (service, field). Secret fields are stored AES-256-GCM encrypted with the application key (App\Support\Encryptor);
-- non-secret ones (a site key, a region, a mode) are stored as they are. The pseudo-field `_enabled` holds a service's on/off
-- switch. Values are never written to the audit log — only which fields changed.

CREATE TABLE integration_credentials (
    service     VARCHAR(40)     NOT NULL,
    field       VARCHAR(60)     NOT NULL,
    value       TEXT            NOT NULL,
    is_secret   TINYINT(1)      NOT NULL DEFAULT 0,
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (service, field),
    CONSTRAINT fk_integration_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only the super admin sees or changes credentials by default (adjustable in Admin → Roles).
INSERT IGNORE INTO permissions (name, module, label) VALUES
    ('integrations.view',   'integrations', 'View integrations and their status (secrets stay masked)'),
    ('integrations.manage', 'integrations', 'Set, rotate and clear API keys and secrets');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('integrations.view', 'integrations.manage')
WHERE r.name = 'super_admin';
