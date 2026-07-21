<?php
require __DIR__ . '/db.php';
// Log out only via an explicit, CSRF-checked POST (prevents drive-by/CSRF logout).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    remember_clear();   // revoke the persistent-login token + clear its cookie
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect('login.php');
}
// A bare GET (e.g. a bookmarked link) no longer logs out silently.
redirect(current_user() ? 'index.php' : 'login.php');
