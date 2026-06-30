<?php
/**
 * MagDyn — Admin ▸ Old Inventory Import
 * Created: 20260616_130000_IST
 *
 * Central admin hub that gathers every "Import from Old Inventory" flow (and
 * its matching delete / reset controls) into one place under the Admin tab.
 *
 * Each card links to the existing per-module import page, where the Import
 * buttons and the Delete / Reset buttons already live together — this page is
 * purely a launcher, it does not duplicate those flows.
 *
 * Visibility: registered as the `old_inventory_admin` module and granted only
 * to the admin role (see sql/migration_20260616_130000_IST.sql), so it appears
 * under Admin for administrators only. The scattered per-page import buttons
 * are likewise gated by is_admin() and now point users here.
 *
 * Permissions: old_inventory_admin.view
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('old_inventory_admin', 'view');

$page_title  = 'Old Inventory Import';
$page_module = 'old_inventory_admin';

/**
 * Import flows. Each entry: [icon, title, href, what-it-covers, reset-note].
 */
$cards = [
    [
        'icon'  => '📦',
        'title' => 'Assets, Vendors &amp; Users',
        'href'  => url('/old_inventory_import.php'),
        'desc'  => 'Models, assets and transaction history, plus vendor companies '
                 . '(contacts / addresses) and user accounts.',
        'reset' => 'Delete All Asset Records · Delete All Vendors · Delete All Users',
    ],
    [
        'icon'  => '🧬',
        'title' => 'BOM / Inventory',
        'href'  => url('/bom_old_import.php'),
        'desc'  => 'Auto-fetch every BOM tree from <code>tree7-5.php</code> on the old '
                 . 'server and import items, edges and transactions.',
        'reset' => 'Delete All Inventory Records',
    ],
    [
        'icon'  => '🧾',
        'title' => 'Invoices',
        'href'  => url('/invoice.php?action=import'),
        'desc'  => 'Migrate <code>approveinv</code> (pending) and <code>recp_inv</code> '
                 . '(approved) purchase invoices with their line items.',
        'reset' => 'Delete All Invoices',
    ],
    [
        'icon'  => '📐',
        'title' => 'Inspection Templates',
        'href'  => url('/inspection.php?action=import_old_templates'),
        'desc'  => 'Import the legacy <code>inspection</code> table as reusable '
                 . 'inspection templates.',
        'reset' => 'Delete Imported Templates · Delete Visual-Only Templates · Delete ALL Templates',
    ],
    [
        'icon'  => '✅',
        'title' => 'Inspection Records',
        'href'  => url('/inspection.php?action=import_old_inspections'),
        'desc'  => 'Import completed inspection records and their results from the '
                 . 'old system.',
        'reset' => 'Delete Imported Inspections · Delete ALL Inspections',
    ],
    [
        'icon'  => '📝',
        'title' => 'Running Notes',
        'href'  => url('/running_notes.php?action=import'),
        'desc'  => 'Import <code>inv_notes</code> and their attachment metadata, then '
                 . 'download the physical attachment files.',
        'reset' => 'Delete All Running Notes',
    ],
];

/** Related read-only / post-import tools. */
$related = [
    [
        'title' => 'Imported Data Viewer',
        'href'  => url('/old_inv_data.php'),
        'desc'  => 'Browse the raw data pulled in from the old inventory system.',
    ],
    [
        'title' => 'Creator Backfill',
        'href'  => url('/admin_user_backfill.php'),
        'desc'  => 'Stamp the original created-by user onto already-imported records.',
    ],
];

require __DIR__ . '/includes/header.php';
?>
<div class="form-page">
    <?= form_toolbar([
        'title'    => 'Old Inventory Import',
        'subtitle' => 'Admin-only hub for migrating data from the legacy inventory_live system (192.168.1.249). '
                    . 'Each card opens a page where the Import and Delete / Reset controls live together.',
    ]) ?>

    <div class="form-page-body" style="max-width:980px;">

        <div class="alert alert-info" style="margin-bottom:24px;">
            ⚠ These flows write to and can wipe live data. Run a delete / reset only when you
            intend a clean re-import.
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
            <?php foreach ($cards as $c): ?>
            <div style="border:1px solid var(--border);border-radius:10px;padding:18px 20px;
                        background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.05);display:flex;
                        flex-direction:column;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:24px;line-height:1;"><?= $c['icon'] ?></span>
                    <h3 style="margin:0;font-size:16px;"><?= $c['title'] ?></h3>
                </div>
                <p class="muted small" style="margin:0;flex:1;"><?= $c['desc'] ?></p>
                <div class="small" style="color:#7f1d1d;">
                    <strong>Reset:</strong> <?= h($c['reset']) ?>
                </div>
                <div style="margin-top:4px;">
                    <a class="btn btn-primary btn-sm" href="<?= h($c['href']) ?>">Open import &amp; reset →</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 style="margin:32px 0 10px;">Related tools</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
            <?php foreach ($related as $r): ?>
            <div style="border:1px solid var(--border);border-radius:10px;padding:16px 18px;
                        background:#fafafa;display:flex;flex-direction:column;gap:8px;">
                <h3 style="margin:0;font-size:15px;"><?= h($r['title']) ?></h3>
                <p class="muted small" style="margin:0;flex:1;"><?= h($r['desc']) ?></p>
                <div><a class="btn btn-ghost btn-sm" href="<?= h($r['href']) ?>">Open →</a></div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
