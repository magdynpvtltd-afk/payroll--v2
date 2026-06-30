-- MagDyn — migration_20260617_120000_IST
-- Add `refno` to invoices: an auto-incrementing reference number assigned
-- per invoice at creation time (distinct from the vendor-issued invoice_no).
--
--   * New invoices created in MagDyn get the next sequential number
--     (MAX numeric refno + 1), auto-populated on save.
--   * Imported invoices adopt the legacy `refno` from approveinv / recp_inv.
--
-- Stored as VARCHAR(32) so legacy values (which may be non-numeric strings)
-- survive verbatim while new numeric values keep incrementing past them.
--
-- Idempotent + phpMyAdmin-safe (guards on information_schema so re-running
-- is a no-op).

SET NAMES utf8mb4;

-- Column ----------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'invoices'
       AND COLUMN_NAME  = 'refno'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `invoices` ADD COLUMN `refno` VARCHAR(32) DEFAULT NULL AFTER `invoice_no`',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index -----------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'invoices'
       AND INDEX_NAME   = 'ix_invoices_refno'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE `invoices` ADD KEY `ix_invoices_refno` (`refno`)',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
