<?php
/**
 * MagDyn — Old Inventory Inspection RECORD Import Service (API version)
 *
 * Imports the legacy `inspection_data` table (the actual measured readings)
 * from the old inventory system via api_export_inspections.php and turns each
 * legacy inspection event into a MagDyn `inspections` record + its
 * `inspection_results` rows.
 *
 * Grouping (old → new):
 *   `inspection_data.transaction_id` identifies one inspection EVENT (one row
 *   per bubble × sample reading). Every transaction belongs to a single
 *   product (`p_id`). All readings for a transaction become ONE `inspections`
 *   row plus one `inspection_results` row per reading.
 *
 * Linking — "the corresponding template and bubble":
 *   - `p_id` → the imported template `OINS-<p_id>` (built by
 *     OldInventoryInspectionTemplateImportService). The inspection's
 *     template_id is set to it and the inspection is linked to the inv_item
 *     whose code = p_id.
 *   - Each reading's bubble → the template item: matched on
 *     `inspection_data.insp_bubbleno` (the real drawing bubble number that
 *     joins to old `inspection.BubbleNo`, i.e. the template item's bubble_no),
 *     falling back to `bubble_no` (the sequential display index). The matched
 *     template item's metadata (label / check_type / target / tolerances /
 *     unit) is snapshotted onto the result, exactly like the live "execute"
 *     flow, and the measured `data` + computed pass/fail are stored against
 *     its `sample_no`.
 *
 * Record identity:
 *   code = 'OINS-T-' . transaction_id   — stable, so a re-run UPDATES the same
 *   inspection (results rebuilt) instead of duplicating, and the "Delete
 *   imported inspections" reset can target them with LIKE 'OINS-T-%'.
 *
 * Status is derived from the readings: any failing reading → 'failed', else
 * any passing reading → 'passed', else 'in_progress'.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/OldInventoryInspectionRecordImportService.php';
 *   $svc    = new OldInventoryInspectionRecordImportService(current_user_id());
 *   $result = $svc->run();                       // full (no-JS fallback)
 *   // or, chunked: $svc->buildLookupMaps(); $svc->importTransaction($id, $rows);
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryInspectionRecordImportService
{
    /** Transactions per API page (each carries all its readings). */
    private const TXN_BATCH = 50;

    /** Imported-record code prefix (also the Delete-imported LIKE target). */
    public const CODE_PREFIX = 'OINS-T-';

    /** Template code prefix produced by the template importer. */
    private const TPL_PREFIX = 'OINS-';

    /** @var int  User credited as planner / inspector of imported records */
    private int $actorId;

    /** @var array<string,int>  pid → inspection_templates.id (imported templates) */
    private array $templateByPid = [];

    /** @var array<string,int>  inv_items.code → inv_items.id */
    private array $itemIdByCode = [];

    /** @var array<int,array<string,array>>  template_id → (bubble_no → item row) */
    private array $bubbleCache = [];

    /** @var bool */
    private bool $mapsBuilt = false;

    /** @var array */
    private array $errors = [];

    /** @var callable|null */
    private $onProgress = null;

    /** @var array */
    private array $counts = [
        'row_total'        => 0,   // readings seen
        'txn_total'        => 0,   // transactions processed
        'insp_created'     => 0,
        'insp_updated'     => 0,
        'insp_skipped'     => 0,   // no template for pid
        'result_created'   => 0,
        'bubble_unmatched' => 0,   // reading whose bubble isn't in the template
        'entity_linked'    => 0,   // inspection linked to an inv_item
        'txn_failed'       => 0,
    ];

    public function __construct(int $actorUserId)
    {
        $this->actorId = $actorUserId;
    }

    public function setProgressCallback(callable $cb): void
    {
        $this->onProgress = $cb;
    }

    private function emitProgress(string $phase, int $done, int $total): void
    {
        if ($this->onProgress) { ($this->onProgress)($phase, $done, $total); }
    }

    public function counts(): array
    {
        return $this->counts;
    }

    // ── Full run (no-JS fallback) — loops every transaction window ───────────
    public function run(): array
    {
        $this->buildLookupMaps();

        $txnOffset = 0;
        while (true) {
            $data = old_inventory_inspections_api('inspection_data_json', [
                'txn_offset' => $txnOffset,
                'txn_limit'  => self::TXN_BATCH,
            ]);
            $rows     = $data['rows'] ?? [];
            $txnCount = (int)($data['txn_count'] ?? 0);
            if ($txnCount === 0 || empty($rows)) { break; }

            foreach ($this->groupByTxn($rows) as $txnId => $txnRows) {
                $this->counts['row_total'] += count($txnRows);
                try {
                    $this->importTransaction((int)$txnId, $txnRows);
                } catch (Throwable $e) {
                    $this->counts['txn_failed']++;
                    $this->log("Transaction {$txnId} failed: " . $e->getMessage(), 'error');
                }
            }
            $this->emitProgress('inspections', $txnOffset + $txnCount, 0);

            $txnOffset += self::TXN_BATCH;
            if ($txnCount < self::TXN_BATCH) { break; }
        }

        $this->log(
            "Done — Inspections: created {$this->counts['insp_created']}, " .
            "updated {$this->counts['insp_updated']}, skipped (no template) {$this->counts['insp_skipped']}. " .
            "Results: {$this->counts['result_created']}. " .
            "Linked to item: {$this->counts['entity_linked']}. " .
            "Unmatched bubbles: {$this->counts['bubble_unmatched']}. " .
            "Failed: {$this->counts['txn_failed']}."
        );
        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    /** Group an ordered reading list into [transaction_id => rows]. */
    public function groupByTxn(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $tid = (int)($r['transaction_id'] ?? 0);
            if ($tid <= 0) { continue; }
            $groups[$tid][] = $r;
        }
        return $groups;
    }

    // ── Import one transaction = one inspection record ───────────────────────
    public function importTransaction(int $txnId, array $rows): void
    {
        $this->counts['txn_total']++;
        $first = $rows[0];
        $pid   = trim((string)($first['p_id'] ?? ''));

        $templateId = $this->templateByPid[$pid] ?? 0;
        if (!$templateId) {
            $this->counts['insp_skipped']++;
            $this->log("Transaction {$txnId}: no imported template for pid '{$pid}' (skipped).", 'warn');
            return;
        }
        $itemId = $this->itemIdByCode[$pid] ?? 0;

        // Event metadata across the transaction's readings.
        $sampleCount = 1;
        $inspDate    = '';
        $doneBy      = '';
        foreach ($rows as $r) {
            $sn = (int)($r['sample_number'] ?? 1);
            if ($sn > $sampleCount) { $sampleCount = $sn; }
            if ($inspDate === '') { $inspDate = $this->normalizeDate((string)($r['inspection_date'] ?? '')); }
            if ($doneBy === '')   { $doneBy   = trim((string)($r['done_by'] ?? '')); }
        }
        if ($inspDate === '') { $inspDate = date('Y-m-d'); }
        $recordedAt = $inspDate . ' 00:00:00';

        $bubbleMap = $this->bubbleMap($templateId);
        $code      = self::CODE_PREFIX . $txnId;
        $notes     = 'Imported from old inspection_data (transaction ' . $txnId . ').'
                   . ($doneBy !== '' ? ' Inspected by (legacy): ' . $doneBy . '.' : '');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $existing = db_one('SELECT id FROM inspections WHERE code = ?', [$code]);
            if ($existing) {
                $inspectionId = (int)$existing['id'];
                db_exec('DELETE FROM inspection_results WHERE inspection_id = ?', [$inspectionId]);
                $this->counts['insp_updated']++;
            } else {
                db_exec(
                    'INSERT INTO inspections
                       (code, inspection_type, entity_type, entity_id, template_id,
                        status, verdict_notes, planned_by, planned_at,
                        sample_count, pid, is_deleted)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
                    [$code, 'adhoc',
                     $itemId ? 'inv_item' : 'none', $itemId ?: null, $templateId,
                     'draft', $notes, $this->actorId, $recordedAt,
                     max(1, $sampleCount), $pid]
                );
                $inspectionId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
                $this->counts['insp_created']++;
                if ($itemId) { $this->counts['entity_linked']++; }
            }

            $anyFail = false; $anyPass = false;
            foreach ($rows as $r) {
                $ibn = trim((string)($r['insp_bubbleno'] ?? ''));
                $bn  = trim((string)($r['bubble_no'] ?? ''));
                $item = $bubbleMap[$ibn] ?? ($bubbleMap[$bn] ?? null);
                if ($item === null) {
                    $this->counts['bubble_unmatched']++;
                    continue;
                }

                $val = $r['data'];
                $val = ($val === null) ? null : trim((string)$val);
                $sno = (int)($r['sample_number'] ?? 1);
                if ($sno < 1) { $sno = 1; }

                $pf = $this->evaluate(
                    (string)$item['check_type'], $val,
                    $item['target_value'], $item['tolerance_lower'], $item['tolerance_upper']
                );
                if ($pf === 'fail')      { $anyFail = true; }
                elseif ($pf === 'pass')  { $anyPass = true; }

                db_exec(
                    'INSERT INTO inspection_results
                       (inspection_id, sample_no, template_item_id, sort_order,
                        label, bubble_no, gdt_symbol, check_type,
                        target_value, tolerance_lower, tolerance_upper, unit,
                        measured_value, pass_fail, recorded_by, recorded_at, instrument_asset_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$inspectionId, $sno, (int)$item['id'], (int)$item['sort_order'],
                     $item['label'], $item['bubble_no'] ?? null, $item['gdt_symbol'] ?? null,
                     $item['check_type'], $item['target_value'],
                     $item['tolerance_lower'], $item['tolerance_upper'], $item['unit'],
                     ($val === '' ? null : $val), $pf,
                     $this->actorId, $recordedAt, $item['instrument_asset_id'] ?? null]
                );
                $this->counts['result_created']++;
            }

            $status = $anyFail ? 'failed' : ($anyPass ? 'passed' : 'in_progress');
            db_exec(
                'UPDATE inspections
                    SET status = ?, sample_count = ?, template_id = ?,
                        entity_type = ?, entity_id = ?, verdict_notes = ?,
                        inspected_by = ?, inspected_at = ?
                  WHERE id = ?',
                [$status, max(1, $sampleCount), $templateId,
                 $itemId ? 'inv_item' : 'none', $itemId ?: null, $notes,
                 $this->actorId, $recordedAt, $inspectionId]
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** template_id → (bubble_no → item row), built and cached on first use. */
    private function bubbleMap(int $templateId): array
    {
        if (isset($this->bubbleCache[$templateId])) {
            return $this->bubbleCache[$templateId];
        }
        $map = [];
        $items = db_all(
            'SELECT id, sort_order, label, bubble_no, gdt_symbol, check_type,
                    target_value, tolerance_lower, tolerance_upper, unit, instrument_asset_id
               FROM inspection_template_items
              WHERE template_id = ? ORDER BY sort_order, id',
            [$templateId]
        );
        foreach ($items as $it) {
            $key = trim((string)($it['bubble_no'] ?? ''));
            if ($key === '') { continue; }
            // First item wins for a given bubble number (templates rarely repeat).
            if (!isset($map[$key])) { $map[$key] = $it; }
        }
        return $this->bubbleCache[$templateId] = $map;
    }

    /**
     * Compute pass/fail for one reading. Mirrors the live execute() logic:
     * numeric/nominal types auto-evaluate against the spec; logic reads a
     * pass/fail token; notes are n/a; everything else stays pending until a
     * value can be interpreted.
     */
    private function evaluate(string $checkType, ?string $val, $target, $lower, $upper): string
    {
        if ($val === null || $val === '') { return 'pending'; }

        if (in_array($checkType, ['numeric', 'nom', 'logical-nom'], true)) {
            list($min, $max) = $this->minMaxNom($target, $lower, $upper);
            return $this->evalNumeric($val, $min, $max);
        }
        if (in_array($checkType, ['min-max', 'logical-min-max'], true)) {
            $min = is_numeric($lower) ? (float)$lower : null;
            $max = is_numeric($upper) ? (float)$upper : null;
            return $this->evalNumeric($val, $min, $max);
        }
        if ($checkType === 'notes') { return 'na'; }
        if ($checkType === 'logic') {
            $t = strtolower(trim($val));
            if ($t === 'ok' || $t === 'pass') { return 'pass'; }
            if ($t === 'fail' || strpos($t, 'not ok') === 0 || strpos($t, 'ng') !== false) { return 'fail'; }
            return 'na';
        }
        // boolean / text / visual — recognise ok/pass/fail text, else n/a.
        $t = strtolower(trim($val));
        if ($t === 'ok' || $t === 'pass') { return 'pass'; }
        if ($t === 'fail' || strpos($t, 'not ok') === 0 || strpos($t, 'ng') !== false) { return 'fail'; }
        return 'na';
    }

    /** target ± offset semantics (min = target − lower, max = target + upper). */
    private function minMaxNom($target, $lower, $upper): array
    {
        if ($target === null || $target === '' || !is_numeric($target)) { return [null, null]; }
        $t = (float)$target;
        $min = ($lower !== null && $lower !== '' && is_numeric($lower)) ? $t - (float)$lower : null;
        $max = ($upper !== null && $upper !== '' && is_numeric($upper)) ? $t + (float)$upper : null;
        return [$min, $max];
    }

    private function evalNumeric(?string $value, $min, $max): string
    {
        if ($value === null || $value === '') { return 'na'; }
        if (!is_numeric($value)) {
            $t = strtolower(trim($value));
            if ($t === 'ok' || $t === 'pass') { return 'pass'; }
            if ($t === 'fail' || strpos($t, 'not ok') === 0 || strpos($t, 'ng') !== false) { return 'fail'; }
            return 'na';
        }
        if (($min === null) && ($max === null)) { return 'na'; }
        $v = (float)$value;
        if ($min !== null && $v < (float)$min) { return 'fail'; }
        if ($max !== null && $v > (float)$max) { return 'fail'; }
        return 'pass';
    }

    /** Coerce a legacy date to a valid 'Y-m-d', or '' when unusable. */
    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0000-00-00' || strcasecmp($raw, 'null') === 0) { return ''; }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    // ── Build lookups once up front (idempotent) ─────────────────────────────
    public function buildLookupMaps(): void
    {
        if ($this->mapsBuilt) { return; }
        $this->mapsBuilt = true;

        $pfx = self::TPL_PREFIX;
        $cut = strlen($pfx);
        foreach (db_all('SELECT id, code FROM inspection_templates WHERE code LIKE ?', [$pfx . '%']) as $r) {
            $pid = substr((string)$r['code'], $cut);
            if ($pid !== '') { $this->templateByPid[$pid] = (int)$r['id']; }
        }
        foreach (db_all('SELECT id, code FROM inv_items') as $r) {
            $this->itemIdByCode[(string)$r['code']] = (int)$r['id'];
        }
        $this->log('Lookup maps built — imported templates: ' . count($this->templateByPid)
            . ', inv_items: ' . count($this->itemIdByCode) . '.');
    }

    private function log(string $message, string $level = 'info'): void
    {
        $this->errors[] = [
            'level'   => $level,
            'message' => $message,
            'time'    => date('H:i:s'),
        ];
    }
}
