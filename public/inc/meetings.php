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

/** The key could not be used (4.29.0): missing while stored values need it, damaged, or
 *  readable by others. Its message names the file and the way out. */
final class MeetingKeyUnavailable extends RuntimeException {}

/** The key file exists but the site cannot open it (4.29.0): it may still be the right key. */
const MEETING_KEY_ACCESS_MSG = 'Nothing was forgotten: the website cannot open the key file meeting_tokens.key (it belongs to another user, perhaps because it was copied back as root, or its mode does not let the site read it). It may still be the right key. Make it owned by the user the website runs as (chown), readable only by that user (chmod 600), then check again.';

/** What the people connecting QuickBooks or a meeting account are told when the key cannot be used. */
const MEETING_KEY_LOST_MSG = "the server's key file (meeting_tokens.key) is missing or damaged, so no connection can be stored. An administrator can copy the old key file back, or use \"Forget stored connections and keys\" on System health.";

/**
 * What is wrong with the key file, for System health and its "Forget stored connections
 * and keys" action (4.29.0, P14): null (nothing), 'missing' (gone while stored values need
 * it), 'damaged' (read, but not a key), 'access' (the site cannot open the file: wrong owner or
 * mode; it may be a good key, so nothing is forgotten), 'permissions' (a good key readable by others:
 * chmod 600 fixes it, nothing needs forgetting), 'mismatch' (a working key that cannot read
 * some stored values: it was replaced) or 'unknown' (no private directory).
 */
function meeting_key_problem(): ?string {
    $state = meeting_key_status();
    if ($state === 'unknown') return 'unknown';
    if ($state === 'missing') return 'missing';
    if ($state === 'unreadable') {
        $file = (string)$GLOBALS['CMS_PRIVATE_DIR'] . '/meeting_tokens.key';
        // A file the site cannot open (another owner, e.g. copied back as root) may be a good key:
        // never called damaged, so nothing is forgotten that a chown would have saved.
        if (!is_readable($file)) return 'access';
        $raw = @file_get_contents($file);
        if ($raw === false) return 'access';
        $k = base64_decode(trim($raw), true);
        return ($k !== false && strlen($k) === 32) ? 'permissions' : 'damaged';
    }
    if ($state !== 'ok') return null;
    return meeting_unreadable_counts() !== ['seeds' => 0, 'meetings' => 0, 'qbo' => 0] ? 'mismatch' : null;
}

/** How many stored values cannot be read with the key file as it is now: authenticator seeds
 *  ("v1:"), meeting accounts and the QuickBooks connection. A table not there yet counts as none. */
function meeting_unreadable_counts(): array {
    $n = ['seeds' => 0, 'meetings' => 0, 'qbo' => 0];
    try {
        foreach (db()->query("SELECT totp_secret FROM users WHERE totp_secret LIKE 'v1:%'")->fetchAll(PDO::FETCH_COLUMN) as $v)
            if (totp_secret_plain((string)$v) === null) $n['seeds']++;
    } catch (PDOException $e) {}
    foreach (['meetings' => 'meeting_accounts', 'qbo' => 'qbo_connection'] as $k => $table) {
        try {
            foreach (db()->query("SELECT access_token, refresh_token FROM `$table` WHERE access_token <> '' OR refresh_token <> ''")->fetchAll() as $r)
                if (!meeting_token_readable($r)) $n[$k]++;
        } catch (PDOException $e) {}
    }
    return $n;
}

/** Whether a stored connection's tokens open with the key as it is now (an empty one counts as readable). */
function meeting_token_readable(array $row): bool {
    foreach (['access_token', 'refresh_token'] as $c) {
        $v = (string)($row[$c] ?? '');
        if ($v !== '' && meeting_open($v) === null) return false;
    }
    return true;
}

/**
 * System health, "Forget stored connections and keys" (4.29.0, P14): the way out after the
 * key file is lost or replaced, when the old one cannot be copied back. Only what cannot be
 * read is forgotten:
 *  - each authenticator app whose seed cannot be read is turned off (as reset_2fa.php does for
 *    the app; passkeys and sessions are kept), the person is emailed, and it is audited;
 *  - each meeting account (anyone's) and the QuickBooks connection that cannot be read is removed;
 *  - a damaged key file is moved aside (meeting_tokens.key.damaged-<time>), never deleted;
 * then a new key file is made, so QuickBooks, meetings and authenticator apps can be set up again.
 * Returns ['ok' => bool, 'error' => ?string, 'seeds' => n, 'meetings' => n, 'qbo' => n].
 */
