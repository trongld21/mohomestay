CREATE TABLE IF NOT EXISTS people_counter_cameras (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_received_at DATETIME NULL,
    last_frame_at DATETIME NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'WAITING'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_counter_events (
    camera_id VARCHAR(64) NOT NULL,
    event_id CHAR(32) NOT NULL,
    direction VARCHAR(3) NOT NULL,
    occurred_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    PRIMARY KEY (camera_id, event_id),
    INDEX counter_camera_time (camera_id, occurred_at, event_id),
    FOREIGN KEY (camera_id) REFERENCES people_counter_cameras(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO people_counter_cameras (id, name)
VALUES ('entrance', 'Lễ tân / cửa chính')
ON DUPLICATE KEY UPDATE id = id;
