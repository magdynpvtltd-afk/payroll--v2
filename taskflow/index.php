<?php
/** Dashboard (mobile card view): tasks assigned to me + tasks I created, with a filter.
 *  This view is now PHONES ONLY — on a desktop-width screen the page forwards to
 *  desktop.php (the table view); see the $headHtml routing script below. The two
 *  views are no longer a user-facing choice, so there is no ?view= override and
 *  nothing is remembered: the viewport decides, every time. desktop.php carries
 *  the mirror-image guard. */
require __DIR__ . '/db.php';
require __DIR__ . '/task_query.php';
require __DIR__ . '/uploads.php';
$me = require_login();

$filter = $_GET['filter'] ?? 'mine';   // mine | created | unassigned | unread | all
$status = $_GET['status'] ?? '';       // '' | open | in_progress | done
$admin  = $me['role'] === 'admin';

// 'unassigned' is an admin-only triage view. A non-admin who lands on it
// (stale link, hand-typed URL) is bounced back to their own tasks so
// unassigned tasks never leak to non-admins.
if ($filter === 'unassigned' && !$admin) { $filter = 'mine'; }

$tasks = tf_task_list($me, $filter, $status, $admin);
// Attachments for every listed task, so the 📎 badge can open them in place
// (see tf_att_trigger() / tf_attachment_list_assets()).
$attMap = tf_list_attachments(array_column($tasks, 'id'));

$pill = fn($f, $lbl) => '<a class="pill ' . ($filter === $f ? 'on' : '') . '" href="?filter=' . $f
    . ($status ? '&status=' . e($status) : '') . '">' . $lbl . '</a>';
$spill = fn($s, $lbl) => '<a class="pill ' . ($status === $s ? 'on' : '') . '" href="?filter=' . e($filter)
    . ($s ? '&status=' . $s : '') . '">' . $lbl . '</a>';

$statusLabel = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'];
$pageTitle = 'Tasks';

// Responsive routing: on a desktop-width viewport, hand over to the table view.
// Runs in <head> before the body paints, so there is no flash of the card list.
// The breakpoint is character-for-character the one desktop.php tests, and the
// two guards are exact complements (it forwards here when this does NOT match),
// so they can never bounce a visitor back and forth.
$headHtml = <<<'HTML'
<script>
(function () {
  try {
    if (window.matchMedia && window.matchMedia('(min-width:720px)').matches) {
      location.replace('desktop.php' + (location.search || ''));
    }
  } catch (e) {}
})();
</script>
HTML;

require __DIR__ . '/header.php';
?>
<div class="listhead">
  <h1>Tasks</h1>
  <a class="btn primary" href="task_form.php" title="New task (Alt+N)">＋ New task</a>
</div>

<div class="filters">
  <?= $pill('mine', 'Mine')
    . $pill('created', 'I assigned')
    . ($admin ? $pill('unassigned', 'Unassigned') : '')
    . $pill('unread', 'Unread')
    . $pill('all', $admin ? 'All' : 'All mine') ?>
</div>
<div class="filters">
  <?= $spill('', 'Any') . $spill('open', 'Open') . $spill('in_progress', 'In progress') . $spill('done', 'Done') ?>
</div>

<?php if (!$tasks): ?>
  <div class="card empty"><p>No tasks here yet.</p><a class="btn primary" href="task_form.php">Create the first task</a></div>
<?php endif; ?>

<div class="tasklist">
<?php foreach ($tasks as $t): ?>
  <a class="card task s-<?= e($t['status']) ?>" href="task_view.php?id=<?= $t['id'] ?>">
    <div class="task-top">
      <span class="prio p-<?= e($t['priority']) ?>"><?= e($t['priority']) ?></span>
      <span class="task-top-right">
        <?php if ((int)$t['unread_count'] > 0): ?>
          <span class="unread-badge" title="<?= (int)$t['unread_count'] ?> unread comment(s)"><?= (int)$t['unread_count'] ?></span>
        <?php endif; ?>
        <span class="stat"><?= e($statusLabel[$t['status']]) ?></span>
      </span>
    </div>
    <div class="task-title <?= $t['status'] === 'done' ? 'done' : '' ?>"><?= e($t['title']) ?></div>
    <div class="task-meta muted small">
      <?php if ($t['assigned_to'] === null): ?>
        <span class="badge b-unassigned">Unassigned</span> · from <?= e($t['creator_name']) ?>
      <?php elseif ((int)$t['assigned_to'] === (int)$me['id']): ?>
        From <?= e($t['creator_name']) ?>
      <?php else: ?>
        To <?= e($t['assignee_name']) ?>
      <?php endif; ?>
      <?php if ($t['created_at']): ?> · assigned <?= e(tf_fmt_date($t['created_at'])) ?><?php endif; ?>
      <?php if ($t['due_date']): ?> · due <?= e($t['due_date']) ?><?php endif; ?>
      <?php if ($t['attach_count']): ?> · <?= tf_att_trigger($attMap[(int)$t['id']] ?? []) ?><?php endif; ?>
    </div>
    <?php if ($t['last_comment'] !== null && $t['last_comment'] !== ''): ?>
      <div class="last-comment small">💬 <span class="muted"><?= e(tf_excerpt($t['last_comment'], 40)) ?></span></div>
    <?php endif; ?>
  </a>
<?php endforeach; ?>
</div>
<?php tf_attachment_list_assets(); ?>
<?php require __DIR__ . '/footer.php';
