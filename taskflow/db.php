<?php
/**
 * TaskFlow - core bootstrap: config, DB connection, session, security & auth helpers.
 * Every page includes this file first.
 *
 * Integrated with MagDyn: this build shares the MagDyn inventory database,
 * its `users` table, and its login session (single sign-on). No settings to
 * edit here — DB credentials and the password pepper come from MagDyn's
 * config/ files.
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// 0. PHP 7.4 COMPATIBILITY  (this host runs PHP 7.4; TaskFlow used a few
//    PHP 8 string helpers — polyfill them so the code runs unchanged.)
// ------------------------------------------------------------------
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// ------------------------------------------------------------------
// 1. CONFIGURATION  (loaded from MagDyn — nothing to edit)
// ------------------------------------------------------------------
$__magdynRoot = dirname(__DIR__);
$__dbCfg  = is_file($__magdynRoot . '/config/db.config.php')  ? require $__magdynRoot . '/config/db.config.php'  : [];
$__appCfg = is_file($__magdynRoot . '/config/app.config.php') ? require $__magdynRoot . '/config/app.config.php' : [];

define('DB_HOST',    $__dbCfg['host']    ?? '127.0.0.1');
define('DB_PORT',    (int)($__dbCfg['port'] ?? 3306));
define('DB_NAME',    $__dbCfg['name']    ?? 'magdyn');
define('DB_USER',    $__dbCfg['user']    ?? 'root');
define('DB_PASS',    $__dbCfg['pass']    ?? '');
define('DB_CHARSET', $__dbCfg['charset'] ?? 'utf8mb4');

// MagDyn appends this pepper to the password before hashing/verifying.
define('PASSWORD_PEPPER', $__appCfg['password_pepper'] ?? '');
// Sharing MagDyn's session cookie name gives single sign-on: log into the
// inventory app or TaskFlow and you are signed into both.
define('MAGDYN_SESSION_NAME', $__appCfg['session_name'] ?? 'magdyn_sid');

// Absolute filesystem path where uploaded attachments are stored.
// Kept alongside the app; access is blocked by .htaccess and files are
// only ever served through attachment.php after a permission check.
define('UPLOAD_DIR', __DIR__ . '/uploads');

// Max attachment size in bytes (default 15 MB).
const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;

// Allowed attachment mime types.
const ALLOWED_MIME = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'text/plain', 'text/csv',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip',
    'audio/mpeg', 'audio/ogg', 'audio/mp4',
    'video/mp4', 'video/3gpp', 'video/quicktime',
    // CAD / 3D model formats the embedded viewer (../cad_viewer.php) renders.
    // Stored under these canonical types via CAD_EXT_MIME below.
    'image/vnd.dxf', 'image/vnd.dwg', 'image/cgm',
    'model/stl', 'model/obj', 'model/step', 'model/iges', 'model/jt',
    'application/x-3ds',
];

// Extension -> canonical CAD/3D mime, for files whose content sniffs as a
// vague catch-all (an ASCII DXF/STL/STEP reads as text/plain; a binary
// DWG/3DS as application/octet-stream). Same trust model as
// CONTAINER_EXT_MIME: the extension names the type ONLY when the sniff was
// one of those ambiguous catch-alls — a precise, non-CAD sniff still wins.
// Keep in step with CAD_EXTS in uploads.php :: tf_attachment_preview_assets().
const CAD_EXT_MIME = [
    'dxf'  => 'image/vnd.dxf',
    'dwg'  => 'image/vnd.dwg',
    'cgm'  => 'image/cgm',
    'stl'  => 'model/stl',
    'obj'  => 'model/obj',
    'step' => 'model/step',
    'stp'  => 'model/step',
    'iges' => 'model/iges',
    'igs'  => 'model/iges',
    'jt'   => 'model/jt',
    '3ds'  => 'application/x-3ds',
];

// Sniffed types vague enough that a matching CAD extension should override them.
const CAD_AMBIGUOUS_MIME = ['application/octet-stream', 'text/plain'];

/**
 * Generic container types libmagic reports when it can't name an Office file.
 *
 * A legacy .doc/.xls is an OLE2 compound document — the same container an .msi
 * or an Outlook .msg uses — so this build sniffs it as 'application/CDFV2'
 * rather than 'application/msword'. That never matched ALLOWED_MIME, which is
 * why attaching a .doc failed with "That file type is not allowed
 * (application/CDFV2)". (A .docx is sniffed precisely, so it always worked.)
 *
 * Content alone can't distinguish these, so a container hit is trusted only
 * when the file's extension agrees (see resolve_container_mime in uploads.php)
 * and the row is stored under the precise type from CONTAINER_EXT_MIME.
 */
