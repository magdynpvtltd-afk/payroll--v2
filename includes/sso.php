<?php
/**
 * SSO abstraction.
 *
 * The intent: keep the local username/password flow as the default while
 * giving you the bare minimum surface area to wire one of these in later:
 *
 *   - MAGDYN : MagDyn central SSO server (OAuth-style code flow via SSOClient)
 *   - OIDC : Authorisation Code flow (Google, Azure AD, Keycloak, etc.)
 *   - SAML : SP-initiated SAML 2.0 (use a library like onelogin/php-saml)
 *   - HEADER : Trusted reverse-proxy header injection
 *
 * Each mode implements two functions:
 *
 *   sso_<mode>_begin()    -> redirects the browser to start the flow
 *   sso_<mode>_callback() -> validates the response, returns an array
 *                            ['subject' => ..., 'email' => ..., 'name' => ...]
 *                            on success or null on failure.
 *
 * The router calls sso_begin() / sso_callback() which dispatch by mode.
 *
 * Created: 20260515_060024_IST
 */

function sso_enabled()
{
    return !empty($GLOBALS['APP']['sso']['enabled']);
}

function sso_mode()
{
    return $GLOBALS['APP']['sso']['mode'] ?? 'oidc';
}

function sso_begin()
{
    if (!sso_enabled()) {
        flash_set('error', 'SSO is not enabled. Use local sign-in.');
        redirect(url('/login.php'));
    }
    $mode = sso_mode();
    $fn = 'sso_' . $mode . '_begin';
    if (function_exists($fn)) return $fn();
    flash_set('error', 'SSO mode "' . h($mode) . '" not implemented yet.');
    redirect(url('/login.php'));
}

function sso_callback()
{
    if (!sso_enabled()) {
        flash_set('error', 'SSO is not enabled.');
        redirect(url('/login.php'));
    }
    $mode = sso_mode();
    $fn = 'sso_' . $mode . '_callback';
    if (function_exists($fn)) {
        $claims = $fn();
        if ($claims) return sso_complete_sign_in($claims);
    }
    flash_set('error', 'SSO sign-in failed.');
    redirect(url('/login.php'));
}

/**
 * Given a claims array ['subject','email','name'], find or auto-provision
 * the local user and sign them in.
 */
function sso_complete_sign_in(array $claims)
{
    $subject = $claims['subject'] ?? '';
    $email   = $claims['email']   ?? '';
    $name    = $claims['name']    ?? '';
    $mode    = sso_mode();

    if ($email === '' && $subject === '') {
        flash_set('error', 'SSO response missing subject and email.');
        redirect(url('/login.php'));
    }

    // Match by (sso_provider, external_id) first, then by email.
    $user = null;
    if ($subject !== '') {
        $user = db_one(
            'SELECT * FROM users WHERE sso_provider = ? AND external_id = ? LIMIT 1',
            [$mode, $subject]
        );
    }
    if (!$user && $email !== '') {
        $user = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($user && $subject !== ''
            && ($user['external_id'] !== $subject || $user['sso_provider'] !== $mode)) {
            db_exec(
                'UPDATE users SET sso_provider = ?, external_id = ? WHERE id = ?',
                [$mode, $subject, (int)$user['id']]
            );
        }
    }

    if (!$user) {
        if (!empty($GLOBALS['APP']['sso']['auto_provision'])) {
            $username = $email !== '' ? explode('@', $email)[0] : ('sso_' . substr(md5($subject), 0, 8));
            // ensure unique
            $base = $username; $i = 0;
            while (db_one('SELECT id FROM users WHERE username = ?', [$username])) {
                $i++; $username = $base . $i;
            }
            db_exec(
                'INSERT INTO users (username, email, full_name, sso_provider, external_id, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())',
                [$username, $email, $name ?: $username, $mode, $subject]
            );
            $newId = db()->lastInsertId();
            // Default new SSO users to the "viewer" role.
            db_exec(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT ?, id FROM roles WHERE code = ?',
                [$newId, 'viewer']
            );
            $user = db_one('SELECT * FROM users WHERE id = ?', [$newId]);
        } else {
            flash_set('error', 'No matching local account, and auto-provisioning is disabled.');
            redirect(url('/login.php'));
        }
    }

    if (empty($user['is_active'])) {
        flash_set('error', 'This account has been deactivated.');
        redirect(url('/login.php'));
    }

    auth_sign_in($user['id']);
    // Honour where the user was heading before being bounced to SSO.
    $ret = $_SESSION['_return_to'] ?? ($_SESSION['sso_return_to'] ?? '');
    unset($_SESSION['_return_to'], $_SESSION['sso_return_to']);
    redirect($ret ?: url('/index.php'));
}

