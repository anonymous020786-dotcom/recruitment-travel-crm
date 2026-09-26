# Step 14.7a — CMS pages (Admin → Pages)

Website pages with a real editorial workflow. Migration **0023** (`cms_pages`, `cms_revisions`, `cms_preview_tokens`, permissions `cms.view` / `cms.manage` / `cms.publish`) — run `php scripts/migrate.php`. Admin and super admin hold all three; managers can write (`cms.view`, `cms.manage`) but not publish.

## Features in this step (numbered, so the count is honest)

**Writing**
1. Create / edit / list pages with a title, summary and a Markdown-style body (`CmsFormatter`).
2. Headings (H2–H4) get unique anchor ids automatically.
3. Lists, numbered lists, block quotes, horizontal rules, fenced code blocks, inline code.
4. Tables with column alignment (`|:---|---:|:---:|`), padded and escaped.
5. Images with alt text and caption (`https://` or site paths only), lazy-loaded.
6. Safe links: `https://`, site paths, `#anchor`, `mailto:`, `tel:` — nothing else becomes a link.
7. Shortcodes (allowlist, arguments checked): `{{jobs:N}}` latest jobs, `{{packages:N}}` latest packages, `{{contact:Label}}`, `{{button:/url|Label}}`, `{{phone}}`, `{{whatsapp}}`, `{{email}}` (from Admin → Settings), `{{toc}}` table of contents, `{{faq}}`, `{{youtube:ID}}` (link card, no third-party iframe).
8. FAQ editor (up to 20 Q&A) rendered as accessible `<details>` and sent as FAQPage structured data.
9. Three layouts: standard column, wide, landing (no menu or footer).
10. Featured image + alt text (also used for link previews).
11. Word count stored per save.
12. Formatting help built into the editor.

**Addresses**
13. Addresses of one to three lowercase segments (`services/visa`), generated from the title when left empty.
14. Unique addresses; a route or system folder (`admin`, `blog`, `pay`, `sitemap.xml`…) can never be taken — read live from the route table (`ReservedPaths`).
15. The address freezes once a page has been published.
16. Pages are served by the router's new **fallback**: they answer only when no route matched, so a page can never shadow a real screen, and a wrong method on a real route is still a 405.

**Workflow**
17. Draft → **In review** → published; publishers are notified when a page is submitted.
18. Send back from review.
19. Publish now or **schedule** a go-live time (business time zone).
20. **Automatic take-down** time — no cron needed; visibility is checked on every request.
21. Unpublish, archive, restore to draft.
22. Writers cannot change or trash a live page — only publishers can.
23. The first publication date is kept through unpublish/republish.
24. **Trash** with restore; pages in the trash keep their address; auto-purge after 30 days (nightly cleanup); "delete for good" needs a publisher **and** a fresh password.
25. Duplicate as a new draft (`…-copy`, `…-copy-2`).
26. Bulk actions (submit, publish, unpublish, archive, trash, restore) — each page judged on its own, reasons shown for the skipped ones.
27. List tabs with counts: All, Drafts, In review, Live, Scheduled, Taken down, Archived, Trash; search by title or address.

**Versions**
28. Every save is a version (the newest 50 kept) with who and when.
29. **Edit-conflict detection**: a save based on an old version is refused and nothing is lost silently.
30. Compare any two versions line by line (added/removed counts).
31. Restore any version as the newest (settings included, the address never travels back).
32. Saving unchanged content creates no version.

**Preview**
33. Staff preview of any saved page exactly as visitors would see it (`noindex`, not cached).
34. **Shareable preview links** for people without an account: 7 days, at most 5 active per page, only a SHA-256 hash stored, revocable, dead once the page is trashed.

**SEO**
35. Search title, meta description, focus keyword.
36. Canonical URL override, `noindex` switch.
37. Separate share (Open Graph) title and description.
38. Per-page sitemap inclusion, priority and change frequency — `sitemap.xml` lists only live, indexable pages.
39. WebPage + BreadcrumbList structured data on every page (and FAQPage when there are questions).
40. **SEO checklist with a score** beside the editor: title/description length, content length, sub-headings, internal links, image + alt text, sentence length, focus keyword placement (title, description, first paragraph, address, headings), duplicate titles, noindex warning.
41. Search-result preview beside the editor.

**Speed & safety**
42. Public pages are cacheable (`max-age=300`) with **ETag / 304 Not Modified**.
43. Output is escape-first: a 20-payload hostile corpus is tested to produce no script, iframe, SVG, event handler or `javascript:` URL.
44. Editors cannot forge placeholders or use the formatter's internal markers.
45. Every change is audited (module `cms`).
46. `cms.view` / `cms.manage` / `cms.publish` enforced on every route and again inside the service.
47. Preview links are rate-limited and sent with `Referrer-Policy: no-referrer`.

## Tests
`CmsFormatterTest` (14) and `CmsPagesTest` (28). Guard tests updated: the public `GET /preview/{token}` route is on the audited anonymous list.

## Still to come (14.7b)
Menus editor, 301 redirects manager (and automatic redirects when a draft's address changes), reusable snippets for `{{snippet:key}}` (currently renders nothing), a media library, and bringing the blog onto the same revisions/scheduling features.
