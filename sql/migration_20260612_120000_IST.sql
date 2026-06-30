-- MagDyn — migration_20260612_120000_IST
-- Inspection templates: give each template item its own free-text `notes`
-- field (separate from the descriptive `description` blob). The old-inventory
-- importer fills it from the legacy `inspection.notes` column, and the value
-- surfaces in the inspection View / Execute grid and the printed/PDF IR's
-- "Notes" column.
--
-- Adds inspection_template_items.notes. Idempotent + phpMyAdmin-safe (guards
-- on information_schema so re-running is a no-op).

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'inspection_template_items'
       AND COLUMN_NAME  = 'notes'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `inspection_template_items` ADD COLUMN `notes` TEXT NULL DEFAULT NULL AFTER `description`',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
