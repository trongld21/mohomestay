CREATE TABLE IF NOT EXISTS retail_products (
    id CHAR(32) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    price INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_sales (
    id CHAR(32) NOT NULL PRIMARY KEY,
    request_id CHAR(32) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    sale_code VARCHAR(24) NOT NULL UNIQUE,
    total INT UNSIGNED NOT NULL,
    listed_total INT UNSIGNED NOT NULL,
    status ENUM('PENDING','PAID') NOT NULL DEFAULT 'PENDING',
    order_code BIGINT UNSIGNED NOT NULL UNIQUE,
    created_by VARCHAR(64) NOT NULL,
    qr_code TEXT NULL,
    checkout_url TEXT NULL,
    payment_link_id VARCHAR(64) NULL,
    transaction_reference VARCHAR(128) NULL UNIQUE,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_retail_paid (status,paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_sale_items (
    sale_id CHAR(32) NOT NULL,
    product_id CHAR(32) NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    unit_price INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    PRIMARY KEY (sale_id,product_id),
    CONSTRAINT fk_retail_item_sale FOREIGN KEY (sale_id) REFERENCES retail_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
