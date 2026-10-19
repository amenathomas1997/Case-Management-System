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

/** Escape text cells that spreadsheets could interpret as formulas. */
function csv_cell($v): string {
    $v = (string)$v;
    if (is_numeric($v)) return $v;
    return preg_match('/^(?:[\\x00-\\x20]*[=+\\-@]|[\\t\\r\\n])/', $v) ? "'" . $v : $v;
}

/** Explicit RFC-style escaping also avoids PHP 8.4 CSV deprecation output. */
function write_csv_row($stream, array $fields): int|false {
    return fputcsv($stream, $fields, ',', '"', '');
}
