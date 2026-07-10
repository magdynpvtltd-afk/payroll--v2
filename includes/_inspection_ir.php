<?php
/**
 * MagDyn — Inspection IR helpers
 *
 * Utility functions used by inspection.php for the printed IR
 * (multi-sample, snapshot-style) workflow. Pulled out into its own
 * file so inspection.php stays focused on action dispatching.
 *
 * Conventions:
 *   - measured_value stays VARCHAR — accepts numeric readings AND text
 *     indicators ("OK", "NOT OK[NG]", "Values Found but Job Nos
 *     Mismatched") that the printed IR carries.
 *   - sample_no is 1-indexed; NULL means "legacy single-sample row"
 *     (won't appear on multi-sample IRs but tolerated for old data).
 *   - "Snapshot" fields (part_no/rev/desc/pid on inspections,
 *     instrument_asset_id on inspection_results) are copied AT
 *     creation time and never re-read. Live links (job_card_id) ARE
 *     re-read on every view so PO corrections flow.
 */


/**
 * Snapshot part identity from inv_items into [part_no, part_rev,
 * part_description, pid] suitable for INSERT into `inspections`.
 *
 * Falls back to nulls when the item doesn't exist (deleted between
 * picker click and save, etc.) — caller can still allow free-form
 * overrides on top of the snapshot.
 *
 * Column map (confirmed):
 *   inv_items.part_no          → inspections.part_no
 *   inv_items.part_rev_no      → inspections.part_rev
 *   inv_items.long_description → inspections.part_description
 *   inv_items.code             → inspections.pid
 */
function ir_snapshot_part_from_inv_item($invItemId)
{
    $row = db_one(
        'SELECT part_no, part_rev_no, long_description, code
           FROM inv_items WHERE id = ?',
        [(int)$invItemId]
    );
    if (!$row) {
        return ['part_no' => null, 'part_rev' => null, 'part_description' => null, 'pid' => null];
    }
    return [
        'part_no'          => $row['part_no']          ?: null,
        'part_rev'         => $row['part_rev_no']      ?: null,
        'part_description' => $row['long_description'] ?: null,
        'pid'              => $row['code']             ?: null,
    ];
}


/**
 * Live read of job_card fields used in the IR header (PO no, PO line,
 * PDN qty). Never snapshotted — if Production fixes a wrong PO, the
 * correction flows through to every linked IR.
 *
 * Returns ['po_no' => …, 'line_no' => …, 'pdn_qty' => …] or NULL if
 * the job card doesn't exist or the column is missing.
 *
 * Column map (confirmed):
 *   job_cards.po_no, job_cards.line_no, job_cards.pdn_qty
 */
function ir_job_card_header($jobCardId)
{
    if (!$jobCardId) return null;
    return db_one(
        'SELECT id, po_no, line_no, pdn_qty
           FROM job_cards WHERE id = ?',
        [(int)$jobCardId]
    );
}


/**
 * Job-card picker query. Used by the entity-picker AJAX endpoint and
 * by the IR new/edit form.
 *
 * Display label format (confirmed): code + po_no + line_no + part_no
 * — assembled in the picker UI rather than concatenated in SQL so the
 * front-end can style each segment if it wants.
 *
 * `$q` is a fuzzy term matched against code / po_no / part_no.
 */
function ir_job_card_picker($q = '', $limit = 25)
{
    $q = trim((string)$q);
    $where  = '1=1';
    $params = [];
    if ($q !== '') {
        $where  .= ' AND (jc.code LIKE ? OR jc.po_no LIKE ? OR jc.part_no LIKE ?)';
        $like    = '%' . $q . '%';
        $params  = [$like, $like, $like];
    }
    $sql = "
        SELECT jc.id, jc.code, jc.po_no, jc.line_no, jc.part_no,
               jc.pdn_qty
          FROM job_cards jc
         WHERE $where
         ORDER BY jc.id DESC
         LIMIT " . (int)$limit;
    return db_all($sql, $params);
}


/**
 * Active assets, suitable for the instrument picker. No category
 * filter — answered as "just is_active=1 against all assets". The
 * picker label is "code — name".
 */
