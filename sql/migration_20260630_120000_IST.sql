-- MagDyn — migration_20260630_120000_IST
-- Running notes on shipment lines.
--
-- The Ship & Receipt list page (and shipment view page) gain a per-line
-- running-notes composer (body + file attachments). A note attaches to the
-- specific transaction LINE — i.e. the inventory item code on that line —
-- NOT the whole shipment. Notes are stored in the shared `notes` table
-- keyed by entity_type = 'shr_line', entity_id = inv_shipment_lines.id.
--
-- ('shiprcpt' is also added — an earlier iteration used a shipment-level
--  entity_type; it stays a valid enum value so any rows created under it
--  remain readable, but the live UI now uses the per-line 'shr_line'.)
--
-- This migration widens the notes.entity_type ENUM. Existing rows / values
-- are untouched.
--
-- Idempotent + phpMyAdmin-safe. No SET @var usage.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS magdyn_p_notes_shr_enum;
DELIMITER //
CREATE PROCEDURE magdyn_p_notes_shr_enum()
BEGIN
    -- Keyed on the newest value ('shr_line') so re-running after an earlier
    -- 'shiprcpt'-only version still applies the widening.
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'notes'
                      AND COLUMN_NAME = 'entity_type'
                      AND COLUMN_TYPE LIKE '%''shr_line''%') THEN
        ALTER TABLE notes
            MODIFY COLUMN entity_type
            enum('asset','asset_txn','inv_item','inv_txn','inspection','inspection_template','document','shiprcpt','shr_line')
            DEFAULT NULL;
    END IF;
END //
DELIMITER ;
CALL magdyn_p_notes_shr_enum();
DROP PROCEDURE magdyn_p_notes_shr_enum;
