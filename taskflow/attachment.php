<?php
/**
 * Serve or delete an attachment. Files live outside the web-accessible flow
 * (uploads/ is denied by .htaccess); this script is the only way to read them,
 * and only task participants (or admins) are allowed.
 *
 *   GET  attachment.php?id=NN            -> inline view
 *   GET  attachment.php?id=NN&dl=1       -> force download
 *   POST attachment.php  (action=delete) -> delete
 */
require __DIR__ . '/db.php';
require __DIR__ . '/uploads.php';

// Load attachment + its task in one go.
function load_att(int $id): ?array
{
    $s = db()->prepare(
        'SELECT a.*, t.created_by, t.assigned_to
         FROM tf_attachments a JOIN tf_tasks t ON t.id = a.task_id
         WHERE a.id = ?'
    );
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Stream a file with the correct headers, then exit. */
function serve_attachment(array $att): void
{
    $path = UPLOAD_DIR . '/' . basename($att['stored_name']);
    if (!is_file($path)) { http_response_code(404); exit('File missing on server.'); }
    $disp = isset($_GET['dl']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $att['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($att['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}

// ---- Public signed link (GET): serve without login iff the signature is valid.
// Grants read of THIS ONE file only; every other path still requires login. ----
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['sig'])) {
    $sid = get_int('id');
    $exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
    if (attachment_token_valid($sid, $exp, (string)$_GET['sig'])) {
        $att = load_att($sid);
        if ($att) serve_attachment($att);   // exits
    }
    // invalid/expired signature -> fall through to the login+permission path below
}

$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $att = load_att((int)post('id'));
    if (!$att) { http_response_code(404); exit('Not found.'); }
    // Only uploader, task creator, or admin may delete.
    $mayDelete = (int)$att['uploaded_by'] === (int)$me['id']
        || (int)$att['created_by'] === (int)$me['id']
        || $me['role'] === 'admin';
    if (!$mayDelete) { http_response_code(403); exit('Not allowed.'); }
    @unlink(UPLOAD_DIR . '/' . $att['stored_name']);
    db()->prepare('DELETE FROM tf_attachments WHERE id = ?')->execute([(int)$att['id']]);
    flash('Attachment removed.');
    redirect('task_view.php?id=' . $att['task_id']);
}

$att = load_att(get_int('id'));
if (!$att) { http_response_code(404); exit('Not found.'); }

// Only participants of the task (or admin) can view.
$mayView = (int)$att['created_by'] === (int)$me['id']
    || (int)$att['assigned_to'] === (int)$me['id']
    || $me['role'] === 'admin';
if (!$mayView) { http_response_code(403); exit('Not allowed.'); }

serve_attachment($att);
