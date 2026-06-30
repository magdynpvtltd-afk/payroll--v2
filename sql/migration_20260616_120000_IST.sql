-- MagDyn — migration_20260616_120000_IST
-- Employee directory (`users_info`) + admin "Employees" module.
--
-- `users_info` is a lightweight people list (shop-floor / staff names) that is
-- independent of the login `users` table. It is the source for the inventory
-- "Process ▸ Done by" picker (who physically performed a process), so those
-- names need not be system login accounts.
--
--   status: 1 = active, 0 = inactive
--
-- This migration:
--   1) creates `users_info` and seeds the known roster (idempotent),
--   2) registers the `employees` admin module (employees.php) under the
--      Admin group with view/create/manage/delete permissions,
--   3) grants all four permissions to the admin role.
--
-- Idempotent + phpMyAdmin-safe (procedure with local DECLAREs, no SET @var).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) Employee table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users_info` (
  `id`     int(11)    NOT NULL AUTO_INCREMENT,
  `name`   varchar(45) NOT NULL,
  `status` int(1)     NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the roster only on a fresh table (don't clobber edits on re-run).
INSERT INTO `users_info` (`id`, `name`, `status`)
SELECT * FROM (
    SELECT  1 AS id, 'Dhruva'        AS name, 1 AS status UNION ALL
    SELECT  2, 'Vignesh', 0        UNION ALL
    SELECT  3, 'RSK', 1            UNION ALL
    SELECT  4, 'Kevin', 0          UNION ALL
    SELECT  5, 'Thiru', 0          UNION ALL
    SELECT  6, 'Rajeev', 1         UNION ALL
    SELECT  7, 'Ajith', 0          UNION ALL
    SELECT  8, 'Kowshig', 0        UNION ALL
    SELECT  9, 'Mohan', 0          UNION ALL
    SELECT 10, 'Mahesh', 0         UNION ALL
    SELECT 11, 'Vidhya', 1         UNION ALL
    SELECT 12, 'Anju', 1           UNION ALL
    SELECT 13, 'Rekha', 1          UNION ALL
    SELECT 14, 'Ramesh', 0         UNION ALL
    SELECT 15, 'Pramoth', 1        UNION ALL
    SELECT 16, 'Saravanan', 1      UNION ALL
    SELECT 17, 'Guna', 1           UNION ALL
    SELECT 18, 'Prasanna kumar', 0 UNION ALL
    SELECT 19, 'Antony', 0         UNION ALL
    SELECT 21, 'Prem', 1           UNION ALL
    SELECT 22, 'Anthony Peter', 1  UNION ALL
    SELECT 23, 'Mahalakshmi', 0    UNION ALL
    SELECT 24, 'Vinod', 1          UNION ALL
    SELECT 25, 'Vijay Prakash', 0  UNION ALL
    SELECT 26, 'PalaniRaj', 0      UNION ALL
    SELECT 27, 'Sathish', 1        UNION ALL
    SELECT 28, 'James', 1          UNION ALL
    SELECT 29, 'Munisamy', 1       UNION ALL
    SELECT 30, 'Muthu', 1          UNION ALL
    SELECT 31, 'RajMohan', 1       UNION ALL
    SELECT 32, 'Umapathi', 1       UNION ALL
    SELECT 33, 'Bikash', 1         UNION ALL
    SELECT 34, 'Rajesh', 1         UNION ALL
    SELECT 35, 'Abishek', 1        UNION ALL
    SELECT 36, 'Ashwanth', 0       UNION ALL
    SELECT 37, 'RamKumar', 1       UNION ALL
    SELECT 38, 'RaviKumar', 1      UNION ALL
    SELECT 39, 'SriRam', 1         UNION ALL
    SELECT 40, 'Sahul Hameed', 1   UNION ALL
    SELECT 41, 'Namasivayam', 1    UNION ALL
    SELECT 42, 'Revathi', 1        UNION ALL
    SELECT 43, 'Arul Raj', 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `users_info` LIMIT 1);

-- ---------------------------------------------------------------------------
-- 2) + 3) Module registration + permission grants
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS magdyn_p_add_employees;
DELIMITER //
CREATE PROCEDURE magdyn_p_add_employees()
BEGIN
    DECLARE v_parent  INT DEFAULT NULL;
    DECLARE v_mod     INT DEFAULT NULL;
    DECLARE v_pid     INT DEFAULT NULL;

    -- Sidebar module under the existing Admin group.
    IF NOT EXISTS (SELECT 1 FROM modules WHERE code = 'employees') THEN
        SELECT id INTO v_parent FROM modules WHERE code = 'admin' LIMIT 1;
        INSERT INTO modules (code, name, description, parent_id, is_group, icon, virtual_url, sort_order, is_active)
        VALUES ('employees', 'Employees',
                'Shop-floor / staff directory used by the Process "Done by" picker',
                v_parent, 0, '👷', '/employees.php', 115, 1);
    END IF;

    SELECT id INTO v_mod FROM modules WHERE code = 'employees' LIMIT 1;

    -- Permissions for the new module.
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'view') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'view', 'View employees');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'create') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'create', 'Create employees');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'manage') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'manage', 'Edit / activate employees');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'delete') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'delete', 'Delete employees');
    END IF;

    -- Grant every employees permission to the admin role so it works immediately.
    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id
      FROM roles r
      JOIN permissions p ON p.module_id = v_mod
     WHERE r.code = 'admin'
       AND NOT EXISTS (
            SELECT 1 FROM role_permissions rp
             WHERE rp.role_id = r.id AND rp.permission_id = p.id
       );
END //
DELIMITER ;
CALL magdyn_p_add_employees();
DROP PROCEDURE magdyn_p_add_employees;