function meeting_forget_stored_keys(int $actorId): array {
    $problem = meeting_key_problem();
    $none = ['seeds' => 0, 'meetings' => 0, 'qbo' => 0];
    if ($problem === 'permissions') return $none + ['ok' => false, 'error' => 'Nothing was forgotten: the key file is fine but other users on the server can read it. Make it readable only by its owner (chmod 600 meeting_tokens.key in the private directory), then check again.'];
    if ($problem === 'access') return $none + ['ok' => false, 'error' => MEETING_KEY_ACCESS_MSG];
    if ($problem === 'unknown') return $none + ['ok' => false, 'error' => 'Nothing was forgotten: the private directory is not known, so the key file cannot be checked.'];
    if ($problem === null) return $none + ['ok' => false, 'error' => 'Nothing was forgotten: the key file is working and reads every stored value.'];
    $dir = (string)$GLOBALS['CMS_PRIVATE_DIR'];
    $file = $dir . '/meeting_tokens.key';
    $reset = [];
    $n = $none;
    $db = db();
    $db->beginTransaction();
    try {
        $q = $db->query("SELECT id, name, email, is_active, totp_secret FROM users WHERE totp_secret LIKE 'v1:%' FOR UPDATE");
        $off = $db->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_last_step = NULL WHERE id = ? AND totp_secret = ?');
        foreach ($q->fetchAll() as $u) {
            if (totp_secret_plain((string)$u['totp_secret']) !== null) continue;
            $off->execute([(int)$u['id'], (string)$u['totp_secret']]);
            if ($off->rowCount() === 1) { $reset[] = $u; $n['seeds']++; }
        }
        try {
            $rows = $db->query("SELECT id, user_id, provider, access_token, refresh_token FROM meeting_accounts WHERE access_token <> '' OR refresh_token <> '' FOR UPDATE")->fetchAll();
            $del = $db->prepare('DELETE FROM meeting_accounts WHERE id = ?');
            foreach ($rows as $r) if (!meeting_token_readable($r)) { $del->execute([(int)$r['id']]); $n['meetings'] += $del->rowCount(); }
        } catch (PDOException $e) { if ($e->getCode() !== '42S02' && $e->getCode() !== '42S22') throw $e; }
        try {
            $c = $db->query("SELECT access_token, refresh_token FROM qbo_connection WHERE id = 1 FOR UPDATE")->fetch();
            if ($c && !meeting_token_readable($c)) $n['qbo'] = $db->exec('DELETE FROM qbo_connection WHERE id = 1') ? 1 : 0;
        } catch (PDOException $e) { if ($e->getCode() !== '42S02' && $e->getCode() !== '42S22') throw $e; }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    // A damaged key file is kept beside, under another name, never deleted.
    clearstatcache(true, $file);
    $aside = null;
    if ($problem === 'damaged' && is_file($file)) {
        $aside = $file . '.damaged-' . date('Ymd-His');
        if (!@rename($file, $aside)) {
            return $n + ['ok' => false, 'error' => 'The stored values that could not be read were forgotten, but the damaged key file could not be moved aside. Rename or remove meeting_tokens.key in the private directory, then check again.'];
        }
    }
    // The new key (a replaced key that works is kept: it is the one now in use).
    $made = true;
    try { meeting_key(true); } catch (Throwable $e) { $made = false; error_log('meeting_forget_stored_keys: ' . $e->getMessage()); }
    audit($actorId, 'keys.forget', 'settings', null, "key file $problem: " . $n['seeds'] . ' authenticator app(s) turned off, ' . $n['meetings'] . ' meeting account(s) and '
        . $n['qbo'] . ' QuickBooks connection forgotten' . ($aside ? ', damaged key file moved to ' . basename($aside) : '') . ($made ? '' : '; no new key file could be made'));
    foreach ($reset as $u) {
        audit($actorId, 'user.reset_2fa', 'user', (int)$u['id'], 'authenticator app turned off by Forget stored connections and keys (its seed could not be read)');
        if ((int)$u['is_active'] === 1) {
            send_app_mail((string)$u['email'], 'Your authenticator app was turned off',
                "Hello {$u['name']},\n\nThe server for " . cfg('name') . " lost the key that protected authenticator apps, so an administrator turned yours off.\n"
                . "Sign in with your password and the code we email you, then set up the authenticator app again under My Account (remove the old entry from the app first).\n"
                . "If you did not expect this, contact your administrator.\n");
        }
    }
    if (!$made) return $n + ['ok' => false, 'error' => 'The stored values that could not be read were forgotten, but a new key file could not be made in the private directory. Check that the private directory can be written to, then check again.'];
    return $n + ['ok' => true, 'error' => null];
}

/** The 32-byte key, made once in the private directory (beside config.php), readable only by its owner.
 *  $reload drops the copy kept for this request (after System health forgot the old values). */
function meeting_key(bool $reload = false): string {
    static $key = null;
    if ($reload) $key = null;
    if ($key !== null) return $key;
    $dir = (string)($GLOBALS['CMS_PRIVATE_DIR'] ?? '');
    if ($dir === '' || !is_dir($dir)) throw new MeetingKeyUnavailable('The private directory is not known, so meeting accounts cannot be stored.');
    $file = $dir . '/meeting_tokens.key';
    if (!is_file($file) && meeting_key_dependents() > 0) {
        // 4.28.0: never a new key while stored values need the old one (after a restore or a
        // move without the key file): a new key would make them unreadable for good, and
        // System health could no longer tell. Restore the old key file, or (4.29.0) forget
        // those values with System health's "Forget stored connections and keys".
        error_log('meeting_key: ' . $file . ' is missing, but stored authenticator seeds or connection tokens need it; no new key was made. Copy the old meeting_tokens.key back (chmod 600), or use Forget stored connections and keys on System health.');
        throw new MeetingKeyUnavailable('The key file meeting_tokens.key is missing from the private directory, but stored values need it. Copy the old key file back, or use "Forget stored connections and keys" on System health.');
    }
    if (!is_file($file)) {
        // Written to a temporary file and linked into place, so two requests at once can never
        // both make a key, nor read a half-written one: the second link fails and it reads the first.
        $old = umask(077);
        $tmp = $file . '.' . bin2hex(random_bytes(6));
        $ok = @file_put_contents($tmp, base64_encode(random_bytes(32)));
        umask($old);
        if ($ok === false) throw new MeetingKeyUnavailable('The meeting key could not be written in the private directory.');
        @link($tmp, $file);
        @unlink($tmp);
        if (!is_file($file)) throw new MeetingKeyUnavailable('The meeting key could not be written in the private directory.');
    }
    clearstatcache(true, $file);
    if ((fileperms($file) & 0077) !== 0) throw new MeetingKeyUnavailable('The meeting key file can be read by others; make it readable only by its owner (chmod 600).');
    $k = base64_decode(trim((string)file_get_contents($file)), true);
    if ($k === false || strlen($k) !== 32) throw new MeetingKeyUnavailable('The meeting key file is damaged.');
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
        // Every request is kept (4.32.0), so tests check what is sent, not only what comes back.
        $GLOBALS['CMS_TEST_MEETING_REQUESTS'][] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (($log = getenv('CMS_TEST_MEETINGS_LOG')) !== false && $log !== '') file_put_contents($log, json_encode(end($GLOBALS['CMS_TEST_MEETING_REQUESTS'])) . "\n", FILE_APPEND);
        foreach ($map as $prefix => $reply) {
            if (!str_starts_with($url, $prefix)) continue;
            // A list of replies is answered in turn (the last one repeats): a refusal, then success.
            if (array_is_list($reply)) {
                $n = $GLOBALS['CMS_TEST_MEETING_SEEN'][$prefix] = ($GLOBALS['CMS_TEST_MEETING_SEEN'][$prefix] ?? -1) + 1;
                $reply = $reply[min($n, count($reply) - 1)];
            }
            return [(int)($reply['status'] ?? 200), $reply['body'] ?? null];
        }
        return [599, null];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($resp) ? json_decode($resp, true) : null];
}

