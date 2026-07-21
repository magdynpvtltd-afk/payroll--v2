<?php
/**
 * PWA Web Share Target. When the user shares a photo/file from WhatsApp (or any
 * app) and picks "TaskFlow", the browser POSTs the file(s) here. We stage them,
 * then let the user attach to an existing task or create a new task from them.
 *
 * Declared in manifest.webmanifest under "share_target".
 */
require __DIR__ . '/db.php';
require __DIR__ . '/uploads.php';
$me = require_login();

ensure_upload_dir();

// ---- Preview a staged image (only files currently staged in this session) ----
if (isset($_GET['preview'])) {
    $name = basename((string)$_GET['preview']);
    $mime = null;
    foreach (($_SESSION['wa_pending'] ?? []) as $f) {
        if ($f['stored'] === $name && is_image($f['mime'])) { $mime = $f['mime']; break; }
    }
    $path = UPLOAD_DIR . '/' . $name;
    if ($mime && is_file($path)) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit;
}

// ---- Step 1: receive the share (files posted by the OS share sheet) ----
$incoming = [];
if (!empty($_FILES)) {
    // Accept any file field the sharing app uses (we declare "files" but be lenient).
    foreach ($_FILES as $field => $f) {
        $names = is_array($f['name']) ? $f['name'] : [$f['name']];
        foreach ($names as $i => $name) {
            $err  = is_array($f['error']) ? $f['error'][$i] : $f['error'];
            $tmp  = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
            $size = is_array($f['size']) ? $f['size'][$i] : $f['size'];
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
            $incoming[] = ['name' => $name ?: 'shared', 'tmp' => $tmp, 'size' => (int)$size];
        }
    }
}

if ($incoming && post('do') !== 'commit') {
    // Stage files on disk and remember them in the session.
    $staged = $_SESSION['wa_pending'] ?? [];
    $finfo  = new finfo(FILEINFO_MIME_TYPE);
    foreach ($incoming as $f) {
        if ($f['size'] <= 0 || $f['size'] > MAX_UPLOAD_BYTES) continue;
        $mime = normalize_sniffed_mime($finfo->file($f['tmp']) ?: 'application/octet-stream');
        $mime = resolve_container_mime($mime, (string)$f['name']);
        if (!in_array($mime, ALLOWED_MIME, true)) continue;
        $orig = substr(preg_replace('/[^\w.\- ]+/u', '_', $f['name']) ?: 'shared', 0, 200);
        $ext  = pathinfo($orig, PATHINFO_EXTENSION);
        $stored = 'wa_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . strtolower($ext) : '');
        if (move_uploaded_file($f['tmp'], UPLOAD_DIR . '/' . $stored)) {
            $staged[] = ['stored' => $stored, 'orig' => $orig, 'mime' => $mime, 'size' => (int)$f['size']];
        }
    }
    $_SESSION['wa_pending'] = $staged;
    redirect('share_target.php');   // PRG: show the picker
}

// ---- Step 3: commit staged files to a task ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'commit') {
    csrf_check();
    $staged = $_SESSION['wa_pending'] ?? [];
    if (!$staged) { redirect('index.php'); }

    $mode = post('mode');
    if ($mode === 'new') {
        $title = post('title') ?: 'Shared from WhatsApp';
        $assignee = (int)post('assigned_to') ?: (int)$me['id'];
        // Falls back to sharing it to yourself if that user is gone/disabled.
        if (!is_assignable_user($assignee)) $assignee = (int)$me['id'];
        $ins = db()->prepare('INSERT INTO tf_tasks (title, priority, created_by, assigned_to) VALUES (?, "medium", ?, ?)');
        $ins->execute([$title, $me['id'], $assignee]);
        $taskId = (int)db()->lastInsertId();
    } else {
        $taskId = (int)post('task_id');
        $s = db()->prepare('SELECT * FROM tf_tasks WHERE id=?');
        $s->execute([$taskId]);
        $t = $s->fetch();
        if (!$t || !can_edit_task($t)) { http_response_code(403); exit('You cannot attach to that task.'); }
    }

    $stmt = db()->prepare(
        'INSERT INTO tf_attachments (task_id, comment_id, uploaded_by, original_name, stored_name, mime_type, size_bytes, source)
         VALUES (?, NULL, ?, ?, ?, ?, ?, "whatsapp")'
    );
    foreach ($staged as $f) {
        // Skip anything that vanished from disk.
        if (!is_file(UPLOAD_DIR . '/' . $f['stored'])) continue;
        $stmt->execute([$taskId, $me['id'], $f['orig'], $f['stored'], $f['mime'], $f['size']]);
    }
    unset($_SESSION['wa_pending']);
    flash('Attached ' . count($staged) . ' file(s) from WhatsApp.');
    redirect('task_view.php?id=' . $taskId);
}

// ---- Step 2: picker UI ----
$staged = $_SESSION['wa_pending'] ?? [];
$pageTitle = 'Import from WhatsApp';
require __DIR__ . '/header.php';

if (!$staged) {
    echo '<div class="card"><h1>Nothing shared</h1>'
       . '<p class="muted">Open WhatsApp, tap <strong>Share</strong> on a photo or file, and choose '
       . '<strong>TaskFlow</strong>. The shared files will appear here to attach to a task.</p>'
       . '<a class="btn primary" href="index.php">Back to tasks</a></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$people = assignable_users();
$myTasks = db()->prepare(
    'SELECT id, title FROM tf_tasks WHERE created_by=? OR assigned_to=? ORDER BY id DESC LIMIT 50'
);
$myTasks->execute([$me['id'], $me['id']]);
$myTasks = $myTasks->fetchAll();
?>
<h1>Attach <?= count($staged) ?> file(s) from WhatsApp</h1>
<div class="card">
  <div class="attgrid">
    <?php foreach ($staged as $f): ?>
      <div class="att">
        <?php if (is_image($f['mime'])): ?>
          <img class="thumb" src="share_target.php?preview=<?= e($f['stored']) ?>" alt="">
        <?php else: ?>
          <span class="filelink">📄 <?= e($f['orig']) ?></span>
        <?php endif; ?>
        <div class="att-meta muted small"><?= human_size((int)$f['size']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<form method="post" class="card stack">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="commit">

  <fieldset class="stack">
    <label><input type="radio" name="mode" value="existing" checked> Add to an existing task</label>
    <select name="task_id">
      <?php foreach ($myTasks as $t): ?>
        <option value="<?= $t['id'] ?>"><?= e($t['title']) ?></option>
      <?php endforeach; ?>
      <?php if (!$myTasks): ?><option value="">(no tasks yet — create one below)</option><?php endif; ?>
    </select>
  </fieldset>

  <fieldset class="stack">
    <label><input type="radio" name="mode" value="new" <?= $myTasks ? '' : 'checked' ?>> Create a new task</label>
    <input name="title" placeholder="New task title">
    <select name="assigned_to">
      <?php foreach ($people as $p): ?>
        <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === (int)$me['id'] ? 'selected' : '' ?>>
          <?= e($p['name']) ?><?= (int)$p['id'] === (int)$me['id'] ? ' (me)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </fieldset>

  <button class="btn primary">Attach files</button>
</form>
<?php require __DIR__ . '/footer.php';
