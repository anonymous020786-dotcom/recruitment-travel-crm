-- Migration 0023 — CMS pages (Admin → Pages) with revisions and shareable preview links.
--
-- A page lives at `path` on the public site (e.g. `visa-services` or `services/visa`, one to three lowercase segments). It is
-- public only when status = 'published', it is not in the trash, and now lies inside [publish_at, unpublish_at) — so a page
-- can be scheduled to go live and to disappear. `body_source` is what the editor typed (a Markdown subset); `body_html` is
-- generated from it on every save by App\Cms\CmsFormatter (escaped, allowlisted markup only) and is all the public site prints.
-- Every save writes a row to `cms_revisions`; `version` counts saves and doubles as the edit-conflict check.

CREATE TABLE cms_pages (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id         CHAR(26)        NOT NULL,
    path              VARCHAR(150)    NOT NULL,
    title             VARCHAR(180)    NOT NULL,
    summary           VARCHAR(300)    NULL,
    body_source       MEDIUMTEXT      NOT NULL,
    body_html         MEDIUMTEXT      NOT NULL,
    template          ENUM('default','wide','landing') NOT NULL DEFAULT 'default',
    status            ENUM('draft','review','published','archived') NOT NULL DEFAULT 'draft',
    publish_at        DATETIME        NULL,
    unpublish_at      DATETIME        NULL,
    published_at      DATETIME        NULL,
    meta_title        VARCHAR(120)    NULL,
    meta_description  VARCHAR(200)    NULL,
    focus_keyword     VARCHAR(80)     NULL,
    canonical_url     VARCHAR(300)    NULL,
    robots            ENUM('index','noindex') NOT NULL DEFAULT 'index',
    og_title          VARCHAR(120)    NULL,
    og_description    VARCHAR(200)    NULL,
    featured_image    VARCHAR(300)    NULL,
    featured_alt      VARCHAR(160)    NULL,
    in_sitemap        TINYINT(1)      NOT NULL DEFAULT 1,
    sitemap_priority  DECIMAL(2,1)    NOT NULL DEFAULT 0.5,
    sitemap_changefreq ENUM('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
    faq               MEDIUMTEXT      NULL,               -- JSON list of {q, a}
    word_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    version           INT UNSIGNED    NOT NULL DEFAULT 1,
    author_id         BIGINT UNSIGNED NULL,
    updated_by        BIGINT UNSIGNED NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME        NULL,               -- in the trash (path stays reserved so a restore is always safe)
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_page_public_id (public_id),
    UNIQUE KEY uq_cms_page_path (path),
    KEY idx_cms_page_public (status, publish_at, unpublish_at),
    KEY idx_cms_page_list (deleted_at, status, updated_at),
    CONSTRAINT fk_cms_page_author  FOREIGN KEY (author_id)  REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_cms_page_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_revisions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id      BIGINT UNSIGNED NOT NULL,
    version      INT UNSIGNED    NOT NULL,
    title        VARCHAR(180)    NOT NULL,
    body_source  MEDIUMTEXT      NOT NULL,
    snapshot     MEDIUMTEXT      NOT NULL,              -- JSON: every other editable field as saved
    note         VARCHAR(200)    NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_rev (page_id, version),
    CONSTRAINT fk_cms_rev_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_rev_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links that show an unpublished page to someone without an account; only the hash of the token is stored.
CREATE TABLE cms_preview_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id      BIGINT UNSIGNED NOT NULL,
    token_hash   CHAR(64)        NOT NULL,
    expires_at   DATETIME        NOT NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_preview_token (token_hash),
    KEY idx_cms_preview_page (page_id),
    CONSTRAINT fk_cms_preview_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_preview_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Writers draft and submit; publishers (cms.publish) put pages live, schedule and archive them.
INSERT IGNORE INTO permissions (name, module, label) VALUES
    ('cms.view',    'cms', 'View website pages'),
    ('cms.manage',  'cms', 'Write and edit website pages; submit them for review'),
    ('cms.publish', 'cms', 'Publish, schedule, unpublish and archive website pages');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('cms.view', 'cms.manage', 'cms.publish')
WHERE r.name IN ('super_admin', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('cms.view', 'cms.manage')
WHERE r.name = 'manager';