function ir_instrument_picker($q = '', $limit = 50)
{
    $q = trim((string)$q);
    $where  = 'is_active = 1';
    $params = [];
    if ($q !== '') {
        $where  .= ' AND (code LIKE ? OR name LIKE ?)';
        $like    = '%' . $q . '%';
        $params  = [$like, $like];
    }
    return db_all(
        "SELECT id, code, name FROM assets WHERE $where ORDER BY code LIMIT " . (int)$limit,
        $params
    );
}


/**
 * Per-sample remarks codec. The remarks live in
 * inspections.sample_remarks_json as a sparse map:
 *   { "1": "Accepted", "5": "Rejected", "12": "Hold" }
 * NULL/empty means "all default" — the view layer fills in "Accepted"
 * (or whatever default) for any sample_no not in the map.
 *
 * Decode tolerates malformed JSON by returning an empty array (so a
 * corrupted column doesn't break the page).
 */
function ir_remarks_decode($json)
{
    if ($json === null || $json === '') return [];
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $k => $v) {
        $kInt = (int)$k;
        if ($kInt < 1) continue;
        $out[$kInt] = (string)$v;
    }
    return $out;
}

/**
 * Encode a remarks map for storage. Filters out empty values (so the
 * JSON stays compact and "all default = empty map = NULL in db").
 */
function ir_remarks_encode(array $map)
{
    $out = [];
    foreach ($map as $k => $v) {
        $kInt = (int)$k;
        $vStr = trim((string)$v);
        if ($kInt < 1) continue;
        if ($vStr === '') continue;
        $out[(string)$kInt] = $vStr;
    }
    if (!$out) return null;
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}


/**
 * Seed `inspection_results` from a template, expanding by sample_count.
 *
 * Replaces the legacy seed loop (one row per template_item) with
 * N rows per template_item — one per sample. Each row carries:
 *   - The same snapshot of label/bubble_no/gdt_symbol/check_type/
 *     target_value/tolerance_lower/tolerance_upper/unit as before
 *   - The new sample_no (1..N)
 *   - The new instrument_asset_id snapshotted from the template item
 *
 * Wipes existing inspection_results for the given inspection_id
 * before seeding (so re-seeding after a sample_count change is safe).
 * Called in a transaction by the caller — this fn does not start one.
 */
function ir_seed_results_with_samples($inspectionId, $templateId, $sampleCount)
{
    $inspectionId = (int)$inspectionId;
    $templateId   = (int)$templateId;
    $sampleCount  = max(1, (int)$sampleCount);

    db_exec('DELETE FROM inspection_results WHERE inspection_id = ?', [$inspectionId]);
    if (!$templateId) return;

    $items = db_all(
        'SELECT * FROM inspection_template_items
          WHERE template_id = ? ORDER BY sort_order, id',
        [$templateId]
    );
    foreach ($items as $it) {
        for ($s = 1; $s <= $sampleCount; $s++) {
            db_exec(
                'INSERT INTO inspection_results
                   (inspection_id, sample_no, template_item_id, sort_order,
                    label, bubble_no, gdt_symbol, check_type,
                    target_value, tolerance_lower, tolerance_upper, unit,
                    instrument_asset_id, pass_fail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$inspectionId, $s, (int)$it['id'], (int)$it['sort_order'],
                 $it['label'], $it['bubble_no'] ?? null, $it['gdt_symbol'] ?? null,
                 $it['check_type'], $it['target_value'],
                 $it['tolerance_lower'], $it['tolerance_upper'], $it['unit'],
                 $it['instrument_asset_id'] ?? null, 'pending']
            );
        }
    }
}


/**
 * Load results for one inspection, indexed as
 *   $grid[$templateItemId][$sampleNo] = $resultRow
 *
 * Used by the multi-sample execute UI and the IR view. Rows with NULL
 * sample_no (legacy data) bucket under sample_no=1 so they still
 * render in the first column.
 */
