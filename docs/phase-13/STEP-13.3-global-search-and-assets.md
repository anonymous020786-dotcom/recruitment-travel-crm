# Step 13.3 — Global search, and a guard against stale CSS

## Global search (`/search`, permission `search.global`)

The permission existed but there was no search: the topbar icon was a dead link (removed in 13.1). Now:

- A search box in the top bar (a search icon on phones) and a results page grouped by module: **leads, candidates, applications,
  employers, jobs, invoices, payments, tour bookings, website enquiries**. Each group shows the 5 best hits with number, name and
  phone, and "See all N" opening that module's own list with the same `?q=`.
- `GlobalSearchService` does **no querying of its own**. Every section calls the same scoped `paginate()` that powers that module's list
  screen, so branch isolation, soft-delete rules and matching (name, phone prefix, email, reference number; `LIKE` wildcards escaped)
  are exactly the module's. A section the user has no permission for is never queried at all. Website enquiries belong to no branch, so
  permission alone decides.
- Two-character minimum, 60-character cap, whitespace normalised, output escaped, throttled like the dashboard.

Tests (`GlobalSearchTest`, 7): finds by name, phone prefix and lead number; **another branch is invisible** (its name and public id
never appear); an org-wide administrator sees all branches; sections follow permissions (a manager sees enquiries, accounts do not);
empty / too-short / no-result / XSS / SQL-metacharacter / `%_%` queries; normalisation and cap; more than five hits link to the full list;
the top bar box renders and anonymous users are sent to sign in. Live: a lead created in the database was found, linked to
`/leads/<public id>`, and the search pages audit with 0 errors.

## Stale-CSS guard

Tailwind only compiles the classes it finds in the views **when it is built**. Rebuilding showed the committed stylesheet was out of
date for the Phase 13 views (64,016 → 65,818 bytes): pages would have rendered unstyled in those spots. Fixed by rebuilding, and it can
no longer drift silently:

- `node scripts/build.mjs --check` builds to a temp file and exits 1 if `public/assets/build/app.css` differs.
- `AssetsUpToDateTest` runs it wherever Node and Tailwind are installed (skipped on hosts that only carry the committed build). Verified
  both ways: a view with an uncompiled class makes it fail; removing it makes it pass.

**Rule from now on: after changing any view's classes, run `npm run build` and commit the new hashed CSS.**

Full suite: **1010 tests, 3 433 assertions**.
