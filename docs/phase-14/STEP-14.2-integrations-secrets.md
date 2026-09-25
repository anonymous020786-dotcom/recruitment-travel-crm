# Step 14.2 — Admin → Integrations: every service's keys and secrets, managed by the super admin

One screen (`/admin/integrations`) for the credentials of every outside service the CRM uses or is about to use. **Super admin only** (`integrations.view` / `integrations.manage` — granted to no other role by default, and the shipped Admin matrix explicitly excludes `integrations.*`).

## Services in the catalogue (`config/integration_registry.php`, 15)
| Group | Services |
|---|---|
| Payment gateways — India | Razorpay, PayU India, Cashfree, PhonePe PG, CCAvenue, Paytm |
| Payment gateways — International | Stripe, PayPal |
| File storage | Amazon S3, Cloudflare R2 |
| Email | SMTP (host, port, encryption, username, password, from address/name) |
| Anti-spam & security | Cloudflare Turnstile |
| Analytics & chat | Google Analytics 4, Tawk.to, WhatsApp click-to-chat |

Adding a service is one registry entry (label, group, fields with type `text | secret | select | number | url`, optional `required`, `env`, `config`, `help`). The gateway and storage adapters (next steps) read their keys through `App\Integrations\Credentials`.

## How it behaves
- **Secrets are write-only.** Stored AES-256-GCM encrypted with the application key; the pages show only a mask (`••••1234` for a long secret, `••••••••` for a short one) and *never* contain the value. To change one you type a new value; an empty box means "keep"; a checkbox removes it. A secret that can no longer be decrypted (application key changed) reads as missing and is flagged "enter it again" — it never returns ciphertext.
- **Precedence:** a value saved in the panel wins, then the `.env` variable named in the registry, else unset. The screen says where each value comes from ("Saved here" / "From the .env file" / "Not set"). Emptying a plain field returns it to the `.env` default.
- **Switch per service.** "Service switched on" — off hands out nothing (a gateway can't be used) and, for services wired into config, blanks the settings: switching Turnstile off removes the widget, switching SMTP off makes mail use the log driver.
- **Config overlay.** At boot (`bootstrap/app.php`, one small query, skipped silently before the table exists or if the database is unreachable) saved values are copied into the config paths the registry names (`integrations.turnstile.*`, `mail.smtp.*`, `integrations.analytics.ga4_id`, `integrations.tawk.*`, `integrations.whatsapp.number`), so existing code keeps calling `config(...)` and simply sees what the super admin saved — no code changes needed in Turnstile, mail or the layouts.
- **Validation** per field type (select must be a listed option, number a whole number, no control characters, length limits), all-or-nothing.
- **Safety:** writes need a fresh password confirmation (`confirm`), CSRF and throttling; pages are `Cache-Control: no-store, private`; a rejected save never flashes what was typed back (it may be a secret). "Remove all saved values" resets a service.
- **Audit:** `integration_updated` / `integration_cleared` record the service and the *names* of the fields changed — never a value (tested).

## Data
Migration `0019` (`integration_credentials(service, field, value, is_secret, updated_by, updated_at)`, plus the two permissions granted to super_admin only). Run `php scripts/migrate.php` on the server.

## Tests (+14, 1154 total)
`IntegrationCredentialsTest`: registry well-formed (no duplicate config/env names, every secret-bearing gateway field typed `secret`, the six Indian + two international gateways and two storage providers present); encrypted at rest and plain fields not; the view never contains a secret; blank secret keeps / blank plain resets / explicit clear / reset; `.env` vs saved precedence; 7 kinds of invalid input, all-or-nothing; audit has names not values; undecryptable secrets; status states and the on/off switch; the config overlay incl. switching off; only the super admin holds the permissions; the screens list every service and never contain a secret; step-up confirmation, no echo of typed secrets, reset.
