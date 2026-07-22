<?php
/**
 * MagDyn — SO Pending Card  (Job Order group)
 * Created: 2026-07-22 IST
 *
 * The card-view counterpart to /so_pending.php. Same pending data (from the
 * shared builder in includes/_so_pending.php — job cards not yet Billing
 * Pending / Closed), but laid out as a masonry of Bootstrap-style cards, one
 * per part, echoing the legacy Custom/pendinglist.php:
 *
 *   ┌────────────────────────────────────────┐
 *   │ NAME(part-no-rev)              [ total ]│  ← card header + qty badge
 *   ├────────────────────────────────────────┤
 *   │ 18-Apr-26 - 4500996376 - 10 - GER (642) │  ← one line per pending delivery
 *   │ …                                       │     (red when overdue)
 *   └────────────────────────────────────────┘
 *
 * Each delivery line is: delivery-date - PO# - line# - location (qty).
 * Plus the same category tabs + search filter as the list view.
 *
 * Visibility: gated like the rest of Job Order — any user with job_card.view
 * OR ats.view.
 *
 * URL: /so_pending_card.php
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if (!permission_check('job_card', 'view') && !permission_check('ats', 'view')) {
    require_permission('job_card', 'view');   // renders the standard 403 page + exits
}

$page_module = 'so_pending_card';
$page_title  = 'SO Pending Card';

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

/** Format one delivery as the legacy "date - PO - line - location (qty)" line. */
function sopc_line(array $d)
{
    $date = $d['date'] ? date('d-M-y', strtotime($d['date'])) : 'No date';
    $parts = array_filter([
        $date,
        trim((string)$d['po_no']),
        trim((string)$d['line_no']),
        trim((string)$d['location']),
    ], function ($v) { return $v !== ''; });
    return implode(' - ', $parts) . ' (' . $d['qty'] . ')';
}

