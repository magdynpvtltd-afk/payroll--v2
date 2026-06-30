<?php
/**
 * One-off: extract the signature + stamp images (with alpha) from the
 * reference PO template PDF and write them as transparent PNGs into
 * assets/img/.  Run once, then the assets are committed.
 *
 *   php tools/extract_po_images.php <template.pdf>
 */

$pdfPath = $argv[1] ?? null;
if (!$pdfPath || !is_file($pdfPath)) { fwrite(STDERR, "usage: php extract_po_images.php <pdf>\n"); exit(1); }
$data = file_get_contents($pdfPath);

/** Locate the raw (still-compressed) stream bytes for object `$id 0 obj`. */
function obj_stream($data, $id) {
    if (!preg_match('/\b' . $id . '\s+0\s+obj\b/', $data, $m, PREG_OFFSET_CAPTURE)) return null;
    $start = $m[0][1];
    $sp = strpos($data, 'stream', $start);
    if ($sp === false) return null;
    $p = $sp + 6;
    if (substr($data, $p, 2) === "\r\n") $p += 2;
    elseif ($data[$p] === "\n" || $data[$p] === "\r") $p += 1;
    $ep = strpos($data, 'endstream', $p);
    return substr($data, $p, $ep - $p);
}

/** Reverse the PNG predictor (Predictor 15) producing raw sample bytes. */
function unpredict($raw, $colors, $columns, $bpc = 8) {
    $bpp = (int)ceil($colors * $bpc / 8);      // bytes per pixel
    $stride = (int)ceil($colors * $bpc / 8 * $columns); // bytes per scanline (samples)
    $out = '';
    $prev = str_repeat("\0", $stride);
    $pos = 0;
    $len = strlen($raw);
    while ($pos < $len) {
        $ft = ord($raw[$pos++]);
        $line = substr($raw, $pos, $stride);
        $pos += $stride;
        $cur = array_fill(0, $stride, 0);
        for ($i = 0; $i < strlen($line); $i++) {
            $x = ord($line[$i]);
            $a = $i >= $bpp ? $cur[$i - $bpp] : 0;          // left
            $b = ord($prev[$i]);                            // up
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;    // up-left
            switch ($ft) {
                case 0: $v = $x; break;
                case 1: $v = $x + $a; break;
                case 2: $v = $x + $b; break;
                case 3: $v = $x + (int)(($a + $b) / 2); break;
                case 4:
                    $p = $a + $b - $c;
                    $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                    $pred = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                    $v = $x + $pred; break;
                default: $v = $x; break;
            }
            $cur[$i] = $v & 0xFF;
        }
        $out .= pack('C*', ...$cur);
        $prev = pack('C*', ...$cur);
    }
    return $out;
}

/** Build a transparent PNG from RGB obj + grayscale SMask obj. */
function build_png($data, $rgbId, $maskId, $w, $h, $outFile) {
    $rgbRaw  = gzuncompress(obj_stream($data, $rgbId));
    $maskRaw = gzuncompress(obj_stream($data, $maskId));
    $rgb  = unpredict($rgbRaw, 3, $w);
    $mask = unpredict($maskRaw, 1, $w);

    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $ri = ($y * $w + $x) * 3;
            $r = ord($rgb[$ri]); $g = ord($rgb[$ri + 1]); $b = ord($rgb[$ri + 2]);
            $a = ord($mask[$y * $w + $x]);          // 0=transparent..255=opaque
            $alpha = (int)round((255 - $a) / 255 * 127); // GD: 0=opaque..127=transparent
            $col = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
            imagesetpixel($img, $x, $y, $col);
        }
    }
    imagepng($img, $outFile);
    imagedestroy($img);
    echo "wrote $outFile ({$w}x{$h})\n";
}

$assetDir = __DIR__ . '/../assets/img';
build_png($data, 17, 16, 100, 80,  $assetDir . '/po_signature.png');
build_png($data, 19, 18, 263, 114, $assetDir . '/po_stamp.png');
