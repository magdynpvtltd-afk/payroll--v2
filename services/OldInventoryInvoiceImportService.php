<?php
/**
 * MagDyn — Old Inventory Invoice Import Service (API version)
 *
 * Fetches the legacy purchase-invoice tables from the old inventory system
 * via api_export_invoices.php and imports them into the MagDyn `invoices`
 * (+ `invoice_items`, `invoice_lines`) tables.
 *
 * Sources (old → new):
 *   approveinv  — entered, not yet approved/linked   → invoices.status = 'pending'
 *   recp_inv    — approved & linked to a transaction → invoices.status = 'approved'
 *
 * Both source tables are LINE-LEVEL (one row per invoice line). Many rows
 * share a single `inv_no`; we group them back into one invoice header
 * (keyed by source + inv_no + vendor + financial year) with N line items.
 *
 * Field resolution (old → new):
 *   companyname            → vendor: matched on vendors.name (case-insensitive).
 *                            When companyname is blank (older recp_inv rows),
 *                            the vendor is taken from the shipment the line's
 *                            trans_id links to. Invoices whose vendor can't be
 *                            resolved are skipped and logged.
 *   product_id             → invoice line code. product_id IS the legacy
 *                            inventory_model_id, and the new inv_items.code is
 *                            that same id — so we match product_id directly
 *                            against inv_items.code and adopt the current name.
 *                            When product_id is 0 / the "Misc No PID" placeholder
 *                            (1217) / unresolved, we fall back to a synthetic
 *                            code (OLD-P-<product_id>) + the legacy productname.
 *   class 'A' / 'P'        → invoice_items.item_kind 'asset' / 'inv_item'.
 *   trans_id               → linkage to a shipment. trans_id maps to
 *                            inv_shipment_lines.old_transaction_id. When that
 *                            shipment line has an inv_receipt, we create the
 *                            formal invoice_lines link; otherwise the trans_id
 *                            is recorded on the line note (OLD-TRANS-<id>) so a
 *                            link can be attached once receipts are generated.
 *   qty/unit_price/gst/uom/hsn_code → copied onto the invoice line.
 *
 * Re-running this import is an UPSERT: an invoice already present (matched by
 * its natural identity — base invoice_no + vendor + financial year) is updated
 * in place (header refreshed, line items replaced); anything new is created.
 * Use "Delete All Invoices" only when you want a from-scratch reload.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/OldInventoryInvoiceImportService.php';
 *   $svc    = new OldInventoryInvoiceImportService(current_user_id());
 *   $result = $svc->run();
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryInvoiceImportService
{
    /** Records per API page */
    private const BATCH_SIZE = 500;

    /** The "Misc / no product id" placeholder inventory_model_id — treat as no code. */
    private const MISC_MODEL_ID = 1217;

    /** @var int  User credited as creator / approver of imported rows */
    private int $actorId;

    /** @var array<string,string>  inv_items.code → inv_items.name */
    private array $itemMap = [];

    /** @var array<string,int>  lower(vendors.name) → vendors.id */
    private array $vendorByName = [];

    /**
     * old_transaction_id → [
     *   'vendor_id' => ?int,                       // first shipment vendor seen
     *   'lines'     => [ ['sl_id'=>int,'ship_id'=>int,'kind'=>string,
     *                     'code'=>?string,'receipt_id'=>?int,
     *                     'qty_planned'=>float,'qty_received'=>float], ... ],
     * ]
     * Built from inv_shipment_lines (+ shipment vendor, item code, any existing
     * inv_receipt on the line). One legacy txn can fan out to several lines.
     */
    private array $txnMap = [];

    /** @var array<int,int>  inv_shipment_lines.id → inv_receipts.id (anchor cache) */
    private array $receiptCache = [];

    /** @var int  Default destination location for auto-created receipt anchors */
    private int $defaultLocationId = 0;

    /** @var array<string,bool>  invoice_no values already taken (DB + this run) */
    private array $usedNos = [];

    /**
     * Natural-identity → invoice_id for already-imported (or matching)
     * invoices, so a re-run UPDATES in place instead of creating duplicates.
     * Key is lower(base invoice_no) | vendor_id | fy  — see importIdentity().
     * Seeded from the DB up front and kept current as new rows are inserted.
     *
     * @var array<string,int>
     */
    private array $existingByIdentity = [];

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var callable|null  fn(string $phase, int $done, int $total) */
    private $onProgress = null;

    /** @var array */
    private array $counts = [
        'row_total'         => 0,   // source line rows seen
        'inv_created'       => 0,   // invoice headers created
        'inv_updated'       => 0,   // existing invoice headers updated in place
        'inv_pending'       => 0,
        'inv_approved'      => 0,
        'inv_skipped'       => 0,   // groups skipped (no vendor)
        'item_created'      => 0,
        'item_resolved'     => 0,   // line code resolved to a real inv_items.code
        'item_synthetic'    => 0,   // line fell back to OLD-P-<id> + legacy name
        'link_created'      => 0,   // invoice_lines rows written
        'receipt_created'   => 0,   // inv_receipts anchors auto-created for links
        'txn_shipline_hit'  => 0,   // trans_id matched a shipment line (→ linked)
        'txn_no_match'      => 0,   // trans_id present but no shipment line
        'row_failed'        => 0,
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

        // approveinv → pending, recp_inv → approved.
        foreach (['approveinv' => 'pending', 'recp_inv' => 'approved'] as $src => $status) {
            $rows = $this->fetchAll($src);
            $this->counts['row_total'] += count($rows);
            $this->log(ucfirst($src) . ": " . count($rows) . " line rows fetched from source.");
            $groups = $this->groupRows($rows, $src);
            $this->log(ucfirst($src) . ': grouped into ' . count($groups) . ' invoices.');

            $done  = 0;
            $total = count($groups);
            $this->emitProgress($src, 0, $total);
            foreach ($groups as $g) {
                try {
                    $this->importInvoice($g, $status);
                } catch (Throwable $e) {
                    $this->counts['row_failed']++;
                    $this->log("Invoice '{$g['inv_no']}' ({$src}) failed: " . $e->getMessage(), 'error');
                }
                $this->emitProgress($src, ++$done, $total);
            }
        }

        $this->log(
            "Done — Invoices created: {$this->counts['inv_created']}, updated: {$this->counts['inv_updated']} " .
            "(pending: {$this->counts['inv_pending']}, approved: {$this->counts['inv_approved']}, " .
            "skipped no-vendor: {$this->counts['inv_skipped']}). " .
            "Items: {$this->counts['item_created']} " .
            "(resolved: {$this->counts['item_resolved']}, synthetic: {$this->counts['item_synthetic']}). " .
            "Links: {$this->counts['link_created']} via {$this->counts['receipt_created']} receipt anchors " .
            "(trans matched shipment line: {$this->counts['txn_shipline_hit']}, " .
            "no shipment match: {$this->counts['txn_no_match']})."
        );

        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    // ── Fetch one whole source table (all pages) ─────────────────────────────
    private function fetchAll(string $src): array
    {
        $all    = [];
        $offset = 0;
        while (true) {
            $data  = old_inventory_invoices_api('invoices_json', [
                'src'    => $src,
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

    // ── Group line rows into invoice headers ─────────────────────────────────
    private function groupRows(array $rows, string $src): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $invNo    = trim((string)($r['inv_no'] ?? ''));
            if ($invNo === '') { $invNo = 'NO-NUMBER'; }
            $vendorId = $this->resolveVendor($r);
            $fy       = trim((string)($r['financialyear'] ?? ''));

            // Key on source + number + vendor + FY so reused numbers across
            // vendors / years don't collapse into one another.
            $key = $src . '|' . $invNo . '|' . ($vendorId ?: ('c' . (string)($r['company_id'] ?? 0))) . '|' . $fy;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'src'       => $src,
                    'inv_no'    => $invNo,
                    'vendor_id' => $vendorId,
                    'fy'        => $fy,
                    'rows'      => [],
                ];
            }
            // First non-null vendor for the group wins as header vendor.
            if (!$groups[$key]['vendor_id'] && $vendorId) {
                $groups[$key]['vendor_id'] = $vendorId;
            }
            $groups[$key]['rows'][] = $r;
        }
        return array_values($groups);
    }

    // ── Import one grouped invoice ───────────────────────────────────────────
    private function importInvoice(array $g, string $status): void
    {
        $vendorId = (int)($g['vendor_id'] ?? 0);
        if ($vendorId <= 0) {
            $this->counts['inv_skipped']++;
            $first = $g['rows'][0] ?? [];
            $this->log(
                "Skipped invoice '{$g['inv_no']}' ({$g['src']}): vendor could not be resolved " .
                "(companyname='" . trim((string)($first['companyname'] ?? '')) . "').",
                'warn'
            );
            return;
        }

        $rows    = $g['rows'];
        $first   = $rows[0];
        $invDate = $this->normalizeDate((string)($first['inv_date'] ?? ''));

        // Adopt the legacy reference number verbatim. '0' / blank are the
        // old system's "no ref" placeholders → store NULL.
        $refno = trim((string)($first['refno'] ?? ''));
        if ($refno === '' || $refno === '0') { $refno = null; }
        else { $refno = substr($refno, 0, 32); }

        // FY + Department now land in dedicated columns (invoices.fy / .dept)
        // instead of being stuffed into the notes blob. Ledger is per-line
        // (set in importLine). Both are taken verbatim from the legacy row —
        // '0' / blank are the old system's "unset" placeholders → NULL.
        $fy   = trim((string)($g['fy'] ?? ''));
        $fy   = ($fy === '' || $fy === '0') ? null : substr($fy, 0, 16);
        $dept = trim((string)($first['department'] ?? ''));
        $dept = ($dept === '' || $dept === '0') ? null : substr($dept, 0, 64);

        $headerNotes = 'Imported from old ' . $g['src'] . '.';

        // Upsert: does an invoice with this natural identity already exist
        // (from a prior run, the other source table, or an earlier group in
        // THIS run)? If so update it in place; otherwise create a fresh one.
        $identity   = $this->importIdentity((string)$g['inv_no'], $vendorId, (string)($g['fy'] ?? ''));
        $existingId = $this->existingByIdentity[$identity] ?? 0;
        $isUpdate   = $existingId > 0;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($isUpdate) {
                $invoiceId = $existingId;
                // Refresh the header from the latest source values. Keep the
                // stored invoice_no as-is (it's the matched row's number).
                if ($status === 'approved') {
                    db_exec(
                        'UPDATE invoices
                            SET refno = ?, invoice_date = ?, vendor_id = ?, currency = ?,
                                status = ?, notes = ?, fy = ?, dept = ?,
                                approved_by = ?, approved_at = NOW(), rejection_reason = NULL
                          WHERE id = ?',
                        [$refno, $invDate, $vendorId, 'INR', 'approved', $headerNotes, $fy, $dept,
                         $this->actorId, $invoiceId]
                    );
                } else {
                    db_exec(
                        'UPDATE invoices
                            SET refno = ?, invoice_date = ?, vendor_id = ?, currency = ?,
                                status = ?, notes = ?, fy = ?, dept = ?,
                                approved_by = NULL, approved_at = NULL, rejection_reason = NULL
                          WHERE id = ?',
                        [$refno, $invDate, $vendorId, 'INR', 'pending', $headerNotes, $fy, $dept, $invoiceId]
                    );
                }
                // Replace the line items wholesale (drop their txn links first).
                db_exec(
                    'DELETE il FROM invoice_lines il
                       JOIN invoice_items ii ON ii.id = il.invoice_item_id
                      WHERE ii.invoice_id = ?',
                    [$invoiceId]
                );
                db_exec('DELETE FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
            } else {
                $invoiceNo = $this->uniqueInvoiceNo((string)$g['inv_no']);
                if ($status === 'approved') {
                    db_exec(
                        'INSERT INTO invoices
                           (invoice_no, refno, invoice_date, vendor_id, currency, status, notes, fy, dept,
                            created_by, approved_by, approved_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                        [$invoiceNo, $refno, $invDate, $vendorId, 'INR', 'approved', $headerNotes, $fy, $dept,
                         $this->actorId, $this->actorId]
                    );
                } else {
                    db_exec(
                        'INSERT INTO invoices
                           (invoice_no, refno, invoice_date, vendor_id, currency, status, notes, fy, dept, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$invoiceNo, $refno, $invDate, $vendorId, 'INR', 'pending', $headerNotes, $fy, $dept, $this->actorId]
                    );
                }
                $invoiceId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
                // Register so later groups / the other source pass update this
                // same invoice rather than inserting another copy.
                $this->existingByIdentity[$identity] = $invoiceId;
            }

            $sort = 0;
            foreach ($rows as $r) {
                $this->importLine($invoiceId, $r, $sort++);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        if ($isUpdate) { $this->counts['inv_updated']++; }
        else           { $this->counts['inv_created']++; }
        if ($status === 'approved') { $this->counts['inv_approved']++; }
        else                        { $this->counts['inv_pending']++; }
    }

    // ── Import one line item (+ optional link) ───────────────────────────────
    private function importLine(int $invoiceId, array $r, int $sort): void
    {
        $class     = strtoupper(trim((string)($r['class'] ?? '')));
        $itemKind  = ($class === 'A') ? 'asset' : 'inv_item';
        $productId = (int)($r['product_id'] ?? 0);
        $legacyNm  = trim((string)($r['productname'] ?? ''));
        $modelNm   = trim((string)($r['model_name'] ?? ''));

        // Code resolution: product_id IS the inventory_model_id, and the new
        // inv_items.code equals that id. Match directly. The "Misc No PID"
        // placeholder (1217) and 0 mean "no real product" → synthetic + name.
        $code = '';
        $desc = '';
        if ($itemKind === 'inv_item'
            && $productId > 0
            && $productId !== self::MISC_MODEL_ID
            && isset($this->itemMap[(string)$productId])) {
            $code = (string)$productId;
            $desc = $this->itemMap[(string)$productId];   // current authoritative name
            $this->counts['item_resolved']++;
        } else {
            // Synthetic code + real (legacy) name.
            $prefix = ($itemKind === 'asset') ? 'OLD-A-' : 'OLD-P-';
            $code   = $productId > 0 ? ($prefix . $productId) : ($prefix . 'NA');
            $desc   = $legacyNm !== '' ? $legacyNm : ($modelNm !== '' ? $modelNm : 'Legacy item');
            $this->counts['item_synthetic']++;
        }

        $qty   = (float)($r['qty'] ?? 0);
        if ($qty <= 0) { $qty = 1; }
        $price = (float)($r['unit_price'] ?? 0);
        $uom   = trim((string)($r['uom'] ?? '')) ?: 'pcs';
        $gstR  = ($r['gst'] ?? null);
        $gst   = ($gstR === null || $gstR === '') ? null : (float)$gstR;
        $hsn   = trim((string)($r['hsn_code'] ?? ''));
        $hsn   = ($hsn === '' || $hsn === '-') ? null : $hsn;
        // Ledger is per-line in the legacy data → invoice_items.ledger.
        $ledger = trim((string)($r['ledger'] ?? ''));
        $ledger = ($ledger === '' || $ledger === '0') ? null : substr($ledger, 0, 64);

        // Resolve trans_id → the best matching shipment line for linkage.
        // trans_id is the "Txn ID" shown in the shipment list
        // (inv_shipment_lines.old_transaction_id). Prefer a 'receive' line,
        // and among those prefer one whose item code matches this line.
        $transId  = (int)($r['trans_id'] ?? 0);
        $slLine   = ($transId > 0) ? $this->pickShipmentLine($transId, $code) : null;
        $lineNote = trim((string)($r['notes'] ?? ''));
        if ($transId > 0) {
            $this->counts[$slLine ? 'txn_shipline_hit' : 'txn_no_match']++;
            $tag = 'OLD-TRANS-' . $transId . ($slLine ? '' : ' (no shipment match)');
            $lineNote = $lineNote === '' ? $tag : ($lineNote . ' | ' . $tag);
        }

        db_exec(
            'INSERT INTO invoice_items
               (invoice_id, sort_order, item_kind, item_code, description,
                qty, uom, unit_price, gst_rate, hsn_code, notes, ledger)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$invoiceId, $sort, $itemKind, substr($code, 0, 64), substr($desc, 0, 500),
             $qty, substr($uom, 0, 16), $price, $gst, $hsn ? substr($hsn, 0, 16) : null,
             substr($lineNote, 0, 255) ?: null, $ledger]
        );
        $this->counts['item_created']++;
        $invoiceItemId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);

        // Real link to the shipment: ensure a receipt anchor exists on the
        // matched shipment line, then link this invoice line to it
        // (link_kind 'inv' → inv_receipt_id). The anchor carries no stock txn.
        if ($slLine) {
            $receiptId = $this->ensureReceiptAnchor($slLine, $transId, $this->normalizeDate((string)($r['inv_date'] ?? '')));
            if ($receiptId > 0) {
                db_exec(
                    'INSERT INTO invoice_lines
                       (invoice_item_id, link_kind, inv_receipt_id, qty, created_by)
                     VALUES (?, ?, ?, ?, ?)',
                    [$invoiceItemId, 'inv', $receiptId, $qty, $this->actorId]
                );
                $this->counts['link_created']++;
            }
        }
    }

    /**
     * Choose the best shipment line for a legacy txn id. Prefers a 'receive'
     * line (receipts only make sense for received goods), and among the
     * candidates prefers an exact item-code match with the invoice line.
     */
    private function pickShipmentLine(int $transId, string $invoiceCode): ?array
    {
        if (!isset($this->txnMap[$transId])) {
            return null;
        }
        $lines = $this->txnMap[$transId]['lines'] ?? [];
        if (!$lines) {
            return null;
        }
        $receive = array_values(array_filter($lines, fn($l) => $l['kind'] === 'receive'));
        $pool    = $receive ?: $lines;     // fall back to any line if no receive line
        foreach ($pool as $l) {            // exact code match wins
            if ($l['code'] !== null && (string)$l['code'] === $invoiceCode) {
                return $l;
            }
        }
        return $pool[0];                   // else first of the preferred pool
    }

    /**
     * Return the inv_receipts.id anchoring a shipment line, creating a
     * stock-neutral receipt (txn_id = NULL) if none exists yet. Cached per
     * shipment line so multiple invoice lines share one receipt.
     */
    private function ensureReceiptAnchor(array $sl, int $transId, string $receiptDate): int
    {
        $slId = (int)$sl['sl_id'];
        if (isset($this->receiptCache[$slId])) {
            return $this->receiptCache[$slId];
        }
        // An existing receipt (native or from a prior run) takes precedence.
        if (!empty($sl['receipt_id'])) {
            return $this->receiptCache[$slId] = (int)$sl['receipt_id'];
        }

        $qty = (float)($sl['qty_received'] ?? 0);
        if ($qty <= 0) { $qty = (float)($sl['qty_planned'] ?? 0); }
        if ($qty <= 0) { $qty = 1; }

        $receiptNo = substr('IMP-RCP-' . $slId, 0, 32);
        db_exec(
            'INSERT INTO inv_receipts
               (receipt_no, shipment_id, shipment_line_id, qty_received, receipt_date,
                dst_location_id, txn_id, ref_doc, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)',
            [$receiptNo, (int)$sl['ship_id'], $slId, $qty, $receiptDate,
             $this->defaultLocationId, 'OLD-TRANS-' . $transId,
             'Auto receipt anchor for imported invoice link (Txn ' . $transId . ').',
             $this->actorId]
        );
        $rid = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
        $this->counts['receipt_created']++;
        return $this->receiptCache[$slId] = $rid;
    }

    // ── Vendor resolution ────────────────────────────────────────────────────
    private function resolveVendor(array $r): ?int
    {
        $name = trim((string)($r['companyname'] ?? ''));
        if ($name !== '') {
            $key = mb_strtolower($name);
            if (isset($this->vendorByName[$key])) {
                return $this->vendorByName[$key];
            }
        }
        // Fall back to the vendor on the shipment this line's trans_id links to.
        $transId = (int)($r['trans_id'] ?? 0);
        if ($transId > 0 && isset($this->txnMap[$transId]) && !empty($this->txnMap[$transId]['vendor_id'])) {
            return (int)$this->txnMap[$transId]['vendor_id'];
        }
        return null;
    }

    // ── Unique invoice_no (UNIQUE constraint) ────────────────────────────────
    private function uniqueInvoiceNo(string $base): string
    {
        $base = trim($base);
        if ($base === '') { $base = 'NO-NUMBER'; }
        $base = substr($base, 0, 64);
        $candidate = $base;
        $n = 2;
        while (isset($this->usedNos[mb_strtolower($candidate)])) {
            $suffix    = ' (' . $n . ')';
            $candidate = substr($base, 0, 64 - strlen($suffix)) . $suffix;
            $n++;
        }
        $this->usedNos[mb_strtolower($candidate)] = true;
        return $candidate;
    }

    /**
     * Natural identity for upsert matching: base invoice number (with any
     * " (n)" dedup suffix this importer may have appended stripped off) +
     * vendor + financial year. Two source groups that map to the same real
     * invoice resolve to the same identity, so a re-import updates rather
     * than duplicates.
     */
    private function importIdentity(string $invoiceNo, int $vendorId, string $fy): string
    {
        $base = preg_replace('/ \(\d+\)$/', '', trim($invoiceNo));
        return mb_strtolower((string)$base) . '|' . $vendorId . '|' . trim($fy);
    }

    // ── Build all lookup maps once up front ──────────────────────────────────
    private function buildLookupMaps(): void
    {
        foreach (db_all('SELECT code, name FROM inv_items') as $r) {
            $this->itemMap[(string)$r['code']] = (string)$r['name'];
        }
        foreach (db_all('SELECT id, name FROM vendors') as $r) {
            $this->vendorByName[mb_strtolower(trim((string)$r['name']))] = (int)$r['id'];
        }
        // Default destination location for auto-created receipt anchors:
        // prefer 'Store', then 'Magdyn', else the lowest-id location.
        $this->defaultLocationId =
            (int)db_val("SELECT id FROM locations WHERE code IN ('Store','Magdyn') ORDER BY (code='Store') DESC LIMIT 1", [], 0)
            ?: (int)db_val('SELECT id FROM locations ORDER BY id LIMIT 1', [], 0);

        // old transaction id → its shipment line(s) (item code, shipment vendor,
        // qtys, and any existing inv_receipt). One legacy txn can fan out to
        // several lines; we keep them all and pick the best at link time.
        $sql = "
            SELECT sl.old_transaction_id AS otid,
                   sl.id                AS sl_id,
                   sl.shipment_id       AS ship_id,
                   sl.line_kind         AS kind,
                   sl.qty_planned       AS qty_planned,
                   sl.qty_received      AS qty_received,
                   i.code               AS item_code,
                   s.vendor_id          AS vendor_id,
                   r.id                 AS receipt_id
              FROM inv_shipment_lines sl
         LEFT JOIN inv_items i     ON i.id = sl.item_id
         LEFT JOIN inv_shipments s ON s.id = sl.shipment_id
         LEFT JOIN inv_receipts r  ON r.shipment_line_id = sl.id
             WHERE sl.old_transaction_id IS NOT NULL
          ORDER BY sl.old_transaction_id, (sl.line_kind = 'receive') DESC, sl.id
        ";
        foreach (db_all($sql) as $r) {
            $otid = (int)$r['otid'];
            if ($otid <= 0) { continue; }
            if (!isset($this->txnMap[$otid])) {
                $this->txnMap[$otid] = ['vendor_id' => null, 'lines' => []];
            }
            if ($this->txnMap[$otid]['vendor_id'] === null && $r['vendor_id'] !== null) {
                $this->txnMap[$otid]['vendor_id'] = (int)$r['vendor_id'];
            }
            $this->txnMap[$otid]['lines'][] = [
                'sl_id'        => (int)$r['sl_id'],
                'ship_id'      => (int)$r['ship_id'],
                'kind'         => (string)$r['kind'],
                'code'         => $r['item_code'] !== null ? (string)$r['item_code'] : null,
                'receipt_id'   => $r['receipt_id'] !== null ? (int)$r['receipt_id'] : null,
                'qty_planned'  => (float)$r['qty_planned'],
                'qty_received' => (float)$r['qty_received'],
            ];
        }
        // Preload existing invoices: their numbers (so generated ones stay
        // unique) AND their natural identity (so a re-import updates the
        // matching invoice in place instead of inserting a duplicate).
        foreach (db_all('SELECT id, invoice_no, vendor_id, fy FROM invoices') as $r) {
            $this->usedNos[mb_strtolower(trim((string)$r['invoice_no']))] = true;
            $key = $this->importIdentity(
                (string)$r['invoice_no'], (int)$r['vendor_id'], (string)($r['fy'] ?? '')
            );
            // First match wins — keeps behaviour deterministic if two rows
            // somehow share an identity.
            if (!isset($this->existingByIdentity[$key])) {
                $this->existingByIdentity[$key] = (int)$r['id'];
            }
        }

        $this->log(
            'Lookup maps built — items: ' . count($this->itemMap) .
            ', vendors: ' . count($this->vendorByName) .
            ', shipment txns: ' . count($this->txnMap) .
            ', default receipt location id: ' . $this->defaultLocationId . '.'
        );
    }

    /** Coerce a legacy date to a valid 'Y-m-d', defaulting to today. */
    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0000-00-00' || strcasecmp($raw, 'null') === 0) {
            return date('Y-m-d');
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
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
