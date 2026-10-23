ALTER TABLE booking_checkout_charges MODIFY COLUMN payment_status ENUM('NONE','DUE','PENDING','PAID','CANCELLED') NOT NULL;
ALTER TABLE booking_checkout_charges ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL;
ALTER TABLE booking_checkout_charges ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(64) NULL;
