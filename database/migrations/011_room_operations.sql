CREATE TABLE IF NOT EXISTS room_emergency_locks (
    id CHAR(32) NOT NULL PRIMARY KEY,
    booking_id CHAR(32) NOT NULL UNIQUE,
    room_id VARCHAR(64) NOT NULL,
    starts_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    locked_by VARCHAR(64) NOT NULL,
    ended_by VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emergency_room (room_id, ended_at, starts_at),
    CONSTRAINT fk_emergency_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_checkout_charges (
    booking_id CHAR(32) NOT NULL PRIMARY KEY,
    actual_checkout_at DATETIME NOT NULL,
    confirmed_by VARCHAR(64) NOT NULL,
    late_seconds INT UNSIGNED NOT NULL,
    billable_hours INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    order_code BIGINT UNSIGNED NULL UNIQUE,
    payment_status ENUM('NONE','DUE','PENDING','PAID') NOT NULL,
    qr_code TEXT NULL,
    checkout_url TEXT NULL,
    payment_link_id VARCHAR(64) NULL,
    transaction_reference VARCHAR(128) NULL UNIQUE,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_checkout_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
