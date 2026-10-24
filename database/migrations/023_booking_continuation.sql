ALTER TABLE bookings ADD COLUMN IF NOT EXISTS continued_from_booking_id CHAR(32) NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS continued_to_booking_id CHAR(32) NULL;
