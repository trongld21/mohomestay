CREATE TABLE IF NOT EXISTS promotions (
    id CHAR(32) NOT NULL PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    type VARCHAR(16) NOT NULL,
    percent TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    weekdays TEXT NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    min_stays INT UNSIGNED NOT NULL DEFAULT 1,
    room_ids TEXT NOT NULL,
    package_ids TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable audit data; deliberately no FK to editable/deletable promotion rules.
CREATE TABLE IF NOT EXISTS booking_promotion_snapshots (
    booking_id CHAR(32) NOT NULL PRIMARY KEY,
    base_price INT UNSIGNED NOT NULL,
    price INT UNSIGNED NOT NULL,
    discount_percent TINYINT UNSIGNED NOT NULL,
    promotion_id CHAR(32) NULL,
    promotion_name VARCHAR(160) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
