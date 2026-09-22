ALTER TABLE rooms ADD COLUMN IF NOT EXISTS subtitle VARCHAR(255) NOT NULL DEFAULT '' AFTER name;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER subtitle;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS tag VARCHAR(120) NOT NULL DEFAULT '' AFTER description;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS status ENUM('ACTIVE','COMING_SOON','HIDDEN') NOT NULL DEFAULT 'ACTIVE' AFTER tag;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS bedrooms TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER max_guests;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS bathrooms TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER bedrooms;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER bathrooms;

CREATE TABLE IF NOT EXISTS room_images (
  id VARCHAR(64) PRIMARY KEY,
  room_id VARCHAR(32) NOT NULL,
  path VARCHAR(500) NOT NULL,
  caption VARCHAR(255) NOT NULL DEFAULT '',
  is_cover TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_room_images_order (room_id, sort_order, created_at),
  CONSTRAINT fk_room_images_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_tags (
  id VARCHAR(64) PRIMARY KEY,
  room_id VARCHAR(32) NOT NULL,
  label VARCHAR(40) NOT NULL,
  normalized_label VARCHAR(40) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_room_tag (room_id, normalized_label),
  KEY idx_room_tags_order (room_id, sort_order),
  CONSTRAINT fk_room_tags_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_packages (
  id VARCHAR(64) PRIMARY KEY,
  room_id VARCHAR(32) NOT NULL,
  name VARCHAR(80) NOT NULL,
  timing_mode ENUM('DURATION','FIXED_TIME') NOT NULL,
  duration_minutes INT UNSIGNED NULL,
  check_in_time TIME NULL,
  check_out_time TIME NULL,
  price DECIMAL(12,0) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_room_packages_order (room_id, is_enabled, sort_order),
  CONSTRAINT fk_room_packages_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_id VARCHAR(64) NULL AFTER stay_package;
ALTER TABLE bookings MODIFY COLUMN stay_package VARCHAR(64) NOT NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_name_snapshot VARCHAR(80) NOT NULL DEFAULT '' AFTER package_id;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_mode_snapshot ENUM('DURATION','FIXED_TIME') NULL AFTER package_name_snapshot;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_duration_snapshot INT UNSIGNED NULL AFTER package_mode_snapshot;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_check_in_snapshot TIME NULL AFTER package_duration_snapshot;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_check_out_snapshot TIME NULL AFTER package_check_in_snapshot;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_price_snapshot DECIMAL(12,0) NULL AFTER package_check_out_snapshot;

UPDATE rooms SET name='Pink', subtitle='Một chút ngọt ngào, một chút mơ mộng.', description='Dành một khoảng thời gian thật riêng cho nhau, trong không gian dịu dàng và ấm áp.', tag='Dịu dàng & lãng mạn', status='ACTIVE', bedrooms=1, bathrooms=1, sort_order=10 WHERE id='pink' AND subtitle='';
UPDATE rooms SET name='White', subtitle='Nhẹ nhàng như một ngày không vội.', description='Một căn phòng sáng, tinh giản và dễ chịu. Tạm gác những bộn bề để tận hưởng khoảng thời gian của riêng bạn.', tag='Tinh giản & thư thái', status='ACTIVE', bedrooms=1, bathrooms=1, sort_order=20 WHERE id='white' AND subtitle='';
UPDATE rooms SET name='Black', subtitle='Một không gian, một sắc thái riêng.', description='Không gian trầm ấm dành cho những ai yêu sự riêng tư. Thả mình nghỉ ngơi và để nhịp sống chậm lại.', tag='Cá tính & riêng tư', status='ACTIVE', bedrooms=1, bathrooms=1, sort_order=30 WHERE id='black' AND subtitle='';

INSERT INTO room_images (id,room_id,path,caption,is_cover,sort_order) VALUES
('pink-legacy-cover','pink','/images/pink-illustration.jpg','Ảnh minh họa',1,10),
('white-legacy-cover','white','/images/white-illustration.jpg','Ảnh minh họa',1,10),
('black-legacy-cover','black','/images/black-illustration.jpg','Ảnh minh họa',1,10)
ON DUPLICATE KEY UPDATE path=VALUES(path);

INSERT INTO room_packages (id,room_id,name,timing_mode,duration_minutes,check_in_time,check_out_time,price,is_enabled,sort_order) VALUES
('pink-3h','pink','Gói 3 giờ','DURATION',180,NULL,NULL,200000,1,10),
('pink-6h','pink','Gói 6 giờ','DURATION',360,NULL,NULL,380000,1,20),
('pink-overnight','pink','Qua đêm','FIXED_TIME',NULL,'22:00:00','10:00:00',330000,1,30),
('white-3h','white','Gói 3 giờ','DURATION',180,NULL,NULL,150000,1,10),
('white-6h','white','Gói 6 giờ','DURATION',360,NULL,NULL,350000,1,20),
('white-overnight','white','Qua đêm','FIXED_TIME',NULL,'22:00:00','10:00:00',250000,1,30),
('black-3h','black','Gói 3 giờ','DURATION',180,NULL,NULL,180000,1,10),
('black-6h','black','Gói 6 giờ','DURATION',360,NULL,NULL,300000,1,20),
('black-overnight','black','Qua đêm','FIXED_TIME',NULL,'22:00:00','10:00:00',320000,1,30)
ON DUPLICATE KEY UPDATE name=VALUES(name),price=VALUES(price);

UPDATE bookings b
SET b.package_id = CONCAT(b.room_id, '-', b.stay_package),
    b.package_name_snapshot = CASE b.stay_package WHEN '3h' THEN 'Gói 3 giờ' WHEN '6h' THEN 'Gói 6 giờ' ELSE 'Qua đêm' END,
    b.package_mode_snapshot = CASE WHEN b.stay_package IN ('3h','6h') THEN 'DURATION' ELSE 'FIXED_TIME' END,
    b.package_duration_snapshot = CASE b.stay_package WHEN '3h' THEN 180 WHEN '6h' THEN 360 ELSE NULL END,
    b.package_check_in_snapshot = CASE WHEN b.stay_package='overnight' THEN '22:00:00' ELSE NULL END,
    b.package_check_out_snapshot = CASE WHEN b.stay_package='overnight' THEN '10:00:00' ELSE NULL END,
    b.package_price_snapshot = b.total_price
WHERE b.package_id IS NULL AND b.stay_package IN ('3h','6h','overnight');

SET @package_fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND CONSTRAINT_NAME='fk_bookings_package');
SET @package_fk_sql = IF(@package_fk_exists=0, 'ALTER TABLE bookings ADD CONSTRAINT fk_bookings_package FOREIGN KEY (package_id) REFERENCES room_packages(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE package_fk_stmt FROM @package_fk_sql;
EXECUTE package_fk_stmt;
DEALLOCATE PREPARE package_fk_stmt;
