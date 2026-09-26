-- Migration 0025 — the media library (Admin → Pages → Media): public images for pages, snippets and link previews.
--
-- Files live under public/media/<yyyy>/<mm>/<random>.<ext> (config cms.media_root) with random names, so they can be cached
-- forever. Every upload is re-encoded with GD (strips metadata and anything hidden after the image data), capped in size,
-- and gets a WebP copy and a thumbnail. `sha256` is of the uploaded bytes: uploading the same file again reuses it.

CREATE TABLE cms_media (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id     CHAR(26)        NOT NULL,
    path          VARCHAR(200)    NOT NULL,               -- relative to the media root, e.g. 2026/09/01j….jpg
    webp_path     VARCHAR(200)    NULL,
    thumb_path    VARCHAR(200)    NULL,
    original_name VARCHAR(200)    NOT NULL,
    mime          VARCHAR(40)     NOT NULL,
    size_bytes    INT UNSIGNED    NOT NULL,
    width         INT UNSIGNED    NOT NULL,
    height        INT UNSIGNED    NOT NULL,
    alt_text      VARCHAR(200)    NULL,
    title         VARCHAR(120)    NULL,
    sha256        CHAR(64)        NOT NULL,
    uploaded_by   BIGINT UNSIGNED NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_media_public_id (public_id),
    UNIQUE KEY uq_cms_media_sha (sha256),
    KEY idx_cms_media_created (created_at),
    CONSTRAINT fk_cms_media_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
