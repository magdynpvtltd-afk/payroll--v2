<?php
/**
 * Backend WhatsApp share: send a task's details + its attachments as native
 * WhatsApp media (via the gateway configured in db.php). POST-only, CSRF- and
 * participant-checked. Falls back gracefully with a flash message when the API
 * isn't usable — the task_view button keeps a wa.me text link for those cases.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/uploads.php';
require __DIR__ . '/whatsapp.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST only.'); }
csrf_check();

$id = (int)post('id');
$s = db()->prepare(
    'SELECT t.*, cu.phone AS creator_phone, au.phone AS assignee_phone
     FROM tf_tasks t
     JOIN users cu ON cu.id = t.created_by
     LEFT JOIN users au ON au.id = t.assigned_to
     WHERE t.id = ?'
);
$s->execute([$id]);
$task = $s->fetch();
if (!$task) { http_response_code(404); exit('Task not found.'); }

// Only task participants (or admin) may share it.
$isParticipant = (int)$task['created_by'] === (int)$me['id']
    || (int)$task['assigned_to'] === (int)$me['id']
    || $me['role'] === 'admin';
if (!$isParticipant) { http_response_code(403); exit('You cannot share this task.'); }

// Recipient = the other participant's number.
$iAmAssignee = (int)$task['assigned_to'] === (int)$me['id'];
$toPhone = wa_number($iAmAssignee ? $task['creator_phone'] : $task['assignee_phone']);

// Build the message text from a PUBLIC base URL (so links resolve off-localhost).
$statusLabel = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'];
$base = public_base_url();
// Caption/header only — the files themselves are sent as native media below.
$text = wa_task_message($task['title'], $statusLabel[$task['status']], "{$base}/task_view.php?id={$id}");

// Task-level attachments as signed, publicly-fetchable media (edge case: none -> text only).
$aStmt = db()->prepare(
    'SELECT id, original_name, mime_type FROM tf_attachments
     WHERE task_id = ? AND comment_id IS NULL ORDER BY id'
);
$aStmt->execute([$id]);
$media = [];
foreach ($aStmt as $a) {
    $media[] = [
        'url'  => attachment_share_url($base, (int)$a['id']),
        'name' => $a['original_name'],
        'mime' => $a['mime_type'],
    ];
}

[$ok, $msg] = wa_send_task($toPhone, $text, $media);
flash(($ok ? '✅ ' : '⚠️ ') . $msg);
redirect('task_view.php?id=' . $id);
