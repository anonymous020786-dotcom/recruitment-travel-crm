-- Migration 0014 — append-only status history for refunds.
-- Same shape as the invoice / tour-booking histories. Written only by RefundService
-- inside the transaction that changes the refund. A rejection's reason lives here
-- (refunds.reason is the reason the refund was *requested*).

CREATE TABLE refund_status_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    refund_id   BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40)     NULL,
    to_status   VARCHAR(40)     NOT NULL,
    reason      VARCHAR(255)    NULL,
    changed_by  BIGINT UNSIGNED NULL,
    changed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rsh_refund (refund_id, changed_at),
    CONSTRAINT fk_rsh_refund FOREIGN KEY (refund_id) REFERENCES refunds (id) ON DELETE CASCADE,
    CONSTRAINT fk_rsh_user   FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
