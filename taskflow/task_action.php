<?php
/** POST-only endpoints to change task status / delete. Enforces the permission rule. */
require __DIR__ . '/db.php';
require __DIR__ . '/notify.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST only.'); }
csrf_check();

$id = (int)post('id');
$s = db()->prepare('SELECT * FROM tf_tasks WHERE id = ?');
$s->execute([$id]);
$task = $s->fetch();
if (!$task) { http_response_code(404); exit('Task not found.'); }

// Only the assigner or the assignee (or admin) may update/finish the task.
if (!can_edit_task($task)) { http_response_code(403); exit('You are not allowed to update this task.'); }

$action = post('action');
switch ($action) {
    case 'status':
        $new = post('status');
        if (!in_array($new, ['open', 'in_progress', 'done'], true)) { http_response_code(400); exit('Bad status.'); }
        $completed = $new === 'done' ? date('Y-m-d H:i:s') : null;
        $u = db()->prepare('UPDATE tf_tasks SET status = ?, completed_at = ? WHERE id = ?');
        $u->execute([$new, $completed, $id]);
        if ($new !== $task['status']) {                       // notify on any real status change
            notify_task_event($id, 'status', (int)$me['id'], ['status' => $new]);
        }
        flash($new === 'done' ? 'Task marked as finished.' : 'Task status updated.');
        break;

    case 'assign':
        // Assign (or reassign) the task to an active user. Permission is the
        // same can_edit_task rule checked above (creator or admin for an
        // unassigned task). Notifies the new assignee.
        $newAssignee = (int)post('assigned_to');
        if (!is_assignable_user($newAssignee)) { http_response_code(400); exit('Choose a valid user to assign.'); }
        db()->prepare('UPDATE tf_tasks SET assigned_to = ? WHERE id = ?')->execute([$newAssignee, $id]);
        if ($newAssignee !== (int)$me['id']) {
            notify_task_event($id, 'created', (int)$me['id']);   // ping the new assignee
        }
        flash('Task assigned.');
        break;

    case 'delete':
        // Only the creator (assigner) or an admin may delete.
        if ((int)$task['created_by'] !== (int)$me['id'] && $me['role'] !== 'admin') {
            http_response_code(403); exit('Only the task creator can delete it.');
        }
        db()->prepare('DELETE FROM tf_tasks WHERE id = ?')->execute([$id]);
        flash('Task deleted.');
        redirect('index.php');
        break;

    default:
        http_response_code(400); exit('Unknown action.');
}

redirect('task_view.php?id=' . $id);
