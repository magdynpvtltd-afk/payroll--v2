-- MagDyn — migration_20260616_130000_IST
-- Register the admin "Old Inventory Import" hub (old_inventory_admin.php) under
-- the Admin group, with a view permission granted to the admin role only.
--
-- The page is a launcher: it gathers every "Import from Old Inventory" flow and
-- its matching delete / reset controls into one Admin-only place. It adds NO
-- data columns — only this menu / permission registration.
--
-- Idempotent + phpMyAdmin-safe (procedure with local DECLAREs, no SET @var).

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS magdyn_p_add_old_inventory_admin;
DELIMITER //
CREATE PROCEDURE magdyn_p_add_old_inventory_admin()
BEGIN
    DECLARE v_parent INT DEFAULT NULL;
    DECLARE v_mod    INT DEFAULT NULL;
    DECLARE v_pview  INT DEFAULT NULL;

    -- 1) Sidebar module under the existing Admin group.
    IF NOT EXISTS (SELECT 1 FROM modules WHERE code = 'old_inventory_admin') THEN
        SELECT id INTO v_parent FROM modules WHERE code = 'admin' LIMIT 1;
        INSERT INTO modules (code, name, description, parent_id, is_group, virtual_url, sort_order, is_active)
        VALUES ('old_inventory_admin', 'Old Inventory Import',
                'Admin hub for all import-from-old-inventory and delete / reset flows',
                v_parent, 0, '/old_inventory_admin.php', 110, 1);
    END IF;

    SELECT id INTO v_mod FROM modules WHERE code = 'old_inventory_admin' LIMIT 1;

    -- 2) View permission for the new module.
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'view') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'view', 'View old inventory import hub');
    END IF;

    -- 3) Grant it to the admin role so it appears (admin only).
    SELECT id INTO v_pview FROM permissions WHERE module_id = v_mod AND code = 'view' LIMIT 1;

    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, v_pview FROM roles r
     WHERE r.code = 'admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = v_pview);
END //
DELIMITER ;
CALL magdyn_p_add_old_inventory_admin();
DROP PROCEDURE magdyn_p_add_old_inventory_admin;
