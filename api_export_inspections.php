<?php
/**
 * MagDyn — Old Inventory Inspection Export API
 *
 * Deploy this file on the OLD inventory server (PHP 5.6, 192.168.1.249).
 * Place it in the web root (/inventory/) and point `inspections_url` in
 * config/old_inventory_api.php on the new MagDyn system at it.
 *
 * It exports the legacy `inspection` table — the per-characteristic
 * inspection rows captured against each product. Each row carries a
 * `pid` (= legacy inventory_model_id, which equals the new MagDyn
 * inv_items.code) plus the bubble / dimension definition (nominal value,
 * tolerances, unit, parameter name, drawing no/rev, process step, etc.).
 *
 * MagDyn groups every row sharing a `pid` into ONE inspection template,
 * one template item per inspection row, and links the template to the
 * inv_item whose code = pid.
 *
 * We LEFT JOIN `inventory_model` so the importer also gets the legacy
 * model short_description / code as a fallback when the new side can't
 * resolve the pid to a current inv_item.
 *
 * Endpoints (all GET, all require ?token=MAGDYN_IMPORT_SECRET):
 *   ?action=ping
 *       Returns: {"ok": true, "server": "api_export_inspections"}
 *
 *   ?action=inspection_count
 *       Returns: {"count": N, "distinct_pids": M}
 *
 *   ?action=inspections_json&offset=0&limit=500
 *       Returns: {"rows": [ { ...row fields... }, ... ], "count": N}
 *
 *   ?action=inspection_data_count
 *       Returns: {"count": N, "transactions": M}
 *           N = total inspection_data rows, M = distinct transaction_id
 *           (one transaction = one recorded inspection event).
 *
 *   ?action=inspection_data_json&txn_offset=0&txn_limit=50
 *       Returns: {"rows":[...], "count":N, "txn_count":K,
 *                 "txn_offset":O, "txn_limit":L}
 *           Paginates by *transaction* (not row): returns every reading for
 *           the next L distinct transaction_ids. Chunking by transaction
 *           keeps a single inspection event whole — one transaction can hold
 *           thousands of readings (samples × bubbles), so a row-window would
 *           split it. The importer is done when txn_count < txn_limit.
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
    echo json_encode(array('ok' => true, 'server' => 'api_export_inspections'));
    exit;
}

// ── inspection_count — total rows + distinct products ───────────────────────────
if ($action === 'inspection_count') {
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM inspection')->fetchColumn();
        $p = (int)$pdo->query('SELECT COUNT(DISTINCT pid) FROM inspection')->fetchColumn();
        echo json_encode(array('count' => $n, 'distinct_pids' => $p));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Count query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── inspections_json — paginated rows, grouped-friendly order ────────────────────
if ($action === 'inspections_json') {
    try {
        // Optional single-product filter. The "Restore one template's bubbles"
        // button in MagDyn passes ?pid=<n> so it can rebuild just that template
        // without walking the whole table. Absent/blank = full export (old
        // behaviour). Parameterised to stay injection-safe.
        $pidFilter = isset($_GET['pid']) ? trim((string)$_GET['pid']) : '';
        $where  = '';
        $params = array();
        if ($pidFilter !== '') {
            $where = ' WHERE i.pid = ? ';
            $params[] = $pidFilter;
        }

        // Order by pid so a single product's rows stay together, then by the
        // row id (initialdata_id) so bubble order is preserved. The importer
        // re-groups by pid anyway, but a stable order keeps templates tidy.
        $sql = "
            SELECT i.pid, i.BubbleNo, i.DrawingNo, i.Rev, i.ProductName,
                   i.ProcessStep, i.HowMeasured, i.NomValue, i.Tolneg, i.Tolpos,
                   i.toltype, i.ProcessType, i.unitofmeasured, i.Parametername,
                   i.stepno, i.materialspec, i.processspec, i.initialdata_id,
                   i.minimum, i.maximum, i.notes, i.description,
                   im.short_description    AS model_short,
                   im.inventory_model_code AS model_code
              FROM inspection i
         LEFT JOIN inventory_model im ON im.inventory_model_id = i.pid
              " . $where . "
          ORDER BY CAST(i.pid AS UNSIGNED) ASC, i.pid ASC, i.initialdata_id ASC
             LIMIT " . $limit . " OFFSET " . $offset . "
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        echo json_encode(array('rows' => $rows, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── inspection_data_count — total readings + distinct transactions ───────────────
if ($action === 'inspection_data_count') {
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM inspection_data')->fetchColumn();
        $t = (int)$pdo->query('SELECT COUNT(DISTINCT transaction_id) FROM inspection_data')->fetchColumn();
        echo json_encode(array('count' => $n, 'transactions' => $t));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Count query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── inspection_data_json — paginated by TRANSACTION (keeps an event whole) ───────
if ($action === 'inspection_data_json') {
    $txnOffset = max(0, (int)(isset($_GET['txn_offset']) ? $_GET['txn_offset'] : 0));
    $txnLimit  = min(500, max(1, (int)(isset($_GET['txn_limit']) ? $_GET['txn_limit'] : 50)));
    try {
        // Pick the next window of distinct transaction_ids, then fetch every
        // reading belonging to them. insp_bubbleno is the real drawing bubble
        // number that joins to inspection.BubbleNo / the new template item;
        // bubble_no is a sequential display index kept as a fallback.
        $idStmt = $pdo->prepare(
            'SELECT DISTINCT transaction_id FROM inspection_data
              ORDER BY transaction_id LIMIT ' . $txnLimit . ' OFFSET ' . $txnOffset
        );
        $idStmt->execute();
        $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN, 0);

        if (empty($ids)) {
            echo json_encode(array('rows' => array(), 'count' => 0, 'txn_count' => 0,
                                   'txn_offset' => $txnOffset, 'txn_limit' => $txnLimit));
            exit;
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $sql = '
            SELECT entry_id, transaction_id, p_id, bubble_no, insp_bubbleno,
                   sample_number, data, done_by, inspection_date
              FROM inspection_data
             WHERE transaction_id IN (' . $place . ')
             ORDER BY transaction_id, CAST(insp_bubbleno AS UNSIGNED), insp_bubbleno,
                      sample_number, entry_id';
        $rowStmt = $pdo->prepare($sql);
        $rowStmt->execute(array_map('intval', $ids));
        $rows = $rowStmt->fetchAll();

        echo json_encode(array(
            'rows'       => $rows,
            'count'      => count($rows),
            'txn_count'  => count($ids),
            'txn_offset' => $txnOffset,
            'txn_limit'  => $txnLimit,
        ));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

echo json_encode(array('error' => 'Unknown action: ' . $action));
