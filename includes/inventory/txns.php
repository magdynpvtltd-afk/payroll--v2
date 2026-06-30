<?php
/**
 * MagDyn — Inventory: Transactions (txn_save, process, history, ledger, import)
 * Extracted Stage 1: 20260517_223400_IST
 *
 * All operations that read or write inv_txns: manual receive/issue/adjust, BOM-driven process, the per-item ledger, the global txn history datatable, and CSV import.
 *
 * PARTIAL — not a standalone page. Routed by inventory.php (the
 * dispatcher). Variables already in scope from the dispatcher:
 *   $action, $canViewItems, $canCreateItems, $canManageItems,
 *   $canDeleteItems, $canViewBoms, $canCreateBoms, $canManageBoms,
 *   $canDeleteBoms.
 */

// ============================================================
// Stock transactions — shared helper (now in includes/_inventory_txn.php)
// ============================================================
require_once dirname(__DIR__, 2) . '/includes/_inventory_txn.php';
require_once dirname(__DIR__, 2) . '/includes/_qc.php';

// ============================================================
// txn_save — handles receive / issue / adjust
// ============================================================
// ============================================================
// TRANSACTION IMPORT — two-step (preview + commit), append-only
// ============================================================
// CSV columns:
//   txn_type *    required, one of receive | issue | adjust
//   txn_date *    required, YYYY-MM-DD
//   item_code *   required, matches inv_items.code
//   location_code * required, matches locations.code
//   qty *         required, positive numeric
//                  - receive: stock increases by qty
//                  - issue:   stock decreases by qty
//                  - adjust:  qty is the TARGET balance; delta computed
//                             from current stock at commit time
//   ref_doc       optional — PO number, work order, etc.
//   notes         optional
//
// Transactions are immutable ledger entries — there's no upsert. Rows
// are applied IN CSV ORDER at commit, using the canonical inv_post_txn()
// helper. Each row's success depends on the running stock balance,
// which may shift relative to preview-time state if other rows in the
// same import (or other users) have changed stock since the preview.
// Insufficient-stock failures show up at commit time per row, are
// logged, and counted in the success flash.
require_once dirname(__DIR__, 2) . '/includes/_import.php';

