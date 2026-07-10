<?php
/**
 * MagDyn — Custom API: Product Model-ID → Qty (Magdyn location)
 *
 * Emits every active product's inventory model code together with the
 * quantity on hand at the "Magdyn" location, as a single concatenated
 * string of "code:qty" pairs separated by ";".
 *
 *     MODEL-A:12;MODEL-B:0;MODEL-C:3.5
 *
 * "Qty in magdyn" is the balance at the Magdyn location only
 * (inv_item_location_stock for the 'Magdyn' location) — it does NOT
 * include stock parked at the ATS location by ats_applied.php.
 *
 * Method : GET or POST
 * Params :
 *   token   (optional) shared secret — same scheme as the ATS APIs, see
 *           ATS_API_TOKEN below. Empty const = open endpoint.
 *
 * Output : text/plain. Only products with a non-zero Magdyn balance are
 *          listed (zero / no-stock products are omitted). Codes are listed
 *          in code order. Content-Type is text/plain so the raw concatenated
 *          string is returned verbatim.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Optional shared-secret — kept identical to the ATS inventory APIs
// (ats_applied.php / ats_reject.php / ats_shipment.php). Leave '' to keep
// the endpoint open (LAN/internal use); set to require ?token=... on every call.
const ATS_API_TOKEN = '';

// ── Auth (same scheme as the ATS APIs) ───────────────────────────────────────
if (ATS_API_TOKEN !== '' && hash_equals(ATS_API_TOKEN, (string)input('token', '')) === false) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    echo 'Unauthorized.';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// ── Products with a non-zero Magdyn-location balance ─────────────────────────
// INNER JOIN on the stock row + qty <> 0 so only products actually holding
// stock at the Magdyn location are returned.
$rows = db_all(
    "SELECT i.code AS code,
            s.qty  AS qty
       FROM inv_items i
       JOIN locations l
         ON l.code = 'Magdyn'
       JOIN inv_item_location_stock s
         ON s.item_id = i.id
        AND s.location_id = l.id
      WHERE i.is_product = 1
        AND i.is_active  = 1
        AND s.qty <> 0
      ORDER BY i.code"
);

$parts = [];
foreach ($rows as $r) {
    // Trim trailing zeros so decimal(12,3) reads as "3.5" / "12", not "3.500".
    $qty = rtrim(rtrim(number_format((float)$r['qty'], 3, '.', ''), '0'), '.');
    if ($qty === '' || $qty === '-0') $qty = '0';
    $parts[] = $r['code'] . ':' . $qty;
}

echo implode(';', $parts);