function ir_results_grid($inspectionId)
{
    // LEFT JOIN the template item so each result row also carries the
    // template's free-text `notes` (aliased item_notes — kept distinct from
    // inspection_results.notes, the per-result inspector note). Surfaced in
    // the inspection View / Execute grid and the printed/PDF IR.
    $rows = db_all(
        'SELECT ir.*, iti.notes AS item_notes
           FROM inspection_results ir
           LEFT JOIN inspection_template_items iti ON iti.id = ir.template_item_id
          WHERE ir.inspection_id = ?
          ORDER BY ir.sort_order, ir.bubble_no, ir.template_item_id, ir.sample_no',
        [(int)$inspectionId]
    );
    $grid = [];
    $params = []; // parameter rows in order, dedup by group key
    $seen = [];
    // Synthetic group ids for rows whose template_item_id is NULL. This
    // happens two ways: (1) historical records imported without a template
    // item, and (2) the FK on inspection_results.template_item_id is
    // ON DELETE SET NULL, so editing a template (which DELETEs + reinserts
    // its items) nulls template_item_id on every existing result row that
    // referenced it. Both cast to 0 below, which would collapse the WHOLE
    // checklist into a single displayed row. We instead group such rows by
    // their stored snapshot identity (sort_order + bubble_no + label +
    // check_type) so each parameter stays a distinct row and its samples
    // still bucket together. Synthetic ids are negative so they can never
    // collide with a real template_item_id.
    $synthSig  = [];
    $synthNext = -1;
    foreach ($rows as $r) {
        $tid = (int)$r['template_item_id'];
        if ($tid <= 0) {
            $sig = ($r['sort_order'] ?? '') . "\0" . ($r['bubble_no'] ?? '')
                 . "\0" . ($r['label'] ?? '') . "\0" . ($r['check_type'] ?? '');
            if (!isset($synthSig[$sig])) {
                $synthSig[$sig] = $synthNext--;
            }
            $tid = $synthSig[$sig];
            // Write the synthetic id back so consumers that re-derive the
            // group via (int)$param['template_item_id'] agree with $grid.
            $r['template_item_id'] = $tid;
        }
        $sno = (int)($r['sample_no'] ?? 1) ?: 1;
        $grid[$tid][$sno] = $r;
        if (!isset($seen[$tid])) {
            $seen[$tid] = true;
            $params[] = $r;          // sentinel row — first seen carries the param metadata
        }
    }
    return ['grid' => $grid, 'params' => $params];
}


/**
 * Compute [min, max] from a target value plus ± tolerances.
 *
 * IMPORTANT — semantics: tolerance_lower/upper are interpreted as
 * NEGATIVE / POSITIVE offsets from the target (matching the printed
 * IR's "Tol −0.8 / +0.8" convention). Min = target − lower,
 * Max = target + upper. If either tolerance is missing/non-numeric,
 * that bound is NULL (one-sided spec).
 */
function ir_min_max($target, $lower, $upper)
{
    if ($target === null || $target === '' || !is_numeric($target)) return [null, null];
    $t = (float)$target;
    $min = ($lower !== null && $lower !== '' && is_numeric($lower)) ? $t - (float)$lower : null;
    $max = ($upper !== null && $upper !== '' && is_numeric($upper)) ? $t + (float)$upper : null;
    return [$min, $max];
}


/**
 * Evaluate a measurement string vs a [min, max] spec.
 * Returns 'pass' / 'fail' / 'na'. Text indicators ("OK", "NOT OK[NG]")
 * are recognised so the IR grid colours them correctly.
 */
function ir_evaluate($value, $min, $max)
{
    if ($value === null || $value === '') return 'na';
    if (!is_numeric($value)) {
        $t = strtolower(trim((string)$value));
        if ($t === 'ok' || $t === 'pass')                             return 'pass';
        if ($t === 'fail' || strpos($t, 'not ok') === 0 || strpos($t, 'ng') !== false) return 'fail';
        return 'na';
    }
    if (($min === null || $min === '') && ($max === null || $max === '')) return 'na';
    $v = (float)$value;
    if ($min !== null && is_numeric($min) && $v < (float)$min) return 'fail';
    if ($max !== null && is_numeric($max) && $v > (float)$max) return 'fail';
    return 'pass';
}


/**
 * Return [min, max] bounds for a check type, interpreting stored fields
 * according to type semantics:
 *   NOM / LOGICAL-NOM / numeric : target ± offsets (same as ir_min_max)
 *   MIN-MAX / LOGICAL-MIN-MAX   : tolerance_lower IS min, tolerance_upper IS max
 *   everything else             : [null, null]
 */