/** Exchange an authorization code, or a refresh token, for tokens. Returns the reply or null. */
function meeting_token_request(array $app, array $fields, ?bool &$refused = null, ?string &$appProblem = null): ?array {
    // One way of proving the app per request (RFC 6749 2.3): Zoom takes Basic auth, Microsoft the body.
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($app['scope'] === '') $headers[] = 'Authorization: Basic ' . base64_encode($app['id'] . ':' . $app['secret']);
    else $fields += ['client_id' => $app['id'], 'client_secret' => $app['secret']];
    [$code, $data] = meeting_http('POST', $app['token'], $headers, http_build_query($fields));
    $ok = $code === 200 && is_array($data) && !empty($data['access_token']);
    // Refused (4.32.0): only when the person's own grant is no longer good (the app was removed,
    // a password changed, or the token went unused too long). Microsoft says invalid_grant; Zoom
    // says invalid_grant, or invalid_request "Invalid Token!" for a spent refresh token. A fault
    // of the app itself (its secret expired or changed, its permissions or tenant) is the
    // administrator's to fix and ends nobody's connection; anything else (no answer, a server
    // error, throttling) may pass, so the connection is kept for the next try.
    $err = is_array($data) ? strtolower((string)($data['error'] ?? '')) : '';
    $reason = is_array($data) ? strtolower((string)($data['reason'] ?? '')) : '';
    $refused = !$ok && in_array($code, [400, 401], true)
        && ($err === 'invalid_grant' || ($app['scope'] === '' && $err === 'invalid_request' && str_contains($reason, 'invalid token')));
    $appProblem = !$ok && !$refused && in_array($code, [400, 401], true) ? ($err !== '' ? $err : 'HTTP ' . $code) : null;
    if (!$ok) error_log('meeting token request refused: HTTP ' . $code . ' ' . mb_substr($err !== '' ? $err : $reason, 0, 80));
    return $ok ? $data : null;
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
    // A working connection again (4.32.0; the column comes with migration 4.32.0).
    try {
        db()->prepare('UPDATE meeting_accounts SET refused_at = NULL WHERE user_id = ? AND provider = ? AND refused_at IS NOT NULL')->execute([$userId, $provider]);
    } catch (PDOException $e) {}
}