const CONTAINER_MIME = [
    'application/cdfv2',
    'application/cdfv2-corrupt',
    'application/x-ole-storage',
    'application/vnd.ms-office',
    'application/vnd.ms-office; charset=binary',
];

// Extension -> the real type, for files that sniff as a bare container above.
// Deliberately only the Office types ALLOWED_MIME already intends to permit.
const CONTAINER_EXT_MIME = [
    'doc'  => 'application/msword',
    'dot'  => 'application/msword',
    'xls'  => 'application/vnd.ms-excel',
    'xlt'  => 'application/vnd.ms-excel',
];

// Secret for signing shareable attachment download links (WhatsApp).
// Set to a long random string to enable public, no-login file links.
//   php -r "echo bin2hex(random_bytes(32));"
// Leave '' to disable — shared attachment links then require login (auth-only).
const ATTACH_LINK_SECRET = 'c0df98e234d81e85488785665216f1c1cb61229007ad90b1368b02a88888277b';

// ------------------------------------------------------------------
// 1b. NOTIFICATIONS  (Web Push + WhatsApp webhook)
// ------------------------------------------------------------------

// Public URL of the app, used to build links inside notifications.
// Leave '' to auto-detect from the current request (recommended: set it,
// because background sends may not have request context).
const APP_URL = '';   // e.g. 'https://tasks.example.com'

// --- Web Push (VAPID) ---
// Keypair generated 2026-07-11 (P-256 / ES256). The private key is a secret —
// regenerating it invalidates every existing browser subscription.
const VAPID_SUBJECT     = 'mailto:shyam@truetracking.in';  // mailto: or https URL
const VAPID_PUBLIC      = 'BAIurlFxzMA-6kAMd1EzNTREXrDRYR0kEdTJG9L4L5JqmLztVSl6W5-2tM6GeeLtMeeP6mGtVbg8TlHQLrMdMbI';   // base64url public key (also used by the browser)
const VAPID_PRIVATE_PEM = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIDB97KT3GNvuWtgbPmxPnlaor7YizBquamfnalEn12w8oAoGCCqGSM49
AwEHoUQDQgAEAi6uUXHMwD7qQAx3UTM1NEResNFhHSQR1Mkb0vgvkmqYvO1VKXpb
n7a0zoZ54u0x54/qYa1VuDxOUdAusx0xsg==
-----END EC PRIVATE KEY-----
PEM;

// --- WhatsApp notifications (generic webhook) ---
// TaskFlow POSTs a JSON body to this URL for each WhatsApp notification.
// Point it at your gateway (n8n, self-hosted, cloud function, etc.).
// Leave '' to disable WhatsApp notifications.
const WHATSAPP_WEBHOOK_URL   = '';
const WHATSAPP_WEBHOOK_TOKEN = '';   // optional; sent as "Authorization: Bearer <token>"

// ------------------------------------------------------------------
// 1c. WHATSAPP SHARE — native media sending (the task "Share" button)
// ------------------------------------------------------------------
// wa.me / whatsapp:// links carry TEXT ONLY. To push the actual files as
// native WhatsApp media, the Share button posts to share_action.php, which
// calls the gateway selected below. Leave WA_PROVIDER = '' to keep the old
// text-only wa.me behaviour (nothing else needs to be set).

// Public base URL the gateway uses to FETCH attachment media. MUST be reachable
// from the public internet — NOT http://localhost, 127.0.0.1, or a LAN IP.
// In local dev, expose the app through an https tunnel (e.g. ngrok) and put
// that URL here. Falls back to APP_URL, then to the request host.
const PUBLIC_BASE_URL = '';   // e.g. 'https://tasks.example.com'

