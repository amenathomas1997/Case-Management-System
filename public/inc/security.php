<?php
declare(strict_types=1);

/** Only local PHP routes are eligible as a post-authentication destination. */
function safe_resume_url($value): string {
    if (!is_string($value) || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $value)) return '/index.php';
    $path = explode('?', $value, 2)[0];
    return preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\\.php$~D', $path) ? $value : '/index.php';
}

/** Whether a form field may be kept in a draft: never one that looks like a credential. */
function form_draft_key_ok($key): bool {
    return !preg_match('/password|secret|token|csrf|signature|(?:^|_)(?:pw|key|code|confirm)(?:_|$)/i', (string)$key);
}

/** Never retain credentials in error/re-authentication drafts. */
function safe_form_draft(array $input): array {
    return array_filter($input, static function ($value, $key): bool {
        return is_string($value) && form_draft_key_ok($key);
    }, ARRAY_FILTER_USE_BOTH);
}

/**
 * The multi-value fields of a draft (ticked checkboxes, multi-selects:
 * name="x[]"), which safe_form_draft() leaves out. Only flat lists of up to
 * 500 strings are kept, under the same credential filter; pages re-tick them
 * with old_in_list().
 */
function safe_form_lists(array $input): array {
    return array_map('array_values', array_filter($input, static function ($value, $key): bool {
        return is_array($value) && count($value) <= 500 && form_draft_key_ok($key)
            && count(array_filter($value, 'is_string')) === count($value);
    }, ARRAY_FILTER_USE_BOTH));
}

/**
 * A bcrypt hash at the running PHP's default cost, to verify against when no
 * account has the email, so that answer takes as long as a wrong password for
 * a real account (PHP 8.1 hashes at cost 10, PHP 8.4 at 12). Fixed strings:
 * hashing one per request would itself double the time.
 */
function dummy_password_hash(): string {
    $cost = defined('PASSWORD_BCRYPT_DEFAULT_COST') ? (int)PASSWORD_BCRYPT_DEFAULT_COST : 10;
    return $cost >= 12
        ? '$2y$12$mZ4rocAYAch6VBKB9XumuutsDwirmbxoBUbUIgqKMG9tMfhZBUuyO'
        : '$2y$10$9l7NYsHXEIrnStehE7nZH.5koc.rXILRTilnjVag5fjzD/Jk91qBi';
}

/**
 * Atomic consumption prevents a previously read OTP from being reused.
 * $tryTaken: the caller already counted this guess as one of the code's 5
 * tries (verify.php takes the try before checking), so the 5th try may claim.
 */
function claim_login_otp(int $id, bool $tryTaken = false): bool {
    $claim = db()->prepare('UPDATE login_otps SET consumed = 1 WHERE id = ? AND consumed = 0 AND expires_at >= NOW() AND attempts < ?');
    $claim->execute([$id, $tryTaken ? 6 : 5]);
    return $claim->rowCount() === 1;
}

/**
 * Re-check the signed-in user's password before a sensitive account change
 * (adding or removing a sign-in method, changing the password). Failures are
 * counted per account in login_attempts under 'reauth:<email>' (shown with the
 * sign-in attempts and cleared with them on users.php). After 5 failures in 15
 * minutes the password is no longer tested at all, so a hijacked session cannot
 * keep guessing. Returns 'ok', 'wrong', 'lockout' (this failure reached the
 * limit: the caller ends the session) or 'locked' (refused, not tested).
 */
function reauth_password(int $userId, string $password): string {
    $s = db()->prepare('SELECT email, password_hash FROM users WHERE id = ?');
    $s->execute([$userId]);
    $row = $s->fetch();
    if (!$row) return 'wrong';
    $key = mb_substr('reauth:' . strtolower((string)$row['email']), 0, 190);
    $rl = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $rl->execute([$key]);
    $failures = (int)$rl->fetchColumn();
    if ($failures >= 5) return 'locked';
    if ($password === '') return 'wrong';   // nothing typed is not a guess, so it is not counted
    if (password_verify($password, (string)$row['password_hash'])) return 'ok';
    db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)')
        ->execute([client_ip(), $key]);
    return $failures + 1 >= 5 ? 'lockout' : 'wrong';
}

/** Account linking needs the same authenticated local identity that initiated it. */
function sso_link_authorized(int $intentId, ?array $actor, int $targetId): bool {
    return $intentId > 0 && $actor !== null && $intentId === $targetId && (int)$actor['id'] === $targetId;
}

