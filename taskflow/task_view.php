<?php
/** Single task view: details, status controls, attachments, comments, WhatsApp share. */
require __DIR__ . '/db.php';
require __DIR__ . '/uploads.php';
require __DIR__ . '/whatsapp.php';
require __DIR__ . '/task_query.php';
$me = require_login();

$id = get_int('id');
$s = db()->prepare(
    'SELECT t.*, cu.name AS creator_name, cu.phone AS creator_phone,
                  au.name AS assignee_name, au.phone AS assignee_phone
     FROM tf_tasks t
     JOIN users cu ON cu.id = t.created_by
     LEFT JOIN users au ON au.id = t.assigned_to
     WHERE t.id = ?'
);
$s->execute([$id]);
$task = $s->fetch();
if (!$task) { http_response_code(404); exit('Task not found.'); }

// View permission: participants or admin.
$isParticipant = (int)$task['created_by'] === (int)$me['id']
    || (int)$task['assigned_to'] === (int)$me['id']
    || $me['role'] === 'admin';
if (!$isParticipant) { http_response_code(403); exit('You do not have access to this task.'); }

$canEdit = can_edit_task($task);

// For an unassigned task, the creator or an admin can assign it to someone.
// Only load the picker list when it will actually be shown.
$canAssign = $task['assigned_to'] === null && $canEdit;
$people = $canAssign ? assignable_users() : [];

// Task-level attachments (comment_id IS NULL) + all attachments keyed by comment.
$attStmt = db()->prepare('SELECT * FROM tf_attachments WHERE task_id = ? ORDER BY id');
$attStmt->execute([$id]);
$taskAtt = [];
$byComment = [];
foreach ($attStmt as $a) {
    if ($a['comment_id'] === null) $taskAtt[] = $a;
    else $byComment[(int)$a['comment_id']][] = $a;
}

// Comments.
$cStmt = db()->prepare(
    'SELECT c.*, u.name AS author FROM tf_comments c JOIN users u ON u.id = c.user_id
     WHERE c.task_id = ? ORDER BY c.id'
);
$cStmt->execute([$id]);
$comments = $cStmt->fetchAll();

// Opening the task marks its comments read for this user, so the unread
// balloon and the "Unread" filter on the dashboards clear on next load.
tf_mark_task_read((int)$me['id'], $id);

// Opening the task also reads any push notifications it generated for this
// user, so a read notification is never surfaced again.
try {
    db()->prepare('UPDATE tf_notifications SET read_at = NOW() WHERE task_id = ? AND user_id = ? AND read_at IS NULL')
        ->execute([$id, (int)$me['id']]);
} catch (\Throwable $e) { /* notifications table optional — ignore */ }

// Absolute base URL for WhatsApp links.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

/** Build a wa.me share link. $to = digits or '' for a chooser. */
function wa_link(string $to, string $text): string
{
    $q = 'text=' . rawurlencode($text);
    return $to !== '' ? "https://wa.me/{$to}?{$q}" : "https://wa.me/?{$q}";
}

$statusLabel = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'];
$pageTitle = $task['title'];

// Responsive routing: on a desktop-width viewport, hand over to the desktop
// view (task_view_desktop.php), which wears MagDyn's sidebar chrome. Runs in
// <head> before the body paints, so there is no flash of the phone layout. Same
// breakpoint and exact complement as the guard in task_view_desktop.php, so the
// two can never bounce a visitor back and forth. location.search carries ?id=.
$headHtml = <<<'HTML'
<script>
(function () {
  try {
    if (window.matchMedia && window.matchMedia('(min-width:720px)').matches) {
      location.replace('task_view_desktop.php' + (location.search || ''));
    }
  } catch (e) {}
})();
</script>
HTML;

require __DIR__ . '/header.php';

