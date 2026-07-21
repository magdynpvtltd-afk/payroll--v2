<?php
/** Add a comment (with optional file attachments) to a task. */
require __DIR__ . '/db.php';
require __DIR__ . '/uploads.php';
require __DIR__ . '/notify.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST only.'); }
if (post_exceeded_limit()) {
    // Over post_max_size: $_POST is empty, so fall back to the task id in the URL.
    flash(post_limit_message());
    redirect('task_view.php?id=' . (int)($_GET['tid'] ?? 0));
}
csrf_check();

$taskId = (int)post('task_id');
$s = db()->prepare('SELECT * FROM tf_tasks WHERE id = ?');
$s->execute([$taskId]);
$task = $s->fetch();
if (!$task) { http_response_code(404); exit('Task not found.'); }

// Only participants (assigner/assignee) or admin may comment/attach.
if (!can_edit_task($task)) { http_response_code(403); exit('You cannot comment on this task.'); }

$body   = post('body');
$source = post('source') === 'whatsapp' ? 'whatsapp' : 'upload';

// Normalise multiple-file input into a list of single-file arrays.
$files = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $i => $name) {
        if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $files[] = [
            'name'     => $name,
            'type'     => $_FILES['files']['type'][$i],
            'tmp_name' => $_FILES['files']['tmp_name'][$i],
            'error'    => $_FILES['files']['error'][$i],
            'size'     => $_FILES['files']['size'][$i],
        ];
    }
}

if ($body === '' && !$files) {
    flash('Add a comment or attach a file.');
    redirect('task_view.php?id=' . $taskId);
}

$errs = [];
db()->beginTransaction();
try {
    $commentId = null;
    if ($body !== '') {
        $c = db()->prepare('INSERT INTO tf_comments (task_id, user_id, body) VALUES (?, ?, ?)');
        $c->execute([$taskId, $me['id'], $body]);
        $commentId = (int)db()->lastInsertId();
    }
    foreach ($files as $f) {
        [$ok, $res] = save_attachment($f, $taskId, $commentId, (int)$me['id'], $source);
        if (!$ok) $errs[] = $f['name'] . ': ' . $res;
    }
    db()->commit();
} catch (Throwable $ex) {
    db()->rollBack();
    flash('Could not save your comment.');
    redirect('task_view.php?id=' . $taskId);
}

// Notify the other participant(s) of the new comment (push once, read-aware).
if (!empty($commentId)) {
    notify_task_event($taskId, 'commented', (int)$me['id'], ['comment' => $body]);
}

flash($errs ? ('Saved, but: ' . implode(' ', $errs)) : 'Comment added.');
redirect('task_view.php?id=' . $taskId);
