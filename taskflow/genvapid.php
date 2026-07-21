<?php
/**
 * One-time VAPID key generator for Web Push.
 * Open this in your browser, copy the three constants it prints into db.php,
 * then DELETE this file.
 */
require __DIR__ . '/db.php';

$pageTitle = 'Generate VAPID keys';
require __DIR__ . '/header.php';

if (VAPID_PUBLIC !== '' && VAPID_PRIVATE_PEM !== '') {
    echo '<div class="card"><h1>Already configured</h1>'
       . '<p class="muted">VAPID keys are already set in <code>db.php</code>. '
       . 'Delete <code>genvapid.php</code> from the server.</p>'
       . '<a class="btn primary" href="index.php">Back</a></div>';
    require __DIR__ . '/footer.php';
    exit;
}

if (!function_exists('openssl_pkey_new')) {
    echo '<div class="card"><p class="err">The PHP openssl extension is required.</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_pkey_export($res, $pem);
$d = openssl_pkey_get_details($res);
$pub = "\x04"
    . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
    . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$pubB64 = rtrim(strtr(base64_encode($pub), '+/', '-_'), '=');

$block = "const VAPID_SUBJECT     = 'mailto:admin@example.com';\n"
    . "const VAPID_PUBLIC      = '" . $pubB64 . "';\n"
    . "const VAPID_PRIVATE_PEM = <<<'PEM'\n" . trim($pem) . "\nPEM;\n";
?>
<div class="card">
  <h1>Your VAPID keys</h1>
  <p class="muted">Replace the matching lines near the top of <code>db.php</code> with the block below,
     then delete this file. (Set <code>VAPID_SUBJECT</code> to your real contact address.)</p>
  <pre style="user-select:all"><?= e($block) ?></pre>
  <p class="err">Keep the private key secret. Generating new keys invalidates existing subscriptions.</p>
</div>
<?php require __DIR__ . '/footer.php';