/* ---------- MagDyn central SSO server (via SSOClient.php) ----------
   Authentication only: the SSO server confirms WHO the user is; MagDyn's own
   roles/permissions tables continue to govern WHAT they may do. New users are
   matched/auto-provisioned by sso_complete_sign_in() above. */

/** Build the SSOClient config, auto-deriving redirect_uri when left blank. */
function sso_magdyn_config()
{
    $cfg = $GLOBALS['APP']['sso']['magdyn'] ?? [];
    if (empty($cfg['redirect_uri'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base  = rtrim($GLOBALS['APP']['base_url'], '/');
        $cfg['redirect_uri'] = $proto . '://' . $host . $base . '/sso_callback.php';
    }
    return $cfg;
}

/** Instantiate the client, or throw a clear error if not yet configured. */
function sso_magdyn_client()
{
    require_once __DIR__ . '/SSOClient.php';
    $cfg = sso_magdyn_config();
    foreach (['sso_base_url', 'client_id', 'client_secret'] as $k) {
        if (empty($cfg[$k])) {
            throw new RuntimeException(
                'MagDyn SSO is not configured yet: set "' . $k . '" in config/app.config.php (sso.magdyn).'
            );
        }
    }
    return new SSOClient($cfg);
}

function sso_magdyn_begin()
{
    try {
        $client = sso_magdyn_client();
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect(url('/login.php'));
    }
    $returnTo = $_SESSION['_return_to'] ?? url('/index.php');
    $client->login($returnTo); // redirects to the SSO server and exits
}

function sso_magdyn_callback()
{
    try {
        $client = sso_magdyn_client();
        $user   = $client->handle_callback(); // validates state + exchanges code; throws on failure
    } catch (Throwable $e) {
        flash_set('error', 'SSO sign-in failed: ' . $e->getMessage());
        redirect(url('/login.php'));
    }
    // Bridge the SSO identity into MagDyn's user/RBAC system.
    return [
        'subject' => (string)($user['id'] ?? ''),
        'email'   => (string)($user['email'] ?? ''),
        'name'    => (string)($user['full_name'] ?? ($user['username'] ?? '')),
    ];
}

/**
 * If the current session was established via the MagDyn SSO server, return the
 * central logout URL (so logout.php can end the global session too). Otherwise
 * null — local sign-ins just clear the local session.
 */
function sso_logout_url()
{
    if (sso_mode() !== 'magdyn') return null;
    if (empty($_SESSION['sso_access_token'])) return null;
    $cfg = sso_magdyn_config();
    if (empty($cfg['sso_base_url'])) return null;

    $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim($GLOBALS['APP']['base_url'], '/');
    $return = $proto . '://' . $host . $base . '/login.php';

    return rtrim($cfg['sso_base_url'], '/') . '/logout.php?' . http_build_query(['redirect' => $return]);
}

/* ---------- Header-based SSO (simplest mode) ---------- */
function sso_header_begin()
{
    // Begin is a no-op; the reverse proxy will have already populated headers.
    sso_header_callback();
}
function sso_header_callback()
{
    $cfg = $GLOBALS['APP']['sso']['header'];
    $u  = $_SERVER[$cfg['user_header']]  ?? '';
    $e  = $_SERVER[$cfg['email_header']] ?? '';
    $n  = $_SERVER[$cfg['name_header']]  ?? '';
    if (!$u && !$e) return null;
    return ['subject' => $u, 'email' => $e, 'name' => $n];
}

/* ---------- OIDC (stub) ----------
   Implement using your favourite library (e.g. jumbojett/openid-connect-php).
   Skeleton kept here so you can flesh out without touching the rest of the app. */
function sso_oidc_begin()
{
    $cfg = $GLOBALS['APP']['sso']['oidc'];
    $state = bin2hex(random_bytes(16));
    $_SESSION['_oidc_state'] = $state;
    $params = [
        'response_type' => 'code',
        'client_id'     => $cfg['client_id'],
        'redirect_uri'  => $cfg['redirect_uri'],
        'scope'         => $cfg['scopes'],
        'state'         => $state,
    ];
    $authzEndpoint = rtrim($cfg['issuer'], '/') . '/authorize';
    redirect($authzEndpoint . '?' . http_build_query($params));
}

function sso_oidc_callback()
{
    // TODO: validate $_GET['state'] against $_SESSION['_oidc_state'],
    //       exchange ?code for tokens at /token, fetch /userinfo, return claims.
    flash_set('error', 'OIDC callback is a stub — drop your library code here.');
    return null;
}

/* ---------- SAML (stub) ---------- */
function sso_saml_begin()    { flash_set('error', 'SAML begin not implemented.'); return null; }
function sso_saml_callback() { flash_set('error', 'SAML callback not implemented.'); return null; }
