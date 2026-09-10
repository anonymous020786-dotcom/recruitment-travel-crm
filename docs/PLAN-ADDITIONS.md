# Plan additions — requested feature set

Slotted into the phase plan without derailing the recruitment/travel core. Grouped
into two new tracks plus a few public-site items.

## Track A — Site integrations (public site only; the CRM stays private + noindex)

| Feature | Where | Notes |
|---|---|---|
| **WhatsApp floating button** | `components/whatsapp-button.php`, public layout | `wa.me` deep link from `INTEGRATIONS_WHATSAPP_NUMBER` + prefilled text; also reused inside the CRM lead screens (already there as an action) |
| **Google Analytics (GA4)** | public `<head>` only | Loaded from `INTEGRATIONS_GA4_ID`; gated on consent flag; **never** on CRM/auth pages; CSP `script-src`/`connect-src`/`img-src` gets `*.googletagmanager.com` / `*.google-analytics.com` **only for the public group** |
| **Cloudflare Turnstile** | public forms (contact, job-apply, travel enquiry, password-reset request) | Client widget + **server-side siteverify** via `HttpClient`; `TurnstileValidator` returns pass/fail; keys from env; disabled cleanly when unconfigured (dev) |
| **Tawk.to live chat** | public pages | Embed from `INTEGRATIONS_TAWK_PROPERTY_ID`/`_WIDGET_ID`; public CSP allows `*.tawk.to`; off on CRM |

Config: `config/integrations.php` (all env-driven). Rendering: `IntegrationsService`
+ `x-integrations-head` / `x-integrations-body` partials, nonce-aware. Status: **Step 2.A** (this batch).

## Track B — Authentication enhancements

Delivered as steps after Phase 2, but the schema lands now so migrations stay ordered.

| Step | Feature | Detail |
|---|---|---|
| **B.1** | **Remember me** | Opt-in on login. `auth_tokens` table (selector + validator hash, series rotation, theft detection per "Improved Persistent Login Cookie Best Practice"). Long-lived cookie, separate from the session; re-auth still required for sensitive actions (step-up). |
| **B.1** | **Trusted device** | `trusted_devices` table (device token hash, UA/IP fingerprint, `trusted_until`). "Remember this device for 30 days" — skips the 2FA prompt (not the password) on that device. |
| **B.2** | **Email OTP** | 6-digit code to the account email as a **fallback** second factor and for step-up on risky actions. `auth_otp_codes` table (hash, purpose, expiry, attempts). Rate-limited, single-use. |
| **B.2** | **TOTP (authenticator app)** | RFC 6238, home-grown (base32 + HMAC-SHA1, ±1 step skew). `users.totp_secret` (encrypted with `APP_KEY`), `users.totp_confirmed_at`, one-time **recovery codes** (`auth_recovery_codes`, hashed). Enrolment shows a QR (otpauth URI → we render the URI; QR drawn client-side or via a tiny SVG QR encoder). |
| **B.3** | **Passkey / WebAuthn** | ✅ `webauthn_credentials` table. In-house `Cbor` decoder + `CoseKey` (ES256 / RS256 → PEM SPKI, OpenSSL verify). `WebAuthn\WebAuthnService` runs both ceremonies server-side: registration verifies clientDataJSON (type/challenge/origin), attestation (`none` + `packed` self-attestation + fido-u2f), RP-ID hash, UP flag; assertion verifies clientDataJSON, RP-ID hash, UP (+ UV for passwordless), the signature over `authData ‖ SHA-256(clientDataJSON)` with the stored COSE key, and a strict sign-count regression (clone) check. Challenges are single-use and purpose-bound (session). Self-service management at `/account/passkeys` (add / rename / remove, "use as second factor"); passwordless sign-in button on `/login`; passkey as a 2FA method on `/two-factor`. Browser glue in `resources/js/app.js` (`[data-passkey-*]`). |
| **B.x** | **2FA policy** | ✅ `Enforce2fa` middleware (in the CRM auth group): roles in `auth.two_factor.required_roles` that have no second factor get `auth.two_factor.grace_logins` sign-ins (counted from `login_history`, server-side) with an amber countdown banner, then every page except the enrolment / sign-out routes redirects to `/account/two-factor`. A passwordless passkey sign-in counts as MFA and is exempt. Step-up: `RequireRecentAuth` (`confirm[:<minutes>]`) gates the security screen, 2FA-disable, recovery-code regen, all passkey mutations, and "sign out everywhere" — a session with no fresh password/2FA auth within `auth.password_confirm.timeout_minutes` (default 15) is sent to `/confirm-password`; JSON callers get a 403 with `confirm_required` and the JS redirects. Remember-me recalls never satisfy it. |

