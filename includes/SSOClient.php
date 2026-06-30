<?php
/**
 * SSOClient — drop-in library for PHP apps to authenticate via the SSO server.
 * Compatible with PHP 7.0+
 *
 * Usage:
 *
 *   require_once 'SSOClient.php';
 *   $sso = new SSOClient([
 *       'sso_base_url'  => 'http://sso.example.com',
 *       'client_id'     => 'your_client_id',
 *       'client_secret' => 'your_client_secret',
 *       'redirect_uri'  => 'http://yourapp.example.com/sso_callback.php',
 *   ]);
 *
 *   // To require login on a page:
 *   $user = $sso->require_login();
 *   echo "Hi " . $user['username'];
 *
 *   // To log out:
 *   $sso->logout();
 */

class SSOClient
{
    private $sso_base_url;
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $session_key = 'sso_user';

    public function __construct($config)
    {
        $required = ['sso_base_url', 'client_id', 'client_secret', 'redirect_uri'];
        foreach ($required as $k) {
            if (empty($config[$k])) {
                throw new InvalidArgumentException("SSOClient: missing config key '$k'");
            }
        }
        $this->sso_base_url  = rtrim($config['sso_base_url'], '/');
        $this->client_id     = $config['client_id'];
        $this->client_secret = $config['client_secret'];
        $this->redirect_uri  = $config['redirect_uri'];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** Returns the logged-in user array, or null if not signed in. */
    public function user()
    {
        return isset($_SESSION[$this->session_key]) ? $_SESSION[$this->session_key] : null;
    }

    /** Returns true if a user is signed in locally. */
    public function is_logged_in()
    {
        return !empty($_SESSION[$this->session_key]);
    }

    /**
     * Returns the user's role names for this app (array of strings).
     * Empty array if not logged in.
     */
    public function roles()
    {
        $u = $this->user();
        if (!$u || empty($u['roles'])) return [];
        return $u['roles'];
    }

    /**
     * Returns the user's permissions for this app (array of strings).
     * Empty array if not logged in.
     */
    public function permissions()
    {
        $u = $this->user();
        if (!$u || empty($u['permissions'])) return [];
        return $u['permissions'];
    }

    /**
     * Does the user have a given role? Pass a string for one role, or
     * an array to check if they have ANY of the listed roles.
     *
     *   $sso->has_role('admin')
     *   $sso->has_role(['admin', 'editor'])  // any of these
     */
    public function has_role($role)
    {
        $user_roles = $this->roles();
        if (!is_array($role)) $role = [$role];
        foreach ($role as $r) {
            if (in_array($r, $user_roles, true)) return true;
        }
        return false;
    }

    /**
     * Does the user have a given permission? Pass a string or array (any-of).
     *
     *   if ($sso->can('posts.edit')) { ... }
     *   if ($sso->can(['posts.edit', 'posts.create'])) { ... }
     */
    public function can($permission)
    {
        $user_perms = $this->permissions();
        if (!is_array($permission)) $permission = [$permission];
        foreach ($permission as $p) {
            if (in_array($p, $user_perms, true)) return true;
        }
        return false;
    }

    /**
     * Like can() but requires ALL listed permissions.
     */
    public function can_all($permissions)
    {
        $user_perms = $this->permissions();
        if (!is_array($permissions)) $permissions = [$permissions];
        foreach ($permissions as $p) {
            if (!in_array($p, $user_perms, true)) return false;
        }
        return true;
    }

    /**
     * Throw / 403 if the user lacks the permission.
     * Convenience for protecting handler routes.
     */
    public function require_permission($permission)
    {
        $this->require_login();
        if (!$this->can($permission)) {
            http_response_code(403);
            exit('Access denied: missing permission "' . htmlspecialchars(is_array($permission) ? implode(',', $permission) : $permission) . '".');
        }
    }

    /** Same, but for roles. */
    public function require_role($role)
    {
        $this->require_login();
        if (!$this->has_role($role)) {
            http_response_code(403);
            exit('Access denied: missing role "' . htmlspecialchars(is_array($role) ? implode(',', $role) : $role) . '".');
        }
    }

    /**
     * Bounce the user to the SSO server to authenticate.
     * Pass a return URL to come back to a specific page after login.
     */
    public function login($return_to = null)
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['sso_state']     = $state;
        $_SESSION['sso_return_to'] = $return_to ? $return_to : $this->current_url();

        $url = $this->sso_base_url . '/authorize.php?' . http_build_query([
            'client_id'    => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'state'        => $state,
        ]);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Convenience: if not logged in, kick to SSO; if logged in, return user.
     */
    public function require_login()
    {
        if ($this->is_logged_in()) {
            return $this->user();
        }
        $this->login();
        exit; // login() already exits, but be explicit
    }

    /**
     * Handle the callback from the SSO server.
     * Call this from your registered redirect_uri page.
     */
    public function handle_callback()
    {
        $code  = isset($_GET['code'])  ? $_GET['code']  : '';
        $state = isset($_GET['state']) ? $_GET['state'] : '';

        if (!$code) {
            throw new RuntimeException('Missing code in callback.');
        }
        if (empty($_SESSION['sso_state']) || !hash_equals((string) $_SESSION['sso_state'], (string) $state)) {
            throw new RuntimeException('Invalid state — possible CSRF.');
        }
        unset($_SESSION['sso_state']);

        // Server-to-server exchange
        $response = $this->http_post($this->sso_base_url . '/api/token.php', [
            'code'          => $code,
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri'  => $this->redirect_uri,
        ]);

        $data = json_decode($response, true);
        if (!$data || empty($data['access_token'])) {
            throw new RuntimeException('Token exchange failed: ' . $response);
        }

        $_SESSION[$this->session_key]  = $data['user'];
        $_SESSION['sso_access_token']  = $data['access_token'];
        $_SESSION['sso_token_expires'] = time() + (int) $data['expires_in'];

        return $data['user'];
    }

    /** Where to send the user after the callback handler is done. */
    public function return_to()
    {
        $url = isset($_SESSION['sso_return_to']) ? $_SESSION['sso_return_to'] : '/';
        unset($_SESSION['sso_return_to']);
        return $url;
    }

    /** Log the user out locally and at the SSO server. */
    public function logout($return_to = null)
    {
        unset(
            $_SESSION[$this->session_key],
            $_SESSION['sso_access_token'],
            $_SESSION['sso_token_expires']
        );

        $url = $this->sso_base_url . '/logout.php';
        if ($return_to) {
            $url .= '?' . http_build_query(['redirect' => $return_to]);
        }
        header('Location: ' . $url);
        exit;
    }

    /**
     * Re-fetch the user from the SSO server. Useful to verify the
     * central session is still alive (caller should logout if false).
     */
    public function refresh_user()
    {
        if (empty($_SESSION['sso_access_token'])) return null;

        $ch = curl_init($this->sso_base_url . '/api/userinfo.php');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $_SESSION['sso_access_token']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) return null;
        $u = json_decode($body, true);
        if (!$u) return null;

        $_SESSION[$this->session_key] = $u;
        return $u;
    }

