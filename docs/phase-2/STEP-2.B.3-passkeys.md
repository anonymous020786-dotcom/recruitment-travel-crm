# Step 2.B.3 — Passkeys / WebAuthn

**Status:** implemented; 330 tests green (+31). In-house CBOR + COSE, no external
crypto library. Track B.3 of `docs/PLAN-ADDITIONS.md`.

## A. Files created

| File | Purpose |
|---|---|
| `app/Support/Cbor.php` | Minimal RFC 8949 decoder — definite-length only, rejects anything a conformant authenticator would not emit. `decode()` and `decodeFirst()` (reports bytes consumed, for the COSE key embedded in authData). |
| `app/Support/CoseKey.php` | COSE_Key → PEM SubjectPublicKeyInfo. ES256 (EC P-256, fixed SPKI prefix + point) and RS256 (hand-built DER: INTEGER/SEQUENCE/BIT STRING). `verify()` via `openssl_verify(..., SHA256)`. |
| `app/Auth/WebAuthn/AuthenticatorData.php` | Parses authenticator data (rpIdHash, flags UP/UV/AT/ED, signCount, attested credential data → aaguid / credentialId / COSE key + its raw bytes). |
| `app/Auth/WebAuthn/WebAuthnException.php` | User-safe ceremony failure. |
| `app/Auth/WebAuthn/WebAuthnService.php` | The two ceremonies, verified end to end. Challenges: 32 random bytes, single-use, purpose-bound (`register:<uid>` / `assert:<uid|*>`), TTL from config, always cleared on consume. |
| `app/Repositories/WebAuthnCredentialRepository.php` | CRUD over `webauthn_credentials` (raw-byte credential id + COSE key). |
| `app/Controllers/Crm/PasskeyController.php` | `/account/passkeys` — list, JSON options + register, rename, delete, "use passkeys as second factor" toggle. Removing the last passkey while it is the 2FA method reverts the account to no 2FA (unless the role mandates it and no TOTP exists). |
| `app/Controllers/Auth/WebAuthnLoginController.php` | Passwordless sign-in (`/login/passkey/{options,verify}`). Discoverable credential identifies the user; UV mandatory → passkey alone is 2FA-grade. `Auth::login($user, 'passkey')`. |
| `config/webauthn.php` | `rp_id` / `rp_name` / `origins` (env, default from `APP_URL`), timeouts, challenge TTL, `user_verification`, `passwordless` toggle. |
| `resources/views/crm/account/passkeys.php` | Management screen. |
| `tests/Support/FakeAuthenticator.php` | In-process EC P-256 authenticator — emits attestation ("none") + assertion JSON exactly like the browser; tiny CBOR encoder; works around Windows/XAMPP openssl.cnf. |
| `tests/Unit/Support/CborTest.php`, `tests/Unit/Support/CoseKeyTest.php`, `tests/Feature/WebAuthnServiceTest.php` | Decoder vectors; ES256/RS256 sign→verify round trips; full registration + assertion, replayed challenge, foreign origin, sign-count regression, tampered signature, passwordless UV enforcement, single-use challenge. |

## B. Files modified

- `app/Controllers/Auth/TwoFactorChallengeController.php` — passkey as a second
  factor (`passkeyOptions` / `passkeyVerify`, JSON); shared `complete()` helper
  (session + alert + remember/trust cookies) now used by every success path.
- `bootstrap/services.php` — `WebAuthnService` singleton.
- `routes/web.php` — passkey routes (guest passwordless, 2FA challenge, account
  management); JSON ceremony endpoints also carry the `json` alias so failures
  return `application/json`.
- `resources/js/app.js` — `[data-passkey-register|login|2fa]` glue: base64url ↔
  ArrayBuffer, `navigator.credentials.create/get`, serialize, POST, redirect.
  Hides `[data-passkey-only]` when `window.PublicKeyCredential` is absent.
- `resources/views/layouts/{app,guest}.php` — `<meta name="csrf-token">`; guest
  layout now loads `app.js` (nonce'd).
- `resources/views/auth/{login,two-factor}.php` — passkey buttons + fallbacks.
- `resources/views/crm/account/security.php` — "Passkeys" card linking out.
- `docs/PLAN-ADDITIONS.md` — B.3 marked done.

## C. Migration

None — `webauthn_credentials` and the `two_factor_method` `'passkey'` enum value
landed in `0003_auth_enhancements.sql`.

## F. Security

- Every check is server-side and fails closed: clientDataJSON `type`, `challenge`
  (`hash_equals` against the single-use session challenge), `origin` (exact
  allow-list); authenticator-data RP-ID hash (`hash_equals` of SHA-256(rpId)),
  UP flag always, UV flag for passwordless.
- Assertion signature verified over `authData ‖ SHA-256(clientDataJSON)` with the
  stored COSE key.
- Sign-count regression: once either side is non-zero, the new counter must
  strictly exceed the stored one — otherwise the credential is treated as cloned
  and access is denied.
- Attestation: we request `none`. `packed` self-attestation and `fido-u2f` are
  verified when sent; `packed` with an x5c leaf is signature-checked (no chain to
  a trust anchor — out of scope for a 2FA credential, documented). Unknown
  formats are rejected.
- Challenges are purpose-bound so a registration challenge cannot be replayed
  into an assertion, and vice versa; consumed (deleted) on first use regardless
  of outcome.
- Credential ids / COSE keys stored as raw bytes (`VARBINARY` / `BLOB`), never
  logged. The WebAuthn user handle is `SHA-256("webauthn-user:" . id)` — no PII,
  no bare primary key.
- CBOR decoder refuses indefinite-length, trailing bytes, oversized 64-bit
  values, and truncated items rather than guessing.
- Passkey enrol / remove and the second-factor toggle are written to
  `activity_logs`.

## H. Manual QA — verified

- [x] `GET /login` → "Sign in with a passkey" present, `csrf-token` meta, `app.js` loaded
- [x] `POST /login/passkey/options` without a token → `419 application/json`
- [x] `GET /account/passkeys` unauthenticated → `302 /login`
- [x] Full suite: **330 tests, 761 assertions** green
- [ ] End-to-end with a real authenticator (platform / YubiKey) — needs HTTPS + a real device; do on staging

## I. Performance

- Registration: 1 challenge write + 1 read + 1 insert. Assertion: 1 read + 1
  update. No N+1; `webauthn_credentials` is indexed on `user_id` and unique on
  `credential_id`.
- CBOR/COSE parsing is a few hundred bytes of pure PHP — negligible.
- DB-free requests stay DB-free: the browser JS only calls the endpoints on an
  explicit button click.
