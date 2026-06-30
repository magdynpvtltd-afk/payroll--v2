<?php
/**
 * MagDyn — SSO permissions manifest.
 *
 * Unlike the static example in ONBOARDING, MagDyn's permissions are DB-driven
 * (modules + permissions + roles + role_permissions), so this manifest is
 * generated live from those tables. That way it never drifts from what the app
 * actually enforces — re-running sync_permissions.php always publishes the
 * current catalogue.
 *
 * Permission naming follows MagDyn's own convention: <module_code>.<perm_code>
 * (e.g. users.view, training.manage), which already matches the SSO server's
 * expected resource.action shape.
 *
 * Returns the manifest array (consumed by sync_permissions.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';

// ---- Permissions: every module.permission pair the app defines ----
$permRows = db_all(
    "SELECT CONCAT(m.code, '.', p.code) AS name,
            CONCAT(m.name, ' — ', p.name) AS description
       FROM permissions p
       JOIN modules m ON m.id = p.module_id
      ORDER BY m.code, p.code"
);

// ---- Roles: each role with its current permission set ----
$roleRows = db_all('SELECT id, code, name, description FROM roles ORDER BY code');
$roles = [];
foreach ($roleRows as $r) {
    $rolePerms = db_all(
        "SELECT CONCAT(m.code, '.', p.code) AS name
           FROM role_permissions rp
           JOIN permissions p ON p.id = rp.permission_id
           JOIN modules m     ON m.id = p.module_id
          WHERE rp.role_id = ?
          ORDER BY m.code, p.code",
        [(int)$r['id']]
    );
    $roles[] = [
        'name'        => $r['code'],
        'description' => $r['description'] ?: $r['name'],
        'permissions' => array_column($rolePerms, 'name'),
    ];
}

return [
    'permissions' => $permRows,
    'roles'       => $roles,
];
