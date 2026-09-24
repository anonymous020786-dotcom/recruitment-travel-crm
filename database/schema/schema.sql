-- =============================================================================
-- OVERSEAS RECRUITMENT + JOB PLACEMENT + TRAVEL CRM
-- Canonical MySQL 8+ schema (InnoDB, utf8mb4). Phase 0 design artifact.
-- -----------------------------------------------------------------------------
-- Conventions
--   * Engine InnoDB, CHARACTER SET utf8mb4, COLLATE utf8mb4_unicode_ci
--   * PKs: BIGINT UNSIGNED AUTO_INCREMENT
--   * All timestamps stored UTC. Application converts to business timezone.
--   * created_at / updated_at on every mutable table.
--   * Soft delete (deleted_at) ONLY on: leads, candidates, employers, jobs,
--     tour_packages, persons. Everything else hard-deletes with FK guards.
--   * Optimistic locking: record_version on payments, invoices, refunds,
--     applications, visa_applications, documents verification.
--   * Money: DECIMAL(14,2). Currency stored as CHAR(3) ISO-4217.
--   * Enumerations that are business-configurable live in lookup tables
--     (lead_statuses, document_types, ...). Enumerations that are system
--     invariants (transition engine keys) are CHAR/VARCHAR with app allowlist.
--   * Public identifiers: ULID CHAR(26) column `public_id` on entities exposed
--     in URLs, in addition to the numeric PK. Numeric PK never appears in URLs.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =============================================================================
-- 1. IDENTITY, RBAC, ORG STRUCTURE
-- =============================================================================

