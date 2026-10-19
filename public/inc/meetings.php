<?php
declare(strict_types=1);
// Online meetings (4.10.0): each staff member connects their own Zoom and Microsoft (Teams)
// account on My Account (owner decision 2026-10-16), and a remote session booked for them can
// get its meeting link made in that account. An administrator registers the app with Zoom
// and Microsoft once (Settings, Online meetings; the Administrator guide has the steps).
// Tokens are kept encrypted (AES-256-GCM) with a key file in the private directory, never in
// the database or its backups; after a restore to another server, people connect again.

const MEETING_PROVIDERS = ['zoom' => 'Zoom', 'microsoft' => 'Microsoft Teams'];
// Microsoft's connection also asks for Calendars.ReadWrite (4.20.0): the person's own calendar, for
// inc/calendar_sync.php. What each person actually granted is kept (meeting_accounts.granted_scope) and
// a renewal asks for that only: Microsoft refuses a renewal asking for more than was granted, which would
// otherwise break Teams links for everyone who connected before the calendar was added.
const MEETING_MS_LEGACY_SCOPE = 'offline_access User.Read OnlineMeetings.ReadWrite';

/** The registered app for a provider, or null when an administrator has not set it up. */
function meeting_app(string $provider): ?array {
    global $CONFIG;
    $m = is_array($CONFIG['meet'] ?? null) ? $CONFIG['meet'] : [];
    $base = rtrim((string)cfg('base_url'), '/');
    if ($provider === 'zoom' && !empty($m['zoom_client_id']) && !empty($m['zoom_client_secret'])) {
        return ['id' => (string)$m['zoom_client_id'], 'secret' => (string)$m['zoom_client_secret'],
            'authorize' => 'https://zoom.us/oauth/authorize', 'token' => 'https://zoom.us/oauth/token',
            'scope' => '', 'redirect' => $base . '/meeting_connect.php?provider=zoom'];
    }
    if ($provider === 'microsoft' && !empty($m['ms_client_id']) && !empty($m['ms_client_secret'])) {
        $tenant = preg_match('/^[A-Za-z0-9.-]+$/', (string)($m['ms_tenant'] ?? '')) ? (string)$m['ms_tenant'] : 'organizations';
        return ['id' => (string)$m['ms_client_id'], 'secret' => (string)$m['ms_client_secret'],
            'authorize' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize", 'token' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            'scope' => 'offline_access User.Read OnlineMeetings.ReadWrite Calendars.ReadWrite', 'redirect' => $base . '/meeting_connect.php?provider=microsoft'];
    }
    return null;
}

/* ---------- Encryption of stored tokens ---------- */

/**
 * How many stored values need the key file (4.28.0): authenticator seeds stored encrypted
 * ("v1:"), connected meeting accounts and the QuickBooks connection. A table or column
 * not there yet counts as none.
 */
function meeting_key_dependents(): int {
    $n = 0;
    foreach (["SELECT COUNT(*) FROM users WHERE totp_secret LIKE 'v1:%'",
              "SELECT COUNT(*) FROM meeting_accounts WHERE access_token <> '' OR refresh_token <> ''",
              "SELECT COUNT(*) FROM qbo_connection WHERE access_token <> '' OR refresh_token <> ''"] as $sql) {
        try { $n += (int)db()->query($sql)->fetchColumn(); } catch (Throwable $e) {}
    }
    return $n;
}

/** 'ok', 'none' (no key file and nothing needs one: it is made on first use), 'missing'
 *  (no key file, but stored values need it), 'unreadable' (damaged, unreadable or readable by
 *  others), or 'unknown' (the private directory is not known). Never creates the key. */
function meeting_key_status(): string {
    $dir = (string)($GLOBALS['CMS_PRIVATE_DIR'] ?? '');
    if ($dir === '' || !is_dir($dir)) return 'unknown';
    $file = $dir . '/meeting_tokens.key';
    clearstatcache(true, $file);
    if (!is_file($file)) return meeting_key_dependents() > 0 ? 'missing' : 'none';
    if (!is_readable($file) || (fileperms($file) & 0077) !== 0) return 'unreadable';
    $k = base64_decode(trim((string)@file_get_contents($file)), true);
    return ($k === false || strlen($k) !== 32) ? 'unreadable' : 'ok';
}

/** The 32-byte key, made once in the private directory (beside config.php), readable only by its owner. */
function meeting_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    $dir = (string)($GLOBALS['CMS_PRIVATE_DIR'] ?? '');
    if ($dir === '' || !is_dir($dir)) throw new RuntimeException('The private directory is not known, so meeting accounts cannot be stored.');
    $file = $dir . '/meeting_tokens.key';
    if (!is_file($file) && meeting_key_dependents() > 0) {
        // 4.28.0: never a new key while stored values need the old one (after a restore or a
        // move without the key file): a new key would make them unreadable for good, and
        // System health could no longer tell. Restore the old key file instead.
        error_log('meeting_key: ' . $file . ' is missing, but stored authenticator seeds or connection tokens need it; no new key was made. Copy the old meeting_tokens.key back (chmod 600).');
        throw new RuntimeException('The key file meeting_tokens.key is missing from the private directory, but stored values need it. Copy the old key file back.');
    }
    if (!is_file($file)) {
        // Written to a temporary file and linked into place, so two requests at once can never
        // both make a key, nor read a half-written one: the second link fails and it reads the first.
        $old = umask(077);
        $tmp = $file . '.' . bin2hex(random_bytes(6));
        $ok = @file_put_contents($tmp, base64_encode(random_bytes(32)));
        umask($old);
        if ($ok === false) throw new RuntimeException('The meeting key could not be written in the private directory.');
        @link($tmp, $file);
        @unlink($tmp);
        if (!is_file($file)) throw new RuntimeException('The meeting key could not be written in the private directory.');
    }
    clearstatcache(true, $file);
    if ((fileperms($file) & 0077) !== 0) throw new RuntimeException('The meeting key file can be read by others; make it readable only by its owner (chmod 600).');
    $k = base64_decode(trim((string)file_get_contents($file)), true);
    if ($k === false || strlen($k) !== 32) throw new RuntimeException('The meeting key file is damaged.');
    return $key = $k;
}