/** When Zoom or Microsoft refused to renew this connection (4.32.0), or null while it works. */
function meeting_refused_at(?array $acc): ?string {
    $v = $acc['refused_at'] ?? null;
    return is_string($v) && $v !== '' ? $v : null;
}

/** A connection that still works: connected, and not refused at its last renewal (4.32.0). */
function meeting_connected(int $userId, string $provider): bool {
    $acc = meeting_account($userId, $provider);
    return $acc !== null && meeting_refused_at($acc) === null;
}

/**
 * A working access token for this person, refreshing it when it has expired or has less than
 * five minutes left (a calendar run uses one token for minutes); null when not connected or
 * refused. $force renews it whatever its time (the provider refused it before it expired:
 * the person removed the app). A refusal is kept on the connection (4.32.0), so My Account
 * says to connect again and the person is told once.
 */
function meeting_access_token(int $userId, string $provider, bool $force = false): ?string {
    $acc = meeting_account($userId, $provider);
    $app = meeting_app($provider);
    if (!$acc || !$app || meeting_refused_at($acc) !== null) return null;
    $margin = time() + 300;
    if (!$force && strtotime((string)$acc['expires_at']) > $margin) return meeting_open((string)$acc['access_token']);
    $before = (string)$acc['access_token'];
    // Renewed under a lock on the row: Zoom replaces the refresh token each time, so two
    // bookings at once must not both spend the same one. The second finds the first's result.
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        $q = db()->prepare('SELECT * FROM meeting_accounts WHERE user_id = ? AND provider = ? FOR UPDATE');
        $q->execute([$userId, $provider]);
        $acc = $q->fetch();
        if (!$acc || meeting_refused_at($acc) !== null) { if ($own) db()->rollBack(); return null; }
        // Another request renewed it while this one waited for the lock: use that.
        $renewedMeanwhile = (string)$acc['access_token'] !== $before;
        if ((!$force || $renewedMeanwhile) && strtotime((string)$acc['expires_at']) > $margin) { if ($own) db()->commit(); return meeting_open((string)$acc['access_token']); }
        // A token this server cannot read (the key file missing or replaced after a restore) is
        // the server's problem, not the person's: logged, never marked (System health shows the key).
        $refresh = meeting_open((string)$acc['refresh_token']);
        $tok = null;
        $refused = false; $appProblem = null;
        if ($refresh === null) {
            error_log('meeting_access_token: a stored refresh token could not be read (meeting_tokens.key)');
        } elseif ($refresh === '') {
            $refused = true;
        } else {
            $fields = ['grant_type' => 'refresh_token', 'refresh_token' => $refresh];
            // Only what this person granted (a connection from before 4.20.0 granted the legacy scope).
            if ($app['scope'] !== '') $fields['scope'] = trim((string)($acc['granted_scope'] ?? '')) ?: MEETING_MS_LEGACY_SCOPE;
            $tok = meeting_token_request($app, $fields, $refused, $appProblem);
        }
        if ($tok !== null) {
            $tok['refresh_token'] ??= $refresh;
            meeting_store($userId, $provider, $tok, (string)$acc['account_email']);
        } elseif ($refused) {
            meeting_mark_refused($userId, $provider, $acc);
        } elseif ($appProblem !== null) {
            meeting_app_refused($provider, $appProblem);
        }
        if ($own) db()->commit();
        return $tok !== null ? (string)$tok['access_token'] : null;
    } catch (Throwable $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

/**
 * Note that Zoom or Microsoft refused to renew this connection (4.32.0), and tell the person
 * once: their links are no longer made until they connect again on My Account. Before
 * migration 4.32.0 there is nowhere to keep it, and nothing is said.
 */
function meeting_mark_refused(int $userId, string $provider, array $acc): void {
    try {
        $u = db()->prepare('UPDATE meeting_accounts SET refused_at = NOW() WHERE user_id = ? AND provider = ? AND refused_at IS NULL');
        $u->execute([$userId, $provider]);
        if ($u->rowCount() !== 1) return;
    } catch (PDOException $e) {
        return;
    }
    $name = MEETING_PROVIDERS[$provider] ?? $provider;
    if (function_exists('notify_user')) notify_user($userId, 'Your ' . $name . ' connection has ended, so new remote sessions will not get ' . $name . ' links. Connect ' . $name . ' again on My Account.', 'account.php#meetings-h');
}

/**
 * Zoom or Microsoft refused the system's own app (4.32.0): its secret expired or changed, or its
 * permissions or tenant are wrong. Nobody's connection ends; administrators are told once a day.
 */
function meeting_app_refused(string $provider, string $error): void {
    if (!function_exists('setting') || !function_exists('set_setting')) return;
    $key = 'meeting_app_refused_' . $provider;
    if (setting($key) === date('Y-m-d')) return;
    set_setting($key, date('Y-m-d'));
    $name = MEETING_PROVIDERS[$provider] ?? $provider;
    if (function_exists('notify_admin_users')) notify_admin_users($name . ' refused this system\'s app (' . mb_substr($error, 0, 40) . '): check its client ID and secret in Settings, Online meetings. Staff connections are kept.', 'settings.php');
}

/**
 * Renew every working connection (4.32.0, from the daily job). Zoom ends a connection whose
 * refresh token goes unused for 90 days, and Microsoft one unused for 90 days by default, so
 * people who make links rarely would lose theirs unseen. A connection refused here is marked
 * and its owner told (meeting_mark_refused). Returns [renewed, refused, failed].
 */
function meeting_renew_all(int $seconds = 240): array {
    $done = [0, 0, 0];
    $deadline = time() + $seconds;
    try {
        // Active staff only, oldest renewal first, so a run cut short by its time limit reaches the rest tomorrow.
        $rows = db()->query("SELECT m.user_id, m.provider FROM meeting_accounts m JOIN users u ON u.id = m.user_id AND u.is_active = 1
            WHERE m.refused_at IS NULL ORDER BY m.expires_at")->fetchAll();
    } catch (PDOException $e) {
        return $done;   // before migration 4.32.0
    }
    $appDown = [];
    foreach ($rows as $r) {
        if (time() >= $deadline) break;
        if (!meeting_app((string)$r['provider']) || isset($appDown[$r['provider']])) continue;
        try {
            $tok = meeting_access_token((int)$r['user_id'], (string)$r['provider'], true);
        } catch (Throwable $e) {
            error_log('meeting_renew_all: ' . get_class($e));
            $tok = null;
        }
        if ($tok !== null) $done[0]++;
        elseif (meeting_refused_at(meeting_account((int)$r['user_id'], (string)$r['provider'])) !== null) $done[1]++;
        else {
            $done[2]++;
            // The app itself refused (told to administrators): no use trying the others today.
            if (setting('meeting_app_refused_' . $r['provider']) === date('Y-m-d')) $appDown[$r['provider']] = true;
        }
    }
    return $done;
}

/** Forget a connection. Zoom is also told to withdraw it; Microsoft has no such call for one
 * app's tokens (the person removes the app under their Microsoft account's app permissions). */
function meeting_disconnect(int $userId, string $provider): void {
    $acc = meeting_account($userId, $provider);
    $app = meeting_app($provider);
    if ($acc && $app && $provider === 'zoom') {
        try {
            // Zoom withdraws the app when given a working access token (4.32.0): renewed first
            // when it has expired, which it usually has.
            $tok = meeting_access_token($userId, 'zoom');
            // No fresh token (Zoom did not answer): the stored one is still sent, as before 4.32.0.
            if (!$tok) $tok = meeting_open((string)$acc['access_token']) ?: meeting_open((string)$acc['refresh_token']);
            if ($tok) meeting_http('POST', 'https://zoom.us/oauth/revoke', ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($app['id'] . ':' . $app['secret'])], http_build_query(['token' => $tok]));
        } catch (Throwable $e) {
            error_log('meeting_disconnect: ' . get_class($e));
        }
    }
    db()->prepare('DELETE FROM meeting_accounts WHERE user_id = ? AND provider = ?')->execute([$userId, $provider]);
    // A renewal refused while disconnecting must not leave a notice to connect again.
    $name = MEETING_PROVIDERS[$provider] ?? $provider;
    try {
        db()->prepare("DELETE FROM notifications WHERE user_id = ? AND link = 'account.php#meetings-h' AND message LIKE ? AND created_at >= NOW() - INTERVAL 10 MINUTE")
            ->execute([$userId, 'Your ' . $name . ' connection has ended%']);
    } catch (PDOException $e) {}
}

/**
 * Make a meeting in this person's account. One link serves a whole series (Zoom: a recurring
 * meeting with no fixed time; Teams: a meeting open from the first start to the last end).
 * Returns the join link, or null with $why set.
 */
function meeting_create(int $userId, string $provider, string $topic, int $start, int $end, bool $series, ?string &$why = null): ?string {
    $name = MEETING_PROVIDERS[$provider];
    $reconnect = $name . ' is not connected for this instructor, or its connection has ended: they can connect again on My Account.';
    $token = meeting_access_token($userId, $provider);
    if ($token === null) { $why = $reconnect; return null; }
    if ($provider === 'zoom') {
        $endpoint = 'https://api.zoom.us/v2/users/me/meetings';
        $body = ['topic' => mb_substr($topic, 0, 200), 'type' => $series ? 3 : 2, 'duration' => max(15, (int)round(($end - $start) / 60)),
            'start_time' => gmdate('Y-m-d\TH:i:s\Z', $start), 'timezone' => date_default_timezone_get(),
            'settings' => ['waiting_room' => true, 'join_before_host' => false]];
    } else {
        $endpoint = 'https://graph.microsoft.com/v1.0/me/onlineMeetings';
        $body = ['subject' => mb_substr($topic, 0, 200), 'startDateTime' => gmdate('Y-m-d\TH:i:s\Z', $start), 'endDateTime' => gmdate('Y-m-d\TH:i:s\Z', $end)];
    }
    [$code, $data] = meeting_http('POST', $endpoint, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], json_encode($body));
    // 401 (4.32.0): the token was withdrawn before it expired (the app was removed). Renew it
    // once and try again; a refused renewal marks the connection, and the person is told.
    if ($code === 401) {
        $token = meeting_access_token($userId, $provider, true);
        if ($token === null) { $why = $reconnect; return null; }
        [$code, $data] = meeting_http('POST', $endpoint, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], json_encode($body));
    }
    $url = is_array($data) ? ($provider === 'zoom' ? ($data['join_url'] ?? null) : ($data['joinWebUrl'] ?? null)) : null;
    if ($code < 200 || $code >= 300 || !is_string($url) || strlen($url) > 500 || !preg_match('~^https://\S+$~', $url)) {
        $why = meeting_refusal_reason($provider, $code, $data) . ' The session is booked without a link; add one by moving the session, or try again.';
        return null;
    }
    return $url;
}

