-- MagDyn — migration_20260616_140000_IST
-- Per-user, per-datatable VIEW STATE persistence (filters, global search,
-- sort, page size). Complements `user_dt_prefs` (which stores column
-- order / visibility / width). Together they let every list table in the
-- app come back exactly as the user left it.
--
-- One row per (user_id, dt_id). The view state itself is a small JSON blob
-- so adding new state fields later needs no schema change.
--
-- Idempotent + phpMyAdmin-safe (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_dt_view_state` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `dt_id` varchar(120) NOT NULL,
  `state_json` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_dt_view` (`user_id`,`dt_id`),
  KEY `ix_udvs_user_dt` (`user_id`,`dt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
