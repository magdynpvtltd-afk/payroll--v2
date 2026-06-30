<?php
/**
 * MagDyn — Admin ▸ Creator Backfill
 * Created: 20260612_181500_IST
 *
 * Self-contained admin module that stamps the ORIGINAL "created by" user onto
 * records imported from the old inventory system, by pulling per-record
 * created_by / modified_by usernames from the old server
 * (api_export_audit_users.php) and matching them to MagDyn users.
 *
 * This module ONLY writes creator-analog columns that already exist — it does
 * NOT add schema. Targets:
 *   assets              → assets.created_by          (join: asset_tag = old asset_id)
 *   asset transactions  → asset_transactions.actor_id(join: notes [old-txn:N] + asset_tag)
 *   inventory txns      → inv_txns.created_by         (join: ref_doc = OLD-ITX-N)
 *   shipments           → inv_shipments.created_by    (join: inv_shipment_lines.old_transaction_id)
 *   inspections         → inspections.inspected_by    (join: code = OINS-T-N; source = done_by)
 *
 * Models (asset_models) and inventory items (inv_items) are intentionally NOT
 * touched — they have no created_by/modified_by column in the new schema.
 *
 * Everything is chunked: the page POSTs ?action=run_chunk&entity=X&offset=N for
 * one ~500-row window at a time and the browser loops until done, so the
 * largest table (inventory transactions, ~43k rows) can't time the request out.
 *
 * Actions:
 *   ?action=index                 landing + progress UI (default)
 *   ?action=counts        (GET)   JSON: source + linkable counts
 *   ?action=run_chunk     (POST)  JSON: apply one chunk for one entity
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$action = (string) input('action', 'index');

require_permission('creator_backfill', 'view');

// ─────────────────────────────────────────────────────────────────────────────
//  Old-server API client (creator_audit_url) — self-contained to this module.
// ─────────────────────────────────────────────────────────────────────────────
function cb_audit_api($apiAction, array $params = [])
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config/old_inventory_api.php';
    }
    if (empty($cfg['creator_audit_url'])) {
        throw new RuntimeException('creator_audit_url not set in config/old_inventory_api.php.');
    }

    $params['action'] = $apiAction;
    $params['token']  = $cfg['token'];

    $url = rtrim($cfg['creator_audit_url'], '/') . '?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => isset($cfg['timeout']) ? $cfg['timeout'] : 120,
            'header'        => "Accept: application/json\r\nConnection: close\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException(
            'Could not reach the old-inventory audit API at ' . $cfg['creator_audit_url'] .
            '. Check the server is reachable and api_export_audit_users.php is deployed.'
        );
    }

    $data = json_decode($raw, true);
    if ($data === null) {
        throw new RuntimeException('Audit API returned invalid JSON. First 200 chars: ' . substr($raw, 0, 200));
    }
    if (isset($data['error'])) {
        throw new RuntimeException('Audit API error: ' . $data['error']);
    }
    return $data;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Entity registry — drives the API call, the UPDATE, and the UI cards.
// ─────────────────────────────────────────────────────────────────────────────
function cb_entities()
{
    return [
        'assets' => [
            'label'      => 'Assets',
            'api'        => 'assets',
            'target'     => 'assets.created_by',
            'src_field'  => 'created_by',
            'join'       => 'asset_tag = old asset_id',
        ],
        'asset_txns' => [
            'label'      => 'Asset transactions',
            'api'        => 'asset_txns',
            'target'     => 'asset_transactions.actor_id',
            'src_field'  => 'created_by',
            'join'       => 'notes [old-txn:N] + asset',
        ],
        'inv_txns' => [
            'label'      => 'Inventory transactions',
            'api'        => 'inv_txns',
            'target'     => 'inv_txns.created_by',
            'src_field'  => 'created_by',
            'join'       => 'ref_doc = OLD-ITX-N',
        ],
        'shipments' => [
            'label'      => 'Shipments / Receipts',
            'api'        => 'shipments',
            'target'     => 'inv_shipments.created_by',
            'src_field'  => 'created_by',
            'join'       => 'line old_transaction_id',
        ],
        'inspections' => [
            'label'      => 'Inspections',
            'api'        => 'inspections',
            'target'     => 'inspections.inspected_by',
            'src_field'  => 'done_by',
            'join'       => 'code = OINS-T-N (done_by)',
        ],
    ];
}

// Resolve an old username / inspector name to a MagDyn users.id.
// Username is unique (primary match); full_name is a best-effort fallback.
// $cache and $unmatched are kept across rows within a single chunk request.
function cb_resolve_user($name, array &$cache, array &$unmatched)
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }
    $key = mb_strtolower($name);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $row = db_one('SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1', [$name]);
    if (!$row) {
        $row = db_one('SELECT id FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1', [$name]);
    }

    $id = $row ? (int) $row['id'] : null;
    if ($id === null) {
        $unmatched[$name] = true;
    }
    return $cache[$key] = $id;
}

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX (GET): source counts + locally-linkable counts for the progress UI.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'counts') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $src = cb_audit_api('counts');

        $local = [
            'assets'      => (int) db_val("SELECT COUNT(*) FROM assets WHERE asset_tag REGEXP '^[0-9]+$'", [], 0),
            'asset_txns'  => (int) db_val("SELECT COUNT(*) FROM asset_transactions WHERE notes LIKE '%[old-txn:%'", [], 0),
            'inv_txns'    => (int) db_val("SELECT COUNT(*) FROM inv_txns WHERE ref_doc LIKE 'OLD-ITX-%'", [], 0),
            'shipments'   => (int) db_val('SELECT COUNT(DISTINCT shipment_id) FROM inv_shipment_lines WHERE old_transaction_id IS NOT NULL AND old_transaction_id > 0', [], 0),
            'inspections' => (int) db_val("SELECT COUNT(*) FROM inspections WHERE code LIKE 'OINS-T-%'", [], 0),
        ];

        echo json_encode(['ok' => true, 'source' => $src, 'local' => $local]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX (POST): apply ONE chunk for ONE entity. Chunked + idempotent.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'run_chunk') {
    header('Content-Type: application/json; charset=utf-8');
    require_permission('creator_backfill', 'manage');
    csrf_check();
    @set_time_limit(0);

    $entity = (string) input('entity', '');
    $offset = max(0, (int) input('offset', 0));
    $limit  = 500;

    $entities = cb_entities();
    if (!isset($entities[$entity])) {
        echo json_encode(['ok' => false, 'error' => 'Unknown entity: ' . $entity]);
        exit;
    }
    $cfg = $entities[$entity];

    try {
        $data    = cb_audit_api($cfg['api'], ['offset' => $offset, 'limit' => $limit]);
        $rows    = isset($data['rows']) ? $data['rows'] : [];
        $fetched = count($rows);
        $done    = ($fetched < $limit);

        $pdo = db();

        // One prepared statement per entity, reused across the chunk.
        switch ($entity) {
            case 'assets':
                $stmt = $pdo->prepare('UPDATE assets SET created_by = ? WHERE asset_tag = ?');
                break;
            case 'asset_txns':
                $stmt = $pdo->prepare(
                    'UPDATE asset_transactions at
                       JOIN assets a ON a.id = at.asset_id
                        SET at.actor_id = ?
                      WHERE a.asset_tag = ? AND at.notes LIKE ?'
                );
                break;
            case 'inv_txns':
                $stmt = $pdo->prepare('UPDATE inv_txns SET created_by = ? WHERE ref_doc = ?');
                break;
            case 'shipments':
                $stmt = $pdo->prepare(
                    'UPDATE inv_shipments s
                       JOIN inv_shipment_lines l ON l.shipment_id = s.id
                        SET s.created_by = ?
                      WHERE l.old_transaction_id = ?'
                );
                break;
            case 'inspections':
                $stmt = $pdo->prepare('UPDATE inspections SET inspected_by = ? WHERE code = ?');
                break;
        }

        $updated      = 0;   // rows actually changed by an UPDATE
        $matchedUser  = 0;   // source rows whose user resolved to a MagDyn id
        $skippedNoUsr = 0;   // source rows with empty/unresolved creator
        $cache        = [];
        $unmatched    = [];

        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $uid = cb_resolve_user(isset($r[$cfg['src_field']]) ? $r[$cfg['src_field']] : '', $cache, $unmatched);
            if ($uid === null) {
                $skippedNoUsr++;
                continue;
            }
            $matchedUser++;

            switch ($entity) {
                case 'assets':
                    $stmt->execute([$uid, (string) $r['old_id']]);
                    break;
                case 'asset_txns':
                    $stmt->execute([$uid, (string) $r['asset_id'], '%[old-txn:' . (int) $r['transaction_id'] . ']%']);
                    break;
                case 'inv_txns':
                    $stmt->execute([$uid, 'OLD-ITX-' . (int) $r['old_id']]);
                    break;
                case 'shipments':
                    $stmt->execute([$uid, (int) $r['transaction_id']]);
                    break;
                case 'inspections':
                    $stmt->execute([$uid, 'OINS-T-' . (int) $r['transaction_id']]);
                    break;
            }
            $updated += $stmt->rowCount();
        }
        $pdo->commit();

        echo json_encode([
            'ok'             => true,
            'fetched'        => $fetched,
            'next_offset'    => $offset + $fetched,
            'done'           => $done,
            'updated'        => $updated,
            'matched_user'   => $matchedUser,
            'skipped_no_user'=> $skippedNoUsr,
            'unmatched'      => array_keys($unmatched),
        ]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        echo json_encode(['ok' => false, 'error' => 'Chunk @ ' . $offset . ': ' . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
//  GET: landing page + progress UI
// ─────────────────────────────────────────────────────────────────────────────
$canManage = permission_check('creator_backfill', 'manage');

// Probe the old server so an unreachable host renders a clear banner.
$apiError = null;
try {
    cb_audit_api('ping');
} catch (Throwable $e) {
    $apiError = $e->getMessage();
}

$entities    = cb_entities();
$page_title  = 'Creator Backfill';
$page_module = 'admin';
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<style>
  .cb-wrap { max-width: 920px; }
  .cb-card { border: 1px solid var(--border, #d8dee9); border-radius: 10px; padding: 16px 18px; margin-bottom: 14px; background: var(--card-bg, #fff); }
  .cb-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  .cb-meta { font-size: 12px; color: var(--muted, #6b7280); }
  .cb-meta code { font-size: 11px; }
  .cb-bar { height: 8px; border-radius: 6px; background: #e5e7eb; overflow: hidden; margin-top: 10px; }
  .cb-bar > span { display: block; height: 100%; width: 0; background: #2563eb; transition: width .2s ease; }
  .cb-stat { font-variant-numeric: tabular-nums; font-size: 13px; }
  .cb-status { font-size: 12px; margin-top: 6px; min-height: 16px; }
  .cb-status.ok { color: #15803d; }
  .cb-status.err { color: #b91c1c; }
  .cb-unmatched { font-size: 12px; color: #b45309; margin-top: 4px; }
  .cb-banner { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
  .cb-banner.err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  .cb-banner.info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
</style>

<div class="cb-wrap">
  <h1 style="margin-bottom:4px;">Creator Backfill</h1>
  <p class="cb-meta" style="margin-top:0;">
    Stamps the original <strong>created-by</strong> user (from the old inventory
    system) onto records already imported into MagDyn. Pulls usernames from
    <code>api_export_audit_users.php</code> and matches them to MagDyn users by
    username (then full name). Re-runnable — applying twice is harmless.
  </p>

  <?php if ($apiError): ?>
    <div class="cb-banner err">
      <strong>Old server unreachable.</strong> <?= h($apiError) ?><br>
      Deploy <code>api_export_audit_users.php</code> to the old server and confirm
      <code>creator_audit_url</code> + token in <code>config/old_inventory_api.php</code>.
    </div>
  <?php elseif (!$canManage): ?>
    <div class="cb-banner info">
      You have view access only. Ask an administrator for the
      <code>creator_backfill.manage</code> permission to run the backfill.
    </div>
  <?php endif; ?>

  <div style="margin:14px 0;">
    <button type="button" class="btn" id="cb-run-all" <?= ($apiError || !$canManage) ? 'disabled' : '' ?>>
      Run all
    </button>
    <span class="cb-meta" style="margin-left:8px;">Runs each entity in sequence, chunk by chunk.</span>
  </div>

  <?php foreach ($entities as $key => $e): ?>
    <div class="cb-card" data-entity="<?= h($key) ?>">
      <div class="cb-row">
        <div>
          <strong><?= h($e['label']) ?></strong>
          <div class="cb-meta">
            → <code><?= h($e['target']) ?></code> &nbsp;·&nbsp; join: <?= h($e['join']) ?>
          </div>
        </div>
        <div style="text-align:right;">
          <div class="cb-stat" data-role="counts">—</div>
          <button type="button" class="btn btn-sm" data-role="run" <?= ($apiError || !$canManage) ? 'disabled' : '' ?>>
            Run
          </button>
        </div>
      </div>
      <div class="cb-bar"><span data-role="bar"></span></div>
      <div class="cb-status" data-role="status"></div>
      <div class="cb-unmatched" data-role="unmatched"></div>
    </div>
  <?php endforeach; ?>
</div>

<script>
(function () {
  var CSRF_FIELD = <?= json_encode($GLOBALS['APP']['csrf_field']) ?>;
  var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
  var URL_BASE   = <?= json_encode(url('/admin_user_backfill.php')) ?>;
  var ENTITIES   = <?= json_encode(array_keys($entities)) ?>;

  function card(entity) { return document.querySelector('.cb-card[data-entity="' + entity + '"]'); }
  function setBar(entity, pct) { card(entity).querySelector('[data-role=bar]').style.width = Math.max(0, Math.min(100, pct)) + '%'; }
  function setStatus(entity, msg, cls) {
    var el = card(entity).querySelector('[data-role=status]');
    el.textContent = msg || '';
    el.className = 'cb-status' + (cls ? ' ' + cls : '');
  }
  function setCounts(entity, txt) { card(entity).querySelector('[data-role=counts]').textContent = txt; }

  var srcCounts = {}, localCounts = {};

  function loadCounts() {
    return fetch(URL_BASE + '?action=counts', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { ENTITIES.forEach(function (e) { setStatus(e, d.error, 'err'); }); return; }
        srcCounts = d.source || {}; localCounts = d.local || {};
        ENTITIES.forEach(function (e) {
          setCounts(e, (localCounts[e] || 0) + ' linkable · ' + (srcCounts[e] || 0) + ' source');
        });
      });
  }

  function runEntity(entity) {
    return new Promise(function (resolve) {
      var total = srcCounts[entity] || 0;
      var processed = 0, updated = 0, matched = 0, skipped = 0;
      var unmatched = {};
      var runBtn = card(entity).querySelector('[data-role=run]');
      runBtn.disabled = true;
      setStatus(entity, 'Running…');
      card(entity).querySelector('[data-role=unmatched]').textContent = '';

      function step(offset) {
        var body = 'action=run_chunk'
          + '&entity=' + encodeURIComponent(entity)
          + '&offset=' + offset
          + '&' + encodeURIComponent(CSRF_FIELD) + '=' + encodeURIComponent(CSRF_TOKEN);

        fetch(URL_BASE, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { setStatus(entity, d.error, 'err'); runBtn.disabled = false; resolve(false); return; }

          processed += d.fetched;
          updated   += d.updated;
          matched   += d.matched_user;
          skipped   += d.skipped_no_user;
          (d.unmatched || []).forEach(function (n) { unmatched[n] = true; });

          if (total > 0) { setBar(entity, processed / total * 100); }
          setStatus(entity, 'Processed ' + processed + (total ? '/' + total : '')
            + ' · stamped ' + updated + ' · matched user ' + matched + ' · skipped ' + skipped);

          if (d.done) {
            setBar(entity, 100);
            setStatus(entity, 'Done. Stamped ' + updated + ' record(s); '
              + matched + ' had a matched user, ' + skipped + ' skipped (no/unknown user).', 'ok');
            var names = Object.keys(unmatched);
            if (names.length) {
              card(entity).querySelector('[data-role=unmatched]').textContent =
                'Unmatched names (no MagDyn user): ' + names.join(', ');
            }
            runBtn.disabled = false;
            resolve(true);
          } else {
            step(d.next_offset);
          }
        })
        .catch(function (err) { setStatus(entity, String(err), 'err'); runBtn.disabled = false; resolve(false); });
      }
      step(0);
    });
  }

  // Wire per-entity Run buttons.
  ENTITIES.forEach(function (entity) {
    var btn = card(entity).querySelector('[data-role=run]');
    if (btn) { btn.addEventListener('click', function () { runEntity(entity); }); }
  });

  // Run all — sequential so the old server isn't hammered in parallel.
  var runAll = document.getElementById('cb-run-all');
  if (runAll) {
    runAll.addEventListener('click', function () {
      runAll.disabled = true;
      var i = 0;
      (function next() {
        if (i >= ENTITIES.length) { runAll.disabled = false; return; }
        runEntity(ENTITIES[i++]).then(next);
      })();
    });
  }

  loadCounts();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
