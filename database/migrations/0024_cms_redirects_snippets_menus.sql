-- Migration 0024 — CMS extras: redirects, reusable snippets and the public site's menus.
--
-- `cms_redirects` answer only when no route and no page matched (the router fallback), so they can never hide a real screen.
-- `from_path` is stored normalised: lowercase, leading slash, no trailing slash, no query string. 410 = "gone for good".
-- `cms_snippets` are reusable blocks inserted with {{snippet:key}}; like pages, the HTML is generated from what was typed.
-- `cms_menu_items` replace the built-in header/footer links once at least one active item exists for that menu.

CREATE TABLE cms_redirects (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_path    VARCHAR(300)    NOT NULL,
    to_url       VARCHAR(500)    NULL,               -- NULL only for 410
    status_code  SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    keep_query   TINYINT(1)      NOT NULL DEFAULT 1,
    hits         INT UNSIGNED    NOT NULL DEFAULT 0,
    last_hit_at  DATETIME        NULL,
    note         VARCHAR(200)    NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_redirect_from (from_path),
    CONSTRAINT fk_cms_redirect_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_snippets (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_name     VARCHAR(60)     NOT NULL,
    title        VARCHAR(120)    NOT NULL,
    body_source  MEDIUMTEXT      NOT NULL,
    body_html    MEDIUMTEXT      NOT NULL,
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    updated_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_snippet_key (key_name),
    CONSTRAINT fk_cms_snippet_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_menu_items (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu         ENUM('header','footer') NOT NULL,
    label        VARCHAR(60)     NOT NULL,
    url          VARCHAR(300)    NOT NULL,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    new_tab      TINYINT(1)      NOT NULL DEFAULT 0,
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cms_menu (menu, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