/** Whether a first Microsoft sign-in may be matched to an existing account by its
    email (owner decision, 2026-10-15; tightened in 4.27.0). Only for a member of the
    configured tenant, whose email and user name that organisation's administrators
    control. The ID token must carry the optional `acct` claim with the value 0
    (member; 1 is a guest) and a `tid` naming this tenant (taken from the issuer
    https://login.microsoftonline.com/<tenant>/v2.0). Without the claim nothing
    proves membership, so the email is not matched: the person signs in with their
    password once and connects Microsoft from My Account. A guest (B2B) account's idp
    claim naming another organisation is refused as before. */
function sso_email_match_allowed(array $claims, string $issuer): bool {
    if (!preg_match('~^https://login\.microsoftonline\.com/([^/]+)/v2\.0$~i', $issuer, $m)) return false;
    $tenant = strtolower($m[1]);
    if (strtolower((string)($claims['tid'] ?? '')) !== $tenant) return false;
    // Present and exactly 0: Microsoft sends it as a number; "0" as text is accepted too.
    if (!array_key_exists('acct', $claims) || !in_array($claims['acct'], [0, '0'], true)) return false;
    $idp = strtolower(trim((string)($claims['idp'] ?? '')));
    return $idp === '' || $idp === strtolower($issuer);
}

/** The message for a first Microsoft sign-in that cannot be matched by email (4.27.0). */
const SSO_EMAIL_MATCH_REFUSED_MSG = 'Sign in with your password once, then connect Microsoft from My Account.';

/* ---------- A browser handed over for signing in person (4.29.0, P2) ----------
   kiosk_end() signs the staff member out, but the browser may still hold their
   Microsoft session or an unlocked passkey. A short-lived marker cookie outlives
   the ended session: while it is set, "Sign in with Microsoft" makes Microsoft ask
   for the password again (prompt=login, checked through auth_time) and a passkey
   cannot sign in. A full sign-in with password and code (verify.php) clears it. */
const HANDOVER_COOKIE = 'cms_handover';
const HANDOVER_SECONDS = 2 * 3600;
const HANDOVER_PASSKEY_MSG = 'This browser was handed over for signing, so a passkey cannot be used here until someone signs in with their email, password and code.';
const HANDOVER_SSO_MSG = 'This browser was handed over for signing, and Microsoft did not confirm that it asked for your password just now. Sign in with your email, password and code instead. If you have never had a password here, choose "Forgot your password?" to set one.';

/** True while this browser carries the hand-over marker, or its session is still handed over. */
function handover_marked(): bool {
    if (is_array($_SESSION['kiosk_sign'] ?? null)) return true;
    // Tests (command line only) set the marker without a cookie, as CMS_TEST_SSO_TOKENS does for sso.php.
    if (PHP_SAPI === 'cli' && getenv('CMS_TEST_HANDOVER') === '1') return true;
    $v = $_COOKIE[HANDOVER_COOKIE] ?? null;
    return is_string($v) && ctype_digit($v) && (int)$v > time();
}

/** True when the marker cookie is set with more than half its time left (no need to renew it). */
function handover_cookie_fresh(): bool {
    $v = $_COOKIE[HANDOVER_COOKIE] ?? null;
    return is_string($v) && ctype_digit($v) && (int)$v > time() + intdiv(HANDOVER_SECONDS, 2);
}

/** Set (or renew) the marker. The value is its own expiry time, so an old copy is ignored. */
function handover_mark(): void {
    $until = time() + HANDOVER_SECONDS;
    $_COOKIE[HANDOVER_COOKIE] = (string)$until;
    if (headers_sent()) return;
    setcookie(HANDOVER_COOKIE, (string)$until, ['expires' => $until, 'path' => '/', 'secure' => (bool)($GLOBALS['isHttps'] ?? true),
        'httponly' => true, 'samesite' => 'Lax']);
}

/** Remove the marker (after a full sign-in with password and code). */
function handover_clear(): void {
    if (!isset($_COOKIE[HANDOVER_COOKIE])) return;
    unset($_COOKIE[HANDOVER_COOKIE]);
    if (headers_sent()) return;
    setcookie(HANDOVER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => (bool)($GLOBALS['isHttps'] ?? true),
        'httponly' => true, 'samesite' => 'Lax']);
}

/** The query for Microsoft's authorize endpoint. $fresh (a handed-over browser) makes Microsoft
 *  ask for the password again, whatever session it holds, and report when (auth_time). */
function sso_authorize_params(string $clientId, string $redirectUri, string $state, string $nonce, bool $fresh): array {
    $q = ['client_id' => $clientId, 'response_type' => 'code', 'redirect_uri' => $redirectUri, 'response_mode' => 'query',
        'scope' => 'openid profile email', 'state' => $state, 'nonce' => $nonce];
    if ($fresh) $q += ['prompt' => 'login', 'max_age' => '0'];
    return $q;
}

