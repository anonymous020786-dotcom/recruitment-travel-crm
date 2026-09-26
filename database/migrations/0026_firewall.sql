-- Migration 0026 — the application firewall (Admin → Security → Firewall).
--
-- Settings (managed rule modes, escalation, country rules, lockdown) live in `security_settings` under the `fw.` prefix, so
-- they load with the one query the security policy already makes at boot. This migration adds the super admin's own rules
-- and the event log. Events are throttled per address when written and pruned after 30 days.

CREATE TABLE firewall_rules (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(80)     NOT NULL,
    part         ENUM('path','query','body','user_agent','referer','method','ip','country','header') NOT NULL,
    header_name  VARCHAR(60)     NULL,
    operator     ENUM('contains','equals','starts_with','ends_with','regex','in_cidr','in_list') NOT NULL,
    value        VARCHAR(500)    NOT NULL,
    negate       TINYINT(1)      NOT NULL DEFAULT 0,
    action       ENUM('block','log','allow') NOT NULL DEFAULT 'block',
    priority     SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    expires_at   DATETIME        NULL,
    hits         INT UNSIGNED    NOT NULL DEFAULT 0,
    last_hit_at  DATETIME        NULL,
    note         VARCHAR(200)    NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fw_rules_active (is_active, priority),
    CONSTRAINT fk_fw_rules_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE firewall_events (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address   VARBINARY(16)   NOT NULL,
    method       VARCHAR(10)     NOT NULL,
    path         VARCHAR(300)    NOT NULL,
    rule_key     VARCHAR(60)     NOT NULL,
    rule_label   VARCHAR(120)    NOT NULL,
    action       ENUM('block','log','ban') NOT NULL,
    part         VARCHAR(40)     NULL,
    sample       VARCHAR(200)    NULL,               -- the text that matched, truncated; always shown escaped
    user_agent   VARCHAR(255)    NULL,
    country      CHAR(2)         NULL,
    request_id   VARCHAR(40)     NULL,
    PRIMARY KEY (id),
    KEY idx_fw_events_time (created_at),
    KEY idx_fw_events_ip (ip_address, created_at),
    KEY idx_fw_events_rule (rule_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
