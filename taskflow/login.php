<?php
require __DIR__ . '/db.php';
if (current_user()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ident = post('email');   // an email OR a MagDyn username
    $pass  = post('password');
    // Authenticate against the shared MagDyn users table (username or email),
    // applying MagDyn's password pepper — same credentials as the inventory app.
    $stmt = db()->prepare('SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1 LIMIT 1');
    $stmt->execute([$ident, $ident]);
    $u = $stmt->fetch();
    if ($u && !empty($u['password_hash']) && password_verify($pass . PASSWORD_PEPPER, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid']        = (int)$u['id'];
        $_SESSION['persistent'] = isset($_POST['remember']);
        $_SESSION['regen_at']   = time();
        if ($_SESSION['persistent']) {
            refresh_session_cookie(SESSION_LIFETIME);
            remember_issue((int)$u['id']);   // persistent cookie survives session GC / store wipe
        } else {
            remember_clear();                // no "remember" → drop any stale token/cookie
        }
        redirect('index.php');
    }
    $error = 'Incorrect username/email or password, or the account is disabled.';
    usleep(300000); // small delay to slow brute force
}

$pageTitle = 'Login';
require __DIR__ . '/header.php';
?>
<div class="card auth">
  <img class="auth-logo" src="logo.svg" alt="Mag Dyn">
  <h1>Sign in</h1>
  <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Email or username<input type="text" name="email" required autofocus
      value="<?= e($_POST['email'] ?? '') ?>"></label>
    <p class="muted small">Use your MagDyn inventory login.</p>
    <label>Password<input type="password" name="password" required></label>
    <label class="remember"><input type="checkbox" name="remember" value="1" checked> Keep me logged in</label>
    <button class="btn primary" type="submit">Log in</button>
  </form>
</div>
<?php require __DIR__ . '/footer.php';
