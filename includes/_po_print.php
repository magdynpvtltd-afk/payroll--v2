<?php
/**
 * MagDyn — PO print HTML renderer
 *
 * Single source of HTML for both:
 *   - Browser print at purchase_orders.php?action=print
 *   - PDF generation via includes/_po_pdf.php (dompdf)
 *
 * Layout reproduces the company PO template (MD-DOC-0026-DF VER-1 REV-0)
 * exactly — A4 **landscape**, Helvetica body / Courier T&C, lavender
 * (#E7E6FA) label cells, black hairline borders, and the authorised
 * signatory's signature + company seal above "Authorised Signatory":
 *   Page 1 — Main PO (header, vendor block, lines table, footer)
 *   Page 2 — Input Material Issued
 *   Page 3 — Terms & Conditions
 *
 * DOMPDF-safe rules applied here:
 *   - No flexbox/grid, no border-radius, no box-shadow, no CSS variables
 *   - No `* { box-sizing }` (universal selector ignored by dompdf)
 *   - No `padding` on <table> elements (dompdf ignores it; padding goes on <td>)
 *   - Every grid uses table-layout:fixed + <colgroup> so rowspan/colspan
 *     column widths are computed from the colgroup, not the th attributes
 *   - All styles inline or in <style>/<head>; no external CSS files
 */

require_once __DIR__ . '/_purchase_orders.php';

/**
 * Build the full PO print HTML as a string.
 *
 * $opts:
 *   'include_actions_bar' => bool  (default true; PDF callers set false)
 */
