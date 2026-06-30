<?php
/**
 * MagDyn — Inspection Report print HTML renderer
 *
 * Renders a multi-sample IR as a self-contained HTML document suitable
 * for both browser printing and PDF generation via DOMPDF.
 *
 * Layout (A4 landscape, matches old-application IR format):
 *   - Header row 1 : logo | company | "Inspection Report" | date/inspector | IR no
 *   - Header row 2 : Part No/Rev | Part Desc | PID | DWG No/Rev | PO No/Line | PDN Qty | Chkd Qty | Accepted Qty
 *   - Data table   : Bbl No | Parameter | Nom Value | Tolerance | Min/Max | UOM | Sample1..N | Notes
 *   - Remarks row  : per-sample acceptance remarks
 *   - Footer       : notes + inspector signature block
 *
 * 6 samples per page; multiple pages for > 6 samples (columns 1–6 on
 * page 1, 7–12 on page 2, etc.). Each page repeats the two header rows.
 *
 * DOMPDF-safe: no flexbox/grid, no border-radius, no box-shadow, no
 * external CSS, all fonts by family name only (DejaVu Sans).
 */

require_once __DIR__ . '/_inspection_ir.php';

/**
 * Build the full IR print HTML as a string.
 *
 * $opts:
 *   'include_actions_bar' => bool         (default true — hides on PDF)
 *   'po_no_override'      => string|null  (PO No + line entered at print
 *                                          time; overrides the job-card PO)
 *
 * Returns null when the inspection doesn't exist.
 */
