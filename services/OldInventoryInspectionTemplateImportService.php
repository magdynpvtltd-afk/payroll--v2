<?php
/**
 * MagDyn — Old Inventory Inspection Template Import Service (API version)
 *
 * Fetches the legacy `inspection` table from the old inventory system via
 * api_export_inspections.php and turns it into MagDyn inspection templates.
 *
 * Grouping (old → new):
 *   Every legacy `inspection` row carries a `pid` (= legacy
 *   inventory_model_id, which is also the new inv_items.code). All rows
 *   sharing a pid collapse into ONE inspection_templates row, with one
 *   inspection_template_items row per legacy inspection row, and a
 *   inspection_template_targets link to the inv_item whose code = pid.
 *
 * Template identity:
 *   name  = <inv_items.short_description> . ' Template'
 *           (falls back to the item name, then the legacy model
 *            short_description / ProductName when the pid can't be
 *            resolved to a current inv_item).
 *   code  = 'OINS-' . pid     — a stable, per-product code so a re-run
 *           UPDATES the same template (items + target rebuilt) instead of
 *           duplicating, and "Delete imported templates" can target them
 *           with a single LIKE 'OINS-%'.
 *
 * Per-item field mapping (old → new inspection_template_items):
 *   Parametername / ProcessStep / HowMeasured → label
 *   BubbleNo                                   → bubble_no
 *   unitofmeasured                             → unit
 *   stepno                                     → sort_order
 *   toltype + NomValue                         → check_type:
 *       'notes'           → notes
 *       'logic'           → logic   (operator picks pass/fail)
 *       'min/max'/'min-max' → min-max (legacy minimum/maximum → bounds)
 *       'nom' / numeric NomValue → nom (target ± Tolneg/Tolpos)
 *       NomValue 'NA'/blank + visual method → visual
 *       otherwise         → text
 *   minimum / maximum            → tolerance_lower / tolerance_upper
 *                                  (min-max checks — lower IS min, upper IS max)
 *   NomValue / Tolneg / Tolpos → target_value / tolerance_lower / upper
 *                                (nom checks only)
 *   notes                      → notes (dedicated template-item field)
 *   HowMeasured / ProcessStep / DrawingNo+Rev / description
 *                              → folded into the item description text
 *
 * Re-running UPDATES templates keyed by code 'OINS-<pid>' (items + target
 * are deleted and rebuilt). Use the "Delete imported templates" button for
 * a from-scratch reset.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/OldInventoryInspectionTemplateImportService.php';
 *   $svc    = new OldInventoryInspectionTemplateImportService(current_user_id());
 *   $result = $svc->run();
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryInspectionTemplateImportService
{
    /** Records per API page */
    private const BATCH_SIZE = 500;

    /** Imported-template code prefix (also the Delete-all LIKE target). */
    public const CODE_PREFIX = 'OINS-';

    /** Valid new-system check types (the enum on inspection_template_items). */
    private const VALID_CHECK_TYPES = [
        'numeric', 'boolean', 'text', 'visual', 'nom', 'min-max',
        'logic', 'logical-min-max', 'logical-nom', 'notes',
    ];

    /** @var int  User credited as creator of imported templates */
    private int $actorId;

    /** @var array<string,array{id:int,short:?string,name:string}>  inv_items.code → row */
    private array $itemByCode = [];

    /** @var bool  Guards buildLookupMaps() so it runs only once per instance. */
    private bool $mapsBuilt = false;

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var callable|null  fn(string $phase, int $done, int $total) */
    private $onProgress = null;

    /** @var array */
    private array $counts = [
        'row_total'         => 0,   // source inspection rows seen
        'pid_total'         => 0,   // distinct pids (= templates targeted)
        'pid_matched'       => 0,   // pids resolved to an inv_items.code
        'pid_unmatched'     => 0,   // pids with no current inv_item
        'tpl_created'       => 0,
        'tpl_updated'       => 0,
        'item_created'      => 0,
        'target_linked'     => 0,
        'pid_failed'        => 0,
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
        if ($this->onProgress) {
            ($this->onProgress)($phase, $done, $total);
        }
    }

    public function run(): array
    {
        $this->buildLookupMaps();

        $rows = $this->fetchAll();
        $this->counts['row_total'] = count($rows);
        $this->log(count($rows) . ' inspection rows fetched from source.');

        // Group by pid (preserving fetch order within each group).
        $groups = [];
        foreach ($rows as $r) {
            $pid = trim((string)($r['pid'] ?? ''));
            if ($pid === '') { continue; }
            $groups[$pid][] = $r;
        }
        $this->counts['pid_total'] = count($groups);
        $this->log('Grouped into ' . count($groups) . ' product templates (by pid).');

        $done  = 0;
        $total = count($groups);
        $this->emitProgress('templates', 0, $total);
        foreach ($groups as $pid => $groupRows) {
            try {
                $this->importTemplate((string)$pid, $groupRows);
            } catch (Throwable $e) {
                $this->counts['pid_failed']++;
                $this->log("Template for pid '{$pid}' failed: " . $e->getMessage(), 'error');
            }
            $this->emitProgress('templates', ++$done, $total);
        }

        $this->log(
            "Done — Templates: created {$this->counts['tpl_created']}, " .
            "updated {$this->counts['tpl_updated']}. " .
            "Items: {$this->counts['item_created']}. " .
            "Targets linked: {$this->counts['target_linked']}. " .
            "pids matched: {$this->counts['pid_matched']}, " .
            "unmatched: {$this->counts['pid_unmatched']}, " .
            "failed: {$this->counts['pid_failed']}."
        );

        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    // ── Fetch the whole inspection table (all pages) ─────────────────────────
    private function fetchAll(): array
    {
        $all    = [];
        $offset = 0;
        while (true) {
            $data  = old_inventory_inspections_api('inspections_json', [
                'offset' => $offset,
                'limit'  => self::BATCH_SIZE,
            ]);
            $batch = $data['rows'] ?? [];
            if (empty($batch)) {
                break;
            }
            foreach ($batch as $r) {
                $all[] = $r;
            }
            $offset += self::BATCH_SIZE;
            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
        }
        return $all;
    }

    /** Accumulated counts (running tally). Read by the chunked driver. */
    public function counts(): array
    {
        return $this->counts;
    }

    // ── Fetch just ONE product's rows (for a single-template restore) ─────────
    /**
     * Fetch every legacy `inspection` row for a single pid, used by the
     * "Restore one template's bubbles" button. Passes the pid to the API as
     * an optimization; older deployed endpoints ignore unknown params and
     * return the full paginated set, so we ALSO filter client-side. Because
     * inspections_json orders by CAST(pid AS UNSIGNED) ASC, once the scan
     * passes a numeric target pid we can stop early instead of walking the
     * whole table.
     *
     * Returns the matching rows in source order (possibly empty).
     */
    public function fetchRowsForPid(string $pid): array
    {
        $target    = trim($pid);
        $targetNum = ctype_digit($target) ? (int)$target : null;
        $collected = [];
        $offset    = 0;

        while (true) {
            $data  = old_inventory_inspections_api('inspections_json', [
                'offset' => $offset,
                'limit'  => self::BATCH_SIZE,
                'pid'    => $target,   // honored by updated endpoints; ignored by old ones
            ]);
            $batch = $data['rows'] ?? [];
            if (empty($batch)) {
                break;
            }

            $sawBeyond = false;
            foreach ($batch as $r) {
                $rp = trim((string)($r['pid'] ?? ''));
                if ($rp === $target) {
                    $collected[] = $r;
                }
                if ($targetNum !== null && ctype_digit($rp) && (int)$rp > $targetNum) {
                    $sawBeyond = true;
                }
            }

            // Last page reached, or (server didn't filter) we've scanned past
            // the target pid in the ascending ordering — nothing more to find.
            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
            if ($sawBeyond) {
                break;
            }
            $offset += self::BATCH_SIZE;
        }

        return $collected;
    }

    // ── Import one product's rows as a single template ───────────────────────
    // Public so the chunked AJAX driver in inspection.php can feed it one
    // complete pid group at a time (the full-table run() loops over it too).
    public function importTemplate(string $pid, array $rows): void
    {
        $first   = $rows[0];
        $item    = $this->itemByCode[$pid] ?? null;
        $matched = $item !== null;
        if ($matched) { $this->counts['pid_matched']++; }
        else          { $this->counts['pid_unmatched']++; }

        // Template name: "<short description> Template".
        $baseName = '';
        if ($matched) {
            $baseName = trim((string)($item['short'] ?? '')) ?: trim((string)($item['name'] ?? ''));
        }
        if ($baseName === '') {
            $baseName = trim((string)($first['model_short'] ?? ''))
                     ?: trim((string)($first['ProductName'] ?? ''));
        }
        if ($baseName === '') { $baseName = 'Product ' . $pid; }
        $templateName = mb_substr($baseName . ' Template', 0, 200);

        $code = self::CODE_PREFIX . $pid;

        $description = 'Imported from old inventory `inspection` table (pid ' . $pid . ').';
        if (!$matched) {
            $description .= ' No current inv_item matched this pid — not linked.';
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $existing = db_one('SELECT id FROM inspection_templates WHERE code = ?', [$code]);
            if ($existing) {
                $templateId = (int)$existing['id'];
                db_exec(
                    'UPDATE inspection_templates
                        SET name = ?, description = ?, is_active = 1
                      WHERE id = ?',
                    [$templateName, mb_substr($description, 0, 60000), $templateId]
                );
                db_exec('DELETE FROM inspection_template_items   WHERE template_id = ?', [$templateId]);
                db_exec('DELETE FROM inspection_template_targets WHERE template_id = ?', [$templateId]);
                $this->counts['tpl_updated']++;
            } else {
                db_exec(
                    'INSERT INTO inspection_templates
                       (code, name, description, inspection_type, is_active, created_by)
                     VALUES (?, ?, ?, NULL, 1, ?)',
                    [$code, $templateName, mb_substr($description, 0, 60000), $this->actorId]
                );
                $templateId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
                $this->counts['tpl_created']++;
            }

            $sort = 0;
            foreach ($rows as $r) {
                $this->importItem($templateId, $r, $sort++);
            }

            // Link the template to the inv_item whose code = pid.
            if ($matched) {
                db_exec(
                    "INSERT IGNORE INTO inspection_template_targets
                        (template_id, entity_type, entity_id)
                     VALUES (?, 'inv_item', ?)",
                    [$templateId, (int)$item['id']]
                );
                $this->counts['target_linked']++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ── Map one legacy inspection row to a template item ─────────────────────
    private function importItem(int $templateId, array $r, int $fallbackSort): void
    {
        $param   = trim((string)($r['Parametername'] ?? ''));
        $step    = trim((string)($r['ProcessStep'] ?? ''));
        $how     = trim((string)($r['HowMeasured'] ?? ''));
        $bubble  = trim((string)($r['BubbleNo'] ?? ''));

        // Label — prefer the dimension/parameter name, then the process step,
        // then the measuring method, finally a bubble-numbered placeholder.
        $label = $param;
        if ($label === '') { $label = $step; }
        if ($label === '') { $label = $how; }
        if ($label === '') { $label = $bubble !== '' ? ('Characteristic ' . $bubble) : 'Characteristic'; }
        $label = mb_substr($label, 0, 200);

        $nom     = trim((string)($r['NomValue'] ?? ''));
        $tolNeg  = trim((string)($r['Tolneg'] ?? ''));
        $tolPos  = trim((string)($r['Tolpos'] ?? ''));
        $minVal  = trim((string)($r['minimum'] ?? ''));
        $maxVal  = trim((string)($r['maximum'] ?? ''));
        $toltype = strtolower(trim((string)($r['toltype'] ?? '')));

        $nomIsNumeric = ($nom !== '' && strcasecmp($nom, 'NA') !== 0 && is_numeric($nom));

        // Resolve check_type from the legacy toltype. The old data stores the
        // min/max kind as 'min/max' (slash form, the common case) or 'min-max'.
        $isMinMax = ($toltype === 'min/max' || $toltype === 'min-max' || $toltype === 'minmax');

        if ($toltype === 'notes') {
            $checkType = 'notes';
        } elseif ($toltype === 'logic') {
            $checkType = 'logic';
        } elseif ($isMinMax) {
            // Carry the legacy minimum/maximum columns as the bounds.
            $checkType = 'min-max';
        } elseif ($toltype === 'nom' || $nomIsNumeric) {
            $checkType = 'nom';
        } elseif (stripos($how, 'visual') !== false || strcasecmp($param, 'Visual') === 0) {
            $checkType = 'visual';
        } else {
            $checkType = 'text';
        }
        if (!in_array($checkType, self::VALID_CHECK_TYPES, true)) {
            $checkType = 'text';
        }

        // Numeric spec — carried for nominal- and min/max-style checks.
        $target = null; $lower = null; $upper = null;
        if ($checkType === 'min-max') {
            // MIN-MAX semantics: tolerance_lower IS the min, tolerance_upper IS
            // the max (from the legacy `minimum` / `maximum` columns).
            if ($minVal !== '' && is_numeric($minVal)) { $lower = (float)$minVal; }
            if ($maxVal !== '' && is_numeric($maxVal)) { $upper = (float)$maxVal; }
        } elseif ($checkType === 'nom' && $nomIsNumeric) {
            $target = (float)$nom;
            if ($tolNeg !== '' && is_numeric($tolNeg)) { $lower = (float)$tolNeg; }
            if ($tolPos !== '' && is_numeric($tolPos)) { $upper = (float)$tolPos; }
        }

        $unit = mb_substr(trim((string)($r['unitofmeasured'] ?? '')), 0, 30) ?: null;
        $bb   = $bubble !== '' ? mb_substr($bubble, 0, 8) : null;

        // sort_order from stepno when numeric, else the fetch sequence.
        $stepno = trim((string)($r['stepno'] ?? ''));
        $sort   = ($stepno !== '' && ctype_digit($stepno)) ? (int)$stepno : $fallbackSort;

        $description = $this->buildItemDescription($r, $how, $step);

        // Legacy `notes` lands in the dedicated template-item notes field (it
        // is NOT folded into the description blob — see buildItemDescription).
        $notes = trim((string)($r['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;

        db_exec(
            'INSERT INTO inspection_template_items
               (template_id, sort_order, label, bubble_no, gdt_symbol, description,
                notes, check_type, target_value, tolerance_lower, tolerance_upper,
                unit, is_required)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$templateId, $sort, $label, $bb,
             $description !== '' ? $description : null, $notes,
             $checkType, $target, $lower, $upper, $unit]
        );
        $this->counts['item_created']++;
    }

    /**
     * Fold the descriptive legacy fields into one item description blob.
     * The legacy `notes` column is intentionally NOT included here — it now
     * has its own dedicated template-item `notes` field (see importItem()).
     */
    private function buildItemDescription(array $r, string $how, string $step): string
    {
        $parts = [];
        if ($how !== '')  { $parts[] = 'Measured by: ' . $how; }
        if ($step !== '') { $parts[] = 'Process: ' . $step; }

        $dwg = trim((string)($r['DrawingNo'] ?? ''));
        $rev = trim((string)($r['Rev'] ?? ''));
        if ($dwg !== '') { $parts[] = 'Drawing: ' . $dwg . ($rev !== '' ? (' Rev ' . $rev) : ''); }

        $mat = trim((string)($r['materialspec'] ?? ''));
        if ($mat !== '') { $parts[] = 'Material: ' . $mat; }

        $notes = trim((string)($r['notes'] ?? ''));
        $legacyDesc = trim((string)($r['description'] ?? ''));
        if ($legacyDesc !== '' && $legacyDesc !== '.' && strcasecmp($legacyDesc, $notes) !== 0) {
            $parts[] = $legacyDesc;
        }

        return trim(implode(' · ', $parts));
    }

    // ── Build the inv_items.code → row lookup once up front ──────────────────
    // Public + idempotent so the chunked driver can call it per chunk cheaply.
    public function buildLookupMaps(): void
    {
        if ($this->mapsBuilt) { return; }
        $this->mapsBuilt = true;
        foreach (db_all('SELECT id, code, short_description, name FROM inv_items') as $r) {
            $this->itemByCode[(string)$r['code']] = [
                'id'    => (int)$r['id'],
                'short' => $r['short_description'] !== null ? (string)$r['short_description'] : null,
                'name'  => (string)$r['name'],
            ];
        }
        $this->log('Lookup map built — inv_items: ' . count($this->itemByCode) . '.');
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
