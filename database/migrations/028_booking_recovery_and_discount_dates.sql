ALTER TABLE rooms ADD COLUMN IF NOT EXISTS discount_start DATE NULL;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS discount_end DATE NULL;
ALTER TABLE room_packages ADD COLUMN IF NOT EXISTS discount_start DATE NULL;
ALTER TABLE room_packages ADD COLUMN IF NOT EXISTS discount_end DATE NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS payment_checked_at DATETIME NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS payment_next_check_at DATETIME NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS payment_provision_checked_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS booking_payment_receipts (
    id CHAR(32) PRIMARY KEY,
    booking_id CHAR(32) NOT NULL UNIQUE,
    confirmed_by VARCHAR(64) NOT NULL,
    amount DECIMAL(12,0) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    confirmed_at DATETIME NOT NULL,
    CONSTRAINT fk_receipt_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
