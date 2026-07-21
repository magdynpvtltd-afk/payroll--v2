<?php
/**
 * Notification dispatcher. Sends Web Push (+ optional WhatsApp webhook)
 * notifications for task events, ONLY to the task's participants (assigner +
 * assignee), excluding whoever triggered the event.
 *
 * Every notification is first PERSISTED to tf_notifications (one row per
 * recipient). That record makes delivery idempotent and read-aware:
 *   - it is web-pushed at most once (pushed_at is stamped on success);
 *   - it is marked read when the user opens the task or clicks/clears the OS
 *     notification, so it is never shown or popped again.
 *
 * All failures are swallowed so a notification problem never blocks the task
 * action. Include after db.php.
 */
require_once __DIR__ . '/webpush.php';
require_once __DIR__ . '/uploads.php';   // attachment_share_url() for media payloads

/**
 * @param int    $taskId
 * @param string $event    'created' | 'commented' | 'status'  ('completed' is
 *                          accepted as a legacy alias for a 'done' status change)
 * @param int    $actorId  the user who performed the action (never notified)
 * @param array  $ctx      optional extras: ['comment' => <body>] for 'commented',
 *                          ['status' => <new status>] for 'status'
 */
function notify_task_event(int $taskId, string $event, int $actorId, array $ctx = []): void
{
    // Legacy alias: the old API fired 'completed' for a finished task.
    if ($event === 'completed') {
        $ctx['status'] = $ctx['status'] ?? 'done';
        $event = 'status';
    }
    if (!in_array($event, ['created', 'commented', 'status'], true)) {
        return;
    }

    try {
        $s = db()->prepare(
            'SELECT t.id, t.title, t.status, t.created_by, t.assigned_to,
                    cu.name AS creator_name, cu.phone AS creator_phone,
                    au.name AS assignee_name, au.phone AS assignee_phone
             FROM tf_tasks t
             JOIN users cu ON cu.id = t.created_by
             LEFT JOIN users au ON au.id = t.assigned_to
             WHERE t.id = ?'
        );
        $s->execute([$taskId]);
        $t = $s->fetch();
        if (!$t) {
            return;
        }

        // Actor's display name (for the message text).
        $actorName = ((int)$t['created_by'] === $actorId) ? $t['creator_name']
            : (((int)$t['assigned_to'] === $actorId) ? $t['assignee_name'] : 'Someone');

        // Recipients = both participants minus the actor (de-duplicated).
        // An unassigned task (assigned_to = NULL) has only the creator.
        $participants = [
            (int)$t['created_by'] => ['name' => $t['creator_name'], 'phone' => $t['creator_phone']],
        ];
        if ($t['assigned_to'] !== null) {
            $participants[(int)$t['assigned_to']] = ['name' => $t['assignee_name'], 'phone' => $t['assignee_phone']];
        }
        unset($participants[$actorId]);
        if (!$participants) {
            return; // self-assigned task actioned by that same user
        }

        $url = app_base_url() . '/task_view.php?id=' . (int)$t['id'];

        // Task-level attachments as shareable media (signed public URLs, if configured).
        $mediaAtt = [];
        $aStmt = db()->prepare(
            'SELECT id, original_name, mime_type FROM tf_attachments
             WHERE task_id = ? AND comment_id IS NULL ORDER BY id'
        );
        $aStmt->execute([(int)$t['id']]);
        foreach ($aStmt as $a) {
            $mediaAtt[] = [
                'url'  => attachment_share_url(app_base_url(), (int)$a['id']),
                'name' => $a['original_name'],
                'mime' => $a['mime_type'],
            ];
        }

        // Build the human message per event.
        $statusLabels = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'];
        switch ($event) {
            case 'commented':
                $excerpt = function_exists('tf_excerpt') ? tf_excerpt($ctx['comment'] ?? '', 80) : trim((string)($ctx['comment'] ?? ''));
                $title   = 'New comment';
                $body    = $actorName . ' commented on “' . $t['title'] . '”' . ($excerpt !== '' ? ': ' . $excerpt : '');
                $waEvent = 'task_commented';
                $waMsg   = '💬 ' . $actorName . ' commented on “' . $t['title'] . '”' . ($excerpt !== '' ? ': ' . $excerpt : '') . "\n" . $url;
                break;

            case 'status':
                $st    = $ctx['status'] ?? $t['status'];
                $lbl   = $statusLabels[$st] ?? $st;
                $done  = $st === 'done';
                $title = $done ? 'Task completed' : 'Task status updated';
                $body  = $actorName . ' set “' . $t['title'] . '” to ' . $lbl;
                $waEvent = $done ? 'task_completed' : 'task_status';
                $waMsg   = ($done ? '✅ ' : '🔄 ') . $actorName . ' set “' . $t['title'] . '” to ' . $lbl . "\n" . $url;
                break;

            case 'created':
            default:
                $title   = 'New task assigned';
                $body    = $actorName . ' assigned: ' . $t['title'];
                $waEvent = 'task_created';
                $waMsg   = '📋 New task from ' . $actorName . ': ' . $t['title'] . "\n" . $url;
        }

        $cap = function (string $x, int $n): string {
            return function_exists('mb_substr') ? mb_substr($x, 0, $n) : substr($x, 0, $n);
        };

        foreach ($participants as $uid => $info) {
            // 1) Persist the notification — the idempotent, read-aware record.
            $ins = db()->prepare(
                'INSERT INTO tf_notifications (user_id, task_id, actor_id, type, title, body, url)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([(int)$uid, (int)$t['id'], $actorId ?: null, $event, $cap($title, 160), $cap($body, 255), $url]);
            $nid = (int)db()->lastInsertId();

            // 2) Web-push it exactly once; stamp pushed_at only if it actually went out.
            $payload = json_encode([
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
                'tag'   => 'tf-' . $nid,   // per-notification tag: each event pops on its own
                'nid'   => $nid,
            ]);
            if (push_to_user((int)$uid, $payload)) {
                db()->prepare('UPDATE tf_notifications SET pushed_at = NOW() WHERE id = ?')->execute([$nid]);
            }

            // 3) WhatsApp (no-op unless the webhook is configured).
            wa_notify($info['phone'], $info['name'], $waEvent, $t, $actorName, $url, $waMsg, $mediaAtt);
        }
    } catch (Throwable $ex) {
        // Never let notification errors surface to the user.
        error_log('notify_task_event: ' . $ex->getMessage());
    }
}

/**
 * Send a push payload to every subscription a user has; prune dead endpoints.
 * Returns true if at least one endpoint accepted it (HTTP 2xx).
 */
function push_to_user(int $userId, string $payload): bool
{
    if (!webpush_configured()) {
        return false;
    }
    $stmt = db()->prepare('SELECT id, endpoint, p256dh, auth FROM tf_push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $delivered = false;
    foreach ($stmt->fetchAll() as $sub) {
        try {
            $code = webpush_send($sub, $payload);
            if ($code >= 200 && $code < 300) {
                $delivered = true;
            }
            if ($code === 404 || $code === 410) {
                db()->prepare('DELETE FROM tf_push_subscriptions WHERE id = ?')->execute([$sub['id']]);
            }
        } catch (Throwable $ex) {
            error_log('push_to_user: ' . $ex->getMessage());
        }
    }
    return $delivered;
}

/** POST a WhatsApp notification to the configured webhook. */
function wa_notify(?string $phone, string $toName, string $event, array $task,
                   string $actorName, string $url, string $message, array $attachments = []): void
{
    if (WHATSAPP_WEBHOOK_URL === '' || !$phone) {
        return;
    }
    $to = wa_number($phone);
    if ($to === '') {
        return;
    }
    $json = json_encode([
        'event'       => $event,
        'to'          => $to,
        'to_name'     => $toName,
        'task_id'     => (int)$task['id'],
        'task_title'  => $task['title'],
        'task_url'    => $url,
        'actor'       => $actorName,
        'message'     => $message,
        'attachments' => $attachments,   // [{url,name,mime}] for media-capable gateways
    ]);
    $headers = ['Content-Type: application/json'];
    if (WHATSAPP_WEBHOOK_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . WHATSAPP_WEBHOOK_TOKEN;
    }

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init(WHATSAPP_WEBHOOK_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $json,
                'timeout'       => 8,
                'ignore_errors' => true,
            ]]);
            @file_get_contents(WHATSAPP_WEBHOOK_URL, false, $ctx);
        }
    } catch (Throwable $ex) {
        error_log('wa_notify: ' . $ex->getMessage());
    }
}
