-- MagDyn — migration_20260618_140000_IST
-- Allow ad-hoc "New item" lines on invoices.
--
--   * invoice_items.item_kind — extend the ENUM to add 'custom' so an
--     invoice line can be a free-text item that is neither a registered
--     inventory item (inv_item) nor a tracked asset. The typed name is
--     stored in invoice_items.description; item_code stays blank for these.
--
-- This mirrors the shipment create page, where a line can be an Item, an
-- Asset, or a "New item". On an invoice a "New item" is purely a billing
-- line (service / freight / misc charge) — it is not back-filled into the
-- inventory master, so no extra columns are needed.
--
-- Idempotent + phpMyAdmin-safe: we only ALTER when 'custom' is not yet part
-- of the column type, so re-running is a no-op.

SET NAMES utf8mb4;

-- invoice_items.item_kind ENUM ------------------------------------------
SET @has_custom := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'invoice_items'
       AND COLUMN_NAME  = 'item_kind'
       AND COLUMN_TYPE LIKE '%custom%'
);
SET @ddl := IF(@has_custom = 0,
    "ALTER TABLE `invoice_items` MODIFY COLUMN `item_kind` ENUM('asset','inv_item','custom') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
