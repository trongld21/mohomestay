CREATE TABLE IF NOT EXISTS booking_access_codes (
    booking_id CHAR(32) PRIMARY KEY,
    lock_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(9) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    remote_id BIGINT UNSIGNED NULL,
    sync_status ENUM('PENDING','UNKNOWN','READY','FAILED','REVOKED') NOT NULL DEFAULT 'PENDING',
    sync_error VARCHAR(500) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY booking_access_pin (lock_id, code),
    INDEX booking_access_retry (sync_status, last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
