# Step 14.4 — Online payments (payment gateways)

Customers can pay an invoice online; the payment lands in the ledger automatically, exactly once. The framework and the first two adapters (**Razorpay**, **Stripe**) are in; the remaining adapters (PayU, Cashfree, PhonePe, CCAvenue, Paytm, PayPal) follow in later commits of this step and plug into the same framework.

## How it works
1. **Staff** open an invoice → **Collect online**: pick a gateway that is configured *and* can collect the invoice's currency, optionally an instalment amount (empty = the whole balance) → **Create pay link**. They get a link (`/pay/<26-char id>`), a copy field and a "Share on WhatsApp" button. Links live 7 days; staff can cancel them. Nothing is sent to the gateway yet.
2. The **customer** opens the link: a page with the invoice number, their *first name* and the amount (never cached, `noindex`, `Referrer-Policy: no-referrer`). **Pay** (a POST) creates the checkout at the gateway *now* — so a link never holds an expired session — and sends them there (redirect, or an auto-submitted form for the gateways that need one). A gateway outage shows a friendly retry page and leaves the link usable.
3. The **gateway tells us the result** by a signed **webhook** (`POST /webhooks/<gateway>`) and/or by sending the customer's browser back (`/pay/<id>/return`) with a signed result. `OnlinePaymentService::handleEvent` is the *only* place a payment is recorded.

## The safety rules (all tested)
- **Nothing is trusted before its signature verifies** (Stripe `t=…,v1=…` HMAC with a 5-minute replay window; Razorpay HMAC-SHA256 over the raw body, plus the signed return parameters). Forged deliveries get a 401 and are **all kept** in `gateway_events` (bad-signature attempts are never de-duplicated away).
- **Exactly once:** the link row is locked for the transaction; `UNIQUE(gateway, provider_payment_id)`; the payment carries an idempotency key `sha256("gw:<gateway>:<payment id>")`. An identical delivery, a re-sent event with a new id, and "browser return + webhook" for the same payment all produce **one** payment. (A delivery logged but never finished — the process died half-way — is processed again on retry; the handler is idempotent.)
- **Amount and currency must match** what was asked, to the paisa. Otherwise the link is set aside as `mismatch`, nothing is recorded, and the link creator and every active super admin are notified. Same when the creator has left, lost `payments.create`, or is deactivated: the money is **flagged, never guessed at**.
- **A paid link is never downgraded**; a second payment on an already-settled invoice is reported as `second_payment` (refund it at the gateway or record it by hand); a second payment on a still-open invoice is recorded.
- **Rate limits:** `pay_public` 60/min/IP for the customer pages, `webhook` 600/min/IP. Bodies over 64 KB get a 413 before anything else. Webhook headers are read from a fixed whitelist only.
- Unknown gateway → 404; known but switched off / not configured → 409; a message that is not ours (unknown reference) → 200 "unknown_payment" so the gateway stops retrying.
- The pay page starts a payment with a **POST** (no state change behind a GET); the three public write routes without CSRF (no session exists) are listed with their justification in `RouteAuditTest`.

## Data
Migration **0020**: `gateway_payments` (our unguessable `reference` — short enough for PayU's 25-character limit — the gateway's ids, status `created|pending|paid|failed|cancelled|expired|mismatch`, the recorded `payment_id`) and `gateway_events` (every inbound delivery with its signature verdict). Run `php scripts/migrate.php`.

## Setup for the super admin
Admin → Integrations → the gateway → paste the keys (secrets are encrypted, write-only) → in the provider's dashboard create the webhook pointing at `https://<your-domain>/webhooks/<gateway>` with the same signing secret → start in *test* mode, make a test payment, then go live.

## Honest limits
The adapters are written from each provider's public API documentation and are tested with request-shape checks, signature/hash verification (valid, forged, stale, tampered) and recorded responses — **not against live or sandbox accounts** (no keys here). Do a sandbox payment per gateway before going live.

## Tests
`StripeRazorpayGatewayTest` (14) and `OnlinePaymentFlowTest` (22): link creation rules, the public page's privacy, checkout start, the whole webhook lifecycle (record once, replays, forgery, mismatch, unknown reference, creator lost permission, failed→retried, second payment, instalments), verified/tampered returns, the HTTP endpoints (no session/CSRF needed but a valid signature is), the invoice card, branch isolation, rate-limit buckets.
