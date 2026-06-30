<?php
/**
 * MagDyn — Old Inventory "Created/Modified By" Export API
 *
 * Deploy this file on the OLD inventory server (PHP 5.6, 192.168.1.249)
 * in the web root (/inventory/). Point `creator_audit_url` in
 * config/old_inventory_api.php on the new MagDyn system at it.
 *
 * Purpose
 * -------
 * The new MagDyn system imported assets / models / transactions / shipments /
 * inspections WITHOUT carrying over who originally created or modified each
 * record. This endpoint re-exports, per legacy record, the *username* of the
 * old `created_by` / `modified_by` user (resolved through user_account by
 * user_account_id). The MagDyn admin "Creator Backfill" module consumes this
 * and stamps the matching new rows.
 *
 * For inspections the legacy `inspection_data` table has no created_by — it
 * carries a free-text `done_by` (the inspector's name). Per spec that value is
 * mapped to the inspection's creator/inspector on the new side.
 *
 * Endpoints (all GET, all require ?token=MAGDYN_IMPORT_SECRET):
 *   ?action=ping
 *       {"ok":true,"server":"api_export_audit_users"}
 *
 *   ?action=counts
 *       {"assets":N,"asset_txns":N,"inv_txns":N,"shipments":N,"inspections":N}
 *       (shipments = shipment rows + receipt rows;
 *        inspections = DISTINCT transaction_id in inspection_data)
 *
 *   ?action=assets&offset=0&limit=500
 *       {"rows":[{"old_id":<asset_id>,"created_by":<username|null>,
 *                 "modified_by":<username|null>}, ...]}
 *
 *   ?action=asset_txns&offset=0&limit=500
 *       {"rows":[{"asset_transaction_id":N,"transaction_id":N,"asset_id":N,
 *                 "created_by":<username|null>,"modified_by":<username|null>}, ...]}
 *
 *   ?action=inv_txns&offset=0&limit=500
 *       {"rows":[{"old_id":<inventory_transaction_id>,
 *                 "created_by":<username|null>,"modified_by":<username|null>}, ...]}
 *
 *   ?action=shipments&offset=0&limit=500
 *       {"rows":[{"transaction_id":N,"kind":"shipment|receipt",
 *                 "created_by":<username|null>,"modified_by":<username|null>}, ...]}
 *
 *   ?action=inspections&offset=0&limit=500
 *       {"rows":[{"transaction_id":N,"done_by":<name|null>}, ...]}
 *       Paginated over DISTINCT transaction_id; done_by = first non-empty
 *       reading author for that inspection event.
 *
 * The caller advances `offset` by `limit` until a page returns fewer than
 * `limit` rows (chunked import — never loads the whole table at once).
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

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

// Pagination — cap limit at 1000 to protect server memory.
$offset = max(0, (int) (isset($_GET['offset']) ? $_GET['offset'] : 0));
$limit  = min(1000, max(1, (int) (isset($_GET['limit']) ? $_GET['limit'] : 500)));

// ── ping ──────────────────────────────────────────────────────────────────────
if ($action === 'ping') {
    echo json_encode(array('ok' => true, 'server' => 'api_export_audit_users'));
    exit;
}

// ── counts ──────────────────────────────────────────────────────────────────────
if ($action === 'counts') {
    try {
        $out = array(
            'assets'      => (int) $pdo->query('SELECT COUNT(*) FROM asset')->fetchColumn(),
            'asset_txns'  => (int) $pdo->query('SELECT COUNT(*) FROM asset_transaction')->fetchColumn(),
            'inv_txns'    => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transaction')->fetchColumn(),
            'shipments'   => (int) $pdo->query('SELECT COUNT(*) FROM shipment')->fetchColumn()
                           + (int) $pdo->query('SELECT COUNT(*) FROM receipt')->fetchColumn(),
            'inspections' => (int) $pdo->query('SELECT COUNT(DISTINCT transaction_id) FROM inspection_data')->fetchColumn(),
        );
        echo json_encode($out);
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Count query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── assets ──────────────────────────────────────────────────────────────────────
if ($action === 'assets') {
    try {
        $sql = "
            SELECT a.asset_id          AS old_id,
                   uc.username         AS created_by,
                   um.username         AS modified_by
              FROM asset a
         LEFT JOIN user_account uc ON uc.user_account_id = a.created_by
         LEFT JOIN user_account um ON um.user_account_id = a.modified_by
          ORDER BY a.asset_id ASC
             LIMIT " . $limit . " OFFSET " . $offset . "
        ";
        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$r) { $r['old_id'] = (int) $r['old_id']; }
        echo json_encode(array('rows' => $rows, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── asset_txns ────────────────────────────────────────────────────────────────
if ($action === 'asset_txns') {
    try {
        $sql = "
            SELECT at.asset_transaction_id AS asset_transaction_id,
                   at.transaction_id       AS transaction_id,
                   at.asset_id             AS asset_id,
                   uc.username             AS created_by,
                   um.username             AS modified_by
              FROM asset_transaction at
         LEFT JOIN user_account uc ON uc.user_account_id = at.created_by
         LEFT JOIN user_account um ON um.user_account_id = at.modified_by
          ORDER BY at.asset_transaction_id ASC
             LIMIT " . $limit . " OFFSET " . $offset . "
        ";
        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$r) {
            $r['asset_transaction_id'] = (int) $r['asset_transaction_id'];
            $r['transaction_id']       = (int) $r['transaction_id'];
            $r['asset_id']             = (int) $r['asset_id'];
        }
        echo json_encode(array('rows' => $rows, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── inv_txns ──────────────────────────────────────────────────────────────────
if ($action === 'inv_txns') {
    try {
        $sql = "
            SELECT it.inventory_transaction_id AS old_id,
                   uc.username                 AS created_by,
                   um.username                 AS modified_by
              FROM inventory_transaction it
         LEFT JOIN user_account uc ON uc.user_account_id = it.created_by
         LEFT JOIN user_account um ON um.user_account_id = it.modified_by
          ORDER BY it.inventory_transaction_id ASC
             LIMIT " . $limit . " OFFSET " . $offset . "
        ";
        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$r) { $r['old_id'] = (int) $r['old_id']; }
        echo json_encode(array('rows' => $rows, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── shipments (shipment + receipt, by transaction_id) ─────────────────────────
if ($action === 'shipments') {
    try {
        // UNION ALL so a single offset/limit window walks shipments then
        // receipts in transaction_id order. The new side links a shipment back
        // through inv_shipment_lines.old_transaction_id, so transaction_id is
        // the join key for both kinds.
        $sql = "
            SELECT u.transaction_id AS transaction_id,
                   u.kind           AS kind,
                   uc.username      AS created_by,
                   um.username      AS modified_by
              FROM (
                    SELECT s.transaction_id AS transaction_id, 'shipment' AS kind,
                           s.created_by AS cb, s.modified_by AS mb
                      FROM shipment s
                    UNION ALL
                    SELECT r.transaction_id AS transaction_id, 'receipt' AS kind,
                           r.created_by AS cb, r.modified_by AS mb
                      FROM receipt r
                   ) u
         LEFT JOIN user_account uc ON uc.user_account_id = u.cb
         LEFT JOIN user_account um ON um.user_account_id = u.mb
          ORDER BY u.transaction_id ASC, u.kind ASC
             LIMIT " . $limit . " OFFSET " . $offset . "
        ";
        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$r) { $r['transaction_id'] = (int) $r['transaction_id']; }
        echo json_encode(array('rows' => $rows, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── inspections (done_by per inspection event = transaction_id) ───────────────
if ($action === 'inspections') {
    try {
        // One row per distinct transaction_id (= one inspection event in the new
        // system, coded OINS-T-<transaction_id>). done_by is the first non-empty
        // reading author recorded for that event.
        $sql = "
            SELECT idd.transaction_id AS transaction_id,
                   (
                     SELECT d2.done_by
                       FROM inspection_data d2
                      WHERE d2.transaction_id = idd.transaction_id
                        AND d2.done_by <> ''
                      ORDER BY d2.entry_id ASC
                      LIMIT 1
                   ) AS done_by
              FROM (
                    SELECT DISTINCT transaction_id
                      FROM inspection_data
                  ORDER BY transaction_id ASC
                     LIMIT " . $limit . " OFFSET " . $offset . "
                   ) idd
          ORDER BY idd.transaction_id ASC
        ";
        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$r) { $r['transaction_id'] = (int) $r['transaction_id']; }
        echo json_encode(array('rows' => $rows, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(array('error' => 'Unknown action. Supported: ping, counts, assets, asset_txns, inv_txns, shipments, inspections'));
