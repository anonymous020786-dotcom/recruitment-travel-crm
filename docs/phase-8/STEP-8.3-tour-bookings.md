# Phase 8 · Step 8.3 — Tour bookings

Closes Phase 8. A customer buys a package (or a custom trip). This side of the business is **independent of the recruitment pipeline** but shares the same people: the customer is a `persons` row, matched by phone / email exactly like lead conversion, so someone who is a candidate *and* a tour customer is one identity — their tour bookings appear on the candidate's page.

## What exists
| Layer | Files |
|---|---|
| Model | `TourBooking` (joined to person / package / assignee; `travellersLabel()`, `amountLabel()`, `tripLabel()`) |
| SQL | `TourBookingRepository` (branch-scoped, **optimistic-locked** `updateVersioned`, search / filters / sort, `statusCounts`, `forPerson`, `hasOpenDuplicate`), `TourBookingHistoryRepository` (append-only — no update/delete method) |
| Rules | `TourBookingService`, `TourBookingValidator`, `TourBookingPolicy`, `tour_booking` state machine |
| UI | `/tours/bookings` register with status tiles, create form, booking page (trip, customer, status history, move form), edit form; a "Tour bookings" card on the candidate page |

**Migration 0012** adds `tour_booking_status_history` (mirrored in `schema.sql`); the cancellation reason lives there. `tour_bookings` itself is unchanged.

## Lifecycle
```
inquiry ─▶ quoted ─▶ confirmed ─▶ travelling ─▶ completed
   │          │          │
   └──────────┴──────────┴──▶ cancelled          (inquiry may also go straight to confirmed)
```
`completed` and `cancelled` are final — a cancelled inquiry is replaced by a new booking. Every move is asserted against the machine, written with `WHERE record_version = :seen` (a stale form gets "changed just now" and changes nothing), appended to the history and audited in one transaction. Business gates on top of the table:
- **quoted** needs a price;
- **confirmed** needs a price and a travel date that is not in the past (an already-confirmed booking whose date is *unchanged* is not re-judged against today when edited);
- **travelling** only on or after the travel date (tolerating the furthest-ahead time zone, `TZ_SLACK_HOURS = 14`);
- **cancelled** needs a reason, and the actor needs `tours.bookings.delete` in addition to `change_status` (`TourBookingPolicy::cancel` — the permission catalogue labels `bookings.delete` "Cancel tour bookings").

## Customer identity
`persons` is matched by phone, then email (`PersonRepository::findOrCreate`), so the same human never gets a second record. When a match is found the booking shows the **name on file**, and the confirmation flash says so ("Matched the existing customer …"), because two people sharing a phone number would otherwise be silently merged. The same customer cannot hold two **open** bookings for the same package and travel date (double-submit guard); a different date is a different trip, and cancelling frees the slot.

## Pricing
Leave the amount blank and it is **package price × travellers** in the package's currency; type an amount and that (with your currency, default the package's, else INR) is used. A custom trip with no amount is stored as 0.00 and cannot be quoted or confirmed until priced. Editing with a blank amount re-prices from the (possibly changed) package or traveller count.

## Rules worth knowing
- **Branch scope:** a booking belongs to the branch it was created in; every read and write is scoped to it. The branch defaults to the creator's primary branch. An **org-wide user with no primary branch** (e.g. the first admin) previously had nothing to fall back on, so the form now shows a branch picker whenever there is a real choice *or* no default — found by the HTTP smoke test, covered by a service test.
- **Packages:** only `active` ones can be booked, but an existing booking keeps its package (and stays editable) after the package is archived.
- **Assignee** defaults to the creator; anyone else must be an active user who can serve the branch (`UserRepository::canServeBranch`: org-wide, or attached to it). Assigning someone else (on create or edit) sends them a notification (`tour_booking_assigned`); assigning yourself does not.
- **Edits** are allowed while a booking is `inquiry`, `quoted` or `confirmed`; a confirmed one must keep its price and date.
- Phone is validated like leads (7–30 digits, spaces / `+` / `-` / brackets) and whitespace-collapsed so matching behaves the same; email is lower-cased.

## Also in this step
- Sidebar: **Tours** (catalogue) and **Bookings** (this step) are separate entries.
- `UserRepository::canServeBranch`, `TourBookingRepository::hasOpenDuplicate` (`<=>` null-safe on package and date).

## Verification
- **17 new tests** (`TourBookingServiceTest`): creation (inquiry, number `TB-YYYY-NNNNNN`, history, audit), package vs manual pricing, shared-identity matching by phone and email including a candidate created from a lead (one `persons` row, visible from the candidate), duplicate-open-booking guard, validator rejections and normalisation, package rules (draft / unknown refused, archived package kept on an existing booking), branch + assignee rules and notification, the org-wide-without-branch case, the full pipeline with history, invalid transitions, every business gate, cancel-needs-reason (reason and actor kept in the history), stale versions refused on both edit and status, re-pricing on edit, confirmed-booking protection and read-only closed bookings, permissions (`read_only`, `counselor`, another branch, and the `travel` role working end to end), and the register's search (customer, phone fragment, number, package) / filters (status, package, upcoming, undated) / sort / counts.
- Full suite: **693 tests, 1847 assertions, all green.**
- HTTP smoke test against `php -S` (32 checks): pages and sidebar; branch picker for the org-wide admin; a bad phone and a missing branch bounce back without creating anything; create → priced 20,000 × 3, phone matched the candidate's existing person, the booking shows the candidate's name and the candidate page lists the booking; edit form pre-filled, re-price on update, a stale edit refused; inquiry → quoted → confirmed, refused start before the travel date, cancel refused without a reason then accepted with one, the reason visible in the history, four history rows, a cancelled booking no longer editable; search / filters / sort; 404 for an unknown booking; 419 for an unauthenticated POST. All smoke rows removed.

## Not in this step
Invoicing and payments against a booking (Phase 9 — invoices are polymorphic over applications and tour bookings), CSV export (`tours.bookings.export` exists as a permission but has no screen yet), per-traveller names beyond the counts, and a "Book a tour" shortcut from the candidate page.
