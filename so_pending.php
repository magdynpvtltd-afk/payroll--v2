<?php
/**
 * MagDyn — SO Pending List  (Job Order group)
 * Created: 2026-07-22 IST
 *
 * Pending sales-order quantities, computed natively from MagDyn's own
 * job_cards. A job card is "pending" while its status is NOT
 * 'billing_pending' and NOT 'closed' — i.e. it still has work to do before
 * the invoice is raised and stock ships. (Cancelled cards are terminal dead
 * work and are excluded too; flip $PENDING_STATUSES below to change that.)
 *
 * Per part (inv_item), each qualifying job card is one pending delivery:
 *   days = DATEDIFF(delivery_date, today)   negative = overdue, 0 = today, + = future
 *   qty  = COALESCE(sub_qty, po_qty)
 *
 * Why COALESCE(sub_qty, po_qty) and not po_qty: when production submits a
 * partial quantity the card advances (sub_qty = produced) but its po_qty is
 * left unchanged and the balance is carved into a CHILD card. Summing raw
 * po_qty would then double-count the balance (parent full qty + child
 * balance). sub_qty is the amount actually committed to THIS card; when no
 * split happened sub_qty equals po_qty (or is NULL pre-production, so we fall
 * back to po_qty). This makes parent + children add up to the true outstanding.
 *
 * Layout mirrors the legacy pending list: category tabs, one row per part
 * (serial · NAME(part-no-rev)[code] · total pending qty), and a strip of
 * per-delivery chips (days over/until delivery + qty).
 *
 * Visibility: gated like the rest of Job Order — any user with job_card.view
 * OR ats.view. Nav visibility inherits via $navInherit in includes/permissions.php.
 *
 * URL: /so_pending.php
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

// Same audience as Job Cards / ATS. Either functional permission is enough.
if (!permission_check('job_card', 'view') && !permission_check('ats', 'view')) {
    require_permission('job_card', 'view');   // renders the standard 403 page + exits
}

$page_module = 'so_pending';
$page_title  = 'SO Pending List';

// Pending statuses + grouped data come from the shared builder so this list
// view and the card view (/so_pending_card.php) can never drift apart.
require_once __DIR__ . '/includes/_so_pending.php';
$tabs = so_pending_tabs();

$asOf = date('d M Y, H:i');
$totalItems   = 0;
$pendingUnits = 0.0;   // in-production
$billingUnits = 0.0;   // awaiting invoice
foreach ($tabs as $t) {
    $totalItems += count($t['items']);
    foreach ($t['items'] as $it) {
        $pendingUnits += (float)$it['pending_qty'];
        $billingUnits += (float)$it['billing_qty'];
    }
}
$pendingDisp = so_pending_num($pendingUnits);
$billingDisp = so_pending_num($billingUnits);

require __DIR__ . '/includes/header.php';
?>
<style>
    .sop-wrap { padding: 16px 22px 40px; }
    .sop-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin: 4px 0 14px; }
    .sop-search { position: relative; flex: 1 1 260px; max-width: 360px; }
    .sop-search input {
        width: 100%; box-sizing: border-box; padding: 8px 12px 8px 32px;
        border: 1px solid var(--border-strong, #d0d4dc); border-radius: 6px;
        font-size: 13px; background: var(--surface, #fff); color: var(--text, #111);
    }
    .sop-search .sop-search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; }
    .sop-meta { color: var(--text-muted); font-size: 12px; display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
    .sop-legend { display: flex; gap: 12px; align-items: center; font-size: 11px; color: var(--text-muted); margin-left: auto; flex-wrap: wrap; }
    .sop-legend .lg { display: inline-flex; align-items: center; gap: 5px; }
    .sop-legend .sw { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }

    /* Category tabs (mirrors the legacy Bootstrap nav-tabs) */
    .sop-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 14px; flex-wrap: wrap; }
    .sop-tab {
        border: 1px solid transparent; border-bottom: none; background: none;
        padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
        color: var(--text-muted); border-radius: 6px 6px 0 0; margin-bottom: -1px;
    }
    .sop-tab.active { color: var(--primary, #1d4ed8); background: var(--surface); border-color: var(--border); border-bottom: 1px solid var(--surface); }

    /* Per-part card */
    .sop-item {
        display: grid; grid-template-columns: 46px minmax(220px, 1fr) auto;
        gap: 4px 14px; align-items: start;
        border: 1px solid var(--border); border-radius: 8px;
        background: var(--surface); padding: 10px 14px; margin-bottom: 8px;
    }
    .sop-serial { font-weight: 700; color: var(--text-muted); text-align: center; font-size: 13px; padding-top: 2px; }
    .sop-label { font-size: 13px; color: var(--text); font-weight: 600; line-height: 1.35; word-break: break-word; }
    .sop-total { justify-self: end; text-align: right; }
    .sop-total .n { font-size: 17px; font-weight: 700; color: var(--text); }
    .sop-total .l { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
    .sop-total .bill-sub { display: inline-block; margin-top: 3px; font-size: 10.5px; font-weight: 700;
        color: #6d28d9; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 5px; padding: 1px 6px; white-space: nowrap; }

    /* Delivery strip spans the full width beneath the header row */
    .sop-deliveries { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
    .sop-chip {
        display: inline-flex; flex-direction: column; align-items: center;
        min-width: 46px; border-radius: 6px; padding: 3px 8px; border: 1px solid var(--border);
        background: var(--surface-alt, #f7f8fa);
    }
    .sop-chip .d { font-size: 11px; font-weight: 700; line-height: 1.2; }
    .sop-chip .q { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.3; }
    .sop-chip.overdue { background: var(--danger-bg, #fef2f2); border-color: #f6b8b8; }
    .sop-chip.overdue .d { color: var(--danger, #dc2626); }
    .sop-chip.today   { background: var(--warn-bg, #fffbeb); border-color: #f5e0a3; }
    .sop-chip.today   .d { color: var(--warn, #b45309); }
    .sop-chip.future  { background: var(--success-bg, #f0fdf4); border-color: #b7e4c7; }
    .sop-chip.future  .d { color: var(--success, #16a34a); }
    /* Billing pending = produced, awaiting invoice. Violet, and it OVERRIDES
       the day-based colour so it's unmistakable from in-production chips. */
    .sop-chip.billing { background: #f5f3ff; border-color: #c4b5fd; }
    .sop-chip.billing .d { color: #6d28d9; }
    .sop-chip .bill { font-size: 8.5px; font-weight: 800; letter-spacing: .04em; color: #6d28d9; line-height: 1; margin-bottom: 1px; }

    .sop-empty { color: var(--text-muted); padding: 30px; text-align: center; font-style: italic; }
    .sop-no-match { display: none; color: var(--text-muted); padding: 24px; text-align: center; font-style: italic; }
</style>

<?= form_toolbar([
    'back_href'  => url('/job_card.php'),
    'back_label' => 'Job cards',
    'title'      => 'SO Pending List',
    'subtitle'   => 'Pending sales-order deliveries by part',
]) ?>

<div class="sop-wrap">

    <div class="sop-toolbar">
        <div class="sop-search">
            <span class="sop-search-icon" aria-hidden="true">🔍</span>
            <input type="text" id="sopFilter" placeholder="Search part name, number, or code…" autocomplete="off">
        </div>
        <div class="sop-meta">
            <span><strong><?= (int)$totalItems ?></strong> parts</span>
            <span><strong><?= h($pendingDisp) ?></strong> in production</span>
            <span style="color:#6d28d9;">🧾 <strong><?= h($billingDisp) ?></strong> billing</span>
            <span>As of <?= h($asOf) ?></span>
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/so_pending.php')) ?>" title="Recompute from the latest job cards">↻ Refresh</a>
        </div>
        <div class="sop-legend">
            <span class="lg"><span class="sw" style="background:#fef2f2;border:1px solid #f6b8b8;"></span>Overdue (−days)</span>
            <span class="lg"><span class="sw" style="background:#fffbeb;border:1px solid #f5e0a3;"></span>Due today (0)</span>
            <span class="lg"><span class="sw" style="background:#f0fdf4;border:1px solid #b7e4c7;"></span>Due in +days</span>
            <span class="lg"><span class="sw" style="background:#f5f3ff;border:1px solid #c4b5fd;"></span>🧾 Billing pending</span>
        </div>
    </div>

    <?php if (!$tabs || $totalItems === 0): ?>
        <div class="sop-empty">No pending job cards right now. Cards count as pending until they reach Billing Pending or Closed.</div>
    <?php else: ?>

        <?php $multi = count($tabs) > 1; ?>
        <?php if ($multi): ?>
            <div class="sop-tabs" role="tablist">
                <?php foreach ($tabs as $i => $t): ?>
                    <button type="button" class="sop-tab<?= $i === 0 ? ' active' : '' ?>"
                            data-pane="sop-pane-<?= h($t['id']) ?>"><?= h($t['name']) ?>
                        <span style="opacity:.6;">(<?= count($t['items']) ?>)</span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($tabs as $i => $t): ?>
            <div class="sop-pane" id="sop-pane-<?= h($t['id']) ?>" <?= ($multi && $i !== 0) ? 'hidden' : '' ?>>
                <?php foreach ($t['items'] as $it):
                    $search = strtolower($it['label'] . ' ' . $it['serial']);
                ?>
                    <div class="sop-item" data-search="<?= h($search) ?>">
                        <div class="sop-serial"><?= h($it['serial']) ?></div>
                        <div class="sop-label"><?= h($it['label']) ?></div>
                        <div class="sop-total">
                            <span class="n"><?= h($it['total']) ?></span><span class="l">Qty</span>
                            <?php if ((float)$it['billing_qty'] > 0): ?>
                                <span class="bill-sub" title="Of the total, this much is produced and awaiting invoice">🧾 <?= h($it['billing_total']) ?> billing</span>
                            <?php endif; ?>
                        </div>
                        <div class="sop-deliveries">
                            <?php foreach ($it['deliveries'] as $d):
                                $dv = $d['days'];                       // int|null
                                $daysRaw = ($dv === null) ? '' : (string)$dv;
                                if (!empty($d['billing'])) {
                                    // Billing bucket: violet, overrides day-based colour.
                                    $cls = 'billing';
                                    $tip = 'Billing pending — produced, awaiting invoice';
                                    if ($dv !== null) $tip .= ' · delivery ' . ($dv < 0 ? abs($dv) . 'd overdue' : ($dv == 0 ? 'today' : 'in ' . $dv . 'd'));
                                } else {
                                    $cls = 'future';
                                    if ($dv === null)      $cls = '';
                                    elseif ($dv < 0)       $cls = 'overdue';
                                    elseif ($dv == 0)      $cls = 'today';
                                    if ($dv === null)      $tip = 'No delivery date';
                                    elseif ($dv < 0)       $tip = abs($dv) . ' day(s) overdue';
                                    elseif ($dv == 0)      $tip = 'Due today';
                                    else                   $tip = 'Due in ' . $dv . ' day(s)';
                                }
                            ?>
                                <span class="sop-chip <?= $cls ?>" title="<?= h($tip) ?>">
                                    <?php if (!empty($d['billing'])): ?><span class="bill">BILL</span><?php endif; ?>
                                    <span class="d"><?= h($daysRaw !== '' ? $daysRaw : '—') ?></span>
                                    <span class="q"><?= h($d['qty'] !== '' ? $d['qty'] : '—') ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="sop-no-match">No parts match your search.</div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('sopFilter');
    var panes = Array.prototype.slice.call(document.querySelectorAll('.sop-pane'));
    var tabs  = Array.prototype.slice.call(document.querySelectorAll('.sop-tab'));

    // ---- Category tab switching ----
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabs.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var target = btn.getAttribute('data-pane');
            panes.forEach(function (p) { p.hidden = (p.id !== target); });
        });
    });

    // ---- Live filter across every part in every pane ----
    function applyFilter() {
        var q = (input.value || '').toLowerCase().trim();
        panes.forEach(function (pane) {
            var items = pane.querySelectorAll('.sop-item');
            var shown = 0;
            items.forEach(function (it) {
                var hit = q === '' || (it.getAttribute('data-search') || '').indexOf(q) > -1;
                it.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            var nomatch = pane.querySelector('.sop-no-match');
            if (nomatch) nomatch.style.display = (shown === 0 && q !== '') ? 'block' : 'none';
        });
    }
    if (input) input.addEventListener('input', applyFilter);
}());
</script>

<?php
require __DIR__ . '/includes/footer.php';
