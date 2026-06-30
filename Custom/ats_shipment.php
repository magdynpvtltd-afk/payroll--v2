<?php
/**
 * MagDyn — Custom API: ATS Shipment
 *
 * Ships stock out to the "Misc Vendor" when an ATS is shipped/invoiced on
 * the billing side. The goods are taken from the ATS location (where
 * ats_applied.php parked them).
 *
 * Method : GET or POST
 * Params :
 *   inventory_model_id  Inventory model code  → inv_items.code
 *   Qty                 Quantity to ship (> 0)
 *   ats_no              ATS number — written into the shipment + txn notes
 *   inv_no              Invoice number for the shipment
 *   token               (optional) shared secret, see ATS_API_TOKEN below
 *
 * Effect (single DB transaction, all-or-nothing):
 *   1. Creates an inv_shipments header (mode 'ship', vendor = Misc Vendor,
 *      reference/ref_doc = inv_no, notes = "ATS Shipped — <ats_no>").
 *   2. Adds one ship line for the item, sourced from the ATS location.
 *   3. Approves the shipment.
 *   4. Posts the `ship_out` ledger txn (–qty at ATS) and marks the
 *      shipment 'shipped'. inv_post_txn throws if ATS stock is insufficient.
 *
 * Errors :
 *   Returns {"ok":false,"error":...} with a non-2xx status. Insufficient
 *   stock at ATS is reported as HTTP 409 and rolls the whole shipment back.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/_inventory_txn.php';
require_once __DIR__ . '/../includes/_codes.php';

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

// Attribute the shipment + ledger rows to a system user.
$sysUid = (int)db_val("SELECT id FROM users WHERE username = 'admin' AND is_active = 1 LIMIT 1", [], 0);
if (!$sysUid) $sysUid = (int)db_val("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1", [], 0);
if ($sysUid) $_SESSION['uid'] = $sysUid;   // current_user_id() reads this

// ── Params ──────────────────────────────────────────────────────────────────
$code  = trim((string)input('inventory_model_id', ''));
$qty   = (float)input('Qty', input('qty', 0));
$atsNo = trim((string)input('ats_no', ''));
$invNo = trim((string)input('inv_no', ''));

if ($code === '')  ats_api_fail(422, 'inventory_model_id (inventory model code) is required.');
if ($qty <= 0)     ats_api_fail(422, 'Qty must be a positive number.');
if ($atsNo === '') ats_api_fail(422, 'ats_no is required.');
if ($invNo === '') ats_api_fail(422, 'inv_no (invoice number) is required.');

// ── Resolve item, source location, vendor ───────────────────────────────────
$item = db_one('SELECT id, code, name, uom_id FROM inv_items WHERE code = ?', [$code]);
if (!$item) ats_api_fail(404, 'No inventory item found for inventory_model_id "' . $code . '".');

// Ship out of the ATS location — that is where applied stock sits.
$atsId = (int)db_val("SELECT id FROM locations WHERE code = 'ATS' LIMIT 1", [], 0);
if (!$atsId) ats_api_fail(500, 'ATS location (code "ATS") is missing.');

$vendorId = (int)db_val("SELECT id FROM vendors WHERE name = 'Misc Vendor' LIMIT 1", [], 0);
if (!$vendorId) $vendorId = (int)db_val("SELECT id FROM vendors WHERE code = 'V-01856' LIMIT 1", [], 0);
if (!$vendorId) ats_api_fail(500, 'Misc Vendor not found in the vendors table.');

// ── Create → approve → ship, atomically ─────────────────────────────────────
$today    = date('Y-m-d');
$refDoc   = substr($invNo, 0, 64);
$note     = substr('ATS Shipped — ' . $atsNo, 0, 255);
$uomId    = isset($item['uom_id']) && $item['uom_id'] !== null ? (int)$item['uom_id'] : null;

try {
    db()->beginTransaction();

    // 1. Header (draft). ship_no is minted by scanning, so a rollback leaves
    //    no gap in the sequence.
    $shipNo = code_next('shipment');
    db_exec(
        "INSERT INTO inv_shipments
            (ship_no, vendor_id, reference, mode, status, ref_doc, notes, created_by)
         VALUES (?, ?, ?, 'ship', 'draft', ?, ?, ?)",
        [$shipNo, $vendorId, $invNo, $refDoc, $note, $sysUid ?: null]
    );
    $shipmentId = (int)db()->lastInsertId();

    // 2. Single ship line, sourced from ATS.
    db_exec(
        "INSERT INTO inv_shipment_lines
            (shipment_id, sort_order, line_kind, entity_type, item_id, qty_planned, uom_id, src_location_id, notes)
         VALUES (?, 0, 'ship', 'inv_item', ?, ?, ?, ?, ?)",
        [$shipmentId, (int)$item['id'], $qty, $uomId, $atsId, $note]
    );
    $lineId = (int)db()->lastInsertId();

    // 3. Approve.
    db_exec(
        "UPDATE inv_shipments SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
        [$sysUid ?: null, $shipmentId]
    );

    // 4. Ship-out: decrement ATS stock. Throws on insufficient quantity.
    $txn = inv_post_txn('ship_out', $today, (int)$item['id'], $atsId, -$qty, null, $refDoc, $note);

    db_exec("UPDATE inv_shipment_lines SET qty_shipped = qty_planned WHERE id = ?", [$lineId]);
    db_exec(
        "UPDATE inv_shipments
            SET status = 'shipped', shipped_by = ?, shipped_at = NOW(), actual_ship_date = ?
          WHERE id = ?",
        [$sysUid ?: null, $today, $shipmentId]
    );

    db()->commit();
} catch (\Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    // Insufficient stock and any other failure land here.
    ats_api_fail(409, $e->getMessage());
}

// Best-effort audit trail (outside the transaction — never blocks the ship).
try {
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.ship', ?, ?)",
        [$sysUid ?: null, $shipmentId, $shipNo . ' · ATS ' . $atsNo . ' · inv ' . $invNo]
    );
} catch (\Throwable $e) { /* audit is non-critical */ }

ats_api_ok([
    'message'      => sprintf('Shipped %s of %s to Misc Vendor (%s).',
                        rtrim(rtrim(number_format($qty, 3), '0'), '.'), $item['code'], $shipNo),
    'item_code'    => $item['code'],
    'qty'          => 0 + $qty,
    'ats_no'       => $atsNo,
    'inv_no'       => $invNo,
    'ship_no'      => $shipNo,
    'shipment_id'  => $shipmentId,
    'ship_txn_id'  => (int)$txn['txn_id'],
    'vendor'       => 'Misc Vendor',
    'shipped_from' => 'ATS',
    'ats_balance'  => 0 + (float)$txn['qty_after'],
    'status'       => 'shipped',
]);
