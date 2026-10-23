CREATE TABLE IF NOT EXISTS booking_identity_images (
    booking_id CHAR(32) NOT NULL,
    side ENUM('front','back') NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    encrypted_image MEDIUMBLOB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (booking_id, side),
    CONSTRAINT fk_booking_identity_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
