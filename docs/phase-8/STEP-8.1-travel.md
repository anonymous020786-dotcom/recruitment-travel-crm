# Phase 8 · Step 8.1 — Travel: flights, departure, arrival, placement

The recruitment side of Phase 8. Tour packages and bookings (the travel-agency side) are Step 8.2. `TravelService` owns the chain; `TravelController` is thin; the UI is a travel card on the application page plus two registers (`/travel`, `/placements`).

## The chain
```
visa_approved ── first live flight ──▶ ticket_pending ── a ticketed flight ──▶ ticket_booked
ticket_booked ── record departure ──▶ departed ── confirm arrival, then place ──▶ placed
```
Every move goes through `ApplicationService::advance()` inside the caller's transaction, so it lands in the append-only application history, refreshes the candidate's `stage`, and is audited like any other status change.

**One rule for ticketing — `reconcile()`.** Creating, editing, re-ticketing or cancelling a flight all call it, and it leaves the application where its flights say it should be:
- a live flight exists and the application is `visa_approved` → `ticket_pending`;
- a ticketed (`booked`/`issued`) flight exists → `ticket_booked`;
- the last ticketed flight is cancelled or marked `changed` → back to `ticket_pending`. This needed one new pipeline edge, `ticket_booked → ticket_pending` (`config/statuses.php`); everything else in the application table is unchanged.

`travel_profiles.readiness` mirrors the same steps (planning → ticket_pending → ticket_booked → departed → arrived). One profile row per candidate; saving the form (preferred departure city, notes) never resets readiness.

## Rules
| Action | Permission | Requires |
|---|---|---|
| Book / edit / re-status a flight | `travel.tickets.manage` | application `visa_approved`…`ticket_booked`; **one live flight per application**; a booked/issued ticket needs a PNR and departure time |
| Record departure | `travel.departure.manage` | application `ticket_booked` with a ticketed flight; once only; time not in the future |
| Confirm arrival | `travel.departure.manage` | application `departed`; not before the departure; once only |
| Record placement | `travel.placement.manage` | arrival confirmed; one per application; not dated before the departure |
| End a placement | `travel.placement.manage` | placement `active` → completed / terminated / absconded (reason required for the last two) |
| Save travel profile | `travel.profile.manage` | — |

All of it is also branch-scoped through the candidate / application (`FlightPolicy`, `PlacementPolicy`, `Gate` `view` on the application).

Flight statuses (`config/statuses.php` → `flight`): `planned → booked → issued → flown`, with `changed` (itinerary altered, waiting to be re-ticketed) and `cancelled`. `flown` is set only by recording the departure; `flown` and `cancelled` are final. Placement (`placement`): `active` → `completed | terminated | absconded`, all final.

Departure and arrival times are entered in local time (browser `datetime-local`); "not in the future" allows for the furthest-ahead time zone (`TZ_SLACK_HOURS = 14`) rather than guessing the user's.

Arrival confirmation notifies the application's owner (not the person who confirmed it) with a link to the application's `#travel` card.

## Data
- **Migration 0011** — `UNIQUE (application_id)` on `departure_records` (mirrored in `schema.sql`), so the pipeline join can never duplicate a row. Existing tables `flight_bookings`, `departure_records`, `placements`, `travel_profiles` are otherwise used as designed.
- New read models `FlightBooking`, `DepartureRecord`, `Placement`, `TravelProfile`; repositories `FlightRepository` (guarded `updateFrom(id, set, fromStatuses)` — a flight that changed under the caller is refused, never overwritten), `DepartureRepository`, `PlacementRepository`, `TravelProfileRepository`, and `TravelRepository` for the desk pipeline (`stageCounts`, `paginate`; search covers name, CAN-…, APP-… and PNR, with distinct placeholders).

## UI
- `/travel` — stage tiles (visa approved / ticket pending / ticket booked / departed), search, stage filter, sortable table with the current flight, PNR and departure time.
- `/placements` — register with search (candidate, CAN-…, employer), status and employer filters.
- Application page → **Travel** card (shown once the visa is approved, or if it already has flights/a placement): flights with status actions and an itinerary editor, add-flight form, departure / arrival / placement forms shown only when the step is legal *and* the user may do it, end-placement, travel profile.

## Also changed
- `DbTestCase` now disconnects after every test (`Db::disconnect()`, `#[After]`). PHPUnit keeps every test object — and its PDO connection — alive until the run ends, and this step took the suite past MariaDB's `max_connections` (151); the failure showed up as "Too many connections" in unrelated tests. The suite also runs faster (≈38 s).

## Verification
- **22 new tests** (`TravelServiceTest`): each transition above, both directions of `reconcile()`, all rejection paths (no ticket before the visa, second live flight, `flown` by hand, missing PNR, arrival before departure, placement before arrival / before departure / twice), validator edge cases, permissions for `read_only` / `counselor` and a `visa` user in another branch, the `visa` role running the whole chain, owner-only notification, profile create-then-update, pipeline + placements search/filters/branch scoping, and the panel read model.
- Full suite: **663 tests, 1645 assertions, all green.**
- HTTP smoke test against `php -S` (33 checks): login; `/travel` and `/placements` render; the card appears on a visa-approved application; a PNR-less "issued" flight is refused without creating anything; planned flight → `ticket_pending`; issued → `ticket_booked`; departure → `departed` + flight `flown`; placement before arrival is refused softly; arrival, placement → `placed`; register and filters list it; terminate without reason refused, complete succeeds; profile saves; an unauthenticated POST gets 419. All smoke rows were removed afterwards.

## Not in this step
Flight ticket attachment (`ticket_document_id` exists in the table but has no upload UI yet), a per-flight reminder for upcoming departures (Phase 11 automation), and tour packages / bookings (Step 8.2).
