# Step 12.7 — Accessibility, responsiveness and SEO audit

`App\Support\HtmlAudit` (DOM rule engine) + `php scripts/html-audit.php --base=<url>` signs in, fetches 34 real pages
(CRM screens incl. detail pages with data, sign-in, public site) and applies the rules. It is structural: it cannot judge
colour contrast or true layout — do a browser pass (Lighthouse / a phone) once on the deployed site.

Rules: `<html lang>`, title, viewport (and no zoom lock), exactly one `<h1>`, heading order, one `<main>`, labelled `<nav>`s,
skip link, `<img alt>`/size, every form control labelled, password `autocomplete`, POST forms carry a CSRF field, every
button/link has an accessible name, no inline handlers / clickable divs, duplicate ids, dangling `aria-*` references,
`target=_blank` has `rel=noopener`, data tables have `<th>`, are inside a horizontally scrolling wrapper (phones) and are
named, no fixed pixel widths, CRM pages must be `noindex`, and for public pages: title/description length, absolute
canonical, Open Graph, valid JSON-LD, not `noindex`.

## Results

First run against a database with real volume: **33 errors, 56 warnings**. Fixed:

| Finding | Where | Fix |
|---|---|---|
| Two `<h1>` on every signed-in page (top bar + page header) | layout | top bar title is a `<p>`; the page header is the one `<h1>` |
| Sidebar `<nav>` unlabelled (2 landmarks) | layout | `aria-label="Main"`; public `Primary` / `Footer` |
| `target="_blank"` without `rel="noopener"` | payment receipt, report print | added |
| No skip link, no `id` on `<main>` | public layout | added |
| Empty states used `<h3>` straight after the `<h1>` | `empty-state` component | `<h2>` |
| Public pages: no `og:url`, no structured data, 30-character contact description | public layout / contact | `og:url`; Organization + WebSite JSON-LD (`JSON_HEX_TAG`-safe); fuller description |
| **`sitemap.xml` advertised `/jobs` (redirects to sign-in) and `/travel-packages` (404); the public nav, footer and home CTA linked to them too** | sitemap, layout, home | removed until those public pages exist |
| Honeypot input flagged as unlabelled | audit rule | aria-hidden fields are skipped |

Final: **0 errors, 13 warnings** (`og:image` — needs a brand image, set when you have one; `table-name` — data tables have
headers but no caption, cosmetic). Responsive: viewport meta present everywhere, every table is inside `.table-wrap` (scrolls
sideways on a phone), no fixed-width inline styles, images sized.

## Regression guards (in the normal test suite)

- `HtmlAuditTest` (30): clean markup has no findings; 26 classic mistakes each trip their own rule; SEO completeness rules.
- `PageAuditTest` (9): the real public pages and the sign-in page rendered from the real views have **no errors**; the public
  layout keeps its skip link, JSON-LD, `og:url` and labelled navs; **every internal link on a public page opens for an anonymous
  visitor, and every sitemap URL does too** (matched against the real route table) — the dead-link bug above cannot come back.
- The signed-in screens need a session and data, so `scripts/html-audit.php` is the tool for them (run it before a release).

Full suite: **955 tests, 3 134 assertions** (3 skipped without GD).

## Known gaps (not done here)

- Public **Jobs**, **Travel packages** and **Blog** pages do not exist yet — the public site is Home / About / Contact. Building them
  is the scheduled public/SEO work; add their URLs to the sitemap and nav as they ship (the tests will keep the links honest).
- `og:image`: supply a brand image URL to the layout's `ogImage`.
- Colour contrast and a real device pass (see above).
