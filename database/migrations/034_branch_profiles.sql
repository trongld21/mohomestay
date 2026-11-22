CREATE TABLE IF NOT EXISTS branches (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    map_url VARCHAR(500) NOT NULL DEFAULT '',
    description TEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Do not overwrite names/contact information when this migration is retried.
INSERT IGNORE INTO branches(id,name,address,phone,description) VALUES
    (1,'Chi nhánh 1','82 đường B18, KDC 91B, Ninh Kiều, Cần Thơ','0357907153',''),
    (2,'Chi nhánh 2','','','');
