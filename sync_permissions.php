<?php
/**
 * MagDyn — publish the permissions manifest to the SSO server.
 *
 * Run via CLI:    php sync_permissions.php
 * Or via browser: https://<host>/<base>/sync_permissions.php  (admin login required)
 *
 * Re-run any time the app's modules/permissions/roles change — the manifest is
 * generated live from the DB (see sso_manifest.php), so it always reflects the
 * current catalogue.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

// Over the web this must be an authenticated admin. The CLI is trusted.
if (!$isCli) {
    if (!real_user_id() || !is_admin()) {
        http_response_code(403);
        exit('Access denied: admin login required to sync permissions.');
    }
}

function sync_fail($msg, $isCli)
{
    if ($isCli) {
        fwrite(STDERR, 'Sync failed: ' . $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Sync failed: ' . $msg;
    exit;
}

try {
    $client   = sso_magdyn_client();          // validates client_id/secret/base_url
    $manifest = require __DIR__ . '/sso_manifest.php';
    $result   = $client->register_permissions($manifest);
} catch (Throwable $e) {
    sync_fail($e->getMessage(), $isCli);
}

if ($isCli) {
    echo "Sync OK\n";
    if (!empty($result['counts']) && is_array($result['counts'])) {
        foreach ($result['counts'] as $k => $v) {
            echo "  $k: $v\n";
        }
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
