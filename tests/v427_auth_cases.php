<?php
// Included by full_schema.php after v426_cases.php, against its synthetic database only:
// 4.27.0 sign-in and hand-over fixes (H1, M1, the resend cap and the session epoch).

// ---- H1: a device handed over for signing in person opens nothing else ----
$k1 = $call('start_signature', ['client_id' => $ac, 'kind' => 'document', 'signer_role' => 'Client', 'signer_name' => 'App Synthetic Client', 'title' => 'Synthetic kiosk 427 one', 'body' => 'Kiosk words one.']);
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['kiosk_sign' => ['client' => $ac, 'id' => $k1['id'], 'lang' => '']]));
[$code, $html, $err] = $renderPage('signatures', ['client' => (string)$ac, 'sign' => (string)$k1['id']]);
check(str_contains($html, 'Kiosk words one.') && !str_contains($html, 'Send a document to sign'), 'While waiting, the hand-over page still opens' . ($err ? ": $err" : ''));
$call('sign_in_person', ['client_id' => $ac, 'id' => $k1['id'], 'typed_name' => 'App Synthetic Client']);
[$code, $html, $err] = $renderPage('signatures', ['client' => (string)$ac, 'sign' => (string)$k1['id']]);
check(!str_contains($html, 'Send a document to sign') && !str_contains($html, 'name="withdraw"') && !str_contains($html, 'id="main-nav"'),
    'After signing, Back to the hand-over address does not show the staff Documents to sign page' . ($err ? ": $err" : ''));
[$code, $html, $err] = $renderPage('signatures', ['client' => (string)$ac, 'signed' => '1']);
check(str_contains($html, 'It is signed. Thank you.') && !str_contains($html, 'name="withdraw"'), 'The thank-you page still shows during the hand-over' . ($err ? ": $err" : ''));

$k2 = $call('start_signature', ['client_id' => $ac, 'kind' => 'document', 'signer_role' => 'Client', 'signer_name' => 'App Synthetic Client', 'title' => 'Synthetic kiosk 427 two', 'body' => 'Kiosk words two.']);
$other = $call('start_signature', ['client_id' => $ac, 'kind' => 'document', 'signer_role' => 'Client', 'signer_name' => 'App Synthetic Client', 'title' => 'Synthetic kiosk 427 other', 'body' => 'Another document.']);
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['kiosk_sign' => ['client' => $ac, 'id' => $k2['id'], 'lang' => '']]));
$renderPage('signatures', ['client' => (string)$ac, 'signed' => '1'], ['withdraw' => (string)$other['id']]);
$renderPage('signatures', ['client' => (string)$ac, 'sign' => (string)$k2['id']], ['sign_now' => (string)$k2['id'], 'withdraw' => (string)$other['id']]);
$renderPage('signatures', ['client' => (string)$ac], ['send_signature' => '1', 'kind' => 'services', 'signer_role' => 'Client', 'signer_name' => 'App Synthetic Client', 'signer_email' => 'kiosk427@example.invalid']);
check($db->query('SELECT withdrawn_at FROM client_signatures WHERE id=' . (int)$other['id'])->fetchColumn() === null
    && $db->query('SELECT withdrawn_at FROM client_signatures WHERE id=' . (int)$k2['id'])->fetchColumn() === null
    && !(int)$db->query("SELECT COUNT(*) FROM client_signatures WHERE signer_email='kiosk427@example.invalid'")->fetchColumn(),
    'During a hand-over, no other form on the Documents to sign page runs (withdraw, send)');
// The signer's own Hand back still works.
$renderPage('signatures', ['client' => (string)$ac], ['sign_now' => (string)$k2['id'], 'stop' => '1']);
check($db->query('SELECT withdrawn_at FROM client_signatures WHERE id=' . (int)$k2['id'])->fetchColumn() !== null, 'Hand back without signing still works during the hand-over');
[$code, $html, $err] = $renderPage('help', []);
check(!str_contains($html, 'id="main-nav"'), 'Help does not show the staff menu on a handed-over device' . ($err ? ": $err" : ''));
// Public pages that do not sign out (forgot, portal, sign…) still never show the staff menu.
[$code, $html, $err] = $renderPage('forgot', []);
putenv('CMS_TEST_SESSION_ADD');
check(str_contains($html, 'Forgot your password?') && !str_contains($html, 'id="main-nav"'), 'A public page shows no staff menu on a handed-over device' . ($err ? ": $err" : ''));

