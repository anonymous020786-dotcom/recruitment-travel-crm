# Step 13.8 — Public blog

Articles about visas, medicals, jobs and destinations bring search traffic to the public site; this adds the blog end to end.

## For staff — Admin → Blog (`blog.view` to list, `blog.manage` to write)
- **Write a post**: title, optional web address, optional summary, and the article in a small Markdown subset (blank line = paragraph, `## Heading`, `### Sub-heading`, `- bullet`, `1. numbered`, `**bold**`, `*italic*`, `[text](/overseas-jobs)`). The edit page shows a preview of the generated HTML next to the form.
- **Workflow**: draft → **Publish** → (Move back to draft | Archive); an archived post is restored to draft before it can be edited. Every step is audited (`blog_created/updated/published/unpublished/archived`).
- **Slug**: made from the title (`-2`, `-3` on collision; `post-xxxxxx` for a title with no Latin letters). Editable until the post is first published, then frozen — links and rankings depend on it.
- `published_at` is set the first time a post goes live and kept through unpublish/republish, so the byline date is stable.
- Managers get `blog.*` by default; admins and super admins have everything. (Adjustable in Admin → Roles.)

## For the public — `/blog`, `/blog/{slug}`
Session-free and cacheable like the jobs/packages pages. A visitor can only ever see a `published` post (one `PUBLIC` predicate in `BlogRepository`); drafts, archived posts and unknown slugs are a 404. Article pages carry a canonical link, a description (the summary, else the start of the article), `BlogPosting` JSON-LD and links on to jobs / packages / contact. The blog is in the footer and in `sitemap.xml` (with `lastmod`).

## Safety
What the author types is **never** trusted as HTML. `BlogFormatter` escapes everything first and then emits only `p br h3 h4 ul ol li strong em a`; a link must be `http(s)://…` or a site-relative `/path` (never `javascript:`, `data:` or `//host`), and its URL can no longer contain a quote, angle bracket or asterisk. The text source is kept (`body_source`) so posts stay editable; the HTML is regenerated on every save.

## Data / deployment
Migration `0016_blog.sql` (also mirrored in `database/schema/schema.sql`): `blog_posts` **and** the two new permissions, granted to super_admin / admin / manager — so an already-installed database gets them from `php scripts/migrate.php` without re-running the seeder (which would reset custom role grants made in Admin → Roles). Applied to the dev database.

## Tests
`BlogFormatterTest` (19, incl. a hostile-input set: script/img/iframe/svg, `javascript:`/`data:`/`//host` links, quote and apostrophe breakouts, entity smuggling, nested brackets — output may contain only allowlisted tags and only safe anchors) and `BlogTest` (11: draft creation and generated HTML, unique slugs, validation, permission, slug freeze, full workflow incl. stable date and 404s, staff screens end to end, public visibility of draft/archived/future posts, escaping, canonical + JSON-LD, sitemap, footer link/empty state). Route audit updated. Live smoke (php -S): 11/11.
Full suite: **1072 tests, 3 808 assertions** (3 skipped: GD-only image tests). Compiled CSS rebuilt.
