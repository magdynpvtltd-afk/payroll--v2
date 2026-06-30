-- MagDyn — migration_20260612_181500_IST
-- Register the admin "Creator Backfill" module (admin_user_backfill.php) under
-- the Admin group, with view/manage permissions granted to the admin role.
--
-- The module stamps the original created-by user (pulled from the old inventory
-- system via api_export_audit_users.php) onto already-imported records. It adds
-- NO data columns — only this menu/permission registration.
--
-- Idempotent + phpMyAdmin-safe (procedure with local DECLAREs, no SET @var).

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS magdyn_p_add_creator_backfill;
DELIMITER //
CREATE PROCEDURE magdyn_p_add_creator_backfill()
BEGIN
    DECLARE v_parent  INT DEFAULT NULL;
    DECLARE v_mod     INT DEFAULT NULL;
    DECLARE v_pview   INT DEFAULT NULL;
    DECLARE v_pmanage INT DEFAULT NULL;

    -- 1) Sidebar module under the existing Admin group.
    IF NOT EXISTS (SELECT 1 FROM modules WHERE code = 'creator_backfill') THEN
        SELECT id INTO v_parent FROM modules WHERE code = 'admin' LIMIT 1;
        INSERT INTO modules (code, name, description, parent_id, is_group, virtual_url, sort_order, is_active)
        VALUES ('creator_backfill', 'Creator Backfill',
                'Stamp original created-by users from the old inventory system',
                v_parent, 0, '/admin_user_backfill.php', 120, 1);
    END IF;

    SELECT id INTO v_mod FROM modules WHERE code = 'creator_backfill' LIMIT 1;

    -- 2) Permissions for the new module.
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'view') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'view', 'View creator backfill');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'manage') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'manage', 'Run creator backfill');
    END IF;

    -- 3) Grant both to the admin role so it works immediately.
    SELECT id INTO v_pview   FROM permissions WHERE module_id = v_mod AND code = 'view'   LIMIT 1;
    SELECT id INTO v_pmanage FROM permissions WHERE module_id = v_mod AND code = 'manage' LIMIT 1;

    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, v_pview FROM roles r
     WHERE r.code = 'admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = v_pview);

    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, v_pmanage FROM roles r
     WHERE r.code = 'admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = v_pmanage);
END //
DELIMITER ;
CALL magdyn_p_add_creator_backfill();
DROP PROCEDURE magdyn_p_add_creator_backfill;
