# Step 13.1 — Public jobs and travel packages, the enquiry inbox, and the notifications screen

Until now the public site was Home / About / Contact, staff could not see the enquiries it collected, and the CRM's
notifications (reminders, approvals, alerts) had **no screen at all** — the topbar bell and a search icon linked to
routes that did not exist. This step closes all three loops.

## Public site (`/overseas-jobs`, `/travel-packages`)

- **Routes** — `GET /overseas-jobs`, `/overseas-jobs/{slug}`, `/travel-packages`, `/travel-packages/{slug}` are session-free
  and cacheable (`Cache-Control: public, max-age=300`). The apply / enquire *forms* live on their own pages
  (`…/apply`, `…/enquire`, `noindex`) because they carry a CSRF token and so need a session — crawlers never create sessions.
  (`/jobs` is the signed-in CRM screen, hence the public path `/overseas-jobs`.)
- **One visibility rule** — `PublicCatalogRepository` (`JOB_VISIBLE` / `PACKAGE_VISIBLE`): a job is public only if
  `is_public = 1`, `status = open`, not soft-deleted and its deadline has not passed; a package only if public, `active`,
  not deleted. Drafts, private, closed, expired and deleted rows are 404 everywhere (list, detail, apply form, apply POST,
  sitemap). Internal ids, job numbers, **the employer and the branch are never selected**.
- **Pages** — list with country chips, search (`noindex,follow`), pagination and an empty state; detail with facts, salary
  ("AED 1,800 – 2,400 / month"), benefits, requirements and sanitised description (re-sanitised on output);
  package detail with itinerary, inclusions, exclusions, terms. Home now shows the latest jobs and packages.
- **SEO** — per-page title, description, canonical (country pages get their own), `og:*`; **JobPosting** JSON-LD for Google
  Jobs (agency as hiring organisation, salary, `validThrough`, location) and **TouristTrip** JSON-LD for packages
  (`JSON_HEX_TAG`-safe); the sitemap lists every visible job and package with `lastmod`; nav and footer link to both sections.
- **Applying** — name + phone required (message/email optional). `PublicEnquiryService` is now the single anti-abuse pipeline for
  contact, job application and package enquiry: honeypot → validation → Turnstile → per-IP flood guard → store, with the job or
  package attached. A bot or flooder is thanked and ignored. New enquiries **notify** super admins, admins and managers.

## Enquiry inbox (`/enquiries`, permission `public_enquiries.view` / `.convert`)

List defaulting to the work queue (`new`), status cards, filters (status, type), search; detail page with contact links
(tel, WhatsApp, mailto), the message (escaped), job/package and where it came from. **Mark reviewed / spam / back to new**
(guarded on the status the user saw — two people cannot overwrite each other; audited) and **Turn into a lead**: creates a
lead (source *Website*, interested country/job and the message carried into the notes) in a branch the user can access, or —
if the person is already a lead — links the enquiry to that lead instead of creating a duplicate.

## Notifications screen (`/notifications`)

Own notifications only: list (all / unread), **open** (marks read, then goes to the record: leads, candidates, applications,
visas, invoices, refunds, payments, tour bookings, enquiries, exports; integrity/cron alerts go to `/admin/cron`; a deleted
record returns to the list with a message), mark all read, and a live **unread badge on the bell** (also in its accessible name).
The target screen still enforces its own permission and branch scope. The dead `/search` icon was removed (global search is not built).

## Tests (33 new, 988 in total)

`PublicSiteTest` (14): only open/public/current jobs and active/public packages appear (draft, private, closed, expired,
deleted, archived all 404 and are absent from the sitemap); no employer name or job number leaks; country filter, search
(noindex), junk input; titles escaped; job page details + valid JobPosting JSON-LD; apply creates a linked enquiry, needs
only name + phone, bots/hidden jobs/missing CSRF are refused; package enquiry; the whole catalogue passes the accessibility/SEO
audit; home features the latest items. `PublicFormatTest` (6). `EnquiryInboxTest` (13): permission (accounts 403, manager 200,
anonymous → sign-in), default queue/filters/search, XSS-safe rendering, guarded triage, conversion with context, no duplicate
lead, branch scope, notifications (own only, open → redirect + read, target resolution, others' notifications untouchable,
badge, mark all read, and staff are notified of a new enquiry but accounts are not). `RouteAuditTest` updated for the new routes.

**Live smoke** (php -S, real DB): all public pages 200 and 404 for an unknown slug; the sitemap lists the job and package; a real
application POST stored a linked `job_apply` enquiry; as admin the bell showed "1 unread", the notification opened the enquiry,
and *Create lead* produced `LEAD-…` from source Website with the job and country carried over; the audit of `/enquiries/{id}`,
`/notifications` and `/dashboard` found 0 errors, 0 warnings. Smoke rows were removed.

## Known gaps

- Blog is not built. `og:image` still needs a brand image URL. No CV upload on the public application form (name/phone only, by design).
- Public enquiries have no per-record public id (numeric ids, org-wide, permission-gated).
- Global search (`search.global` permission exists) is not built.
