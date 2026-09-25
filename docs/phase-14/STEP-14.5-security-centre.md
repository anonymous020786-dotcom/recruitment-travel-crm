# Step 14.5 — Security centre (Admin → Security)

Super admin only (`security.view` / `security.manage`; the `admin` role does not hold them — change that in Admin → Roles if you want). Every write asks for a fresh password confirmation (`confirm` middleware) and is audited (module `security`). Migration **0021** (`security_settings`, `ip_rules`, the two permissions) — run `php scripts/migrate.php`.

## Pages

| Page | What it does |
|---|---|
| **Overview** | Signed-in people/sessions, failed sign-ins in 24 h, locked accounts, IP-rule count, the two-factor status (who in a required role has not enrolled yet), the addresses with the most failures (one-click **Block 24 h**) and the latest failed sign-ins. |
| **Rate limits** | Every bucket in `config/rate_limits.php` with its plain-language label, what it is counted per, and editable *requests / seconds*. A "Customised" badge and the default beside each. Saving the default removes the override. Reset one or all. |
| **Two-factor & auto-block** | Tick the roles that must use two-factor (with how many people in each role still lack it) and the grace sign-ins; set the automatic block (N failed sign-ins in 15 min → block for M minutes; 0 = off). |
| **IP rules** | Add a block or an **always-allow** rule for one address or a CIDR range (IPv4 and IPv6), optional lifetime in minutes, a note. Lists rules with expiry, last time it turned someone away and who added it. |
| **Sessions** | Everyone signed in right now (person, role, address, device, last active). **End session**, **Sign out everywhere** (per person — same rules as Admin → Users), **Sign everyone else out**. |

## How it plugs in (no other code had to change)

`SecurityPolicy::applyToConfig()` runs once at boot (like the Integrations overlay; skipped if the table is missing) and copies the saved values into `rate_limits.buckets.*`, `auth.two_factor.*` and `security.*`. `RateLimit`, `Enforce2fa` and `AuthService` keep reading config as before. A missing row = the default from config/`.env`.

## Guard rails

- **Rate limits:** requests 1–100000, window 10–86400 s. The four buckets that guard passwords and codes (sign-in, two-factor codes, password confirmation, password reset) are *protected*: tighten freely, raise by at most **double** the default, never shorten the window below half. One invalid row rejects the whole save.
- **IP rules:** an **allow always wins**; you cannot block the address you are connecting from (unless an allow rule covers it); ranges wider than /8 (IPv4) or /32 (IPv6) cannot be blocked; at most 500 rules; temporary rules expire by themselves (pruned nightly by `cron/cleanup.php`); the automatic block never touches an allowed address.
- **Cost:** while there are no rules the filter costs nothing (a flag loaded at boot); with rules it is one indexed `BETWEEN` query per request (addresses are stored as 16 bytes, IPv4 as `::ffff:a.b.c.d`). If that query fails the request is **let through** and logged — a broken database must not lock everyone out.
- **Emergency exit:** if you do lock yourself out, on the server run `php scripts/security-unblock.php` (removes every rule, switches the automatic block off).
- The blocked page is a generic 403 that does not echo the address.

## Tests
`SecurityCentreTest` (21): address parsing/normalisation and hostile input, block/allow precedence and expiry, duplicate handling, the guard rails, the 500 cap, remove/prune/clear, the global filter (no rules / blocked / allow wins / flag survives restart / fails open), the automatic block (off by default, threshold, allow-list immunity, audit), a saved limit actually enforced by `RateLimit`, default-removal, all-or-nothing validation and the protected buckets, reset, the two-factor policy overlay and validation, auto-block settings, permissions (only super admin), page rendering + `no-store`, password-confirmation on writes, the write flows and their audit rows, and the sessions page (lifetime, end one, never your own, sign out a person, sign out everyone else).

## Honest limits
- Blocking is by the address PHP sees. Behind a proxy or Cloudflare the real visitor address is used only if the proxy is listed in `TRUSTED_PROXIES` in the `.env` file; otherwise every visitor looks like the proxy and a block would hit everyone. Check the address shown under "You are connecting from" before adding rules.
- Rate-limit changes take effect on the next request; counters already running keep their window.