function inv_txn_import_adapter(array $row, bool $upsert) {
    // Note: $upsert is ignored — transactions are append-only. The shared
    // helper passes the flag through uniformly so we accept it.

    $type = strtolower(trim((string)($row['txn_type'] ?? '')));
    if (!in_array($type, ['receive','issue','adjust'], true)) {
        return ['status' => 'error',
                'reason' => 'txn_type must be receive / issue / adjust'];
    }

    $date = trim((string)($row['txn_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['status' => 'error', 'reason' => 'txn_date must be YYYY-MM-DD'];
    }

    $itemCode = trim((string)($row['item_code'] ?? ''));
    if ($itemCode === '') return ['status' => 'error', 'reason' => 'item_code is required'];
    $i = db_one('SELECT id FROM inv_items WHERE code = ?', [$itemCode]);
    if (!$i) return ['status' => 'error', 'reason' => 'Unknown item_code "' . $itemCode . '"'];
    $itemId = (int)$i['id'];

    $locCode = trim((string)($row['location_code'] ?? ''));
    if ($locCode === '') return ['status' => 'error', 'reason' => 'location_code is required'];
    $l = db_one('SELECT id FROM locations WHERE code = ?', [$locCode]);
    if (!$l) return ['status' => 'error', 'reason' => 'Unknown location_code "' . $locCode . '"'];
    $locId = (int)$l['id'];

    $qtyRaw = trim((string)($row['qty'] ?? ''));
    if ($qtyRaw === '' || !is_numeric($qtyRaw)) {
        return ['status' => 'error', 'reason' => 'qty is required and must be numeric'];
    }
    $qty = (float)$qtyRaw;
    if ($qty <= 0 && $type !== 'adjust') {
        return ['status' => 'error', 'reason' => 'qty must be > 0 for receive/issue'];
    }
    if ($qty < 0) {
        return ['status' => 'error', 'reason' => 'qty cannot be negative (for adjust, use 0 to drain)'];
    }

    // Preview-time stock check for issue. We mark the row as a warning-
    // valued "insert" if stock would go negative AT PREVIEW STATE; the
    // commit step will re-check against the true running balance and
    // may still fail with a more accurate message.
    $previewReason = '';
    if ($type === 'issue') {
        $current = (float)db_val(
            'SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?',
            [$itemId, $locId], 0.0
        );
        if ($current - $qty < -0.0001) {
            $previewReason = 'Heads-up: current stock ' . number_format($current, 3)
                           . ' is less than issue qty ' . number_format($qty, 3)
                           . ' — commit will fail unless an earlier row in this import receives stock';
        }
    }

    $clean = [
        'txn_type'      => $type,
        'txn_date'      => $date,
        'item_id'       => $itemId,
        'item_code'     => $itemCode,
        'location_id'   => $locId,
        'location_code' => $locCode,
        'qty'           => $qty,
        'ref_doc'       => trim((string)($row['ref_doc'] ?? '')),
        'notes'         => trim((string)($row['notes'] ?? '')),
    ];
    return ['status' => 'insert', 'data' => $clean, 'reason' => $previewReason];
}

if ($action === 'inv_txn_import_preview') {
    if (!$canManageItems) require_permission('inventory_view_items', 'manage');
    csrf_check();
    $parsed = import_parse_uploaded_csv('csv');
    if (empty($parsed['ok'])) {
        flash_set('error', $parsed['error']);
        redirect(url('/inventory.php?action=txn_history'));
    }
    $token  = import_stash($parsed['csv_text'], 'inv_txns');
    $result = import_run_adapter($parsed['rows'], 'inv_txn_import_adapter', false);

    $page_title  = 'Import inventory transactions · preview';
    $page_module = 'inventory_view_items';
    require dirname(__DIR__, 2) . '/includes/header.php';
    import_render_preview([
        'title'      => 'Import inventory transactions · preview',
        'commit_url' => url('/inventory.php?action=inv_txn_import_commit'),
        'cancel_url' => url('/inventory.php?action=txn_history'),
        'token'      => $token,
        'upsert'     => false,
        'counts'     => $result['counts'],
        'rows'       => $result['rows'],
        'columns'    => [
            ['txn_date',      'Date'],
            ['txn_type',      'Type'],
            ['item_code',     'Item'],
            ['location_code', 'Location'],
            ['qty',           'Qty'],
            ['ref_doc',       'Ref doc'],
        ],
    ]);
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

if ($action === 'inv_txn_import_commit') {
    if (!$canManageItems) require_permission('inventory_view_items', 'manage');
    csrf_check();
    $token = (string)input('token', '');
    $csv = import_unstash($token, 'inv_txns');
    if ($csv === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/inventory.php?action=txn_history'));
    }
    $parsed = import_parse_csv_text($csv);
    if (empty($parsed['ok'])) {
        flash_set('error', 'Re-parse failed: ' . ($parsed['error'] ?? 'unknown'));
        redirect(url('/inventory.php?action=txn_history'));
    }
    $result = import_run_adapter($parsed['rows'], 'inv_txn_import_adapter', false);

    $applied = 0; $errors = 0; $errorLines = [];
    // Apply rows in CSV order via inv_post_txn(). Each succeeds or fails
    // independently — we don't wrap the whole import in a single DB
    // transaction because partial success is more useful than all-or-
    // nothing when 99 rows are good and 1 fails on stock.
    foreach ($result['rows'] as $r) {
        if ($r['status'] !== 'insert') continue;
        $d = $r['data'];
        try {
            $delta = 0.0;
            if ($d['txn_type'] === 'receive') {
                $delta = +$d['qty'];
            } elseif ($d['txn_type'] === 'issue') {
                $delta = -$d['qty'];
            } else {
                // adjust: compute delta from current balance at the moment
                // of application (NOT preview time)
                $current = (float)db_val(
                    'SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?',
                    [$d['item_id'], $d['location_id']], 0.0
                );
                $delta = $d['qty'] - $current;
                if (abs($delta) < 0.0001) {
                    // No-op adjust — skip silently rather than logging an error
                    continue;
                }
            }
            db()->beginTransaction();
            inv_post_txn(
                $d['txn_type'], $d['txn_date'], $d['item_id'], $d['location_id'],
                $delta, null,
                $d['ref_doc'] !== '' ? $d['ref_doc'] : null,
                $d['notes']   !== '' ? $d['notes']   : null
            );
            db()->commit();
            $applied++;
        } catch (Exception $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors++;
            $errorLines[] = (int)$r['line'];
            error_log('[inv_txn_import] line ' . $r['line'] . ': ' . $e->getMessage());
        }
    }
    $msg = 'Applied ' . $applied . ' transaction' . ($applied === 1 ? '' : 's') . '.';
    if ($errors > 0) {
        $msg .= ' ' . $errors . ' failed (lines: ' . implode(', ', array_slice($errorLines, 0, 20))
              . (count($errorLines) > 20 ? '…' : '') . '). See server log for details.';
    }
    flash_set($errors > 0 ? 'warn' : 'success', $msg);
    redirect(url('/inventory.php?action=txn_history'));
}

if ($action === 'txn_save') {
    csrf_check();
    $txnType = (string)input('txn_type', '');
    if (!in_array($txnType, ['receive','issue','adjust'], true)) {
        flash_set('error', 'Unknown transaction type.');
        redirect(url('/inventory.php?action=items'));
    }

    // Permission gate: receive/issue/adjust each require inventory_view_items.manage
    if (!$canManageItems) require_permission('inventory_view_items', 'manage');

    $itemId   = (int)input('item_id', 0);
    $locId    = (int)input('location_id', 0);
    $qty      = (float)input('qty', 0);
    $txnDate  = (string)input('txn_date', date('Y-m-d'));
    $refDoc   = trim((string)input('ref_doc')) ?: null;
    $notes    = trim((string)input('notes'))   ?: null;

    $errors = [];
    if (!$itemId) $errors[] = 'Item is required.';
    if (!$locId)  $errors[] = 'Location is required.';
    if ($qty <= 0) $errors[] = 'Quantity must be greater than zero.';
    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        redirect(url('/inventory.php?action=' . $txnType));
    }

    // Determine the delta
    if ($txnType === 'receive') {
        $delta = +$qty;
    } elseif ($txnType === 'issue') {
        $delta = -$qty;
    } else {
        // 'adjust' — the input qty is the target absolute level at this
        // (item, location). Compute delta from the current balance.
        $current = (float)db_val(
            'SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?',
            [$itemId, $locId], 0.0
        );
        $delta = $qty - $current;
        if (abs($delta) < 0.0001) {
            flash_set('info', 'No change — current balance already matches.');
            redirect(url('/inventory.php?action=ledger&id=' . $itemId));
        }
    }

    try {
        db()->beginTransaction();
        inv_post_txn($txnType, $txnDate, $itemId, $locId, $delta, null, $refDoc, $notes);
        db()->commit();
        flash_set('success', ucfirst($txnType) . ' recorded.');
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', $e->getMessage());
        redirect(url('/inventory.php?action=' . $txnType));
    }
    redirect(url('/inventory.php?action=ledger&id=' . $itemId));
}

// ============================================================
// txn_process — produce N units of a product, consume direct children
// ============================================================
if ($action === 'txn_process') {
    csrf_check();
    if (!permission_check('inventory_process', 'create') && !permission_check('inventory_process', 'manage')) {
        flash_set('error', 'No permission to process inventory.');
        redirect(url('/inventory.php?action=items'));
    }
    $productId = (int)input('product_id', 0);
    // The destination location is forced server-side to LOC-QCH — every
    // qty-increasing process txn must land in QC Hold for inspection.
    // We still read whatever the form posted (now a hidden field) so
    // pre-existing client-side flows don't error on missing input, but
    // we override below.
    $dstLocId  = (int)input('dst_location_id', 0);
    $qty       = (float)input('qty', 0);
    $txnDate   = (string)input('txn_date', date('Y-m-d'));
    $refDoc    = trim((string)input('ref_doc')) ?: null;
    $notes     = trim((string)input('notes'))   ?: null;

    // Per-child-line source split. The form posts two parallel arrays keyed
    // by BOM line id, each holding one entry per source location the operator
    // chose for that line:
    //   child_source[<bom_line_id>][] = location_id
    //   child_qty[<bom_line_id>][]    = qty drawn from that location
    // A line with a single source is just a one-element split. The user must
    // cover every BOM line (sum == required) unless it's a direct addition or
    // a leaf-item build.
    $childSourceRaw = isset($_POST['child_source']) && is_array($_POST['child_source'])
        ? $_POST['child_source'] : [];
    $childQtyRaw    = isset($_POST['child_qty']) && is_array($_POST['child_qty'])
        ? $_POST['child_qty'] : [];
    // $childSplit[<line_id>] = [ ['loc' => locId, 'qty' => qty], ... ]
    $childSplit = [];
    foreach ($childSourceRaw as $lineId => $locs) {
        $lineId = (int)$lineId;
        $locs   = (array)$locs;
        $qtys   = isset($childQtyRaw[$lineId]) ? (array)$childQtyRaw[$lineId] : [];
        $entries = [];
        foreach ($locs as $k => $locId) {
            $locId = (int)$locId;
            $eqty  = isset($qtys[$k]) ? (float)$qtys[$k] : 0.0;
            if ($locId <= 0 || $eqty <= 0) continue;   // drop blank / zero rows
            $entries[] = ['loc' => $locId, 'qty' => $eqty];
        }
        if ($entries) $childSplit[$lineId] = $entries;
    }

    // Guard: held locations (LOC-LIP / LOC-SMP) may never be a consumption
    // source — their stock is add/move only. The per-line picker already
    // excludes them; this rejects a hand-crafted POST that tries anyway.
    if ($childSplit) {
        $heldInList = inv_held_location_codes_sql();
        $heldIds = [];
        foreach (db_all(
            "SELECT id FROM locations
              WHERE code COLLATE utf8mb4_unicode_ci IN ($heldInList)"
        ) as $hr) {
            $heldIds[(int)$hr['id']] = true;
        }
        foreach ($childSplit as $entries) {
            foreach ($entries as $e) {
                if (isset($heldIds[$e['loc']])) {
                    flash_set('error', 'Held stock (Lost In Process / Sample) cannot be '
                        . 'consumed in a build. Move it to an available location first.');
                    redirect(url('/inventory.php?action=process'));
                }
            }
        }
    }

    $errors = [];
    if (!$productId) $errors[] = 'Item is required.';
    if ($qty <= 0)   $errors[] = 'Quantity must be greater than zero.';
    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        redirect(url('/inventory.php?action=process'));
    }

    // Destination is decided server-side by whether the produced item
    // carries an active inspection template. With a template the header
    // txn lands at LOC-QCH (Quality Check Hold) pending inspection and an
    // inspection is auto-created; the inspection's approval routes the
    // qty out to the final location. Without a template the qty is added
    // straight to stores (ST-HLD) and no inspection is created.
    $qchId = qc_loc_id('LOC-QCH');
    if (!$qchId) {
        flash_set('error', 'LOC-QCH location is missing. Run the migration that seeds it.');
        redirect(url('/inventory.php?action=process'));
    }
    $procTplId = qc_item_template_id($productId);
    if ($procTplId) {
        $dstLocId = $qchId;
    } else {
        $stHldId = qc_loc_id('ST-HLD');
        if (!$stHldId) {
            flash_set('error', 'ST-HLD (stores) location is missing. Run the migration that seeds it.');
            redirect(url('/inventory.php?action=process'));
        }
        $dstLocId = $stHldId;
    }

    $product = db_one('SELECT * FROM inv_items WHERE id = ?', [$productId]);
    if (!$product) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=process'));
    }

    // The "direct addition" toggle bypasses child consumption entirely:
    // the item's stock is just incremented at the location, as if it
    // arrived as a finished assembly. Leaf items (no BOM children)
    // automatically follow the same path — there's nothing to consume.
    $directAddition = !empty(input('direct_addition'));

    // The "rework" toggle is mutually exclusive with direct addition.
    // It pulls `qty` of the SAME item from the I-Rework location instead
    // of cascading through the BOM children — i.e. it represents a
    // rework cycle where the item was previously placed in I-Rework and
    // is now finished and moved back to the destination. Net effect:
    //   destination: +qty  (the item being put back into circulation)
    //   I-Rework   : -qty  (the in-rework instance is consumed)
    // No child consumption happens in this path.
    $reworkFlag = !empty(input('rework'));
    if ($reworkFlag && $directAddition) {
        flash_set('error', 'Pick either Rework or Direct addition, not both.');
        redirect(url('/inventory.php?action=process&product_id=' . $productId
            . '&dst_location_id=' . $dstLocId));
    }

    // Resolve I-Rework's location id once if needed.
    $reworkLocId = 0;
    if ($reworkFlag) {
        $reworkLocId = (int)db_val(
            "SELECT id FROM locations
              WHERE code COLLATE utf8mb4_unicode_ci = 'I-Rework'
              LIMIT 1",
            [], 0
        );
        if (!$reworkLocId) {
            flash_set('error', 'Rework selected but the I-Rework location does not exist. '
                . 'Create a location with code I-Rework first.');
            redirect(url('/inventory.php?action=process&product_id=' . $productId
                . '&dst_location_id=' . $dstLocId));
        }
    }

    // BOM children are only consulted when neither direct nor rework
    // bypasses them. Rework still skips the per-line child consumption
    // (the rework txn itself replaces it).
    $lines = ($directAddition || $reworkFlag) ? [] : db_all(
        'SELECT bl.id, bl.child_item_id, bl.qty AS line_qty, ci.code, ci.name
           FROM inv_bom_lines bl
           JOIN inv_items ci ON ci.id = bl.child_item_id
          WHERE bl.parent_item_id = ?',
        [$productId]
    );

    // Validate that EVERY child line has at least one source, and that the
    // per-location quantities sum EXACTLY to the line's required quantity
    // (line_qty * build qty). We ask the user to pick explicitly per the
    // chosen design — no implicit default. A missing source or a sum that
    // doesn't match required is a hard error.
    if ($lines) {
        $missing  = [];
        $mismatch = [];
        foreach ($lines as $L) {
            $entries = $childSplit[(int)$L['id']] ?? [];
            if (!$entries) {
                $missing[] = $L['code'];
                continue;
            }
            $required = (float)$L['line_qty'] * $qty;
            $alloc    = 0.0;
            foreach ($entries as $e) $alloc += $e['qty'];
            if (abs($alloc - $required) > 0.0001) {
                $mismatch[] = sprintf('%s (need %g, allocated %g)', $L['code'], $required, $alloc);
            }
        }
        if ($missing) {
            flash_set('error', 'Pick a source location for each line. Missing: ' . implode(', ', $missing));
            redirect(url('/inventory.php?action=process&product_id=' . $productId
                . '&dst_location_id=' . $dstLocId));
        }
        if ($mismatch) {
            flash_set('error', 'Source quantities must sum to the required quantity for each line. '
                . 'Off on: ' . implode('; ', $mismatch));
            redirect(url('/inventory.php?action=process&product_id=' . $productId
                . '&dst_location_id=' . $dstLocId));
        }
    }

    try {
        db()->beginTransaction();

        // Resolve done_by employee IDs (users_info) + names up-front. The
        // names are stamped into the header note ("Process done by …") as
        // well as persisted to inv_txn_done_by below. Reads the multi-select
        // POST `done_by[]`; unknown / duplicate IDs are silently dropped.
        $doneByRaw = isset($_POST['done_by']) ? (array)$_POST['done_by'] : [];
        $doneBy    = array_values(array_unique(array_filter(array_map('intval', $doneByRaw))));
        $doneByNames = [];
        if ($doneBy) {
            $ph   = implode(',', array_fill(0, count($doneBy), '?'));
            $rows = db_all("SELECT id, name FROM users_info WHERE id IN ($ph)", $doneBy);
            $nameById = [];
            foreach ($rows as $r) $nameById[(int)$r['id']] = $r['name'];
            // Preserve the order the user picked them in.
            foreach ($doneBy as $eid) {
                if (isset($nameById[$eid])) $doneByNames[] = $nameById[$eid];
            }
        }

        // 1. Increment the produced item at the DESTINATION location.
        //    Header note = "Process done by <names>" + the user's notes.
        $noteParts = [];
        if ($doneByNames) $noteParts[] = 'Process done by ' . implode(', ', $doneByNames);
        if ($notes)       $noteParts[] = $notes;
        $headerNote = implode(' — ', $noteParts);
        if ($headerNote === '') {
            if ($directAddition) {
                $headerNote = 'Direct addition: ' . $qty . ' x ' . $product['code'];
            } elseif ($reworkFlag) {
                $headerNote = 'Rework: ' . $qty . ' x ' . $product['code']
                    . ' returned from I-Rework';
            } elseif (!$lines) {
                $headerNote = 'Added leaf item: ' . $qty . ' x ' . $product['code'];
            } else {
                $headerNote = 'Produced ' . $qty . ' x ' . $product['code'];
            }
        }
        $header = inv_post_txn(
            'process', $txnDate, $productId, $dstLocId, +$qty,
            null, $refDoc, $headerNote
        );

        // Persist done_by rows (PK on (txn_id, user_id) dedupes).
        foreach ($doneBy as $uid) {
            db_exec(
                'INSERT IGNORE INTO inv_txn_done_by (txn_id, user_id) VALUES (?, ?)',
                [(int)$header['txn_id'], $uid]
            );
        }

        if ($reworkFlag) {
            // 2a. Rework path: consume the SAME item from I-Rework. No
            //     child cascade — the rework instance IS the input. If
            //     I-Rework is short the inv_post_txn call throws and the
            //     transaction rolls back.
            inv_post_txn(
                'process', $txnDate,
                $productId, $reworkLocId, -$qty,
                $header['txn_id'], $refDoc,
                'Rework consumption: ' . $product['code'] . ' x ' . $qty
            );
        } else {
            // 2b. Cascade child consumption. Each line can be split across
            //     several source locations (per-line multi-source per the new
            //     design); we post one consumption txn PER source entry. The
            //     cascade rows in inv_txns each carry their own location_id,
            //     so the ledger view will correctly show which location lost
            //     stock for each subpart.
            foreach ($lines as $L) {
                $entries = $childSplit[(int)$L['id']] ?? [];
                foreach ($entries as $e) {
                    inv_post_txn(
                        'process', $txnDate,
                        (int)$L['child_item_id'], (int)$e['loc'], -(float)$e['qty'],
                        $header['txn_id'], $refDoc,
                        'Consumed for ' . $product['code'] . ' x ' . $qty
                    );
                }
            }
        }

        // 3. Auto-create the QC inspection for this header txn, but only
        //    when the item has an inspection template. It lands in 'draft'
        //    status and shows up in the pending QC list. We do this BEFORE
        //    commit so a failure in inspection-create rolls back the stock
        //    move too — keeping the ledger consistent with the inspection
        //    queue. Template-less items went straight to stores (ST-HLD)
        //    above and need no inspection.
        $inspectionId = $procTplId
            ? qc_auto_create_inspection_for_txn((int)$header['txn_id'])
            : 0;

        db()->commit();
        $modeTag = $directAddition
            ? ' [direct]'
            : ($reworkFlag ? ' [rework]' : ($lines ? ' [per-line sources]' : ' [leaf]'));
        $destCode = $procTplId ? 'LOC-QCH' : 'ST-HLD';
        $auditDetails = $procTplId
            ? sprintf('%s x %g into LOC-QCH%s · inspection #%d',
                $product['code'], $qty, $modeTag, $inspectionId)
            : sprintf('%s x %g into ST-HLD%s · no template, QC skipped',
                $product['code'], $qty, $modeTag);
        db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.process', ?, ?)",
            [current_user_id(), $productId, $auditDetails]);
        $landing = $procTplId
            ? 'Landed at LOC-QCH pending inspection.'
            : 'Added to stores (ST-HLD). No template — QC skipped.';
        if ($reworkFlag) {
            $msg = sprintf('Reworked %g x %s. %s', $qty, $product['code'], $landing);
        } elseif ($lines) {
            $msg = sprintf('Processed %g x %s. %s', $qty, $product['code'], $landing);
        } else {
            $msg = sprintf('Added %g x %s%s. %s',
                $qty, $product['code'], $directAddition ? ' (direct addition)' : '', $landing);
        }
        flash_set('success', $msg);
        redirect(url('/inventory.php?action=ledger&id=' . $productId));
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', $e->getMessage());
        redirect(url('/inventory.php?action=process&product_id=' . $productId
            . '&dst_location_id=' . $dstLocId));
    }
}

