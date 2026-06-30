<?php
/**
 * MagDyn — My account (self-service)
 * Created: 20260616_IST
 *
 * Lets the signed-in user change their own password. Always acts on the
 * REAL signed-in account (never the impersonated one), requires the current
 * password to be confirmed, and enforces a strong-password policy via
 * password_strength_errors().
 *
 * Actions:
 *   ?action=password        (default) change-password form
 *   ?action=change_password (POST)    process the change
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

// Self-service always targets the real signed-in user, even mid-impersonation.
$me = real_user();
if (!$me) {
    flash_set('error', 'Account not found.');
    redirect(url('/index.php'));
}

$action = (string)input('action', 'password');

if ($action === 'change_password') {
    csrf_check();

    $current = (string)input('current_password');
    $new     = (string)input('new_password');
    $confirm = (string)input('confirm_password');

    $errors = [];

    $hasLocalPassword = !empty($me['password_hash']);
    $pepper = $GLOBALS['APP']['password_pepper'];

    // Verify the current password for accounts that have one. SSO-only
    // accounts (no local hash) are allowed to set an initial password.
    if ($hasLocalPassword) {
        if ($current === '') {
            $errors[] = 'Enter your current password.';
        } elseif (!password_verify($current . $pepper, $me['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        }
    }

    if ($new === '') {
        $errors[] = 'Enter a new password.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }
    if ($hasLocalPassword && $new !== '' && $new === $current) {
        $errors[] = 'New password must be different from your current password.';
    }

    // Strong-password policy.
    foreach (password_strength_errors($new, ['username' => $me['username'], 'email' => $me['email']]) as $e) {
        $errors[] = $e;
    }

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        redirect(url('/account.php?action=password'));
    }

    $hash = password_hash($new . $pepper, PASSWORD_DEFAULT);
    db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, (int)$me['id']]);
    try {
        db_exec(
            "INSERT INTO audit_log (actor_id, target_id, action, details, ip)
             VALUES (?, ?, 'user.password_change', 'changed own password', ?)",
            [(int)$me['id'], (int)$me['id'], $_SERVER['REMOTE_ADDR'] ?? null]
        );
    } catch (\Throwable $e) { /* audit best-effort */ }

    flash_set('success', 'Your password has been changed.');
    redirect(url('/account.php?action=password'));
}

// ============================================================
// RENDER — change-password form
// ============================================================
$hasLocalPassword = !empty($me['password_hash']);

$page_title  = 'My account';
$page_module = '';
$focus_id    = $hasLocalPassword ? 'f_current' : 'f_new';
require __DIR__ . '/includes/header.php';
?>
<div class="form-page">
    <?= form_toolbar([
        'title'    => 'Change password',
        'subtitle' => $me['full_name'] . ' · ' . $me['email'],
        'back_href'  => url('/index.php'),
        'back_label' => 'Home',
        'actions_html' =>
            '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
          . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
          . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/index.php')) . '"'
          . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
    ]) ?>
    <form id="main-form" class="form-page-body" method="post"
          action="<?= h(url('/account.php?action=change_password')) ?>" autocomplete="off" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <?php if ($hasLocalPassword): ?>
                <div class="field span-2">
                    <label for="f_current">Current password *</label>
                    <input id="f_current" name="current_password" type="password" tabindex="1"
                           autocomplete="current-password" required>
                </div>
            <?php else: ?>
                <div class="field span-2">
                    <p class="muted small">
                        Your account signs in via SSO and has no password yet.
                        Set one below to also enable local sign-in.
                    </p>
                </div>
            <?php endif; ?>

            <div class="field span-2">
                <label for="f_new">New password *</label>
                <input id="f_new" name="new_password" type="password" tabindex="2"
                       autocomplete="new-password" required>
                <span class="muted small">
                    At least 10 characters with an uppercase letter, a lowercase letter,
                    a number, and a symbol. Must not contain your username or email.
                </span>
            </div>
            <div class="field span-2">
                <label for="f_confirm">Confirm new password *</label>
                <input id="f_confirm" name="confirm_password" type="password" tabindex="3"
                       autocomplete="new-password" required>
            </div>
        </div>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
