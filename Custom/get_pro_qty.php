<?php
/**
 * MagDyn — Custom API: Inventory Model-ID → Qty at Magdyn
 *
 * Returns the quantity on hand at the "Magdyn" location for a single
 * inventory model code, as a bare plain-text number:
 *
 *     GET /Custom/get_pro_qty.php?inventory_model_id=599   →   14
 *
 * This is the single-item counterpart to product_model_id_qty.php, which
 * emits every product as "code:qty" pairs.
 *
 * "Qty" is the balance at the Magdyn location only (inv_item_location_stock
 * for the 'Magdyn' location) — it does NOT include stock parked at the ATS
 * location by ats_applied.php, nor any other location (QC-Hold, Store, ...).
 *
 * Method : GET or POST
 * Params :
 *   inventory_model_id  Inventory model code → inv_items.code (unique).
 *                       Despite the "pro" in the name this is NOT limited to
 *                       is_product rows; any inv_items code resolves.
 *   token               (optional) shared secret — same scheme as the ATS
 *                       APIs, see ATS_API_TOKEN below. Empty const = open.
 *
 * Output : text/plain, always HTTP 200.
 *   known code            → the Magdyn balance, trailing zeros trimmed ("14", "3.5")
 *   known code, no stock  → "0"
 *   unknown / missing code→ empty body
 * The empty-vs-"0" split is what lets a caller tell "no such model" apart
 * from "model exists but holds nothing at Magdyn"; keep it that way.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Optional shared-secret — kept identical to the ATS inventory APIs
// (ats_applied.php / ats_reject.php / ats_shipment.php). Leave '' to keep
// the endpoint open (LAN/internal use); set to require ?token=... on every call.
const ATS_API_TOKEN = '';

header('Content-Type: text/plain; charset=utf-8');

// ── Auth (same scheme as the ATS APIs) ───────────────────────────────────────
if (ATS_API_TOKEN !== '' && hash_equals(ATS_API_TOKEN, (string)input('token', '')) === false) {
    http_response_code(403);
    echo 'Unauthorized.';
    exit;
}

// ── Params ───────────────────────────────────────────────────────────────────
$code = trim((string)input('inventory_model_id', ''));
if ($code === '') exit;                 // empty body

// ── Resolve the item ─────────────────────────────────────────────────────────
$itemId = (int)db_val('SELECT id FROM inv_items WHERE code = ? LIMIT 1', [$code], 0);
if (!$itemId) exit;                     // unknown code → empty body

// ── Magdyn-location balance ──────────────────────────────────────────────────
// No stock row for the location simply means nothing on hand → 0.
$qty = (float)db_val(
    "SELECT s.qty
       FROM inv_item_location_stock s
       JOIN locations l
         ON l.id = s.location_id
        AND l.code = 'Magdyn'
      WHERE s.item_id = ?
      LIMIT 1",
    [$itemId],
    0
);

// Trim trailing zeros so decimal(12,3) reads as "3.5" / "14", not "3.500".
$out = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
if ($out === '' || $out === '-0') $out = '0';

echo $out;
