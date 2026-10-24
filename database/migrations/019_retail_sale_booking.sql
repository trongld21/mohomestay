ALTER TABLE retail_sales ADD COLUMN IF NOT EXISTS booking_id CHAR(32) NULL;
ALTER TABLE retail_sales ADD COLUMN IF NOT EXISTS booking_code_snapshot VARCHAR(32) NULL;
