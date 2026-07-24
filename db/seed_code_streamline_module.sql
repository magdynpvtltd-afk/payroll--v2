-- ---------------------------------------------------------------------------
-- MagDyn — register the "Streamline codes" admin page.
-- Created: 2026-07-23 IST
--
-- Adds /code_streamline.php under the Admin sidebar group, its two
-- permissions (view / manage), grants them to the same roles that hold
-- code_sequences (admin + manager), and creates the audit tables the page
-- records each run into.
--
-- Idempotent + environment-independent:
--   * resolves the Admin group and roles by CODE (not hardcoded ids);
--   * INSERT IGNORE + unique keys make re-runs a no-op;
--   * CREATE TABLE IF NOT EXISTS for the audit tables.
--
-- Run on every environment (local + production):
--   mysql -uroot magdyn < db/seed_code_streamline_module.sql
-- ---------------------------------------------------------------------------

-- 1) Sidebar module row (Admin group, just after Code sequences at 150).
SET @admin := (SELECT id FROM modules WHERE code = 'admin' AND is_group = 1 LIMIT 1);

INSERT IGNORE INTO modules
    (code, name, description, is_group, parent_id, icon, virtual_url, sort_order, is_active)
VALUES
    ('code_streamline',
     'Streamline codes',
     'One-click conversion of non-numeric asset tags / inventory item codes into plain numeric codes.',
     0, @admin, NULL, NULL, 155, 1);   -- icon comes from module_icon() static map (#-keycap)

SET @mod := (SELECT id FROM modules WHERE code = 'code_streamline' LIMIT 1);

-- 2) Permissions: view + manage.
INSERT IGNORE INTO permissions (module_id, code, name) VALUES
    (@mod, 'view',   'View Streamline codes'),
    (@mod, 'manage', 'Manage Streamline codes');

-- 3) Grant to the roles that already manage code_sequences (admin, manager).
SET @p_view   := (SELECT id FROM permissions WHERE module_id = @mod AND code = 'view'   LIMIT 1);
SET @p_manage := (SELECT id FROM permissions WHERE module_id = @mod AND code = 'manage' LIMIT 1);
SET @r_admin   := (SELECT id FROM roles WHERE code = 'admin'   LIMIT 1);
SET @r_manager := (SELECT id FROM roles WHERE code = 'manager' LIMIT 1);

INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES
    (@r_admin,   @p_view),
    (@r_admin,   @p_manage),
    (@r_manager, @p_view),
    (@r_manager, @p_manage);

-- 4) Audit tables (the page also self-heals these via CREATE TABLE IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS `code_streamline_runs` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `kind` enum('asset','inventory') NOT NULL,
    `scope` varchar(20) NOT NULL DEFAULT 'active',
    `format` varchar(20) NOT NULL DEFAULT 'plain',
    `start_number` int(10) unsigned NOT NULL,
    `total_changed` int(10) unsigned NOT NULL DEFAULT 0,
    `min_new_code` varchar(64) DEFAULT NULL,
    `max_new_code` varchar(64) DEFAULT NULL,
    `seq_updated` tinyint(1) NOT NULL DEFAULT 0,
    `run_by` int(10) unsigned DEFAULT NULL,
    `run_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `ix_csr_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `code_streamline_map` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `run_id` int(10) unsigned NOT NULL,
    `kind` enum('asset','inventory') NOT NULL,
    `entity_id` int(10) unsigned NOT NULL,
    `entity_name` varchar(255) DEFAULT NULL,
    `extra_info` varchar(500) DEFAULT NULL,
    `old_code` varchar(64) NOT NULL,
    `new_code` varchar(64) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_csm_run` (`run_id`),
    KEY `ix_csm_entity` (`kind`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
