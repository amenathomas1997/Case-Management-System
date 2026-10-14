<?php
declare(strict_types=1);
// Microsoft Graph mail sender — client credentials flow, app-only Mail.Send.

function graph_cfg(): ?array {
    global $CONFIG;
    $g = $CONFIG['graph'] ?? null;
    if (!$g || empty($g['tenant_id']) || empty($g['client_id']) || empty($g['client_secret']) || empty($g['sender'])) {
        return null;
    }
    return $g;
}

/**
 * An app-only Graph token. $fresh skips both caches and discards the cached
 * file: Graph refused the old token (see graph_retry_token()).
 */
function graph_token(bool $fresh = false): ?string {
    // Within one request the token is fetched at most once, whatever the cache.
    static $mem = null;
    if (!$fresh && is_array($mem) && $mem['expires'] > time() + 60) return $mem['token'];
    $g = graph_cfg();
    if (!$g) return null;

    // Cached beside the private config: token_cache when set, else the private
    // directory that holds uploads. Never a shared temp folder (the token can
    // send mail), so with neither there is simply no cache. The file is named
    // after the tenant, app and secret, so correcting any of them never reuses
    // a token issued to the old settings.
    $cacheDir = !empty($g['token_cache']) ? dirname((string)$g['token_cache'])
        : (cfg('upload_dir') ? dirname((string)cfg('upload_dir')) : '');
    $cacheKey = substr(hash('sha256', $g['tenant_id'] . '|' . $g['client_id'] . '|' . hash('sha256', (string)$g['client_secret'])), 0, 32);
    $cacheFile = $cacheDir !== '' ? $cacheDir . '/graph_token_' . $cacheKey . '.json' : '';
    if ($fresh && $cacheFile !== '' && is_file($cacheFile)) @unlink($cacheFile);
    if (!$fresh && $cacheFile !== '' && is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            return $cached['token'];
        }
    }

    $ch = curl_init("https://login.microsoftonline.com/{$g['tenant_id']}/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $g['client_id'],
            'client_secret' => $g['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!is_string($resp)) return null;
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) {
        error_log('Graph token error: ' . substr($resp, 0, 500));
        return null;
    }
    $mem = ['token' => $data['access_token'], 'expires' => time() + (int)($data['expires_in'] ?? 3600)];
    // Written to a new file that is made owner-only BEFORE the token goes in,
    // then moved over the cache in one step: the token is never in a file
    // others could read, and a reader never sees half a file.
    $saved = false;
    if ($cacheFile !== '' && is_dir($cacheDir)) {
        $tmp = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $fh = @fopen($tmp, 'x');
        if ($fh !== false) {
            $saved = chmod($tmp, 0600) && fwrite($fh, (string)json_encode($mem)) !== false;
            fclose($fh);
            $saved = $saved && rename($tmp, $cacheFile);
            if (!$saved) @unlink($tmp);
        }
    }
    if (!$saved) {
        // Every send would otherwise fetch a new token without anyone knowing why.
        error_log('Graph token cache not writable or could not be made owner-only (set graph.token_cache in config.php); fetching a token per request.');
    }
    return $data['access_token'];
}

/**
 * After Graph refused a request's token, a newly issued token to retry it with
 * once, or null. A 401 always gets one; a 403 only the first time in a request:
 * an app-only token keeps the permissions it was issued with, so one cached
 * before Mail.Send was consented is refused until it expires, while a 403 that
 * a new token does not cure is a real permission refusal.
 */
function graph_retry_token(int $code): ?string {
    static $retried403 = false;
    if ($code === 403) {
        if ($retried403) return null;
        $retried403 = true;
    } elseif ($code !== 401) {
        return null;
    }
    return graph_token(true);
}

/**
 * Low-level Graph call. Returns [httpCode, decodedBody]. $headers are extra
 * request headers, such as 'Prefer: outlook.body-content-type="text"'.
 */
function graph_api(string $method, string $path, ?array $payload = null, array $headers = []): array {
    $token = graph_token();
    if (!$token) return [0, null];
    for ($try = 0; ; $try++) {
        $ch = curl_init('https://graph.microsoft.com/v1.0' . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT => 30,
        ];
        if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        elseif ($method === 'POST') $opts[CURLOPT_POSTFIELDS] = ''; // body-less POST still needs Content-Length
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        // A refused token means the request was not carried out, so it is safe to resend.
        if ($try === 0 && ($fresh = graph_retry_token($code)) !== null) { $token = $fresh; continue; }
        return [$code, is_string($resp) && $resp !== '' ? json_decode($resp, true) : null];
    }
}

