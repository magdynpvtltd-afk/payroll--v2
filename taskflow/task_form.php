<?php
/** Create a new task (assign to a user) or edit an existing one. */
require __DIR__ . '/db.php';
require __DIR__ . '/uploads.php';
require __DIR__ . '/notify.php';
$me = require_login();

/** Save any uploaded files against a task (comment_id = NULL). Returns per-file errors. */
function save_task_files(int $taskId, int $userId): array
{
    $errs = [];
    foreach (collect_uploaded_files('files') as $f) {
        [$ok, $res] = save_attachment($f, $taskId, null, $userId, 'upload');
        if (!$ok) $errs[] = $f['name'] . ': ' . $res;
    }
    return $errs;
}

$id = get_int('id');
$task = null;
if ($id) {
    $s = db()->prepare('SELECT * FROM tf_tasks WHERE id = ?');
    $s->execute([$id]);
    $task = $s->fetch();
    if (!$task)                 { http_response_code(404); exit('Task not found.'); }
    if (!can_edit_task($task))  { http_response_code(403); exit('You cannot edit this task.'); }
}

// Users you can assign to (every active account).
$people = assignable_users();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_exceeded_limit()) {
    $errors[] = post_limit_message();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title    = post('title');
    $desc     = post('description');
    $priority = in_array(post('priority'), ['low', 'medium', 'high'], true) ? post('priority') : 'medium';
    $due      = post('due_date') ?: null;
    // Assignment is optional: an empty choice leaves the task unassigned (NULL).
    $assignee = (int)post('assigned_to') ?: null;

    if ($title === '')                          $errors[] = 'Title is required.';
    if ($assignee && !is_assignable_user($assignee)) {
        $errors[] = 'Selected assignee is not valid.';
    }

    if (!$errors) {
        if ($task) {
            $s = db()->prepare(
                'UPDATE tf_tasks SET title=?, description=?, priority=?, due_date=?, assigned_to=? WHERE id=?'
            );
            $s->execute([$title, $desc, $priority, $due, $assignee, $task['id']]);
            $fileErrs = save_task_files((int)$task['id'], (int)$me['id']);
            flash($fileErrs ? ('Task updated, but some files were skipped: ' . implode(' ', $fileErrs))
                            : 'Task updated.');
            redirect('task_view.php?id=' . $task['id']);
        } else {
            $s = db()->prepare(
                'INSERT INTO tf_tasks (title, description, priority, due_date, created_by, assigned_to)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $s->execute([$title, $desc, $priority, $due, $me['id'], $assignee]);
            $newId = (int)db()->lastInsertId();
            $fileErrs = save_task_files($newId, (int)$me['id']);
            notify_task_event($newId, 'created', (int)$me['id']);
            $okMsg = $assignee ? 'Task created and assigned.' : 'Task created (unassigned).';
            flash($fileErrs ? ('Task created, but some files were skipped: ' . implode(' ', $fileErrs))
                            : $okMsg);
            redirect('task_view.php?id=' . $newId);
        }
    }
}

$val = fn($k, $d = '') => e($_POST[$k] ?? ($task[$k] ?? $d));

// For a brand-new task (not an edit, not a re-render after a validation error),
// default "Assign to" to whoever this user assigned their previous task to —
// most creators assign to the same person again and again. If that account has
// since been disabled it won't be in $people, so the option simply won't render
// and the field falls back to Unassigned.
$defaultAssignee = null;
if (!$task && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $lastStmt = db()->prepare(
        'SELECT assigned_to FROM tf_tasks
          WHERE created_by = ? AND assigned_to IS NOT NULL
          ORDER BY id DESC LIMIT 1'
    );
    $lastStmt->execute([(int)$me['id']]);
    $prev = $lastStmt->fetchColumn();
    if ($prev !== false) $defaultAssignee = (int)$prev;
}

$pageTitle = $task ? 'Edit task' : 'New task';

// Responsive routing: on a desktop-width viewport, hand over to the desktop
// form (task_form_desktop.php), which wears MagDyn's sidebar chrome. Runs in
// <head> before the body paints, so there is no flash of the phone layout. Same
// breakpoint and exact complement as the guard in task_form_desktop.php, so the
// two can never bounce a visitor back and forth. location.search carries ?id=.
$headHtml = <<<'HTML'
<script>
(function () {
  try {
    if (window.matchMedia && window.matchMedia('(min-width:720px)').matches) {
      location.replace('task_form_desktop.php' + (location.search || ''));
    }
  } catch (e) {}
})();
</script>
HTML;

require __DIR__ . '/header.php';
?>
<h1><?= $task ? 'Edit task' : 'New task' ?></h1>
<div class="card">
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <?php /* data-hotkey-save: app.js submits this form on Alt+S. */ ?>
  <form method="post" enctype="multipart/form-data" class="stack" data-hotkey-save>
    <?= csrf_field() ?>
    <?php /* New task opens with the cursor already in Title, so you can start
             typing straight away. Editing doesn't steal focus — you're usually
             heading for a different field. */ ?>
    <label>Title<input name="title" required value="<?= $val('title') ?>"<?= $task ? '' : ' autofocus' ?>></label>
    <label>Description<textarea name="description" rows="4"><?= $val('description') ?></textarea></label>
    <label>Assign to <span class="muted small">(optional)</span>
      <select name="assigned_to">
        <option value="">— Unassigned —</option>
        <?php foreach ($people as $p):
            $sel = (string)($task['assigned_to'] ?? '') === (string)$p['id']
                || (string)($_POST['assigned_to'] ?? '') === (string)$p['id']
                || ($defaultAssignee !== null && $defaultAssignee === (int)$p['id']); ?>
          <option value="<?= $p['id'] ?>" <?= $sel ? 'selected' : '' ?>>
            <?= e($p['name']) ?><?= (int)$p['id'] === (int)$me['id'] ? ' (me)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="two">
      <label>Priority
        <select name="priority">
          <?php foreach (['low', 'medium', 'high'] as $pr):
              $sel = ($_POST['priority'] ?? $task['priority'] ?? 'medium') === $pr; ?>
            <option value="<?= $pr ?>" <?= $sel ? 'selected' : '' ?>><?= ucfirst($pr) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Due date<input type="date" name="due_date" value="<?= $val('due_date') ?>"></label>
    </div>
    <label class="filepick">Attachments <span class="muted small">(optional — images, PDF, Word/Excel, media; max <?= round(MAX_UPLOAD_BYTES / 1048576) ?> MB each)</span>
      <input type="file" name="files[]" multiple
             accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.dxf,.dwg,.stl,.obj,.step,.stp,.iges,.igs,.cgm,.jt,.3ds">
    </label>
    <div class="formbtns">
      <button class="btn primary" type="submit" title="<?= $task ? 'Save changes' : 'Create task' ?> (Alt+S)"><?= $task ? 'Save changes' : 'Create task' ?></button>
      <a class="btn" href="<?= $task ? 'task_view.php?id=' . $task['id'] : 'index.php' ?>">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/footer.php';