function ir_render_print_html($inspectionId, array $opts = [])
{
    $opts += ['include_actions_bar' => true, 'po_no_override' => null];
    $inspectionId = (int)$inspectionId;
    $poNoOverride = ($opts['po_no_override'] !== null && trim((string)$opts['po_no_override']) !== '')
        ? trim((string)$opts['po_no_override'])
        : null;

    // ----------------------------------------------------------------
    // Load inspection row with user display names
    // ----------------------------------------------------------------
    $row = db_one(
        "SELECT i.*,
                iu.full_name AS inspected_by_name,
                pu.full_name AS planned_by_name,
                au.full_name AS approved_by_name
           FROM inspections i
           LEFT JOIN users iu ON iu.id = i.inspected_by
           LEFT JOIN users pu ON pu.id = i.planned_by
           LEFT JOIN users au ON au.id = i.approved_by
          WHERE i.id = ? AND i.is_deleted = 0",
        [$inspectionId]
    );
    if (!$row) return null;

    // Job-card header — live read (PO no, PO line)
    $jcInfo = ir_job_card_header($row['job_card_id'] ?? null);

    // Inspected item — live resolve (covers inv_item AND inv_txn targets).
    // Part No / Rev / Desc and the drawing come from the actual item, not
    // the creation-time snapshot (which may be blank for txn/legacy IRs).
    $resolved = ir_resolve_inspected_item($row);
    $item     = $resolved['item'];

    $dwgNo = $item['dwg_no']     ?? null;
    $dwgRev = $item['dwg_rev_no'] ?? null;
    if (!$dwgNo)  $dwgNo  = null;
    if (!$dwgRev) $dwgRev = null;

    // Results grid
    $gr      = ir_results_grid($inspectionId);
    $params  = $gr['params'];   // one row per parameter (carries spec fields)
    $grid    = $gr['grid'];     // $grid[templateItemId][sampleNo] = result row

    // Sample count, remarks
    $sampleCount  = max(1, (int)($row['sample_count'] ?? 1));
    $remarks      = ir_remarks_decode($row['sample_remarks_json'] ?? null);

    // Per-sample acceptance map — shared by the Remarks row and the
    // Accepted-qty header figure.
    $acceptMap = ir_sample_accept_map($params, $grid, $sampleCount);

    // Derived header quantities (PDN = txn qty or sample qty; Chkd =
    // sample qty; Accepted = count of accepted samples).
    $qtys        = ir_header_quantities($row, $params, $grid);
    $pdnQty      = $qtys['pdn'];
    $chkdQty     = $qtys['chkd'];
    $acceptedQty = $qtys['accepted'];

    $poNo        = $jcInfo['po_no']   ?? '';
    $poLine      = $jcInfo['line_no'] ?? '';
    $irNo        = $row['ir_no']      ?? '';
    // Part No / Rev from the inspected item; Part Desc from its name.
    // Fall back to the snapshot columns when the item is unavailable.
    $partNo      = ($item['part_no']     ?? null) ?: ($row['part_no']    ?? '');
    $partRev     = ($item['part_rev_no'] ?? null) ?: ($row['part_rev']   ?? '');
    $partDesc    = ($item['name']        ?? null) ?: ($row['part_description'] ?? '');
    $pid         = $row['pid']         ?? '';

    $irDateRaw = $row['inspected_at'] ? substr((string)$row['inspected_at'], 0, 10) : '';
    $irDateFmt = $irDateRaw !== '' ? date('d-M-Y', strtotime($irDateRaw)) : '—';
    $inspectorName = $row['inspected_by_name'] ?? '';

    // Logo — embed as base64 data URI so DOMPDF never needs remote access
    $logoPath    = __DIR__ . '/../assets/img/logo.png';
    $logoHtml    = '';
    if (file_exists($logoPath)) {
        $logoDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        $logoHtml    = '<img src="' . $logoDataUri . '" style="height:40px; width:auto;">';
    }

    // Verdict notes (used in footer)
    $verdictNotes = (string)($row['verdict_notes'] ?? '');

    $SAMPLES_PER_PAGE = 6;

    // ----------------------------------------------------------------
    // Helper: render the two IR header rows as an HTML string
    // ----------------------------------------------------------------
    $renderHeaders = function () use (
        $logoHtml, $partNo, $partRev, $partDesc, $pid, $dwgNo, $dwgRev,
        $poNo, $poLine, $poNoOverride, $pdnQty, $chkdQty, $acceptedQty,
        $irDateFmt, $inspectorName, $irNo
    ) {
        $dwgDisplay = $dwgNo ? h($dwgNo) . ($dwgRev ? ' Rev.' . h($dwgRev) : '') : '—';
        // PO No: a print-time override (entered in the dialog) wins;
        // otherwise fall back to the linked job-card PO + line. Wrapped
        // in .ir-po-value so the print-page dialog can live-update it.
        if ($poNoOverride !== null) {
            $poInner = h($poNoOverride);
        } else {
            $poInner = $poNo
                ? h($poNo) . ($poLine ? ' / L:' . h($poLine) : '')
                : '—';
        }
        $poDisplay = '<span class="ir-po-value">' . $poInner . '</span>';

        $h  = '<table style="border:1px solid #000; border-bottom:0; border-collapse:collapse; width:100%; font-family:DejaVu Sans,sans-serif; font-size:10px;">';
        $h .= '<tr>';
        $h .= '<td style="width:10%; border-right:1px solid #000; text-align:center; padding:4px;">' . $logoHtml . '</td>';
        $h .= '<td style="width:25%; border-right:1px solid #000; padding:5px; font-weight:bold;">MAGNETO DYNAMICS PVT LTD<br><span style="font-weight:normal; font-size:9px;">Chennai 600096, INDIA</span></td>';
        $h .= '<td style="width:25%; border-right:1px solid #000; padding:5px; font-size:16px; font-weight:bold; text-align:center;">Inspection Report</td>';
        $h .= '<td style="width:25%; border-right:1px solid #000; padding:5px;"><span style="font-weight:bold;">Inspection Date:</span><br>' . h($irDateFmt) . '<br><span style="font-weight:bold;">Inspected By:</span><br>' . h($inspectorName) . '</td>';
        $h .= '<td style="width:15%; padding:5px; font-weight:bold; text-align:center;">' . h($irNo) . '</td>';
        $h .= '</tr></table>';

        $h .= '<table style="border:1px solid #000; border-bottom:0; border-collapse:collapse; width:100%; font-family:DejaVu Sans,sans-serif; font-size:10px;">';
        $h .= '<tr>';
        $h .= '<td style="width:12%; border-right:1px solid #000; padding:5px;"><span style="font-weight:bold;">Part No:</span><br>' . h($partNo) . '<br><span style="font-size:9px;">Rev. ' . h($partRev) . '</span></td>';
        $h .= '<td style="width:30%; border-right:1px solid #000; padding:5px;"><span style="font-weight:bold;">Part Desc:</span><br>' . h($partDesc) . '</td>';
        $h .= '<td style="width:8%; border-right:1px solid #000; padding:5px;"><span style="font-weight:bold;">PID:</span><br>' . h($pid) . '</td>';
        $h .= '<td style="width:15%; border-right:1px solid #000; padding:5px;"><span style="font-weight:bold;">DWG No:</span><br>' . $dwgDisplay . '</td>';
        $h .= '<td style="width:15%; border-right:1px solid #000; padding:5px;"><span style="font-weight:bold;">PO No:</span><br>' . $poDisplay . '</td>';
        $h .= '<td style="width:7%; border-right:1px solid #000; padding:5px; text-align:center;"><span style="font-weight:bold;">PDN<br>Qty</span><br>' . ($pdnQty !== null ? h((string)$pdnQty) : '—') . '</td>';
        $h .= '<td style="width:7%; border-right:1px solid #000; padding:5px; text-align:center;"><span style="font-weight:bold;">Chkd<br>Qty</span><br>' . ($chkdQty !== null ? h((string)$chkdQty) : '—') . '</td>';
        $h .= '<td style="width:6%; padding:5px; text-align:center;"><span style="font-weight:bold;">Accepted<br>Qty</span><br>' . ($acceptedQty !== null ? h((string)$acceptedQty) : '—') . '</td>';
        $h .= '</tr></table>';

        return $h;
    };

    // ----------------------------------------------------------------
    // Helper: render data table for one chunk of samples [snStart, snEnd]
    // ----------------------------------------------------------------
    $renderDataTable = function ($snStart, $snEnd) use ($params, $grid, $sampleCount, $remarks, $acceptMap) {
        $sampleSpan = $snEnd - $snStart + 1;

        $t  = '<table style="border-collapse:collapse; width:100%; font-family:DejaVu Sans,sans-serif; font-size:9px;">';

        // "Measured Values" super-header
        $t .= '<tr>';
        $t .= '<th colspan="6" style="border:1px solid #000; border-bottom:0; border-right:0; padding:2px 4px;"></th>';
        $t .= '<th colspan="' . $sampleSpan . '" style="border:1px solid #000; border-bottom:0; border-right:0; padding:2px 4px; text-align:center;">Measured Values</th>';
        $t .= '<th style="border:1px solid #000; border-bottom:0; padding:2px 4px;"></th>';
        $t .= '</tr>';

        // Column headers
        $t .= '<tr>';
        $t .= '<th style="border:1px solid #000; border-bottom:0; border-right:0; padding:3px 4px; width:20px;">Bbl No</th>';
        $t .= '<th style="border:1px solid #000; border-bottom:0; border-right:0; padding:3px 4px; width:100px;">Parameter</th>';
        $t .= '<th style="border:1px solid #000; border-bottom:0; border-right:0; padding:3px 4px; width:50px;">Nom Value</th>';
        $t .= '<th style="border:1px solid #000; border-bottom:0; border-right:0; padding:3px 4px; width:70px;">Tolerance</th>';
        $t .= '<th style="border:1px solid #000; border-bottom:0; border-right:0; padding:3px 4px; width:50px;">Min / Max</th>';
        $t .= '<th style="border:1px solid #000; border-bottom:0; border-right:0; padding:3px 4px; width:40px;">UOM</th>';
        for ($s = $snStart; $s <= $snEnd; $s++) {
            $t .= '<th style="border-collapse:collapse; border:1px solid #000; border-bottom:0; border-right:0; padding:3px 4px; text-align:center;">S' . $s . '</th>';
        }
        $t .= '<th style="border:1px solid #000; border-bottom:0; padding:3px 4px; width:80px;">Notes</th>';
        $t .= '</tr>';

        // Track per-column failures for the Remarks row
        $colFailed   = [];
        $colHasValue = [];
        for ($s = $snStart; $s <= $snEnd; $s++) {
            $colFailed[$s]   = false;
            $colHasValue[$s] = false;
        }

        // Data rows
        foreach ($params as $p) {
            $ct     = strtolower((string)($p['check_type'] ?? 'numeric'));
            $target = $p['target_value']    ?? null;
            $lower  = $p['tolerance_lower'] ?? null;
            $upper  = $p['tolerance_upper'] ?? null;
            $unit   = (string)($p['unit'] ?? '');
            $bblNo  = (string)($p['bubble_no'] ?? '');
            $label  = (string)($p['label']     ?? '');
            $tid    = (int)$p['template_item_id'];

            // UOM display symbol
            $uomDisplay = inspection_uom_display($unit);

            // Spec column values
            if ($ct === 'nom' || $ct === 'logical-nom' || $ct === 'numeric') {
                $nomVal = ir_fmt_num($target);
                $tolParts = [];
                if ($lower !== null && $lower !== '') $tolParts[] = '(−) ' . ir_fmt_num($lower);
                if ($upper !== null && $upper !== '') $tolParts[] = '(+) ' . ir_fmt_num($upper);
                $tolStr = implode(' / ', $tolParts);
                list($minV, $maxV) = ir_min_max_for_type($ct, $target, $lower, $upper);
                $minStr = ($minV !== null) ? ir_fmt_num($minV) : '—';
                $maxStr = ($maxV !== null) ? ir_fmt_num($maxV) : '—';
                $minMaxStr = $minStr . ' / ' . $maxStr;
            } elseif ($ct === 'min-max' || $ct === 'logical-min-max') {
                $nomVal    = '';
                $tolStr    = '';
                $uomDisplay = inspection_uom_display($unit);
                list($minV, $maxV) = ir_min_max_for_type($ct, $target, $lower, $upper);
                $minStr = ($minV !== null) ? ir_fmt_num($minV) : '—';
                $maxStr = ($maxV !== null) ? ir_fmt_num($maxV) : '—';
                $minMaxStr = $minStr . ' / ' . $maxStr;
            } else {
                // logic, boolean, visual, notes, text
                $nomVal    = '';
                $tolStr    = '';
                $minMaxStr = '';
                $uomDisplay = '';
                // No numeric spec — clear bounds so the per-cell pass/fail
                // colouring below can't pick up a previous row's min/max.
                $minV = $maxV = null;
            }

            $t .= '<tr>';
            $t .= '<td style="border:1px solid #000; padding:3px 4px; text-align:center; height:22px;">' . h($bblNo) . '</td>';
            $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px;">' . h($label) . '</td>';
            $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px; text-align:center;">' . h($nomVal) . '</td>';
            $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px; text-align:center;">' . h($tolStr) . '</td>';
            $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px; text-align:center; vertical-align:middle;">' . h($minMaxStr) . '</td>';
            $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px; text-align:center;">' . h($uomDisplay) . '</td>';

            for ($s = $snStart; $s <= $snEnd; $s++) {
                $cell = isset($grid[$tid][$s]) ? $grid[$tid][$s] : null;
                $val  = $cell ? (string)($cell['measured_value'] ?? '') : '';

                // Text colour only — no background colours in PDF
                // Pass → black; Fail → red; empty → default
                $cellStyle = '';
                if ($val !== '') {
                    $colHasValue[$s] = true;
                    if (is_numeric($val) && ($minV !== null || $maxV !== null)) {
                        $numVal  = (float)$val;
                        $inRange = true;
                        if ($minV !== null && $numVal < (float)$minV) $inRange = false;
                        if ($maxV !== null && $numVal > (float)$maxV) $inRange = false;
                        if (!$inRange) {
                            $cellStyle = 'color:#dc2626; font-weight:bold;';
                            $colFailed[$s] = true;
                        }
                        // pass: no extra style (black text, transparent bg)
                    } elseif ($cell && ($cell['pass_fail'] ?? '') === 'fail') {
                        $cellStyle = 'color:#dc2626; font-weight:bold;';
                        $colFailed[$s] = true;
                    }
                    // pass_fail==='pass': no extra style
                }

                // Dropdown-verdict types (logic / logical-nom / logical-min-max)
                // store "pass"/"fail" — title-case it for the printed report.
                $dispRaw = (ir_is_select_passfail($ct) && $val !== '') ? ucfirst($val) : $val;
                $dispVal = $val !== '' ? h($dispRaw) : '&nbsp;';
                $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px; text-align:center; ' . $cellStyle . '">' . $dispVal . '</td>';
            }

            // Notes column — the template item's free-text note (imported from
            // the legacy inspection.notes column; editable in the template editor).
            $noteText = (string)($p['item_notes'] ?? '');
            $t .= '<td style="border:1px solid #000; padding:3px 4px;">' . h($noteText) . '</td>';
            $t .= '</tr>';
        }

        // Remarks row
        $t .= '<tr>';
        $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px; text-align:center;"></td>';
        $t .= '<td style="border:1px solid #000; border-right:0; border-left:0; padding:3px 4px; font-weight:bold;">Remarks</td>';
        $t .= '<td style="border:1px solid #000; border-right:0; border-left:0; padding:3px 4px;"></td>';
        $t .= '<td style="border:1px solid #000; border-right:0; border-left:0; padding:3px 4px;"></td>';
        $t .= '<td style="border:1px solid #000; border-right:0; border-left:0; padding:3px 4px;"></td>';
        $t .= '<td style="border:1px solid #000; border-right:0; border-left:0; padding:3px 4px;"></td>';
        for ($s = $snStart; $s <= $snEnd; $s++) {
            $remark = !empty($acceptMap[$s]) ? 'Accepted' : '';
            $t .= '<td style="border:1px solid #000; border-right:0; padding:3px 4px; text-align:center;">' . h($remark) . '</td>';
        }
        $t .= '<td style="border:1px solid #000; padding:3px 4px;"></td>';
        $t .= '</tr>';

        $t .= '</table>';
        return $t;
    };

    // ----------------------------------------------------------------
    // Helper: render footer (notes + signature block)
    // ----------------------------------------------------------------
    $renderFooter = function () use ($verdictNotes, $inspectorName) {
        $f  = '<table style="border-collapse:collapse; width:100%; font-family:DejaVu Sans,sans-serif; font-size:10px; margin-top:0;">';
        $f .= '<tr>';
        $f .= '<td style="border:1px solid #000; width:80px; padding:4px 6px; font-weight:bold;">Notes:</td>';
        $f .= '<td style="border:1px solid #000; padding:4px 6px;">' . nl2br(h($verdictNotes)) . '</td>';
        $f .= '</tr>';
        $f .= '</table>';

        // Signature block
        $inspectors = array_map('trim', explode(',', $inspectorName));
        $inspectors = array_filter($inspectors);

        if (!empty($inspectors)) {
            $colWidth = min(120, (int)floor(280 / count($inspectors)));
            $f .= '<br>';
            $f .= '<table style="border:1px solid #000; border-collapse:collapse; font-family:DejaVu Sans,sans-serif; font-size:10px;">';
            $f .= '<tr>';
            $f .= '<td style="padding:6px 10px; font-weight:bold; width:80px; vertical-align:top;">Inspected By:</td>';
            foreach ($inspectors as $insp) {
                $f .= '<td style="width:' . $colWidth . 'px; padding:4px 8px; text-align:center; vertical-align:bottom;">&nbsp;</td>';
            }
            $f .= '</tr>';
            $f .= '<tr>';
            $f .= '<td></td>';
            foreach ($inspectors as $insp) {
                $f .= '<td style="padding:2px 8px; text-align:center; border-top:1px solid #000;">' . h($insp) . '</td>';
            }
            $f .= '</tr>';
            $f .= '</table>';
        } else {
            $f .= '<br>';
            $f .= '<table style="border:1px solid #000; border-collapse:collapse; font-family:DejaVu Sans,sans-serif; font-size:10px;">';
            $f .= '<tr><td style="padding:8px 10px; font-weight:bold;">Inspected By:</td><td style="width:120px; padding:8px;"></td></tr>';
            $f .= '</table>';
        }

        return $f;
    };

    // ----------------------------------------------------------------
    // Assemble HTML
    // ----------------------------------------------------------------
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IR <?= h($irNo ?: $row['code']) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #000; margin: 0; padding: 0; }
    .ir-page { padding: 6mm 8mm; }
    .page-break { page-break-after: always; }
    .actions { margin-bottom: 12px; }
    .actions a, .actions button { display: inline-block; padding: 5px 12px; border: 1px solid #999;
        background: #fff; font-size: 12px; text-decoration: none; color: inherit; margin-right: 4px; cursor: pointer; }
    .actions .primary { background: #2d3a8c; color: #fff; border-color: #2d3a8c; }
    @media print { .actions, .ir-modal-overlay { display: none !important; } }

    /* PO No entry dialog (browser print page only) */
    .ir-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.45);
        display: flex; align-items: flex-start; justify-content: center; z-index: 9999; }
    .ir-modal { background: #fff; border: 1px solid #cbd5e1; margin-top: 12vh;
        width: 360px; max-width: 90vw; padding: 18px 20px; font-size: 13px;
        box-shadow: 0 8px 28px rgba(0,0,0,0.25); }
    .ir-modal h3 { margin: 0 0 4px; font-size: 15px; }
    .ir-modal p { margin: 0 0 12px; color: #64748b; font-size: 12px; }
    .ir-modal label { display: block; font-weight: bold; margin-bottom: 4px; }
    .ir-modal input[type=text] { width: 100%; padding: 7px 9px; border: 1px solid #94a3b8;
        font-size: 13px; font-family: inherit; }
    .ir-modal-actions { margin-top: 16px; text-align: right; }
    .ir-modal-actions button { padding: 6px 14px; border: 1px solid #2d3a8c;
        background: #2d3a8c; color: #fff; font-size: 13px; cursor: pointer; margin-left: 6px; }
    .ir-modal-actions button.ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
</style>
</head>
<body>
<div class="ir-page">
<?php if ($opts['include_actions_bar']): ?>
<div class="actions">
    <button onclick="window.print()" class="primary">Print</button>
    <a id="ir-download-pdf"
       data-base="<?= h(url('/inspection.php?action=download_pdf&id=' . $inspectionId)) ?>"
       href="<?= h(url('/inspection.php?action=download_pdf&id=' . $inspectionId)) ?>"
       target="_blank">Download PDF</a>
    <a href="<?= h(url('/inspection.php?action=view&id=' . $inspectionId)) ?>">Back to Inspection</a>
    <button type="button" onclick="document.getElementById('ir-po-modal').style.display='flex';document.getElementById('ir-po-input').focus();">Edit PO No</button>
</div>

<!-- PO No entry dialog — shown on load so the operator stamps the
     customer PO (with line number) onto the report before printing. -->
<div id="ir-po-modal" class="ir-modal-overlay" style="display:flex;">
    <div class="ir-modal">
        <h3>Enter PO No</h3>
        <p>Type the customer PO number with its line number. It will appear in the <strong>PO No</strong> box of this report and the downloaded PDF.</p>
        <label for="ir-po-input">PO No (with line number)</label>
        <input type="text" id="ir-po-input" autocomplete="off"
               value="<?= h($poNoOverride !== null ? $poNoOverride : ($poNo ? $poNo . ($poLine ? ' / L:' . $poLine : '') : '')) ?>"
               placeholder="e.g. PO-12345 / L:2">
        <div class="ir-modal-actions">
            <button type="button" class="ghost" id="ir-po-skip">Skip</button>
            <button type="button" id="ir-po-apply">Apply</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Render pages: one page per 6-sample chunk
$snStart = 1;
$pageNum = 0;
while ($snStart <= $sampleCount) {
    $snEnd = min($sampleCount, $snStart + $SAMPLES_PER_PAGE - 1);
    $isLastPage = ($snEnd >= $sampleCount);

    if ($pageNum > 0) {
        echo '<div class="page-break"></div>';
    }

    echo $renderHeaders();
    echo $renderDataTable($snStart, $snEnd);
    echo $renderFooter();

    $snStart += $SAMPLES_PER_PAGE;
    $pageNum++;
}
?>

</div>
<?php if ($opts['include_actions_bar']): ?>
<script>
(function () {
    var modal = document.getElementById('ir-po-modal');
    var input = document.getElementById('ir-po-input');
    var apply = document.getElementById('ir-po-apply');
    var skip  = document.getElementById('ir-po-skip');
    var dl    = document.getElementById('ir-download-pdf');
    if (!modal) return;

    function applyPo() {
        var v = input.value.trim();
        var cells = document.querySelectorAll('.ir-po-value');
        for (var i = 0; i < cells.length; i++) {
            cells[i].textContent = v !== '' ? v : '—';
        }
        if (dl) {
            var base = dl.getAttribute('data-base');
            dl.href = base + (v !== '' ? '&po_no=' + encodeURIComponent(v) : '');
        }
        modal.style.display = 'none';
    }
    apply.addEventListener('click', applyPo);
    skip.addEventListener('click', function () { modal.style.display = 'none'; });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applyPo(); }
    });
    input.focus();
    input.select();
})();
</script>
<?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}