/** Render one attachment chip with view / whatsapp-share / delete. */
function att_chip(array $a, string $base): void
{
    global $me, $task;
    $url = $base . '/attachment.php?id=' . $a['id'];
    $wa  = wa_link('', 'Attachment from TaskFlow: ' . $task['title'] . "\n" . attachment_share_url($base, (int)$a['id']));
    $canDel = (int)$a['uploaded_by'] === (int)$me['id']
        || (int)$task['created_by'] === (int)$me['id']
        || $me['role'] === 'admin';
    echo '<div class="att">';
    if (is_image($a['mime_type'])) {
        echo '<a class="tf-att-link" title="' . e($a['original_name']) . '" href="attachment.php?id=' . $a['id'] . '" target="_blank">'
           . '<img class="thumb" src="attachment.php?id=' . $a['id'] . '" alt="' . e($a['original_name']) . '"></a>';
    } else {
        echo '<a class="filelink tf-att-link" title="' . e($a['original_name']) . '" href="attachment.php?id=' . $a['id'] . '">📄 ' . e($a['original_name']) . '</a>';
    }
    echo '<div class="att-meta muted small">' . human_size((int)$a['size_bytes']);
    if ($a['source'] === 'whatsapp') echo ' · via WhatsApp';
    echo '</div><div class="att-actions">';
    echo '<a class="wa" target="_blank" rel="noopener" href="' . e($wa) . '">↗ WhatsApp</a>';
    if ($canDel) {
        echo '<form method="post" action="attachment.php" onsubmit="return confirm(\'Remove this attachment?\')">'
           . csrf_field() . '<input type="hidden" name="id" value="' . $a['id'] . '">'
           . '<input type="hidden" name="action" value="delete"><button class="linkbtn">Delete</button></form>';
    }
    echo '</div></div>';
}
?>
<a class="back" href="index.php">‹ Back to tasks</a>

<div class="card task-detail s-<?= e($task['status']) ?>">
  <div class="task-top">
    <span class="prio p-<?= e($task['priority']) ?>"><?= e($task['priority']) ?> priority</span>
    <span class="stat"><?= e($statusLabel[$task['status']]) ?></span>
  </div>
  <h1 class="<?= $task['status'] === 'done' ? 'done' : '' ?>"><?= e($task['title']) ?></h1>
  <?php if ($task['description'] !== ''): ?>
    <p class="desc"><?= nl2br(e($task['description'])) ?></p>
  <?php endif; ?>
  <div class="muted small meta">
    Created by <strong><?= e($task['creator_name']) ?></strong>,
    <?php if ($task['assigned_to'] === null): ?>
      currently <strong>unassigned</strong>.
    <?php else: ?>
      assigned to <strong><?= e($task['assignee_name']) ?></strong>.
    <?php endif; ?>
    <?php if ($task['due_date']): ?> Due <?= e($task['due_date']) ?>.<?php endif; ?>
    <?php if ($task['completed_at']): ?> Finished <?= e($task['completed_at']) ?>.<?php endif; ?>
  </div>

  <?php if ($canAssign): ?>
  <form method="post" action="task_action.php" class="assign-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $task['id'] ?>">
    <input type="hidden" name="action" value="assign">
    <label>Assign to
      <select name="assigned_to" required>
        <option value="">— choose a user —</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?><?= (int)$p['id'] === (int)$me['id'] ? ' (me)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn small primary" type="submit">Assign</button>
  </form>
  <?php endif; ?>

  <?php
    // Share whole task on WhatsApp (to the other participant if a phone exists).
    $iAmAssignee = (int)$task['assigned_to'] === (int)$me['id'];
    $otherPhone  = wa_number($iAmAssignee ? $task['creator_phone'] : $task['assignee_phone']);
    $attLinks = [];
    foreach ($taskAtt as $a) {
        $attLinks[] = ['name' => $a['original_name'], 'url' => attachment_share_url($base, (int)$a['id'])];
    }
    $taskText = wa_task_message(
        $task['title'], $statusLabel[$task['status']],
        "{$base}/task_view.php?id={$task['id']}", $attLinks
    );
  ?>
  <?php $canApiShare = wa_api_configured() && $otherPhone !== ''; ?>
  <div class="share-row">
    <?php if ($canApiShare): ?>
      <form method="post" action="share_action.php" class="inline">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $task['id'] ?>">
        <button class="btn wa-btn" type="submit">Send task<?= $taskAtt ? ' + ' . count($taskAtt) . ' file(s)' : '' ?> on WhatsApp</button>
      </form>
      <a class="btn small" target="_blank" rel="noopener" href="<?= e(wa_link($otherPhone, $taskText)) ?>">Text only ↗</a>
    <?php else: ?>
      <a class="btn wa-btn" target="_blank" rel="noopener" href="<?= e(wa_link($otherPhone, $taskText)) ?>">Share task on WhatsApp</a>
    <?php endif; ?>
  </div>

  <?php if ($canEdit): ?>
  <div class="statusbar">
    <form method="post" action="task_action.php" class="inline">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $task['id'] ?>"><input type="hidden" name="action" value="status">
      <?php foreach (['open' => 'Reopen', 'in_progress' => 'Start', 'done' => 'Finish ✓'] as $st => $lbl):
          if ($st === $task['status']) continue; ?>
        <button class="btn small st-<?= $st ?>" name="status" value="<?= $st ?>"><?= $lbl ?></button>
      <?php endforeach; ?>
    </form>
    <a class="btn small" href="task_form.php?id=<?= $task['id'] ?>">Edit</a>
    <?php if ((int)$task['created_by'] === (int)$me['id'] || $me['role'] === 'admin'): ?>
      <form method="post" action="task_action.php" class="inline" onsubmit="return confirm('Delete this task and all its comments?')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $task['id'] ?>"><input type="hidden" name="action" value="delete">
        <button class="btn small danger">Delete</button>
      </form>
    <?php endif; ?>
  </div>
  <?php else: ?>
    <p class="muted small">Only <?= e($task['creator_name']) ?> (who created it)<?php if ($task['assigned_to'] !== null): ?>, <?= e($task['assignee_name']) ?> (assignee)<?php endif; ?> or an admin can update or finish this task.</p>
  <?php endif; ?>
