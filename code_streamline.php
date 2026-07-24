<?php
/**
 * MagDyn — Streamline Asset & Inventory codes (admin)
 * Created: 20260723_IST
 *
 * A one-click cleanup that converts the remaining NON-NUMERIC asset
 * tags / inventory item codes into plain numeric codes, so the whole
 * catalogue reads as numbers. Most codes were imported numeric
 * (assets 1..916, items up to 2303); only the app-generated ones
 * (AST-#####, P-#####, I-<timestamp>) are still prefixed. This page
 * renumbers just those, appending from a chosen base:
 *
 *   • Streamline Asset code      → new codes start at 917
 *   • Streamline Inventory code  → new codes start at 2310
 *
 * Design decisions (confirmed with the operator):
 *   - Scope: ACTIVE rows only (archived assets / inactive-obsolete
 *     items keep whatever code they have).
 *   - Already-numeric codes are LEFT UNTOUCHED.
 *   - New codes are plain integers, assigned sequentially from the
 *     base, skipping any integer already in use anywhere in the table
 *     (so the result is globally unique).
 *   - Because every base is above the current max numeric code, the
 *     new numbers never collide with an existing code — the rename is
 *     collision-free and the op is naturally re-runnable (a second run
 *     finds nothing left non-numeric).
 *   - Denormalised copies of the code (ats_lines.inv_code,
 *     invoice_items.item_code, inv_so_pending_summary.item_code) are
 *     updated in the SAME transaction so nothing dangles.
 *   - Optionally the matching code_sequences row is switched to a bare
 *     numeric format so NEW assets/items keep counting up numerically
 *     instead of minting AST-/P- codes again.
 *
 * Every run is recorded (code_streamline_runs + code_streamline_map)
 * and the old→new mapping is downloadable as a CSV (Excel-friendly).
 *
 * Lives at /code_streamline.php under the Admin sidebar group.
 * Permissions: 'code_streamline' module — 'view' to see, 'manage' to run.
 *
 * NOTE on external systems: inventory codes are also pushed outward as
 * the sync key to the Billing app (inv_code) and travel on ATS pushes.
 * Renumbering an item changes its billing hash and will trigger a
 * re-push on the next sync; if the billing side keys on the code string
 * rather than the numeric magdyn id, coordinate before running. This is
 * surfaced as a warning on the page.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_code_streamline.php';
require_login();

// --- base numbers (per operator's spec) ---
const CS_ASSET_START = 917;
const CS_INV_START   = 2310;

$canManage = permission_check('code_streamline', 'manage');
if (!permission_check('code_streamline', 'view')) {
    require_permission('code_streamline', 'view');
}

$action = (string)input('action', 'index');

// ==================================================================
// RUN — perform a streamline (POST only, gated + confirmed)
// ==================================================================
if ($action === 'run') {
    require_permission('code_streamline', 'manage');
    csrf_check();

    $kind = (input('kind') === 'inventory') ? 'inventory' : 'asset';
    if (input('confirm') !== 'yes') {
        flash_set('error', 'Please tick the confirmation box before running.');
        redirect(url('/code_streamline.php'));
    }
    $updateSeq = (bool)input('update_sequence');
    $start = ($kind === 'asset') ? CS_ASSET_START : CS_INV_START;

    try {
        $res = cs_streamline($kind, $start, $updateSeq);
        if ($res['count'] === 0) {
            flash_set('success', ucfirst($kind) . ' codes are already all numeric — nothing to change.');
            redirect(url('/code_streamline.php'));
        }
        flash_set('success', sprintf(
            'Streamlined %d %s code%s (%s → %s).%s Download the mapping CSV below.',
            $res['count'], $kind, $res['count'] === 1 ? '' : 's',
            $res['min'], $res['max'],
            $updateSeq ? ' Future codes will continue numerically.' : ''
        ));
        redirect(url('/code_streamline.php?last_run=' . $res['run_id']));
    } catch (\Throwable $e) {
        flash_set('error', 'Streamline failed — no changes were made (rolled back). ' . $e->getMessage());
        redirect(url('/code_streamline.php'));
    }
}

// ==================================================================
// DOWNLOAD — stream a run's mapping as CSV (Excel-friendly, BOM)
// ==================================================================
if ($action === 'download') {
    require_permission('code_streamline', 'view');
    cs_ensure_tables();
    $runId = (int)input('run_id');
    $run = db_one("SELECT * FROM code_streamline_runs WHERE id = ?", [$runId]);
    if (!$run) {
        flash_set('error', 'Run not found.');
        redirect(url('/code_streamline.php'));
    }
    $rows = db_all("SELECT * FROM code_streamline_map WHERE run_id = ? ORDER BY id", [$runId]);
    $kind = $run['kind'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="streamline_' . $kind . '_run' . $runId . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));   // UTF-8 BOM for Excel
    if ($kind === 'asset') {
        fputcsv($out, ['Asset ID', 'Asset Name', 'Model', 'Old Asset Code', 'New Asset Code']);
    } else {
        fputcsv($out, ['Item ID', 'Item Name', 'Part No', 'Old Inventory Code', 'New Inventory Code']);
    }
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['entity_id'],
            str_replace(["\r\n", "\r", "\n"], ' ', (string)$r['entity_name']),
            str_replace(["\r\n", "\r", "\n"], ' ', (string)$r['extra_info']),
            $r['old_code'],
            $r['new_code'],
        ]);
    }
    fclose($out);
    exit;
}

// ==================================================================
// INDEX — the admin page with the two buttons
// ==================================================================
cs_ensure_tables();

// Live counts of what each button would change right now.
$assetPending = (int)db_val(
    "SELECT COUNT(*) FROM assets WHERE status <> 'archived' AND asset_tag NOT REGEXP '^[0-9]+$'", [], 0);
$invPending = (int)db_val(
    "SELECT COUNT(*) FROM inv_items WHERE is_active = 1 AND is_obsolete = 0 AND code NOT REGEXP '^[0-9]+$'", [], 0);

$lastRun    = (int)input('last_run', 0);
$recentRuns = db_all("SELECT r.*, u.name AS run_by_name
                        FROM code_streamline_runs r
                        LEFT JOIN users u ON u.id = r.run_by
                       ORDER BY r.id DESC LIMIT 15");

$page_title  = 'Streamline codes';
$page_module = 'code_streamline';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Streamline Asset &amp; Inventory codes</h1>
        <p class="muted">
            Converts the remaining <strong>non-numeric</strong> codes into plain numbers so the whole
            catalogue reads numerically. Already-numeric codes are left untouched. New asset codes start
            at <code><?= CS_ASSET_START ?></code>; new inventory codes start at <code><?= CS_INV_START ?></code>.
            Only <strong>active</strong> rows are affected.
        </p>
    </div>
</div>

<?php if ($lastRun): $lr = db_one("SELECT * FROM code_streamline_runs WHERE id = ?", [$lastRun]); ?>
    <?php if ($lr): ?>
    <div class="card" style="padding:16px; border-left:4px solid var(--accent, #2d7ff9); margin-bottom:16px;">
        <div><strong>Last run #<?= (int)$lr['id'] ?></strong> — <?= h(ucfirst($lr['kind'])) ?>:
            changed <?= (int)$lr['total_changed'] ?> code(s)
            <?php if ($lr['total_changed']): ?>(<?= h($lr['min_new_code']) ?> → <?= h($lr['max_new_code']) ?>)<?php endif; ?>.
        </div>
        <div style="margin-top:10px;">
            <a class="btn btn-primary" href="<?= h(url('/code_streamline.php?action=download&run_id=' . (int)$lr['id'])) ?>">
                ⬇ Download mapping CSV
            </a>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card" style="padding:14px 16px; background:var(--surface-alt,#fff8e1); border:1px solid var(--warn-border,#f0d58a); margin-bottom:20px;">
    <strong>⚠ Please read before running</strong>
    <ul class="muted small" style="margin:8px 0 0 18px;">
        <li>This rewrites the actual asset tags / item codes and every stored copy of them
            (invoices, ATS lines, SO-pending). It runs in a transaction and is logged, but there is
            <strong>no one-click undo</strong> — keep the CSV it produces.</li>
        <li>Inventory codes are also the sync key sent to the <strong>Billing</strong> app (and on ATS
            pushes). Renumbering an item will trigger a re-push on its next sync. If billing keys on the
            code string rather than the internal id, coordinate first.</li>
        <li>Take a DB backup before the first run if you can.</li>
    </ul>
</div>

<div class="grid-2col" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:18px;">

    <!-- ---------------- Asset button ---------------- -->
    <form method="post" action="<?= h(url('/code_streamline.php?action=run')) ?>" class="card"
          style="padding:18px;" onsubmit="return confirm('Streamline ASSET codes now? This will renumber <?= $assetPending ?> non-numeric asset tag(s) starting at <?= CS_ASSET_START ?>. This cannot be undone with one click.');">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="asset">
        <h2 style="margin:0 0 4px;">1 · Streamline Asset code</h2>
        <p class="muted small" style="margin:0 0 12px;">
            New numeric tags assigned from <code><?= CS_ASSET_START ?></code> upward.
        </p>
        <p style="margin:0 0 12px;">
            <span class="pill <?= $assetPending ? 'pill-active' : 'pill-neutral' ?>">
                <?= $assetPending ?> non-numeric asset code<?= $assetPending === 1 ? '' : 's' ?> to convert
            </span>
        </p>
        <?php if ($canManage): ?>
            <label class="inline" style="display:block; margin-bottom:8px;">
                <input type="checkbox" name="update_sequence" value="1" checked>
                Also set new assets to keep counting numerically
            </label>
            <label class="inline" style="display:block; margin-bottom:14px;">
                <input type="checkbox" name="confirm" value="yes" required>
                I understand this rewrites live asset codes.
            </label>
            <button type="submit" class="btn btn-primary" <?= $assetPending ? '' : 'disabled' ?>>
                Streamline Asset code
            </button>
        <?php else: ?>
            <p class="muted small">You have view-only access; the <code>manage</code> permission is required to run this.</p>
        <?php endif; ?>
    </form>

    <!-- ---------------- Inventory button ---------------- -->
    <form method="post" action="<?= h(url('/code_streamline.php?action=run')) ?>" class="card"
          style="padding:18px;" onsubmit="return confirm('Streamline INVENTORY codes now? This will renumber <?= $invPending ?> non-numeric item code(s) starting at <?= CS_INV_START ?>. This cannot be undone with one click.');">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="inventory">
        <h2 style="margin:0 0 4px;">2 · Streamline Inventory code</h2>
        <p class="muted small" style="margin:0 0 12px;">
            New numeric codes assigned from <code><?= CS_INV_START ?></code> upward.
        </p>
        <p style="margin:0 0 12px;">
            <span class="pill <?= $invPending ? 'pill-active' : 'pill-neutral' ?>">
                <?= $invPending ?> non-numeric inventory code<?= $invPending === 1 ? '' : 's' ?> to convert
            </span>
        </p>
        <?php if ($canManage): ?>
            <label class="inline" style="display:block; margin-bottom:8px;">
                <input type="checkbox" name="update_sequence" value="1" checked>
                Also set new items to keep counting numerically
            </label>
            <label class="inline" style="display:block; margin-bottom:14px;">
                <input type="checkbox" name="confirm" value="yes" required>
                I understand this rewrites live inventory codes.
            </label>
            <button type="submit" class="btn btn-primary" <?= $invPending ? '' : 'disabled' ?>>
                Streamline Inventory code
            </button>
        <?php else: ?>
            <p class="muted small">You have view-only access; the <code>manage</code> permission is required to run this.</p>
        <?php endif; ?>
    </form>
</div>

<!-- ---------------- Run history ---------------- -->
<h2 style="margin-top:28px;">Recent runs</h2>
<table class="data-table">
    <thead>
        <tr>
            <th>#</th>
            <th>When</th>
            <th>By</th>
            <th>Kind</th>
            <th class="r">Changed</th>
            <th>New range</th>
            <th>Seq?</th>
            <th class="r">Mapping</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($recentRuns as $r): ?>
        <tr<?= (int)$r['id'] === $lastRun ? ' style="background:var(--surface-alt,#eef5ff);"' : '' ?>>
            <td><?= (int)$r['id'] ?></td>
            <td class="nowrap"><?= h($r['run_at']) ?></td>
            <td><?= h($r['run_by_name'] ?? '—') ?></td>
            <td><?= h(ucfirst($r['kind'])) ?></td>
            <td class="r"><?= (int)$r['total_changed'] ?></td>
            <td><?php if ($r['total_changed']): ?><code><?= h($r['min_new_code']) ?></code> – <code><?= h($r['max_new_code']) ?></code><?php else: ?>—<?php endif; ?></td>
            <td><?= $r['seq_updated'] ? 'yes' : 'no' ?></td>
            <td class="r">
                <?php if ($r['total_changed']): ?>
                    <a class="btn btn-icon" title="Download CSV"
                       href="<?= h(url('/code_streamline.php?action=download&run_id=' . (int)$r['id'])) ?>">⬇ <span class="dt-action-label">CSV</span></a>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($recentRuns)): ?>
        <tr><td colspan="8" class="empty muted">No runs yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