/**
 * Send mail via Graph as the configured sender. Returns true on success.
 * $attachments: list of ['name' => ..., 'mime' => ..., 'bytes' => raw binary string].
 * Direct sendMail rejects attachments for this tenant (ErrorAccessDenied), so
 * attachment sends go through the draft -> attach -> send flow instead.
 */
function graph_send_mail(string $to, string $subject, string $body, bool $isHtml = false, ?string $cc = null, array $attachments = []): bool {
    $g = graph_cfg();
    $token = $g ? graph_token() : null;
    if (!$g || !$token) return false;
    $sender = '/users/' . rawurlencode($g['sender']);

    $message = [
        'subject' => $subject,
        'body' => ['contentType' => $isHtml ? 'HTML' : 'Text', 'content' => $body],
        'toRecipients' => [['emailAddress' => ['address' => $to]]],
    ];
    if ($cc) $message['ccRecipients'] = [['emailAddress' => ['address' => $cc]]];

    if (!$attachments) {
        // No copy in Sent Items: app mail carries sign-in codes, reset and portal
        // links and client details, and a mailbox copy would sit outside the
        // app's retention and trash controls (the app keeps its own records).
        [$code] = graph_api('POST', $sender . '/sendMail', ['message' => $message, 'saveToSentItems' => false]);
        if ($code !== 202) error_log("Graph sendMail failed with HTTP $code");
        return $code === 202;
    }

    // Attachment sends: this tenant rejects JSON sendMail-with-attachments and
    // draft /send (ErrorAccessDenied), so post the message as raw MIME instead —
    // that path uses the same permission as plain sendMail, which works.
    // Graph has no saveToSentItems switch for MIME sends, so these copies stay in
    // the sender's Sent Items: a mailbox retention policy has to clear them.
    // Header values are built by hand here, so strip CR/LF (header injection)
    // and MIME-encode the subject (it may contain names from public forms).
    $hdr = fn(string $s) => preg_replace('/[\r\n\x00]+/', ' ', $s);
    $to = $hdr($to);
    $cc = $cc !== null ? $hdr($cc) : null;
    $encSubject = mb_encode_mimeheader($hdr($subject), 'UTF-8', 'B', "\r\n");
    // The display name is encoded as send_app_mail() does for mail(): non-ASCII
    // as an encoded word, and an ASCII name with RFC 5322 specials (a comma in
    // "Acme, Inc.") as a quoted-string, or the From header becomes a broken
    // mailbox list. With no name, the address stands alone.
    $fromName = trim($hdr((string)cfg('mail_from_name')));
    if (preg_match('/[^\x20-\x7E]/', $fromName)) $fromName = mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n");
    elseif (preg_match('/[()<>\[\]:;@\\\\,."]/', $fromName)) $fromName = '"' . addcslashes($fromName, '"\\') . '"';
    $boundary = 'cms' . bin2hex(random_bytes(12));
    $mime = 'From: ' . ($fromName !== '' ? "{$fromName} <{$g['sender']}>" : $g['sender']) . "\r\n"
        . "To: {$to}\r\n"
        . ($cc ? "Cc: {$cc}\r\n" : '')
        . "Subject: {$encSubject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: " . ($isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    foreach ($attachments as $a) {
        $safeName = preg_replace('/[\r\n\x00"]+/', '', $a['name']);
        // Constrain the MIME type so it can't inject headers: a valid type/subtype,
        // plus only the parameters a caller needs (an invite's method=REQUEST).
        $parts = array_map('trim', explode(';', (string)($a['mime'] ?? '')));
        $base = array_shift($parts);
        $safeMime = preg_match('#^[A-Za-z0-9.+\-]+/[A-Za-z0-9.+\-]+$#D', $base) ? $base : 'application/octet-stream';
        if ($safeMime !== 'application/octet-stream') {
            foreach ($parts as $p) {
                if (preg_match('#^(?:method=(?:REQUEST|PUBLISH|CANCEL|REPLY)|charset=[A-Za-z0-9\-]+)$#Di', $p)) $safeMime .= '; ' . $p;
            }
        }
        $mime .= "--{$boundary}\r\n"
            . "Content-Type: {$safeMime}; name=\"{$safeName}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n"
            . chunk_split(base64_encode($a['bytes']), 76, "\r\n");
    }
    $mime .= "--{$boundary}--\r\n";

    for ($try = 0; ; $try++) {
        $ch = curl_init('https://graph.microsoft.com/v1.0' . $sender . '/sendMail');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => base64_encode($mime),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: text/plain'],
            CURLOPT_TIMEOUT => 60,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($try === 0 && ($fresh = graph_retry_token($code)) !== null) { $token = $fresh; continue; }
        break;
    }
    if ($code !== 202) error_log("Graph MIME sendMail failed with HTTP $code");
    return $code === 202;
}