</div>

<?php if ($taskAtt): ?>
<div class="card">
  <h2>Attachments</h2>
  <div class="attgrid"><?php foreach ($taskAtt as $a) att_chip($a, $base); ?></div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Comments</h2>
  <?php if (!$comments): ?><p class="muted">No comments yet.</p><?php endif; ?>
  <div class="timeline">
    <?php foreach ($comments as $c): ?>
      <div class="comment">
        <div class="c-head"><strong><?= e($c['author']) ?></strong>
          <span class="muted small"><?= e($c['created_at']) ?></span></div>
        <div class="c-body"><?= nl2br(e($c['body'])) ?></div>
        <?php if (!empty($byComment[(int)$c['id']])): ?>
          <div class="attgrid"><?php foreach ($byComment[(int)$c['id']] as $a) att_chip($a, $base); ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($canEdit): ?>
  <form method="post" action="comment_action.php?tid=<?= $task['id'] ?>" enctype="multipart/form-data" class="commentform stack">
    <?= csrf_field() ?>
    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
    <label>Add a comment
      <textarea name="body" rows="3" placeholder="Write an update…"></textarea></label>
    <label class="filepick">Attach files <span class="muted small">(photos, docs, or media saved from WhatsApp)</span>
      <input type="file" name="files[]" multiple accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.dxf,.dwg,.stl,.obj,.step,.stp,.iges,.igs,.cgm,.jt,.3ds">
    </label>
    <label class="wa-source">
      <input type="checkbox" name="source" value="whatsapp"> These files came from WhatsApp
    </label>
    <button class="btn primary">Post</button>
  </form>
  <p class="muted small wa-hint">📲 On your phone you can also open WhatsApp, tap <em>Share</em> on a photo or file, and pick <strong>TaskFlow</strong> to attach it here directly.</p>
  <?php endif; ?>
</div>
<?php tf_attachment_preview_assets(); ?>
<?php require __DIR__ . '/footer.php';
