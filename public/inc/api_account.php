<?php
declare(strict_types=1);
/*
 * Account settings for the app (API_ACCOUNT_ACTIONS in api.php): runs in
 * api.php's scope once the caller's token is checked ($action, $input,
 * $userId, $tok). Every change goes through inc/account_actions.php, so the
 * database writes, audit entries and notification emails are the website's.
 * Each action ends with out() or fail().
 */
require_once __DIR__ . '/account_actions.php';

// The website's texts (REAUTH_LOCKED_MSG / REAUTH_SIGNED_OUT_MSG in inc/bootstrap.php,
// which the API does not load), said the same way here.
const API_REAUTH_LOCKED_MSG = 'Too many incorrect passwords. Wait 15 minutes, then try again.';
const API_REAUTH_SIGNED_OUT_MSG = 'Too many incorrect passwords, so you were signed out to protect your account. Sign in again; you can retry this change after 15 minutes.';
/** Changes that email the account owner, per account per hour. */
const API_ACCOUNT_MAIL_LIMIT = 10;
/** How long a setup key from totp_begin can be confirmed. */
const API_TOTP_PENDING_SECONDS = 600;

/**
 * The website's account_reauth_error() for the app: null when the password
 * matches, else the field message. An empty password is a missed step, not a
 * guess. Too many wrong passwords are refused (429); the one that reaches the
 * limit signs this account out of the app everywhere (401), as the website
 * ends its session.
 */
function api_account_reauth(int $userId, $password, string $wrong): ?string {
    if (!is_string($password) || $password === '') return 'Enter your password to confirm this change. Nothing was changed.';
    $r = reauth_password($userId, $password);
    if ($r === 'ok') return null;
    if ($r === 'locked') fail(API_REAUTH_LOCKED_MSG, 429);
    if ($r === 'lockout') {
        audit($userId, 'reauth.lockout', 'user', $userId, 'signed out after 5 wrong passwords in 15 minutes (mobile app)');
        db()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND wipe_requested_at IS NULL')->execute([$userId]);
        // 403, not 401: the app replays a request after a 401 sign-in, which would resend
        // the wrong password. Its tokens are gone, so the next request asks for sign-in.
        fail(API_REAUTH_SIGNED_OUT_MSG, 403);
    }
    return $wrong;
}

/** A shared action's refusal: field errors are said together, in order, as one message (422). */
function api_account_refused(array $r): never {
    if (isset($r['error'])) fail($r['error'], !empty($r['forbidden']) ? 403 : 409);
    $errors = $r['errors'] ?? [];
    out(['ok' => false, 'error' => implode(' ', $errors) ?: 'Nothing was changed.', 'errors' => $errors], 422);
}

/** The setup key waiting for confirmation, or '' when there is none or it has run out. */
function api_totp_pending(int $userId): string {
    $pending = json_decode((string)setting('totp_pending_' . $userId), true);
    if (!is_array($pending) || !is_string($pending['secret'] ?? null) || (int)($pending['expires'] ?? 0) < time()) return '';
    return $pending['secret'];
}
function api_totp_pending_clear(int $userId): void {
    db()->prepare('DELETE FROM settings WHERE name = ?')->execute(['totp_pending_' . $userId]);
}