/** For a sign-in started with $fresh: the ID token must say the password was asked after
 *  $startedAt (auth_time; 5 minutes allowed for clock difference). No auth_time: refused. */
function sso_fresh_auth_ok(array $claims, int $startedAt): bool {
    if (!isset($claims['auth_time']) || !is_numeric($claims['auth_time'])) return false;
    $at = (int)$claims['auth_time'];
    return $at >= $startedAt - 300 && $at <= time() + 300;
}

/**
 * The address a request came from, for per-address limits (4.27.0). The
 * X-Forwarded-For header is used only when config 'trust_proxy' is on AND the
 * request really arrived from a trusted proxy: REMOTE_ADDR is listed in config
 * 'trusted_proxies' (an array of addresses), or, when that list is not set, is a
 * private or loopback address. The header is then read from the right, skipping
 * hops listed in 'trusted_proxies' (with no list, the right-most entry is taken),
 * and the first other hop is the client, even a private
 * address (an office computer behind the proxy). Entries further left are
 * written by the client and never trusted. Otherwise REMOTE_ADDR.
 */
function client_ip(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!cfg('trust_proxy')) return $remote;
    $list = cfg('trusted_proxies');
    $list = is_array($list) && $list ? array_map('strval', $list) : null;
    $isTrusted = static function (string $ip) use ($list): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
        if ($list !== null) return in_array($ip, $list, true);
        // No list: the proxy is on this machine or the private network.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    };
    if (!$isTrusted($remote)) return $remote;
    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff === '') return $remote;
    $last = $remote;
    foreach (array_reverse(array_map('trim', explode(',', $xff))) as $hop) {
        if (filter_var($hop, FILTER_VALIDATE_IP) === false) return $last; // malformed: stop at the last good hop
        // Without a list only REMOTE_ADDR is known to be ours: its right-most entry is the client.
        if ($list === null || !in_array($hop, $list, true)) return $hop;
        $last = $hop;
    }
    return $last; // every hop is a proxy of ours: the left-most one
}

/**
 * Escape text cells that spreadsheets could interpret as formulas. Applying it
 * twice changes nothing (an escaped cell starts with an apostrophe). A plain
 * number, also written with thousands commas, a currency sign or a percent
 * (number_format(), fmt_money(): "-1,250.00", "-$40.00", "-5%"), is left as it
 * is: a spreadsheet reads it as a number and it can never run as a formula.
 */
function csv_cell($v): string {
    $v = (string)$v;
    if (is_numeric($v)) return $v;
    if (preg_match('/^[-+]?\$?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?%?$/', $v)) return $v;
    return preg_match('/^(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $v) ? "'" . $v : $v;
}

/**
 * One CSV row, with RFC-style escaping (which also avoids PHP 8.4 CSV
 * deprecation output). Every text cell goes through csv_cell() here (4.28.0,
 * N8), so no download can forget the formula guard; numbers and empty cells
 * (null) pass unchanged, and a cell the caller already escaped is not changed again.
 */
function write_csv_row($stream, array $fields): int|false {
    foreach ($fields as $k => $v) if (is_string($v)) $fields[$k] = csv_cell($v);
    return fputcsv($stream, $fields, ',', '"', '');
}

/* ---------- Authenticator (TOTP) seeds, encrypted at rest (4.28.0) ----------
   users.totp_secret holds "v1:" + base64(iv 12 bytes, GCM tag 12 bytes, the
   20-byte seed encrypted with AES-256-GCM) under the private key file that
   meeting and QuickBooks tokens use (meeting_key(), private/meeting_tokens.key).
   That is 63 characters, so it fits the existing varchar(64) column: no
   database change is needed. A backup of the database alone no longer holds
   anyone's second factor. A seed stored before 4.28.0 (plain base32) is still
   read, and is stored again encrypted the next time it signs someone in. */
const TOTP_SEAL_PREFIX = 'v1:';

/** Base32 (RFC 4648, no padding) for the seed, kept here so security.php does not
 *  depend on inc/totp.php, which some pages load with a plain require. */
function totp_seed_b32(string $bytes, bool $decode = false): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    if ($decode) {
        foreach (str_split(strtoupper($bytes)) as $c) { $v = strpos($alphabet, $c); if ($v !== false) $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT); }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        return $out;
    }
    foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