require __DIR__ . '/includes/header.php';
?>
<style>
    .sopc-wrap { padding: 16px 22px 40px; }
    .sopc-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin: 4px 0 14px; }
    .sopc-search { position: relative; flex: 1 1 260px; max-width: 360px; }
    .sopc-search input {
        width: 100%; box-sizing: border-box; padding: 8px 12px 8px 32px;
        border: 1px solid var(--border-strong, #d0d4dc); border-radius: 6px;
        font-size: 13px; background: var(--surface, #fff); color: var(--text, #111);
    }
    .sopc-search .sopc-search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; }
    .sopc-meta { color: var(--text-muted); font-size: 12px; display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
    .sopc-legend { display: flex; gap: 12px; align-items: center; font-size: 11px; color: var(--text-muted); margin-left: auto; flex-wrap: wrap; }
    .sopc-legend .lg { display: inline-flex; align-items: center; gap: 5px; }
    .sopc-legend .sw { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }

    /* Category tabs */
    .sopc-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 14px; flex-wrap: wrap; }
    .sopc-tab {
        border: 1px solid transparent; border-bottom: none; background: none;
        padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
        color: var(--text-muted); border-radius: 6px 6px 0 0; margin-bottom: -1px;
    }
    .sopc-tab.active { color: var(--primary, #1d4ed8); background: var(--surface); border-color: var(--border); border-bottom: 1px solid var(--surface); }

    /* Masonry of cards (CSS columns, like Bootstrap .card-columns) */
    .sopc-cards { column-gap: 12px; column-count: 1; }
    @media (min-width: 760px)  { .sopc-cards { column-count: 2; } }
    @media (min-width: 1140px) { .sopc-cards { column-count: 3; } }
    @media (min-width: 1560px) { .sopc-cards { column-count: 4; } }

    .sopc-card {
        break-inside: avoid; -webkit-column-break-inside: avoid; page-break-inside: avoid;
        display: inline-block; width: 100%;
        border: 1px solid var(--border); border-radius: 8px; overflow: hidden;
        background: var(--surface); margin-bottom: 12px;
    }
    .sopc-card-head {
        display: flex; gap: 8px; align-items: flex-start; justify-content: space-between;
        padding: 8px 12px; background: var(--surface-alt, #f7f8fa);
        border-bottom: 1px solid var(--border);
    }
    .sopc-card-name { font-size: 12.5px; font-weight: 700; color: var(--text); line-height: 1.3; word-break: break-word; }
    .sopc-card-totals { flex-shrink: 0; display: flex; flex-direction: column; gap: 3px; align-items: flex-end; }
    .sopc-card-total {
        font-size: 12px; font-weight: 700; color: #fff;
        background: var(--primary, #1d4ed8); border-radius: 5px; padding: 2px 9px; line-height: 1.5; white-space: nowrap;
    }
    .sopc-card-billing {
        font-size: 11px; font-weight: 700; color: #6d28d9;
        background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 5px; padding: 1px 7px; line-height: 1.5; white-space: nowrap;
    }
    .sopc-card-body { padding: 8px 12px; font-size: 12px; line-height: 1.55; }
    .sopc-line { color: var(--text); }
    .sopc-line.overdue { color: var(--danger, #dc2626); }
    /* Billing pending line: violet, overrides overdue red, with a tag. */
    .sopc-line.billing { color: #6d28d9; }
    .sopc-bill-tag {
        display: inline-block; font-size: 9px; font-weight: 800; letter-spacing: .04em;
        color: #6d28d9; background: #f5f3ff; border: 1px solid #ddd6fe;
        border-radius: 4px; padding: 0 4px; margin-right: 5px; vertical-align: 1px;
    }

    .sopc-empty { color: var(--text-muted); padding: 30px; text-align: center; font-style: italic; }
    .sopc-no-match { display: none; color: var(--text-muted); padding: 24px; text-align: center; font-style: italic; }
</style>

<?= form_toolbar([
    'back_href'  => url('/job_card.php'),
    'back_label' => 'Job cards',
    'title'      => 'SO Pending Card',
    'subtitle'   => 'Pending sales-order deliveries by part — card view',
]) ?>

<div class="sopc-wrap">

    <div class="sopc-toolbar">
        <div class="sopc-search">
            <span class="sopc-search-icon" aria-hidden="true">🔍</span>
            <input type="text" id="sopcFilter" placeholder="Search part name, number, PO, or code…" autocomplete="off">
        </div>
        <div class="sopc-meta">
            <span><strong><?= (int)$totalItems ?></strong> parts</span>
            <span><strong><?= h($pendingDisp) ?></strong> in production</span>
            <span style="color:#6d28d9;">🧾 <strong><?= h($billingDisp) ?></strong> billing</span>
            <span>As of <?= h($asOf) ?></span>
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/so_pending_card.php')) ?>" title="Recompute from the latest job cards">↻ Refresh</a>
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/so_pending.php')) ?>" title="Switch to the list view">☰ List view</a>
        </div>
        <div class="sopc-legend">
            <span class="lg"><span class="sw" style="background:var(--danger,#dc2626);"></span>Overdue delivery</span>
            <span class="lg"><span class="sw" style="background:var(--text,#111);"></span>Upcoming</span>
            <span class="lg"><span class="sw" style="background:#f5f3ff;border:1px solid #c4b5fd;"></span>🧾 Billing pending</span>
        </div>
    </div>

    <?php if (!$tabs || $totalItems === 0): ?>
        <div class="sopc-empty">No pending job cards right now. Cards count as pending until they reach Billing Pending or Closed.</div>
    <?php else: ?>

        <?php $multi = count($tabs) > 1; ?>
        <?php if ($multi): ?>
            <div class="sopc-tabs" role="tablist">
                <?php foreach ($tabs as $i => $t): ?>
                    <button type="button" class="sopc-tab<?= $i === 0 ? ' active' : '' ?>"
                            data-pane="sopc-pane-<?= h($t['id']) ?>"><?= h($t['name']) ?>
                        <span style="opacity:.6;">(<?= count($t['items']) ?>)</span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($tabs as $i => $t): ?>
            <div class="sopc-pane" id="sopc-pane-<?= h($t['id']) ?>" <?= ($multi && $i !== 0) ? 'hidden' : '' ?>>
                <div class="sopc-cards">
                    <?php foreach ($t['items'] as $it):
                        // Search haystack: name, part, code + every delivery's PO/location.
                        $hay = $it['label'];
                        foreach ($it['deliveries'] as $d) $hay .= ' ' . $d['po_no'] . ' ' . $d['location'];
                        $search = strtolower($hay);
                        $headName = $it['name'] . ($it['part'] !== '' ? '(' . $it['part'] . ')' : '');
                    ?>
                        <div class="sopc-card" data-search="<?= h($search) ?>">
                            <div class="sopc-card-head">
                                <span class="sopc-card-name" title="<?= h($it['label']) ?>"><?= h($headName) ?></span>
                                <span class="sopc-card-totals">
                                    <span class="sopc-card-total" title="Total qty (in production + billing)"><?= h($it['total']) ?></span>
                                    <?php if ((float)$it['billing_qty'] > 0): ?>
                                        <span class="sopc-card-billing" title="Produced, awaiting invoice">🧾 <?= h($it['billing_total']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="sopc-card-body">
                                <?php foreach ($it['deliveries'] as $d):
                                    $billing = !empty($d['billing']);
                                    $overdue = (!$billing && $d['days'] !== null && $d['days'] < 0);
                                    $lineCls = $billing ? ' billing' : ($overdue ? ' overdue' : '');
                                ?>
                                    <div class="sopc-line<?= $lineCls ?>"<?= $billing ? ' title="Billing pending — produced, awaiting invoice"' : '' ?>>
                                        <?php if ($billing): ?><span class="sopc-bill-tag">BILL</span><?php endif; ?><?= h(sopc_line($d)) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="sopc-no-match">No parts match your search.</div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('sopcFilter');
    var panes = Array.prototype.slice.call(document.querySelectorAll('.sopc-pane'));
    var tabs  = Array.prototype.slice.call(document.querySelectorAll('.sopc-tab'));

    // ---- Category tab switching ----
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabs.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var target = btn.getAttribute('data-pane');
            panes.forEach(function (p) { p.hidden = (p.id !== target); });
        });
    });

    // ---- Live filter across every card in every pane ----
    function applyFilter() {
        var q = (input.value || '').toLowerCase().trim();
        panes.forEach(function (pane) {
            var cards = pane.querySelectorAll('.sopc-card');
            var shown = 0;
            cards.forEach(function (c) {
                var hit = q === '' || (c.getAttribute('data-search') || '').indexOf(q) > -1;
                c.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            var nomatch = pane.querySelector('.sopc-no-match');
            if (nomatch) nomatch.style.display = (shown === 0 && q !== '') ? 'block' : 'none';
        });
    }
    if (input) input.addEventListener('input', applyFilter);
}());
</script>

<?php
require __DIR__ . '/includes/footer.php';
