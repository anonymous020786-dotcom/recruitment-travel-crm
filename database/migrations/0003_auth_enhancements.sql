-- Migration 0003 — authentication enhancements: remember-me, trusted devices,
-- email OTP, TOTP, recovery codes, WebAuthn passkeys.
-- Portable across MySQL 8+ and MariaDB 10.4+.

-- ---- users: 2FA columns -----------------------------------------------------
ALTER TABLE users
    ADD COLUMN totp_secret        VARBINARY(255) NULL          AFTER must_change_password,
    ADD COLUMN totp_confirmed_at  DATETIME       NULL          AFTER totp_secret,
    ADD COLUMN two_factor_enabled TINYINT(1)     NOT NULL DEFAULT 0 AFTER totp_confirmed_at,
    ADD COLUMN two_factor_method  ENUM('none','totp','email','passkey') NOT NULL DEFAULT 'none' AFTER two_factor_enabled;

-- ---- Persistent login (remember me) --------------------------------------
-- Selector/validator split: the selector is looked up, the validator is
-- compared with hash_equals. A series survives rotation; a mismatched
-- validator for a known selector = theft -> revoke the whole series.
CREATE TABLE auth_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    series         CHAR(32)        NOT NULL,
    selector       CHAR(24)        NOT NULL,
    validator_hash CHAR(64)        NOT NULL,
    expires_at     DATETIME        NOT NULL,
    last_used_at   DATETIME        NULL,
    created_ip     VARBINARY(16)   NULL,
    created_ua     VARCHAR(255)    NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_selector (selector),
    KEY idx_auth_tokens_user (user_id),
    KEY idx_auth_tokens_series (series),
    KEY idx_auth_tokens_expiry (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Trusted devices (skip the 2FA prompt, not the password) ------------
CREATE TABLE trusted_devices (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64)        NOT NULL,
    label         VARCHAR(120)    NULL,
    ua_hash       CHAR(64)        NULL,
    last_ip       VARBINARY(16)   NULL,
    trusted_until DATETIME        NOT NULL,
    last_seen_at  DATETIME        NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trusted_devices_token (token_hash),
    KEY idx_trusted_devices_user (user_id),
    KEY idx_trusted_devices_expiry (trusted_until),
    CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Email / step-up one-time codes -----------------------------------
CREATE TABLE auth_otp_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    purpose     VARCHAR(40)     NOT NULL,          -- 'login_2fa', 'step_up', ...
    code_hash   CHAR(64)        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    consumed_at DATETIME        NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_ip  VARBINARY(16)   NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auth_otp_user_purpose (user_id, purpose),
    KEY idx_auth_otp_expiry (expires_at),
    CONSTRAINT fk_auth_otp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- TOTP recovery codes (single use, hashed) -------------------------
CREATE TABLE auth_recovery_codes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    code_hash  CHAR(64)        NOT NULL,
    used_at    DATETIME        NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recovery_codes_hash (code_hash),
    KEY idx_recovery_codes_user (user_id),
    CONSTRAINT fk_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- WebAuthn / passkey credentials ---------------------------------
CREATE TABLE webauthn_credentials (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    credential_id VARBINARY(255)  NOT NULL,
    public_key    BLOB            NOT NULL,          -- COSE key
    sign_count    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports    VARCHAR(120)    NULL,
    aaguid        BINARY(16)      NULL,
    label         VARCHAR(120)    NULL,
    last_used_at  DATETIME        NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webauthn_credential (credential_id),
    KEY idx_webauthn_user (user_id),
    CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