function meeting_seal(string $plain): string {
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', meeting_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return base64_encode($iv . $tag . $ct);
}

/** The plain text, or null when it cannot be read (another server's key, or damaged). */
function meeting_open(string $sealed): ?string {
    $raw = base64_decode($sealed, true);
    if ($raw === false || strlen($raw) < 29) return null;
    // A missing or damaged key file reads as "cannot be read" (4.28.0), as a wrong key always has.
    try { $key = meeting_key(); } catch (Throwable $e) { error_log('meeting_open: ' . $e->getMessage()); return null; }
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? null : $out;
}

/* ---------- HTTP (replaceable in tests) ---------- */

/** POST or GET returning [status, decoded JSON or null]. Tests set CMS_TEST_MEETINGS to a JSON map of URL => reply. */
function meeting_http(string $method, string $url, array $headers, ?string $body): array {
    if (PHP_SAPI === 'cli' && ($fake = getenv('CMS_TEST_MEETINGS')) !== false && $fake !== '') {
        $map = (array)json_decode($fake, true);
        foreach ($map as $prefix => $reply) if (str_starts_with($url, $prefix)) return [(int)($reply['status'] ?? 200), $reply['body'] ?? null];
        return [599, null];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($resp) ? json_decode($resp, true) : null];
}

/** Exchange an authorization code, or a refresh token, for tokens. Returns the reply or null. */
function meeting_token_request(array $app, array $fields): ?array {
    // One way of proving the app per request (RFC 6749 2.3): Zoom takes Basic auth, Microsoft the body.
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($app['scope'] === '') $headers[] = 'Authorization: Basic ' . base64_encode($app['id'] . ':' . $app['secret']);
    else $fields += ['client_id' => $app['id'], 'client_secret' => $app['secret']];
    [$code, $data] = meeting_http('POST', $app['token'], $headers, http_build_query($fields));
    return $code === 200 && is_array($data) && !empty($data['access_token']) ? $data : null;
}

/* ---------- Connected accounts ---------- */

/** This person's connection for a provider (with the email it signed in as), or null. */
function meeting_account(int $userId, string $provider): ?array {
    try {
        $q = db()->prepare('SELECT * FROM meeting_accounts WHERE user_id = ? AND provider = ?');
        $q->execute([$userId, $provider]);
        return $q->fetch() ?: null;
    } catch (PDOException $e) {
        return null;   // before migration 4.10.0
    }
}