/** Why Zoom or Microsoft did not make a meeting, in words (4.32.0): the common causes named, then the provider's own message. */
function meeting_refusal_reason(string $provider, int $code, $data): string {
    $name = MEETING_PROVIDERS[$provider];
    $msg = '';
    if (is_array($data)) {
        $m = $provider === 'zoom' ? ($data['message'] ?? '') : ($data['error']['message'] ?? '');
        if (is_string($m)) $msg = trim(preg_replace('/\s+/', ' ', strip_tags($m)));
    }
    $tail = $msg !== '' ? ' ' . $name . ' said: "' . mb_substr($msg, 0, 200) . '".' : '';
    return match (true) {
        $code === 0 || $code >= 500 => $name . ' did not answer just now.',
        $code === 429 => $name . ' is busy (too many requests): try again in a minute.',
        $code === 401 => 'The connection to ' . $name . ' was refused: the instructor can connect again on My Account.' . $tail,
        $code === 403 && $provider === 'microsoft' => 'Microsoft refused: the instructor\'s Microsoft account may not have Teams, or the app is missing the OnlineMeetings.ReadWrite permission.' . $tail,
        $code === 403 || $code === 404 => $name . ' refused: the instructor\'s ' . $name . ' account may not allow making meetings this way.' . $tail,
        default => $name . ' did not make the meeting (reply ' . $code . ').' . $tail,
    };
}

