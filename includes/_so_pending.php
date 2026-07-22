<?php
/**
 * MagDyn — shared data builder for the SO Pending pages.
 * Created: 2026-07-22 IST
 *
 * Both /so_pending.php (list view) and /so_pending_card.php (card view) render
 * the SAME pending sales-order data from job_cards; only the presentation
 * differs. This file is the single source of truth for "what is pending" and
 * how it is grouped, so the two pages can never drift apart.
 *
 * TWO buckets are surfaced, per delivery:
 *   - IN-PRODUCTION pending: status IN so_pending_statuses()
 *       (qc_pending / prod_pending / ats_pending) — still being made.
 *   - BILLING pending: status = 'billing_pending' — produced, stock moved to
 *       SHP, only awaiting the invoice. Carried as a distinct bucket so the
 *       views can show it differentiated (flagged 'billing' => true on each
 *       delivery, and summed separately into billing_total).
 *   closed / cancelled are never shown.
 *
 * Per-card qty is COALESCE(sub_qty, po_qty): after a partial-production split
 * the parent keeps its full po_qty and the balance moves to a child card, so
 * summing raw po_qty double-counts. sub_qty is the amount committed to THIS
 * card (falls back to po_qty pre-production), so parent + children add up to
 * the true outstanding.
 */

/** In-production pending statuses (NOT billing). Edit here to change both pages. */
function so_pending_statuses()
{
    return ['qc_pending', 'prod_pending', 'ats_pending'];
}

/** Format a qty for display: drop trailing fractional zeros ("30.000" -> "30"). */
function so_pending_num($v)
{
    if ($v === null || $v === '') return '0';
    $s = (string)(0 + $v);
    if (strpos($s, '.') === false) return $s;
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/**
 * Build the grouped pending structure both views consume:
 *
 *   [ ['id'=>paneId, 'name'=>category, 'items'=>[
 *        ['serial','name','part','code','label',
 *         'total','pending_total','billing_total',   // display strings
 *         'billing_qty' (float), 'min_days',
 *         'deliveries'=>[
 *            ['days'=>int|null, 'date'=>'YYYY-MM-DD'|null,
 *             'po_no','line_no','location','qty'=>string,
 *             'billing'=>bool], ...
 *         ]], ...
 *   ]], ... ]
 *
 * Categories ordered by (sort_order, name); parts within a category by
 * earliest/most-overdue delivery first; deliveries within a part by date.
 */
function so_pending_tabs()
{
    $pending   = so_pending_statuses();
    $wanted    = array_merge($pending, ['billing_pending']);   // + billing bucket
    $pendingBK = array_flip($pending);                          // fast lookup

    $in = implode(',', array_fill(0, count($wanted), '?'));
    $rows = db_all(
        "SELECT jc.id,
                jc.item_id,
                jc.status,
                COALESCE(jc.sub_qty, jc.po_qty)                  AS pending_qty,
                jc.delivery_date,
                DATEDIFF(jc.delivery_date, CURDATE())            AS days_offset,
                jc.po_no,
                jc.line_no,
                COALESCE(NULLIF(jc.location,''), jc.supplier_name) AS location,
                i.code                                           AS item_code,
                COALESCE(NULLIF(i.short_description,''), i.name)  AS item_name,
                i.part_no,
                i.part_rev_no,
                COALESCE(c.name, 'Uncategorized')                AS category_name,
                c.sort_order                                     AS cat_sort
           FROM job_cards jc
           JOIN inv_items i  ON i.id = jc.item_id
      LEFT JOIN categories c ON c.id = i.category_id
          WHERE jc.status IN ($in)
       ORDER BY (c.sort_order IS NULL), c.sort_order, category_name,
                jc.item_id,
                (jc.delivery_date IS NULL), jc.delivery_date, jc.id",
        $wanted
    );

    // ---- Group: category -> item -> deliveries ----
    $catMap = [];
    foreach ($rows as $r) {
        $cat = $r['category_name'];
        if (!isset($catMap[$cat])) {
            $catMap[$cat] = [
                'name'  => $cat,
                'sort'  => ($r['cat_sort'] === null ? 9999 : (int)$r['cat_sort']),
                'items' => [],
            ];
        }
        $iid = (int)$r['item_id'];
        if (!isset($catMap[$cat]['items'][$iid])) {
            $partRev = trim((string)$r['part_no']);
            if ($r['part_rev_no'] !== null && $r['part_rev_no'] !== '') {
                $partRev = trim($partRev . '-' . $r['part_rev_no']);
            }
            $label = (string)$r['item_name'];
            if ($partRev !== '') $label .= '(' . $partRev . ')';
            $label .= '[' . $r['item_code'] . ']';

            $catMap[$cat]['items'][$iid] = [
                'name'          => (string)$r['item_name'],
                'part'          => $partRev,
                'code'          => (string)$r['item_code'],
                'label'         => $label,
                'total_qty'     => 0.0,   // grand: in-production + billing
                'pending_qty'   => 0.0,   // in-production only
                'billing_qty'   => 0.0,   // billing only
                'min_days'      => PHP_INT_MAX,
                'deliveries'    => [],
            ];
        }

        $isBilling = !isset($pendingBK[$r['status']]);   // true only for billing_pending
        $days = $r['days_offset'];                        // NULL when delivery_date is NULL
        $qtyF = (float)$r['pending_qty'];

        $catMap[$cat]['items'][$iid]['deliveries'][] = [
            'days'     => ($days === null ? null : (int)$days),
            'date'     => $r['delivery_date'],
            'po_no'    => (string)$r['po_no'],
            'line_no'  => (string)($r['line_no'] ?? ''),
            'location' => (string)($r['location'] ?? ''),
            'qty'      => so_pending_num($r['pending_qty']),
            'billing'  => $isBilling,
        ];
        $catMap[$cat]['items'][$iid]['total_qty'] += $qtyF;
        if ($isBilling) $catMap[$cat]['items'][$iid]['billing_qty'] += $qtyF;
        else            $catMap[$cat]['items'][$iid]['pending_qty'] += $qtyF;
        if ($days !== null && (int)$days < $catMap[$cat]['items'][$iid]['min_days']) {
            $catMap[$cat]['items'][$iid]['min_days'] = (int)$days;
        }
    }

    // ---- Flatten into ordered tabs ----
    uasort($catMap, function ($a, $b) {
        return [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']];
    });

    $tabs = [];
    foreach ($catMap as $cat) {
        $items = array_values($cat['items']);
        usort($items, function ($a, $b) {
            return $a['min_days'] <=> $b['min_days'];
        });
        $serial = 0;
        foreach ($items as &$it) {
            $serial++;
            $it['serial']        = (string)$serial;
            $it['billing_qty_f'] = $it['billing_qty'];               // raw float for tests
            $it['total']         = so_pending_num($it['total_qty']);
            $it['pending_total'] = so_pending_num($it['pending_qty']);
            $it['billing_total'] = so_pending_num($it['billing_qty']);
        }
        unset($it);

        $paneId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $cat['name']);
        if ($paneId === '' || $paneId === '-') $paneId = 'cat-' . count($tabs);
        $tabs[] = ['id' => $paneId, 'name' => $cat['name'], 'items' => $items];
    }

    return $tabs;
}