if ($action !== 'account' && $_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST for changes.', 405);

$acctQ = db()->prepare('SELECT u.id, u.name, u.email, u.role, u.totp_enabled, u.cal_token, g.name AS group_name
    FROM users u LEFT JOIN `groups` g ON g.id = u.group_id WHERE u.id = ?');
$acctQ->execute([$userId]);
$acct = $acctQ->fetch();
if (!$acct) fail('Session expired — sign in again.', 401);
$acctUser = ['id' => (int)$acct['id'], 'name' => (string)$acct['name'], 'email' => (string)$acct['email']];
$acctReauth = fn($password, string $wrong): ?string => api_account_reauth($userId, $password, $wrong);
$acctMailKey = 'acct-mail-' . $userId;
if (in_array($action, ['change_password', 'totp_confirm', 'totp_disable', 'cal_feed_regen'], true)
    && api_throttle_count($acctMailKey, 3600) >= API_ACCOUNT_MAIL_LIMIT) {
    fail('Too many account changes in the last hour. Nothing was changed. Wait an hour, then try again.', 429);
}

switch ($action) {
    case 'account':
        $pk = db()->prepare('SELECT id, name, created_at, last_used_at FROM passkeys WHERE user_id = ? ORDER BY created_at');
        $pk->execute([$userId]);
        $sessions = db()->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND expires_at > NOW() AND wipe_requested_at IS NULL');
        $sessions->execute([$userId]);
        out(['ok' => true, 'name' => $acctUser['name'], 'email' => $acctUser['email'], 'role' => (string)$acct['role'],
             'group' => $acct['group_name'] !== null ? (string)$acct['group_name'] : null,
             'totp_enabled' => (bool)$acct['totp_enabled'],
             // Whether an address exists, never the address itself (cal_feed_show asks for the password).
             'has_cal_feed' => (string)$acct['cal_token'] !== '',
             'can_schedule' => can('schedule'),
             'passkeys' => array_map(fn(array $p): array => ['id' => (int)$p['id'], 'name' => (string)($p['name'] ?? ''),
                 'created' => (string)$p['created_at'], 'last_used' => $p['last_used_at'] !== null ? (string)$p['last_used_at'] : null], $pk->fetchAll()),
             'app_sessions' => (int)$sessions->fetchColumn()]);

    case 'change_password':
        $r = account_change_password($acctUser, $input['current_password'] ?? '', $input['new_password'] ?? null, $input['confirm_password'] ?? null,
            !empty($input['ack_passkeys']), $acctReauth);
        if (!$r['ok']) api_account_refused($r);
        api_totp_pending_clear($userId);
        api_throttle_record($acctMailKey);
        // Every app session went with it, this one too.
        out(['ok' => true, 'signed_out' => true, 'message' => $r['message'],
             'passkeys_removed' => $r['passkeys_removed'], 'cal_feed_turned_off' => $r['cal_feed_turned_off']]);

    case 'totp_begin':
        if ($acct['totp_enabled']) fail('The authenticator app is already turned on. Turn it off first to set up a different one.', 409);
        // Kept on the server (as the website keeps it in the session) until confirmed or run out.
        $secret = totp_generate_secret();
        set_setting('totp_pending_' . $userId, json_encode(['secret' => $secret, 'expires' => time() + API_TOTP_PENDING_SECONDS]));
        out(['ok' => true, 'secret' => $secret, 'account_name' => $acctUser['email'],
             'otpauth_uri' => totp_provisioning_uri($secret, $acctUser['email']),
             'expires_minutes' => intdiv(API_TOTP_PENDING_SECONDS, 60)]);

    case 'totp_confirm':
        $r = account_totp_confirm($acctUser, api_totp_pending($userId), $input['password'] ?? '', $input['code'] ?? '', $acctReauth);
        if (!$r['ok']) {
            if (isset($r['error'])) api_totp_pending_clear($userId);   // it had run out
            api_account_refused($r);
        }
        api_totp_pending_clear($userId);
        api_throttle_record($acctMailKey);
        out(['ok' => true, 'message' => $r['message']]);

    case 'totp_disable':
        if (!$acct['totp_enabled']) fail('The authenticator app is not turned on, so nothing was changed.', 409);
        $r = account_totp_disable($acctUser, $input['password'] ?? '', $acctReauth);
        if (!$r['ok']) api_account_refused($r);
        api_throttle_record($acctMailKey);
        out(['ok' => true, 'message' => $r['message']]);

    case 'cal_feed_show':
        if (!can('schedule')) {
            fail('A calendar subscription shows your scheduled sessions, so it needs the Scheduling permission, which your account does not have. Ask an administrator if you need it.', 403);
        }
        // The address is a lasting key to the schedule, so the password is checked before it is shown.
        $pwErr = api_account_reauth($userId, $input['password'] ?? '', 'Password incorrect — the calendar address was not shown.');
        if ($pwErr !== null) api_account_refused(['errors' => ['password' => $pwErr]]);
        $calToken = (string)$acct['cal_token'];
        out(['ok' => true, 'exists' => $calToken !== '', 'address' => $calToken !== '' ? account_cal_feed_url($calToken) : null,
             'message' => $calToken !== '' ? 'Your calendar address is shown.' : 'You have no calendar address yet. Create one to subscribe your calendar app.']);

    case 'cal_feed_regen':
        $r = account_cal_regen($acctUser, $input['password'] ?? '', $acctReauth);
        if (!$r['ok']) api_account_refused($r);
        api_throttle_record($acctMailKey);
        out(['ok' => true, 'address' => $r['address'], 'replaced' => $r['replaced'], 'message' => $r['message']]);

    case 'remove_passkey':
        $pkId = api_number($input, 'id', 'Passkey');
        if ($pkId === null || $pkId < 1 || floor($pkId) !== $pkId) fail('Choose a passkey to remove.', 422);
        $r = account_remove_passkey($acctUser, (int)$pkId);
        if (!$r['ok']) fail($r['error'], 404);
        out(['ok' => true, 'message' => $r['message']]);

    case 'logout_all':
        $pwErr = api_account_reauth($userId, $input['password'] ?? '', 'Password incorrect — you are still signed in.');
        if ($pwErr !== null) api_account_refused(['errors' => ['password' => $pwErr]]);
        $del = db()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND wipe_requested_at IS NULL');
        $del->execute([$userId]);
        api_totp_pending_clear($userId);
        $ended = $del->rowCount();
        audit($userId, 'api.logout_all', 'user', $userId, $ended . ' app session(s) ended');
        out(['ok' => true, 'signed_out' => true, 'sessions_ended' => $ended,
             'message' => 'You were signed out of the app on every device, including this one. Website sessions are not affected: sign out of the website separately if you need to.']);
}
fail('Unknown action.', 400);
