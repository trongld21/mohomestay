CREATE TABLE IF NOT EXISTS staff_email_notifications (
    id CHAR(32) NOT NULL PRIMARY KEY,
    booking_id CHAR(32) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    payload TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    attempts INT NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff_event (booking_id, event_type),
    INDEX idx_staff_mail_pending (status, last_attempt_at),
    CONSTRAINT fk_staff_mail_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