// ============================================================
// move — atomic transfer of stock from one location to another
// ============================================================
// Form (GET): /inventory.php?action=move[&item_id=NN][&src_location_id=NN]
// Save (POST): /inventory.php?action=move_save
//
// Posts a paired txn through inv_post_txn():
//   - 'move' txn type at src_location_id with -qty
//   - 'move' txn type at dst_location_id with +qty, parent_txn_id = first
// Both rows share the ref_doc and notes so the ledger reads as one event.
// ============================================================
if ($action === 'move_save') {
    csrf_check();
    if (!$canManageItems) require_permission('inventory_view_items', 'manage');

    $itemId    = (int)input('item_id', 0);
    $srcLocId  = (int)input('src_location_id', 0);
    $dstLocId  = (int)input('dst_location_id', 0);
    $qty       = (float)input('qty', 0);
    $txnDate   = (string)input('txn_date', date('Y-m-d'));
    $refDoc    = trim((string)input('ref_doc')) ?: null;
    $notes     = trim((string)input('notes'))   ?: null;

    $errors = [];
    if (!$itemId)         $errors[] = 'Item is required.';
    if (!$srcLocId)       $errors[] = 'Source location is required.';
    if (!$dstLocId)       $errors[] = 'Destination location is required.';
    if ($srcLocId === $dstLocId) $errors[] = 'Source and destination must differ.';
    if ($qty <= 0)        $errors[] = 'Quantity must be greater than zero.';
    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        redirect(url('/inventory.php?action=move&item_id=' . $itemId
            . '&src_location_id=' . $srcLocId));
    }

    try {
        db()->beginTransaction();
        $out = inv_post_txn(
            'move', $txnDate, $itemId, $srcLocId, -$qty,
            null, $refDoc, $notes ?: 'Moved out to #' . $dstLocId
        );
        inv_post_txn(
            'move', $txnDate, $itemId, $dstLocId, +$qty,
            (int)$out['txn_id'], $refDoc, $notes ?: 'Moved in from #' . $srcLocId
        );
        db()->commit();
        flash_set('success', sprintf('Moved %s from #%d to #%d.',
            rtrim(rtrim(number_format($qty, 3), '0'), '.'), $srcLocId, $dstLocId));
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', 'Move failed: ' . $e->getMessage());
        redirect(url('/inventory.php?action=move&item_id=' . $itemId
            . '&src_location_id=' . $srcLocId));
    }
    redirect(url('/inventory.php?action=ledger&id=' . $itemId));
}