    /**
     * Publish this app's permissions manifest to the SSO server.
     *
     * The manifest shape is:
     *   ['permissions' => [['name'=>..,'description'=>..], ...],
     *    'roles'       => [['name'=>..,'description'=>..,'permissions'=>[...]], ...]]
     *
     * Authenticated server-to-server with client_id + client_secret, mirroring
     * the token exchange. Endpoint defaults to /api/register_permissions.php on
     * the SSO server — adjust here if your server exposes a different path.
     *
     * Returns the decoded server response (expected to include a 'counts' map:
     * perms_added / perms_updated / perms_orphaned / roles_added / roles_updated).
     */
    public function register_permissions($manifest, $endpoint = '/api/register_permissions.php')
    {
        $response = $this->http_post($this->sso_base_url . $endpoint, [
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'permissions'   => json_encode(isset($manifest['permissions']) ? $manifest['permissions'] : []),
            'roles'         => json_encode(isset($manifest['roles']) ? $manifest['roles'] : []),
        ]);

        $data = json_decode($response, true);
        if (!is_array($data) || isset($data['error'])) {
            throw new RuntimeException('Permission sync failed: ' . $response);
        }
        return $data;
    }

    // --- Internals ---

    private function http_post($url, $params)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP error: ' . $err);
        }
        curl_close($ch);
        return $body;
    }

    private function current_url()
    {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $uri   = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        return $proto . '://' . $host . $uri;
    }
}