// Gateway to send through: 'twilio' | 'meta' | 'ultramsg' | 'webhook' | ''
const WA_PROVIDER = '';

// --- Twilio ---
const WA_TWILIO_SID   = '';
const WA_TWILIO_TOKEN = '';
const WA_TWILIO_FROM  = '';   // e.g. 'whatsapp:+14155238886'

// --- Meta / WhatsApp Business Cloud API ---
const WA_META_TOKEN           = '';       // permanent access token
const WA_META_PHONE_NUMBER_ID = '';       // sender phone-number id
const WA_META_API_VERSION     = 'v19.0';

// --- UltraMsg ---
const WA_ULTRAMSG_INSTANCE = '';   // e.g. 'instance12345'
const WA_ULTRAMSG_TOKEN    = '';
// (the 'webhook' provider reuses WHATSAPP_WEBHOOK_URL / _TOKEN above)

// ------------------------------------------------------------------
// 2. SESSION  (secure cookie settings)
// ------------------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// How long a "keep me logged in" session survives without activity (sliding window).
const SESSION_LIFETIME       = 60 * 60 * 24 * 3650;  // ~10 years
// Rotate the session id this often to limit hijack window on long-lived sessions.
const SESSION_REGEN_INTERVAL = 60 * 30;              // 30 minutes

// "Keep me logged in" persistent-login token. Unlike the session cookie, this
// lives in its OWN cookie + table (tf_remember_tokens): if the PHP session is
// garbage-collected or the session store is wiped, the user is transparently
// signed back in from this cookie — they stay logged in until the cookie is
// cleared (or they log out, which revokes the token).
const REMEMBER_COOKIE   = 'tf_remember';
const REMEMBER_LIFETIME = SESSION_LIFETIME;          // ~10 years; matches the session cookie

// Keep idle-but-valid sessions alive server-side as long as the cookie lives.
ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

session_set_cookie_params([
    'lifetime' => 0,   // base = browser-session; "keep me logged in" upgrades to
                       // a sliding SESSION_LIFETIME cookie in session_maintain()
    'path'     => '/',
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Lax',
]);
session_name(MAGDYN_SESSION_NAME);
session_start();
remember_attempt_login();   // re-establish a dropped session from the persistent cookie
session_maintain();

// ------------------------------------------------------------------
// 3. DATABASE (PDO)
// ------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ------------------------------------------------------------------
// 4. SECURITY HELPERS
// ------------------------------------------------------------------
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Cache-busting URL for a static asset in this directory.
 *
 * The service worker serves assets cache-first, so a plain "style.css" is
 * pinned to whatever copy it cached first and later edits never reach the
 * browser (only a hard reload, which bypasses the worker, showed them).
 * Stamping the mtime makes each edit a new URL — a new cache key — so the
 * fresh file is fetched while unchanged assets still hit the cache.
 */
function tf_asset(string $file): string
{
    $ver = @filemtime(__DIR__ . '/' . $file) ?: 0;
    return e($file) . '?v=' . $ver;
}

/**
 * The people a task may be assigned to: every active account. Disabling an
 * account is a MagDyn job (is_active = 0) and is the only thing that takes
 * someone off this list. Every picker goes through here so the list can't
 * drift from is_assignable_user()'s check on the way back in.
 */
function assignable_users(): array
{
    return db()->query('SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
}

/**
 * Server-side counterpart to assignable_users(): may this user be assigned a
 * task? The pickers are only a UI hint — a hand-crafted POST has to be checked
 * against the same rule.
 */
function is_assignable_user(int $id): bool
{
    if ($id <= 0) return false;
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $ok = isset($_POST['csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Invalid or expired form token. Go back and try again.');
    }
}

// ------------------------------------------------------------------
// 5. AUTH HELPERS
// ------------------------------------------------------------------

/** Per-request upkeep for long-lived logins: slide the cookie, rotate id, stamp activity. */
function session_maintain(): void
{
    if (empty($_SESSION['uid'])) {
        return;
    }
    $now = time();

    // Rotate the session id periodically — but only on real page loads, so
    // background fetch()/XHR don't race the change and drop the session.
    $isDoc = !isset($_SERVER['HTTP_SEC_FETCH_DEST']) || $_SERVER['HTTP_SEC_FETCH_DEST'] === 'document';
    if (empty($_SESSION['regen_at'])) {
        $_SESSION['regen_at'] = $now;
    } elseif ($isDoc && $now - (int)$_SESSION['regen_at'] > SESSION_REGEN_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['regen_at'] = $now;
    }

    $_SESSION['last_seen'] = $now;
    if (!empty($_SESSION['persistent'])) {
        refresh_session_cookie(SESSION_LIFETIME);   // sliding expiry while active
    }
}

/** (Re)issue the session cookie with a given lifetime in seconds (0 = browser session). */
function refresh_session_cookie(int $lifetime): void
{
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => $lifetime > 0 ? time() + $lifetime : 0,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?: 'Lax',
    ]);
}

