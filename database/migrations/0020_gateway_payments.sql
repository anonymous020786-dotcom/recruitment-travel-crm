-- Migration 0020 — online payments through a payment gateway.
--
-- gateway_payments: one row per "pay this invoice online" request. `reference` is OUR unguessable merchant order id (short enough for
-- every gateway: PayU allows 25 characters). The gateway's own ids are filled in as the customer progresses. A payment is recorded
-- in `payments` only when a signed webhook (or a verified return) says money arrived, exactly once: the unique key on
-- (gateway, provider_payment_id) and the payment's idempotency key both stop a replayed webhook from recording it twice.
--
-- gateway_events: every inbound gateway call, signature verdict included, for audit and debugging. The unique hash stops the same
-- delivery being processed twice. Bodies are capped at 64 KB and contain no card data (gateways never send any).

CREATE TABLE gateway_payments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id           CHAR(26)        NOT NULL,             -- in the customer's pay link (/pay/<public_id>)
    reference           VARCHAR(24)     NOT NULL,             -- our merchant order id, sent to the gateway
    invoice_id          BIGINT UNSIGNED NOT NULL,
    gateway             VARCHAR(20)     NOT NULL,
    amount              DECIMAL(14,2)   NOT NULL,
    currency            CHAR(3)         NOT NULL,
    status              ENUM('created','pending','paid','failed','cancelled','expired','mismatch') NOT NULL DEFAULT 'created',
    provider_order_id   VARCHAR(120)    NULL,
    provider_payment_id VARCHAR(120)    NULL,
    checkout_method     ENUM('redirect','post') NULL,
    checkout_url        VARCHAR(1000)   NULL,
    checkout_fields     TEXT            NULL,                 -- JSON, for gateways that need a form POST
    payment_id          BIGINT UNSIGNED NULL,                 -- the recorded payment, once paid
    failure_reason      VARCHAR(255)    NULL,
    expires_at          DATETIME        NOT NULL,
    paid_at             DATETIME        NULL,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gp_public_id (public_id),
    UNIQUE KEY uq_gp_reference (reference),
    UNIQUE KEY uq_gp_provider_payment (gateway, provider_payment_id),
    KEY idx_gp_invoice (invoice_id, status),
    CONSTRAINT fk_gp_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_gp_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
    CONSTRAINT fk_gp_user    FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway       VARCHAR(20)     NOT NULL,
    body_hash     CHAR(64)        NOT NULL,                   -- sha256(gateway | raw body): one row per distinct delivery
    signature_ok  TINYINT(1)      NOT NULL,
    result        VARCHAR(40)     NOT NULL,                   -- paid | failed | pending | ignored | duplicate | mismatch | bad_signature | unknown_payment | error
    reference     VARCHAR(24)     NULL,
    payload       TEXT            NULL,
    received_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ge_hash (body_hash),
    KEY idx_ge_gateway_time (gateway, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
