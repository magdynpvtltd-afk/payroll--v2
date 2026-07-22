<?php
/**
 * Desktop task form — create a new task or edit an existing one, rendered inside
 * MagDyn's real sidebar chrome (via magdyn_chrome.php) so it reads as one system
 * on wide screens, exactly like desktop.php and task_view_desktop.php.
 *
 * task_form.php stays the phone view untouched; the two route by viewport:
 * task_form.php forwards here on desktop widths, and the <head> guard below
 * forwards back on phones. Same 720px breakpoint and exact complement as the
 * rest of the app, so the two can't ping-pong.
 *
 * The POST handling and field logic are intentionally the same as
 * task_form.php's, mirroring how the index/desktop pair each re-implement the
 * task list rather than sharing a partial — the split here is by whole page.
 */
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
            redirect('task_view_desktop.php?id=' . $task['id']);
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
            redirect('task_view_desktop.php?id=' . $newId);
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

// Wear MagDyn's chrome — sidebar nav + script tail — instead of TaskFlow's own
// topbar/tabbar. The bridge sets every header/footer hook this page needs and
// pulls in MagDyn's bootstrap; we only add this page's title and <head> scripts.
require __DIR__ . '/magdyn_chrome.php';

$page_title = $task ? 'Edit task' : 'New task';

// Two <head> scripts, appended after MagDyn's header markup:
//   1. Desktop-only guard — mirror image of task_form.php's forward. This is
//      the desktop view, so a phone that lands here (shared link, bookmark) is
//      sent to the phone form. Runs before paint, so no desktop layout flashes.
//   2. window.TF — the globals app.js reads for web push (and the Alt+S save
//      hotkey), normally emitted by TaskFlow's own footer.
$page_head_html = <<<'HTML'
<script>
(function () {
  try {
    if (!(window.matchMedia && window.matchMedia('(min-width:720px)').matches)) {
      location.replace('task_form.php' + (location.search || ''));
    }
  } catch (e) {}
})();
</script>
HTML
    . tf_chrome_globals_script();

require MAGDYN_INCLUDES . '/header.php';
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
      <a class="btn" href="<?= $task ? 'task_view_desktop.php?id=' . $task['id'] : 'desktop.php' ?>">Cancel</a>
    </div>
  </form>
</div>
<?php require MAGDYN_INCLUDES . '/footer.php';
