<?php
/**
 * Shared task-list query for the mobile (index.php) and desktop (desktop.php)
 * dashboards. Keeping the SELECT, the filter rules, and the unread-comment
 * logic in ONE place means the two views can never drift apart.
 *
 * Each returned row carries, in addition to the tf_tasks columns:
 *   creator_name, assignee_name       — display names (assignee NULL = unassigned)
 *   comment_count, attach_count       — totals
 *   last_comment, last_comment_at     — the most recent comment (body + time), or NULL
 *   unread_count                      — comments by OTHER users newer than what the
 *                                       current user has read (see tf_task_reads)
 */
require_once __DIR__ . '/db.php';

/** The filter keys both dashboards understand, in display order. */
function tf_task_filter_keys(): array
{
    return ['mine', 'created', 'unassigned', 'unread', 'all'];
}

/**
 * SQL snippet counting a task's unread comments for a given user. Used both in
 * the SELECT list and (for the "unread" filter) in the WHERE clause. Each use
 * consumes TWO positional params: (userId, userId).
 */
function tf_unread_expr(): string
{
    return '(SELECT COUNT(*) FROM tf_comments c
               WHERE c.task_id = t.id AND c.user_id <> ?
                 AND c.id > COALESCE((SELECT r.last_read_comment_id FROM tf_task_reads r
                                        WHERE r.user_id = ? AND r.task_id = t.id), 0))';
}

/**
 * Fetch the task list for the current user.
 *
 * @param array  $me     current_user() row (needs 'id')
 * @param string $filter one of tf_task_filter_keys()
 * @param string $status '' | open | in_progress | done
 * @param bool   $admin  whether $me is an admin (controls the 'unassigned'/'all' scope)
 */
function tf_task_list(array $me, string $filter, string $status, bool $admin): array
{
    $meId    = (int)$me['id'];
    $unread  = tf_unread_expr();

    // SELECT: the two params here feed the unread_count sub-select and MUST come
    // first because they appear before the WHERE clause in the statement.
    $sql = "SELECT t.*, cu.name AS creator_name, au.name AS assignee_name,
                   (SELECT COUNT(*) FROM tf_comments  c WHERE c.task_id = t.id) AS comment_count,
                   (SELECT COUNT(*) FROM tf_attachments a WHERE a.task_id = t.id) AS attach_count,
                   (SELECT c.body       FROM tf_comments c WHERE c.task_id = t.id ORDER BY c.id DESC LIMIT 1) AS last_comment,
                   (SELECT c.created_at FROM tf_comments c WHERE c.task_id = t.id ORDER BY c.id DESC LIMIT 1) AS last_comment_at,
                   $unread AS unread_count
              FROM tf_tasks t
              JOIN users cu ON cu.id = t.created_by
              LEFT JOIN users au ON au.id = t.assigned_to";   // LEFT: unassigned tasks have no assignee row
    $args = [$meId, $meId];   // for tf_unread_expr() in the SELECT

    $where = [];
    switch ($filter) {
        case 'created':
            $where[] = 't.created_by = ?'; $args[] = $meId;
            break;
        case 'unassigned':                              // admin-only triage (guarded by caller)
            $where[] = 't.assigned_to IS NULL';
            break;
        case 'unread':                                  // tasks with >=1 unread comment
            $where[] = $unread . ' > 0'; $args[] = $meId; $args[] = $meId;
            if (!$admin) {                              // non-admins only ever see their own tasks
                $where[] = '(t.assigned_to = ? OR t.created_by = ?)'; $args[] = $meId; $args[] = $meId;
            }
            break;
        case 'all':
            if (!$admin) { $where[] = '(t.assigned_to = ? OR t.created_by = ?)'; $args[] = $meId; $args[] = $meId; }
            break;
        case 'mine':
        default:
            $where[] = '(t.assigned_to = ? OR t.created_by = ?)'; $args[] = $meId; $args[] = $meId;
    }
    if (in_array($status, ['open', 'in_progress', 'done'], true)) {
        $where[] = 't.status = ?'; $args[] = $status;
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY FIELD(t.status,'in_progress','open','done'),
                       FIELD(t.priority,'high','medium','low'), t.due_date IS NULL, t.due_date, t.id DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/**
 * Mark every current comment on a task as read for a user (upsert the highest
 * comment id into tf_task_reads). Called when the user opens the task. Best-
 * effort: a missing table / DB hiccup must never break viewing a task.
 */
function tf_mark_task_read(int $userId, int $taskId): void
{
    if ($userId <= 0 || $taskId <= 0) return;
    try {
        $maxId = (int)db()->query('SELECT COALESCE(MAX(id),0) FROM tf_comments WHERE task_id = ' . (int)$taskId)->fetchColumn();
        db()->prepare(
            'INSERT INTO tf_task_reads (user_id, task_id, last_read_comment_id)
                  VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_read_comment_id = GREATEST(last_read_comment_id, VALUES(last_read_comment_id))'
        )->execute([$userId, $taskId, $maxId]);
    } catch (\Throwable $e) {
        // ignore — read tracking is a convenience, not a hard dependency
    }
}
