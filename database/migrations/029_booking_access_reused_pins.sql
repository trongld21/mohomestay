-- TTLock PINs can recur across bookings; booking_id owns provisioning identity.
-- Preserve all records so UNKNOWN requests can reconcile the remote result.
ALTER TABLE booking_access_codes DROP INDEX IF EXISTS booking_access_pin;
CREATE INDEX IF NOT EXISTS booking_access_pin_lookup ON booking_access_codes (lock_id, code);
