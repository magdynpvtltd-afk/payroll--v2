<?php
/**
 * MagDyn — Roles & Permissions
 * Created: 20260515_060024_IST
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('roles', 'view');

$action = (string)input('action', 'index');

if ($action === 'save') {
    require_permission('roles', 'manage');
    csrf_check();
    $id   = (int)input('id', 0);
    $code = trim((string)input('code'));
    $name = trim((string)input('name'));
    $desc = trim((string)input('description'));
    $perms = isset($_POST['perms']) && is_array($_POST['perms'])
        ? array_map('intval', $_POST['perms']) : [];

    if ($code === '' || $name === '') {
        flash_set('error', 'Code and name are required.');
        redirect($id ? url('/roles.php?action=edit&id=' . $id) : url('/roles.php?action=new'));
    }

    if ($id) {
        $row = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
        if (!$row) { flash_set('error', 'Role not found.'); redirect(url('/roles.php')); }
        // System roles: name/desc/perms editable, code locked.
        if ($row['is_system']) {
            db_exec('UPDATE roles SET name = ?, description = ? WHERE id = ?', [$name, $desc, $id]);
        } else {
            db_exec('UPDATE roles SET code = ?, name = ?, description = ? WHERE id = ?', [$code, $name, $desc, $id]);
        }
        db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
        foreach ($perms as $pid) {
            db_exec('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$id, $pid]);
        }
        flash_set('success', 'Role updated.');
    } else {
        $exists = db_one('SELECT id FROM roles WHERE code = ?', [$code]);
        if ($exists) { flash_set('error', 'A role with that code already exists.'); redirect(url('/roles.php?action=new')); }
        db_exec('INSERT INTO roles (code, name, description, is_system) VALUES (?, ?, ?, 0)', [$code, $name, $desc]);
        $newId = db()->lastInsertId();
        foreach ($perms as $pid) {
            db_exec('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$newId, $pid]);
        }
        flash_set('success', 'Role created.');
    }
    redirect(url('/roles.php'));
}

if ($action === 'delete') {
    require_permission('roles', 'manage');
    csrf_check();
    $id  = (int)input('id', 0);
    $row = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
    if (!$row) { flash_set('error', 'Role not found.'); }
    elseif ($row['is_system']) { flash_set('error', 'System roles cannot be deleted.'); }
    else {
        db_exec('DELETE FROM roles WHERE id = ?', [$id]);
        flash_set('success', 'Role deleted.');
    }
    redirect(url('/roles.php'));
}

// ============================================================
// CLONE — duplicate role + role_permissions
// ============================================================
// Copies the role + every role_permissions row. Does NOT copy
// user_roles assignments (cloning a role + auto-granting it to every
// user the original had is a dangerous default). is_system always
// resets to 0 — a clone is never system-protected.
if ($action === 'clone') {
    require_permission('roles', 'manage');
    csrf_check();
    $id  = (int)input('id', 0);
    $src = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
    if (!$src) {
        flash_set('error', 'Role not found.');
        redirect(url('/roles.php'));
    }

    $newCode = clone_unique_code('roles', 'code', $src['code']);
    $newName = 'Copy of ' . $src['name'];

    $newId = clone_row('roles', $id, [
        'code'      => $newCode,
        'name'      => $newName,
        'is_system' => 0,
    ]);
    if ($newId <= 0) {
        flash_set('error', 'Role clone failed.');
        redirect(url('/roles.php'));
    }

    // Copy permission grants
    db_exec(
        'INSERT INTO role_permissions (role_id, permission_id)
         SELECT ?, permission_id FROM role_permissions WHERE role_id = ?',
        [$newId, $id]
    );

    $permCount = (int)db_val('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$newId], 0);
    flash_set('success', 'Role cloned to "' . $newCode . '" with ' . $permCount . ' permission'
        . ($permCount === 1 ? '' : 's') . '. Adjust as needed and save.');
    redirect(url('/roles.php?action=edit&id=' . $newId));
}

// ============================================================
// EDIT / NEW
// ============================================================
if ($action === 'new' || $action === 'edit') {
    require_permission('roles', 'manage');
    $editing = null;
    $rolePerms = [];
    if ($action === 'edit') {
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
        if (!$editing) { flash_set('error', 'Role not found.'); redirect(url('/roles.php')); }
        $rolePerms = array_column(
            db_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$id]),
            'permission_id'
        );
    }
    $matrix = permissions_matrix();

    $page_title  = $editing ? 'Edit role' : 'New role';
    $page_module = 'roles';
    $focus_id    = 'f_code';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit role' : 'New role',
            'subtitle'    => $editing ? $editing['name'] : 'Define a new role and its permissions',
            'back_href'   => url('/roles.php'),
            'back_label'  => 'Roles',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/roles.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/roles.php?action=save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="f_code"><?= shortcut_label('Code', 'C') ?> *</label>
                    <input id="f_code" name="code" type="text" required tabindex="1"
                           value="<?= h($editing['code'] ?? '') ?>"
                           <?= ($editing && $editing['is_system']) ? 'readonly' : '' ?>>
                    <?php if ($editing && $editing['is_system']): ?>
                        <span class="muted small">System role — code is locked.</span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="f_name"><?= shortcut_label('Name', 'N') ?> *</label>
                    <input id="f_name" name="name" type="text" required tabindex="2"
                           value="<?= h($editing['name'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_desc"><?= shortcut_label('Description', 'D') ?></label>
                    <input id="f_desc" name="description" type="text" tabindex="3"
                           value="<?= h($editing['description'] ?? '') ?>">
                </div>
            </div>

            <div class="form-section">
                <h2>Permissions</h2>
                <p class="muted small">Tick the permissions this role should grant. Use the column header to toggle a whole module.</p>

                <?php
                // ----------------------------------------------------------
                // Group the permission matrix to mirror the sidebar nav.
                //
                // Every permission card maps to a TOP-LEVEL nav group (Assets,
                // Inventory, Reports, Admin, ...). Sub-modules that live under
                // a group in the nav (View Assets, View Models, Asset
                // Transactions, ...) render as labelled subsections inside
                // their parent group's card instead of as loose cards.
                //
                // The hierarchy comes from modules.parent_id / is_group, the
                // same columns visible_module_tree() uses for the sidebar — so
                // the section titles match the nav tabs exactly.
                //
                // note_cat_* modules have no nav group; they stay in their own
                // collapsible section below. Inactive ones are skipped.
                // ----------------------------------------------------------
                $byId          = [];
                $childrenOf    = [];
                $noteCatMods   = [];
                foreach ($matrix as $m) {
                    $byId[(int)$m['id']] = $m;
                    if (!empty($m['parent_id'])) {
                        $childrenOf[(int)$m['parent_id']][] = $m;
                    }
                }

                // Flatten a module's descendants depth-first, preserving the
                // matrix (sort_order) ordering. Sub-groups (e.g. Tools →
                // Weight Calculator) are flattened in too.
                $collectDesc = function ($pid) use (&$collectDesc, $childrenOf) {
                    $out = [];
                    foreach ($childrenOf[$pid] ?? [] as $c) {
                        $out[] = $c;
                        foreach ($collectDesc((int)$c['id']) as $d) { $out[] = $d; }
                    }
                    return $out;
                };

                // Top-level entries = modules with no parent. Render groups and
                // standalone modules (Dashboard); peel note categories off.
                $topRows = [];
                foreach ($matrix as $m) {
                    if (!empty($m['parent_id'])) continue;
                    if (strpos($m['code'], 'note_cat_') === 0) {
                        if ((int)$m['is_active'] === 1) $noteCatMods[] = $m;
                        continue;
                    }
                    $topRows[] = $m;
                }

                // ---- Redundancy filters (keep the editor showing only the
                //      permissions that actually DO something) ----
                //
                // 1) Nav-wrapper sub-modules: pure menu-link toggles whose
                //    page is gated by a PARENT permission, not their own (the
                //    destination page checks e.g. invoice.view, never
                //    invoice_view.view). They now appear automatically via the
                //    $navInherit map in visible_modules(), so granting them
                //    here is redundant. Hide them.
                $navWrapperCodes = [
                    'asset_view_assets', 'asset_view_models', 'asset_transactions',
                    'invoice_view', 'invoice_new',
                    'insp_new', 'insp_completed', 'insp_templates',
                    'tools_bubble', 'tools_cad', 'tools_weight', 'tools_calc',
                    'inventory_shipments_list',
                ];

                // 2) Dead permissions: defined in the DB but never enforced by
                //    any require_permission()/permission_check() call. The
                //    "inventory"/"reports" GROUP rows are nav-only (their real
                //    perms live on sub-modules), and tools.manage is unused.
                $deadPerms = [
                    'inventory.view', 'inventory.manage',
                    'reports.view', 'reports.manage',
                    'tools.manage',
                    'manuals.view',        // manuals.php has no permission gate
                    'job_card.close',      // never enforced (close is a workflow step, not a perm)
                ];

                // Drop dead permissions from a module's permission list.
                $livePerms = function ($modCode, $perms) use ($deadPerms) {
                    $out = [];
                    foreach ($perms as $p) {
                        if (in_array($modCode . '.' . $p['code'], $deadPerms, true)) continue;
                        $out[] = $p;
                    }
                    return $out;
                };

                // Build a render model per top-level group:
                //   selfPerms  — permissions on the group module itself
                //   subs       — [['name'=>..., 'id'=>..., 'perms'=>[...]], ...]
                //   total      — total permission count in the card
                $cards = [];
                foreach ($topRows as $g) {
                    if ((int)$g['is_active'] !== 1) continue;   // skip disabled groups
                    $selfPerms = $livePerms($g['code'], $g['permissions']);
                    $subs  = [];
                    $total = count($selfPerms);
                    foreach ($collectDesc((int)$g['id']) as $child) {
                        if ((int)$child['is_active'] !== 1) continue;          // skip disabled modules
                        if (in_array($child['code'], $navWrapperCodes, true)) continue;  // nav-only wrapper
                        $childPerms = $livePerms($child['code'], $child['permissions']);
                        if (!$childPerms) continue;
                        $subs[] = [
                            'id'    => (int)$child['id'],
                            'name'  => $child['name'],
                            'perms' => $childPerms,
                        ];
                        $total += count($childPerms);
                    }
                    if ($total === 0) continue;   // nothing grantable — skip
                    $cards[] = [
                        'row'       => $g,
                        'selfPerms' => $selfPerms,
                        'subs'      => $subs,
                        'total'     => $total,
                    ];
                }

                $permOption = function ($p, $modId, $grpId, &$tabIdx, $rolePerms, $showCode = true) {
                    $checked = in_array($p['id'], $rolePerms) ? 'checked' : '';
                    ob_start(); ?>
                    <label class="perm-option">
                        <input type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>"
                               class="mod-<?= $modId ?> grp-<?= $grpId ?>"
                               tabindex="<?= $tabIdx++ ?>" <?= $checked ?>>
                        <span class="perm-option-text">
                            <span class="perm-option-name"><?= h($p['name']) ?></span>
                            <?php if ($showCode): ?><span class="perm-option-code"><?= h($p['code']) ?></span><?php endif; ?>
                        </span>
                    </label>
                    <?php return ob_get_clean();
                };
                ?>

                <div class="perm-modules">
                    <?php $tabIdx = 4; foreach ($cards as $card):
                        $g     = $card['row'];
                        $grpId = (int)$g['id'];
                    ?>
                        <section class="perm-module">
                            <div class="perm-module-head">
                                <label class="perm-module-title nowrap">
                                    <input type="checkbox" class="mod-toggle"
                                           data-target="grp-<?= $grpId ?>"
                                           tabindex="<?= $tabIdx++ ?>">
                                    <span class="perm-module-icon"><?= h(module_icon($g['code'], $g['icon'])) ?></span>
                                    <span><?= h($g['name']) ?></span>
                                </label>
                                <span class="perm-module-count"><?= (int)$card['total'] ?></span>
                            </div>
                            <div class="perm-module-body">
                                <?php // Group's own permissions (no sub-label).
                                foreach ($card['selfPerms'] as $p) {
                                    echo $permOption($p, $grpId, $grpId, $tabIdx, $rolePerms);
                                } ?>

                                <?php // Each sub-module as its own labelled block.
                                foreach ($card['subs'] as $sub): ?>
                                    <div class="perm-subgroup">
                                        <label class="perm-subgroup-head nowrap">
                                            <input type="checkbox" class="mod-toggle"
                                                   data-target="mod-<?= $sub['id'] ?>"
                                                   tabindex="<?= $tabIdx++ ?>">
                                            <span><?= h($sub['name']) ?></span>
                                        </label>
                                        <?php foreach ($sub['perms'] as $p) {
                                            echo $permOption($p, $sub['id'], $grpId, $tabIdx, $rolePerms);
                                        } ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <?php if ($noteCatMods): ?>
                    <details class="perm-matrix-group" style="margin-top: 16px;">
                        <summary style="cursor: pointer; padding: 8px 12px; background: var(--surface-alt, #f3f4f7); border: 1px solid var(--border); border-radius: 6px; font-weight: 600; font-size: 13px;">
                            📝 Note categories
                            <span class="muted small" style="font-weight: normal;">
                                (<?= count($noteCatMods) ?> categor<?= count($noteCatMods) === 1 ? 'y' : 'ies' ?> — controls who can view/post notes per category)
                            </span>
                        </summary>
                        <div class="perm-modules">
                            <?php foreach ($noteCatMods as $m):
                                // Strip the "Note category: " prefix from the
                                // display since the section header already
                                // explains the context.
                                $displayName = preg_replace('/^Note category:\s*/', '', $m['name']);
                            ?>
                                <section class="perm-module">
                                    <div class="perm-module-head">
                                        <label class="perm-module-title nowrap">
                                            <input type="checkbox" class="mod-toggle"
                                                   data-target="mod-<?= (int)$m['id'] ?>"
                                                   tabindex="<?= $tabIdx++ ?>">
                                            <span><?= h($displayName) ?></span>
                                        </label>
                                        <span class="perm-module-count"><?= count($m['permissions']) ?></span>
                                    </div>
                                    <div class="perm-module-body">
                                        <?php foreach ($m['permissions'] as $p): ?>
                                            <label class="perm-option">
                                                <input type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>"
                                                       class="mod-<?= (int)$m['id'] ?>"
                                                       tabindex="<?= $tabIdx++ ?>"
                                                       <?= in_array($p['id'], $rolePerms) ? 'checked' : '' ?>>
                                                <span class="perm-option-text">
                                                    <span class="perm-option-name"><?= h(ucfirst($p['code'])) ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script>
    // Toggles work at two levels:
    //   • group master  (data-target="grp-<id>")  → every checkbox in the card
    //   • sub-module     (data-target="mod-<id>")  → that sub-module's boxes
    // A master reflects its children: checked when all are on, indeterminate
    // when only some are. Ticking any leaf refreshes every master above it.
    var masters = Array.prototype.slice.call(document.querySelectorAll('.mod-toggle'));

    function childrenOf(cb) {
        return document.querySelectorAll('.' + cb.getAttribute('data-target'));
    }
    function refreshMasters() {
        masters.forEach(function (cb) {
            var children = childrenOf(cb);
            var on = 0;
            Array.prototype.forEach.call(children, function (c) { if (c.checked) on++; });
            cb.checked = children.length > 0 && on === children.length;
            cb.indeterminate = on > 0 && on < children.length;
        });
    }
    masters.forEach(function (cb) {
        cb.addEventListener('change', function () {
            Array.prototype.forEach.call(childrenOf(cb), function (c) { c.checked = cb.checked; });
            refreshMasters();
        });
    });
    document.querySelectorAll('input[name="perms[]"]').forEach(function (c) {
        c.addEventListener('change', refreshMasters);
    });
    refreshMasters();
    </script>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// LIST
