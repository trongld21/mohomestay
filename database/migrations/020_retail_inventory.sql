ALTER TABLE retail_products ADD COLUMN IF NOT EXISTS stock_quantity INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE retail_products ADD COLUMN IF NOT EXISTS track_stock TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS retail_stock_movements (
    id CHAR(32) NOT NULL PRIMARY KEY,
    product_id CHAR(32) NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    movement_type ENUM('IN','SALE') NOT NULL,
    quantity_change INT NOT NULL,
    stock_after INT UNSIGNED NOT NULL,
    reference_id CHAR(32) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_stock_reference (movement_type,reference_id,product_id),
    INDEX idx_stock_product (product_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