function po_render_print_html($poId, array $opts = [])
{
    $opts += ['include_actions_bar' => true];
    $full = po_load_full((int)$poId);
    if (!$full) return null;

    $po       = $full['po'];
    $shipment = $full['shipment'];
    $vendor   = $full['vendor'];
    $contact  = $full['primary_contact'];
    $address  = $full['primary_address'];   // vendor address
    $lines    = $full['lines'];
    // Price and GST come directly from inv_shipment_lines (unit_price, gst_rate).
    $grandTotal = 0.0;

    // ── Company details (from settings, with sensible defaults) ────────
    $co = [
        'name'             => magdyn_setting('company.name',             'Magneto Dynamics (P) Ltd.'),
        'address_line1'    => magdyn_setting('company.address_line1',    'Plot No 7/8/9, Venkateswara Nagar,'),
        'address_line2'    => magdyn_setting('company.address_line2',    'Perungudi, Chennai 600096, I N D I A'),
        'phone'            => magdyn_setting('company.phone',            '+91-44-24960663'),
        'email'            => magdyn_setting('company.email',            'rsk@magdyn.com'),
        'gst_no'           => magdyn_setting('company.gst_no',           '33AAACM4623Q1ZB'),
        'pan_no'           => magdyn_setting('company.pan_no',           'AAACM4623Q'),
        'iec_no'           => magdyn_setting('company.iec_no',           '0403034019'),
        'tan_no'           => magdyn_setting('company.tan_no',           'CHEM01647C'),
        'msme_no'          => magdyn_setting('company.msme_no',          '33-003-11-02194'),
        'delivery_addr1'   => magdyn_setting('company.delivery_addr1',   'Plot No.7/9, Venkateswara Nagar Main Road,'),
        'delivery_addr2'   => magdyn_setting('company.delivery_addr2',   'Perungudi, Chennai – 600096.'),
        'billing_addr1'    => magdyn_setting('company.billing_addr1',    'Plot No.7/8/9, Venkateswara Nagar Main Road,'),
        'billing_addr2'    => magdyn_setting('company.billing_addr2',    'Perungudi, Chennai – 600096.'),
        'despatch_email'   => magdyn_setting('po.despatch_email',        'vidhya@magdyn.com , Accounts@magdyn.com'),
        'accounts_email'   => magdyn_setting('po.accounts_email',        'Accounts@magdyn.com'),
    ];

    // ── Creator / buyer name ────────────────────────────────────────────
    $creatorRow = db_one('SELECT full_name, username FROM users WHERE id = ?', [(int)($po['created_by'] ?? 0)]);
    $buyerDisplay = $creatorRow
        ? ($creatorRow['full_name'] ?: $creatorRow['username'])
        : magdyn_setting('po.default_buyer', '');

    // ── Date formatting ────────────────────────────────────────────────
    $poDateFmt = '';
    if (!empty($po['po_date'])) {
        $ts = strtotime((string)$po['po_date']);
        $poDateFmt = $ts ? date('d-M-Y', $ts) : h($po['po_date']);
    }

    // ── Logo (embedded as base64 so dompdf doesn't need remote URLs) ───
    $logoPath    = __DIR__ . '/../assets/img/logo.png';
    $logoHtml    = '';
    if (file_exists($logoPath)) {
        $logoDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        $logoHtml    = '<img src="' . $logoDataUri . '" style="height:44px; width:auto; display:block;">';
    }

    // ── Authorised-signatory sign + company seal (extracted from the
    //    legacy PO template; placed above "Authorised Signatory"). ───────
    $signPath = __DIR__ . '/../assets/img/po_sign_stamp.png';
    $signHtml = '';
    if (file_exists($signPath)) {
        $signDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($signPath));
        $signHtml    = '<img src="' . $signDataUri . '" style="height:52px; width:auto;">';
    }

    // ── Miscellaneous shipment fields ───────────────────────────────────
    $paymentTerms   = (string)($shipment['payment_terms']        ?? '');
    $packFwd        = (string)($shipment['packing_forwarding']   ?? '');
    $freightIns     = (string)($shipment['freight_insurance']    ?? '');
    $specialInst    = (string)($shipment['special_instructions'] ?? '');
    $poRef          = (string)($shipment['reference']            ?? '');

    // Packing & Forwarding / Freight & Insurance may hold either a numeric
    // charge or free text ("Inclusive", "To pay", …). When numeric, the
    // amount is folded into the PO total; otherwise it's shown as-is and
    // contributes nothing. Tolerates ₹ / Rs / INR prefixes and thousands
    // separators, e.g. "₹1,250.00" → 1250.0.
    $po_charge = function ($v) {
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = preg_replace('/(?i)\b(?:rs|inr)\b\.?/', '', $s);
        $s = str_replace([',', '₹', ' '], '', $s);
        return is_numeric($s) ? (float)$s : null;
    };
    $packFwdAmt     = $po_charge($packFwd);
    $freightInsAmt  = $po_charge($freightIns);

    // ── Terms & Conditions text vs footer "Notes for Internal Use" ──────
    // Page 3 (Terms & Conditions) prefers the per-shipment snapshot, falling
    // back to the system default. A batch of legacy/imported shipments,
    // however, stored the full standard T&C inside the `notes` field (with
    // terms_conditions left blank). We detect that case by its signature
    // wording and render it on the T&C page instead — and crucially we then
    // DON'T echo it in the footer's "Notes for Internal Use" cell, which is
    // what made that cell balloon. Genuine notes (material lists, vendor
    // names, etc.) are left untouched in the footer.
    $shipNotes    = (string)($shipment['notes']            ?? '');
    $tcConfigured = (string)($shipment['terms_conditions'] ?? '');
    $notesAreTerms = $tcConfigured === ''
        && (stripos($shipNotes, 'despatched should be accompanied') !== false
            || stripos($shipNotes, 'Delivery Challan') !== false);
    if ($notesAreTerms) {
        $tcText        = $shipNotes;   // legacy T&C stored in notes → page 3
        $internalNotes = '';           // …and kept out of the footer
    } else {
        $tcText        = $tcConfigured !== '' ? $tcConfigured
                       : magdyn_setting('shiprcpt.terms_conditions', '');
        $internalNotes = $shipNotes;   // genuine note → footer
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PO <?= h($po['po_no']) ?></title>
<style>
    /* Landscape A4 to match the company PO template exactly.
       NOTE: this dompdf build ignores the @page `margin` property, so the
       page margin is driven by the <body> margin instead — dompdf repeats
       it on every page, giving a consistent border on all 3 pages. */
    @page { size: A4 landscape; }
    html { margin: 0; padding: 0; }
    body { margin: 10mm; padding: 0; }
    body  { font-family: Helvetica, Arial, sans-serif; font-size: 8.5px; color: #000; }
    .po-wrap { width: 100%; }

    table { border-collapse: collapse; }
    .w100 { width: 100%; }
    .b   { font-weight: bold; }
    .c   { text-align: center; }
    .r   { text-align: right; }
    .lav { background: #E7E6FA; }              /* template label/header shade */
    .small { font-size: 8px; }

    /* Full hairline-bordered grids (the template is an all-black grid). */
    .grid { width: 100%; table-layout: fixed; border-collapse: collapse; }
    .grid td, .grid th { border: 0.75px solid #000; padding: 3px 5px; vertical-align: top; }
    .noborder td { border: none; padding: 0; }

    .po-title { font-size: 18px; font-weight: bold; letter-spacing: 0.02em; }
    .docref   { text-align: right; font-size: 8px; color: #000; padding: 0 1px 2px 0; }
    .lblcell  { font-size: 8px; }
    .pageno   { text-align: right; font-size: 8.5px; font-weight: bold; font-style: italic; margin-top: 4px; }
    .page-break { page-break-after: always; }

    /* ── Lines table ── */
    .lines-tbl { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .lines-tbl th { background: #E7E6FA; border: 0.75px solid #000; padding: 3px 3px;
        text-align: center; vertical-align: middle; font-size: 7.6px; font-weight: bold; }
    .lines-tbl td { border: 0.75px solid #000; padding: 3px 4px; vertical-align: top; font-size: 8px; }
    .lines-tbl .num { text-align: right; }
    .lines-tbl .ctr { text-align: center; }
    .lines-tbl tfoot td { border: 0.75px solid #000; padding: 3px 6px; font-weight: bold; }

    /* ── Actions bar (browser only) ── */
    .actions { margin-bottom: 14px; }
    .actions a, .actions button { display: inline-block; padding: 5px 14px; border: 1px solid #999; background: #fff;
        font-size: 12px; text-decoration: none; color: inherit; margin-right: 6px; cursor: pointer; }
    .actions .primary { background: #2d3a8c; color: #fff; border-color: #2d3a8c; }
    @media print { .actions { display: none; } }

    /* ── T&C (monospace, like the template's Courier) ── */
    .tc-title { text-align: center; font-size: 12px; font-weight: bold; text-decoration: underline; margin: 4px 0 14px; }
    .tc-tbl { width: 100%; border-collapse: collapse; font-family: 'Courier New', Courier, monospace; font-size: 9px; }
    .tc-tbl td { vertical-align: top; padding: 0 0 8px 0; line-height: 1.45; }
    .tc-tbl td.n { width: 22px; padding-right: 2px; }

    /* ── Input Material table ── */
    .imt-tbl { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; font-size: 8.5px; }
    .imt-tbl th { background: #E7E6FA; border: 0.75px solid #000; padding: 4px 6px; text-align: center; font-weight: bold; }
    .imt-tbl td { border: 0.75px solid #000; padding: 4px 6px; }
</style>
</head>
<body>
<div class="po-wrap">

<?php if ($opts['include_actions_bar']): ?>
<div class="actions">
    <button onclick="window.print()" class="primary">🖨 Print</button>
    <a href="<?= h(url('/purchase_orders.php?action=download_pdf&id=' . (int)$po['id'])) ?>" target="_blank">⬇ Download PDF</a>
    <a href="<?= h(url('/purchase_orders.php?action=view&id=' . (int)$po['id'])) ?>">← Back to PO</a>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     PAGE 1 — MAIN PO
     ═══════════════════════════════════════════════════════════ -->

<!-- Doc ref top-right -->
<div class="docref">MD-DOC-0026-DF VER-1 REV-0</div>

<!-- Company header: logo | name+address | phone/email | tax IDs -->
<table class="grid">
    <colgroup>
        <col style="width:9%;"><col style="width:39%;"><col style="width:21%;"><col style="width:31%;">
    </colgroup>
    <tr>
        <td class="c" style="vertical-align:middle;"><?= $logoHtml ?></td>
        <td style="line-height:1.5;">
            <span class="b" style="font-size:11px;"><?= h($co['name']) ?></span><br>
            <?= h($co['address_line1']) ?><br>
            <?= h($co['address_line2']) ?>
        </td>
        <td style="line-height:1.7;">
            Phone : <?= h($co['phone']) ?><br>
            Email: <?= h($co['email']) ?>
        </td>
        <td style="padding:3px 5px;">
            <table class="w100" style="border-collapse:collapse; line-height:1.55;">
                <tr><td style="border:none; padding:0;"><span class="b">GST NO:</span> <?= h($co['gst_no']) ?></td>
                    <td style="border:none; padding:0;"><span class="b">PAN NO:</span> <?= h($co['pan_no']) ?></td></tr>
                <tr><td style="border:none; padding:0;"><span class="b">IEC NO:</span> <?= h($co['iec_no']) ?></td>
                    <td style="border:none; padding:0;"><span class="b">TAN NO:</span> <?= h($co['tan_no']) ?></td></tr>
                <tr><td style="border:none; padding:0;"><span class="b">MSME NO:</span> <?= h($co['msme_no']) ?></td>
                    <td style="border:none; padding:0;"></td></tr>
            </table>
        </td>
    </tr>
</table>

<!-- PURCHASE ORDER title + PO No + Date -->
<table class="grid" style="border-top:none;">
    <colgroup>
        <col style="width:38%;"><col style="width:13%;"><col style="width:16%;"><col style="width:9%;"><col style="width:24%;">
    </colgroup>
    <tr>
        <td style="border-top:none; vertical-align:middle;"><span class="po-title">PURCHASE ORDER</span></td>
        <td class="lav lblcell" style="border-top:none; vertical-align:middle;">P.O Number</td>
        <td class="b" style="border-top:none; vertical-align:middle;"><?= h($po['po_no']) ?></td>
        <td class="lav lblcell" style="border-top:none; vertical-align:middle;">Date</td>
        <td class="b" style="border-top:none; vertical-align:middle;"><?= h($poDateFmt) ?></td>
    </tr>
</table>

<!-- To Vendor | Delivery Address | Billing Address : lavender header row, white values -->
<table class="grid" style="border-top:none;">
    <colgroup><col style="width:34%;"><col style="width:33%;"><col style="width:33%;"></colgroup>
    <tr>
        <td class="lav b" style="border-top:none;">To Vendor</td>
        <td class="lav b" style="border-top:none;">Delivery Address</td>
        <td class="lav b" style="border-top:none;">Billing Address</td>
    </tr>
    <tr>
        <td style="line-height:1.5;">
            <table class="w100" style="border-collapse:collapse;"><tr>
                <td style="border:none; padding:0;"><span class="b"><?= h($vendor['name'] ?? '—') ?></span></td>
                <td style="border:none; padding:0; text-align:right;"><?php if (!empty($vendor['code'])): ?><span class="b">Vendor Code :</span> <?= h($vendor['code']) ?><?php endif; ?></td>
            </tr></table>
            <?php if ($address): ?>
                <?= h($address['line1'] ?? '') ?><br>
                <?php if (!empty($address['line2'])): ?><?= h($address['line2']) ?><br><?php endif; ?>
                <?php $cityline = trim(($address['city'] ?? '') . ' ' . ($address['state'] ?? '') . ' ' . ($address['pincode'] ?? '')); ?>
                <?php if ($cityline !== ''): ?><?= h($cityline) ?><br><?php endif; ?>
            <?php endif; ?>
            <?php if ($contact): ?>
                Ph: <?= h($contact['phone'] ?? '') ?><br>
                Email: <?= h($contact['email'] ?? '') ?><br>
            <?php endif; ?>
            <?php if (!empty($vendor['gst_no'])): ?><span class="b">GST NO:</span> <?= h($vendor['gst_no']) ?><?php endif; ?>
        </td>
        <td style="line-height:1.5;">
            <span class="b"><?= h($co['name']) ?></span><br>
            <?= h($co['delivery_addr1']) ?><br>
            <?= h($co['delivery_addr2']) ?><br>
            <span class="b">GST NO:</span> <?= h($co['gst_no']) ?>
        </td>
        <td style="line-height:1.5;">
            <span class="b"><?= h($co['name']) ?></span><br>
            <?= h($co['billing_addr1']) ?><br>
            <?= h($co['billing_addr2']) ?><br>
            <span class="b">GST NO:</span> <?= h($co['gst_no']) ?>
        </td>
    </tr>
</table>

<!-- Ref row -->
<table class="grid" style="border-top:none;">
    <tr><td style="border-top:none;"><span class="b">Ref :</span> <?= h($poRef) ?></td></tr>
</table>

<!-- Instructions -->
<div style="margin:4px 0; line-height:1.4;">
    Please Supply the following material(s) as per the terms &amp; conditions mentioned here under and overleaf.
    Quote our PO No. &amp; Date in all your supply documents and correspondence.
</div>

<!-- Lines table — "Rate per" sits over "Qty" only; "GST %" is its own column. -->
<table class="lines-tbl">
    <colgroup>
        <col style="width:3%;">
        <col style="width:33%;">
        <col style="width:5%;">
        <col style="width:5%;">
        <col style="width:8%;">
        <col style="width:7%;">
        <col style="width:9%;">
        <col style="width:6%;">
        <col style="width:6%;">
        <col style="width:5%;">
        <col style="width:4%;">
        <col style="width:4%;">
        <col style="width:5%;">
    </colgroup>
    <thead>
        <tr>
            <th rowspan="2" style="width:3%;">Sl.</th>
            <th rowspan="2" style="width:33%;">Part No. and Description of Material</th>
            <th rowspan="2" style="width:5%;">Qty.</th>
            <th rowspan="2" style="width:5%;">Unit</th>
            <th rowspan="2" style="width:8%; vertical-align:middle; line-height:2.0;">Rate per<br>Qty</th>
            <th rowspan="2" style="width:7%;">GST %</th>
            <th rowspan="2" style="width:9%;">Total Value in Rs.P</th>
            <th rowspan="2" style="width:6%;">Incoming Inspection</th>
            <th colspan="2" style="width:11%;">Delivery Schedule</th>
            <th colspan="3" style="width:13%;">Receipt details for magdyn use</th>
        </tr>
        <tr>
            <th style="width:6%;">Date</th>
            <th style="width:5%;">Quantity</th>
            <th style="width:4%;">Date</th>
            <th style="width:4%;">Quantity</th>
            <th style="width:5%;">CRIN No.</th>
        </tr>
    </thead>
    <tbody>
        <?php
        // Page 1 is the purchase order proper: only receipt lines
        // (line_kind = 'receive') belong here. Material *issued* to the
        // vendor (line_kind = 'ship') is listed separately on page 2
        // ("Input Material Issued") and must not appear here, nor be folded
        // into the PO total.
        $receiveLines = array_values(array_filter($lines, function ($l) {
            return ($l['line_kind'] ?? 'receive') !== 'ship';
        }));
        ?>
        <?php if (!$receiveLines): ?>
            <tr><td colspan="13" class="ctr" style="padding:6px;">No lines.</td></tr>
        <?php else:
            foreach ($receiveLines as $idx => $l):
                $isAsset   = $l['entity_type'] === 'asset';
                $isPending = !$isAsset && empty($l['item_id']) && !empty($l['pending_name']);
                $code = $isAsset
                      ? ($l['asset_tag'] ?: '—')
                      : ($l['item_code'] ?: ($isPending ? '(new)' : '—'));
                $desc = $isAsset
                      ? ($l['asset_model'] ?: '')
                      : ($l['item_name'] ?: ($l['pending_name'] ?? ''));
                $price = ($l['unit_price'] !== null && $l['unit_price'] !== '') ? (float)$l['unit_price'] : null;
                $gst   = ($l['gst_rate']   !== null && $l['gst_rate']   !== '') ? (float)$l['gst_rate']   : null;
                $qty   = (float)($l['qty_planned'] ?? 0);
                $qtyDisp = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
                if ($qtyDisp === '' || $qtyDisp === '.') $qtyDisp = '0';
                // Total value = Qty × Rate, plus GST% on that amount.
                $totalLine = ($price !== null) ? $price * $qty : null;
                if ($totalLine !== null && $gst !== null) $totalLine += $totalLine * ($gst / 100);
                if ($totalLine !== null) $grandTotal += $totalLine;
                $delivDate = '';
                if (!empty($l['delivery_date'])) {
                    $dts = strtotime((string)$l['delivery_date']);
                    $delivDate = $dts ? date('d-M-Y', $dts) : h($l['delivery_date']);
                }
        ?>
            <tr>
                <td class="ctr"><?= $idx + 1 ?></td>
                <td><?= h($code) ?><?php if ($code !== '—' && $code !== '' && $desc !== ''): ?> <?= h($desc) ?><?php endif; ?></td>
                <td class="ctr"><?= h($qtyDisp) ?></td>
                <td class="ctr"><?= h($l['uom_label'] ?? '—') ?></td>
                <td class="num"><?= $price !== null ? h(number_format($price, 2)) : '' ?></td>
                <td class="num"><?= $gst !== null ? h(rtrim(rtrim((string)$gst, '0'), '.')) : '0' ?></td>
                <td class="num"><?= $totalLine !== null ? h(number_format($totalLine, 2)) : '0.00' ?></td>
                <td class="ctr"></td><!-- Incoming Inspection -->
                <td class="ctr"><?= $delivDate ?></td>
                <td class="ctr"><?= h($qtyDisp) ?></td>
                <td class="ctr"></td><!-- Receipt Date -->
                <td class="ctr"></td><!-- Receipt Quantity -->
                <td class="ctr"></td><!-- CRIN No. -->
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <?php
            // Fold any numeric Packing & Forwarding / Freight & Insurance
            // charge into the printed PO total.
            $totalAmount = $grandTotal + ($packFwdAmt ?? 0) + ($freightInsAmt ?? 0);
        ?>
        <tr>
            <td colspan="6" class="r b">TOTAL Amount</td>
            <td class="num b"><?= $totalAmount > 0 ? h(number_format($totalAmount, 2)) : '0' ?></td>
            <td colspan="6"></td>
        </tr>
    </tfoot>
</table>

<!-- Payment / Packing / Freight / Notes — lavender labels row + white values row -->
<table class="grid" style="border-top:none;">
    <colgroup><col style="width:25%;"><col style="width:25%;"><col style="width:25%;"><col style="width:25%;"></colgroup>
    <tr>
        <td class="lav b" style="border-top:none;">Payment terms</td>
        <td class="lav b" style="border-top:none;">Packing &amp; Forwarding</td>
        <td class="lav b" style="border-top:none;">Freight &amp; Insurance</td>
        <td class="lav b" style="border-top:none;">NOTES</td>
    </tr>
    <tr>
        <td><?= h($paymentTerms) ?>&nbsp;</td>
        <td><?= h($packFwd) ?>&nbsp;</td>
        <td><?= h($freightIns) ?>&nbsp;</td>
        <td>&nbsp;</td>
    </tr>
</table>

<!-- Special Instructions / Enclosures : lavender label + white value -->
<table class="grid" style="border-top:none;">
    <colgroup><col style="width:18%;"><col style="width:82%;"></colgroup>
    <tr>
        <td class="lav b" style="border-top:none;">Special Instructions</td>
        <td style="border-top:none;"><?= h($specialInst) ?>&nbsp;</td>
    </tr>
    <tr>
        <td class="lav b">Enclosures to this PO.</td>
        <td>&nbsp;</td>
    </tr>
</table>

<!-- Footer grid: despatch / buyer / approved + signatory box with sign & seal -->
<table class="grid" style="border-top:none;">
    <colgroup>
        <col style="width:18%;"><col style="width:28%;"><col style="width:10%;"><col style="width:17%;"><col style="width:27%;">
    </colgroup>
    <tr>
        <td class="lav" style="border-top:none;">Confirm Despatch by email to</td>
        <td colspan="3" style="border-top:none;"><span class="b"><?= h($co['despatch_email']) ?></span></td>
        <td class="lav c b" style="border-top:none;">For <?= h($co['name']) ?>,</td>
    </tr>
    <tr>
        <td class="lav">Project Code / Job Code</td>
        <td>&nbsp;</td>
        <td class="lav">Buyer</td>
        <td><?= h($buyerDisplay) ?>&nbsp;</td>
        <td rowspan="2" class="c" style="vertical-align:middle; padding:2px;"><?= $signHtml ?></td>
    </tr>
    <tr>
        <td class="lav">Budget Code</td>
        <td>&nbsp;</td>
        <td class="lav">Approved By</td>
        <td><?= h(magdyn_setting('po.default_approver', '')) ?>&nbsp;</td>
    </tr>
    <tr>
        <td class="lav">Notes for Internal Use</td>
        <td colspan="3"><?= h($internalNotes) ?>&nbsp;</td>
        <td class="lav c b">Authorised Signatory</td>
    </tr>
</table>

<div class="pageno">Page 1 of 3</div>

<!-- ═══════════════════════════════════════════════════════════
     PAGE 2 — INPUT MATERIAL ISSUED
     ═══════════════════════════════════════════════════════════ -->
<div class="page-break"></div>

<div style="font-size:12px; font-weight:bold; margin-bottom:6px;">Input Material Issued</div>
<table class="imt-tbl">
    <colgroup><col style="width:6%;"><col style="width:66%;"><col style="width:10%;"><col style="width:18%;"></colgroup>
    <thead>
        <tr>
            <th>Sl.</th>
            <th>Part No. and Description of Material</th>
            <th>Qty.</th>
            <th>Date</th>
        </tr>
    </thead>
    <tbody>
        <?php
        // List every shipment line (line_kind = 'ship') — the material
        // issued, or to be issued, to the vendor. This is the *only* place
        // ship lines appear; the page-1 PO table shows receipt lines alone.
        // Quantity shows what was actually issued (qty_shipped) once shipped,
        // falling back to the planned issue qty before that.
        $imIdx = 0;
        foreach ($lines as $l):
            if (($l['line_kind'] ?? '') !== 'ship') continue;
            $isAsset = $l['entity_type'] === 'asset';
            $code = $isAsset ? ($l['asset_tag'] ?: '—') : ($l['item_code'] ?: '—');
            $desc = $isAsset ? ($l['asset_model'] ?: '') : ($l['item_name'] ?: ($l['pending_name'] ?? ''));
            $issuedQty = (float)($l['qty_shipped'] ?? 0);
            if ($issuedQty <= 0) $issuedQty = (float)($l['qty_planned'] ?? 0);
            $qty  = rtrim(rtrim(number_format($issuedQty, 2), '0'), '.');
            $lineDate = '';
            if (!empty($l['delivery_date'])) {
                $dts = strtotime((string)$l['delivery_date']);
                $lineDate = $dts ? date('d-M-Y', $dts) : h($l['delivery_date']);
            }
        ?>
            <tr>
                <td class="c"><?= ++$imIdx ?></td>
                <td><?= h($code) ?><?= $desc ? ' ' . h($desc) : '' ?></td>
                <td class="c"><?= h($qty) ?></td>
                <td class="c"><?= $lineDate ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($imIdx === 0): ?>
            <tr><td class="c">&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="pageno">Page 2 of 3</div>

<!-- ═══════════════════════════════════════════════════════════
     PAGE 3 — TERMS & CONDITIONS  (monospace, like the template)
     ═══════════════════════════════════════════════════════════ -->
<div class="page-break"></div>

<div class="tc-title">Terms &amp; Conditions</div>

<?php
$standardTerms = [
    'The materials, when despatched should be accompanied by Delivery Challan / Packing slip, Warranty / Test certificate, giving our Purchase Order Number, full description of items despatched, quantity, number of packages, mode of despatch etc.',
    'Bills / Invoices covering the materials despatched must be sent in Duplicate to us along with despatch documents within five days.',
    'Inspection will be carried out by ourselves / customer / third party inspection agency at our premises.',
    'Bills / Invoices should contain in addition to full particulars of the materials, our order number and full particulars of Airway bill / Lorry Receipt No. / courier docket No. under which the materials are despatched.',
    'Materials when received will be inspected by our Inspection Department and the decision of our Inspection Department in regard to the acceptance or rejection of the materials will be final.',
    'If materials are rejected, notice of such rejection will be intimated to suppliers giving reasons for such rejection. On receipt of such intimation, the rejected goods must be removed and replaced immediately by the supplier, if so desired by the suppliers, the rejected materials will be re-booked on "Freight To-Pay" basis. In case of failure to remove rejected materials within a reasonable time, we will reserve the right to dispose of the materials at the supplier\'s risk and no claim whatsoever thereafter will be entertained.',
    'In case of failure to supply the materials as per delivery schedule stipulated in the Purchase Order and accepted by the suppliers or in case the materials are not as per the specification, we reserve the right to cancel the Purchase Order and procure the materials from elsewhere at the supplier\'s risk and cost.',
    'The drawing sketch and or any other documents given in connection with this Purchase order should be treated in strict confidence and should not be disclosed to any third party without our permission in writing. If a third party comes into possession of any of the documents, this purchase order is liable for immediate cancellation and we reserve the right to claim damages and to procure the materials from elsewhere entirely at the supplier\'s risk and cost.',
    'The duplicate (photocopy) copy of this order is to be signed and returned to ' . ($co['name']) . ', Chennai-96 confirming the acceptance within one week.',
    'Any dispute relating to this Purchase Order shall be deemed to have arisen in Tamil Nadu State and shall be subject to the adjudication by a competent Court in Chennai, Tamil Nadu State. India.',
];

// Resolved earlier: per-shipment snapshot → legacy notes-stored T&C →
// system default. Falls through to the 10 standard points when all empty.
$customTerms = $tcText;
?>

<?php if ($customTerms !== ''): ?>
    <div style="font-family:'Courier New',Courier,monospace; font-size:9px; line-height:1.5; white-space:pre-wrap;"><?= h($customTerms) ?></div>
<?php else: ?>
    <table class="tc-tbl">
        <?php foreach ($standardTerms as $i => $tc): ?>
            <tr><td class="n"><?= ($i + 1) ?>.</td><td><?= h($tc) ?></td></tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<div class="pageno">Page 3 of 3</div>

</div><!-- /.po-wrap -->
</body>
</html>
    <?php
    return ob_get_clean();
}
