<?php
/**
 * MagDyn — Old Inventory Invoice Export API
 *
 * Deploy this file on the OLD inventory server (PHP 5.6, 192.168.1.249).
 * Place it in the web root (/inventory/) and point `invoices_url` in
 * config/old_inventory_api.php on the new MagDyn system at it.
 *
 * It exports the legacy purchase-invoice tables:
 *
 *   approveinv  — invoices ENTERED but not yet approved / linked.
 *                 Imported into MagDyn as status = 'pending'.
 *   recp_inv    — invoices APPROVED and linked to a transaction (trans_id).
 *                 Imported into MagDyn as status = 'approved'.
 *
 * Both tables are line-level: one row per invoice line item. Many rows
 * share a single `inv_no` (the vendor's invoice number) — MagDyn groups
 * them back into one invoice header with multiple line items.
 *
 * Each row carries `product_id`, which is the legacy `inventory_model_id`
 * (the new MagDyn `inv_items.code` is that same id). We LEFT JOIN
 * `inventory_model` so the importer also gets the authoritative model
 * name/code as a fallback when the new side can't resolve the code.
 *
 * Endpoints (all GET, all require ?token=MAGDYN_IMPORT_SECRET):
 *   ?action=ping
 *       Returns: {"ok": true, "server": "api_export_invoices"}
 *
 *   ?action=invoice_count
 *       Returns: {"approveinv": N, "recp_inv": M, "count": N+M}
 *
 *   ?action=invoices_json&src=approveinv&offset=0&limit=500
 *   ?action=invoices_json&src=recp_inv&offset=0&limit=500
 *       Returns: {"rows": [ { ...line fields... }, ... ], "count": N}
 *
 * PHP 5.6 compatible — no null coalescing, no return types, no scalar hints.
 */

// ── Shared secret ────────────────────────────────────────────────────────────
define('API_TOKEN', 'MAGDYN_IMPORT_SECRET');   // ← must match config/old_inventory_api.php

// ── Auth check ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($token !== API_TOKEN) {
    http_response_code(403);
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

// ── DB connection (local inventory_live) ─────────────────────────────────────
$db_host = '127.0.0.1';
$db_name = 'inventory_live';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO(
        'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4',
        $db_user,
        $db_pass,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => 'DB connection failed: ' . $e->getMessage()));
    exit;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

// Pagination — cap limit at 1000 to protect server memory.
$offset = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));
$limit  = min(1000, max(1, (int)(isset($_GET['limit']) ? $_GET['limit'] : 500)));

// ── ping ──────────────────────────────────────────────────────────────────────
if ($action === 'ping') {
    echo json_encode(array('ok' => true, 'server' => 'api_export_invoices'));
    exit;
}

// ── invoice_count — line counts per source table ───────────────────────────────
if ($action === 'invoice_count') {
    try {
        $a = (int)$pdo->query('SELECT COUNT(*) FROM approveinv')->fetchColumn();
        $r = (int)$pdo->query('SELECT COUNT(*) FROM recp_inv')->fetchColumn();
        echo json_encode(array('approveinv' => $a, 'recp_inv' => $r, 'count' => $a + $r));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Count query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── invoices_json — one source table, paginated ────────────────────────────────
if ($action === 'invoices_json') {
    $src = isset($_GET['src']) ? (string)$_GET['src'] : '';
    if ($src !== 'approveinv' && $src !== 'recp_inv') {
        echo json_encode(array('error' => "Unknown src '$src' (expected approveinv|recp_inv)."));
        exit;
    }

    try {
        if ($src === 'approveinv') {
            // approveinv → pending. Order by inv_no so a single invoice's
            // lines stay grouped; the importer re-groups anyway.
            $sql = "
                SELECT a.aprid              AS src_id,
                       a.inv_no, a.class, a.inv_date, a.date_created,
                       a.unit_price, a.qty, a.gst, a.notes, a.trans_id,
                       a.ledger, a.refno, a.companyname, a.productname,
                       a.department, a.financialyear, a.product_id,
                       a.company_id, a.uom, a.grnno, a.hsn_code, a.gst_type,
                       im.short_description     AS model_name,
                       im.inventory_model_code  AS model_code
                  FROM approveinv a
             LEFT JOIN inventory_model im ON im.inventory_model_id = a.product_id
              ORDER BY a.inv_no ASC, a.aprid ASC
                 LIMIT " . $limit . " OFFSET " . $offset . "
            ";
        } else {
            // recp_inv → approved. Carries aprid + payment fields too.
            $sql = "
                SELECT r.inv_id             AS src_id,
                       r.inv_no, r.class, r.inv_date, r.date_created,
                       r.unit_price, r.qty, r.gst, r.notes, r.trans_id,
                       r.ledger, r.refno, r.aprid, r.companyname, r.productname,
                       r.department, r.financialyear, r.product_id,
                       r.company_id, r.uom, r.grnno,
                       r.paymetndate, r.paymentreferal, r.hsn_code, r.gst_type,
                       im.short_description     AS model_name,
                       im.inventory_model_code  AS model_code
                  FROM recp_inv r
             LEFT JOIN inventory_model im ON im.inventory_model_id = r.product_id
              ORDER BY r.inv_no ASC, r.inv_id ASC
                 LIMIT " . $limit . " OFFSET " . $offset . "
            ";
        }

        $rows = $pdo->query($sql)->fetchAll();
        echo json_encode(array('rows' => $rows, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

echo json_encode(array('error' => 'Unknown action: ' . $action));
