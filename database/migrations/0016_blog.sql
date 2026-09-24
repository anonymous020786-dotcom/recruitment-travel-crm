-- Migration 0016 — the public blog.
--
-- `body_source` is what the author typed (a small Markdown subset); `body_html` is generated from it on save by
-- BlogFormatter (escaped, allowlisted markup only) and is the only thing the public site prints.
-- A post is public only when status = 'published' AND published_at <= now, so publishing can be scheduled.
-- The slug is the public URL and is frozen once the post has been published.

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

-- New permissions reach an already-installed database here (the seeder only runs at install time).
INSERT IGNORE INTO permissions (name, module, label) VALUES
    ('blog.view',   'blog', 'View blog posts'),
    ('blog.manage', 'blog', 'Write, publish and archive blog posts');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('blog.view', 'blog.manage')
WHERE r.name IN ('super_admin', 'admin', 'manager');
