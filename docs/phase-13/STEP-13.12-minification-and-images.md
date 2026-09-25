# Step 13.12 — Minification, compression and the image optimizer

Goal: ship less to the browser, everywhere, without a Node runtime on the server (Hostinger shared hosting): everything is either built once on the developer's machine and committed, or done by PHP at request time.

## 1. CSS and JavaScript — `npm run build`
`scripts/build.mjs` now runs **Tailwind (purged) → esbuild** for CSS and **esbuild** for `resources/js/app.js`, then content-hashes the results (`app.<hash>.css/js`), writes `manifest.json`, and writes **pre-compressed `.br` (Brotli level 11) and `.gz` (gzip level 9)** copies next to each. Old hashed files are removed.

| Asset | Source | Minified | Brotli |
|---|---|---|---|
| app.css | 65.5 KB | 65.4 KB | **7.4 KB** |
| app.js | 10.4 KB | 5.4 KB | **1.7 KB** |

(Tailwind's own output was already minified, so the big CSS win is compression, not minification.) `app.js` is a plain script, so esbuild never renames its top-level names — inline handlers and `window.*` hooks keep working. `node scripts/build.mjs --check` (run by `AssetsUpToDateTest`) fails if the committed CSS **or JS** is stale. `esbuild` is a dev dependency only; nothing runs on the server. *Note:* any new PHP/view file that contains a word that happens to be a Tailwind utility (`block`, `table`…) changes the compiled CSS — the guard test tells you to rebuild.

## 2. Serving them — `public/.htaccess`
- When the browser sends `Accept-Encoding: br` (or `gzip`) and the `.br`/`.gz` copy exists, Apache/LiteSpeed serves that file as-is with `Content-Encoding` and `Vary: Accept-Encoding` — no CPU per request, better ratio than on-the-fly deflate (which stays as the fallback for HTML/JSON/SVG).
- **Caching fixed:** `immutable`, one year, now applies only to content-hashed build files. Images/fonts (which keep their name when replaced, e.g. `og-default.png`) get 30 days instead of forever.

## 3. HTML — `HtmlMinifier` + `MinifyHtml` middleware
Runs on every rendered page (public and staff), outermost in the web groups. It removes comments (keeping IE conditional ones), collapses whitespace, drops whitespace that only touches block-level tags, and tidies the gaps between attributes — while keeping the single visible space between inline elements, never touching quoted attribute values, and copying `<pre>`, `<textarea>`, `<script>` and `<style>` byte for byte. Anything not well-formed enough to be sure of (e.g. an unclosed `<script>`) is returned unchanged. It only touches complete `text/html` bodies (not downloads, JSON, CSV, streams, empty bodies). Off when `HTML_MINIFY=0` or while `APP_DEBUG=true`, so "view source" stays readable in development. Typical saving on the demo data: dashboard 45.9 → 43.4 KB before compression.

## 4. Images — `scripts/optimize-images.php` (+ `ImageOptimizer`)
`php -d extension=gd scripts/optimize-images.php [paths] [--dry-run] [--quality=82] [--max-width=1600] [--webp] [--lossy]`
- JPEG re-encoded progressive at the chosen quality with EXIF/ICC/thumbnails dropped — **after applying the camera's rotation flag** so phone photos don't turn sideways (mirrored orientations are left alone). PNG at maximum compression with transparency kept; `--lossy` offers a 256-colour palette and keeps whichever file is smaller. WebP re-encoded. `--max-width` only scales down. `--webp` writes a `.webp` sibling when ≥ 10% smaller.
- Replaces a file only when the result is ≥ 3% smaller, atomically (temp file + rename). Type is checked by content; absurd dimensions ("decompression bombs"), non-images, GIFs and symlinks are skipped. Only paths inside the project are accepted. Prints a per-file table and the total saved.
- Uploaded candidate documents were already re-encoded on upload (Phase 12); this tool is for the site's own artwork.

## Tests (+34; 1111 total, 4 135 assertions with GD)
`HtmlMinifierTest` (7: exact output, byte-for-byte raw blocks, attribute values, conditional comments, refuses malformed input, idempotent, structure of a templated page unchanged and ≥ 20% smaller), `HtmlMinifyPagesTest` (4: **every public page and 19 staff pages render identically before and after** — compared as a DOM fingerprint of elements, attributes and text — and get smaller; the middleware's on/off/skip rules; wiring), `ImageOptimizerTest` (12, needs GD, skipped otherwise: shrink, idempotent, dry run, alpha preserved, lossy palette never bigger, max-width down-not-up, webp sibling, refusals incl. a crafted 30000×30000 PNG, EXIF orientation read in both byte orders, rotation applied, mirrored left alone), and two `WebServerConfigTest` checks (built files are hashed by content, `.gz` decompresses to exactly the served file, Brotli copy exists and beats gzip, JS really minified, stale builds removed; the `.htaccess` rules and that only hashed files are `immutable`).
