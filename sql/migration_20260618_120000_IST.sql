-- MagDyn — migration_20260618_120000_IST
-- Add FY + Department to invoice headers and Ledger to invoice line items.
--
--   * invoices.fy        — financial year ("2021-2022" … "2029-2030"),
--                          picked on the invoice form (header-level).
--   * invoices.dept      — department (Electronics / Mechanical / General).
--   * invoice_items.ledger — accounting ledger per line item (R&M Vehicle,
--                          Packing expenses, … — see invoice_ledger_options()).
--
-- All three are free-form VARCHARs (not ENUMs) so legacy values adopted by
-- the old-inventory importer (approveinv.financialyear / .department /
-- .ledger, recp_inv likewise) survive verbatim even if they fall outside
-- the current picklist. The form constrains new entries to the option list.
--
-- Idempotent + phpMyAdmin-safe (guards on information_schema so re-running
-- is a no-op).

SET NAMES utf8mb4;

-- invoices.fy -----------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'invoices'
       AND COLUMN_NAME  = 'fy'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `invoices` ADD COLUMN `fy` VARCHAR(16) DEFAULT NULL AFTER `currency`',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- invoices.dept ---------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'invoices'
       AND COLUMN_NAME  = 'dept'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `invoices` ADD COLUMN `dept` VARCHAR(64) DEFAULT NULL AFTER `fy`',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- invoice_items.ledger --------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'invoice_items'
       AND COLUMN_NAME  = 'ledger'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `invoice_items` ADD COLUMN `ledger` VARCHAR(64) DEFAULT NULL AFTER `hsn_code`',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