/** Keep the start time a single meeting link was made for (4.32.0); before migration 4.32.0 nothing is kept. */
function meeting_note_made_for(array $sessionIds, int $start): void {
    $ids = array_values(array_filter(array_map('intval', $sessionIds)));
    if (!$ids) return;
    try {
        db()->prepare('UPDATE scheduled_sessions SET meeting_made_for = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([date('Y-m-d H:i:s', $start)]);
    } catch (PDOException $e) {}
}

/**
 * After a session moves (4.32.0). A single meeting made by Zoom stops working about 30 days
 * after the time it was made for, and a Teams one 60 days after, so a session now more than 25
 * days past the time its link was made for (however many moves that took) gets a new link from
 * its instructor's own account, when that is still connected. Only links this system made for
 * one time are replaced: a link someone typed (a personal meeting room) and a series' recurring
 * meeting with no fixed time work at any time and are kept. The old meeting is left in Zoom or
 * Teams. Never fails the move.
 */
function meeting_refresh_after_move(int $sid, int $oldStart): void {
    try {
        try {
            $q = db()->prepare("SELECT id, user_id, start_at, end_at, is_remote, meeting_url, meeting_made_for FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled'");
            $q->execute([$sid]);
            $s = $q->fetch();
        } catch (PDOException $e) {
            return;   // before migration 4.32.0
        }
        if (!$s || !(int)$s['is_remote'] || empty($s['meeting_made_for'])) return;
        $url = trim((string)($s['meeting_url'] ?? ''));
        $start = strtotime((string)$s['start_at']);
        if ($url === '' || $start - strtotime((string)$s['meeting_made_for']) <= 25 * 86400) return;
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $provider = preg_match('/(^|\.)zoom\.us$/', $host) ? 'zoom' : (preg_match('/(^|\.)teams\.(microsoft|live)\.com$/', $host) ? 'microsoft' : null);
        if ($provider === null || !meeting_app($provider) || !meeting_connected((int)$s['user_id'], $provider)) return;
        $why = null;
        $new = meeting_create((int)$s['user_id'], $provider, (brand('short') ?: cfg('name')) . ' session', $start, strtotime((string)$s['end_at']), false, $why);
        if ($new === null) return;
        db()->prepare('UPDATE scheduled_sessions SET meeting_url = ? WHERE id = ?')->execute([$new, $sid]);
        meeting_note_made_for([$sid], $start);
    } catch (Throwable $e) {
        error_log('meeting_refresh_after_move: ' . get_class($e));
    }
}
