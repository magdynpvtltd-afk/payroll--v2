-- MagDyn — migration_20260617_150000_IST
-- Multi-location pick for shipment ship lines.
--
-- A ship line can now draw its planned quantity from several source
-- locations instead of just one. The per-location split is persisted in a
-- new child table; `inv_shipment_lines.src_location_id` is retained for
-- backward-compat (legacy / single-source lines) and is set to the first
-- split entry so existing views keep rendering a sensible location.
--
-- Process-build consumption (inventory.php?action=process) splits the same
-- way but is a one-shot transaction — it needs no schema, only multiple
-- ledger txns at post time.
--
-- Idempotent (CREATE TABLE IF NOT EXISTS) + phpMyAdmin-safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `inv_shipment_line_sources` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shipment_line_id` int(10) unsigned NOT NULL,
  `location_id`      int(10) unsigned NOT NULL,
  `qty`              decimal(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `ix_invsls_line` (`shipment_line_id`),
  KEY `ix_invsls_loc`  (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
