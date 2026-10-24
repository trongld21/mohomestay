CREATE TABLE IF NOT EXISTS booking_surcharge_items (
    id CHAR(32) NOT NULL PRIMARY KEY,
    booking_id CHAR(32) NOT NULL,
    name VARCHAR(150) NOT NULL,
    amount INT UNSIGNED NOT NULL,
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_surcharge_booking (booking_id),
    CONSTRAINT fk_surcharge_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
