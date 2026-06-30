<?php
/**
 * MagDyn — Employees (shop-floor / staff directory)
 * Created: 20260616_120000_IST
 *
 * Lightweight people list stored in `users_info` (id, name, status).
 * status: 1 = active, 0 = inactive. Independent of login `users` — this is
 * the source for the inventory "Process ▸ Done by" picker.
 *
 * Actions:
 *   ?action=index           list (default)
 *   ?action=view&id=N       read-only detail
 *   ?action=new             new employee form
 *   ?action=edit&id=N       edit existing
 *   ?action=save  (POST)    create/update
 *   ?action=toggle&id=N (POST)  activate/deactivate
 *   ?action=delete (POST)   delete
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('employees', 'view');

$action = (string)input('action', 'index');

// ============================================================
// POST handlers
// ============================================================

if ($action === 'save') {
    csrf_check();
    $id     = (int)input('id', 0);
    $name   = trim((string)input('name'));
    $status = input('status') ? 1 : 0;

    $errors = [];
    if ($name === '')            $errors[] = 'Name is required.';
    if (mb_strlen($name) > 45)   $errors[] = 'Name must be 45 characters or fewer.';

    // Soft uniqueness: reject an exact (case-insensitive) duplicate name.
    if ($name !== '') {
        $clash = db_one(
            'SELECT id FROM users_info WHERE name = ? AND id <> ? LIMIT 1',
            [$name, $id]
        );
        if ($clash) $errors[] = 'Another employee with that name already exists.';
    }

    if (!$id) require_permission('employees', 'create');
    else      require_permission('employees', 'manage');

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        $back = $id ? url('/employees.php?action=edit&id=' . $id) : url('/employees.php?action=new');
        redirect($back);
    }

    if ($id) {
        db_exec('UPDATE users_info SET name = ?, status = ? WHERE id = ?', [$name, $status, $id]);
        flash_set('success', 'Employee updated.');
    } else {
        db_exec('INSERT INTO users_info (name, status) VALUES (?, ?)', [$name, $status]);
        flash_set('success', 'Employee created.');
    }
    redirect(url('/employees.php'));
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('employees', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    if ($id) {
        db_exec('UPDATE users_info SET status = 1 - status WHERE id = ?', [$id]);
        flash_set('success', 'Employee status toggled.');
    }
    redirect(url('/employees.php'));
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('employees', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    if (!$id) { flash_set('error', 'No employee specified.'); redirect(url('/employees.php')); }
    $e = db_one('SELECT name FROM users_info WHERE id = ?', [$id]);
    if (!$e) { flash_set('error', 'Employee not found.'); redirect(url('/employees.php')); }
    db_exec('DELETE FROM users_info WHERE id = ?', [$id]);
    flash_set('success', 'Employee deleted.');
    redirect(url('/employees.php'));
}

// ============================================================
// VIEW (read-only detail)
// ============================================================
if ($action === 'view') {
    $id  = (int)input('id', 0);
    $emp = db_one('SELECT * FROM users_info WHERE id = ?', [$id]);
    if (!$emp) {
        flash_set('error', 'Employee not found.');
        redirect(url('/employees.php'));
    }
    $canManage = permission_check('employees', 'manage');

    $page_title  = 'Employee · ' . $emp['name'];
    $page_module = 'employees';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($emp['name']) ?></h1>
            <p class="muted">Employee #<?= (int)$emp['id'] ?></p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/employees.php')) ?>">← Employees</a>
            <?php if ($canManage): ?>
                <a class="btn btn-primary" href="<?= h(url('/employees.php?action=edit&id=' . (int)$emp['id'])) ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <table class="data-table">
                <tbody>
                    <tr><th style="width:160px;">Name</th><td><?= h($emp['name']) ?></td></tr>
                    <tr><th>Status</th><td>
                        <?= $emp['status']
                            ? '<span class="pill pill-active">active</span>'
                            : '<span class="pill pill-neutral">inactive</span>' ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// NEW / EDIT form
// ============================================================
if ($action === 'new' || $action === 'edit') {
    $editing = null;
    if ($action === 'edit') {
        require_permission('employees', 'manage');
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM users_info WHERE id = ?', [$id]);
        if (!$editing) {
            flash_set('error', 'Employee not found.');
            redirect(url('/employees.php'));
        }
    } else {
        require_permission('employees', 'create');
    }

    $page_title  = $editing ? 'Edit employee' : 'New employee';
    $page_module = 'employees';
    $focus_id    = 'f_name';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit employee' : 'New employee',
            'subtitle'    => $editing ? $editing['name'] : 'Add a person to the directory',
            'back_href'   => url('/employees.php'),
            'back_label'  => 'Employees',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/employees.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/employees.php?action=save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="f_name"><?= shortcut_label('Name', 'N') ?> *</label>
                    <input id="f_name" name="name" type="text" tabindex="1" required maxlength="45"
                           value="<?= h($editing['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="checkbox" name="status" value="1" tabindex="2"
                               <?= (!$editing || $editing['status']) ? 'checked' : '' ?>>
                        <?= shortcut_label('Active', 'A') ?>
                    </label>
                </div>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// LIST
// ============================================================
require_once __DIR__ . '/includes/datatable.php';

$canCreate = permission_check('employees', 'create');
$canManage = permission_check('employees', 'manage');
$canDelete = permission_check('employees', 'delete');

$dtCfg = [
    'id'       => 'employees',
    'base_sql' => 'SELECT e.* FROM users_info e',
    'columns'  => [
        ['key'=>'name',     'label'=>'Name',    'sortable'=>true, 'searchable'=>true,  'sql_col'=>'e.name'],
        ['key'=>'status',   'label'=>'Status',  'sortable'=>true, 'searchable'=>false, 'sql_col'=>'e.status'],
        ['key'=>'_actions', 'label'=>'Actions', 'sortable'=>false,'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r nowrap'],
    ],
    'default_sort' => ['name', 'asc'],
];

$rowRenderer = function ($e) use ($canManage, $canDelete) {
    $name = $canManage
          ? '<strong><a href="' . h(url('/employees.php?action=edit&id=' . (int)$e['id'])) . '">' . h($e['name']) . '</a></strong>'
          : '<strong><a href="' . h(url('/employees.php?action=view&id=' . (int)$e['id'])) . '">' . h($e['name']) . '</a></strong>';

    $status = $e['status']
        ? '<span class="pill pill-active">active</span>'
        : '<span class="pill pill-neutral">inactive</span>';

    $actions = '<a class="btn btn-icon" title="View" aria-label="View" href="'
             . h(url('/employees.php?action=view&id=' . (int)$e['id'])) . '">👁 <span class="dt-action-label">View</span></a> ';
    if ($canManage) {
        $actions .= '<a class="btn btn-icon" title="Edit employee" aria-label="Edit employee" href="'
                  . h(url('/employees.php?action=edit&id=' . (int)$e['id'])) . '">✎ <span class="dt-action-label">Edit</span></a> ';
        $toggleTitle = $e['status'] ? 'Deactivate' : 'Activate';
        $toggleGlyph = $e['status'] ? '🚫' : '✅';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/employees.php?action=toggle')) . '">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$e['id'] . '">'
                  . '<button class="btn btn-icon" type="submit" title="' . h($toggleTitle) . '" aria-label="' . h($toggleTitle) . '">'
                  . $toggleGlyph . ' <span class="dt-action-label">' . h($toggleTitle) . '</span></button></form> ';
    }
    if ($canDelete) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/employees.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Permanently delete ' . h(addslashes($e['name'])) . '? This cannot be undone.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$e['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete employee" aria-label="Delete employee">🗑 <span class="dt-action-label">Delete</span></button></form>';
    }

    return [
        'name'     => $name,
        'status'   => $status,
        '_actions' => dt_actions_wrap($actions),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);
// data_table_run() exits before reaching here on JSON requests.

$page_title  = 'Employees';
$page_module = 'employees';
$focus_id    = '';

$actionsHtml = '';
if ($canCreate) {
    $actionsHtml = '<a class="btn btn-primary btn-sm" href="' . h(url('/employees.php?action=new')) . '"'
                 . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New employee', 'N') . '</a>';
}
$dtCfg['title']        = 'Employees';
$dtCfg['actions_html'] = $actionsHtml;

require __DIR__ . '/includes/header.php';
?>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
