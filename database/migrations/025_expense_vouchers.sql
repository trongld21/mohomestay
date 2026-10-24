CREATE TABLE IF NOT EXISTS expense_vouchers (
    id CHAR(32) NOT NULL PRIMARY KEY,
    voucher_code VARCHAR(24) NOT NULL UNIQUE,
    request_id CHAR(32) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    spent_at DATETIME NOT NULL,
    category VARCHAR(150) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(150) NOT NULL DEFAULT '',
    note TEXT NOT NULL,
    include_cashflow TINYINT(1) NOT NULL DEFAULT 1,
    created_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX expense_spent_at (spent_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