// ---- M1: an account with an authenticator app must use it ----
require_once $root . '/public/inc/totp.php';
$secret427 = totp_generate_secret();
$db->prepare("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('Totp Synthetic 427','totp427@example.invalid','test427totp','instructor',1,?)")->execute([$secret427]);
$totpUid = (int)$db->lastInsertId();
$emailCode = totp_code($secret427) === '123456' ? '654321' : '123456';
$db->prepare("INSERT INTO login_otps (user_id, code_hash, expires_at, channel) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'web')")->execute([$totpUid, password_hash($emailCode, PASSWORD_DEFAULT)]);
$totpOtp = (int)$db->lastInsertId();
putenv('CMS_TEST_ANON=1');
putenv('CMS_TEST_SESSION=' . json_encode(['pending_user_id' => $totpUid, 'pending_at' => time(), 'pending_credential_version' => hash('sha256', 'test427totp')]));
[$code, $html, $err] = $renderPage('verify', []);
check(str_contains($html, 'name="code"') && !str_contains($html, 'Send a new code'), 'An account with an authenticator app is not offered an emailed code' . ($err ? ": $err" : ''));
[$code, $html, $err] = $renderPage('verify', [], ['code' => $emailCode]);
check((int)$db->query("SELECT consumed FROM login_otps WHERE id=$totpOtp")->fetchColumn() === 0
    && !(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$totpUid")->fetchColumn(),
    'An account with an authenticator app cannot sign in with the emailed code' . ($err ? ": $err" : ''));
$renderPage('verify', [], ['code' => totp_code($secret427)]);
check((int)$db->query("SELECT consumed FROM login_otps WHERE id=$totpOtp")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$totpUid")->fetchColumn() === 1,
    'Its authenticator app code still signs it in');

// ---- "Send a new code" counts against the 5 codes per 15 minutes for the account ----
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('Resend Synthetic 427','resend427@example.invalid','test427resend','instructor')");
$resendUid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO login_otps (user_id, code_hash, expires_at, channel) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'web')")->execute([$resendUid, password_hash('111111', PASSWORD_DEFAULT)]);
putenv('CMS_TEST_SESSION=' . json_encode(['pending_user_id' => $resendUid, 'pending_at' => time(), 'pending_credential_version' => hash('sha256', 'test427resend')]));
$sentCount = fn(): int => (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='login:resend427@example.invalid' AND success=1")->fetchColumn();
$otpCount = fn(): int => (int)$db->query("SELECT COUNT(*) FROM login_otps WHERE user_id=$resendUid")->fetchColumn();
$renderPage('verify', [], ['resend' => '1']);
check($sentCount() === 1 && $otpCount() === 2, 'Each new code is recorded against the account\'s sign-in code cap');
for ($i = 0; $i < 4; $i++) $db->exec("INSERT INTO login_attempts (ip, email, success) VALUES ('192.0.2.27','login:resend427@example.invalid',1)");
$renderPage('verify', [], ['resend' => '1']);
check($sentCount() === 5 && $otpCount() === 2, 'Past 5 codes in 15 minutes, "Send a new code" sends nothing');
// A correct password for an account with an authenticator app emails nothing, so it is not counted as a
// code sent: six sign-ins in a row do not hit the 5-codes cap.
$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash('Synthetic427 password', PASSWORD_DEFAULT), $totpUid]);
putenv('CMS_TEST_SESSION');
$otpBefore = (int)$db->query("SELECT COUNT(*) FROM login_otps WHERE user_id=$totpUid")->fetchColumn();
for ($i = 0; $i < 6; $i++) $renderPage('login', [], ['email' => 'totp427@example.invalid', 'password' => 'Synthetic427 password']);
check((int)$db->query("SELECT COUNT(*) FROM login_otps WHERE user_id=$totpUid")->fetchColumn() === $otpBefore + 6
    && !(int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='login:totp427@example.invalid' AND success=1")->fetchColumn(),
    'Signing in to an account with an authenticator app is not counted against the emailed-codes cap');
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');

// ---- client_ip(): the forwarded address is used only from a trusted proxy ----
$cfgKeep = $GLOBALS['CONFIG']['app'] ?? [];
$srvKeep = [$_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null];
$ipFrom = function (string $remote, string $xff): string { $_SERVER['REMOTE_ADDR'] = $remote; $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff; return client_ip(); };
$GLOBALS['CONFIG']['app']['trust_proxy'] = false; unset($GLOBALS['CONFIG']['app']['trusted_proxies']);
check($ipFrom('10.0.0.2', '198.51.100.7') === '10.0.0.2', 'Without trust_proxy the forwarded header is ignored');
$GLOBALS['CONFIG']['app']['trust_proxy'] = true;
check($ipFrom('10.0.0.2', '198.51.100.66, 192.168.1.20') === '192.168.1.20', 'Behind a local proxy, an office client\'s private address is used');
check($ipFrom('8.8.8.8', '198.51.100.7') === '8.8.8.8', 'A forwarded header from an address that is not a proxy is ignored');
$GLOBALS['CONFIG']['app']['trusted_proxies'] = ['10.0.0.2', '10.0.0.3'];
check($ipFrom('10.0.0.2', '198.51.100.66, 192.168.1.20, 10.0.0.3') === '192.168.1.20', 'Listed proxies are skipped from the right');
check($ipFrom('10.0.0.9', '198.51.100.7') === '10.0.0.9', 'An unlisted sender\'s forwarded header is ignored');
$GLOBALS['CONFIG']['app'] = $cfgKeep;
[$_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']] = $srvKeep;
if ($srvKeep[0] === null) unset($_SERVER['REMOTE_ADDR']);
if ($srvKeep[1] === null) unset($_SERVER['HTTP_X_FORWARDED_FOR']);

// ---- Removing a passkey ends the account's other browser sessions ----
require_once $root . '/public/inc/account_actions.php';
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('Epoch Synthetic 427','epoch427@example.invalid','test427epoch','instructor')");
$epochUid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO passkeys (user_id, credential_id, public_key, name) VALUES (?, 'synthetic-cred-427', 'synthetic-key', 'Synthetic phone')")->execute([$epochUid]);
$pk427 = (int)$db->lastInsertId();
$rowBefore = $db->query("SELECT * FROM users WHERE id=$epochUid")->fetch();
$oldSession = ['user_id' => $epochUid, 'otp_verified' => true, 'credential_version' => credential_version($rowBefore)];
$r = account_remove_passkey(['id' => $epochUid, 'name' => 'Epoch Synthetic 427', 'email' => 'epoch427@example.invalid'], $pk427);
$rowAfter = $db->query("SELECT * FROM users WHERE id=$epochUid")->fetch();
check($r['ok'] && (int)$rowAfter['session_epoch'] === (int)$rowBefore['session_epoch'] + 1 && !session_matches_user($oldSession, $rowAfter),
    'Removing a passkey bumps the session epoch, ending other browser sessions');
check(credential_version(['password_hash' => 'test']) === hash('sha256', 'test') && credential_version(['password_hash' => 'test', 'session_epoch' => 0]) === hash('sha256', 'test'),
    'An epoch of 0 (or no column yet) keeps sessions from before 4.27.0 valid');

// ---- Microsoft sign-in: an email match needs the acct claim showing a member of this tenant ----
$iss427 = 'https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/v2.0';
$tid427 = '11111111-2222-3333-4444-555555555555';
check(sso_email_match_allowed(['tid' => $tid427, 'acct' => 0], $iss427), 'A member (acct 0) of this tenant is matched by email');
check(!sso_email_match_allowed(['tid' => $tid427], $iss427), 'Without the acct claim the email is not matched');
check(!sso_email_match_allowed(['tid' => $tid427, 'acct' => 1], $iss427), 'A guest (acct 1) is not matched by email');
check(!sso_email_match_allowed(['tid' => '99999999-0000-0000-0000-000000000000', 'acct' => 0], $iss427), 'Another tenant is not matched by email');