// ============================================================
require_once __DIR__ . '/includes/datatable.php';

$canManage = permission_check('roles', 'manage');

$dtCfg = [
    'id'       => 'roles',
    'base_sql' => 'SELECT r.*,
                          (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count,
                          (SELECT COUNT(*) FROM user_roles ur     WHERE ur.role_id = r.id) AS user_count
                     FROM roles r',
    'columns'  => [
        ['key'=>'name',        'label'=>'Role',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'r.name'],
        ['key'=>'code',        'label'=>'Code',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'r.code'],
        ['key'=>'description', 'label'=>'Description', 'sortable'=>false,'searchable'=>true, 'sql_col'=>'r.description', 'td_class'=>'muted small'],
        ['key'=>'perm_count',  'label'=>'Permissions', 'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'user_count',  'label'=>'Users',       'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'is_system',   'label'=>'Type',        'sortable'=>true, 'searchable'=>false,'sql_col'=>'r.is_system'],
        ['key'=>'_actions',    'label'=>'Actions',     'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['name', 'asc'],
];

$rowRenderer = function ($r) use ($canManage) {
    $name = $canManage
        ? '<strong><a href="' . h(url('/roles.php?action=edit&id=' . (int)$r['id'])) . '">' . h($r['name']) . '</a></strong>'
        : '<strong>' . h($r['name']) . '</strong>';
    $type = $r['is_system']
        ? '<span class="pill pill-info">system</span>'
        : '<span class="pill pill-neutral">custom</span>';
    $actions = '';
    if ($canManage) {
        $actions .= '<a class="btn btn-icon" title="Edit role" aria-label="Edit role" href="'
                  . h(url('/roles.php?action=edit&id=' . (int)$r['id'])) . '">✎ <span class="dt-action-label">Edit role</span></a> ';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/roles.php?action=clone')) . '"'
                  . ' onsubmit="return confirm(\'Clone role &quot;' . h($r['name']) . '&quot;? Permission grants will be copied; user assignments will not.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                  . '<button class="btn btn-icon" type="submit" title="Clone role" aria-label="Clone role">⎘ <span class="dt-action-label">Clone role</span></button></form>';
    }
    if ($canManage && !$r['is_system']) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/roles.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Delete role &quot;' . h($r['name']) . '&quot;?\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete role" aria-label="Delete role">🗑 <span class="dt-action-label">Delete role</span></button></form>';
    }
    return [
        'name'        => $name,
        'code'        => '<code>' . h($r['code']) . '</code>',
        'description' => h($r['description'] ?: ''),
        'perm_count'  => (int)$r['perm_count'],
        'user_count'  => (int)$r['user_count'],
        'is_system'   => $type,
        '_actions'    => dt_actions_wrap($actions),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);

$page_title  = 'Roles & Permissions';
$page_module = 'roles';
$focus_id    = '';

$actionsHtml = '';
if ($canManage) {
    $actionsHtml = '<a class="btn btn-primary btn-sm" href="' . h(url('/roles.php?action=new')) . '"'
                 . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New role', 'N') . '</a>';
}
$dtCfg['title']        = 'Roles & Permissions';
$dtCfg['actions_html'] = $actionsHtml;

require __DIR__ . '/includes/header.php';
?>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
