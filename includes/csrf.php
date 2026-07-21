<?php
/**
 * CSRF token helpers.
 *
 * The function_exists() guards let TaskFlow (/taskflow/) borrow MagDyn's page
 * chrome — see taskflow/magdyn_chrome.php. TaskFlow declares its own
 * csrf_token()/csrf_field()/csrf_check() trio before pulling us in, and its
 * trio has to stay the one in force there: it signs with $_SESSION['csrf']
 * under its own field name, and its POST endpoints (task_action.php,
 * logout.php) validate with it WITHOUT loading MagDyn. If ours won on a
 * TaskFlow page, every form it rendered would carry a token those endpoints
 * reject.
 *
 * On a MagDyn request nothing else declares these, so the guards are always
 * true and behaviour is unchanged.
 *
 * Created: 20260515_060024_IST
 */

if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        $name = $GLOBALS['APP']['csrf_field'];
        return '<input type="hidden" name="' . h($name) . '" value="' . h(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
        $name = $GLOBALS['APP']['csrf_field'];
        $given = isset($_POST[$name]) ? $_POST[$name] : '';
        $ok = !empty($given) && hash_equals(csrf_token(), $given);
        if (!$ok) {
            http_response_code(419);
            die('CSRF check failed. Reload the page and try again.');
        }
        return true;
    }
}
