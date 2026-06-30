-- MagDyn — migration_20260622_120000_IST
-- Asset transaction business date. Captured on the transaction form as the
-- "Issued date" (for check-out: send_user / send_vendor) or the
-- "Checked in date" (for check-in: receive_user / receive_vendor), so the
-- recorded date can differ from the system timestamp (asset_transactions.at)
-- at which the row was entered.
--
--   asset_transactions.txn_date  — the user-entered date the asset was
--                                  issued / checked in for THAT event
--                                  (immutable history). NULL for events
--                                  where no date was supplied.
--
-- The asset list / view "Issued" column reads the most recent check-out's
-- txn_date (falling back to DATE(at) for rows imported before this column).
--
-- Idempotent + phpMyAdmin-safe. No SET @var usage.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS magdyn_p_asset_txn_date;
DELIMITER //
CREATE PROCEDURE magdyn_p_asset_txn_date()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'asset_transactions'
                      AND COLUMN_NAME = 'txn_date') THEN
        ALTER TABLE asset_transactions ADD COLUMN txn_date DATE NULL AFTER due_date;
    END IF;
END //
DELIMITER ;
CALL magdyn_p_asset_txn_date();
DROP PROCEDURE magdyn_p_asset_txn_date;
