# Step 14.7b — Redirects, snippets and menus (Admin → Pages)

Three site-wide tools, in tabs beside Pages. Migration **0024** (`cms_redirects`, `cms_snippets`, `cms_menu_items`). Everyone with `cms.view` can see them; because they change what every visitor sees, **changing** them needs `cms.publish`. Everything is audited (module `cms`).

## Redirects
- Old address → new address with **301 / 302 / 307 / 308**, or **410 Gone** (a friendly "removed" page, `noindex`).
- Old addresses are normalised: lowercase, one leading slash, no trailing slash, query/fragment dropped, your own domain stripped if pasted in full. Only plain ASCII paths.
- Refused: an address a screen already answers (including route patterns like `/overseas-jobs/…`), an address a page uses, duplicates, the home page, `..` tricks, unsafe targets (`javascript:`, `//host`, plain `http://`), loops, and **chains** (point straight at the final address).
- When a new redirect moves an address that older redirects point at, those are **re-pointed automatically** (no chains ever build up).
- "Keep ?query parameters" (on by default) so campaign tracking survives the redirect.
- Hit counter and last-used date per redirect; switch on/off; edit; search.
- **Bulk import**: up to 500 lines `old, new[, code]` (comma, tab or semicolon); good lines are saved, bad lines are listed with the reason; existing old addresses are updated.
- Redirects are answered by the router fallback before pages, and only when no route matched — they can never hide a screen. Permanent redirects are cacheable for an hour.

## Snippets
- Reusable blocks inserted with `{{snippet:key}}` on any page — change once, updated everywhere.
- Same safe formatting as pages, including blocks like `{{contact}}`, `{{jobs:3}}` (but a snippet cannot include another snippet — refused on save and ignored at render time, so no loops).
- Switch off to hide it everywhere; "used on" list per snippet; a snippet in use cannot be deleted.

## Menus
- Header menu and footer links for the public site, up to 12 links each; site path, `https://`, `#anchor`, `mailto:` or `tel:`; open in a new tab; hide/show; move up/down.
- While a menu has no active link, the built-in links are shown (so a fresh install never has an empty menu). Loaded once per request; a database problem falls back to the built-in links.

## Tests
`CmsSiteToolsTest` (13).