/** Keep tokens from a token reply. */
function meeting_store(int $userId, string $provider, array $tok, string $email): void {
    $expires = date('Y-m-d H:i:s', time() + max(60, (int)($tok['expires_in'] ?? 3600)) - 60);
    // The scope Microsoft granted, when the reply names it (Zoom's does not; it is not used for Zoom).
    $granted = is_string($tok['scope'] ?? null) && $tok['scope'] !== '' ? mb_substr(trim($tok['scope']), 0, 500) : null;
    db()->prepare('INSERT INTO meeting_accounts (user_id, provider, account_email, access_token, refresh_token, expires_at, granted_scope) VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE account_email = VALUES(account_email), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at),
            granted_scope = COALESCE(VALUES(granted_scope), granted_scope)')
        ->execute([$userId, $provider, mb_substr($email, 0, 190), meeting_seal((string)$tok['access_token']), meeting_seal((string)($tok['refresh_token'] ?? '')), $expires, $granted]);
}

/** A working access token for this person, refreshing it when it has expired; null when not connected or refused. */
function meeting_access_token(int $userId, string $provider): ?string {
    $acc = meeting_account($userId, $provider);
    $app = meeting_app($provider);
    if (!$acc || !$app) return null;
    if (strtotime((string)$acc['expires_at']) > time()) return meeting_open((string)$acc['access_token']);
    // Renewed under a lock on the row: Zoom replaces the refresh token each time, so two
    // bookings at once must not both spend the same one. The second finds the first's result.
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        $q = db()->prepare('SELECT * FROM meeting_accounts WHERE user_id = ? AND provider = ? FOR UPDATE');
        $q->execute([$userId, $provider]);
        $acc = $q->fetch();
        if (!$acc) { if ($own) db()->rollBack(); return null; }
        if (strtotime((string)$acc['expires_at']) > time()) { if ($own) db()->commit(); return meeting_open((string)$acc['access_token']); }
        $refresh = meeting_open((string)$acc['refresh_token']);
        $tok = null;
        if ($refresh !== null && $refresh !== '') {
            $fields = ['grant_type' => 'refresh_token', 'refresh_token' => $refresh];
            // Only what this person granted (a connection from before 4.20.0 granted the legacy scope).
            if ($app['scope'] !== '') $fields['scope'] = trim((string)($acc['granted_scope'] ?? '')) ?: MEETING_MS_LEGACY_SCOPE;
            $tok = meeting_token_request($app, $fields);
        }
        if ($tok !== null) {
            $tok['refresh_token'] ??= $refresh;
            meeting_store($userId, $provider, $tok, (string)$acc['account_email']);
        }
        if ($own) db()->commit();
        return $tok !== null ? (string)$tok['access_token'] : null;
    } catch (Throwable $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

/** Forget a connection. Zoom is also told to withdraw it; Microsoft has no such call for one
 * app's tokens (the person removes the app under their Microsoft account's app permissions). */
function meeting_disconnect(int $userId, string $provider): void {
    $acc = meeting_account($userId, $provider);
    $app = meeting_app($provider);
    if ($acc && $app && $provider === 'zoom') {
        try {
            $tok = meeting_open((string)$acc['refresh_token']) ?: meeting_open((string)$acc['access_token']);
            if ($tok) meeting_http('POST', 'https://zoom.us/oauth/revoke', ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($app['id'] . ':' . $app['secret'])], http_build_query(['token' => $tok]));
        } catch (Throwable $e) {
            error_log('meeting_disconnect: ' . get_class($e));
        }
    }
    db()->prepare('DELETE FROM meeting_accounts WHERE user_id = ? AND provider = ?')->execute([$userId, $provider]);
}

/**
 * Make a meeting in this person's account. One link serves a whole series (Zoom: a recurring
 * meeting with no fixed time; Teams: a meeting open from the first start to the last end).
 * Returns the join link, or null with $why set.
 */
function meeting_create(int $userId, string $provider, string $topic, int $start, int $end, bool $series, ?string &$why = null): ?string {
    $token = meeting_access_token($userId, $provider);
    if ($token === null) { $why = MEETING_PROVIDERS[$provider] . ' is not connected for this instructor, or its connection has expired: they can connect again on My Account.'; return null; }
    $auth = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    if ($provider === 'zoom') {
        $body = ['topic' => mb_substr($topic, 0, 200), 'type' => $series ? 3 : 2, 'duration' => max(15, (int)round(($end - $start) / 60)),
            'start_time' => gmdate('Y-m-d\TH:i:s\Z', $start), 'timezone' => date_default_timezone_get(),
            'settings' => ['waiting_room' => true, 'join_before_host' => false]];
        [$code, $data] = meeting_http('POST', 'https://api.zoom.us/v2/users/me/meetings', $auth, json_encode($body));
        $url = $data['join_url'] ?? null;
    } else {
        $body = ['subject' => mb_substr($topic, 0, 200), 'startDateTime' => gmdate('Y-m-d\TH:i:s\Z', $start), 'endDateTime' => gmdate('Y-m-d\TH:i:s\Z', $end)];
        [$code, $data] = meeting_http('POST', 'https://graph.microsoft.com/v1.0/me/onlineMeetings', $auth, json_encode($body));
        $url = $data['joinWebUrl'] ?? null;
    }
    if ($code < 200 || $code >= 300 || !is_string($url) || strlen($url) > 500 || !preg_match('~^https://\S+$~', $url)) {
        $why = MEETING_PROVIDERS[$provider] . ' did not make the meeting (reply ' . $code . '). The session is booked without a link; add one by moving the session, or try again.';
        return null;
    }
    return $url;
}
