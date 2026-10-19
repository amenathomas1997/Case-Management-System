<?php
// Included by full_schema.php after release_3021_cases.php, against its synthetic
// database only: the app's account settings (inc/api_account.php) for the signed-in
// test admin (user 1, app token 'a' x 64). Actions that end the app session take
// that token with them, so it is put back after each; everything changed on user 1
// is restored at the end.
require_once $root . '/public/inc/totp.php';
$acctPw = 'Synthetic-account-pass-1';
$db->prepare('UPDATE users SET password_hash=?, group_id=NULL, totp_enabled=0, totp_secret=NULL, cal_token=NULL WHERE id=1')
    ->execute([password_hash($acctPw, PASSWORD_DEFAULT)]);
$db->exec("DELETE FROM login_attempts WHERE email LIKE 'reauth:%' OR email LIKE 'acct-mail-%'");
$db->exec("DELETE FROM settings WHERE name LIKE 'totp_pending_%'");
$appTokenHash = hash('sha256', str_repeat('a', 64));
$restoreAppToken = function () use ($db, $appTokenHash): void {
    $db->prepare('INSERT IGNORE INTO api_tokens (user_id,token_hash,expires_at) VALUES (1,?,DATE_ADD(NOW(), INTERVAL 1 DAY))')->execute([$appTokenHash]);
};
$otherDevice = function () use ($db): void {
    $db->prepare('INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (1,?,DATE_ADD(NOW(), INTERVAL 1 DAY))')->execute([hash('sha256', bin2hex(random_bytes(32)))]);
};
$appSessions = fn(): int => (int)$db->query('SELECT COUNT(*) FROM api_tokens WHERE user_id=1 AND wipe_requested_at IS NULL')->fetchColumn();
$reauthFailures = fn(): int => (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='reauth:admin@example.invalid' AND success=0")->fetchColumn();
$audited = fn(string $action): int => (int)$db->query('SELECT COUNT(*) FROM audit_log WHERE user_id=1 AND action=' . $db->quote($action))->fetchColumn();
$addPasskey = function (string $name) use ($db): int {
    $db->prepare("INSERT INTO passkeys (user_id,credential_id,public_key,name) VALUES (1,?,'synthetic-public-key',?)")->execute(['synthetic-credential-' . bin2hex(random_bytes(6)), $name]);
    return (int)$db->lastInsertId();
};
$restoreAppToken();
$pkId = $addPasskey('Synthetic phone');

/* ---- Summary ---- */
$acct = $call('account');
check($acct['name'] === 'Test Admin' && $acct['email'] === 'admin@example.invalid' && $acct['role'] === 'admin' && $acct['totp_enabled'] === false
    && $acct['has_cal_feed'] === false && $acct['can_schedule'] === true && $acct['app_sessions'] >= 1
    && count($acct['passkeys']) === 1 && $acct['passkeys'][0]['id'] === $pkId && $acct['passkeys'][0]['name'] === 'Synthetic phone',
    'The app account summary gives the name, email, role, sign-in methods and app sessions');

/* ---- Password re-check ---- */
$before = $reauthFailures();
$call('cal_feed_show', ['password' => 'not-the-password'], [], false, 'Password incorrect');
check($reauthFailures() === $before + 1, 'A wrong account password is refused and counted toward the re-check limit');
$call('cal_feed_show', ['password' => ''], [], false, 'Enter your password');
check($reauthFailures() === $before + 1, 'An empty account password is refused without counting as a guess');

/* ---- Authenticator app: begin, an expired setup, a wrong code, then a live code ---- */
$begin = $call('totp_begin');
check(preg_match('/^[A-Z2-7]{32}$/', $begin['secret']) === 1 && str_starts_with($begin['otpauth_uri'], 'otpauth://totp/')
    && str_contains($begin['otpauth_uri'], 'secret=' . $begin['secret']) && $begin['expires_minutes'] === 10
    && (int)$db->query("SELECT COUNT(*) FROM settings WHERE name='totp_pending_1'")->fetchColumn() === 1,
    'Setting up the authenticator app keeps the setup key on the server and returns it with its otpauth address');
$db->prepare("UPDATE settings SET value=? WHERE name='totp_pending_1'")->execute([json_encode(['secret' => $begin['secret'], 'expires' => time() - 1])]);
$call('totp_confirm', ['password' => $acctPw, 'code' => totp_code($begin['secret'])], [], false, 'The authenticator setup had ended');
check((int)$db->query('SELECT totp_enabled FROM users WHERE id=1')->fetchColumn() === 0, 'An expired authenticator setup turns nothing on');
$begin = $call('totp_begin');
$wrongCode = str_pad((string)(((int)totp_code($begin['secret'], time() + 600)) ^ 1), 6, '0', STR_PAD_LEFT);
if (totp_verify($begin['secret'], $wrongCode)) $wrongCode = str_pad((string)(((int)$wrongCode + 7) % 1000000), 6, '0', STR_PAD_LEFT);
$call('totp_confirm', ['password' => $acctPw, 'code' => $wrongCode], [], false, 'That code did not match');
$call('totp_confirm', ['password' => $acctPw, 'code' => totp_code($begin['secret'])]);
$totpRow = $db->query('SELECT totp_enabled, totp_secret FROM users WHERE id=1')->fetch();
check((int)$totpRow['totp_enabled'] === 1 && $totpRow['totp_secret'] === $begin['secret'] && $audited('totp.enable') >= 1
    && (int)$db->query("SELECT COUNT(*) FROM settings WHERE name='totp_pending_1'")->fetchColumn() === 0,
    'A live code with the password turns on the authenticator app, is audited, and clears the setup key');
$call('totp_begin', [], [], false, 'already turned on');
$call('totp_disable', ['password' => $acctPw]);
$call('totp_disable', ['password' => $acctPw], [], false, 'not turned on');
check((int)$db->query('SELECT totp_enabled FROM users WHERE id=1')->fetchColumn() === 0 && $audited('totp.disable') >= 1,
    'The app turns the authenticator app off with the password, audited');

/* ---- Calendar address: shown and replaced with the password, only with Scheduling ---- */
$calToken = fn() => $db->query('SELECT cal_token FROM users WHERE id=1')->fetchColumn();
$show = $call('cal_feed_show', ['password' => $acctPw]);
check($show['exists'] === false && $show['address'] === null, 'With no calendar address the app is told there is none');
$call('cal_feed_show', ['password' => 'not-the-password'], [], false, 'Password incorrect');
$made = $call('cal_feed_regen', ['password' => $acctPw]);
$firstToken = (string)$calToken();
check($made['replaced'] === false && $made['address'] === 'https://example.invalid/calfeed.php?u=' . $firstToken && strlen($firstToken) === 48,
    'The app creates a calendar address');
$show = $call('cal_feed_show', ['password' => $acctPw]);
$summary = $call('account');
check($show['exists'] === true && $show['address'] === $made['address'] && $summary['has_cal_feed'] === true && !str_contains(json_encode($summary), $firstToken),
    'The calendar address is shown only after the password; the summary says only that one exists');
$replaced = $call('cal_feed_regen', ['password' => $acctPw]);
check($replaced['replaced'] === true && (string)$calToken() !== $firstToken && $audited('calfeed.regen') >= 2, 'Replacing the calendar address makes a new one, audited');
$db->exec("INSERT INTO `groups` (name,perms) VALUES ('Account cases: no scheduling','clients')"); $noScheduleGroup = (int)$db->lastInsertId();
$db->exec("UPDATE users SET group_id=$noScheduleGroup WHERE id=1");
$keptToken = (string)$calToken();
$call('cal_feed_show', ['password' => $acctPw], [], false, 'Scheduling permission');
$call('cal_feed_regen', ['password' => $acctPw], [], false, 'Scheduling permission');
check((string)$calToken() === $keptToken && $call('account')['can_schedule'] === false,
    'Without the Scheduling permission the calendar address is neither shown nor replaced');
$db->exec("UPDATE users SET group_id=NULL WHERE id=1; DELETE FROM `groups` WHERE id=$noScheduleGroup");

/* ---- Passkeys ---- */
$call('remove_passkey', ['id' => $pkId]);
check((int)$db->query("SELECT COUNT(*) FROM passkeys WHERE id=$pkId")->fetchColumn() === 0 && $audited('passkey.remove') >= 1, 'The app removes one of the account\'s passkeys, audited');
$call('remove_passkey', ['id' => $pkId], [], false, 'already been removed');

/* ---- Password change: same rules as the website, then every app session ends ---- */
$addPasskey('Synthetic laptop');
$newPw = 'Synthetic-account-pass-2';
$pwBody = ['current_password' => $acctPw, 'new_password' => $newPw, 'confirm_password' => $newPw];
$call('change_password', $pwBody, [], false, 'Tick the box');
$call('change_password', ['confirm_password' => 'different-password'] + $pwBody + ['ack_passkeys' => true], [], false, 'does not match');
$call('change_password', ['new_password' => 'short', 'confirm_password' => 'short'] + $pwBody + ['ack_passkeys' => true], [], false, 'at least 10 characters');
check(password_verify($acctPw, (string)$db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()), 'A refused password change changes nothing');
$otherDevice();
$changed = $call('change_password', $pwBody + ['ack_passkeys' => true]);
$pwRow = $db->query('SELECT password_hash, cal_token FROM users WHERE id=1')->fetch();
check($changed['signed_out'] === true && $changed['passkeys_removed'] === 1 && $changed['cal_feed_turned_off'] === true
    && password_verify($newPw, (string)$pwRow['password_hash']) && $pwRow['cal_token'] === null && $appSessions() === 0
    && (int)$db->query('SELECT COUNT(*) FROM passkeys WHERE user_id=1')->fetchColumn() === 0 && $audited('password.change') >= 1,
    'A password change from the app signs out every app session, removes passkeys and the calendar address, and is audited');
$acctPw = $newPw;
$restoreAppToken();

/* ---- Sign out everywhere ---- */
$otherDevice();
$call('logout_all', ['password' => 'not-the-password'], [], false, 'Password incorrect');
check($appSessions() === 2, 'Sign out everywhere with a wrong password ends nothing');
$out = $call('logout_all', ['password' => $acctPw]);
check($out['signed_out'] === true && $out['sessions_ended'] === 2 && $appSessions() === 0 && $audited('api.logout_all') === 1
    && str_contains($out['message'], 'Website sessions are not affected'),
    'Sign out everywhere ends every app session of the account, is audited, and says website sessions stay');
$restoreAppToken();

/* ---- Too many wrong passwords ---- */
$db->exec("DELETE FROM login_attempts WHERE email LIKE 'reauth:%'");
for ($i = 0; $i < 4; $i++) $call('cal_feed_show', ['password' => 'not-the-password'], [], false, 'Password incorrect');
$otherDevice();
$call('cal_feed_show', ['password' => 'not-the-password'], [], false, 'signed out to protect your account');
check($appSessions() === 0 && $audited('reauth.lockout') >= 1, 'The fifth wrong password signs the account out of the app everywhere, audited');
$restoreAppToken();
$call('cal_feed_show', ['password' => $acctPw], [], false, 'Wait 15 minutes');
check($appSessions() === 1, 'After that the password is not tested for 15 minutes');

$db->exec("DELETE FROM login_attempts WHERE email LIKE 'reauth:%' OR email LIKE 'acct-mail-%'");
$db->exec("DELETE FROM settings WHERE name LIKE 'totp_pending_%'");
$db->exec("UPDATE users SET password_hash='test', totp_enabled=0, totp_secret=NULL, cal_token=NULL, group_id=NULL WHERE id=1");

/* ---- Sign-in codes guessed with no sign-in pending never lock the address ---- */
$lockEmail=(string)$db->query('SELECT email FROM users WHERE id=1')->fetchColumn();
$db->exec("DELETE FROM login_attempts WHERE success=0");
for($i=0;$i<8;$i++)$call('verify',['email'=>$lockEmail,'code'=>'000000'],[],false,'not correct');
$call('verify',['email'=>$lockEmail,'code'=>'000000'],[],false,'Too many attempts');
check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email=".$db->quote('verify:'.$lockEmail))->fetchColumn()===0
    &&(int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='verify-anon'")->fetchColumn()===8,
    'Wrong codes sent with no sign-in pending count against the sender (locked after 8), never against the staff address');
$db->exec("DELETE FROM login_attempts WHERE email='verify-anon'");
