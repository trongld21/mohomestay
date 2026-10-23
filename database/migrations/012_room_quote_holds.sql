CREATE TABLE IF NOT EXISTS room_quote_holds (
    id CHAR(32) NOT NULL PRIMARY KEY,
    room_id VARCHAR(64) NOT NULL,
    package_id VARCHAR(64) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    created_by VARCHAR(64) NOT NULL,
    released_by VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_quote_hold (room_id, expires_at, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
