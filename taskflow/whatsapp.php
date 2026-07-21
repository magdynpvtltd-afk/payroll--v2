<?php
/**
 * WhatsApp media sending for the task "Share" button.
 *
 * The wa.me / whatsapp:// URL schemes carry TEXT ONLY, so native media has to
 * go through a backend API. This layer is provider-agnostic: pick a gateway
 * with WA_PROVIDER in db.php and fill its credentials. Dependency-free (curl).
 *
 * Include AFTER db.php and uploads.php.
 *
 * Public helper:  wa_send_task(string $toPhone, string $text, array $media): [bool ok, string msg]
 *   $media = list of ['url' => ..., 'name' => ..., 'mime' => ...]
 *   Sends $text as the caption of the first attachment; each further
 *   attachment follows as its own message. Text-only when $media is empty.
 */

/**
 * Build the WhatsApp share text for a task. Uses WhatsApp's *bold* styling and
 * puts each download URL on its own line so it stays tappable/auto-linked.
 * $attLinks = list of ['name' => ..., 'url' => ...] (already public/signed);
 * pass [] to omit the attachments section (e.g. when files go as native media).
 */
function wa_task_message(string $title, string $statusLabel, string $taskUrl, array $attLinks = []): string
{
    $msg = "*Task:* {$title}\n*Status:* {$statusLabel}\n{$taskUrl}";
    if ($attLinks) {
        $max   = 10;                       // keep the message under wa.me URL limits
        $shown = array_slice($attLinks, 0, $max);
        $msg  .= "\n\n*" . (count($attLinks) === 1 ? 'Attachment' : 'Attachments') . "*";
        foreach ($shown as $a) {
            $msg .= "\n\n📎 {$a['name']}\n{$a['url']}";
        }
        if (count($attLinks) > $max) {
            $msg .= "\n\n…and " . (count($attLinks) - $max) . ' more on the task page.';
        }
    }
    return $msg;
}

/** True when the selected provider has enough config to attempt a send. */
function wa_api_configured(): bool
{
    switch (WA_PROVIDER) {
        case 'twilio':   return WA_TWILIO_SID !== '' && WA_TWILIO_TOKEN !== '' && WA_TWILIO_FROM !== '';
        case 'meta':     return WA_META_TOKEN !== '' && WA_META_PHONE_NUMBER_ID !== '';
        case 'ultramsg': return WA_ULTRAMSG_INSTANCE !== '' && WA_ULTRAMSG_TOKEN !== '';
        case 'webhook':  return WHATSAPP_WEBHOOK_URL !== '';
        default:         return false;
    }
}

/**
 * Public base URL a WhatsApp gateway will use to FETCH attachment media.
 * Prefers PUBLIC_BASE_URL, then APP_URL, then the current request host.
 */
function public_base_url(): string
{
    if (defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL !== '') return rtrim(PUBLIC_BASE_URL, '/');
    if (APP_URL !== '') return rtrim(APP_URL, '/');
    return app_base_url();
}

/** True if a URL's host is reachable from the public internet (not loopback/private/.local). */
function url_is_public(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return false;
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) return false;
    if (str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)
        && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false; // private / reserved IP range
    }
    return true;
}

/**
 * Send a task (text + native media) to one recipient over WhatsApp.
 * @return array [bool ok, string message]
 */
function wa_send_task(string $toPhone, string $text, array $media): array
{
    if (!wa_api_configured()) return [false, 'WhatsApp API is not configured (set WA_PROVIDER in db.php).'];
    if (!function_exists('curl_init')) return [false, 'The PHP curl extension is required to send WhatsApp media.'];
    $toPhone = wa_number($toPhone);
    if ($toPhone === '') return [false, 'The recipient has no phone number on file.'];

    if ($media) {
        // Media must be publicly downloadable by the gateway, and signed so it
        // needs no login (attachment.php only serves signed links without auth).
        if (ATTACH_LINK_SECRET === '') {
            return [false, 'Set ATTACH_LINK_SECRET in db.php so attachment links are publicly downloadable.'];
        }
        foreach ($media as $m) {
            if (!url_is_public($m['url'])) {
                return [false, 'Attachment URLs are not publicly reachable (host in "' . $m['url'] . '"). '
                             . 'Set PUBLIC_BASE_URL in db.php to a public https URL (in dev, an ngrok/tunnel URL).'];
            }
        }
    }

    switch (WA_PROVIDER) {
        case 'twilio':   return wa_send_twilio($toPhone, $text, $media);
        case 'meta':     return wa_send_meta($toPhone, $text, $media);
        case 'ultramsg': return wa_send_ultramsg($toPhone, $text, $media);
        case 'webhook':  return wa_send_webhook($toPhone, $text, $media);
        default:         return [false, 'Unknown WhatsApp provider "' . e(WA_PROVIDER) . '".'];
    }
}

// ------------------------------------------------------------------
// Shared orchestration + HTTP
// ------------------------------------------------------------------

/**
 * Run one text+media conversation through a provider's per-message sender.
 * $sendOne = fn(string $body, ?array $mediaItem): array [int httpCode, string body]
 */
function wa_dispatch(string $text, array $media, callable $sendOne, string $label): array
{
    $results = [];
    if (!$media) {
        $results[] = $sendOne($text, null);                 // text-only
    } else {
        $first = true;
        foreach ($media as $m) {
            $results[] = $sendOne($first ? $text : '', $m); // caption on first, then bare media
            $first = false;
        }
    }
    $failed = [];
    foreach ($results as $i => [$code, $body]) {
        if ($code < 200 || $code >= 300) $failed[] = 'msg ' . ($i + 1) . ' [' . $code . '] ' . wa_trim($body);
    }
    if (!$failed) return [true, 'Sent ' . count($results) . ' WhatsApp message(s) via ' . $label . '.'];
    return [false, $label . ' failed on ' . count($failed) . '/' . count($results) . ' message(s): ' . implode('; ', $failed)];
}