### New tables (migration `0003_auth_enhancements.sql`)
```
auth_tokens        (remember-me: id, user_id, selector UNIQUE, validator_hash, series,
                    expires_at, last_used_at, created_ip, created_ua, created_at)
trusted_devices    (id, user_id, token_hash UNIQUE, label, ua_hash, last_ip,
                    trusted_until, created_at, last_seen_at)
auth_otp_codes     (id, user_id, purpose, code_hash, expires_at, consumed_at, attempts, created_at)
auth_recovery_codes(id, user_id, code_hash UNIQUE, used_at, created_at)
webauthn_credentials(id, user_id, credential_id VARBINARY UNIQUE, public_key BLOB,
                    sign_count BIGINT UNSIGNED, transports, aaguid, label,
                    created_at, last_used_at)
```
`users` gains: `totp_secret VARBINARY(255) NULL`, `totp_confirmed_at DATETIME NULL`,
`two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0`.

## Track C — Advanced email notifications  (was Phase 1.11)

| Feature | Detail |
|---|---|
| Real SMTP `SmtpTransport` | ✅ Behind a `Transport` interface (`LogTransport` in dev, `SmtpTransport` in prod). Raw SMTP over `stream_socket_client`, STARTTLS/SSL, AUTH LOGIN, timeouts, no external lib. `EmailMessage` builds MIME multipart/alternative with header-injection guards + quoted-printable. |
| `cron/process-email-queue.php` | ✅ `MailQueue` drains `email_log` (status `queued`, `next_attempt_at` due), retries with exponential backoff `[1,5,15]` min, caps at `max_attempts` → `failed`, prunes terminal rows > 30 days. Runs under `CronRunner` (table advisory lock + `cron_runs` ledger). |
| Templated emails | ✅ `resources/views/mail/*` (`layout`, `reset-password`, `otp`, `new-device`); `MailComposer::send()` renders + queues, injecting `appName`/`appUrl`. `AuthService` + `TwoFactor` now compose via `MailComposer`. |
| New-device sign-in alert | ✅ `LoginAlerts::afterLogin()` — fingerprints UA, records `login_history`, emails on an unrecognised device (not the first login), respects `users.notify_new_device` opt-out. Wired into `LoginController` + `TwoFactorChallengeController`. Never blocks login. |
| Notification email digest | ⬜ Per-user preference (`immediate` / `daily digest` / `off`); `cron/notification-digest.php`. Still pending. |
| Events wired | new device sign-in ✅, password reset ✅, email OTP ✅. Remaining (lead assigned, follow-up due, document rejected, interview tomorrow, payment overdue, passport/visa expiring, departure tomorrow) land with their owning phases. |

Status: **Step 2.C.1 done** (SMTP transport, queue cron, templates, new-device alerts). Digest + remaining event hooks: later.

---

## Security notes for the batch

- GA / Tawk are **third-party scripts**: allowed only in the **public** CSP group, loaded with the page nonce where possible (GA's gtag needs its own domain allow-listed, not `unsafe-inline`). The CRM CSP is untouched — no analytics or chat on authenticated pages.
- Turnstile: **server verification is mandatory** — the client token alone is never trusted. Verified against `challenges.cloudflare.com/turnstile/v0/siteverify` with the secret key and the remote IP.
- Remember-me cookie ≠ session: compromise of a remember-me token must not grant a full session for sensitive actions — those require a fresh password / 2FA (step-up), tracked by `session._authenticated_at`.
- TOTP secret and any long-lived device secret are stored encrypted (AES-256-GCM via `APP_KEY`), never plaintext.
- WebAuthn: verify RP ID hash, origin, challenge single-use, and **sign-count regression** (clone detection). Reject `attestationObject` we cannot parse rather than accepting blindly.
- Every 2FA enrol/disable, trusted-device add/remove, and remember-me issue/revoke is written to `activity_logs`.
