<?php
/**
 * Mark TaskFlow notifications as read for the current user.
 *
 * Called by:
 *   - the service worker on notification click / close (dismiss) — so a
 *     read or cleared notification is never surfaced again; and
 *   - task_view.php when a task is opened.
 *
 * Scoped strictly to the logged-in user (rows are only ever updated where
 * user_id = me). No CSRF token is required: marking your own notification read
 * is idempotent and carries no security value to forge. If there is no active
 * session (e.g. a background SW request after the PHP session was collected),
 * we simply no-op — opening the task later marks it read over an authenticated
 * request, and a pushed notification is never re-sent regardless.
 */
require __DIR__ . '/db.php';
header('Content-Type: application/json');

$me = current_user();
if (!$me) {
    http_response_code(204);   // nothing to do without a session
    exit;
}
// Not reached via require_login(), so gate on taskflow.view here too. Same
// no-op response as a missing session: a revoked user has no notifications
// worth touching.
if (!has_taskflow_access((int)$me['id'])) {
    http_response_code(204);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$nid = isset($in['nid'])     ? (int)$in['nid']     : 0;
$tid = isset($in['task_id']) ? (int)$in['task_id'] : 0;

if ($nid > 0) {
    db()->prepare('UPDATE tf_notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL')
        ->execute([$nid, (int)$me['id']]);
} elseif ($tid > 0) {
    db()->prepare('UPDATE tf_notifications SET read_at = NOW() WHERE task_id = ? AND user_id = ? AND read_at IS NULL')
        ->execute([$tid, (int)$me['id']]);
}

echo json_encode(['ok' => true]);
