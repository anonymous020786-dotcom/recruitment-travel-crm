-- Migration 0004 — mail queue retry scheduling + new-device login alert toggle.

ALTER TABLE email_log
    ADD COLUMN next_attempt_at DATETIME NULL AFTER attempts,
    ADD COLUMN last_error_at   DATETIME NULL AFTER error;

CREATE INDEX idx_email_log_due ON email_log (status, next_attempt_at);

ALTER TABLE users
    ADD COLUMN notify_new_device TINYINT(1) NOT NULL DEFAULT 1 AFTER two_factor_method;

-- Records each successful sign-in context so "new device" can be detected even
-- after session rows are pruned.
CREATE TABLE login_history (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    ip_address   VARBINARY(16)   NULL,
    ua_hash      CHAR(64)        NULL,
    user_agent   VARCHAR(255)    NULL,
    via          VARCHAR(20)     NOT NULL DEFAULT 'password',
    alerted      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_history_user (user_id, created_at),
    KEY idx_login_history_fingerprint (user_id, ua_hash),
    CONSTRAINT fk_login_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
