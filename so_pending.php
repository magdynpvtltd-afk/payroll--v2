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

    /* ---- Legacy-sheet pending table ----
       One <tbody> per part, holding TWO rows: a "Days" row and a "Qty" row.
       The first three columns (serial · name · total) rowspan both rows; the
       delivery cells to their right are variable in count and simply leave
       white space to the right when a part has fewer deliveries (the table is
       content-width, not stretched). Zebra striping is per part = per tbody. */
    .sop-tablewrap { overflow-x: auto; }
    .sop-table { border-collapse: collapse; font-size: 12px; }
    .sop-table td { border: 1px solid var(--border-strong, #b9bec7); padding: 3px 8px; }

    .sop-item { background: var(--surface, #fff); }
    .sop-item:nth-of-type(even) { background: var(--surface-alt, #eef0f3); }

    .sop-serial { width: 34px; text-align: center; font-weight: 700; color: var(--text); vertical-align: middle; }
    .sop-name   { width: 360px; max-width: 360px; font-weight: 600; color: var(--primary, #16447a);
                  line-height: 1.3; word-break: break-word; vertical-align: middle; }
    .sop-total  { width: 46px; text-align: center; vertical-align: middle; }
    .sop-total .n { font-weight: 700; font-size: 14px; color: var(--text); }
    .sop-total .bill-sub { display: block; margin-top: 2px; font-size: 9px; font-weight: 700; color: #6d28d9; white-space: nowrap; }

    /* Row-label cells ("Days" / "Qty") — light blue, like the legacy sheet. */
    .sop-rl { background: #dbe6f3; font-weight: 700; text-align: left; white-space: nowrap; width: 40px; color: #1f3350; }

    /* Delivery value cells */
    .sop-day { text-align: center; font-weight: 700; min-width: 34px; }
    .sop-qty { text-align: center; color: var(--text); min-width: 34px; }

    /* Existing colour-differentiating logic, applied to the Days number:
       overdue = red, due-today = amber, future = green, no-date = muted,
       and billing pending (produced, awaiting invoice) = violet + violet tint. */
    .sop-day.overdue { color: var(--danger, #cc0000); }
    .sop-day.today   { color: var(--warn, #b45309); }
    .sop-day.future  { color: var(--success, #16a34a); }
    .sop-day.nodate  { color: var(--text-muted, #888); }
    .sop-day.billing, .sop-qty.billing { background: #f5f3ff; }
    .sop-day.billing { color: #6d28d9; }

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
              <div class="sop-tablewrap">
                <table class="sop-table">
                <?php foreach ($t['items'] as $it):
                    $search = strtolower($it['label'] . ' ' . $it['serial']);
                    $delivs = $it['deliveries'];
                ?>
                    <tbody class="sop-item" data-search="<?= h($search) ?>">
                        <tr>
                            <td rowspan="2" class="sop-serial"><?= h($it['serial']) ?></td>
                            <td rowspan="2" class="sop-name"><?= h($it['label']) ?></td>
                            <td rowspan="2" class="sop-total">
                                <span class="n"><?= h($it['total']) ?></span>
                                <?php if ((float)$it['billing_qty'] > 0): ?>
                                    <span class="bill-sub" title="Of the total, this much is produced and awaiting invoice">🧾 <?= h($it['billing_total']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="sop-rl">Days</td>
                            <?php foreach ($delivs as $d):
                                $dv = $d['days'];                       // int|null
                                $daysRaw = ($dv === null) ? '' : (string)$dv;
                                if (!empty($d['billing'])) {
                                    // Billing bucket: violet, overrides the day-based colour.
                                    $cls = 'billing';
                                    $tip = 'Billing pending — produced, awaiting invoice';
                                    if ($dv !== null) $tip .= ' · delivery ' . ($dv < 0 ? abs($dv) . 'd overdue' : ($dv == 0 ? 'today' : 'in ' . $dv . 'd'));
                                } else {
                                    if ($dv === null)      { $cls = 'nodate';  $tip = 'No delivery date'; }
                                    elseif ($dv < 0)       { $cls = 'overdue'; $tip = abs($dv) . ' day(s) overdue'; }
                                    elseif ($dv == 0)      { $cls = 'today';   $tip = 'Due today'; }
                                    else                   { $cls = 'future';  $tip = 'Due in ' . $dv . ' day(s)'; }
                                }
                            ?>
                                <td class="sop-day <?= $cls ?>" title="<?= h($tip) ?>"><?= h($daysRaw !== '' ? $daysRaw : '—') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="sop-rl">Qty</td>
                            <?php foreach ($delivs as $d): ?>
                                <td class="sop-qty<?= !empty($d['billing']) ? ' billing' : '' ?>"><?= h($d['qty'] !== '' ? $d['qty'] : '—') ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
                </table>
              </div>
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