if ($action === 'move') {
    if (!$canManageItems) require_permission('inventory_view_items', 'manage');

    $items = db_all(
        "SELECT id, code, COALESCE(NULLIF(short_description, ''), name) AS name
           FROM inv_items WHERE is_active = 1 ORDER BY code"
    );
    $locs = db_all('SELECT id, code, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name');

    // Per-item stock-by-location for source filtering. JSON shape:
    //   { "<item_id>": { "<loc_id>": qty, ... }, ... }
    // Only items with at least one row in inv_item_location_stock appear.
    $stockMap = [];
    foreach (db_all('SELECT item_id, location_id, qty FROM inv_item_location_stock WHERE qty <> 0') as $r) {
        $stockMap[(int)$r['item_id']][(int)$r['location_id']] = (float)$r['qty'];
    }

    $preItemId   = (int)input('item_id', 0);
    $preSrcLocId = (int)input('src_location_id', 0);

    $page_title  = 'Move stock';
    $page_module = 'inventory_view_items';
    $focus_id    = 'f_item';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'Move stock',
            'subtitle'    => 'Transfer qty from one location to another. Paired ledger rows ensure totals are preserved.',
            'back_href'   => url('/inventory.php?action=items'),
            'back_label'  => 'Inventory',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=items')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" method="post" action="<?= h(url('/inventory.php?action=move_save')) ?>"
              class="form-page-body form-grid">
            <?= csrf_field() ?>
            <div class="field">
                <label for="f_item">Item *</label>
                <select id="f_item" name="item_id" required tabindex="1">
                    <option value="">— Select —</option>
                    <?php foreach ($items as $it): ?>
                        <option value="<?= (int)$it['id'] ?>" <?= (int)$it['id'] === $preItemId ? 'selected' : '' ?>>
                            <?= h($it['code']) ?> — <?= h($it['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_src">Source location *</label>
                <select id="f_src" name="src_location_id" required tabindex="2">
                    <option value="">— Select item first —</option>
                </select>
                <span id="f_src_hint" class="muted small">Only locations with stock of the selected item are listed.</span>
            </div>
            <div class="field">
                <label for="f_dst">Destination location *</label>
                <select id="f_dst" name="dst_location_id" required tabindex="3">
                    <option value="">— Select —</option>
                    <?php foreach ($locs as $l): ?>
                        <option value="<?= (int)$l['id'] ?>">
                            <?= h($l['name']) ?> (<?= h($l['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_qty">Quantity *</label>
                <input id="f_qty" name="qty" type="number" step="0.001" min="0.001" required tabindex="4">
                <span id="f_qty_hint" class="muted small"></span>
            </div>
            <div class="field">
                <label for="f_date">Date *</label>
                <input id="f_date" name="txn_date" type="date" required tabindex="5" value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="field">
                <label for="f_ref">Reference doc</label>
                <input id="f_ref" name="ref_doc" type="text" tabindex="6">
            </div>
            <div class="field span-2">
                <label for="f_notes">Notes</label>
                <input id="f_notes" name="notes" type="text" tabindex="7">
            </div>
        </form>
    </div>

    <script>
    (function () {
        var stock = <?= json_encode($stockMap) ?>;
        var locs  = <?= json_encode($locs) ?>;
        var locById = {};
        locs.forEach(function (L) { locById[L.id] = L; });
        var selI = document.getElementById('f_item');
        var selS = document.getElementById('f_src');
        var selD = document.getElementById('f_dst');
        var qty  = document.getElementById('f_qty');
        var qtyHint = document.getElementById('f_qty_hint');
        var preSrc = <?= (int)$preSrcLocId ?>;

        function escape(s) { return String(s).replace(/[&<>"]/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];
        }); }

        function refreshSrc() {
            var itemId = parseInt(selI.value || '0', 10);
            if (!itemId) {
                selS.innerHTML = '<option value="">— Select item first —</option>';
                return;
            }
            var rows = stock[itemId] || {};
            var keys = Object.keys(rows);
            if (!keys.length) {
                selS.innerHTML = '<option value="">— No stock anywhere —</option>';
                return;
            }
            var html = '<option value="">— Select —</option>';
            keys.forEach(function (locId) {
                var q = rows[locId];
                if (q <= 0) return;
                var L = locById[locId];
                if (!L) return;
                var sel = (parseInt(locId, 10) === preSrc) ? ' selected' : '';
                html += '<option value="' + locId + '"' + sel + '>'
                     + escape(L.name) + ' (' + escape(L.code) + ') · '
                     + q.toFixed(3) + '</option>';
            });
            selS.innerHTML = html;
            preSrc = 0; // consume the prefill once
            refreshQtyHint();
        }

        function refreshQtyHint() {
            var itemId = parseInt(selI.value || '0', 10);
            var srcId  = parseInt(selS.value || '0', 10);
            if (!itemId || !srcId) { qtyHint.textContent = ''; return; }
            var have = (stock[itemId] && stock[itemId][srcId]) ? stock[itemId][srcId] : 0;
            qty.max = have;
            qtyHint.textContent = 'Available at source: ' + have.toFixed(3);
        }

        selI.addEventListener('change', refreshSrc);
        selS.addEventListener('change', refreshQtyHint);
        refreshSrc();
    })();
    </script>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// receive / issue / adjust — shared form
// ============================================================
if (in_array($action, ['receive', 'issue', 'adjust'], true)) {
    if (!$canManageItems) require_permission('inventory_view_items', 'manage');

    $items = db_all(
        "SELECT id, code, COALESCE(NULLIF(short_description, ''), name) AS name
           FROM inv_items WHERE is_active = 1 ORDER BY code"
    );
    $locs = db_all('SELECT id, code, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name');
    $preItemId = (int)input('item_id', 0);
    $preLocId  = (int)input('location_id', 0);

    $titles = [
        'receive' => ['Receive stock',  'Adds quantity to a location.'],
        'issue'   => ['Issue stock',    'Removes quantity from a location.'],
        'adjust'  => ['Adjust stock',   'Sets the absolute quantity at a location (delta computed automatically).'],
    ];
    list($title, $sub) = $titles[$action];

    $qtyLabel = $action === 'adjust' ? 'New absolute qty *' : 'Quantity *';

    $page_title  = $title;
    $page_module = 'inventory';
    $focus_id    = 'f_item';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $title,
            'subtitle'    => $sub,
            'back_href'   => url('/inventory.php?action=items'),
            'back_label'  => 'Inventory',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=items')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" method="post" action="<?= h(url('/inventory.php?action=txn_save')) ?>"
              class="form-page-body form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="txn_type" value="<?= h($action) ?>">
            <div class="field">
                <label for="f_item">Item *</label>
                <select id="f_item" name="item_id" required tabindex="1">
                    <option value="">— Select —</option>
                    <?php foreach ($items as $it): ?>
                        <option value="<?= (int)$it['id'] ?>" <?= (int)$it['id'] === $preItemId ? 'selected' : '' ?>>
                            <?= h($it['code']) ?> — <?= h($it['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_loc">Location *</label>
                <select id="f_loc" name="location_id" required tabindex="2">
                    <option value="">— Select —</option>
                    <?php foreach ($locs as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $preLocId ? 'selected' : '' ?>>
                            <?= h($l['name']) ?> (<?= h($l['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_qty"><?= h($qtyLabel) ?></label>
                <input id="f_qty" name="qty" type="number" step="0.001" min="0" required tabindex="3">
            </div>
            <div class="field">
                <label for="f_date">Date *</label>
                <input id="f_date" name="txn_date" type="date" required tabindex="4" value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="field">
                <label for="f_ref">Reference doc</label>
                <input id="f_ref" name="ref_doc" type="text" tabindex="5" placeholder="PO#, GRN#, WO#…">
            </div>
            <div class="field span-2">
                <label for="f_notes">Notes</label>
                <input id="f_notes" name="notes" type="text" tabindex="6">
            </div>
        </form>
    </div>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// process — pick product, qty, location → consume direct children
// ============================================================
if ($action === 'process') {
    if (!permission_check('inventory_process', 'view')) require_permission('inventory_process', 'view');

    // Show every active item — process is allowed for any category.
    // Items without a BOM are still valid; they just get incremented
    // without consuming any children (a "direct addition" — useful when
    // a finished assembly is brought in without being made on-site).
    $products = db_all(
        "SELECT i.id, i.code, COALESCE(NULLIF(i.short_description, ''), i.name) AS name,
                (SELECT COUNT(*) FROM inv_bom_lines bl WHERE bl.parent_item_id = i.id) AS line_count
           FROM inv_items i
          WHERE i.is_active = 1
       ORDER BY i.code"
    );
    // Which products carry an active inspection template. Drives the live
    // preview wording: templated items land in QC Hold pending inspection,
    // template-less items are added straight to stores.
    $tplItemIds = [];
    foreach (db_all(
        "SELECT DISTINCT tt.entity_id AS id
           FROM inspection_template_targets tt
           JOIN inspection_templates t ON t.id = tt.template_id AND t.is_active = 1
          WHERE tt.entity_type = 'inv_item'"
    ) as $r) {
        $tplItemIds[(string)(int)$r['id']] = true;
    }

    // Source-location options for the per-child-line pickers. Held
    // locations (LOC-LIP / LOC-SMP) are excluded — their stock is tracked
    // but can never be consumed in a build, only added to or moved.
    $heldInList = inv_held_location_codes_sql();
    $locs = db_all(
        "SELECT id, code, name FROM locations
          WHERE is_active = 1
            AND code COLLATE utf8mb4_unicode_ci NOT IN ($heldInList)
          ORDER BY sort_order, name"
    );
    $preProduct = (int)input('product_id', 0);
    $preDstLoc  = (int)input('dst_location_id', 0);

    // Build a JSON map of product -> children list so the BOM preview can
    // update reactively when product / qty / location changes.
    $preview = [];
    foreach ($products as $p) {
        $children = db_all(
            'SELECT bl.id AS line_id,
                    bl.qty AS line_qty,
                    ci.id, ci.code,
                    COALESCE(NULLIF(ci.short_description, ""), ci.name) AS name,
                    COALESCE(u.label, ci.uom) AS uom
               FROM inv_bom_lines bl
               JOIN inv_items ci ON ci.id = bl.child_item_id
               LEFT JOIN inv_uom u ON u.id = ci.uom_id
              WHERE bl.parent_item_id = ?
              ORDER BY bl.sort_order, bl.id',
            [(int)$p['id']]
        );
        $preview[(int)$p['id']] = $children;
    }
    // Per-location stock for items that appear in any of the previews,
    // so the JS can show "available at L" beside each required qty.
    $stockAt = [];
    foreach (db_all('SELECT item_id, location_id, qty FROM inv_item_location_stock') as $r) {
        $stockAt[(int)$r['item_id']][(int)$r['location_id']] = (float)$r['qty'];
    }

    // Compact location list for the JS to render per-line source pickers.
    $locsForJs = [];
    foreach ($locs as $l) {
        $locsForJs[] = ['id' => (int)$l['id'], 'name' => $l['name'], 'code' => $l['code']];
    }

    // Active employees (users_info) for the "Done by" multi-select. These
    // are shop-floor / staff names (not login accounts), managed under
    // Admin ▸ Employees. status = 1 means active.
    $activeUsers = db_all(
        "SELECT id, name
           FROM users_info
          WHERE status = 1
          ORDER BY name"
    );

    $page_title  = 'Process inventory';
    $page_module = 'inventory_process';
    $focus_id    = 'f_product';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'Process inventory',
            'subtitle'    => 'Add stock; BOM children consumed at chosen locations',
            'back_href'   => url('/inventory.php?action=items'),
            'back_label'  => 'Inventory',
            'actions_html' =>
                '<button type="submit" form="process-form" class="btn btn-primary btn-sm" id="f_submit"'
              . ' data-shortcut="P" accesskey="p">' . shortcut_label('Process', 'P') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=items')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <div class="form-page-body">
            <form method="post" action="<?= h(url('/inventory.php?action=txn_process')) ?>"
                  id="process-form" class="process-split">
                <?= csrf_field() ?>

                <!-- LEFT PANEL: form fields -->
                <div class="process-pane process-pane-left">
                    <h3>Process details</h3>

                    <div class="field">
                        <label for="f_product">Item *</label>
                        <select id="f_product" name="product_id" required tabindex="1">
                            <option value="">— Select —</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $preProduct ? 'selected' : '' ?>
                                        data-line-count="<?= (int)$p['line_count'] ?>">
                                    <?= h($p['code']) ?> — <?= h($p['name']) ?> (<?= (int)$p['line_count'] ?> lines)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="f_qty">Quantity to produce *</label>
                        <input id="f_qty" name="qty" type="number" step="0.001" min="0.001" value="1" required tabindex="2">
                    </div>

                    <div class="field">
                        <label>Destination</label>
                        <div class="muted small" style="padding: 6px 0;">
                            Items with an inspection template land at <strong>LOC-QCH</strong>
                            (Quality Check Hold) pending inspection, and the inspection's approval
                            routes the qty out automatically. Items with no template are added
                            straight to stores (<strong>ST-HLD</strong>) with no inspection.
                        </div>
                        <input type="hidden" name="dst_location_id" value="">
                    </div>

                    <div class="field">
                        <label for="f_date">Date *</label>
                        <input id="f_date" name="txn_date" type="date" required tabindex="4" value="<?= h(date('Y-m-d')) ?>">
                    </div>

                    <div class="field">
                        <label for="f_ref">Reference (Work Order #)</label>
                        <input id="f_ref" name="ref_doc" type="text" tabindex="5">
                    </div>

                    <div class="field">
                        <label for="f_done_by">Done by</label>
                        <select id="f_done_by" name="done_by[]" multiple class="chips" tabindex="6"
                                data-placeholder="Pick one or more employees…">
                            <?php foreach ($activeUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= h($u['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="muted small">Pick the employee(s) who performed this process. Manage the list under Admin ▸ Employees.</span>
                    </div>

                    <div class="field">
                        <label for="f_notes">Notes</label>
                        <input id="f_notes" name="notes" type="text" tabindex="7">
                    </div>

                    <div class="field">
                        <label class="nowrap" style="font-weight: normal; display: flex; align-items: flex-start; gap: 8px;">
                            <input type="checkbox" id="f_direct" name="direct_addition" value="1" tabindex="8" style="margin-top: 2px;">
                            <span>
                                <strong>Direct addition</strong>
                                <span class="muted small">— add stock without consuming any BOM children.
                                    Useful when receiving a finished assembly rather than producing it.</span>
                            </span>
                        </label>
                    </div>

                    <div class="field">
                        <label class="nowrap" style="font-weight: normal; display: flex; align-items: flex-start; gap: 8px;">
                            <input type="checkbox" id="f_rework" name="rework" value="1" tabindex="9" style="margin-top: 2px;">
                            <span>
                                <strong>Rework</strong>
                                <span class="muted small">— consume the same item from <code>I-Rework</code>
                                    instead of cascading through BOM children. Use this when a previously
                                    set-aside instance is finished and moved back to production.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- RIGHT PANEL: live consumption preview -->
                <div class="process-pane process-pane-right">
                    <h3>Child consumption</h3>
                    <div id="process-preview" class="muted small">Select an item to see what will happen.</div>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        var preview = <?= json_encode($preview) ?>;
        var stock   = <?= json_encode($stockAt) ?>;
        var locs    = <?= json_encode($locsForJs) ?>;
        // item id -> true when the item has an active inspection template.
        var hasTpl  = <?= json_encode((object)$tplItemIds) ?>;
        // Phrase the landing spot for the preview based on template status.
        function landingPhrase(pid) {
            return hasTpl[pid]
                ? '<strong>LOC-QCH</strong> pending inspection'
                : 'stores (<strong>ST-HLD</strong>) with no inspection';
        }
        var selP = document.getElementById('f_product');
        var inpQ = document.getElementById('f_qty');
        // selL was the destination-location picker. The destination is
        // now decided server-side (LOC-QCH when the item has an inspection
        // template, else stores/ST-HLD), so the picker was replaced with a
        // static notice and there's no `f_loc` element to read.
        var box  = document.getElementById('process-preview');
        var btn  = document.getElementById('f_submit');
        var chkD = document.getElementById('f_direct');
        var chkR = document.getElementById('f_rework');

        // Identify the I-Rework location once. May be undefined if the
        // location doesn't exist in this DB; the rework path then refuses
        // to enable until it's created.
        var reworkLoc = null;
        for (var li = 0; li < locs.length; li++) {
            if (locs[li].code === 'I-Rework') { reworkLoc = locs[li]; break; }
        }

        // Remember user's per-line source split across re-renders. Keyed by
        // BOM line_id, value is an array of { loc: location_id, qty: number }
        // entries — one per source location the operator drew from. When the
        // user changes the product, this map is wiped — it only makes sense
        // to retain picks for the lines currently in view.
        var sourceMap = {};
        var lastProductId = null;

        function renderLocOptions(itemId, selectedId) {
            // Each option shows the available qty of THIS item at THIS
            // location, so the operator picks an informed source without
            // hunting through the ledger. Locations with zero stock for
            // the item are hidden — there's no point offering them. The
            // currently-selected option (if any) is always retained so
            // the operator's prior pick isn't silently dropped when stock
            // at that location happens to be zero.
            var html = '<option value="">— Pick —</option>';
            locs.forEach(function (L) {
                var qty = (stock[itemId] && stock[itemId][L.id]) ? stock[itemId][L.id] : 0;
                if (qty <= 0 && L.id !== selectedId) return;
                var label = L.name + ' (' + L.code + ') · ' + qty.toFixed(2);
                html += '<option value="' + L.id + '"' + (L.id === selectedId ? ' selected' : '') + '>'
                     + escape(label) + '</option>';
            });
            return html;
        }

        function refresh() {
            var pid    = parseInt(selP.value || '0', 10);
            var qty    = parseFloat(inpQ.value || '0');
            var direct = chkD && chkD.checked;
            var rework = chkR && chkR.checked;

            // Wipe per-line picks when the product changes. Also
            // auto-tick Rework if this product has any stock sitting
            // at I-Rework — the operator is almost certainly here to
            // reprocess that previously-rejected lot rather than to
            // produce fresh from BOM children. The operator can untick
            // afterwards if their intent was a new build. We don't
            // auto-untick when they re-pick a product without I-Rework
            // stock: if it was set manually we leave it alone.
            if (pid !== lastProductId) {
                sourceMap = {};
                lastProductId = pid;
                if (chkR && reworkLoc && pid) {
                    var reworkOnHand = (stock[pid] && stock[pid][reworkLoc.id])
                        ? stock[pid][reworkLoc.id] : 0;
                    if (reworkOnHand > 0.0001 && !chkR.checked) {
                        chkR.checked = true;
                        rework = true;
                    }
                }
            }

            if (!pid) {
                box.innerHTML = 'Select an item to see what will happen.';
                btn.disabled = false;
                return;
            }
            var lines  = preview[pid] || [];
            var isLeaf = !lines.length;

            // Rework path: consume the same item from I-Rework instead
            // of cascading children. Mutually exclusive with direct
            // addition; if both are ticked we surface a clear error and
            // disable submit.
            if (rework) {
                if (direct) {
                    box.innerHTML = '<p class="text-danger"><strong>Pick either Rework or Direct addition, not both.</strong></p>';
                    btn.disabled = true;
                    return;
                }
                if (!reworkLoc) {
                    box.innerHTML = '<p class="text-danger"><strong>Rework selected but no <code>I-Rework</code> location exists in this database.</strong> '
                        + 'Create a location with code <code>I-Rework</code> first.</p>';
                    btn.disabled = true;
                    return;
                }
                var avail = (stock[pid] && stock[pid][reworkLoc.id]) ? stock[pid][reworkLoc.id] : 0;
                var autoHint = (avail > 0.0001)
                    ? '<div class="muted small" style="background:#eff6ff;border-left:3px solid #3b82f6;padding:6px 10px;margin-bottom:8px;border-radius:3px;">'
                      + 'Rework auto-selected because this product has '
                      + avail.toFixed(3) + ' on hand at I-Rework. '
                      + 'Untick if you intend to produce fresh from BOM children instead.'
                      + '</div>'
                    : '';
                var prefix = autoHint
                    + 'Rework selected. <strong>' + qty.toFixed(3) + '</strong> of this item will be consumed from <strong>'
                    + escape(reworkLoc.name) + '</strong> (' + escape(reworkLoc.code) + ') and added at ' + landingPhrase(pid) + '.';
                if (!qty || qty <= 0) {
                    box.innerHTML = prefix + '<br><span class="muted">Enter a quantity to produce.</span>';
                    btn.disabled = false;
                    return;
                }
                var short = qty - avail;
                if (short > 0.0001) {
                    box.innerHTML = prefix
                        + '<br><span class="text-danger">SHORT by ' + short.toFixed(3)
                        + '</span> — only ' + avail.toFixed(3) + ' available at I-Rework.';
                    btn.disabled = true;
                } else {
                    box.innerHTML = prefix
                        + '<br><span class="text-success">OK</span> — ' + avail.toFixed(3) + ' available at I-Rework.';
                    btn.disabled = false;
                }
                return;
            }

            // Direct-addition path / leaf items: no child consumption.
            if (direct || isLeaf) {
                var reason = direct
                    ? 'Direct addition selected. The item\'s stock will be incremented at ' + landingPhrase(pid) + ', with no child consumption.'
                    : 'This item has no BOM. It will be added to stock at ' + landingPhrase(pid) + ' as a leaf item, with no child consumption.';
                if (!qty || qty <= 0) {
                    box.innerHTML = reason + '<br><span class="muted">Enter a quantity to produce.</span>';
                } else {
                    box.innerHTML = reason + '<br><strong>+ ' + qty.toFixed(3)
                        + '</strong> will be added at ' + landingPhrase(pid) + '.';
                }
                btn.disabled = false;
                return;
            }

            if (!qty || qty <= 0) {
                box.innerHTML = '<span class="muted">Enter a quantity to produce.</span>';
                btn.disabled = false;
                return;
            }

            // Render the per-line preview. Each BOM line can pull from one or
            // more source locations; we show a mini source table per line with
            // a location picker + qty per row, an "+ add source" button, and a
            // running allocated-vs-required status. Inputs are named
            //   child_source[<lineId>][]  and  child_qty[<lineId>][]
            // so the server receives parallel per-line arrays via $_POST.
            function entriesFor(lineId) {
                if (!Array.isArray(sourceMap[lineId]) || !sourceMap[lineId].length) {
                    sourceMap[lineId] = [{ loc: 0, qty: 0 }];
                }
                return sourceMap[lineId];
            }

            var html = '';
            lines.forEach(function (L) {
                var required = parseFloat(L.line_qty) * qty;
                var entries  = entriesFor(L.line_id);
                html += '<div class="proc-line" data-line-id="' + L.line_id
                      + '" data-item-id="' + L.id + '" data-required="' + required + '">';
                html += '<div class="proc-line-head" style="margin:10px 0 4px;">'
                      + '<code>' + escape(L.code) + '</code> — ' + escape(L.name)
                      + ' <span class="muted small">· ' + parseFloat(L.line_qty).toFixed(3) + ' '
                      + escape(L.uom || '') + ' per parent</span>'
                      + ' · need <strong>' + required.toFixed(3) + ' ' + escape(L.uom || '') + '</strong>'
                      + ' <span class="proc-line-status"></span></div>';
                html += '<table class="data-table proc-src-table" style="margin:0 0 4px;"><thead><tr>'
                      + '<th>Pull from</th><th class="r" style="width:120px;">Qty</th>'
                      + '<th class="r" style="width:90px;">Avail.</th><th style="width:36px;"></th>'
                      + '</tr></thead><tbody>';
                entries.forEach(function (e, idx) {
                    var avail = (e.loc && stock[L.id] && stock[L.id][e.loc]) ? stock[L.id][e.loc] : 0;
                    html += '<tr>'
                        + '<td><select class="proc-src-loc" data-line-id="' + L.line_id + '" data-idx="' + idx + '" '
                            + 'name="child_source[' + L.line_id + '][]">'
                            + renderLocOptions(L.id, e.loc) + '</select></td>'
                        + '<td class="r"><input class="proc-src-qty r" type="number" step="0.001" min="0" '
                            + 'data-line-id="' + L.line_id + '" data-idx="' + idx + '" '
                            + 'name="child_qty[' + L.line_id + '][]" value="' + (e.qty ? fmtq(e.qty) : '') + '" '
                            + 'style="width:100%;"></td>'
                        + '<td class="r proc-src-avail">' + (e.loc ? avail.toFixed(3) : '—') + '</td>'
                        + '<td class="r">' + (entries.length > 1
                            ? '<button type="button" class="btn btn-icon btn-danger proc-src-del" '
                              + 'data-line-id="' + L.line_id + '" data-idx="' + idx + '" title="Remove">🗑</button>'
                            : '') + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                html += '<button type="button" class="btn btn-ghost btn-sm proc-src-add" data-line-id="'
                      + L.line_id + '">+ add source</button>';
                html += '</div>';
            });
            box.innerHTML = html;

            // recompute() recalculates per-line allocated-vs-required status and
            // the submit gate WITHOUT rebuilding the DOM, so typing in a qty
            // input doesn't lose focus. Called on every qty keystroke and once
            // after each full render. Location changes / add / remove rebuild
            // via refresh() so the option lists and rows stay consistent.
            function recompute() {
                var blockSubmit = false;
                box.querySelectorAll('.proc-line').forEach(function (lineEl) {
                    var lineId   = parseInt(lineEl.getAttribute('data-line-id'), 10);
                    var itemId   = parseInt(lineEl.getAttribute('data-item-id'), 10);
                    var required = parseFloat(lineEl.getAttribute('data-required')) || 0;
                    var entries  = sourceMap[lineId] || [];
                    var alloc = 0, missing = false, perLoc = {};
                    entries.forEach(function (e) {
                        if (e.qty > 0 && !e.loc) missing = true;
                        if (e.loc && e.qty > 0) {
                            alloc += e.qty;
                            perLoc[e.loc] = (perLoc[e.loc] || 0) + e.qty;
                        }
                    });
                    var over = false;
                    Object.keys(perLoc).forEach(function (loc) {
                        var avail = (stock[itemId] && stock[itemId][loc]) ? stock[itemId][loc] : 0;
                        if (perLoc[loc] - avail > 0.0001) over = true;
                    });
                    var statusEl = lineEl.querySelector('.proc-line-status');
                    var msg, cls;
                    if (missing || alloc <= 0) {
                        msg = '— pick source(s) —'; cls = 'muted'; blockSubmit = true;
                    } else if (over) {
                        msg = 'SHORT — exceeds stock at a location'; cls = 'text-danger'; blockSubmit = true;
                    } else if (Math.abs(alloc - required) > 0.0001) {
                        var diff = alloc - required;
                        msg = 'allocated ' + alloc.toFixed(3) + ' / ' + required.toFixed(3)
                            + (diff < 0 ? ' — short by ' + (-diff).toFixed(3) : ' — over by ' + diff.toFixed(3));
                        cls = 'text-danger'; blockSubmit = true;
                    } else {
                        msg = 'OK — ' + alloc.toFixed(3) + ' / ' + required.toFixed(3); cls = 'text-success';
                    }
                    if (statusEl) { statusEl.className = 'proc-line-status ' + cls; statusEl.textContent = ' · ' + msg; }
                    // Refresh the per-row "Avail." cells from the current picks.
                    lineEl.querySelectorAll('.proc-src-qty').forEach(function (inp) {
                        var idx = parseInt(inp.getAttribute('data-idx'), 10);
                        var e = entries[idx]; if (!e) return;
                        var availCell = inp.closest('tr').querySelector('.proc-src-avail');
                        var avail = (e.loc && stock[itemId] && stock[itemId][e.loc]) ? stock[itemId][e.loc] : 0;
                        if (availCell) availCell.textContent = e.loc ? avail.toFixed(3) : '—';
                    });
                });
                btn.disabled = blockSubmit;
            }

            // Wire the per-entry controls. Location / add / remove rebuild the
            // whole preview; qty edits do an in-place recompute (focus-safe).
            box.querySelectorAll('.proc-src-loc').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    var lineId = parseInt(sel.getAttribute('data-line-id'), 10);
                    var idx    = parseInt(sel.getAttribute('data-idx'), 10);
                    entriesFor(lineId)[idx].loc = parseInt(sel.value || '0', 10);
                    refresh();
                });
            });
            box.querySelectorAll('.proc-src-qty').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    var lineId = parseInt(inp.getAttribute('data-line-id'), 10);
                    var idx    = parseInt(inp.getAttribute('data-idx'), 10);
                    entriesFor(lineId)[idx].qty = parseFloat(inp.value || '0') || 0;
                    recompute();
                });
            });
            box.querySelectorAll('.proc-src-add').forEach(function (b) {
                b.addEventListener('click', function () {
                    var lineId = parseInt(b.getAttribute('data-line-id'), 10);
                    entriesFor(lineId).push({ loc: 0, qty: 0 });
                    refresh();
                });
            });
            box.querySelectorAll('.proc-src-del').forEach(function (b) {
                b.addEventListener('click', function () {
                    var lineId = parseInt(b.getAttribute('data-line-id'), 10);
                    var idx    = parseInt(b.getAttribute('data-idx'), 10);
                    var arr = entriesFor(lineId);
                    arr.splice(idx, 1);
                    if (!arr.length) arr.push({ loc: 0, qty: 0 });
                    refresh();
                });
            });
            recompute();
        }
        function escape(s) { return String(s).replace(/[&<>"]/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];
        }); }
        function fmtq(n) { return (Math.round(n * 1000) / 1000).toString(); }

        selP.addEventListener('change', refresh);
        inpQ.addEventListener('input',  refresh);
        if (chkD) chkD.addEventListener('change', refresh);
        if (chkR) chkR.addEventListener('change', refresh);
        refresh();
    })();
    </script>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// TRANSACTION HISTORY — module-wide, chronological
// ============================================================
// Lives at /inventory.php?action=txn_history and is registered as a
// submodule by migration 213000. Shows every row of inv_txns across
// all items + locations, filterable by date range, txn type, item,
// location, and correction flag.
if ($action === 'txn_history') {
    if (!permission_check('inventory_txn_history', 'view')) {
        require_permission('inventory_txn_history', 'view');
    }
    // The is_correction column was added in migration 203000 (S&R edit
    // support). Guard the SELECT and the column reference so installs
    // that haven't applied it yet still render the page.
    $hasCorr = (bool)db_one("SHOW COLUMNS FROM inv_txns LIKE 'is_correction'");
    $corrCol = $hasCorr ? 't.is_correction' : '0 AS is_correction';

    $dtCfg = [
        'id'       => 'inv_txn_history',
        'base_sql' => 'SELECT t.id, t.created_at, t.txn_date, t.txn_type,
                              t.item_id, t.location_id, t.qty_delta, t.qty_after,
                              t.ref_doc, t.notes, t.parent_txn_id, t.created_by,
                              ' . $corrCol . ',
                              i.code AS item_code,
                              COALESCE(NULLIF(i.short_description, ""), i.name) AS item_name,
                              l.name AS location_name, l.code AS location_code,
                              u.full_name AS actor_name,
                              -- Invoice linkage. Only ship_in (and legacy receive) txns
                              -- carry an inv_receipts row; for those, the subquery
                              -- returns the qty linked to invoices via invoice_lines.
                              -- For other txn types the LEFT JOIN yields NULL → "—".
                              r.id              AS receipt_id,
                              r.qty_received    AS receipt_qty,
                              COALESCE(linked_agg.linked_qty, 0) AS inv_linked_qty
                         FROM inv_txns t
                         JOIN inv_items     i ON i.id = t.item_id
                         LEFT JOIN locations l ON l.id = t.location_id
                         LEFT JOIN users    u ON u.id = t.created_by
                         LEFT JOIN inv_receipts r ON r.txn_id = t.id
                         LEFT JOIN (
                             SELECT inv_receipt_id, SUM(qty) AS linked_qty
                               FROM invoice_lines
                              WHERE inv_receipt_id IS NOT NULL
                              GROUP BY inv_receipt_id
                         ) linked_agg ON linked_agg.inv_receipt_id = r.id',
        'columns'  => [
            ['key'=>'created_at',    'label'=>'When',    'sortable'=>true, 'searchable'=>false, 'sql_col'=>'t.created_at', 'td_class'=>'nowrap'],
            ['key'=>'txn_id',        'label'=>'Txn ID',  'sortable'=>true, 'searchable'=>true,  'sql_col'=>'t.id',          'td_class'=>'nowrap mono'],
            ['key'=>'txn_type',      'label'=>'Type',    'sortable'=>true, 'sql_col'=>'t.txn_type',
             'filter' => [
                 'type' => 'select', 'placeholder' => 'all',
                 'options' => [
                     ['value' => 'receive',  'label' => 'Receive'],
                     ['value' => 'issue',    'label' => 'Issue'],
                     ['value' => 'adjust',   'label' => 'Adjust'],
                     ['value' => 'process',  'label' => 'Process'],
                     ['value' => 'move',     'label' => 'Move'],
                     ['value' => 'ship_out', 'label' => 'Ship out'],
                     ['value' => 'ship_in',  'label' => 'Ship in'],
                 ],
             ]],
            ['key'=>'item_name',     'label'=>'Name',    'sortable'=>true, 'searchable'=>true,
             // Searchable on both item code and name — "(CODE)-Name"
             // displays in the row. The separate Item code column was
             // dropped — code is in the prefix here and the cell is
             // the link to the per-item ledger. Sort goes by code so
             // alphabetical order by SKU still works.
             'sql_col'=>"CONCAT('(', i.code, ')-', COALESCE(NULLIF(i.short_description, ''), i.name))"],
            ['key'=>'location_name', 'label'=>'Location','sortable'=>true, 'searchable'=>true, 'sql_col'=>'l.name'],
            ['key'=>'qty_delta',     'label'=>'Delta',   'sortable'=>true, 'searchable'=>false, 'sql_col'=>'t.qty_delta', 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'qty_after',     'label'=>'After',   'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'inv_linked',    'label'=>'Linked',  'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'inv_unlinked',  'label'=>'Unlinked','sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'ref_doc',       'label'=>'Ref',     'sortable'=>false,'searchable'=>true, 'sql_col'=>'t.ref_doc'],
            ['key'=>'notes',         'label'=>'Notes',   'sortable'=>false,'searchable'=>true, 'sql_col'=>'t.notes', 'td_class'=>'muted small'],
            ['key'=>'actor_name',    'label'=>'By',      'sortable'=>false,'searchable'=>false],
            ['key'=>'_actions',      'label'=>'Actions', 'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['created_at', 'desc'],
    ];
    // We need note counts per row for the gear-menu badge. Run the
    // query first (data_table_query) to get the page's row ids, then
    // batch-fetch counts in one go, then render with the count map.
    $dt = data_table_query($dtCfg);
    $txnIds = array_map(function ($r) { return (int)$r['id']; }, $dt['rows']);
    $noteCounts = notes_counts_for('inv_txn', $txnIds);
    // Permission to plan a new inspection — checked once and reused
    // inside the row renderer so we don't query for every row.
    $canCreateInspection = permission_check('inspection', 'create');

    $rowRenderer = function ($r) use ($noteCounts, $canCreateInspection) {
        $delta = (float)$r['qty_delta'];
        $cls   = $delta >= 0 ? 'text-success' : 'text-danger';
        $type  = $r['txn_type'];
        $pillClass = [
            'receive'  => 'active',
            'issue'    => 'warn',
            'adjust'   => 'info',
            'process'  => 'neutral',
            'move'     => 'info',
            'ship_out' => 'warn',
            'ship_in'  => 'active',
        ][$type] ?? 'neutral';
        $typePill = '<span class="pill pill-' . $pillClass . '">' . h($type) . '</span>';
        if ((int)($r['is_correction'] ?? 0) === 1) {
            $typePill .= ' <span class="pill pill-warn" title="Correction txn">↻</span>';
        }
        // The "Name" column carries the "(CODE)-Name" prefix AND the
        // link to the per-item ledger — we used to have a separate
        // Item column for that link, but the code is now redundant
        // with the prefix shown here, so the column was dropped.
        $itemLink = '<a href="' . h(url('/inventory.php?action=ledger&id=' . (int)$r['item_id'])) . '">'
                  . '(' . h($r['item_code']) . ')-' . h($r['item_name'])
                  . '</a>';
        $nc = $noteCounts[(int)$r['id']] ?? 0;

        // Invoice linked / unlinked cells. Only ship_in & legacy receive
        // txns carry an inv_receipts row (receipt_id is non-null). For
        // everything else (issue, adjust, process, move, ship_out) we
        // show a muted "—" to distinguish "not invoice-eligible" from
        // "zero linked" — those have very different meanings.
        if (!empty($r['receipt_id'])) {
            $rcvQty   = (float)$r['receipt_qty'];
            $linked   = (float)$r['inv_linked_qty'];
            $unlinked = $rcvQty - $linked;
            if ($unlinked < 0) $unlinked = 0.0;
            $fmtQ = function ($v) {
                return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
            };
            $invLinkedCell   = $linked > 0
                ? '<strong style="color:#059669">' . h($fmtQ($linked)) . '</strong>'
                : '<span class="muted">0</span>';
            $invUnlinkedCell = $unlinked > 0
                ? '<strong style="color:#b45309">' . h($fmtQ($unlinked)) . '</strong>'
                : '<span class="muted">0</span>';
        } else {
            $invLinkedCell   = '<span class="muted">—</span>';
            $invUnlinkedCell = '<span class="muted">—</span>';
        }

        // Build gear-menu actions. Notes always available. Inspect only
        // on qty-increasing txns (incoming material → inspect-on-arrival)
        // and only for users with inspection.create.
        $actions = notes_popup_menu_item('inv_txn', (int)$r['id'], 'Notes', $nc);
        if ($canCreateInspection && $delta > 0) {
            $newUrl = url('/inspection.php?action=new&inspection_type=incoming'
                . '&entity_type=inv_txn&entity_id=' . (int)$r['id']);
            $actions .= ' <a class="btn btn-icon" href="' . h($newUrl) . '"'
                     . ' title="Create inspection for this receipt" aria-label="Inspect">'
                     . '🔍 <span class="dt-action-label">Inspect</span></a>';
        }

        return [
            'created_at'    => h(dt_display($r['created_at'])),
            'txn_id'        => '#' . (int)$r['id'],
            'txn_type'      => $typePill,
            'item_name'     => $itemLink,
            'location_name' => h($r['location_name'] ?: '—'),
            'qty_delta'     => '<span class="' . $cls . '">' . ($delta >= 0 ? '+' : '') . number_format($delta, 3) . '</span>',
            'qty_after'     => number_format((float)$r['qty_after'], 3),
            'inv_linked'    => $invLinkedCell,
            'inv_unlinked'  => $invUnlinkedCell,
            'ref_doc'       => h($r['ref_doc'] ?: ''),
            'notes'         => h($r['notes'] ?: ''),
            'actor_name'    => h($r['actor_name'] ?: '—'),
            '_actions'      => dt_actions_wrap($actions),
        ];
    };

    // Handle the dt_format=json case (AJAX page swap) the same way data_table_run does.
    if ((string)input('dt_format', '') === 'json') {
        ob_start();
        data_table_render_rows($dtCfg, $dt, $rowRenderer);
        $rowsHtml = ob_get_clean();
        ob_start();
        data_table_render_pager($dt);
        $pagerHtml = ob_get_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true, 'rows_html' => $rowsHtml, 'pager_html' => $pagerHtml,
            'total' => (int)$dt['total'], 'page' => (int)$dt['page'],
            'pages' => (int)$dt['pages'], 'page_size' => (int)$dt['page_size'],
            'sort' => $dt['sort'], 'dir' => $dt['dir'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $page_title  = 'Transaction history';
    $page_module = 'inventory_txn_history';
    $focus_id    = '';

    $actionsHtml = '<a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=items')) . '">← Inventory</a>';
    if ($canManageItems) {
        $actionsHtml .= ' <button type="button" class="btn btn-ghost btn-sm"'
                      . ' data-open-import="inv-txn-import-modal"'
                      . ' title="Import inventory transactions from CSV">⤒ Import transactions</button>';
    }
    $dtCfg['title']        = 'Transaction history';
    $dtCfg['actions_html'] = $actionsHtml;

    require dirname(__DIR__, 2) . '/includes/header.php';
    data_table_render($dtCfg, $dt, $rowRenderer);
    notes_popup_assets();
    if ($canManageItems) {
        import_modal_html(
            'inv-txn-import-modal',
            'Import inventory transactions from CSV',
            url('/inventory.php?action=inv_txn_import_preview'),
            'Each row is one stock transaction, applied in CSV order. '
              . 'Required columns: <code>txn_type</code> (receive/issue/adjust), '
              . '<code>txn_date</code> (YYYY-MM-DD), '
              . '<code>item_code</code>, <code>location_code</code>, <code>qty</code>. '
              . 'Optional: <code>ref_doc</code>, <code>notes</code>. '
              . 'For <code>adjust</code>, <code>qty</code> is the TARGET balance; the '
              . 'delta is computed at commit time from the running stock. '
              . '<strong>Transactions are append-only</strong> — there is no upsert. '
              . 'Insufficient-stock failures show up at commit time per row.',
            /* showUpsert: */ false
        );
    }
    require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// ledger — per-item transaction history
// ============================================================
if ($action === 'ledger') {
    if (!$canViewItems) require_permission('inventory_view_items', 'view');
    $id   = (int)input('id', 0);
    $item = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
    if (!$item) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=items'));
    }

    $dtCfg = [
        'id'       => 'inv_ledger_' . $id,
        'base_sql' => 'SELECT t.*, l.name AS location_name, u.full_name AS actor_name,
                              -- Invoice linkage (only meaningful for txns carrying
                              -- an inv_receipts row, i.e. ship_in / legacy receive).
                              r.id              AS receipt_id,
                              r.qty_received    AS receipt_qty,
                              COALESCE(linked_agg.linked_qty, 0) AS inv_linked_qty
                         FROM inv_txns t
                         LEFT JOIN locations l ON l.id = t.location_id
                         LEFT JOIN users u     ON u.id = t.created_by
                         LEFT JOIN inv_receipts r ON r.txn_id = t.id
                         LEFT JOIN (
                             SELECT inv_receipt_id, SUM(qty) AS linked_qty
                               FROM invoice_lines
                              WHERE inv_receipt_id IS NOT NULL
                              GROUP BY inv_receipt_id
                         ) linked_agg ON linked_agg.inv_receipt_id = r.id',
        'extra_where' => [['t.item_id = ?', [$id]]],
        'columns'  => [
            ['key'=>'created_at',    'label'=>'When',      'sortable'=>true, 'searchable'=>false,'sql_col'=>'t.created_at',    'td_class'=>'nowrap'],
            ['key'=>'txn_date',      'label'=>'Txn date',  'sortable'=>true, 'searchable'=>false,'sql_col'=>'t.txn_date'],
            ['key'=>'txn_type',      'label'=>'Type',      'sortable'=>true, 'sql_col'=>'t.txn_type',
             'filter' => [
                 'type' => 'select', 'placeholder' => 'all',
                 'options' => [
                     ['value' => 'receive', 'label' => 'Receive'],
                     ['value' => 'issue',   'label' => 'Issue'],
                     ['value' => 'adjust',  'label' => 'Adjust'],
                     ['value' => 'process', 'label' => 'Process'],
                     ['value' => 'move',    'label' => 'Move'],
                 ],
             ]],
            ['key'=>'location_name', 'label'=>'Location',  'sortable'=>true, 'searchable'=>true, 'sql_col'=>'l.name'],
            ['key'=>'qty_delta',     'label'=>'Delta',     'sortable'=>true, 'searchable'=>false,'sql_col'=>'t.qty_delta', 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'qty_after',     'label'=>'After',     'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'inv_linked',    'label'=>'Linked',    'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'inv_unlinked',  'label'=>'Unlinked',  'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'ref_doc',       'label'=>'Ref',       'sortable'=>false,'searchable'=>true, 'sql_col'=>'t.ref_doc'],
            ['key'=>'notes',         'label'=>'Notes',     'sortable'=>false,'searchable'=>true, 'sql_col'=>'t.notes', 'td_class'=>'muted small'],
            ['key'=>'actor_name',    'label'=>'By',        'sortable'=>false,'searchable'=>false],
            ['key'=>'_actions',      'label'=>'Actions',   'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['created_at', 'desc'],
    ];
    $dt = data_table_query($dtCfg);
    $txnIds = array_map(function ($r) { return (int)$r['id']; }, $dt['rows']);
    $noteCounts = notes_counts_for('inv_txn', $txnIds);
    $canCreateInspection = permission_check('inspection', 'create');

    // CMM run counts per txn — for the "CMM" action button on each row
    $cmmCounts = [];
    if (!empty($txnIds) && permission_check('cmm', 'view')) {
        $placeholders = implode(',', array_fill(0, count($txnIds), '?'));
        $cmmRows = db_all(
            "SELECT txn_id, COUNT(*) AS n FROM inv_txn_cmm_runs
              WHERE txn_id IN ($placeholders) GROUP BY txn_id",
            $txnIds
        );
        foreach ($cmmRows as $r) $cmmCounts[(int)$r['txn_id']] = (int)$r['n'];
    }
    $canCmm = permission_check('cmm', 'view');

    $rowRenderer = function ($r) use ($noteCounts, $canCreateInspection, $cmmCounts, $canCmm) {
        $delta = (float)$r['qty_delta'];
        $cls = $delta >= 0 ? 'text-success' : 'text-danger';
        $type = $r['txn_type'];
        $typePill = '<span class="pill pill-'
            . ($type === 'receive' ? 'active'
                : ($type === 'issue' ? 'warn'
                : ($type === 'adjust' ? 'info'
                : ($type === 'move'   ? 'info' : 'neutral'))))
            . '">' . h($type) . '</span>';
        $nc = $noteCounts[(int)$r['id']] ?? 0;
        $cmmN = $cmmCounts[(int)$r['id']] ?? 0;

        $actions = notes_popup_menu_item('inv_txn', (int)$r['id'], 'Notes', $nc);
        if ($canCreateInspection && $delta > 0) {
            $newUrl = url('/inspection.php?action=new&inspection_type=incoming'
                . '&entity_type=inv_txn&entity_id=' . (int)$r['id']);
            $actions .= ' <a class="btn btn-icon" href="' . h($newUrl) . '"'
                     . ' title="Create inspection for this receipt" aria-label="Inspect">'
                     . '🔍 <span class="dt-action-label">Inspect</span></a>';
        }
        // CMM Analyzer link — open in upload-with-txn-context mode if no
        // runs linked yet, otherwise jump straight to the runs list filtered
        // by this txn. The cmm.php list page shows a banner "Linking mode"
        // when a txn_id is supplied so the next upload auto-links.
        if ($canCmm && $delta > 0) {
            $cmmUrl = url('/cmm.php?txn_id=' . (int)$r['id']);
            $label  = $cmmN > 0 ? "📐 CMM ($cmmN)" : "📐 CMM";
            $title  = $cmmN > 0
                ? "$cmmN CMM run(s) linked — click to analyze another"
                : "Open CMM Analyzer for this txn";
            $actions .= ' <a class="btn btn-icon" href="' . h($cmmUrl) . '"'
                     . ' title="' . h($title) . '" aria-label="CMM Analyzer">'
                     . $label . '</a>';
        }

        // Invoice linked / unlinked cells (see txn_history above for
        // rationale on showing "—" vs "0" for non-receipt rows).
        if (!empty($r['receipt_id'])) {
            $rcvQty   = (float)$r['receipt_qty'];
            $linked   = (float)$r['inv_linked_qty'];
            $unlinked = $rcvQty - $linked;
            if ($unlinked < 0) $unlinked = 0.0;
            $fmtQ = function ($v) {
                return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
            };
            $invLinkedCell   = $linked > 0
                ? '<strong style="color:#059669">' . h($fmtQ($linked)) . '</strong>'
                : '<span class="muted">0</span>';
            $invUnlinkedCell = $unlinked > 0
                ? '<strong style="color:#b45309">' . h($fmtQ($unlinked)) . '</strong>'
                : '<span class="muted">0</span>';
        } else {
            $invLinkedCell   = '<span class="muted">—</span>';
            $invUnlinkedCell = '<span class="muted">—</span>';
        }

        return [
            'created_at'    => h(dt_display($r['created_at'])),
            'txn_date'      => h($r['txn_date']),
            'txn_type'      => $typePill,
            'location_name' => h($r['location_name'] ?: '—'),
            'qty_delta'     => '<span class="' . $cls . '">' . ($delta >= 0 ? '+' : '') . number_format($delta, 3) . '</span>',
            'qty_after'     => number_format((float)$r['qty_after'], 3),
            'inv_linked'    => $invLinkedCell,
            'inv_unlinked'  => $invUnlinkedCell,
            'ref_doc'       => h($r['ref_doc'] ?: ''),
            'notes'         => h($r['notes'] ?: ''),
            'actor_name'    => h($r['actor_name'] ?: '—'),
            '_actions'      => dt_actions_wrap($actions),
        ];
    };

    if ((string)input('dt_format', '') === 'json') {
        ob_start();
        data_table_render_rows($dtCfg, $dt, $rowRenderer);
        $rowsHtml = ob_get_clean();
        ob_start();
        data_table_render_pager($dt);
        $pagerHtml = ob_get_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true, 'rows_html' => $rowsHtml, 'pager_html' => $pagerHtml,
            'total' => (int)$dt['total'], 'page' => (int)$dt['page'],
            'pages' => (int)$dt['pages'], 'page_size' => (int)$dt['page_size'],
            'sort' => $dt['sort'], 'dir' => $dt['dir'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Location breakdown
    $breakdown = db_all(
        'SELECT s.qty, l.name AS location_name, l.code AS location_code
           FROM inv_item_location_stock s
           JOIN locations l ON l.id = s.location_id
          WHERE s.item_id = ? AND s.qty <> 0
          ORDER BY l.sort_order, l.name',
        [$id]
    );

    $page_title  = 'Ledger: ' . ($item['short_description'] ?: $item['name']);
    $page_module = 'inventory';
    $focus_id    = '';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($item['short_description'] ?: $item['name']) ?>
                <span class="muted small mono"><?= h($item['code']) ?></span></h1>
            <p class="muted">Transaction ledger. Total on hand: <strong><?= number_format((float)$item['stock_on_hand'], 3) ?></strong></p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=items')) ?>">← Inventory</a>
            <?php if ($canManageItems): ?>
                <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=receive&item_id=' . $id)) ?>">+ Receive</a>
                <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=issue&item_id='   . $id)) ?>">− Issue</a>
                <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=adjust&item_id='  . $id)) ?>">Adjust</a>
                <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=move&item_id='    . $id)) ?>">⇄ Move</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($breakdown): ?>
    <div class="card" style="margin-bottom: 16px;">
        <div class="card-head"><h2>Location breakdown</h2></div>
        <table class="data-table">
            <thead><tr><th>Location</th><th>Code</th><th class="r">Qty on hand</th></tr></thead>
            <tbody>
                <?php foreach ($breakdown as $b): ?>
                    <tr>
                        <td><?= h($b['location_name']) ?></td>
                        <td><code><?= h($b['location_code']) ?></code></td>
                        <td class="r"><strong><?= number_format((float)$b['qty'], 3) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php
    $dtCfg['title'] = 'Transactions';
    data_table_render($dtCfg, $dt, $rowRenderer);
    notes_popup_assets();
    ?>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

