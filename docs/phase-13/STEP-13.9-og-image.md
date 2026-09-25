# Step 13.9 — Link-preview image (`og:image`)

Sharing a page on WhatsApp, Facebook or LinkedIn showed a bare link: no public page had an `og:image` (the HTML audit flagged it as a warning).

## What changed
- **Shipped card** `public/assets/og-default.png` — 1200×630 PNG (16 KB, text-free so it suits any agency name), used on every public page. Generated once with GD (not at runtime, so nothing depends on GD being present on the server).
- **Layout** (`layouts/public.php`): `og:image` is the page's own `ogImage` if a page passes one, else the **Link-preview image** setting, else the shipped card; site-relative paths are made absolute. Also `og:image:alt` (business name), `twitter:card=summary_large_image`, and `og:image:width/height` — only when the shipped card is used, since the size of a custom image is unknown.
- **Admin → Settings → Business profile → Link-preview image** (`business.share_image`, new `url` setting type). Accepts only a `https://` address or a site path (`/assets/my-card.jpg`) ending in `.png/.jpg/.jpeg/.webp/.gif`; `http://`, `//host`, `javascript:`, `data:`, query strings, quotes/angle brackets/backslashes, other extensions and multi-line values are refused. Empty = the standard card. A custom image is uploaded by the agency to the site or any https host (no upload pipeline for public files is added here).

## Tests
3 new in `SettingsTest`: the shipped card is a real 1200×630 PNG and every public page (`/`, about, contact, jobs, packages, blog) carries the absolute `og:image`, size and Twitter card; the setting overrides it (https URL, site path → absolute) and clearing it restores the card; nine hostile/invalid values are rejected. Live check with `php -S`: tags present, image served as `image/png`.
Full suite: **1075 tests, 3 842 assertions** (3 skipped: GD-only image tests).
