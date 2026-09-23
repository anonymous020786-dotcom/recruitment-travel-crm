-- Migration 0013 — append-only status history for invoices.
-- Same shape as the application / visa / tour-booking histories. Written only by
-- InvoiceService (and, from Step 9.2, PaymentService when a payment moves an
-- invoice to partially_paid / paid) inside the same transaction as the change.
-- Voiding needs a reason and it lives here.

CREATE TABLE invoice_status_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40)     NULL,
    to_status   VARCHAR(40)     NOT NULL,
    reason      VARCHAR(255)    NULL,
    changed_by  BIGINT UNSIGNED NULL,
    changed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ish_invoice (invoice_id, changed_at),
    CONSTRAINT fk_ish_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_ish_user    FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
