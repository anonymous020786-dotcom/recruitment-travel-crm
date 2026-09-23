# Phase 8 · Step 8.2 — Tour packages

The travel-agency catalogue: what the company sells. Tour **bookings** (a customer buying a package) are Step 8.3; the public marketing pages that read `is_public` packages come with the public-site work. This step is independent of the recruitment pipeline — a tour package has nothing to do with candidates or branches.

## What exists
| Layer | Files |
|---|---|
| Models | `TourPackage`, `TourPackageItem` (immutable read models; `durationLabel()`, `priceLabel()`) |
| SQL | `TourPackageRepository` (search / filters / sort, guarded `transition()`, soft delete, `activeOptions()` pick-list for booking), `TourPackageItemRepository` |
| Rules | `TourPackageService`, `TourPackageValidator`, `TourPackagePolicy`, `tour_package` state machine |
| UI | `/tours/packages` catalogue, create, edit, package page (details, itinerary, lifecycle, public listing) |

Tables `tour_packages` and `tour_package_items` already existed (initial schema); **no migration in this step**.

## Rules
- **Catalogue is company-wide.** There is no `branch_id` on the table, so `TourPackagePolicy` is permission-only (`tours.packages.view|create|edit|delete|publish`). The `travel` role (tours desk) and managers have them; `read_only` can view; `counselor` has none.
- **Lifecycle** (`config/statuses.php` → `tour_package`): `draft ⇄ active`, either → `archived`, `archived → active` only. The write is `WHERE status = :from`, so two people moving one package get one winner and the other a "changed just now" message.
- **Public = active + priced + itinerary.** `setPublic(true)` is refused unless the package is `active`, has a price, and has at least one itinerary line (the SEO spec wants real price and itinerary on public pages). It is withdrawn automatically when the package leaves `active`, when an edit removes the price, when the last itinerary line is removed, and on delete — the page can never be public and incomplete.
- **Archived is read-only** — no edits, no itinerary changes — until reactivated.
- **Delete** is soft (`deleted_at`, `is_public = 0`) and only from `draft` or `archived`; the row stays so future bookings keep their history. Lookups and the catalogue ignore deleted rows.
- **Slug** = slugified name + the last 6 characters of the ULID: unique without a lookup, and it **never changes on rename**, so a public URL is stable.
- **Itinerary**: day number optional (1–365), title required, description optional; ordered by day, then insertion order, with undated lines last; capped at `TourPackageService::MAX_ITEMS` (60). A line can only be removed through its own package.
- **Input safety**: inclusions / exclusions / terms are entered as plain text and turned into escaped paragraph HTML in the validator (`HtmlSanitizer::fromPlainText`), so the `*_html` columns never hold raw markup. A price needs a currency; nights cannot exceed days; price is stored with 2 decimals.
- Everything is audited (`module = tours`): created, updated (before/after snapshot), status_changed, published / unpublished, deleted, item_added / item_removed.

## Housekeeping in this step
- Sidebar **Tours** now opens the catalogue (`/tours/packages`, gated on `tours.packages.view`); a Bookings entry arrives with Step 8.3. It used to point at `/tours/bookings`, which does not exist yet.
- `robots.txt` (`config/seo.php`) now also disallows the private CRM paths `/tours/packages`, `/travel`, `/placements` and `/flights` (`/travel$` so a future public `/travel-…` page is not caught).

## Verification
- **13 new tests** (`TourPackageServiceTest`): slug stability and uniqueness; the validator's rejections and HTML escaping; archived read-only and reactivation; the status machine; every publish precondition; auto-unpublish on leave-active, price removal and last-line removal; soft delete (row kept, gone from lookups, audited); itinerary ordering, scoping and cap; permissions for `read_only` / `counselor` (denied) and `travel` (allowed); catalogue search by name / destination / departure point, status and visibility filters, price sort, and the active-only pick-list. Search placeholders are distinct (the PDO reuse bug from earlier steps).
- Full suite: **676 tests, 1723 assertions, all green.**
- HTTP smoke test against `php -S` (27 checks): login; catalogue, create and edit forms render and the edit form is pre-filled; a price-without-currency post bounces back and creates nothing; create → itinerary line → activate → publish (a draft is refused first); script tags in inclusions come back escaped; removing the price through the edit form unpublishes the package; search + filters + sort; an active package cannot be deleted; an archived one refuses itinerary edits and can be soft-deleted, after which its page is a 404; an unauthenticated POST gets 419; `robots.txt` lists the new disallow. Smoke rows were removed afterwards.

## Not in this step
Editing an existing itinerary line in place (remove + re-add today), drag-to-reorder, package images / gallery, and the public listing pages themselves.