/** The base32 seed to check codes with, or null (none stored, or it cannot be read with this server's key). */
function totp_secret_plain(?string $stored): ?string {
    $stored = trim((string)$stored);
    if ($stored === '') return null;
    if (!str_starts_with($stored, TOTP_SEAL_PREFIX)) return $stored; // stored before 4.28.0
    $raw = base64_decode(substr($stored, strlen(TOTP_SEAL_PREFIX)), true);
    if ($raw === false || strlen($raw) <= 24) return null;
    try { $key = meeting_key(); } catch (Throwable $e) { error_log('totp_secret_plain: ' . $e->getMessage()); return null; }
    $seed = openssl_decrypt(substr($raw, 24), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 12), 'totp');
    return ($seed === false || $seed === '') ? null : totp_seed_b32($seed);
}

/** Refused storing of an authenticator seed (4.29.0): its message is worded for the person. */
final class TotpStoreRefused extends RuntimeException {}
const TOTP_STORE_REFUSED_MSG = "The authenticator app can't be turned on right now, because the server's key file (meeting_tokens.key) is missing or damaged. Nothing was changed. Ask an administrator: System health says how to fix it.";

/** What to store in users.totp_secret for a base32 seed: always the encrypted form.
 *  4.29.0 (P14): never the plain seed. When the key file cannot be used, or the seed is
 *  not a standard 20-byte one, it throws TotpStoreRefused (TOTP_STORE_REFUSED_MSG)
 *  and the caller stores nothing. */
function totp_secret_store(string $plain): string {
    if (str_starts_with(trim($plain), TOTP_SEAL_PREFIX)) return trim($plain); // already stored form
    $plain = strtoupper(trim($plain));
    $seed = ($plain !== '' && preg_match('/^[A-Z2-7]+$/', $plain)) ? totp_seed_b32($plain, true) : '';
    if (strlen($seed) !== 20 || totp_seed_b32($seed) !== $plain) {
        throw new TotpStoreRefused('The authenticator setup key was not recognised, so nothing was changed. Start the setup again.');
    }
    try {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($seed, 'aes-256-gcm', meeting_key(), OPENSSL_RAW_DATA, $iv, $tag, 'totp', 12);
        if ($ct === false || strlen((string)$tag) !== 12) throw new RuntimeException('Encryption failed.');
    } catch (Throwable $e) {
        error_log('totp_secret_store: refused, the seed was not stored: ' . $e->getMessage());
        throw new TotpStoreRefused(TOTP_STORE_REFUSED_MSG, 0, $e);
    }
    return TOTP_SEAL_PREFIX . base64_encode($iv . $tag . $ct);
}

/** Null when a new authenticator seed can be stored encrypted now, else the message to show
 *  (checked before the setup starts, so nobody scans a code that cannot be kept). */
function totp_store_problem(): ?string {
    try { meeting_key(); return null; } catch (Throwable $e) { return TOTP_STORE_REFUSED_MSG; }
}

/** The message for an account whose authenticator seed is stored but cannot be read here. */
const TOTP_UNREADABLE_MSG = "Your authenticator app can't be checked on this server. Ask an administrator to reset two-step verification (System health, or Users, or reset_2fa.php in the cron folder).";

/** True when the account has the authenticator app turned on with a stored seed. This alone
 *  decides that it signs in with the app only, never whether the seed can be read: a lost
 *  key file must never turn an account back to emailed codes. */
function totp_seed_present(array $user): bool {
    return !empty($user['totp_enabled']) && trim((string)($user['totp_secret'] ?? '')) !== '';
}

/** 'none' (no authenticator app), 'ok' (its seed reads), or 'unreadable' (a seed is stored
 *  but this server cannot read it: the key file is missing, replaced or damaged). */
function totp_seed_state(array $user): string {
    if (!totp_seed_present($user)) return 'none';
    return totp_secret_plain((string)$user['totp_secret']) !== null ? 'ok' : 'unreadable';
}

/** True when a stored seed is still in the pre-4.28.0 plain form. */
function totp_secret_is_legacy(?string $stored): bool {
    $stored = trim((string)$stored);
    return $stored !== '' && !str_starts_with($stored, TOTP_SEAL_PREFIX);
}

/** After a code from $plain signed someone in: store a plain (legacy) seed encrypted.
 *  Only when the row still holds the same value, so a reset at the same moment is never undone. */
function totp_secret_upgrade(int $userId, ?string $stored, string $plain): void {
    if (!totp_secret_is_legacy($stored)) return;
    // Kept as it was when it cannot be encrypted now (no usable key file, or a non-standard seed).
    try { $new = totp_secret_store($plain); } catch (TotpStoreRefused $e) { return; }
    if (!str_starts_with($new, TOTP_SEAL_PREFIX)) return;
    try {
        db()->prepare('UPDATE users SET totp_secret = ? WHERE id = ? AND totp_secret = ?')->execute([$new, $userId, $stored]);
    } catch (Throwable $e) { error_log('totp_secret_upgrade: ' . $e->getMessage()); }
}
