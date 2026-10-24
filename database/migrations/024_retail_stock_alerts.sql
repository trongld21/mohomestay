CREATE TABLE IF NOT EXISTS retail_stock_alert_state (
    product_id CHAR(32) NOT NULL PRIMARY KEY,
    alert_open TINYINT(1) NOT NULL DEFAULT 0,
    cycle_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_stock_alert_emails (
    id CHAR(32) NOT NULL PRIMARY KEY,
    product_id CHAR(32) NOT NULL,
    cycle_number INT UNSIGNED NOT NULL,
    recipient VARCHAR(254) NOT NULL DEFAULT '',
    payload TEXT NOT NULL,
    status ENUM('PENDING','SENDING','FAILED','SENT') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    claim_token CHAR(32) NULL,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_low_stock_cycle (product_id,cycle_number),
    INDEX idx_low_stock_dispatch (status,attempts,last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
