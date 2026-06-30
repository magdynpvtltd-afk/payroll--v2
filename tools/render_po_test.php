<?php
// One-off harness: render a PO to PDF by po_no for visual comparison.
//   php tools/render_po_test.php "MDPL/3766/0365" out.pdf
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
define('MAGDYN_CLI', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/_po_pdf.php';

$poNo = $argv[1] ?? 'MDPL/3766/0365';
$out  = $argv[2] ?? (sys_get_temp_dir() . '/po_current.pdf');

$row = db_one('SELECT id, po_no FROM purchase_orders WHERE po_no = ? ORDER BY id DESC LIMIT 1', [$poNo]);
if (!$row) {
    // try loose match
    $row = db_one("SELECT id, po_no FROM purchase_orders WHERE po_no LIKE ? ORDER BY id DESC LIMIT 1", ['%' . substr($poNo, -4)]);
}
if (!$row) { fwrite(STDERR, "PO not found for $poNo\n");
    $any = db_all('SELECT id, po_no FROM purchase_orders ORDER BY id DESC LIMIT 5');
    foreach ($any as $a) fwrite(STDERR, "  have: #{$a['id']} {$a['po_no']}\n");
    exit(1);
}
fwrite(STDERR, "Rendering PO #{$row['id']} {$row['po_no']}\n");
$att = po_render_pdf((int)$row['id']);
if (!$att) { fwrite(STDERR, "render failed\n"); exit(1); }
copy($att['path'], $out);
echo $out . "\n";
