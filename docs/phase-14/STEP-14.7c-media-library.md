# Step 14.7c — Media library (Admin → Pages → Media)

Public images for pages, snippets, featured images and link previews. Migration **0025** (`cms_media`), config `config/cms.php` (`CMS_MEDIA_ROOT`, `CMS_MEDIA_MAX_KB`, default 8 MB).

## What it does
- Upload (writers: `cms.manage`), describe (alt text + title), search, and delete (publishers: `cms.publish`).
- Each upload gets a **WebP copy** and a **400 px thumbnail**; images wider/taller than **2560 px** are scaled down; JPEG EXIF rotation is applied.
- The same file uploaded twice is stored once (SHA-256 of the bytes).
- The screen gives the ready-to-paste page code `![description](/media/…)` and the plain address (for the featured image or Admin → Settings → link-preview image).
- "Used in" list (pages — content or featured image —, snippets, the settings share image); a file in use cannot be deleted; deleting removes every copy from disk.

## Security
- Size checked before anything is read; type decided from the **bytes** (finfo + magic numbers), never the name or browser: JPEG, PNG, WebP, GIF only — SVG, HTML, PDF and scripts are refused.
- Pixel count checked from the header **before decoding** (decompression bombs refused).
- Every image is **decoded and re-encoded** with GD: EXIF/GPS metadata and anything appended after the image data (PHP/HTML "polyglots") are gone — tested.
- Random file names under `public/media/<yyyy>/<mm>/`; the uploader's file name is only kept (cleaned) as a label. Deletion only touches paths that look like our own files.
- `public/media/.htaccess`: no script execution, `nosniff`, a sandboxing CSP, and one-year immutable caching (names never change). The folder's contents are git-ignored.

## Limits
- Files are stored on the web server's disk (they must be publicly readable; the S3/R2 buckets from step 14.3 are private by design). GIF animations become a still PNG.

## Tests
`MediaLibraryTest` (9) — real images generated with GD, hostile files, bombs, dedupe, usage/delete, permissions and the screen.
