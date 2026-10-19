CREATE TABLE IF NOT EXISTS door_codes (
    id CHAR(32) PRIMARY KEY,
    code VARCHAR(100) NOT NULL,
    period ENUM('DAY','WEEK','MONTH') NOT NULL,
    status ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX door_codes_window (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
