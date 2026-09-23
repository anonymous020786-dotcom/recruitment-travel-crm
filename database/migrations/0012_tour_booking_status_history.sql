-- Migration 0012 — append-only status history for tour bookings.
-- Same shape as application_status_history / visa_status_history: written only by
-- TourBookingService inside the transition transaction, read newest-first on the
-- booking page. The cancellation reason lives here (tour_bookings has no column for it).

CREATE TABLE tour_booking_status_history (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tour_booking_id BIGINT UNSIGNED NOT NULL,
    from_status     VARCHAR(40)     NULL,
    to_status       VARCHAR(40)     NOT NULL,
    reason          VARCHAR(255)    NULL,
    changed_by      BIGINT UNSIGNED NULL,
    changed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tbsh_booking (tour_booking_id, changed_at),
    CONSTRAINT fk_tbsh_booking FOREIGN KEY (tour_booking_id) REFERENCES tour_bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_tbsh_user    FOREIGN KEY (changed_by)      REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
