-- MariaDB dump 10.19  Distrib 10.4.27-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: magdyn
-- ------------------------------------------------------
-- Server version	10.4.27-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `asset_aliases`
--

DROP TABLE IF EXISTS `asset_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_aliases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_cal_frequencies`
--

DROP TABLE IF EXISTS `asset_cal_frequencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_cal_frequencies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `months` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_calibration_options`
--

DROP TABLE IF EXISTS `asset_calibration_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_calibration_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_checked_ok_options`
--

DROP TABLE IF EXISTS `asset_checked_ok_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_checked_ok_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_engraved_options`
--

DROP TABLE IF EXISTS `asset_engraved_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_engraved_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_models`
--

DROP TABLE IF EXISTS `asset_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_models` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(190) NOT NULL,
  `category` varchar(120) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `manufacturer` varchar(150) DEFAULT NULL,
  `model_number` varchar(120) DEFAULT NULL,
  `default_cal_frequency_id` int(10) unsigned DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_model_code` (`code`),
  KEY `ix_model_name` (`name`),
  KEY `fk_model_cal_freq` (`default_cal_frequency_id`),
  KEY `fk_model_category` (`category_id`),
  CONSTRAINT `fk_model_cal_freq` FOREIGN KEY (`default_cal_frequency_id`) REFERENCES `asset_cal_frequencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_model_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_transactions`
--

DROP TABLE IF EXISTS `asset_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(10) unsigned NOT NULL,
  `txn_type` enum('create','move','send_vendor','receive_vendor','send_user','receive_user','archive','restore','calibrate','edit') NOT NULL,
  `from_location_id` int(10) unsigned DEFAULT NULL,
  `to_location_id` int(10) unsigned DEFAULT NULL,
  `from_user_id` int(10) unsigned DEFAULT NULL,
  `to_user_id` int(10) unsigned DEFAULT NULL,
  `from_vendor_id` int(10) unsigned DEFAULT NULL,
  `to_vendor_id` int(10) unsigned DEFAULT NULL,
  `calibration_done_on` date DEFAULT NULL,
  `next_cal_due_on` date DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `txn_date` date DEFAULT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_txn_asset` (`asset_id`),
  KEY `ix_txn_at` (`at`),
  KEY `fk_txn_from_loc` (`from_location_id`),
  KEY `fk_txn_to_loc` (`to_location_id`),
  KEY `fk_txn_from_u` (`from_user_id`),
  KEY `fk_txn_to_u` (`to_user_id`),
  KEY `fk_txn_from_v` (`from_vendor_id`),
  KEY `fk_txn_to_v` (`to_vendor_id`),
  KEY `fk_txn_actor` (`actor_id`),
  CONSTRAINT `fk_txn_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_txn_from_loc` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_from_u` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_from_v` FOREIGN KEY (`from_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_to_loc` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_to_u` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_to_v` FOREIGN KEY (`to_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_tag` varchar(60) NOT NULL,
  `asset_name` varchar(255) DEFAULT NULL,
  `model_id` int(10) unsigned NOT NULL,
  `location_id` int(10) unsigned DEFAULT NULL,
  `parent_asset_id` int(10) unsigned DEFAULT NULL,
  `lock_to_parent` tinyint(1) NOT NULL DEFAULT 0,
  `a_price` decimal(14,2) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `pid_used_in` varchar(150) DEFAULT NULL,
  `alias_id` int(10) unsigned DEFAULT NULL,
  `cal_frequency_id` int(10) unsigned DEFAULT NULL,
  `engraved_id` int(10) unsigned DEFAULT NULL,
  `calibration_id` int(10) unsigned DEFAULT NULL,
  `checked_ok_id` int(10) unsigned DEFAULT NULL,
  `cal_done_on` date DEFAULT NULL,
  `next_cal_due_on` date DEFAULT NULL,
  `status` enum('active','with_vendor','with_user','archived') NOT NULL DEFAULT 'active',
  `current_vendor_id` int(10) unsigned DEFAULT NULL,
  `current_user_id` int(10) unsigned DEFAULT NULL,
  `checkout_due_on` date DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_tag` (`asset_tag`),
  KEY `ix_assets_model` (`model_id`),
  KEY `ix_assets_location` (`location_id`),
  KEY `ix_assets_parent` (`parent_asset_id`),
  KEY `ix_assets_due` (`next_cal_due_on`),
  KEY `ix_assets_status` (`status`),
  KEY `fk_assets_alias` (`alias_id`),
  KEY `fk_assets_freq` (`cal_frequency_id`),
  KEY `fk_assets_engraved` (`engraved_id`),
  KEY `fk_assets_cal` (`calibration_id`),
  KEY `fk_assets_chk` (`checked_ok_id`),
  KEY `fk_assets_user` (`current_user_id`),
  KEY `fk_assets_vendor` (`current_vendor_id`),
  KEY `fk_assets_creator` (`created_by`),
  CONSTRAINT `fk_assets_alias` FOREIGN KEY (`alias_id`) REFERENCES `asset_aliases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_cal` FOREIGN KEY (`calibration_id`) REFERENCES `asset_calibration_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_chk` FOREIGN KEY (`checked_ok_id`) REFERENCES `asset_checked_ok_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_engraved` FOREIGN KEY (`engraved_id`) REFERENCES `asset_engraved_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_freq` FOREIGN KEY (`cal_frequency_id`) REFERENCES `asset_cal_frequencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_model` FOREIGN KEY (`model_id`) REFERENCES `asset_models` (`id`),
  CONSTRAINT `fk_assets_parent` FOREIGN KEY (`parent_asset_id`) REFERENCES `assets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_user` FOREIGN KEY (`current_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assets_vendor` FOREIGN KEY (`current_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ats`
--

DROP TABLE IF EXISTS `ats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ats_no` varchar(64) NOT NULL,
  `job_card_id` int(10) unsigned DEFAULT NULL,
  `po_no` varchar(64) NOT NULL,
  `ats_date` date NOT NULL,
  `ats_ref_no` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','pushed','cancelled','locked') NOT NULL DEFAULT 'draft',
  `billing_ats_id` int(10) unsigned DEFAULT NULL,
  `billing_ats_no` varchar(64) DEFAULT NULL,
  `billing_status` varchar(32) DEFAULT NULL,
  `last_push_at` datetime DEFAULT NULL,
  `last_push_op` varchar(16) DEFAULT NULL,
  `last_push_http` int(11) DEFAULT NULL,
  `last_push_response` text DEFAULT NULL,
  `last_push_error` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ats_no` (`ats_no`),
  UNIQUE KEY `uq_ats_job_card` (`job_card_id`),
  KEY `ix_ats_status` (`status`),
  KEY `ix_ats_po` (`po_no`),
  KEY `ix_ats_billing_id` (`billing_ats_id`),
  KEY `fk_ats_creator` (`created_by`),
  CONSTRAINT `fk_ats_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ats_jc` FOREIGN KEY (`job_card_id`) REFERENCES `job_cards` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ats_lines`
--

DROP TABLE IF EXISTS `ats_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ats_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ats_id` int(10) unsigned NOT NULL,
  `job_card_id` int(10) unsigned NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  `inv_code` varchar(64) NOT NULL,
  `line_no` varchar(32) DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_atsline_atsjc` (`ats_id`,`job_card_id`),
  KEY `ix_atsline_ats` (`ats_id`),
  KEY `ix_atsline_jc` (`job_card_id`),
  KEY `ix_atsline_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ats_push_history`
--

DROP TABLE IF EXISTS `ats_push_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ats_push_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ats_id` int(10) unsigned NOT NULL,
  `op` varchar(16) NOT NULL,
  `http` int(11) NOT NULL DEFAULT 0,
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `response` text DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `error_code` varchar(64) DEFAULT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_aph_ats` (`ats_id`,`id`),
  CONSTRAINT `fk_aph_ats` FOREIGN KEY (`ats_id`) REFERENCES `ats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_actor` (`actor_id`),
  KEY `ix_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `billing_product_pushes`
--

DROP TABLE IF EXISTS `billing_product_pushes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_product_pushes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `op` varchar(16) NOT NULL,
  `http` int(11) NOT NULL DEFAULT 0,
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `request` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `error_code` varchar(64) DEFAULT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_bpp_item` (`item_id`,`id`),
  KEY `fk_bpp_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `code` varchar(60) NOT NULL,
  `name` varchar(150) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_type_code` (`type`,`code`),
  KEY `ix_cat_type` (`type`),
  KEY `ix_cat_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cmm_points`
--

DROP TABLE IF EXISTS `cmm_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cmm_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `idx` int(10) unsigned NOT NULL,
  `tag` varchar(8) DEFAULT NULL,
  `x_actual` decimal(10,5) NOT NULL,
  `x_nominal` decimal(10,5) NOT NULL,
  `x_dev` decimal(10,5) NOT NULL,
  `y_actual` decimal(10,5) NOT NULL,
  `y_nominal` decimal(10,5) NOT NULL,
  `y_dev` decimal(10,5) NOT NULL,
  `z_actual` decimal(10,5) NOT NULL,
  `z_nominal` decimal(10,5) NOT NULL,
  `z_dev` decimal(10,5) NOT NULL,
  `dist_actual` decimal(10,5) NOT NULL,
  `dist_dev` decimal(10,5) NOT NULL,
  `out_of_tol` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cmm_points_run` (`run_id`,`idx`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cmm_runs`
--

DROP TABLE IF EXISTS `cmm_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cmm_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL DEFAULT 0,
  `extracted_via` varchar(16) NOT NULL DEFAULT 'pdfjs',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `report_date` varchar(64) DEFAULT NULL,
  `part_number` varchar(64) DEFAULT NULL,
  `cmm_type` varchar(64) DEFAULT NULL,
  `machine_type` enum('vmc','wedm','manual','grinding','other') NOT NULL DEFAULT 'vmc',
  `is_multipass` tinyint(1) NOT NULL DEFAULT 0,
  `operator` varchar(64) DEFAULT NULL,
  `feature_name` varchar(128) DEFAULT NULL,
  `point_count` int(10) unsigned NOT NULL DEFAULT 0,
  `z_value` decimal(10,4) DEFAULT NULL,
  `upper_tol` decimal(10,5) NOT NULL DEFAULT 0.00050,
  `lower_tol` decimal(10,5) NOT NULL DEFAULT -0.00050,
  `in_tol_count` int(10) unsigned NOT NULL DEFAULT 0,
  `edge_count` int(10) unsigned NOT NULL DEFAULT 0,
  `oot_count` int(10) unsigned NOT NULL DEFAULT 0,
  `cpk_upper` decimal(8,4) DEFAULT NULL,
  `verdict` enum('PASS','MARGINAL','REJECT') NOT NULL DEFAULT 'REJECT',
  `comment` text DEFAULT NULL,
  `analysis_json` longtext NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cmm_runs_uploaded` (`uploaded_at`),
  KEY `idx_cmm_runs_verdict` (`verdict`),
  KEY `idx_cmm_runs_part` (`part_number`),
  KEY `fk_cmm_runs_user` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `code_sequences`
--

DROP TABLE IF EXISTS `code_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `code_sequences` (
  `name` varchar(40) NOT NULL,
  `label` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `prefix` varchar(20) NOT NULL,
  `pad` int(10) unsigned NOT NULL DEFAULT 5,
  `format` enum('prefix_pad','prefix_date_seq') NOT NULL DEFAULT 'prefix_pad',
  `date_format` varchar(10) DEFAULT NULL,
  `target_table` varchar(80) DEFAULT NULL,
  `target_column` varchar(80) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doc_acknowledgments`
--

DROP TABLE IF EXISTS `doc_acknowledgments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doc_acknowledgments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_id` int(10) unsigned NOT NULL,
  `document_id` int(10) unsigned NOT NULL,
  `revision_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `acknowledged_at` datetime NOT NULL DEFAULT current_timestamp(),
  `comments` varchar(500) DEFAULT NULL,
  `training_completion_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_ack_unique` (`recipient_id`),
  KEY `ix_doc_ack_doc` (`document_id`,`revision_id`),
  KEY `ix_doc_ack_user` (`user_id`),
  KEY `fk_doc_ack_rev` (`revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doc_categories`
--

DROP TABLE IF EXISTS `doc_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doc_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `kind` enum('internal','external') NOT NULL,
  `prefix` varchar(16) NOT NULL,
  `description` varchar(400) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_cat_code` (`code`),
  KEY `ix_doc_cat_kind` (`kind`,`is_active`),
  KEY `ix_doc_cat_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doc_entity_links`
--

DROP TABLE IF EXISTS `doc_entity_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doc_entity_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `entity_type` enum('asset','inv_item','inspection','inspection_template','invoice','shipment','ecn') NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `link_note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_entity_link` (`document_id`,`entity_type`,`entity_id`),
  KEY `ix_doc_entity_link_back` (`entity_type`,`entity_id`),
  KEY `fk_doc_el_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doc_history`
--

DROP TABLE IF EXISTS `doc_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doc_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `event_type` enum('created','edited','rev_added','status_change','recipient_added','acknowledged','transmitted','entity_linked','entity_unlinked','file_uploaded','cover_uploaded','approved','rejected') NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `related_id` int(10) unsigned DEFAULT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_doc_hist_doc` (`document_id`,`id`),
  KEY `ix_doc_hist_event` (`event_type`),
  KEY `fk_doc_hist_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doc_recipients`
--

DROP TABLE IF EXISTS `doc_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doc_recipients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `revision_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `role_id` int(10) unsigned DEFAULT NULL,
  `external_name` varchar(160) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_doc_rcp_doc` (`document_id`,`revision_id`),
  KEY `ix_doc_rcp_user` (`user_id`),
  KEY `ix_doc_rcp_role` (`role_id`),
  KEY `fk_doc_rcp_rev` (`revision_id`),
  KEY `fk_doc_rcp_by` (`assigned_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doc_revisions`
--

DROP TABLE IF EXISTS `doc_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doc_revisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `rev_major` int(10) unsigned DEFAULT NULL,
  `rev_minor` int(10) unsigned DEFAULT NULL,
  `rev_label` varchar(64) NOT NULL,
  `stage` enum('draft','review','release','correction') NOT NULL DEFAULT 'draft',
  `ecn_id` int(10) unsigned DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `file_mime` varchar(120) DEFAULT NULL,
  `file_hash` varchar(64) DEFAULT NULL,
  `change_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_rev_label` (`document_id`,`rev_label`),
  KEY `ix_doc_rev_doc` (`document_id`,`id`),
  KEY `ix_doc_rev_stage` (`stage`),
  KEY `fk_doc_rev_creator` (`created_by`),
  KEY `ix_doc_rev_ecn` (`ecn_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doc_transmittals`
--

DROP TABLE IF EXISTS `doc_transmittals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doc_transmittals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transmittal_no` varchar(64) NOT NULL,
  `document_id` int(10) unsigned NOT NULL,
  `revision_id` int(10) unsigned NOT NULL,
  `recipient_kind` enum('customer','vendor','user','external_party') NOT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `vendor_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `external_party` varchar(160) DEFAULT NULL,
  `recipient_attn` varchar(160) DEFAULT NULL,
  `recipient_email` varchar(160) DEFAULT NULL,
  `recipient_phone` varchar(60) DEFAULT NULL,
  `sent_date` date NOT NULL,
  `method` enum('email','post','courier','portal','handover','other') NOT NULL DEFAULT 'email',
  `reference` varchar(120) DEFAULT NULL,
  `subject` varchar(240) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `delivery_status` enum('sent','delivered','acknowledged','signed','returned','failed') NOT NULL DEFAULT 'sent',
  `delivered_at` datetime DEFAULT NULL,
  `delivered_note` varchar(500) DEFAULT NULL,
  `cover_kind` enum('uploaded','auto','none') NOT NULL DEFAULT 'none',
  `cover_file_name` varchar(255) DEFAULT NULL,
  `cover_file_path` varchar(500) DEFAULT NULL,
  `cover_file_size` bigint(20) unsigned DEFAULT NULL,
  `cover_file_mime` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_trn_no` (`transmittal_no`),
  KEY `ix_doc_trn_doc` (`document_id`,`sent_date`),
  KEY `ix_doc_trn_recipient` (`recipient_kind`,`vendor_id`,`user_id`),
  KEY `ix_doc_trn_status` (`delivery_status`),
  KEY `fk_doc_trn_rev` (`revision_id`),
  KEY `fk_doc_trn_vendor` (`vendor_id`),
  KEY `fk_doc_trn_user` (`user_id`),
  KEY `fk_doc_trn_created` (`created_by`),
  KEY `fk_doc_trn_cancelled` (`cancelled_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `doc_no` varchar(120) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `kind` enum('internal','external') NOT NULL,
  `status` enum('draft','in_review','approved','released','obsolete','received','accepted','rejected','filed') NOT NULL DEFAULT 'draft',
  `current_rev_id` int(10) unsigned DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `released_by` int(10) unsigned DEFAULT NULL,
  `received_date` date DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `next_review_date` date DEFAULT NULL,
  `external_ref` varchar(120) DEFAULT NULL,
  `vendor_id` int(10) unsigned DEFAULT NULL,
  `owner_id` int(10) unsigned DEFAULT NULL,
  `approver_id` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `review_required` tinyint(1) NOT NULL DEFAULT 1,
  `training_course_id` int(10) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) unsigned DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_code` (`code`),
  KEY `ix_doc_kind_status` (`kind`,`status`,`deleted_at`),
  KEY `ix_doc_category` (`category_id`),
  KEY `ix_doc_owner` (`owner_id`),
  KEY `ix_doc_vendor` (`vendor_id`),
  KEY `ix_doc_effective` (`effective_date`),
  KEY `ix_doc_expiry` (`expiry_date`),
  KEY `ix_doc_next_review` (`next_review_date`),
  KEY `fk_doc_approver` (`approver_id`),
  KEY `fk_doc_released` (`released_by`),
  KEY `fk_doc_created` (`created_by`),
  KEY `fk_doc_updated` (`updated_by`),
  KEY `fk_doc_current_rev` (`current_rev_id`),
  KEY `idx_documents_doc_no` (`doc_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecn_affected_items`
--

DROP TABLE IF EXISTS `ecn_affected_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecn_affected_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ecn_id` int(10) unsigned NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecn_affected` (`ecn_id`,`item_id`),
  KEY `ix_ecn_affected_back` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecn_history`
--

DROP TABLE IF EXISTS `ecn_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecn_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ecn_id` int(10) unsigned NOT NULL,
  `event` enum('created','edited','submitted','approved','rejected','effective','closed','cancelled','signoff_approved','signoff_rejected','resubmitted','doc_rev_created','auto_drafted') NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `related_id` int(10) unsigned DEFAULT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_ecn_hist_ecn` (`ecn_id`,`id`),
  KEY `fk_ecn_hist_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecn_signoff_slots`
--

DROP TABLE IF EXISTS `ecn_signoff_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecn_signoff_slots` (
  `code` varchar(32) NOT NULL,
  `name` varchar(80) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `role_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`),
  KEY `fk_ecn_slot_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecn_signoffs`
--

DROP TABLE IF EXISTS `ecn_signoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecn_signoffs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ecn_id` int(10) unsigned NOT NULL,
  `slot_code` varchar(32) NOT NULL,
  `decision` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `comment` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecn_signoff` (`ecn_id`,`slot_code`),
  KEY `ix_ecn_signoffs_ecn` (`ecn_id`,`decision`),
  KEY `fk_ecn_signoff_slot` (`slot_code`),
  KEY `fk_ecn_signoff_user` (`decided_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecns`
--

DROP TABLE IF EXISTS `ecns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ecn_no` varchar(32) NOT NULL,
  `title` varchar(240) NOT NULL,
  `ecn_type` enum('drawing_rev','bom_change','material_sub','uom_change','vendor_change','obsolescence','item_change') NOT NULL,
  `status` enum('draft','submitted','in_review','approved','effective','closed','cancelled','rejected') NOT NULL DEFAULT 'draft',
  `originator_id` int(10) unsigned DEFAULT NULL,
  `business_reason` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `type_details` mediumtext DEFAULT NULL,
  `pending_doc_id` int(10) unsigned DEFAULT NULL,
  `pending_item_id` int(10) unsigned DEFAULT NULL,
  `pending_rev_label` varchar(64) DEFAULT NULL,
  `pending_file_name` varchar(255) DEFAULT NULL,
  `pending_file_path` varchar(500) DEFAULT NULL,
  `pending_file_size` bigint(20) unsigned DEFAULT NULL,
  `pending_file_mime` varchar(120) DEFAULT NULL,
  `pending_file_hash` varchar(64) DEFAULT NULL,
  `disp_use_as_is` decimal(14,3) DEFAULT NULL,
  `disp_rework` decimal(14,3) DEFAULT NULL,
  `disp_scrap` decimal(14,3) DEFAULT NULL,
  `disp_sort` decimal(14,3) DEFAULT NULL,
  `disp_notes` text DEFAULT NULL,
  `effectivity_mode` enum('date','lot','manual') NOT NULL DEFAULT 'date',
  `effective_date` date DEFAULT NULL,
  `trigger_txn_id` int(10) unsigned DEFAULT NULL,
  `cutover_done_at` datetime DEFAULT NULL,
  `also_revise_drawings` tinyint(1) NOT NULL DEFAULT 0,
  `drawings_drafted_at` datetime DEFAULT NULL,
  `bom_sweep_required` tinyint(1) NOT NULL DEFAULT 0,
  `bom_sweep_completed_at` datetime DEFAULT NULL,
  `successor_item_id` int(10) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `effective_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(500) DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecn_no` (`ecn_no`),
  KEY `ix_ecns_status` (`status`),
  KEY `ix_ecns_type` (`ecn_type`),
  KEY `ix_ecns_originator` (`originator_id`),
  KEY `ix_ecns_pending_doc` (`pending_doc_id`),
  KEY `ix_ecns_effective` (`effective_date`),
  KEY `fk_ecns_trigger_txn` (`trigger_txn_id`),
  KEY `fk_ecns_successor` (`successor_item_id`),
  KEY `ix_ecns_pending_item` (`pending_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_attachments`
--

DROP TABLE IF EXISTS `inspection_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspection_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `stored_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_inspection` (`inspection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_results`
--

DROP TABLE IF EXISTS `inspection_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspection_results` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int(10) unsigned NOT NULL,
  `sample_no` int(10) unsigned DEFAULT NULL,
  `template_item_id` int(10) unsigned DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `label` varchar(200) NOT NULL,
  `bubble_no` varchar(8) DEFAULT NULL,
  `gdt_symbol` varchar(8) DEFAULT NULL,
  `check_type` enum('numeric','boolean','text','visual','nom','min-max','logic','logical-min-max','logical-nom','notes') NOT NULL DEFAULT 'boolean',
  `target_value` decimal(18,6) DEFAULT NULL,
  `tolerance_lower` decimal(18,6) DEFAULT NULL,
  `tolerance_upper` decimal(18,6) DEFAULT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `measured_value` varchar(255) DEFAULT NULL,
  `pass_fail` enum('pass','fail','na','pending') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `recorded_at` datetime DEFAULT NULL,
  `instrument_asset_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_inspection` (`inspection_id`,`sort_order`),
  KEY `ix_pass_fail` (`pass_fail`),
  KEY `fk_ir_template_item` (`template_item_id`),
  KEY `ix_insp_res_sample` (`inspection_id`,`sample_no`),
  KEY `ix_insp_res_instr` (`instrument_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_template_attachments`
--

DROP TABLE IF EXISTS `inspection_template_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspection_template_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `stored_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `kind` enum('drawing','reference','annotated_drawing') NOT NULL DEFAULT 'reference',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_template` (`template_id`),
  KEY `ix_kind` (`template_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_template_items`
--

DROP TABLE IF EXISTS `inspection_template_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspection_template_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `label` varchar(200) NOT NULL,
  `bubble_no` varchar(8) DEFAULT NULL,
  `gdt_symbol` varchar(8) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `check_type` enum('numeric','boolean','text','visual','nom','min-max','logic','logical-min-max','logical-nom','notes') NOT NULL DEFAULT 'boolean',
  `target_value` decimal(18,6) DEFAULT NULL,
  `tolerance_lower` decimal(18,6) DEFAULT NULL,
  `tolerance_upper` decimal(18,6) DEFAULT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `source_attachment_id` int(10) unsigned DEFAULT NULL,
  `bubble_page` int(10) unsigned DEFAULT NULL,
  `bubble_grid_cell` varchar(8) DEFAULT NULL,
  `bubble_x` decimal(10,3) DEFAULT NULL,
  `bubble_y` decimal(10,3) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `instrument_asset_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_template` (`template_id`,`sort_order`),
  KEY `ix_source_attachment` (`source_attachment_id`),
  KEY `ix_insp_tpl_instr` (`instrument_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_template_targets`
--

DROP TABLE IF EXISTS `inspection_template_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspection_template_targets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned NOT NULL,
  `entity_type` enum('asset','inv_item') NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_link` (`template_id`,`entity_type`,`entity_id`),
  KEY `ix_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_templates`
--

DROP TABLE IF EXISTS `inspection_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspection_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `inspection_type` enum('incoming','asset_cal','finished_goods','first_article','adhoc') DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `ix_type` (`inspection_type`),
  KEY `ix_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_uoms`
--

DROP TABLE IF EXISTS `inspection_uoms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspection_uoms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `symbol` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `ix_active` (`is_active`),
  KEY `ix_category` (`category`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspections`
--

DROP TABLE IF EXISTS `inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inspections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `ir_no` varchar(40) DEFAULT NULL,
  `inspection_type` enum('incoming','asset_cal','finished_goods','first_article','adhoc') NOT NULL DEFAULT 'adhoc',
  `entity_type` enum('asset','inv_item','inv_txn','none') NOT NULL DEFAULT 'none',
  `entity_id` int(10) unsigned DEFAULT NULL,
  `template_id` int(10) unsigned DEFAULT NULL,
  `status` enum('draft','in_progress','pending_approval','passed','failed','rework','hold','cancelled') NOT NULL DEFAULT 'draft',
  `verdict_notes` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `planned_by` int(10) unsigned DEFAULT NULL,
  `planned_at` datetime DEFAULT NULL,
  `inspected_by` int(10) unsigned DEFAULT NULL,
  `inspected_at` datetime DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `qc_release_done` tinyint(1) NOT NULL DEFAULT 0,
  `qc_release_loc_id` int(10) unsigned DEFAULT NULL,
  `qc_release_at` datetime DEFAULT NULL,
  `qc_release_by` int(10) unsigned DEFAULT NULL,
  `part_no` varchar(120) DEFAULT NULL,
  `part_rev` varchar(20) DEFAULT NULL,
  `part_description` varchar(500) DEFAULT NULL,
  `drawing_no` varchar(120) DEFAULT NULL,
  `drawing_rev` varchar(20) DEFAULT NULL,
  `pid` varchar(40) DEFAULT NULL,
  `customer_po_no` varchar(60) DEFAULT NULL,
  `customer_po_line` varchar(20) DEFAULT NULL,
  `pdn_qty` int(11) DEFAULT NULL,
  `chkd_qty` int(11) DEFAULT NULL,
  `accepted_qty` int(11) DEFAULT NULL,
  `sample_count` int(10) unsigned NOT NULL DEFAULT 1,
  `parent_inspection_id` int(10) unsigned DEFAULT NULL,
  `sample_remarks_json` text DEFAULT NULL,
  `job_card_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `ix_entity` (`entity_type`,`entity_id`),
  KEY `ix_type` (`inspection_type`),
  KEY `ix_status` (`status`,`due_date`),
  KEY `ix_planned_at` (`planned_at`),
  KEY `ix_template` (`template_id`),
  KEY `fk_insp_qc_release_loc` (`qc_release_loc_id`),
  KEY `fk_insp_qc_release_by` (`qc_release_by`),
  KEY `ix_insp_ir_no` (`ir_no`),
  KEY `ix_insp_part_no` (`part_no`),
  KEY `ix_insp_cust_po` (`customer_po_no`),
  KEY `ix_insp_parent` (`parent_inspection_id`),
  KEY `ix_insp_job_card` (`job_card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_bom_lines`
--

DROP TABLE IF EXISTS `inv_bom_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_bom_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_item_id` int(10) unsigned NOT NULL,
  `child_item_id` int(10) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `ref_designator` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bom_parent` (`parent_item_id`),
  KEY `idx_bom_child` (`child_item_id`),
  KEY `idx_bom_sort` (`parent_item_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_cert_types`
--

DROP TABLE IF EXISTS `inv_cert_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_cert_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `label` varchar(128) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_cert_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_item_certs`
--

DROP TABLE IF EXISTS `inv_item_certs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_item_certs` (
  `item_id` int(10) unsigned NOT NULL,
  `cert_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`item_id`,`cert_id`),
  KEY `idx_ic_cert` (`cert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_item_location_stock`
--

DROP TABLE IF EXISTS `inv_item_location_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_item_location_stock` (
  `item_id` int(10) unsigned NOT NULL,
  `location_id` int(10) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`,`location_id`),
  KEY `idx_iils_location` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_item_vendors`
--

DROP TABLE IF EXISTS `inv_item_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_item_vendors` (
  `item_id` int(10) unsigned NOT NULL,
  `vendor_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`,`vendor_id`),
  KEY `idx_iv_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_items`
--

DROP TABLE IF EXISTS `inv_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_description` varchar(255) DEFAULT NULL,
  `long_description` text DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `division_id` int(10) unsigned DEFAULT NULL,
  `manufacturer_type` enum('internal','external') NOT NULL DEFAULT 'internal',
  `uom_id` int(10) unsigned DEFAULT NULL,
  `dwg_no` varchar(64) DEFAULT NULL,
  `dwg_rev_no` varchar(32) DEFAULT NULL,
  `part_no` varchar(64) DEFAULT NULL,
  `part_rev_no` varchar(32) DEFAULT NULL,
  `ecn` varchar(64) DEFAULT NULL,
  `process_spec` text DEFAULT NULL,
  `process_step_id` int(10) unsigned DEFAULT NULL,
  `step_no` varchar(32) DEFAULT NULL,
  `step_time_min` decimal(10,2) DEFAULT NULL,
  `step_cost` decimal(12,2) DEFAULT NULL,
  `min_stock_level` decimal(12,3) DEFAULT NULL,
  `min_order_qty` decimal(12,3) DEFAULT NULL,
  `min_sample_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `min_sample_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
  `material_spec` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `rev_label` varchar(64) DEFAULT NULL,
  `uom` varchar(16) NOT NULL DEFAULT 'pcs',
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `stock_on_hand` decimal(12,3) NOT NULL DEFAULT 0.000,
  `stock_rejected` decimal(12,3) NOT NULL DEFAULT 0.000,
  `stock_on_order` decimal(12,3) NOT NULL DEFAULT 0.000,
  `follow_up_note` varchar(255) DEFAULT NULL,
  `is_product` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_obsolete` tinyint(1) NOT NULL DEFAULT 0,
  `billing_product_id` int(10) unsigned DEFAULT NULL,
  `billing_last_push_at` datetime DEFAULT NULL,
  `billing_last_push_op` varchar(16) DEFAULT NULL,
  `billing_last_push_http` int(11) DEFAULT NULL,
  `billing_last_push_error` varchar(255) DEFAULT NULL,
  `billing_last_push_hash` varchar(64) DEFAULT NULL,
  `obsoleted_by_item_id` int(10) unsigned DEFAULT NULL,
  `supersedes_item_id` int(10) unsigned DEFAULT NULL,
  `primary_vendor_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_items_code` (`code`),
  UNIQUE KEY `uq_inv_items_partno_rev` (`part_no`,`part_rev_no`),
  KEY `idx_inv_items_name` (`name`),
  KEY `idx_inv_items_is_product` (`is_product`),
  KEY `idx_inv_items_active` (`is_active`),
  KEY `idx_inv_items_cat` (`category_id`),
  KEY `idx_inv_items_div` (`division_id`),
  KEY `idx_inv_items_uom` (`uom_id`),
  KEY `idx_inv_items_step` (`process_step_id`),
  KEY `idx_inv_items_obs` (`is_obsolete`),
  KEY `idx_inv_items_vendor` (`primary_vendor_id`),
  KEY `fk_inv_obsby` (`obsoleted_by_item_id`),
  KEY `fk_inv_supersedes` (`supersedes_item_id`),
  KEY `idx_inv_items_ecn` (`ecn`),
  KEY `ix_inv_items_billing_id` (`billing_product_id`),
  KEY `ix_inv_items_billing_err` (`billing_last_push_error`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_process_steps`
--

DROP TABLE IF EXISTS `inv_process_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_process_steps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `label` varchar(128) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_step_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_receipts`
--

DROP TABLE IF EXISTS `inv_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_receipts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(32) NOT NULL,
  `shipment_id` int(10) unsigned NOT NULL,
  `shipment_line_id` int(10) unsigned NOT NULL,
  `qty_received` decimal(12,3) NOT NULL,
  `receipt_date` date NOT NULL,
  `due_date_snapshot` date DEFAULT NULL,
  `dst_location_id` int(10) unsigned NOT NULL,
  `txn_id` int(10) unsigned DEFAULT NULL,
  `ref_doc` varchar(64) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `receive_line_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_receipts_no` (`receipt_no`),
  KEY `ix_invr_shipment` (`shipment_id`),
  KEY `ix_invr_line` (`shipment_line_id`),
  KEY `ix_invr_date` (`receipt_date`),
  KEY `fk_invr_loc` (`dst_location_id`),
  KEY `fk_invr_txn` (`txn_id`),
  KEY `fk_invr_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_shipment_lines`
--

DROP TABLE IF EXISTS `inv_shipment_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_shipment_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `line_kind` enum('ship','receive') NOT NULL,
  `entity_type` enum('inv_item','asset') NOT NULL DEFAULT 'inv_item',
  `item_id` int(10) unsigned DEFAULT NULL,
  `asset_id` int(10) unsigned DEFAULT NULL,
  `pending_name` varchar(190) DEFAULT NULL,
  `pending_uom_id` int(10) unsigned DEFAULT NULL,
  `before_date` date DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `qty_planned` decimal(12,3) NOT NULL,
  `uom_id` int(10) unsigned DEFAULT NULL,
  `unit_price` decimal(14,4) DEFAULT NULL,
  `gst_rate` decimal(5,2) DEFAULT NULL,
  `src_location_id` int(10) unsigned DEFAULT NULL,
  `qty_shipped` decimal(12,3) NOT NULL DEFAULT 0.000,
  `qty_received` decimal(12,3) NOT NULL DEFAULT 0.000,
  `notes` varchar(255) DEFAULT NULL,
  `old_transaction_id` int(10) unsigned DEFAULT NULL COMMENT 'Legacy transaction.transaction_id this line was imported from (shipment/receipt.transaction_id). NULL for native lines.',
  PRIMARY KEY (`id`),
  KEY `ix_invsl_shipment` (`shipment_id`),
  KEY `ix_invsl_item` (`item_id`),
  KEY `ix_invsl_kind` (`line_kind`),
  KEY `fk_invsl_src` (`src_location_id`),
  KEY `ix_invsl_asset` (`asset_id`),
  KEY `fk_invsl_puom` (`pending_uom_id`),
  KEY `ix_invsl_old_txn` (`old_transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_shipment_line_sources`
--
-- Per-line source split: a ship line draws its planned qty from one or more
-- source locations, each with its own qty. `inv_shipment_lines.src_location_id`
-- is kept for backward-compat (legacy / single-source lines).
--

DROP TABLE IF EXISTS `inv_shipment_line_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_shipment_line_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shipment_line_id` int(10) unsigned NOT NULL,
  `location_id` int(10) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `ix_invsls_line` (`shipment_line_id`),
  KEY `ix_invsls_loc` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_shipments`
--

DROP TABLE IF EXISTS `inv_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_shipments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ship_no` varchar(32) NOT NULL,
  `vendor_id` int(10) unsigned DEFAULT NULL COMMENT 'Vendor (supplier) counterparty. NULL for customer-bound outbound shipments (e.g. set_invoice).',
  `courier_id` int(10) unsigned DEFAULT NULL,
  `reference` varchar(190) DEFAULT NULL,
  `vendor_contact_id` int(10) unsigned DEFAULT NULL,
  `vendor_address_id` int(10) unsigned DEFAULT NULL,
  `mode` enum('receive','ship','both') NOT NULL DEFAULT 'both',
  `ship_due_date` date DEFAULT NULL,
  `receive_due_date` date DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `packing_forwarding` varchar(255) DEFAULT NULL,
  `freight_insurance` varchar(255) DEFAULT NULL,
  `status` enum('draft','approved','shipped','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `shipped_by` int(10) unsigned DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `actual_ship_date` date DEFAULT NULL,
  `ref_doc` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `notes_po` text DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `is_rework` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_shipments_no` (`ship_no`),
  KEY `ix_inv_shipments_vendor` (`vendor_id`),
  KEY `ix_inv_shipments_status` (`status`,`ship_due_date`),
  KEY `ix_inv_shipments_recv_due` (`receive_due_date`),
  KEY `fk_invs_approver` (`approved_by`),
  KEY `fk_invs_shipper` (`shipped_by`),
  KEY `fk_invs_creator` (`created_by`),
  KEY `fk_invs_contact` (`vendor_contact_id`),
  KEY `fk_invs_address` (`vendor_address_id`),
  KEY `ix_invs_courier` (`courier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_so_pending_summary`
--

DROP TABLE IF EXISTS `inv_so_pending_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_so_pending_summary` (
  `item_code` varchar(64) NOT NULL,
  `so_count` int(10) unsigned NOT NULL DEFAULT 0,
  `qty_pending` decimal(14,3) NOT NULL DEFAULT 0.000,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_supersede_chain`
--

DROP TABLE IF EXISTS `inv_supersede_chain`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_supersede_chain` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `from_item_id` int(10) unsigned NOT NULL,
  `to_item_id` int(10) unsigned NOT NULL,
  `ecn_id` int(10) unsigned DEFAULT NULL,
  `reason` enum('material_sub','obsolescence','other') NOT NULL DEFAULT 'other',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_inv_supchain_from` (`from_item_id`),
  KEY `ix_inv_supchain_to` (`to_item_id`),
  KEY `ix_inv_supchain_ecn` (`ecn_id`),
  KEY `fk_inv_supchain_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_txn_cmm_runs`
--

DROP TABLE IF EXISTS `inv_txn_cmm_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_txn_cmm_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `txn_id` int(10) unsigned NOT NULL,
  `cmm_run_id` int(10) unsigned NOT NULL,
  `linked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `linked_by` int(10) unsigned DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invtxn_cmm` (`txn_id`,`cmm_run_id`),
  KEY `idx_invtxn_cmm_back` (`cmm_run_id`),
  KEY `fk_invtxn_cmm_user` (`linked_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_txn_done_by`
--

DROP TABLE IF EXISTS `inv_txn_done_by`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_txn_done_by` (
  `txn_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`txn_id`,`user_id`),
  KEY `idx_txn_done_by_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_txns`
--

DROP TABLE IF EXISTS `inv_txns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_txns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `txn_type` enum('receive','issue','adjust','process','ship_out','ship_in','move') NOT NULL,
  `txn_date` date NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  `location_id` int(10) unsigned NOT NULL,
  `qty_delta` decimal(12,3) NOT NULL,
  `qty_after` decimal(12,3) NOT NULL,
  `parent_txn_id` int(10) unsigned DEFAULT NULL,
  `ref_doc` varchar(64) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_correction` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_inv_txns_item` (`item_id`,`txn_date`),
  KEY `idx_inv_txns_location` (`location_id`,`txn_date`),
  KEY `idx_inv_txns_parent` (`parent_txn_id`),
  KEY `idx_inv_txns_type` (`txn_type`,`txn_date`),
  KEY `ix_inv_txns_ref_doc` (`ref_doc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inv_uom`
--

DROP TABLE IF EXISTS `inv_uom`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inv_uom` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(16) NOT NULL,
  `label` varchar(64) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_uom_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_attachments`
--

DROP TABLE IF EXISTS `invoice_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `stored_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_invattach_invoice` (`invoice_id`),
  KEY `fk_invattach_user` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_items`
--

DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `item_kind` enum('asset','inv_item','custom') NOT NULL,
  `item_code` varchar(64) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `uom` varchar(16) NOT NULL DEFAULT 'pcs',
  `unit_price` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `gst_rate` decimal(5,2) DEFAULT NULL,
  `hsn_code` varchar(16) DEFAULT NULL,
  `ledger` varchar(64) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_invitems_invoice` (`invoice_id`),
  KEY `ix_invitems_code` (`item_kind`,`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_lines`
--

DROP TABLE IF EXISTS `invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_item_id` int(10) unsigned NOT NULL,
  `link_kind` enum('asset','inv') NOT NULL,
  `asset_txn_id` int(10) unsigned DEFAULT NULL,
  `inv_receipt_id` int(10) unsigned DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_invlines_asset_txn` (`asset_txn_id`),
  KEY `ix_invlines_receipt` (`inv_receipt_id`),
  KEY `fk_invlines_creator` (`created_by`),
  KEY `ix_invlines_invoice_item` (`invoice_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(64) NOT NULL,
  `refno` varchar(32) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `vendor_id` int(10) unsigned NOT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'INR',
  `fy` varchar(16) DEFAULT NULL,
  `dept` varchar(64) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_no` (`invoice_no`),
  KEY `ix_invoices_refno` (`refno`),
  KEY `ix_invoices_vendor` (`vendor_id`),
  KEY `ix_invoices_date` (`invoice_date`),
  KEY `ix_invoices_status` (`status`),
  KEY `fk_invoices_approver` (`approved_by`),
  KEY `fk_invoices_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_card_boxes`
--

DROP TABLE IF EXISTS `job_card_boxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_card_boxes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `job_card_id` int(10) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `box_no` varchar(20) NOT NULL,
  `box_type` varchar(64) DEFAULT NULL,
  `box_size` varchar(64) DEFAULT NULL,
  `weight_kg` decimal(10,3) DEFAULT NULL,
  `qty_in_box` decimal(14,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_jc` (`job_card_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_card_events`
--

DROP TABLE IF EXISTS `job_card_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_card_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `job_card_id` int(10) unsigned NOT NULL,
  `event_type` enum('created','qc_saved','prod_saved','ats_saved','closed','partial_split','cancelled','edited','api_push','note') NOT NULL,
  `event_data` text DEFAULT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_label` varchar(64) DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_jc_time` (`job_card_id`,`occurred_at`),
  KEY `fk_jce_user` (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_cards`
--

DROP TABLE IF EXISTS `job_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_cards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `jc_no` varchar(20) NOT NULL,
  `status` enum('qc_pending','prod_pending','ats_pending','billing_pending','closed','cancelled') NOT NULL DEFAULT 'qc_pending',
  `item_id` int(10) unsigned NOT NULL,
  `po_no` varchar(64) NOT NULL,
  `line_no` varchar(32) DEFAULT NULL,
  `po_qty` decimal(14,3) NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `ack_no` varchar(64) DEFAULT NULL,
  `ds` tinyint(1) NOT NULL DEFAULT 0,
  `invoice_no` varchar(64) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `ats_needed` enum('','Yes','No') NOT NULL DEFAULT '',
  `ppm` enum('','Yes','No') NOT NULL DEFAULT '',
  `qn` enum('','Yes','No') NOT NULL DEFAULT '',
  `batch_qc` varchar(128) DEFAULT NULL,
  `mir_text` text DEFAULT NULL,
  `qc_completed_at` datetime DEFAULT NULL,
  `qc_completed_by` int(10) unsigned DEFAULT NULL,
  `sub_qty` decimal(14,3) DEFAULT NULL,
  `batch_prod` varchar(128) DEFAULT NULL,
  `prod_completed_at` datetime DEFAULT NULL,
  `prod_completed_by` int(10) unsigned DEFAULT NULL,
  `ats_no` varchar(64) DEFAULT NULL,
  `ats_completed_at` datetime DEFAULT NULL,
  `ats_completed_by` int(10) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `shipment_id` int(10) unsigned DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `partial_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jc_no` (`jc_no`),
  KEY `ix_status_created` (`status`,`created_at`),
  KEY `ix_po_line` (`po_no`,`line_no`),
  KEY `ix_item` (`item_id`),
  KEY `ix_parent` (`parent_id`),
  KEY `fk_jc_qcuser` (`qc_completed_by`),
  KEY `fk_jc_produs` (`prod_completed_by`),
  KEY `fk_jc_atsus` (`ats_completed_by`),
  KEY `fk_jc_ship` (`shipment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations`
--

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loc_code` (`code`),
  KEY `ix_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `magdyn_settings`
--

DROP TABLE IF EXISTS `magdyn_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `magdyn_settings` (
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `module_groups`
--

DROP TABLE IF EXISTS `module_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `module_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mg_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modules`
--

DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `modules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `is_group` tinyint(1) NOT NULL DEFAULT 0,
  `icon` varchar(8) DEFAULT NULL,
  `virtual_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `parent_group_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `fk_module_parent` (`parent_id`),
  KEY `fk_modules_group` (`parent_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nda_templates`
--

DROP TABLE IF EXISTS `nda_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nda_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `version` varchar(20) NOT NULL DEFAULT '1.0',
  `description` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_mime` varchar(120) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_ndat_active` (`is_active`,`name`),
  KEY `ix_ndat_name` (`name`,`version`),
  KEY `fk_ndat_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `note_attachments`
--

DROP TABLE IF EXISTS `note_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `note_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `note_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `stored_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_note` (`note_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notes`
--

DROP TABLE IF EXISTS `notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` enum('asset','asset_txn','inv_item','inv_txn','inspection','inspection_template','document') DEFAULT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `note_type_id` int(10) unsigned DEFAULT NULL,
  `body_html` text NOT NULL,
  `author_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `edited_at` datetime DEFAULT NULL,
  `edited_by` int(10) unsigned DEFAULT NULL,
  `redacted_at` datetime DEFAULT NULL,
  `redacted_by` int(10) unsigned DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_entity` (`entity_type`,`entity_id`,`created_at`),
  KEY `ix_type` (`note_type_id`),
  KEY `ix_redacted` (`redacted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_types`
--

DROP TABLE IF EXISTS `notification_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(48) NOT NULL,
  `name` varchar(160) NOT NULL,
  `module_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ntype_code` (`code`),
  KEY `fk_ntype_module` (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `entity_type` varchar(32) NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `headline` varchar(255) NOT NULL,
  `body` varchar(500) DEFAULT NULL,
  `href` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_user_unread` (`user_id`,`is_read`,`created_at`),
  KEY `ix_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `old_inv_po`
--

DROP TABLE IF EXISTS `old_inv_po`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `old_inv_po` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `old_po_id` int(10) unsigned NOT NULL COMMENT 'po PK on old server',
  `po_ref_no` int(11) NOT NULL DEFAULT 0,
  `po_type` int(11) NOT NULL DEFAULT 0,
  `customer` varchar(255) NOT NULL DEFAULT '',
  `customer_contact` varchar(255) NOT NULL DEFAULT '',
  `address` varchar(500) NOT NULL DEFAULT '',
  `shipping_courier` varchar(100) NOT NULL DEFAULT '',
  `shipment_type` varchar(60) NOT NULL DEFAULT '',
  `product` varchar(300) NOT NULL DEFAULT '',
  `quantity` float NOT NULL DEFAULT 0,
  `price` float NOT NULL DEFAULT 0,
  `gst` float NOT NULL DEFAULT 0,
  `uom` varchar(30) NOT NULL DEFAULT '',
  `gst_per` float NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `po_create_date` date DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `internal_notes` varchar(255) DEFAULT NULL,
  `special_instruction` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `long_description` varchar(255) DEFAULT NULL,
  `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_old_inv_po_id` (`old_po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `old_inv_receipts`
--

DROP TABLE IF EXISTS `old_inv_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `old_inv_receipts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `old_receipt_id` int(10) unsigned NOT NULL COMMENT 'receipt_id on old server',
  `old_transaction_id` int(10) unsigned NOT NULL,
  `receipt_number` bigint(20) NOT NULL,
  `from_company` varchar(255) NOT NULL DEFAULT '',
  `receipt_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `received_flag` tinyint(1) NOT NULL DEFAULT 0,
  `txn_note` text DEFAULT NULL,
  `txn_date` datetime DEFAULT NULL,
  `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_old_inv_receipts_id` (`old_receipt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `old_inv_shipments`
--

DROP TABLE IF EXISTS `old_inv_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `old_inv_shipments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `old_shipment_id` int(10) unsigned NOT NULL COMMENT 'shipment_id on old server',
  `old_transaction_id` int(10) unsigned NOT NULL,
  `shipment_number` bigint(20) NOT NULL,
  `from_company` varchar(255) NOT NULL DEFAULT '',
  `to_company` varchar(255) NOT NULL DEFAULT '',
  `ship_date` date DEFAULT NULL,
  `courier_name` varchar(255) NOT NULL DEFAULT '',
  `tracking_number` varchar(50) NOT NULL DEFAULT '',
  `shipped` tinyint(1) NOT NULL DEFAULT 0,
  `txn_note` text DEFAULT NULL,
  `txn_date` datetime DEFAULT NULL,
  `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_old_inv_shipments_id` (`old_shipment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `old_inv_txns`
--

DROP TABLE IF EXISTS `old_inv_txns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `old_inv_txns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `old_id` int(10) unsigned NOT NULL COMMENT 'inventory_transaction_id on old server',
  `old_transaction_id` int(10) unsigned NOT NULL,
  `item_code` varchar(100) NOT NULL DEFAULT '',
  `item_name` varchar(255) NOT NULL DEFAULT '',
  `quantity` float NOT NULL DEFAULT 0,
  `txn_type` varchar(50) NOT NULL DEFAULT '',
  `txn_date` datetime DEFAULT NULL,
  `recorded_date` datetime DEFAULT NULL,
  `source_location` varchar(255) NOT NULL DEFAULT '',
  `dest_location` varchar(255) NOT NULL DEFAULT '',
  `note` text DEFAULT NULL,
  `created_by_name` varchar(120) NOT NULL DEFAULT '',
  `file_url` varchar(100) NOT NULL DEFAULT '',
  `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_old_inv_txns_old_id` (`old_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module_id` int(10) unsigned NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(160) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modperm` (`module_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `process_edges`
--

DROP TABLE IF EXISTS `process_edges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `process_edges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `process_id` int(10) unsigned NOT NULL,
  `from_node_id` int(10) unsigned NOT NULL,
  `to_node_id` int(10) unsigned NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `line_style` enum('solid','dashed','thick') NOT NULL DEFAULT 'solid',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_process_edges_proc` (`process_id`,`sort_order`),
  KEY `ix_process_edges_from` (`from_node_id`),
  KEY `ix_process_edges_to` (`to_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `process_nodes`
--

DROP TABLE IF EXISTS `process_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `process_nodes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `process_id` int(10) unsigned NOT NULL,
  `node_key` varchar(32) NOT NULL,
  `node_type` enum('start','end','step','action','decision','reference') NOT NULL DEFAULT 'step',
  `label` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `ref_url` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_process_node_key` (`process_id`,`node_key`),
  KEY `ix_process_nodes_proc` (`process_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `process_revisions`
--

DROP TABLE IF EXISTS `process_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `process_revisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `process_id` int(10) unsigned NOT NULL,
  `rev_no` int(10) unsigned NOT NULL,
  `change_kind` varchar(32) NOT NULL,
  `change_summary` varchar(500) DEFAULT NULL,
  `snapshot_json` mediumtext NOT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_process_rev_no` (`process_id`,`rev_no`),
  KEY `ix_process_rev_proc` (`process_id`,`created_at`),
  KEY `fk_process_rev_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `process_role_access`
--

DROP TABLE IF EXISTS `process_role_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `process_role_access` (
  `process_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`process_id`,`role_id`),
  KEY `fk_prac_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `process_screenshots`
--

DROP TABLE IF EXISTS `process_screenshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `process_screenshots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `process_id` int(10) unsigned NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_proc_ss` (`process_id`,`sort_order`),
  KEY `fk_proc_ss_user` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `processes`
--

DROP TABLE IF EXISTS `processes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `slug` varchar(64) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `mode` enum('document','structured') NOT NULL DEFAULT 'document',
  `body_html` mediumtext DEFAULT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `tags` varchar(255) DEFAULT NULL,
  `owner_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_processes_slug` (`slug`),
  KEY `ix_processes_status` (`status`),
  KEY `fk_processes_owner` (`owner_id`),
  KEY `fk_processes_creator` (`created_by`),
  KEY `fk_processes_editor` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `po_no` varchar(32) NOT NULL,
  `shipment_id` int(10) unsigned NOT NULL,
  `vendor_id` int(10) unsigned NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `parent_po_id` int(10) unsigned DEFAULT NULL,
  `po_date` date NOT NULL,
  `lines_snapshot` longtext DEFAULT NULL COMMENT 'JSON snapshot of shipment lines+prices frozen at amendment time',
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_no` (`po_no`),
  KEY `ix_po_shipment` (`shipment_id`),
  KEY `ix_po_vendor` (`vendor_id`),
  KEY `fk_po_creator` (`created_by`),
  KEY `ix_po_parent` (`parent_po_id`),
  KEY `ix_po_ship_ver` (`shipment_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `push_subscriptions`
--

DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth_key` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_endpoint` (`endpoint`(255)),
  KEY `ix_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_rp_perm` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sent_emails`
--

DROP TABLE IF EXISTS `sent_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sent_emails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `related_type` varchar(40) DEFAULT NULL,
  `related_id` int(10) unsigned DEFAULT NULL,
  `from_addr` varchar(190) NOT NULL,
  `from_name` varchar(190) DEFAULT NULL,
  `to_addrs` text NOT NULL,
  `cc_addrs` text DEFAULT NULL,
  `bcc_addrs` text DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `body_text` mediumtext DEFAULT NULL,
  `attachments` text DEFAULT NULL,
  `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `error_message` text DEFAULT NULL,
  `sent_by` int(10) unsigned DEFAULT NULL,
  `queued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_se_related` (`related_type`,`related_id`),
  KEY `ix_se_sent_by` (`sent_by`),
  KEY `ix_se_status` (`status`,`queued_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shipping_couriers`
--

DROP TABLE IF EXISTS `shipping_couriers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipping_couriers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_courier_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_courses`
--

DROP TABLE IF EXISTS `training_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_courses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `slug` varchar(64) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `nav_mode` enum('strict','free') NOT NULL DEFAULT 'free',
  `validity_months` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_training_slug` (`slug`),
  KEY `fk_tc_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_prerequisites`
--

DROP TABLE IF EXISTS `training_prerequisites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_prerequisites` (
  `course_id` int(10) unsigned NOT NULL,
  `prereq_course_id` int(10) unsigned NOT NULL,
  `gate_mode` enum('hard','soft') NOT NULL DEFAULT 'soft',
  PRIMARY KEY (`course_id`,`prereq_course_id`),
  KEY `ix_tprereq_prereq` (`prereq_course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_progress`
--

DROP TABLE IF EXISTS `training_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_progress` (
  `user_id` int(10) unsigned NOT NULL,
  `course_id` int(10) unsigned NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`course_id`),
  KEY `fk_tp_course` (`course_id`),
  KEY `ix_tp_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_role_access`
--

DROP TABLE IF EXISTS `training_role_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_role_access` (
  `course_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`course_id`,`role_id`),
  KEY `fk_tra_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_screenshots`
--

DROP TABLE IF EXISTS `training_screenshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_screenshots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` int(10) unsigned NOT NULL,
  `step_id` int(10) unsigned DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_course` (`course_id`),
  KEY `ix_tss_step` (`step_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_step_attempts`
--

DROP TABLE IF EXISTS `training_step_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_step_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `step_id` int(10) unsigned NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `score_pct` decimal(5,2) NOT NULL,
  `passed` tinyint(1) NOT NULL,
  `responses_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tsa_user_step` (`user_id`,`step_id`,`attempted_at`),
  KEY `ix_tsa_step` (`step_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_step_progress`
--

DROP TABLE IF EXISTS `training_step_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_step_progress` (
  `user_id` int(10) unsigned NOT NULL,
  `step_id` int(10) unsigned NOT NULL,
  `completed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `passing_attempt_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`user_id`,`step_id`),
  KEY `ix_tsp_step` (`step_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_step_question_options`
--

DROP TABLE IF EXISTS `training_step_question_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_step_question_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `question_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `body` varchar(500) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_tsqo_q` (`question_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_step_questions`
--

DROP TABLE IF EXISTS `training_step_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_step_questions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `step_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `question_type` enum('single_choice','multi_choice') NOT NULL DEFAULT 'single_choice',
  `body` text NOT NULL,
  `explanation` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_tsq_step` (`step_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_steps`
--

DROP TABLE IF EXISTS `training_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_steps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `title` varchar(200) NOT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `pass_pct` tinyint(3) unsigned NOT NULL DEFAULT 100,
  `max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_tsteps_course` (`course_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_dt_prefs`
--

DROP TABLE IF EXISTS `user_dt_prefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_dt_prefs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `dt_id` varchar(120) NOT NULL,
  `column_key` varchar(120) NOT NULL,
  `display_order` smallint(6) DEFAULT NULL,
  `width_px` smallint(6) DEFAULT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_dt_col` (`user_id`,`dt_id`,`column_key`),
  KEY `ix_udp_user_dt` (`user_id`,`dt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_dt_view_state`
--

DROP TABLE IF EXISTS `user_dt_view_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_dt_view_state` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `dt_id` varchar(120) NOT NULL,
  `state_json` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_dt_view` (`user_id`,`dt_id`),
  KEY `ix_udvs_user_dt` (`user_id`,`dt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_mobile_modules`
--

DROP TABLE IF EXISTS `user_mobile_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_mobile_modules` (
  `user_id` int(10) unsigned NOT NULL,
  `module_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`module_id`),
  KEY `fk_umm_module` (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_notification_prefs`
--

DROP TABLE IF EXISTS `user_notification_prefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notification_prefs` (
  `user_id` int(10) unsigned NOT NULL,
  `notification_type_id` int(10) unsigned NOT NULL,
  `channel_web` tinyint(1) NOT NULL DEFAULT 1,
  `channel_email` tinyint(1) NOT NULL DEFAULT 1,
  `channel_push` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`,`notification_type_id`),
  KEY `fk_unp_type` (`notification_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `fk_ur_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `email` varchar(190) NOT NULL,
  `full_name` varchar(190) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `sso_provider` varchar(32) DEFAULT NULL,
  `external_id` varchar(190) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_sso` (`sso_provider`,`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_info`
--

DROP TABLE IF EXISTS `users_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) NOT NULL,
  `status` int(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_addresses`
--

DROP TABLE IF EXISTS `vendor_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_addresses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_id` int(10) unsigned NOT NULL,
  `label` varchar(80) DEFAULT NULL,
  `line1` varchar(190) NOT NULL,
  `line2` varchar(190) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `country` varchar(80) NOT NULL DEFAULT 'India',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor_addresses_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_application_categories`
--

DROP TABLE IF EXISTS `vendor_application_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_application_categories` (
  `application_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`application_id`,`category_id`),
  KEY `ix_vac_cat` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_application_documents`
--

DROP TABLE IF EXISTS `vendor_application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_application_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `doc_type` enum('pan','gst','msme','udyam','cin','bank_proof','cancelled_cheque','iso_cert','quality_manual','company_profile','director_id','nda_signed','other') NOT NULL DEFAULT 'other',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_mime` varchar(120) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `uploaded_by` int(10) unsigned NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_appdoc_app` (`application_id`,`doc_type`),
  KEY `fk_appdoc_user` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_application_history`
--

DROP TABLE IF EXISTS `vendor_application_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_application_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) NOT NULL,
  `note` text DEFAULT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_apphist_app` (`application_id`,`created_at`),
  KEY `fk_apphist_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_applications`
--

DROP TABLE IF EXISTS `vendor_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_no` varchar(40) NOT NULL,
  `status` enum('draft','submitted','under_review','clarifications','approved','rejected') NOT NULL DEFAULT 'draft',
  `existing_vendor_id` int(10) unsigned DEFAULT NULL,
  `approved_vendor_id` int(10) unsigned DEFAULT NULL,
  `legal_name` varchar(190) NOT NULL,
  `trade_name` varchar(190) DEFAULT NULL,
  `business_type` enum('proprietorship','partnership','llp','pvt_ltd','public_ltd','huf','government','trust','other') NOT NULL DEFAULT 'pvt_ltd',
  `year_established` smallint(5) unsigned DEFAULT NULL,
  `employee_count` int(10) unsigned DEFAULT NULL,
  `annual_turnover_range` varchar(40) DEFAULT NULL,
  `address_line1` varchar(200) DEFAULT NULL,
  `address_line2` varchar(200) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `country` varchar(120) NOT NULL DEFAULT 'India',
  `pan_no` varchar(20) DEFAULT NULL,
  `gst_no` varchar(20) DEFAULT NULL,
  `msme_no` varchar(40) DEFAULT NULL,
  `udyam_no` varchar(40) DEFAULT NULL,
  `cin` varchar(30) DEFAULT NULL,
  `bank_name` varchar(190) DEFAULT NULL,
  `bank_branch` varchar(190) DEFAULT NULL,
  `bank_account_no` varchar(40) DEFAULT NULL,
  `bank_account_type` enum('current','savings','cash_credit','overdraft','other') DEFAULT NULL,
  `bank_ifsc` varchar(20) DEFAULT NULL,
  `contact_salutation` varchar(10) DEFAULT NULL,
  `contact_name` varchar(150) DEFAULT NULL,
  `contact_designation` varchar(120) DEFAULT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `contact_phone` varchar(40) DEFAULT NULL,
  `categories` varchar(500) DEFAULT NULL,
  `capabilities` text DEFAULT NULL,
  `iso_certified` tinyint(1) NOT NULL DEFAULT 0,
  `iso_certificate_no` varchar(120) DEFAULT NULL,
  `nda_on_file` tinyint(1) NOT NULL DEFAULT 0,
  `nda_signed_date` date DEFAULT NULL,
  `nda_expiry_date` date DEFAULT NULL,
  `nda_template_id` int(10) unsigned DEFAULT NULL,
  `renewal_of_application_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `submitted_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `decision_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_no` (`application_no`),
  KEY `ix_app_status` (`status`),
  KEY `ix_app_existing_v` (`existing_vendor_id`),
  KEY `ix_app_approved_v` (`approved_vendor_id`),
  KEY `ix_app_legal_name` (`legal_name`),
  KEY `ix_app_pan` (`pan_no`),
  KEY `ix_app_gst` (`gst_no`),
  KEY `ix_app_created` (`created_at`),
  KEY `fk_app_created_by` (`created_by`),
  KEY `fk_app_submitted_by` (`submitted_by`),
  KEY `fk_app_reviewed_by` (`reviewed_by`),
  KEY `ix_app_expires` (`expires_at`),
  KEY `ix_app_nda_template` (`nda_template_id`),
  KEY `ix_app_renewal_of` (`renewal_of_application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_assets`
--

DROP TABLE IF EXISTS `vendor_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_assets` (
  `vendor_id` int(10) unsigned NOT NULL,
  `asset_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vendor_id`,`asset_id`),
  KEY `idx_va_asset` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_categories`
--

DROP TABLE IF EXISTS `vendor_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vc_code` (`code`),
  KEY `ix_vc_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_contacts`
--

DROP TABLE IF EXISTS `vendor_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_id` int(10) unsigned NOT NULL,
  `salutation` varchar(16) DEFAULT NULL,
  `name` varchar(190) NOT NULL,
  `designation` varchar(120) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor_contacts_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_portal_tokens`
--

DROP TABLE IF EXISTS `vendor_portal_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_portal_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `token` char(64) NOT NULL,
  `purpose` enum('fill','renewal') NOT NULL DEFAULT 'fill',
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `use_count` int(10) unsigned NOT NULL DEFAULT 0,
  `revoked_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vpt_token` (`token`),
  KEY `ix_vpt_app` (`application_id`,`expires_at`),
  KEY `fk_vpt_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendors`
--

DROP TABLE IF EXISTS `vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(190) NOT NULL,
  `contact` varchar(150) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `gst_no` varchar(20) DEFAULT NULL,
  `pan_no` varchar(20) DEFAULT NULL,
  `payment_terms` varchar(190) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `empaneled` tinyint(1) NOT NULL DEFAULT 0,
  `empaneled_at` datetime DEFAULT NULL,
  `empanelment_application_id` int(10) unsigned DEFAULT NULL,
  `empanelment_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `bank_account_name` varchar(190) DEFAULT NULL,
  `bank_account_number` varchar(64) DEFAULT NULL,
  `bank_ifsc` varchar(32) DEFAULT NULL,
  `bank_swift` varchar(32) DEFAULT NULL,
  `bank_name` varchar(190) DEFAULT NULL,
  `bank_branch` varchar(190) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_code` (`code`),
  KEY `ix_vendor_empan_app` (`empanelment_application_id`),
  KEY `ix_vendor_empan_expires` (`empanelment_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'magdyn'
--

--
-- Dumping routines for database 'magdyn'
--
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `_recover_fks` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`u184502428_erp`@`127.0.0.1` PROCEDURE `_recover_fks`()
BEGIN
    DECLARE has_fk INT DEFAULT 0;

    -- modules.parent_group_id → module_groups
    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'modules'
       AND CONSTRAINT_NAME = 'fk_modules_group';
    IF has_fk = 0 THEN
        ALTER TABLE modules ADD CONSTRAINT fk_modules_group
            FOREIGN KEY (parent_group_id) REFERENCES module_groups(id) ON DELETE SET NULL;
    END IF;

    -- modules.parent_id → modules (self-ref)
    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'modules'
       AND CONSTRAINT_NAME = 'fk_module_parent';
    IF has_fk = 0 THEN
        ALTER TABLE modules ADD CONSTRAINT fk_module_parent
            FOREIGN KEY (parent_id) REFERENCES modules(id) ON DELETE SET NULL;
    END IF;

    -- asset_models.category_id → categories
    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'asset_models'
       AND CONSTRAINT_NAME = 'fk_model_category';
    IF has_fk = 0 THEN
        ALTER TABLE asset_models ADD CONSTRAINT fk_model_category
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;
    END IF;

    -- inv_items FKs (4)
    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_items'
       AND CONSTRAINT_NAME = 'fk_inv_items_cat';
    IF has_fk = 0 THEN
        ALTER TABLE inv_items ADD CONSTRAINT fk_inv_items_cat
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;
    END IF;

    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_items'
       AND CONSTRAINT_NAME = 'fk_inv_items_div';
    IF has_fk = 0 THEN
        ALTER TABLE inv_items ADD CONSTRAINT fk_inv_items_div
            FOREIGN KEY (division_id) REFERENCES categories(id) ON DELETE SET NULL;
    END IF;

    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_items'
       AND CONSTRAINT_NAME = 'fk_inv_items_uom';
    IF has_fk = 0 THEN
        ALTER TABLE inv_items ADD CONSTRAINT fk_inv_items_uom
            FOREIGN KEY (uom_id) REFERENCES inv_uom(id) ON DELETE SET NULL;
    END IF;

    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_items'
       AND CONSTRAINT_NAME = 'fk_inv_items_step';
    IF has_fk = 0 THEN
        ALTER TABLE inv_items ADD CONSTRAINT fk_inv_items_step
            FOREIGN KEY (process_step_id) REFERENCES inv_process_steps(id) ON DELETE SET NULL;
    END IF;

    -- inv_shipments FK pair from _193000 (note: the column adds and FKs
    -- are also covered by migration_20260523_073500_IST.sql, but
    -- because those use different constraint names — fk_inv_shipments_*
    -- vs fk_invs_* — the install may have one set or the other or
    -- neither. We add the older _193000 set here only if neither
    -- variant exists, so we don't end up with two FKs doing the same
    -- thing.).
    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_shipments'
       AND CONSTRAINT_NAME IN ('fk_inv_shipments_contact','fk_invs_contact');
    IF has_fk = 0 THEN
        -- Column must exist first; check
        SELECT COUNT(*) INTO has_fk FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_shipments'
           AND COLUMN_NAME = 'vendor_contact_id';
        IF has_fk > 0 THEN
            ALTER TABLE inv_shipments ADD CONSTRAINT fk_inv_shipments_contact
                FOREIGN KEY (vendor_contact_id) REFERENCES vendor_contacts(id) ON DELETE SET NULL;
        END IF;
    END IF;

    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_shipments'
       AND CONSTRAINT_NAME IN ('fk_inv_shipments_address','fk_invs_address');
    IF has_fk = 0 THEN
        SELECT COUNT(*) INTO has_fk FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_shipments'
           AND COLUMN_NAME = 'vendor_address_id';
        IF has_fk > 0 THEN
            ALTER TABLE inv_shipments ADD CONSTRAINT fk_inv_shipments_address
                FOREIGN KEY (vendor_address_id) REFERENCES vendor_addresses(id) ON DELETE SET NULL;
        END IF;
    END IF;

    -- inv_receipts.receive_line_id → inv_shipment_receive_lines
    SELECT COUNT(*) INTO has_fk FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_receipts'
       AND CONSTRAINT_NAME = 'fk_inv_receipts_recvline';
    IF has_fk = 0 THEN
        SELECT COUNT(*) INTO has_fk FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_receipts'
           AND COLUMN_NAME = 'receive_line_id';
        IF has_fk > 0 THEN
            ALTER TABLE inv_receipts ADD CONSTRAINT fk_inv_receipts_recvline
                FOREIGN KEY (receive_line_id) REFERENCES inv_shipment_receive_lines(id) ON DELETE SET NULL;
        END IF;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
