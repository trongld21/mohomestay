CREATE TABLE IF NOT EXISTS booking_stay_changes (
    id CHAR(32) NOT NULL PRIMARY KEY,
    booking_id CHAR(32) NOT NULL,
    actor_id VARCHAR(64) NOT NULL,
    old_room_id VARCHAR(64) NOT NULL,
    new_room_id VARCHAR(64) NOT NULL,
    old_check_in DATETIME NOT NULL,
    old_check_out DATETIME NOT NULL,
    new_check_in DATETIME NOT NULL,
    new_check_out DATETIME NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_booking_stay_changes (booking_id, created_at),
    CONSTRAINT fk_booking_stay_changes FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
