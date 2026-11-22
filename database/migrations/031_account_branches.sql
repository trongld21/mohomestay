-- Accounts remain shared; existing staff belong to branch 1.
ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS branch_id TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active;
