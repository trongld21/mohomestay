ALTER TABLE bookings ADD COLUMN IF NOT EXISTS confirmation_email_status ENUM('PENDING','SENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING' AFTER transaction_id;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS confirmation_email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER confirmation_email_status;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS confirmation_email_sent_at DATETIME NULL AFTER confirmation_email_attempts;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS confirmation_email_error VARCHAR(500) NULL AFTER confirmation_email_sent_at;
