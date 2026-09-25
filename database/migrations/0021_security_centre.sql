-- Migration 0021 — Admin → Security: policy overrides, IP rules.
--
-- `security_settings` holds what the super admin changed in the panel: rate-limit overrides (`rate.<bucket>` = {"limit":n,"window":s}),
-- the roles that must use two-factor (`2fa.roles`, `2fa.grace`) and the automatic-block rule (`autoblock.threshold|minutes`).
-- A missing row means "the default from config/.env". `ip.rules` is a cheap "any IP rules exist?" flag so a request only asks
-- the database about its address when there is something to match.
--
-- `ip_rules` are single addresses or CIDR ranges, block or allow (allow wins, so an office range can never be locked out by
-- a broader block). Addresses are stored as 16 bytes (IPv4 as ::ffff:a.b.c.d) so one BETWEEN finds the matching rule.

CREATE TABLE security_settings (
    name        VARCHAR(60)     NOT NULL,
    value       VARCHAR(500)    NOT NULL,
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name),
    CONSTRAINT fk_secset_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ip_rules (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    effect          ENUM('block','allow') NOT NULL DEFAULT 'block',
    cidr            VARCHAR(50)     NOT NULL,           -- as the admin typed it, normalised (1.2.3.4 or 10.0.0.0/8)
    ip_from         VARBINARY(16)   NOT NULL,
    ip_to           VARBINARY(16)   NOT NULL,
    note            VARCHAR(200)    NULL,
    source          ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    expires_at      DATETIME        NULL,               -- NULL = until removed
    last_blocked_at DATETIME        NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ip_rules_cidr (effect, cidr),
    KEY idx_ip_rules_range (ip_from, ip_to),
    KEY idx_ip_rules_expiry (expires_at),
    CONSTRAINT fk_iprules_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, module, label) VALUES
    ('security.view',   'security', 'View the security centre (rate limits, IP rules, sessions, sign-in attempts)'),
    ('security.manage', 'security', 'Change rate limits, two-factor policy and IP rules; sign users out');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('security.view', 'security.manage')
WHERE r.name = 'super_admin';