/** Minimal POST. $body: string (raw) or array (form-encoded). Returns [httpCode, responseBody]. */
function wa_http_post(string $url, $body, array $headers = [], ?array $basic = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => is_array($body) ? http_build_query($body) : $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ]);
    if ($basic) curl_setopt($ch, CURLOPT_USERPWD, $basic[0] . ':' . $basic[1]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); return [0, $err]; }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$resp];
}

/** Collapse + shorten a response body for a flash message. */
function wa_trim(string $s): string
{
    $s = trim((string)preg_replace('/\s+/', ' ', $s));
    return strlen($s) > 180 ? substr($s, 0, 180) . '…' : $s;
}

/** Map a mime type to a WhatsApp media category (used by Meta + UltraMsg). */
function wa_media_type(string $mime): string
{
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    return 'document';
}

// ------------------------------------------------------------------
// Providers
// ------------------------------------------------------------------

/** Twilio Programmable Messaging (WhatsApp). Media via public MediaUrl. */
function wa_send_twilio(string $to, string $text, array $media): array
{
    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(WA_TWILIO_SID) . '/Messages.json';
    $toWa = 'whatsapp:+' . ltrim($to, '+');
    $sendOne = function (string $body, ?array $m) use ($url, $toWa): array {
        $f = 'From=' . rawurlencode(WA_TWILIO_FROM) . '&To=' . rawurlencode($toWa);
        if ($body !== '') $f .= '&Body=' . rawurlencode($body);
        if ($m)           $f .= '&MediaUrl=' . rawurlencode($m['url']);
        return wa_http_post($url, $f,
            ['Content-Type: application/x-www-form-urlencoded'], [WA_TWILIO_SID, WA_TWILIO_TOKEN]);
    };
    return wa_dispatch($text, $media, $sendOne, 'Twilio');
}

/** Meta / WhatsApp Business Cloud API. Media by public link, one per message. */
function wa_send_meta(string $to, string $text, array $media): array
{
    $url = 'https://graph.facebook.com/' . WA_META_API_VERSION
         . '/' . rawurlencode(WA_META_PHONE_NUMBER_ID) . '/messages';
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . WA_META_TOKEN];
    $sendOne = function (string $body, ?array $m) use ($url, $headers, $to): array {
        if (!$m) {
            $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
                        'text' => ['body' => $body !== '' ? $body : ' ']];
        } else {
            $type = wa_media_type($m['mime']);
            $obj  = ['link' => $m['url']];
            if ($type === 'document') $obj['filename'] = $m['name'];
            // Meta allows captions on image/video/document (not audio).
            if ($body !== '' && $type !== 'audio') $obj['caption'] = $body;
            $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $type, $type => $obj];
        }
        return wa_http_post($url, json_encode($payload), $headers);
    };
    return wa_dispatch($text, $media, $sendOne, 'Meta Cloud API');
}

/** UltraMsg gateway. Per-type endpoints; media by public url + caption. */
function wa_send_ultramsg(string $to, string $text, array $media): array
{
    $base = 'https://api.ultramsg.com/' . rawurlencode(WA_ULTRAMSG_INSTANCE) . '/messages/';
    $sendOne = function (string $body, ?array $m) use ($base, $to): array {
        $hdr = ['Content-Type: application/x-www-form-urlencoded'];
        if (!$m) {
            return wa_http_post($base . 'chat',
                ['token' => WA_ULTRAMSG_TOKEN, 'to' => $to, 'body' => $body !== '' ? $body : ' '], $hdr);
        }
        $type   = wa_media_type($m['mime']);
        $fields = ['token' => WA_ULTRAMSG_TOKEN, 'to' => $to];
        switch ($type) {
            case 'image': $fields['image'] = $m['url']; $fields['caption'] = $body; break;
            case 'video': $fields['video'] = $m['url']; $fields['caption'] = $body; break;
            case 'audio': $fields['audio'] = $m['url']; break;
            default:      $fields['document'] = $m['url']; $fields['filename'] = $m['name']; $fields['caption'] = $body;
        }
        return wa_http_post($base . $type, $fields, $hdr);
    };
    return wa_dispatch($text, $media, $sendOne, 'UltraMsg');
}

/** Generic webhook: hand the whole thing (text + attachments[]) to your own gateway. */
function wa_send_webhook(string $to, string $text, array $media): array
{
    $json = json_encode([
        'event'       => 'task_shared',
        'to'          => $to,
        'message'     => $text,
        'attachments' => array_map(fn($m) => ['url' => $m['url'], 'name' => $m['name'], 'mime' => $m['mime']], $media),
    ]);
    $headers = ['Content-Type: application/json'];
    if (WHATSAPP_WEBHOOK_TOKEN !== '') $headers[] = 'Authorization: Bearer ' . WHATSAPP_WEBHOOK_TOKEN;
    [$code, $body] = wa_http_post(WHATSAPP_WEBHOOK_URL, $json, $headers);
    if ($code >= 200 && $code < 300) return [true, 'Handed ' . count($media) . ' attachment(s) to your webhook.'];
    return [false, 'Webhook error [' . $code . ']: ' . wa_trim($body)];
}