function ir_min_max_for_type($checkType, $target, $lower, $upper)
{
    if ($checkType === 'nom' || $checkType === 'logical-nom' || $checkType === 'numeric') {
        return ir_min_max($target, $lower, $upper);
    }
    if ($checkType === 'min-max' || $checkType === 'logical-min-max') {
        $min = ($lower !== null && $lower !== '' && is_numeric($lower)) ? (float)$lower : null;
        $max = ($upper !== null && $upper !== '' && is_numeric($upper)) ? (float)$upper : null;
        return [$min, $max];
    }
    return [null, null];
}


/**
 * Whether a check type auto-computes pass/fail from a NUMERIC measured
 * value against its [min, max] spec (numeric / nom / min-max). The
 * inspector types a reading and the verdict is derived from the bounds.
 * Types that return false require a manual verdict (or have no verdict).
 */
function ir_auto_passfail($checkType)
{
    return in_array($checkType, ['numeric', 'nom', 'min-max'], true);
}


/**
 * Whether a check type records its verdict via a manual Pass/Fail
 * dropdown (logic / logical-nom / logical-min-max). The verdict itself
 * is stored verbatim ("pass"/"fail") in measured_value. The logical-*
 * types still carry a nominal ± tol / min-max spec that is DISPLAYED in
 * the entry header, the view grid and the printed report — the operator
 * just decides pass/fail rather than typing a measurement.
 */
function ir_is_select_passfail($checkType)
{
    return in_array($checkType, ['logic', 'logical-nom', 'logical-min-max'], true);
}


/**
 * Pretty-print a number for display (trim trailing zeros).
 */
function ir_fmt_num($v)
{
    if ($v === null || $v === '') return '';
    if (!is_numeric($v)) return (string)$v;
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
}


/**
 * Resolve the inv_item that an inspection actually targets, plus the
 * transaction quantity when the target is an inventory transaction.
 *
 * The IR header takes Part No / Part Rev / Part Desc from the *live*
 * inspected item (not the creation-time snapshot, which may be blank
 * for txn-targeted or legacy inspections). The item is reached either
 * directly (entity_type = 'inv_item') or through the transaction's
 * item_id (entity_type = 'inv_txn').
 *
 * Returns:
 *   ['item' => array|null, 'txn_qty' => float|null]
 * where `item` carries part_no, part_rev_no, name, code, dwg_no,
 * dwg_rev_no, and `txn_qty` is the magnitude of the txn's qty_delta
 * (NULL when the target isn't a transaction).
 */
function ir_resolve_inspected_item($row)
{
    $entityType = (string)($row['entity_type'] ?? 'none');
    $entityId   = (int)($row['entity_id'] ?? 0);
    $itemId     = null;
    $txnQty     = null;

    if ($entityType === 'inv_item' && $entityId) {
        $itemId = $entityId;
    } elseif ($entityType === 'inv_txn' && $entityId) {
        $txn = db_one('SELECT item_id, qty_delta FROM inv_txns WHERE id = ?', [$entityId]);
        if ($txn) {
            $itemId = (int)$txn['item_id'];
            $txnQty = abs((float)$txn['qty_delta']);
        }
    }

    $item = null;
    if ($itemId) {
        $item = db_one(
            'SELECT part_no, part_rev_no, name, code, dwg_no, dwg_rev_no
               FROM inv_items WHERE id = ?',
            [$itemId]
        );
    }

    return ['item' => $item, 'txn_qty' => $txnQty];
}


/**
 * Resolve the entity that template links (inspection_template_targets)
 * are keyed on for a given inspection row: an inv_item or an asset.
 *
 * inv_txn-targeted inspections resolve through the txn's item_id, so a
 * receipt/process inspection still finds the item's templates. Returns
 * ['type' => 'inv_item'|'asset'|null, 'id' => int]. type is null (id 0)
 * when no template-target entity can be resolved (e.g. standalone IRs).
 *
 * Shared by the Execute Start-Inspection template picker (to list the
 * item's templates) and the execute_start handler (to validate the
 * chosen template actually belongs to this item).
 */
function ir_template_target_entity($row)
{
    $entityType = (string)($row['entity_type'] ?? '');
    $entityId   = (int)($row['entity_id'] ?? 0);
    if ($entityType === 'inv_item' && $entityId) {
        return ['type' => 'inv_item', 'id' => $entityId];
    }
    if ($entityType === 'asset' && $entityId) {
        return ['type' => 'asset', 'id' => $entityId];
    }
    if ($entityType === 'inv_txn' && $entityId) {
        $txn = db_one('SELECT item_id FROM inv_txns WHERE id = ?', [$entityId]);
        if ($txn && (int)$txn['item_id']) {
            return ['type' => 'inv_item', 'id' => (int)$txn['item_id']];
        }
    }
    return ['type' => null, 'id' => 0];
}


