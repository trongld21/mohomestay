ALTER TABLE booking_access_codes ADD COLUMN IF NOT EXISTS code_mode ENUM('CUSTOM','GENERATED') NOT NULL DEFAULT 'CUSTOM';
ALTER TABLE booking_access_codes MODIFY COLUMN code VARCHAR(9) NULL;
-- Only failed, unprovisioned gateway requests can safely switch to generated mode.
UPDATE booking_access_codes SET code_mode='GENERATED', code=NULL, sync_status='PENDING', sync_error=NULL, last_attempt_at=NULL
WHERE remote_id IS NULL AND sync_status='FAILED' AND sync_error LIKE '%-2012%';
