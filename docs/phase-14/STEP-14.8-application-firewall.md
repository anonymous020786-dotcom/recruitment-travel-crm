# Step 14.8 — Application firewall (Admin → Security → Firewall)

A web application firewall (WAF) that inspects every request before the site answers it, managed by the super admin (`security.view` / `security.manage`; every change asks for a fresh password confirmation and is audited). Migration **0026** (`firewall_rules`, `firewall_events`); settings live in `security_settings` under `fw.` and load with the query the security policy already makes at boot.

## How a request is checked (global middleware `FirewallGuard`, right after `IpFilter`)
1. Firewall off, or the address is on an IP **allow** rule → through (a trusted office is never firewalled).
2. **Lockdown** (optional): sign-in and the staff area (`/login`, `/two-factor`, password pages, `/admin`, `/account`, `/dashboard`) answer only allow-listed addresses. The public site stays open.
3. **Country rules**: block chosen countries, or allow only chosen countries — for the whole site or only sign-in/staff area. Uses the `CF-IPCountry` header **only** when the request came through a proxy in `TRUSTED_PROXIES` (a visitor cannot fake it). All 249 ISO countries plus Tor (T1) and unknown (XX).
4. **Custom rules** by priority: look at the path, query, form body, user agent, referer, method, IP, country or any header; *contains / equals / starts with / ends with / regex / in IP ranges / one of*; optional **invert**; action **block**, **log only** or **allow** (skips the rest — e.g. a partner's monitoring bot); optional expiry in hours; hit counter.
5. **Managed rule sets**, each block / log only / off: SQL injection · cross-site scripting · path traversal & file inclusion · code & command injection (incl. Log4Shell, SSTI, Shellshock) · attack tools & bad bots (sqlmap, nikto, nuclei, wpscan…) · probes for secrets & other software (`.env`, `.git`, backups, WordPress, phpMyAdmin, web shells) · protocol abuse (TRACE/CONNECT, null bytes, >2 KB addresses, >8 KB headers) · requests without a user agent (log only by default).

Inputs are normalised before matching (URL-decoded twice, HTML entities decoded, SQL comments removed, lowercased); only the first 16 KB of a part is inspected. **Monitor mode** logs what would be blocked without blocking — use it for a day after big changes.

## What is deliberately not inspected
- **Form content of signed-in staff** (checked against the `sessions` table — a made-up cookie does not help): they are authenticated and CSRF-protected, and editors legitimately write code in pages. Their query strings are still inspected.
- **Payment webhooks** (`/webhooks/…`): every one is verified by the gateway's signature.

## Bans
- Probing for other software's secret files **bans the address at once** (default 30 minutes; 0 = off).
- Otherwise **N blocked requests within M minutes** ban it for M minutes (default 20 in 60).
- Bans are ordinary temporary IP rules (source "auto") under IP rules; allow-listed addresses are never banned.

## Activity screen
24-hour counts (blocked, logged, banned, addresses), events per hour, busiest rules and addresses (one-click **Block 24 h**), and the event log filterable by action, rule and address, with the request, the part and the text that matched, country and the **reference** shown to the blocked visitor. One address can write at most 60 events a minute (a flood cannot fill the database); events are pruned after 30 days by the nightly cleanup.

## Test a request
Enter a method, address, form body, user agent, IP and country, and see every rule that matches and the verdict — nothing is logged, counted or banned.

## Safety
- You cannot switch lockdown on unless your own address is on an allow rule, nor save a country rule that blocks the country you are connecting from.
- The firewall **fails open**: if it errors (e.g. a table is missing) the request goes through and the error is logged — a firewall bug never takes the site down. Blocking still works if only the log cannot be written.
- Emergency exit: `php scripts/security-unblock.php` now also switches off lockdown and country rules.
- Regular expressions are compiled when saved; invalid ones are refused.

## Also fixed in this step
The CRM's Content-Security-Policy allows styles only from the stylesheet or a nonce-carrying `<style>`. The dashboard's bar charts, the side navigation height, the payment receipt and the report print view used inline styles that browsers ignore under that policy (charts drew flat, printouts lost their layout). They now use classes / the nonce, and `NoInlineStylesTest` keeps it that way.

## Tests
`WafSignaturesTest` (47: 44 attack samples caught, 22 real-world texts — enquiries, names with apostrophes, "select", "update me", browser user agents — never matched), `FirewallTest` (20), `NoInlineStylesTest` (1).

## Honest limits
An application firewall is a second line of defence: the code already uses bound SQL parameters, escaping, CSRF and upload re-encoding. It cannot stop large volumetric (DDoS) floods — every request still reaches PHP; use Cloudflare (or your host's) network protection in front for that, and set `TRUSTED_PROXIES` so the firewall sees real visitor addresses and countries.