CREATE TABLE branches (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    name            VARCHAR(120)    NOT NULL,
    code            VARCHAR(20)     NOT NULL,
    address_line1   VARCHAR(180)    NULL,
    address_line2   VARCHAR(180)    NULL,
    city            VARCHAR(90)     NULL,
    state           VARCHAR(90)     NULL,
    country         CHAR(2)         NULL,
    phone           VARCHAR(30)     NULL,
    email           VARCHAR(180)    NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branches_public_id (public_id),
    UNIQUE KEY uq_branches_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(60)     NOT NULL,          -- machine key: super_admin, admin, manager...
    label           VARCHAR(80)     NOT NULL,
    description     VARCHAR(255)    NULL,
    is_system       TINYINT(1)      NOT NULL DEFAULT 0, -- system roles cannot be deleted
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(80)     NOT NULL,          -- 'leads.view', 'payments.refund'
    module          VARCHAR(40)     NOT NULL,          -- 'leads', 'payments'
    label           VARCHAR(120)    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_name (name),
    KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id         BIGINT UNSIGNED NOT NULL,
    permission_id   BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_rp_permission (permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles (id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    name                VARCHAR(120)    NOT NULL,
    email               VARCHAR(180)    NOT NULL,
    phone               VARCHAR(30)     NULL,
    password_hash       VARCHAR(255)    NOT NULL,           -- password_hash() PASSWORD_DEFAULT
    role_id             BIGINT UNSIGNED NOT NULL,
    primary_branch_id   BIGINT UNSIGNED NULL,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    is_org_wide         TINYINT(1)      NOT NULL DEFAULT 0, -- sees all branches
    failed_login_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME        NULL,
    last_login_at       DATETIME        NULL,
    last_login_ip       VARBINARY(16)   NULL,
    password_changed_at DATETIME        NULL,
    must_change_password TINYINT(1)     NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_public_id (public_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role_id),
    KEY idx_users_branch (primary_branch_id),
    KEY idx_users_active (is_active),
    CONSTRAINT fk_users_role   FOREIGN KEY (role_id)           REFERENCES roles (id),
    CONSTRAINT fk_users_branch FOREIGN KEY (primary_branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users may operate across several branches (spec section 20).
CREATE TABLE user_branches (
    user_id     BIGINT UNSIGNED NOT NULL,
    branch_id   BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, branch_id),
    KEY idx_ub_branch (branch_id),
    CONSTRAINT fk_ub_user   FOREIGN KEY (user_id)   REFERENCES users (id)    ON DELETE CASCADE,
    CONSTRAINT fk_ub_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional per-user permission overrides (grant/deny on top of role).
CREATE TABLE user_permissions (
    user_id         BIGINT UNSIGNED NOT NULL,
    permission_id   BIGINT UNSIGNED NOT NULL,
    effect          ENUM('allow','deny') NOT NULL DEFAULT 'allow',
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_up_user       FOREIGN KEY (user_id)       REFERENCES users (id)       ON DELETE CASCADE,
    CONSTRAINT fk_up_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent server-side session store (DB-backed; shared hosting friendly).
CREATE TABLE sessions (
    id              CHAR(64)        NOT NULL,           -- opaque session id (hashed)
    user_id         BIGINT UNSIGNED NULL,
    ip_address      VARBINARY(16)   NULL,
    user_agent      VARCHAR(255)    NULL,
    payload         MEDIUMBLOB      NOT NULL,
    last_activity   INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    token_hash      CHAR(64)        NOT NULL,           -- sha256 of random token
    expires_at      DATETIME        NOT NULL,
    used_at         DATETIME        NULL,
    request_ip      VARBINARY(16)   NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pwreset_token (token_hash),
    KEY idx_pwreset_user (user_id),
    CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email           VARCHAR(180)    NOT NULL,
    ip_address      VARBINARY(16)   NOT NULL,
    successful      TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic DB-backed rate limiter (login, contact form, expensive search, exports).
CREATE TABLE rate_limits (
    bucket_key      VARCHAR(160)    NOT NULL,           -- e.g. 'login:ip:1.2.3.4', 'search:user:42'
    window_started  DATETIME        NOT NULL,
    hits            INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. SHARED PERSON IDENTITY (spec sections 23, 41)
-- =============================================================================
-- A person can be a recruitment candidate, a travel customer, or both.
-- Module profiles reference this single identity but keep their own detail.

CREATE TABLE persons (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    full_name       VARCHAR(150)    NOT NULL,
    gender          ENUM('male','female','other','undisclosed') NULL,
    date_of_birth   DATE            NULL,
    primary_phone   VARCHAR(30)     NULL,
    alternate_phone VARCHAR(30)     NULL,
    email           VARCHAR(180)    NULL,
    nationality     CHAR(2)         NULL,
    city            VARCHAR(90)     NULL,
    state           VARCHAR(90)     NULL,
    country         CHAR(2)         NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_persons_public_id (public_id),
    KEY idx_persons_phone (primary_phone),
    KEY idx_persons_alt_phone (alternate_phone),
    KEY idx_persons_email (email),
    KEY idx_persons_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. LEADS (spec sections 21, 22)
-- =============================================================================

CREATE TABLE lead_sources (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)     NOT NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order  SMALLINT        NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lead_sources_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_statuses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_name        VARCHAR(40)     NOT NULL,           -- 'new','contacted','follow_up'...
    label           VARCHAR(60)     NOT NULL,
    is_terminal     TINYINT(1)      NOT NULL DEFAULT 0, -- lost / not_interested / converted
    is_won          TINYINT(1)      NOT NULL DEFAULT 0, -- 'converted'
    sort_order      SMALLINT        NOT NULL DEFAULT 0,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lead_statuses_key (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    lead_number         VARCHAR(20)     NOT NULL,       -- LEAD-2026-000123
    person_id           BIGINT UNSIGNED NULL,          -- linked once known / on conversion
    branch_id           BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(150)    NOT NULL,
    phone               VARCHAR(30)     NOT NULL,
    alternate_phone     VARCHAR(30)     NULL,
    email               VARCHAR(180)    NULL,
    gender              ENUM('male','female','other','undisclosed') NULL,
    date_of_birth       DATE            NULL,
    city                VARCHAR(90)     NULL,
    state               VARCHAR(90)     NULL,
    source_id           BIGINT UNSIGNED NULL,
    campaign            VARCHAR(120)    NULL,
    interested_country  CHAR(2)         NULL,
    interested_job      VARCHAR(120)    NULL,
    experience_years    DECIMAL(4,1)    NULL,
    qualification       VARCHAR(120)    NULL,
    salary_expectation  DECIMAL(14,2)   NULL,
    salary_currency     CHAR(3)         NULL,
    priority            ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    status_id           BIGINT UNSIGNED NOT NULL,
    assigned_to         BIGINT UNSIGNED NULL,
    converted_at        DATETIME        NULL,
    converted_candidate_id BIGINT UNSIGNED NULL,
    merged_into_id      BIGINT UNSIGNED NULL,          -- set when this lead was merged into another
    lost_reason         VARCHAR(255)    NULL,
    notes               TEXT            NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leads_public_id (public_id),
    UNIQUE KEY uq_leads_number (lead_number),
    KEY idx_leads_branch_status (branch_id, status_id),
    KEY idx_leads_assigned (assigned_to),
    KEY idx_leads_phone (phone),
    KEY idx_leads_alt_phone (alternate_phone),
    KEY idx_leads_email (email),
    KEY idx_leads_created (created_at),
    KEY idx_leads_branch_created (branch_id, deleted_at, created_at, id),
    KEY idx_leads_person (person_id),
    KEY idx_leads_country (interested_country),
    KEY idx_leads_merged_into (merged_into_id),
    CONSTRAINT fk_leads_branch   FOREIGN KEY (branch_id)  REFERENCES branches (id),
    CONSTRAINT fk_leads_source   FOREIGN KEY (source_id)  REFERENCES lead_sources (id),
    CONSTRAINT fk_leads_status   FOREIGN KEY (status_id)  REFERENCES lead_statuses (id),
    CONSTRAINT fk_leads_assignee FOREIGN KEY (assigned_to) REFERENCES users (id),
    CONSTRAINT fk_leads_person   FOREIGN KEY (person_id)   REFERENCES persons (id),
    CONSTRAINT fk_leads_creator  FOREIGN KEY (created_by)  REFERENCES users (id),
    CONSTRAINT fk_leads_merged_into FOREIGN KEY (merged_into_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_notes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id     BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    body        TEXT            NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lead_notes_lead (lead_id, created_at),
    CONSTRAINT fk_lead_notes_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_notes_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_followups (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id         BIGINT UNSIGNED NOT NULL,
    assigned_to     BIGINT UNSIGNED NOT NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,
    due_date        DATE            NOT NULL,
    due_time        TIME            NULL,
    channel         ENUM('call','whatsapp','sms','email','meeting','other') NOT NULL DEFAULT 'call',
    subject         VARCHAR(200)    NULL,
    status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    outcome         VARCHAR(255)    NULL,
    completed_at    DATETIME        NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lead_fu_lead (lead_id),
    KEY idx_lead_fu_due (assigned_to, status, due_date),
    KEY idx_lead_fu_branch_due (branch_id, due_date),
    CONSTRAINT fk_lead_fu_lead     FOREIGN KEY (lead_id)     REFERENCES leads (id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_fu_assignee FOREIGN KEY (assigned_to) REFERENCES users (id),
    CONSTRAINT fk_lead_fu_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. CANDIDATES (spec sections 23, 24)
-- =============================================================================

CREATE TABLE candidates (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    candidate_number    VARCHAR(20)     NOT NULL,       -- CAND-2026-000123
    person_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    origin_lead_id      BIGINT UNSIGNED NULL,
    stage               VARCHAR(40)     NOT NULL DEFAULT 'registered', -- pipeline snapshot
    marital_status      ENUM('single','married','divorced','widowed') NULL,
    current_country     CHAR(2)         NULL,
    highest_qualification VARCHAR(120)  NULL,
    total_experience_years DECIMAL(4,1) NULL,
    profile_photo_document_id BIGINT UNSIGNED NULL,
    assigned_counselor  BIGINT UNSIGNED NULL,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_candidates_public_id (public_id),
    UNIQUE KEY uq_candidates_number (candidate_number),
    UNIQUE KEY uq_candidates_person (person_id),
    KEY idx_candidates_branch (branch_id),
    KEY idx_candidates_branch_created (branch_id, created_at, id),
    KEY idx_candidates_counselor (assigned_counselor),
    KEY idx_candidates_stage (stage),
    CONSTRAINT fk_candidates_person    FOREIGN KEY (person_id)   REFERENCES persons (id),
    CONSTRAINT fk_candidates_branch    FOREIGN KEY (branch_id)   REFERENCES branches (id),
    CONSTRAINT fk_candidates_lead      FOREIGN KEY (origin_lead_id) REFERENCES leads (id),
    CONSTRAINT fk_candidates_counselor FOREIGN KEY (assigned_counselor) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidate_addresses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    type            ENUM('permanent','current','emergency') NOT NULL DEFAULT 'current',
    line1           VARCHAR(180)    NULL,
    line2           VARCHAR(180)    NULL,
    city            VARCHAR(90)     NULL,
    state           VARCHAR(90)     NULL,
    postal_code     VARCHAR(20)     NULL,
    country         CHAR(2)         NULL,
    contact_name    VARCHAR(120)    NULL,               -- for emergency
    contact_phone   VARCHAR(30)     NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cand_addr_candidate (candidate_id),
    CONSTRAINT fk_cand_addr_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidate_education (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    level           VARCHAR(60)     NOT NULL,           -- 10th, 12th, Diploma, Bachelor...
    institution     VARCHAR(180)    NULL,
    board_university VARCHAR(180)   NULL,
    field_of_study  VARCHAR(120)    NULL,
    start_year      SMALLINT        NULL,
    end_year        SMALLINT        NULL,
    grade           VARCHAR(40)     NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cand_edu_candidate (candidate_id),
    CONSTRAINT fk_cand_edu_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidate_experience (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    employer_name   VARCHAR(180)    NOT NULL,
    job_title       VARCHAR(120)    NOT NULL,
    country         CHAR(2)         NULL,
    start_date      DATE            NULL,
    end_date        DATE            NULL,
    is_current      TINYINT(1)      NOT NULL DEFAULT 0,
    responsibilities TEXT           NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cand_exp_candidate (candidate_id),
    CONSTRAINT fk_cand_exp_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE skills (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)     NOT NULL,
    category    VARCHAR(60)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_skills_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidate_skills (
    candidate_id    BIGINT UNSIGNED NOT NULL,
    skill_id        BIGINT UNSIGNED NOT NULL,
    proficiency     ENUM('basic','intermediate','advanced','expert') NOT NULL DEFAULT 'intermediate',
    years          DECIMAL(4,1)    NULL,
    PRIMARY KEY (candidate_id, skill_id),
    KEY idx_cand_skills_skill (skill_id),
    CONSTRAINT fk_cand_skills_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_cand_skills_skill     FOREIGN KEY (skill_id)     REFERENCES skills (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidate_preferences (
    candidate_id        BIGINT UNSIGNED NOT NULL,
    preferred_countries JSON            NULL,           -- ['AE','SA','QA']
    preferred_job_titles JSON           NULL,
    min_expected_salary DECIMAL(14,2)   NULL,
    salary_currency     CHAR(3)         NULL,
    willing_to_relocate TINYINT(1)      NOT NULL DEFAULT 1,
    available_from      DATE            NULL,
    passport_ready      TINYINT(1)      NOT NULL DEFAULT 0,
    notes               VARCHAR(500)    NULL,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (candidate_id),
    CONSTRAINT fk_cand_pref_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE passports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    passport_number VARCHAR(30)     NOT NULL,
    issue_date      DATE            NULL,
    expiry_date     DATE            NULL,
    place_of_issue  VARCHAR(120)    NULL,
    nationality     CHAR(2)         NULL,
    is_primary      TINYINT(1)      NOT NULL DEFAULT 1,
    held_by         ENUM('candidate','agency','employer','embassy') NOT NULL DEFAULT 'candidate',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_passports_number (passport_number),
    KEY idx_passports_candidate (candidate_id),
    KEY idx_passports_expiry (expiry_date),
    CONSTRAINT fk_passports_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidate_notes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    body         TEXT            NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_candidate_notes_candidate (candidate_id, created_at),
    CONSTRAINT fk_candidate_notes_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_candidate_notes_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. DOCUMENTS (spec section 31, 13)
-- =============================================================================

CREATE TABLE document_types (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_name        VARCHAR(50)     NOT NULL,           -- 'passport','aadhaar','cv'...
    label           VARCHAR(120)    NOT NULL,
    category        ENUM('identity','education','experience','medical','police','visa','travel','agreement','other') NOT NULL DEFAULT 'other',
    has_expiry      TINYINT(1)      NOT NULL DEFAULT 0,
    is_required_default TINYINT(1)  NOT NULL DEFAULT 0,
    allowed_mime    VARCHAR(255)    NOT NULL DEFAULT 'application/pdf,image/jpeg,image/png',
    max_size_kb     INT UNSIGNED    NOT NULL DEFAULT 8192,
    sort_order      SMALLINT        NOT NULL DEFAULT 0,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_document_types_key (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidate_documents (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    candidate_id        BIGINT UNSIGNED NOT NULL,
    document_type_id    BIGINT UNSIGNED NOT NULL,
    storage_disk        VARCHAR(20)     NOT NULL DEFAULT 'private',
    storage_path        VARCHAR(255)    NOT NULL,       -- storage/private/documents/ab/cd/<ulid>.bin
    original_name       VARCHAR(200)    NOT NULL,
    mime_type           VARCHAR(120)    NOT NULL,       -- server-detected (finfo), not client
    extension           VARCHAR(12)     NOT NULL,
    size_bytes          INT UNSIGNED    NOT NULL,
    sha256              CHAR(64)        NOT NULL,
    status              ENUM('pending','uploaded','under_review','verified','rejected','expired') NOT NULL DEFAULT 'uploaded',
    rejection_reason    VARCHAR(255)    NULL,
    issued_on           DATE            NULL,
    expires_at          DATE            NULL,
    uploaded_by         BIGINT UNSIGNED NOT NULL,
    verified_by         BIGINT UNSIGNED NULL,
    verified_at         DATETIME        NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cand_docs_public_id (public_id),
    KEY idx_cand_docs_candidate (candidate_id, document_type_id),
    KEY idx_cand_docs_status (status),
    KEY idx_cand_docs_expiry (expires_at),
    CONSTRAINT fk_cand_docs_candidate FOREIGN KEY (candidate_id)     REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_cand_docs_type      FOREIGN KEY (document_type_id) REFERENCES document_types (id),
    CONSTRAINT fk_cand_docs_uploader  FOREIGN KEY (uploaded_by)      REFERENCES users (id),
    CONSTRAINT fk_cand_docs_verifier  FOREIGN KEY (verified_by)      REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-candidate required-document checklist (seeded from document_types defaults,
-- adjustable per job/employer requirement).
CREATE TABLE candidate_document_checklist (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    document_type_id BIGINT UNSIGNED NOT NULL,
    is_required     TINYINT(1)      NOT NULL DEFAULT 1,
    satisfied_document_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checklist (candidate_id, document_type_id),
    CONSTRAINT fk_checklist_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_checklist_type      FOREIGN KEY (document_type_id) REFERENCES document_types (id),
    CONSTRAINT fk_checklist_doc       FOREIGN KEY (satisfied_document_id) REFERENCES candidate_documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_access_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id     BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    action          ENUM('view','download','preview') NOT NULL,
    ip_address      VARBINARY(16)   NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_access_document (document_id, created_at),
    CONSTRAINT fk_doc_access_document FOREIGN KEY (document_id) REFERENCES candidate_documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_access_user     FOREIGN KEY (user_id)     REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. EMPLOYERS & JOBS (spec sections 25, 26, 27)
-- =============================================================================

CREATE TABLE employers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    employer_number     VARCHAR(20)     NOT NULL,
    company_name        VARCHAR(180)    NOT NULL,
    country             CHAR(2)         NOT NULL,
    city                VARCHAR(90)     NULL,
    address             VARCHAR(255)    NULL,
    industry            VARCHAR(120)    NULL,
    website             VARCHAR(180)    NULL,
    license_number      VARCHAR(80)     NULL,
    license_expiry      DATE            NULL,
    status              ENUM('prospect','active','suspended','blacklisted','inactive') NOT NULL DEFAULT 'active',
    branch_id           BIGINT UNSIGNED NULL,
    account_owner       BIGINT UNSIGNED NULL,
    notes               TEXT            NULL,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employers_public_id (public_id),
    UNIQUE KEY uq_employers_number (employer_number),
    KEY idx_employers_country (country),
    KEY idx_employers_status (status),
    KEY idx_employers_name (company_name),
    CONSTRAINT fk_employers_branch FOREIGN KEY (branch_id)     REFERENCES branches (id),
    CONSTRAINT fk_employers_owner  FOREIGN KEY (account_owner) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employer_contacts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employer_id     BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(120)    NOT NULL,
    designation     VARCHAR(120)    NULL,
    email           VARCHAR(180)    NULL,
    phone           VARCHAR(30)     NULL,
    is_primary      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employer_contacts_employer (employer_id),
    CONSTRAINT fk_employer_contacts_employer FOREIGN KEY (employer_id) REFERENCES employers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    job_number          VARCHAR(20)     NOT NULL,
    slug                VARCHAR(180)    NOT NULL,       -- public SEO slug
    title               VARCHAR(160)    NOT NULL,
    employer_id         BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NULL,
    country             CHAR(2)         NOT NULL,
    city                VARCHAR(90)     NULL,
    vacancies           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    salary_min          DECIMAL(14,2)   NULL,
    salary_max          DECIMAL(14,2)   NULL,
    currency            CHAR(3)         NULL,
    experience_required VARCHAR(120)    NULL,
    qualification       VARCHAR(120)    NULL,
    age_min             TINYINT UNSIGNED NULL,
    age_max             TINYINT UNSIGNED NULL,
    gender_requirement  ENUM('any','male','female') NOT NULL DEFAULT 'any',
    accommodation       ENUM('none','provided','allowance') NOT NULL DEFAULT 'none',
    food                ENUM('none','provided','allowance') NOT NULL DEFAULT 'none',
    transport           ENUM('none','provided','allowance') NOT NULL DEFAULT 'none',
    working_hours       VARCHAR(60)     NULL,
    overtime            VARCHAR(120)    NULL,
    contract_duration_months SMALLINT UNSIGNED NULL,
    interview_type      ENUM('in_person','video','telephonic','cv_selection','client_visit') NULL,
    deadline            DATE            NULL,
    status              ENUM('draft','open','paused','interview','filled','closed','cancelled') NOT NULL DEFAULT 'draft',
    is_public           TINYINT(1)      NOT NULL DEFAULT 0,
    description_html     MEDIUMTEXT      NULL,           -- sanitized rich text
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jobs_public_id (public_id),
    UNIQUE KEY uq_jobs_number (job_number),
    UNIQUE KEY uq_jobs_slug (slug),
    KEY idx_jobs_employer (employer_id),
    KEY idx_jobs_status (status),
    KEY idx_jobs_country_status (country, status),
    KEY idx_jobs_public (is_public, status),
    CONSTRAINT fk_jobs_employer FOREIGN KEY (employer_id) REFERENCES employers (id),
    CONSTRAINT fk_jobs_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_requirements (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id      BIGINT UNSIGNED NOT NULL,
    skill_id    BIGINT UNSIGNED NULL,
    label       VARCHAR(120)    NOT NULL,
    is_mandatory TINYINT(1)     NOT NULL DEFAULT 1,
    weight      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_job_req_job (job_id),
    CONSTRAINT fk_job_req_job   FOREIGN KEY (job_id)   REFERENCES jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_req_skill FOREIGN KEY (skill_id) REFERENCES skills (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_benefits (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id      BIGINT UNSIGNED NOT NULL,
    label       VARCHAR(120)    NOT NULL,
    PRIMARY KEY (id),
    KEY idx_job_benefits_job (job_id),
    CONSTRAINT fk_job_benefits_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. APPLICATIONS, INTERVIEWS (spec sections 28, 29, 30)
-- =============================================================================

CREATE TABLE applications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    application_number  VARCHAR(20)     NOT NULL,
    candidate_id        BIGINT UNSIGNED NOT NULL,
    job_id              BIGINT UNSIGNED NOT NULL,
    employer_id         BIGINT UNSIGNED NOT NULL,       -- denormalized from job for reporting
    branch_id           BIGINT UNSIGNED NOT NULL,
    status              VARCHAR(40)     NOT NULL DEFAULT 'applied',  -- transition-engine key
    match_score         DECIMAL(5,2)    NULL,
    match_breakdown     JSON            NULL,
    assigned_to         BIGINT UNSIGNED NULL,
    applied_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at           DATETIME        NULL,
    cancel_reason       VARCHAR(255)    NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_applications_public_id (public_id),
    UNIQUE KEY uq_applications_number (application_number),
    UNIQUE KEY uq_applications_candidate_job (candidate_id, job_id),
    KEY idx_applications_job_status (job_id, status),
    KEY idx_applications_candidate (candidate_id),
    KEY idx_applications_employer (employer_id),
    KEY idx_applications_branch_status (branch_id, status),
    KEY idx_applications_branch_applied (branch_id, applied_at, id),
    KEY idx_applications_applied (applied_at, id),
    CONSTRAINT fk_applications_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id),
    CONSTRAINT fk_applications_job       FOREIGN KEY (job_id)       REFERENCES jobs (id),
    CONSTRAINT fk_applications_employer  FOREIGN KEY (employer_id)  REFERENCES employers (id),
    CONSTRAINT fk_applications_branch    FOREIGN KEY (branch_id)    REFERENCES branches (id),
    CONSTRAINT fk_applications_assignee  FOREIGN KEY (assigned_to)  REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_status_history (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id  BIGINT UNSIGNED NOT NULL,
    from_status     VARCHAR(40)     NULL,
    to_status       VARCHAR(40)     NOT NULL,
    is_override     TINYINT(1)      NOT NULL DEFAULT 0,  -- transition not in allowlist
    reason          VARCHAR(255)    NULL,
    changed_by      BIGINT UNSIGNED NOT NULL,
    changed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ash_application (application_id, changed_at),
    CONSTRAINT fk_ash_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
    CONSTRAINT fk_ash_user        FOREIGN KEY (changed_by)     REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE interviews (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    application_id  BIGINT UNSIGNED NOT NULL,
    candidate_id    BIGINT UNSIGNED NOT NULL,           -- denormalized
    job_id          BIGINT UNSIGNED NOT NULL,           -- denormalized
    employer_id     BIGINT UNSIGNED NOT NULL,           -- denormalized
    round_no        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    type            ENUM('in_person','video','telephonic','client_visit') NOT NULL,
    scheduled_date  DATE            NOT NULL,
    scheduled_time  TIME            NULL,
    location        VARCHAR(200)    NULL,
    meeting_link    VARCHAR(255)    NULL,
    interviewer     VARCHAR(160)    NULL,
    status          ENUM('scheduled','confirmed','completed','selected','rejected','rescheduled','no_show') NOT NULL DEFAULT 'scheduled',
    result          ENUM('pending','selected','rejected','hold') NOT NULL DEFAULT 'pending',
    feedback        TEXT            NULL,
    notes           TEXT            NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_interviews_public_id (public_id),
    KEY idx_interviews_application (application_id),
    KEY idx_interviews_date (scheduled_date, status),
    KEY idx_interviews_candidate (candidate_id),
    CONSTRAINT fk_interviews_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
    CONSTRAINT fk_interviews_candidate   FOREIGN KEY (candidate_id)   REFERENCES candidates (id),
    CONSTRAINT fk_interviews_job         FOREIGN KEY (job_id)         REFERENCES jobs (id),
    CONSTRAINT fk_interviews_employer    FOREIGN KEY (employer_id)    REFERENCES employers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. MEDICAL, VISA (spec sections 32, 33)
-- =============================================================================

CREATE TABLE medical_records (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    application_id  BIGINT UNSIGNED NULL,
    medical_center  VARCHAR(180)    NULL,
    appointment_date DATE           NULL,
    medical_date    DATE            NULL,
    report_date     DATE            NULL,
    result          ENUM('pending','fit','unfit','retest') NOT NULL DEFAULT 'pending',
    certificate_document_id BIGINT UNSIGNED NULL,
    expires_at      DATE            NULL,
    status          ENUM('pending','scheduled','completed','fit','unfit','retest') NOT NULL DEFAULT 'pending',
    notes           TEXT            NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_medical_public_id (public_id),
    KEY idx_medical_candidate (candidate_id),
    KEY idx_medical_application (application_id),
    KEY idx_medical_expiry (expires_at),
    CONSTRAINT fk_medical_candidate   FOREIGN KEY (candidate_id)   REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_medical_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE SET NULL,
    CONSTRAINT fk_medical_certificate FOREIGN KEY (certificate_document_id) REFERENCES candidate_documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visa_applications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    candidate_id        BIGINT UNSIGNED NOT NULL,
    application_id      BIGINT UNSIGNED NULL,
    country             CHAR(2)         NOT NULL,
    visa_type           VARCHAR(80)     NULL,
    visa_number         VARCHAR(80)     NULL,
    reference_number    VARCHAR(80)     NULL,
    sponsor             VARCHAR(180)    NULL,
    submission_date     DATE            NULL,
    approval_date       DATE            NULL,
    expiry_date         DATE            NULL,
    status              ENUM('not_started','documents_pending','submitted','under_processing','approved','rejected','expired','cancelled') NOT NULL DEFAULT 'not_started',
    notes               TEXT            NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_visa_public_id (public_id),
    KEY idx_visa_candidate (candidate_id),
    KEY idx_visa_application (application_id),
    KEY idx_visa_status (status),
    KEY idx_visa_expiry (expiry_date),
    CONSTRAINT fk_visa_candidate   FOREIGN KEY (candidate_id)   REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_visa_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visa_status_history (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visa_application_id BIGINT UNSIGNED NOT NULL,
    from_status         VARCHAR(40)     NULL,
    to_status           VARCHAR(40)     NOT NULL,
    is_override         TINYINT(1)      NOT NULL DEFAULT 0,  -- transition not in allowlist
    reason              VARCHAR(255)    NULL,
    changed_by          BIGINT UNSIGNED NULL,                  -- NULL = the system (expiry cron)
    changed_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vsh_visa (visa_application_id, changed_at),
    CONSTRAINT fk_vsh_visa FOREIGN KEY (visa_application_id) REFERENCES visa_applications (id) ON DELETE CASCADE,
    CONSTRAINT fk_vsh_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. TRAVEL / TICKETING + TOUR PACKAGES (spec sections 34, 40)
-- =============================================================================

CREATE TABLE travel_profiles (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    application_id  BIGINT UNSIGNED NULL,
    readiness       ENUM('not_ready','planning','ticket_pending','ticket_booked','departed','arrived') NOT NULL DEFAULT 'not_ready',
    preferred_departure_city VARCHAR(90) NULL,
    notes           TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_travel_profiles_candidate (candidate_id),
    CONSTRAINT fk_travel_profiles_candidate  FOREIGN KEY (candidate_id)  REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_profiles_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE flight_bookings (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    candidate_id        BIGINT UNSIGNED NOT NULL,
    application_id      BIGINT UNSIGNED NULL,
    pnr                 VARCHAR(20)     NULL,
    airline             VARCHAR(120)    NULL,
    flight_number       VARCHAR(20)     NULL,
    departure_airport   VARCHAR(10)     NULL,
    arrival_airport     VARCHAR(10)     NULL,
    departure_at        DATETIME        NULL,
    arrival_at          DATETIME        NULL,
    baggage_allowance   VARCHAR(60)     NULL,
    ticket_price        DECIMAL(14,2)   NULL,
    currency            CHAR(3)         NULL,
    status              ENUM('planned','booked','issued','changed','cancelled','flown') NOT NULL DEFAULT 'planned',
    ticket_document_id  BIGINT UNSIGNED NULL,
    notes               TEXT            NULL,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flight_bookings_public_id (public_id),
    KEY idx_flight_bookings_candidate (candidate_id),
    KEY idx_flight_bookings_departure (departure_at),
    CONSTRAINT fk_flight_candidate   FOREIGN KEY (candidate_id)   REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_flight_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departure_records (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    application_id  BIGINT UNSIGNED NULL,
    flight_booking_id BIGINT UNSIGNED NULL,
    departed_at     DATETIME        NULL,
    arrived_at      DATETIME        NULL,
    arrival_confirmed_by BIGINT UNSIGNED NULL,
    placement_confirmed_at DATETIME NULL,
    notes           TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departure_application (application_id),
    KEY idx_departure_candidate (candidate_id),
    CONSTRAINT fk_departure_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE,
    CONSTRAINT fk_departure_flight    FOREIGN KEY (flight_booking_id) REFERENCES flight_bookings (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placements (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    candidate_id    BIGINT UNSIGNED NOT NULL,
    application_id  BIGINT UNSIGNED NOT NULL,
    employer_id     BIGINT UNSIGNED NOT NULL,
    job_id          BIGINT UNSIGNED NOT NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,
    placed_on       DATE            NOT NULL,
    monthly_salary  DECIMAL(14,2)   NULL,
    currency        CHAR(3)         NULL,
    contract_end    DATE            NULL,
    status          ENUM('active','completed','terminated','absconded') NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_placements_public_id (public_id),
    UNIQUE KEY uq_placements_application (application_id),
    KEY idx_placements_branch_date (branch_id, placed_on),
    KEY idx_placements_employer (employer_id),
    CONSTRAINT fk_placements_candidate   FOREIGN KEY (candidate_id)   REFERENCES candidates (id),
    CONSTRAINT fk_placements_application FOREIGN KEY (application_id) REFERENCES applications (id),
    CONSTRAINT fk_placements_employer    FOREIGN KEY (employer_id)    REFERENCES employers (id),
    CONSTRAINT fk_placements_job         FOREIGN KEY (job_id)         REFERENCES jobs (id),
    CONSTRAINT fk_placements_branch      FOREIGN KEY (branch_id)      REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tour_packages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    slug            VARCHAR(180)    NOT NULL,
    name            VARCHAR(180)    NOT NULL,
    destination     VARCHAR(120)    NOT NULL,
    duration_days   SMALLINT UNSIGNED NULL,
    duration_nights SMALLINT UNSIGNED NULL,
    start_location  VARCHAR(120)    NULL,
    price           DECIMAL(14,2)   NULL,
    currency        CHAR(3)         NULL,
    hotel_summary   VARCHAR(255)    NULL,
    transport_summary VARCHAR(255)  NULL,
    meals_summary   VARCHAR(255)    NULL,
    inclusions_html MEDIUMTEXT      NULL,
    exclusions_html MEDIUMTEXT      NULL,
    terms_html      MEDIUMTEXT      NULL,
    status          ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    is_public       TINYINT(1)      NOT NULL DEFAULT 0,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tour_packages_public_id (public_id),
    UNIQUE KEY uq_tour_packages_slug (slug),
    KEY idx_tour_packages_status (status, is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tour_package_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tour_package_id BIGINT UNSIGNED NOT NULL,
    day_no          SMALLINT UNSIGNED NULL,
    title           VARCHAR(180)    NOT NULL,
    description     TEXT            NULL,
    sort_order      SMALLINT        NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_tour_items_package (tour_package_id),
    CONSTRAINT fk_tour_items_package FOREIGN KEY (tour_package_id) REFERENCES tour_packages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tour_bookings (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    booking_number      VARCHAR(20)     NOT NULL,
    person_id           BIGINT UNSIGNED NOT NULL,       -- shared customer identity
    tour_package_id     BIGINT UNSIGNED NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    travel_date         DATE            NULL,
    return_date         DATE            NULL,
    adults              SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    children            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_amount        DECIMAL(14,2)   NOT NULL DEFAULT 0,
    currency            CHAR(3)         NOT NULL DEFAULT 'INR',
    status              ENUM('inquiry','quoted','confirmed','travelling','completed','cancelled') NOT NULL DEFAULT 'inquiry',
    assigned_to         BIGINT UNSIGNED NULL,
    notes               TEXT            NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tour_bookings_public_id (public_id),
    UNIQUE KEY uq_tour_bookings_number (booking_number),
    KEY idx_tour_bookings_person (person_id),
    KEY idx_tour_bookings_branch_status (branch_id, status),
    KEY idx_tour_bookings_travel_date (travel_date),
    CONSTRAINT fk_tour_bookings_person  FOREIGN KEY (person_id)       REFERENCES persons (id),
    CONSTRAINT fk_tour_bookings_package FOREIGN KEY (tour_package_id) REFERENCES tour_packages (id),
    CONSTRAINT fk_tour_bookings_branch  FOREIGN KEY (branch_id)       REFERENCES branches (id),
    CONSTRAINT fk_tour_bookings_assignee FOREIGN KEY (assigned_to)    REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only, written by TourBookingService inside the transition transaction.
CREATE TABLE tour_booking_status_history (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tour_booking_id BIGINT UNSIGNED NOT NULL,
    from_status     VARCHAR(40)     NULL,
    to_status       VARCHAR(40)     NOT NULL,
    reason          VARCHAR(255)    NULL,
    changed_by      BIGINT UNSIGNED NULL,
    changed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tbsh_booking (tour_booking_id, changed_at),
    CONSTRAINT fk_tbsh_booking FOREIGN KEY (tour_booking_id) REFERENCES tour_bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_tbsh_user    FOREIGN KEY (changed_by)      REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 10. FINANCE (spec sections 35, 36) — ledger-style, immutable history
-- =============================================================================
-- invoiceable_type/id is a polymorphic link to either an application (recruitment
-- service fee) or a tour_booking. Validated by application allowlist.

CREATE TABLE invoices (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    invoice_number      VARCHAR(24)     NOT NULL,
    person_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    invoiceable_type    ENUM('application','tour_booking','other') NOT NULL,
    invoiceable_id      BIGINT UNSIGNED NULL,
    currency            CHAR(3)         NOT NULL DEFAULT 'INR',
    subtotal            DECIMAL(14,2)   NOT NULL DEFAULT 0,
    discount_total      DECIMAL(14,2)   NOT NULL DEFAULT 0,
    tax_total           DECIMAL(14,2)   NOT NULL DEFAULT 0,
    grand_total         DECIMAL(14,2)   NOT NULL DEFAULT 0,
    amount_paid         DECIMAL(14,2)   NOT NULL DEFAULT 0,   -- maintained by allocations, server-side
    amount_refunded     DECIMAL(14,2)   NOT NULL DEFAULT 0,
    status              ENUM('draft','issued','partially_paid','paid','void') NOT NULL DEFAULT 'draft',
    issued_on           DATE            NULL,
    due_on              DATE            NULL,
    notes               VARCHAR(500)    NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_public_id (public_id),
    UNIQUE KEY uq_invoices_number (invoice_number),
    KEY idx_invoices_person (person_id),
    KEY idx_invoices_branch_status (branch_id, status),
    KEY idx_invoices_invoiceable (invoiceable_type, invoiceable_id),
    KEY idx_invoices_due (due_on, status),
    CONSTRAINT fk_invoices_person FOREIGN KEY (person_id) REFERENCES persons (id),
    CONSTRAINT fk_invoices_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT chk_invoices_nonneg CHECK (subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND grand_total >= 0 AND amount_paid >= 0 AND amount_refunded >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_lines (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255)    NOT NULL,
    quantity    DECIMAL(10,2)   NOT NULL DEFAULT 1,
    unit_price  DECIMAL(14,2)   NOT NULL DEFAULT 0,
    line_total  DECIMAL(14,2)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_invoice_lines_invoice (invoice_id),
    CONSTRAINT fk_invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT chk_invoice_lines_nonneg CHECK (quantity >= 0 AND unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only, written by InvoiceService / PaymentService inside the transition transaction.
CREATE TABLE invoice_status_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40)     NULL,
    to_status   VARCHAR(40)     NOT NULL,
    reason      VARCHAR(255)    NULL,
    changed_by  BIGINT UNSIGNED NULL,
    changed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ish_invoice (invoice_id, changed_at),
    CONSTRAINT fk_ish_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_ish_user    FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    payment_number      VARCHAR(24)     NOT NULL,
    receipt_number      VARCHAR(24)     NOT NULL,
    person_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    amount              DECIMAL(14,2)   NOT NULL,
    currency            CHAR(3)         NOT NULL DEFAULT 'INR',
    method              ENUM('cash','bank_transfer','upi','card','cheque','other') NOT NULL,
    reference           VARCHAR(120)    NULL,
    paid_at             DATETIME        NOT NULL,
    idempotency_key     CHAR(64)        NULL,               -- prevents double-submit
    status              ENUM('recorded','reversed') NOT NULL DEFAULT 'recorded',
    reversed_reason     VARCHAR(255)    NULL,
    notes               VARCHAR(500)    NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_public_id (public_id),
    UNIQUE KEY uq_payments_number (payment_number),
    UNIQUE KEY uq_payments_receipt (receipt_number),
    UNIQUE KEY uq_payments_idempotency (idempotency_key),
    KEY idx_payments_person (person_id),
    KEY idx_payments_branch_date (branch_id, paid_at),
    CONSTRAINT fk_payments_person FOREIGN KEY (person_id) REFERENCES persons (id),
    CONSTRAINT fk_payments_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT chk_payments_amount_pos CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_allocations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id  BIGINT UNSIGNED NOT NULL,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    amount      DECIMAL(14,2)   NOT NULL,
    created_by  BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alloc_payment_invoice (payment_id, invoice_id),
    KEY idx_alloc_invoice (invoice_id),
    CONSTRAINT fk_alloc_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
    CONSTRAINT fk_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT chk_alloc_amount_pos CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,
    refund_number       VARCHAR(24)     NOT NULL,
    payment_id          BIGINT UNSIGNED NOT NULL,
    invoice_id          BIGINT UNSIGNED NULL,
    person_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    amount              DECIMAL(14,2)   NOT NULL,
    currency            CHAR(3)         NOT NULL DEFAULT 'INR',
    method              ENUM('cash','bank_transfer','upi','card','cheque','adjustment') NOT NULL,
    reason              VARCHAR(255)    NOT NULL,
    status              ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
    approved_by         BIGINT UNSIGNED NULL,
    approved_at         DATETIME        NULL,
    refunded_at         DATETIME        NULL,
    record_version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refunds_public_id (public_id),
    UNIQUE KEY uq_refunds_number (refund_number),
    KEY idx_refunds_payment (payment_id),
    KEY idx_refunds_person (person_id),
    KEY idx_refunds_branch_status (branch_id, status),
    CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
    CONSTRAINT fk_refunds_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_refunds_person  FOREIGN KEY (person_id)  REFERENCES persons (id),
    CONSTRAINT fk_refunds_branch  FOREIGN KEY (branch_id)  REFERENCES branches (id),
    CONSTRAINT chk_refunds_amount_pos CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_number  VARCHAR(24)     NOT NULL,
    payment_id      BIGINT UNSIGNED NOT NULL,
    issued_by       BIGINT UNSIGNED NOT NULL,
    issued_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    snapshot_json   JSON            NOT NULL,          -- immutable rendered data
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipts_number (receipt_number),
    KEY idx_receipts_payment (payment_id),
    CONSTRAINT fk_receipts_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only, written by RefundService inside the transition transaction.
CREATE TABLE refund_status_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    refund_id   BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40)     NULL,
    to_status   VARCHAR(40)     NOT NULL,
    reason      VARCHAR(255)    NULL,
    changed_by  BIGINT UNSIGNED NULL,
    changed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rsh_refund (refund_id, changed_at),
    CONSTRAINT fk_rsh_refund FOREIGN KEY (refund_id) REFERENCES refunds (id) ON DELETE CASCADE,
    CONSTRAINT fk_rsh_user   FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monotonic per-branch/per-year document number sequences (gap-free, lock-based).
CREATE TABLE number_sequences (
    scope       VARCHAR(60)     NOT NULL,              -- 'invoice:BR1:2026', 'lead:2026'
    next_value  BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 11. TASKS, NOTIFICATIONS, COMMUNICATION, ACTIVITY (spec sections 17, 37, 38, 45)
-- =============================================================================

CREATE TABLE tasks (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    title           VARCHAR(200)    NOT NULL,
    description     TEXT            NULL,
    related_type    ENUM('lead','candidate','application','payment','invoice','visa','travel','employer','tour_booking','none') NOT NULL DEFAULT 'none',
    related_id      BIGINT UNSIGNED NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,
    assigned_to     BIGINT UNSIGNED NOT NULL,
    priority        ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    due_date        DATE            NULL,
    due_time        TIME            NULL,
    status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    completed_at    DATETIME        NULL,
    source          ENUM('manual','system') NOT NULL DEFAULT 'manual',
    dedupe_key      VARCHAR(120)    NULL,              -- for idempotent system tasks
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tasks_public_id (public_id),
    UNIQUE KEY uq_tasks_dedupe (dedupe_key),
    KEY idx_tasks_assignee_status_due (assigned_to, status, due_date),
    KEY idx_tasks_branch_due (branch_id, due_date),
    KEY idx_tasks_related (related_type, related_id),
    CONSTRAINT fk_tasks_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id),
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    type            VARCHAR(60)     NOT NULL,          -- 'passport_expiring', 'interview_tomorrow'
    title           VARCHAR(200)    NOT NULL,
    body            VARCHAR(500)    NULL,
    link_type       VARCHAR(40)     NULL,             -- deep-link target entity
    link_id         BIGINT UNSIGNED NULL,
    link_fragment   VARCHAR(40)     NULL,             -- tab anchor e.g. 'passport'
    read_at         DATETIME        NULL,
    dedupe_key      VARCHAR(150)    NULL,             -- idempotent automated notifications
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_dedupe (dedupe_key),
    KEY idx_notifications_user_read (user_id, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE communication_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    related_type    ENUM('lead','candidate','employer','application','tour_booking') NOT NULL,
    related_id      BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    channel         ENUM('call','whatsapp','sms','email','meeting','note') NOT NULL,
    direction       ENUM('inbound','outbound','internal') NOT NULL DEFAULT 'outbound',
    summary         VARCHAR(500)    NOT NULL,
    occurred_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comm_logs_related (related_type, related_id, occurred_at),
    CONSTRAINT fk_comm_logs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable audit trail (spec section 17). No UPDATE/DELETE granted at app level.
CREATE TABLE activity_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL,              -- null = system/cron
    action          VARCHAR(60)     NOT NULL,          -- 'created','updated','status_changed','deleted','verified'
    module          VARCHAR(40)     NOT NULL,
    record_type     VARCHAR(40)     NOT NULL,
    record_id       BIGINT UNSIGNED NULL,
    old_values      JSON            NULL,
    new_values      JSON            NULL,
    ip_address      VARBINARY(16)   NULL,
    user_agent      VARCHAR(255)    NULL,
    context         VARCHAR(255)    NULL,              -- free note, e.g. override reason
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_record (record_type, record_id, created_at),
    KEY idx_activity_user_time (user_id, created_at),
    KEY idx_activity_module_time (module, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 12. SUPPORTING: settings, countries, imports, exports, cron, mail, WhatsApp
-- =============================================================================

CREATE TABLE settings (
    key_name    VARCHAR(80)     NOT NULL,
    value       JSON            NOT NULL,
    is_public   TINYINT(1)      NOT NULL DEFAULT 0,    -- safe to expose to public pages
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE countries (
    code        CHAR(2)         NOT NULL,              -- ISO-3166-1 alpha-2
    name        VARCHAR(90)     NOT NULL,
    dial_code   VARCHAR(8)      NULL,
    is_gcc      TINYINT(1)      NOT NULL DEFAULT 0,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (code),
    UNIQUE KEY uq_countries_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_templates (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_name    VARCHAR(50)     NOT NULL,              -- 'document_reminder','interview_reminder'
    label       VARCHAR(120)    NOT NULL,
    body        TEXT            NOT NULL,              -- {{name}}, {{date}} placeholders
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wa_templates_key (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_email    VARCHAR(180)    NOT NULL,
    subject     VARCHAR(255)    NOT NULL,
    template    VARCHAR(60)     NULL,
    status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    error       VARCHAR(255)    NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at     DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_email_log_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_batches (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    entity          ENUM('leads','candidates','jobs','employers') NOT NULL,
    original_name   VARCHAR(200)    NOT NULL,
    storage_path    VARCHAR(255)    NOT NULL,
    total_rows      INT UNSIGNED    NOT NULL DEFAULT 0,
    imported_rows   INT UNSIGNED    NOT NULL DEFAULT 0,
    skipped_rows    INT UNSIGNED    NOT NULL DEFAULT 0,  -- likely duplicates, not imported by choice
    failed_rows     INT UNSIGNED    NOT NULL DEFAULT 0,
    status          ENUM('uploaded','previewed','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
    mapping_json    JSON            NULL,
    report_path     VARCHAR(255)    NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_batches_public_id (public_id),
    CONSTRAINT fk_import_batches_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_import_batches_user   FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_rows (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    row_number      INT UNSIGNED    NOT NULL,
    raw_json        JSON            NOT NULL,
    status          ENUM('pending','imported','skipped','failed') NOT NULL DEFAULT 'pending',
    error           VARCHAR(500)    NULL,
    created_record_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_import_rows_batch (import_batch_id, status),
    CONSTRAINT fk_import_rows_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE export_jobs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       CHAR(26)        NOT NULL,
    report          VARCHAR(60)     NOT NULL,
    filters_json    JSON            NULL,
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    storage_path    VARCHAR(255)    NULL,
    row_count       INT UNSIGNED    NULL,
    requested_by    BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME        NULL,
    expires_at      DATETIME        NULL,              -- file purged by cleanup cron
    PRIMARY KEY (id),
    UNIQUE KEY uq_export_jobs_public_id (public_id),
    KEY idx_export_jobs_user (requested_by, created_at),
    CONSTRAINT fk_export_jobs_user FOREIGN KEY (requested_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cron run ledger + advisory locks (spec section 46).
CREATE TABLE cron_runs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_name    VARCHAR(60)     NOT NULL,
    started_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME        NULL,
    status      ENUM('running','success','failed') NOT NULL DEFAULT 'running',
    items_processed INT UNSIGNED NOT NULL DEFAULT 0,
    message     VARCHAR(500)    NULL,
    PRIMARY KEY (id),
    KEY idx_cron_runs_job_time (job_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cron_locks (
    job_name    VARCHAR(60)     NOT NULL,
    locked_at   DATETIME        NOT NULL,
    locked_by   VARCHAR(120)    NOT NULL,              -- host/pid token
    expires_at  DATETIME        NOT NULL,
    PRIMARY KEY (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public contact / job-application submissions (spec sections 56, 57, 69).
CREATE TABLE public_enquiries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type            ENUM('contact','job_apply','travel_enquiry') NOT NULL,
    job_id          BIGINT UNSIGNED NULL,
    tour_package_id BIGINT UNSIGNED NULL,
    name            VARCHAR(150)    NOT NULL,
    phone           VARCHAR(30)     NOT NULL,
    email           VARCHAR(180)    NULL,
    message         VARCHAR(1000)   NULL,
    meta_json       JSON            NULL,              -- utm, page, referrer
    ip_address      VARBINARY(16)   NULL,
    status          ENUM('new','reviewed','converted','spam') NOT NULL DEFAULT 'new',
    lead_id         BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_public_enquiries_status (status, created_at),
    KEY idx_public_enquiries_job (job_id),
    CONSTRAINT fk_public_enquiries_job  FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE SET NULL,
    CONSTRAINT fk_public_enquiries_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public blog (migration 0016): the author's text, the generated safe HTML, and the publishing state.
CREATE TABLE blog_posts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id     CHAR(26)        NOT NULL,
    slug          VARCHAR(160)    NOT NULL,
    title         VARCHAR(180)    NOT NULL,
    excerpt       VARCHAR(300)    NULL,
    body_source   MEDIUMTEXT      NOT NULL,
    body_html     MEDIUMTEXT      NOT NULL,
    status        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at  DATETIME        NULL,
    author_id     BIGINT UNSIGNED NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blog_public_id (public_id),
    UNIQUE KEY uq_blog_slug (slug),
    KEY idx_blog_public (status, published_at),
    CONSTRAINT fk_blog_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- END SCHEMA
-- Migrations live in database/migrations/NNN_*.sql and are applied in order by
-- a tracked migrations runner (database/migrations/_migrations table below).
-- =============================================================================

CREATE TABLE schema_migrations (
    version     VARCHAR(30)  NOT NULL,
    filename    VARCHAR(160) NOT NULL,
    applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