/**
 * Per-sample acceptance map for an IR results grid.
 *
 * A sample column is "Accepted" when it carries at least one measured
 * value AND none of its readings fail their spec. Mirrors the per-page
 * Remarks-row logic in the printed IR so the on-screen view, the
 * printed report and the Accepted-qty figure all agree.
 *
 * Returns [sampleNo => bool] for sampleNo 1..$sampleCount.
 */
function ir_sample_accept_map($params, $grid, $sampleCount)
{
    $sampleCount = max(1, (int)$sampleCount);
    $hasValue = [];
    $failed   = [];
    for ($s = 1; $s <= $sampleCount; $s++) {
        $hasValue[$s] = false;
        $failed[$s]   = false;
    }

    foreach ($params as $p) {
        $ct  = strtolower((string)($p['check_type'] ?? 'numeric'));
        $tid = (int)$p['template_item_id'];
        list($minV, $maxV) = ir_min_max_for_type(
            $ct,
            $p['target_value']    ?? null,
            $p['tolerance_lower'] ?? null,
            $p['tolerance_upper'] ?? null
        );
        for ($s = 1; $s <= $sampleCount; $s++) {
            $cell = $grid[$tid][$s] ?? null;
            if (!$cell) continue;
            $val = (string)($cell['measured_value'] ?? '');
            if ($val === '') continue;
            $hasValue[$s] = true;
            if (is_numeric($val) && ($minV !== null || $maxV !== null)) {
                $v = (float)$val;
                if (($minV !== null && $v < (float)$minV) ||
                    ($maxV !== null && $v > (float)$maxV)) {
                    $failed[$s] = true;
                }
            } elseif (($cell['pass_fail'] ?? '') === 'fail') {
                $failed[$s] = true;
            }
        }
    }

    $map = [];
    for ($s = 1; $s <= $sampleCount; $s++) {
        $map[$s] = ($hasValue[$s] && !$failed[$s]);
    }
    return $map;
}


/**
 * Derive the IR header quantity figures from an inspection row + its
 * results grid, per the confirmed business rules:
 *
 *   PDN qty      = the production quantity entered at plan time when set;
 *                  otherwise the transaction quantity when the inspection
 *                  targets an inv_txn, otherwise the sample count (= sample qty).
 *   Chkd qty     = sample count (number of sample columns inspected).
 *   Accepted qty = number of sample columns marked Accepted.
 *
 * Returns ['pdn' => int, 'chkd' => int, 'accepted' => int].
 */
function ir_header_quantities($row, $params, $grid)
{
    $sampleCount = max(1, (int)($row['sample_count'] ?? 1));
    $resolved    = ir_resolve_inspected_item($row);
    $txnQty      = $resolved['txn_qty'];

    // Operator-entered production quantity wins when present, so the IR
    // header reflects the lot size the planner recorded.
    if (isset($row['pdn_qty']) && $row['pdn_qty'] !== null && $row['pdn_qty'] !== '') {
        $pdn = (int)$row['pdn_qty'];
    } elseif ($txnQty !== null) {
        $pdn = (int)round($txnQty);
    } else {
        $pdn = $sampleCount;
    }
    $chkd     = $sampleCount;
    $accepted = count(array_filter(ir_sample_accept_map($params, $grid, $sampleCount)));

    return ['pdn' => $pdn, 'chkd' => $chkd, 'accepted' => $accepted];
}


/**
 * Generate the IR document number (IR.NNNNN) for a freshly-created
 * inspection. Wrapped so it falls back gracefully if the code sequence
 * is missing (some installs may not have run the migration yet).
 */
function ir_next_no()
{
    try {
        return code_next('inspection_ir');
    } catch (\Throwable $e) {
        $maxId = (int)db_val(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(ir_no, 4) AS UNSIGNED)), 0)
               FROM inspections WHERE ir_no LIKE 'IR.%'",
            [], 0
        );
        return 'IR.' . ($maxId + 1);
    }
}
