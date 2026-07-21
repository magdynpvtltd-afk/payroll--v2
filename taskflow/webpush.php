<?php
/**
 * Minimal, dependency-free Web Push sender (VAPID + aes128gcm / RFC 8291, RFC 8188).
 * Requires the PHP `openssl` extension with EC (prime256v1) support and PHP 7.3+
 * (uses openssl_pkey_derive + hash_hkdf). No Composer packages needed.
 *
 * Public API:
 *   webpush_configured(): bool
 *   webpush_send(array $sub, string $payload): int   // HTTP status, or 0 on transport error
 *     $sub = ['endpoint' => ..., 'p256dh' => ..., 'auth' => ...]
 *
 * Include after db.php (needs the VAPID_* constants).
 */

function webpush_configured(): bool
{
    return defined('VAPID_PUBLIC') && VAPID_PUBLIC !== ''
        && defined('VAPID_PRIVATE_PEM') && VAPID_PRIVATE_PEM !== ''
        && function_exists('openssl_pkey_derive');
}

/**
 * Options for openssl_pkey_new() when creating the ephemeral ECDH keypair.
 * On this XAMPP/Windows host the process-wide OPENSSL_CONF points at a file
 * that doesn't exist, which makes bare openssl_pkey_new() fail with a fopen
 * error. Passing an explicit `config` to a KNOWN-GOOD openssl.cnf bundled with
 * TaskFlow sidesteps that per call (no Apache restart / system change needed).
 */
function webpush_pkey_opts(): array
{
    $opts = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    $cnf = __DIR__ . '/openssl.cnf';
    if (is_file($cnf)) {
        $opts['config'] = $cnf;
    }
    return $opts;
}

/** URL-safe base64 without padding. */
function b64u_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s) ?: '';
}

/** Convert an ECDSA DER signature into raw R||S (each 32 bytes for P-256). */
function ecdsa_der_to_raw(string $der): string
{
    $off = 0;
    if (($der[$off++] ?? '') !== "\x30") {
        return '';
    }
    // sequence length (skip; may be short form)
    $len = ord($der[$off++]);
    if ($len & 0x80) {
        $off += ($len & 0x7f);
    }
    $readInt = function () use ($der, &$off): string {
        if (($der[$off++] ?? '') !== "\x02") {
            return '';
        }
        $l = ord($der[$off++]);
        $v = substr($der, $off, $l);
        $off += $l;
        $v = ltrim($v, "\x00");                       // strip sign padding
        return str_pad($v, 32, "\x00", STR_PAD_LEFT); // left-pad to 32
    };
    $r = $readInt();
    $s = $readInt();
    return $r . $s;
}

/** Build the VAPID Authorization header value for an endpoint. */
function webpush_vapid_header(string $endpoint): string
{
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'];

    $header  = b64u_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = b64u_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => VAPID_SUBJECT,
    ]));
    $signingInput = $header . '.' . $payload;

    $pkey = openssl_pkey_get_private(VAPID_PRIVATE_PEM);
    if ($pkey === false) {
        return '';
    }
    $der = '';
    openssl_sign($signingInput, $der, $pkey, OPENSSL_ALGO_SHA256);
    $jwt = $signingInput . '.' . b64u_encode(ecdsa_der_to_raw($der));

    return 'vapid t=' . $jwt . ', k=' . VAPID_PUBLIC;
}

/** Encrypt $payload for a subscription; returns the aes128gcm request body. */
function webpush_encrypt(string $payload, string $p256dh_b64u, string $auth_b64u): string
{
    $uaPublic = b64u_decode($p256dh_b64u);   // 65 bytes (0x04 || X || Y)
    $authSecret = b64u_decode($auth_b64u);   // 16 bytes

    // Ephemeral (application-server) ECDH keypair.
    $as = openssl_pkey_new(webpush_pkey_opts());
    $d  = openssl_pkey_get_details($as);
    $asPublic = "\x04"
        . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    // Rebuild the UA public key as an OpenSSL key from its raw point.
    $spkiPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $uaPem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spkiPrefix . $uaPublic), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
    $uaKey = openssl_pkey_get_public($uaPem);

    // ECDH shared secret.
    $ecdh = openssl_pkey_derive($uaKey, $as, 32);

    $salt = random_bytes(16);

    // Derive IKM (RFC 8291 §3.4), then CEK + nonce (RFC 8188).
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
    $ikm   = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authSecret);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // Single record: plaintext || 0x02 delimiter. Encrypt with AES-128-GCM.
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek,
        OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

    // aes128gcm content-coding header: salt(16) rs(4) idlen(1) keyid(=as_public).
    $rs = 4096;
    $header = $salt . pack('N', $rs) . chr(strlen($asPublic)) . $asPublic;

    return $header . $cipher . $tag;
}

/** Send one push. Returns the HTTP status code, or 0 on transport failure. */
function webpush_send(array $sub, string $payload): int
{
    if (!webpush_configured()) {
        return 0;
    }
    $body = webpush_encrypt($payload, $sub['p256dh'], $sub['auth']);
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 2419200',
        'Authorization: ' . webpush_vapid_header($sub['endpoint']),
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    // Fallback without cURL.
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($sub['endpoint'], false, $ctx);
    if (isset($http_response_header[0]) &&
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        return (int)$m[1];
    }
    return $resp === false ? 0 : 200;
}