// ------------------------------------------------------------------
// 5b. PERSISTENT LOGIN  ("Keep me logged in" — remember-me cookie)
//
// A long-lived cookie carries "selector:validator". The selector is a public
// lookup key; only a SHA-256 hash of the secret validator is stored, so a
// leaked database still can't be used to forge a cookie. This keeps the user
// signed in even if the PHP session is garbage-collected or its store wiped —
// they stay logged in until the cookie is cleared (or they log out).
// ------------------------------------------------------------------

/** Send (or, with $value === '', delete) the remember-me cookie. */
function remember_set_cookie(string $value, int $lifetime): void
{
    $p = session_get_cookie_params();   // reuse the session cookie's path/domain/secure
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $value === '' ? time() - 42000 : time() + $lifetime,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => true,             // never exposed to JavaScript
        'samesite' => $p['samesite'] ?: 'Lax',
    ]);
    if ($value === '') {
        unset($_COOKIE[REMEMBER_COOKIE]);
    }
}

/** Issue a fresh persistent-login token for a user and send the cookie.
 *  Best-effort: never fatal if the table is missing — login just falls back to
 *  a normal (session-only) login. */
function remember_issue(int $userId): void
{
    try {
        $selector  = bin2hex(random_bytes(12));   // 24 hex chars — public lookup key
        $validator = bin2hex(random_bytes(32));   // 64 hex chars — the secret
        db()->prepare(
            'INSERT INTO tf_remember_tokens (user_id, selector, validator_hash, expires_at, user_agent, last_used_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, NOW())'
        )->execute([
            $userId,
            $selector,
            hash('sha256', $validator),
            REMEMBER_LIFETIME,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        db()->query('DELETE FROM tf_remember_tokens WHERE expires_at < NOW()');   // opportunistic cleanup
        remember_set_cookie($selector . ':' . $validator, REMEMBER_LIFETIME);
    } catch (\Throwable $e) {
        // Table absent / DB hiccup — degrade silently to a session-only login.
    }
}

/** Revoke the presented token (if any) and clear the cookie. Called on logout
 *  and whenever a "remember" login is declined. */
function remember_clear(): void
{
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($raw !== '' && strpos($raw, ':') !== false) {
        [$selector] = explode(':', $raw, 2);
        try {
            db()->prepare('DELETE FROM tf_remember_tokens WHERE selector = ?')->execute([$selector]);
        } catch (\Throwable $e) { /* ignore */ }
    }
    remember_set_cookie('', 0);
}

/** If there is no active session but a valid remember-me cookie, sign the user
 *  back in. Runs on every request; only acts on real page navigations so that
 *  background fetch()/XHR/asset requests never race the session change. */
function remember_attempt_login(): void
{
    if (!empty($_SESSION['uid'])) {
        return;                         // already signed in
    }
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($raw === '' || strpos($raw, ':') === false) {
        return;                         // no token presented
    }
    // Only auto-login on document navigations — same guard the id-rotation uses,
    // so concurrent background requests can't each regenerate the session.
    $isDoc = !isset($_SERVER['HTTP_SEC_FETCH_DEST']) || $_SERVER['HTTP_SEC_FETCH_DEST'] === 'document';
    if (!$isDoc) {
        return;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if (strlen($selector) !== 24 || !ctype_xdigit($selector) || !ctype_xdigit($validator)) {
        remember_set_cookie('', 0);     // malformed — drop it, no DB hit
        return;
    }
    try {
        $stmt = db()->prepare(
            'SELECT t.id, t.user_id, t.validator_hash
               FROM tf_remember_tokens t
               JOIN users u ON u.id = t.user_id AND u.is_active = 1
              WHERE t.selector = ? AND t.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
    } catch (\Throwable $e) {
        return;                         // table missing / DB down — stay logged out quietly
    }
    if (!$row) {
        remember_set_cookie('', 0);     // unknown/expired selector, or account disabled
        return;
    }
    if (!hash_equals((string)$row['validator_hash'], hash('sha256', $validator))) {
        // Selector matched but validator did not — a stale or forged cookie.
        try { db()->prepare('DELETE FROM tf_remember_tokens WHERE id = ?')->execute([$row['id']]); } catch (\Throwable $e) {}
        remember_set_cookie('', 0);
        return;
    }
    // Valid token → re-establish the shared (SSO) session, exactly like a login.
    session_regenerate_id(true);
    $_SESSION['uid']        = (int)$row['user_id'];
    $_SESSION['persistent'] = true;
    $_SESSION['regen_at']   = time();
    // Slide the token's lifetime and refresh the cookie so an actively-used
    // login never lapses.
    try {
        db()->prepare('UPDATE tf_remember_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), last_used_at = NOW() WHERE id = ?')
            ->execute([REMEMBER_LIFETIME, $row['id']]);
    } catch (\Throwable $e) {}
    remember_set_cookie($selector . ':' . $validator, REMEMBER_LIFETIME);
}

function current_user(): ?array
{
    static $cached = false, $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    if (empty($_SESSION['uid'])) {
        return $user = null;
    }
    // Shared MagDyn users table. `status`/`name`/`phone` are provided by the
    // compatibility columns added during install (status & name are virtual
    // projections of is_active & full_name; phone is a real nullable column).
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch() ?: null;
    if ($user === null) {
        // account disabled/deleted mid-session — drop TaskFlow's view of it
        // without destroying the shared MagDyn session.
        unset($_SESSION['uid']);
        return $user;
    }
    // Derive TaskFlow's admin/user role from MagDyn RBAC: holders of the
    // 'admin' role are TaskFlow admins; everyone else is a regular user.
    $q = db()->prepare(
        'SELECT COUNT(*) FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = ? AND r.code = ?'
    );
    $q->execute([(int)$user['id'], 'admin']);
    $user['role'] = $q->fetchColumn() ? 'admin' : 'user';
    // Defensive fallbacks if the compatibility columns are ever missing.
    if (!isset($user['name']))   { $user['name']   = $user['full_name'] ?? ''; }
    if (!isset($user['status'])) { $user['status'] = ((int)($user['is_active'] ?? 1) === 1) ? 'active' : 'disabled'; }
    return $user;
}

/**
 * Does this user hold MagDyn's `taskflow.view` permission?
 *
 * TaskFlow doesn't keep its own access list: the right to open the app is the
 * same grant that puts TaskFlow in MagDyn's Inventory sidebar (module
 * 'taskflow', permission 'view' — see migration_20260715_181500_IST). Revoke
 * it from a role in MagDyn's Roles admin and those users lose both the menu
 * link and this app.
 *
 * Fail-open guard: if the taskflow module/permission rows aren't in the DB at
 * all — an install predating that migration — there is no grant anybody could
 * hold, so enforcing would lock out every user including admins. In that case
 * we don't enforce. Once the rows exist, the grant is required.
 */
function has_taskflow_access(int $userId): bool
{
    static $permId = false;      // false = not looked up yet, null = not registered
    if ($permId === false) {
        try {
            $q = db()->prepare(
                "SELECT p.id FROM permissions p
                   JOIN modules m ON m.id = p.module_id
                  WHERE m.code = 'taskflow' AND p.code = 'view' LIMIT 1"
            );
            $q->execute();
            $found  = $q->fetchColumn();
            $permId = ($found === false) ? null : (int)$found;
        } catch (\Throwable $ex) {
            $permId = null;      // no permissions schema — nothing to enforce
        }
    }
    if ($permId === null) {
        return true;
    }
    if ($userId <= 0) {
        return false;
    }
    $q = db()->prepare(
        'SELECT COUNT(*) FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
          WHERE ur.user_id = ? AND rp.permission_id = ?'
    );
    $q->execute([$userId, $permId]);
    return (bool)$q->fetchColumn();
}

/**
 * Stop a signed-in user who lacks `taskflow.view` with a 403 page.
 *
 * Deliberately self-contained rather than built from header.php/footer.php:
 * those render the task nav (Tasks / ＋ New / the bell), and every one of
 * those links would bounce off this same gate. All the user needs here is
 * the reason and a way back to MagDyn.
 */
function require_taskflow_access(array $u): void
{
    if (has_taskflow_access((int)$u['id'])) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>No access · TaskFlow</title>
<link rel="icon" href="icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= tf_asset('style.css') ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="../index.php"><img src="logo.svg" alt="Mag Dyn"><span>TaskFlow</span></a>
</header>
<main class="wrap">
  <div class="card empty">
    <h2>You don't have access to TaskFlow</h2>
    <p class="muted">
      Your account (<?= e($u['email'] ?? '') ?>) isn't permitted to open TaskFlow.
      Ask a MagDyn administrator to grant your role the <strong>TaskFlow</strong>
      permission.
    </p>
    <p><a class="btn primary" href="../index.php">↩ Back to MagDyn</a></p>
  </div>
</main>
</body>
</html>
    <?php
    exit;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    // Signed in is not enough — MagDyn's taskflow.view grant gates the app.
    // Sitting here means every page that gates on require_login() (and, via
    // require_admin(), the admin pages too) is covered by one check.
    require_taskflow_access($u);
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden: administrators only.');
    }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/**
 * Core permission rule:
 * Only the user who ASSIGNED the task (created_by) or the user the task is
 * ASSIGNED TO (assigned_to) may update or finish it. Admins may also manage.
 */
function can_edit_task(array $task): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return (int)$task['created_by'] === (int)$u['id']
        || (int)$task['assigned_to'] === (int)$u['id']
        || $u['role'] === 'admin';
}

// ------------------------------------------------------------------
// 6. SMALL UTILITIES
// ------------------------------------------------------------------
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function post(string $k, $default = ''): string
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : (string)$default;
}

function get_int(string $k): int
{
    return isset($_GET[$k]) ? (int)$_GET[$k] : 0;
}

/** Digits-only phone for wa.me links (strips +, spaces, dashes). */
function wa_number(?string $phone): string
{
    return preg_replace('/\D+/', '', (string)$phone);
}

/**
 * Format a DATE / DATETIME string for display, e.g. "10 Jul 2026" (or with the
 * time when $withTime is true). Returns '' for NULL, empty, or zero-dates so
 * callers can simply skip rendering. Shared by the mobile and desktop views so
 * the "assigned date" (tf_tasks.created_at) looks identical in both.
 */
function tf_fmt_date(?string $dt, bool $withTime = false): string
{
    if ($dt === null || $dt === '' || strncmp($dt, '0000', 4) === 0) {
        return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    return date($withTime ? 'j M Y, g:i a' : 'j M Y', $ts);
}

/**
 * Collapse whitespace and truncate a string to $limit characters (adding an
 * ellipsis when cut). Used to show a task's last comment as a short one-liner
 * in the mobile and desktop lists. Returns '' for null/empty.
 */
function tf_excerpt(?string $s, int $limit = 40): string
{
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_strlen')) {
        return mb_strlen($s) > $limit ? rtrim(mb_substr($s, 0, $limit)) . '…' : $s;
    }
    return strlen($s) > $limit ? rtrim(substr($s, 0, $limit)) . '…' : $s;
}

/** True if an active-or-disabled account already uses this email (case-insensitive).
 *  Pass $exceptId to ignore a specific user (e.g. when editing that user). */
function email_exists(string $email, ?int $exceptId = null): bool
{
    $email = strtolower(trim($email));
    $sql = 'SELECT COUNT(*) FROM users WHERE LOWER(email) = ?';
    $args = [$email];
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $args[] = $exceptId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return (bool)$stmt->fetchColumn();
}

/** Absolute base URL of the app (no trailing slash). Prefers APP_URL config. */
function app_base_url(): string
{
    if (APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}
