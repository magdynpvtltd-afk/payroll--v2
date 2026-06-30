<?php
/**
 * MagDyn — Logout
 * Created: 20260515_060024_IST
 */
require_once __DIR__ . '/includes/bootstrap.php';
// Capture the central SSO logout URL before the local session is cleared.
$ssoLogoutUrl = sso_logout_url();
auth_sign_out();
redirect($ssoLogoutUrl ?: url('/login.php'));
