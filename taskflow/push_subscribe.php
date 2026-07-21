<?php
/** Save or remove the current user's Web Push subscription (called by app.js via fetch). */
require __DIR__ . '/db.php';
header('Content-Type: application/json');

$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}
// This endpoint doesn't go through require_login(), so the taskflow.view gate
// has to be applied by hand — otherwise someone whose access was revoked keeps
// receiving push notifications about tasks.
if (!has_taskflow_access((int)$me['id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'no_access']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json']);
    exit;
}

// CSRF: token supplied in the JSON body, compared to the session token.
if (!isset($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_csrf']);
    exit;
}

$action = $in['action'] ?? 'subscribe';
$sub    = $in['subscription'] ?? null;

if ($action === 'unsubscribe') {
    $endpoint = is_array($sub) ? ($sub['endpoint'] ?? '') : (string)($in['endpoint'] ?? '');
    if ($endpoint !== '') {
        $q = db()->prepare('DELETE FROM tf_push_subscriptions WHERE endpoint = ? AND user_id = ?');
        $q->execute([$endpoint, $me['id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// subscribe / update
$endpoint = $sub['endpoint'] ?? '';
$p256dh   = $sub['keys']['p256dh'] ?? '';
$auth     = $sub['keys']['auth'] ?? '';
if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'incomplete']);
    exit;
}

$q = db()->prepare(
    'INSERT INTO tf_push_subscriptions (user_id, endpoint, p256dh, auth)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
                             p256dh  = VALUES(p256dh),
                             auth    = VALUES(auth)'
);
$q->execute([$me['id'], $endpoint, $p256dh, $auth]);
echo json_encode(['ok' => true]);
