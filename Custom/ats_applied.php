<?php
/**
 * MagDyn — Custom API: ATS Applied
 *
 * Moves stock from the MAGDYN location to the ATS location when an ATS
 * is applied on the billing side.
 *
 * Method : GET or POST
 * Params :
 *   inventory_model_id  Inventory model code  → inv_items.code
 *   Qty                 Quantity to move (> 0)
 *   ats_no              ATS number — written into the txn notes
 *   token               (optional) shared secret, see ATS_API_TOKEN below
 *
 * Effect :
 *   Posts a paired `move` transaction (–qty at MAGDYN, +qty at ATS) via the
 *   canonical inv_post_txn() helper, wrapped in a DB transaction so it is
 *   all-or-nothing. The notes carry the ats_no with an "ATS applied" comment.
 *
 * Errors :
 *   Returns {"ok":false,"error":...} with a non-2xx status. Insufficient
 *   stock at MAGDYN is reported as HTTP 409 (inv_post_txn throws).
 *
 * Response (success): {"ok":true, "message":..., "out_txn_id":N, "in_txn_id":N, ...}
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/_inventory_txn.php';

header('Content-Type: application/json; charset=utf-8');

// Optional shared-secret. Leave '' to keep the endpoint open (LAN/internal
// use). Set to a non-empty value to require ?token=... on every call.
const ATS_API_TOKEN = '';

function ats_api_fail($code, $msg, array $extra = [])
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
    exit;
}
function ats_api_ok(array $data = [])
{
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

// ── Auth ────────────────────────────────────────────────────────────────────
if (ATS_API_TOKEN !== '' && hash_equals(ATS_API_TOKEN, (string)input('token', '')) === false) {
    ats_api_fail(403, 'Unauthorized.');
}

// Attribute the ledger rows to a system user so created_by is populated.
$sysUid = (int)db_val("SELECT id FROM users WHERE username = 'admin' AND is_active = 1 LIMIT 1", [], 0);
if (!$sysUid) $sysUid = (int)db_val("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1", [], 0);
if ($sysUid) $_SESSION['uid'] = $sysUid;   // current_user_id() reads this

// ── Params ──────────────────────────────────────────────────────────────────
$code  = trim((string)input('inventory_model_id', ''));
$qty   = (float)input('Qty', input('qty', 0));
$atsNo = trim((string)input('ats_no', ''));

if ($code === '')  ats_api_fail(422, 'inventory_model_id (inventory model code) is required.');
if ($qty <= 0)     ats_api_fail(422, 'Qty must be a positive number.');
if ($atsNo === '') ats_api_fail(422, 'ats_no is required.');

// ── Resolve item + locations ────────────────────────────────────────────────
$item = db_one('SELECT id, code, name FROM inv_items WHERE code = ?', [$code]);
if (!$item) ats_api_fail(404, 'No inventory item found for inventory_model_id "' . $code . '".');

$magdynId = (int)db_val("SELECT id FROM locations WHERE code = 'Magdyn' LIMIT 1", [], 0);
$atsId    = (int)db_val("SELECT id FROM locations WHERE code = 'ATS' LIMIT 1", [], 0);
if (!$magdynId) ats_api_fail(500, 'MAGDYN location (code "Magdyn") is missing.');
if (!$atsId)    ats_api_fail(500, 'ATS location (code "ATS") is missing.');

// ── Move MAGDYN → ATS ───────────────────────────────────────────────────────
$today   = date('Y-m-d');
$refDoc  = substr($atsNo, 0, 64);
$note    = substr('ATS applied — ' . $atsNo, 0, 255);

try {
    db()->beginTransaction();
    // –qty at MAGDYN (throws if insufficient), +qty at ATS linked as the child.
    $out = inv_post_txn('move', $today, (int)$item['id'], $magdynId, -$qty, null, $refDoc, $note);
    $in  = inv_post_txn('move', $today, (int)$item['id'], $atsId, +$qty, (int)$out['txn_id'], $refDoc, $note);
    db()->commit();
} catch (\Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    // Insufficient stock and any other failure land here.
    ats_api_fail(409, $e->getMessage());
}

ats_api_ok([
    'message'     => sprintf('Moved %s of %s from MAGDYN to ATS.',
                        rtrim(rtrim(number_format($qty, 3), '0'), '.'), $item['code']),
    'item_code'   => $item['code'],
    'qty'         => 0 + $qty,
    'ats_no'      => $atsNo,
    'from'        => 'Magdyn',
    'to'          => 'ATS',
    'out_txn_id'  => (int)$out['txn_id'],
    'in_txn_id'   => (int)$in['txn_id'],
    'ats_balance' => 0 + (float)$in['qty_after'],
]);
