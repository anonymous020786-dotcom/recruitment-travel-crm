# Step 13.7 — Settings screen and a real public contact profile

**Admin → Settings** (`/admin/settings`; view = `settings.view`, change = `settings.manage`, so managers can read and admins can edit).

## Design
- The screen edits only what `config/settings.php` declares (label, type, limits, default, public flag). Adding an option is one entry there plus a call to `setting('group.key')` — no new screen code.
- Values live in the existing `settings` table (JSON). An emptied field **deletes its row** and the field falls back to its default (a literal, or the value of a config key — i.e. the environment file). Only fields whose stored value differs are written and audited (`settings_updated`, old → new for the changed keys; nothing is written when nothing changed).
- Reads never fail: a missing row, a value of the wrong shape or an unreachable database all fall back to the default, so public pages and the error page always render. One query per request.
- Deliberately **not** settable here: refund self-approval, password/2FA rules, upload limits, maintenance mode (file-based so it works with the database down). Those stay deploy-time.

## Fields shipped
| Group | Key | Type | Used by |
|---|---|---|---|
| Business profile (public) | `business.name` | text ≤120 | footer, JSON-LD, page titles/descriptions (was `seo.organization_name`) |
| | `business.phone`, `business.whatsapp` | phone | contact page (tap-to-call, wa.me), footer, JSON-LD `telephone` |
| | `business.email` | email | contact page (mailto), JSON-LD `email` |
| | `business.address`, `business.hours` | text / line | contact page, JSON-LD `address` |
| Finance | `finance.reminder_max_repeats` | int 0–20 | `PaymentReminderService` (wired in `bootstrap/services.php`) |

The contact page used to promise "call, WhatsApp" with no number anywhere; it now shows a contact card (only for fields that are filled — no empty block) and the footer carries the phone. Free text is escaped everywhere it is printed.

## Code
`config/settings.php`, `SettingsRepository`, `SettingsService` (`get`/`saved`/`update`, strict per-type validation: phone 7–30 digits, email, single-line, control characters refused, all-or-nothing on error), `SettingsController`, view `crm/admin/settings/index.php`, global helper `setting()`, routes `admin.settings[.update]`, sidebar entry.

## Tests
`tests/Feature/SettingsTest.php` (10): defaults and unknown-key refusal; wrong-shaped stored values ignored; save + audit only the changed keys + `is_public` flag; emptying restores defaults; field-by-field validation and all-or-nothing; undeclared keys untouched; who may view/change (counselor 403, manager view-only, admin edits); screen round-trip incl. validation message and retained input; public site shows tel/wa.me/mailto/hours, escapes the address, JSON-LD carries the details; empty profile shows no contact block. Live smoke (php -S): 8/8.
Full suite: **1042 tests, 3 615 assertions** (3 skipped: GD-only image tests). Compiled CSS rebuilt.
