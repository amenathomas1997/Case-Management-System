<?php
// Included by full_schema.php after the v428 cases, against its synthetic database only:
// 4.29.0 sign-in fixes: P1 (a deactivated account's Microsoft identity), P2 (a browser handed
// over for signing), P14 (recovering from a lost key file) and the access Lows (staff training
// ticks, credential and hold form error summaries).

// The admin pages below run as user 1, as a full administrator (put back at the end).
$admin429 = $db->query('SELECT role, group_id FROM users WHERE id = 1')->fetch();
$db->exec("UPDATE users SET role = 'admin', group_id = NULL WHERE id = 1");

// ---- P1: a Microsoft identity that belongs to a deactivated account never signs in or provisions ----
$tid429 = '11111111-2222-3333-4444-555555555429';
$cid429 = '99999999-8888-7777-6666-555555555429';
$db->exec("INSERT INTO `groups` (name, perms) VALUES ('V429-AUTH SSO group', 'clients')");
$entraKeep429 = $db->query("SELECT name, value FROM settings WHERE name IN ('entra_enabled','entra_tenant_id','entra_client_id','entra_auto_provision_group')")->fetchAll(PDO::FETCH_KEY_PAIR);
$setEntra429 = $db->prepare('INSERT INTO settings (name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
foreach (['entra_enabled' => '1', 'entra_tenant_id' => $tid429, 'entra_client_id' => $cid429, 'entra_auto_provision_group' => 'V429-AUTH SSO group'] as $k => $v) $setEntra429->execute([$k, $v]);
$sso429 = function (array $claims, array $session = []) use ($renderPage, $tid429, $cid429): array {
    $nonce = bin2hex(random_bytes(8)); $state = bin2hex(random_bytes(8));
    $claims += ['iss' => "https://login.microsoftonline.com/$tid429/v2.0", 'aud' => $cid429, 'tid' => $tid429, 'exp' => time() + 600, 'nonce' => $nonce, 'acct' => 0];
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    putenv('CMS_TEST_SSO_TOKENS=' . json_encode(['id_token' => $b64('{"alg":"none"}') . '.' . $b64(json_encode($claims)) . '.' . $b64('sig')]));
    putenv('CMS_TEST_ANON=1');
    putenv('CMS_TEST_SESSION=' . json_encode($session + ['sso_state' => $state, 'sso_nonce' => $nonce]));
    $r = $renderPage('sso', ['code' => 'synthetic-code', 'state' => $state]);
    putenv('CMS_TEST_SSO_TOKENS'); putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
    return $r;
};
$db->exec("INSERT INTO users (name,email,password_hash,role,is_active,entra_oid) VALUES ('V429-AUTH Left','v429-old.name@example.invalid','x','instructor',0,'v429-oid-left')");
$left429 = (int)$db->lastInsertId();
[, , $err] = $sso429(['oid' => 'v429-oid-left', 'email' => 'v429-new.name@example.invalid', 'name' => 'V429-AUTH New Name']);
check($err === '' && !(int)$db->query("SELECT COUNT(*) FROM users WHERE email='v429-new.name@example.invalid'")->fetchColumn()
    && (int)$db->query("SELECT COUNT(*) FROM users WHERE entra_oid='v429-oid-left'")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login.sso_fail' AND entity_id=$left429 AND detail LIKE '%deactivated account%'")->fetchColumn() === 1
    && !(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('login','user.provision') AND detail LIKE '%Entra%' AND entity_id IN (SELECT id FROM users WHERE email='v429-new.name@example.invalid')")->fetchColumn(),
    'P1: a deactivated account\'s Microsoft identity, now with another email, gets no new account and the refusal is audited' . ($err ? ": $err" : ''));
// The same with an active account holding that email: never bound to a deactivated person's identity.
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V429-AUTH Same Email','v429-same@example.invalid','x','instructor')");
$same429 = (int)$db->lastInsertId();
$sso429(['oid' => 'v429-oid-left', 'email' => 'v429-same@example.invalid', 'name' => 'V429-AUTH Same Email']);
check($db->query("SELECT entra_oid FROM users WHERE id=$same429")->fetchColumn() === null
    && !(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$same429")->fetchColumn(),
    'P1: that identity is not linked to another account by email either');
// An active account with its identity still signs in.
$db->exec("INSERT INTO users (name,email,password_hash,role,entra_oid) VALUES ('V429-AUTH Active','v429-active@example.invalid','x','instructor','v429-oid-active')");
$act429 = (int)$db->lastInsertId();
$sso429(['oid' => 'v429-oid-active', 'email' => 'v429-active@example.invalid', 'name' => 'V429-AUTH Active']);
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$act429 AND detail='Entra ID SSO'")->fetchColumn() === 1, 'P1: an active linked account still signs in with Microsoft');

// ---- P1: the UNIQUE key on users.entra_oid, with duplicates in existing data ----
// The real migration, without its schema_version line (this runs on a copy of the users table).
$ddl429 = (string)preg_replace('/^INSERT INTO `settings`.*$/m', '', (string)@file_get_contents($root . '/dist/migrations/mig_4.29.0.sql'));
if ($ddl429 === '') {
    echo "SKIP: mig_4.29.0.sql not found\n";
} else {
    $db->exec('DROP TABLE IF EXISTS users_v429');
    $db->exec('CREATE TABLE users_v429 LIKE users');
    // The copy starts in the 4.28.0 shape: a plain (not unique) key, as before the migration.
    $db->exec('ALTER TABLE users_v429 DROP INDEX IF EXISTS idx_entra_oid'); $db->exec('ALTER TABLE users_v429 ADD KEY idx_entra_oid (entra_oid)');
    $db->exec("INSERT INTO users_v429 (id,name,email,password_hash,role,is_active,entra_oid) VALUES
        (1,'V429-AUTH Dup A','v429-dup-a@example.invalid','x','instructor',0,'v429-dup'),(2,'V429-AUTH Dup B','v429-dup-b@example.invalid','x','instructor',1,'v429-dup'),
        (3,'V429-AUTH Dup C','v429-dup-c@example.invalid','x','instructor',1,'v429-dup'),(4,'V429-AUTH Gone A','v429-gone-a@example.invalid','x','instructor',0,'v429-gone'),
        (5,'V429-AUTH Gone B','v429-gone-b@example.invalid','x','instructor',0,'v429-gone'),(6,'V429-AUTH Blank','v429-blank@example.invalid','x','instructor',1,''),
        (7,'V429-AUTH Blank 2','v429-blank2@example.invalid','x','instructor',1,''),(8,'V429-AUTH Single','v429-single@example.invalid','x','instructor',1,'v429-single')");
    $sql429 = str_replace('`users`', '`users_v429`', (string)preg_replace('/^\s*--.*$/m', '', $ddl429));
    $runDdl429 = function () use ($db, $sql429): void { foreach (preg_split('/;\s*$/m', $sql429) as $st) if (trim($st) !== '') $db->exec($st); };
    $runDdl429();
    $oids429 = $db->query('SELECT id, entra_oid FROM users_v429 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $uniq429 = $db->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users_v429' AND INDEX_NAME='idx_entra_oid'")->fetchColumn();
    check($oids429 === [1 => null, 2 => 'v429-dup', 3 => null, 4 => 'v429-gone', 5 => null, 6 => null, 7 => null, 8 => 'v429-single'] && (string)$uniq429 === '0'
        && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='user.sso_unlink' AND entity_id IN (1,3,5) AND detail LIKE 'Database upgrade 4.29.0%'")->fetchColumn() === 3,
        'P1 DDL: duplicates keep the identity on the active (else the deactivated) lowest id, the rest are unlinked and audited, and the key is UNIQUE');
    $runDdl429();
    check($db->query('SELECT id, entra_oid FROM users_v429 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) === $oids429
        && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='user.sso_unlink' AND detail LIKE 'Database upgrade 4.29.0%'")->fetchColumn() === 3, 'P1 DDL: running it again changes nothing');
    $dupRefused429 = false;
    try { $db->exec("UPDATE users_v429 SET entra_oid='v429-dup' WHERE id=8"); } catch (PDOException $e) { $dupRefused429 = (string)$e->getCode() === '23000'; }
    check($dupRefused429, 'P1 DDL: a second account can no longer take the same identity');
    $db->exec('DROP TABLE users_v429');
    $db->exec("DELETE FROM audit_log WHERE action='user.sso_unlink' AND detail LIKE 'Database upgrade 4.29.0%'");
}

// ---- P2: a browser handed over for signing ----
$q429 = sso_authorize_params('cid', 'https://example.invalid/sso.php', 'st', 'no', true);
$p429 = sso_authorize_params('cid', 'https://example.invalid/sso.php', 'st', 'no', false);
check(($q429['prompt'] ?? '') === 'login' && ($q429['max_age'] ?? '') === '0' && !isset($p429['prompt']) && !isset($p429['max_age']),
    'P2: after a hand-over Microsoft is told to ask for the password again (prompt=login, max_age=0); otherwise not');
check(sso_fresh_auth_ok(['auth_time' => time()], time() - 5) && !sso_fresh_auth_ok([], time() - 5) && !sso_fresh_auth_ok(['auth_time' => time() - 3600], time() - 5),
    'P2: only an auth_time after the sign-in started counts; none, or an old one, is refused');
unset($_COOKIE[HANDOVER_COOKIE]);
$before429 = handover_marked();
handover_mark();
$marked429 = handover_marked();
handover_clear();
check(!$before429 && $marked429 && !handover_marked(), 'P2: the hand-over marker is set and cleared');
$_COOKIE[HANDOVER_COOKIE] = (string)(time() - 10);
check(!handover_marked(), 'P2: an expired marker is ignored');
unset($_COOKIE[HANDOVER_COOKIE]);
// Microsoft sign-in on a handed-over browser: refused without a fresh password at Microsoft.
putenv('CMS_TEST_HANDOVER=1');
$sso429(['oid' => 'v429-oid-active', 'email' => 'v429-active@example.invalid', 'name' => 'V429-AUTH Active']);
$sso429(['oid' => 'v429-oid-active', 'email' => 'v429-active@example.invalid', 'name' => 'V429-AUTH Active'], ['sso_fresh' => time() - 20]);
$missing429 = (string)$db->query("SELECT value FROM settings WHERE name='sso_auth_time_missing'")->fetchColumn();
[$code, $html, $err] = $renderPage('federation', []);
$fedWarn429 = str_contains($html, 'did not send the optional claim auth_time') && str_contains($html, '/login.php</code>');
[$code, $html, $err] = $renderPage('system_health', []);
check($missing429 === date('Y-m-d') && $fedWarn429 && str_contains($html, 'did not send the optional claim auth_time'),
    'P2: an ID token without auth_time after a hand-over is recorded, and Federation and System health say the claim is missing');
$sso429(['oid' => 'v429-oid-active', 'email' => 'v429-active@example.invalid', 'name' => 'V429-AUTH Active', 'auth_time' => time() - 7200], ['sso_fresh' => time() - 20]);
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$act429")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login.sso_fail' AND detail LIKE 'browser handed over for signing%'")->fetchColumn() === 3,
    'P2: Microsoft sign-in after a hand-over is refused without a new password entry (none asked, no auth_time, an old auth_time)');
$sso429(['oid' => 'v429-oid-active', 'email' => 'v429-active@example.invalid', 'name' => 'V429-AUTH Active', 'auth_time' => time()], ['sso_fresh' => time() - 20]);
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$act429")->fetchColumn() === 2, 'P2: with the password just typed at Microsoft, it signs in');
check((string)$db->query("SELECT value FROM settings WHERE name='sso_auth_time_missing'")->fetchColumn() === '', 'P2: once auth_time arrives, the warning is cleared');
check(HANDOVER_SECONDS === 7200 && str_contains(HANDOVER_SSO_MSG, 'Forgot your password?'), 'P2: the marker lasts 2 hours, and the refusal points people without a password to "Forgot your password?"');
// Passkey sign-in is refused, and the sign-in page offers no passkey.
[$code, $out, $err] = $renderPage('passkey', ['action' => 'login_options'], []);
check(str_contains($out, json_encode(HANDOVER_PASSKEY_MSG)) && !str_contains($out, '"challenge"'), 'P2: a passkey cannot start signing in on a handed-over browser' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderPage('passkey', ['action' => 'login_finish'], []);
check(str_contains($out, json_encode(HANDOVER_PASSKEY_MSG)), 'P2: nor finish');
putenv('CMS_TEST_HANDOVER');
[$code, $out, $err] = $renderPage('passkey', ['action' => 'login_options'], []);
check(str_contains($out, '"challenge"'), 'P2: without a hand-over a passkey sign-in starts as before' . ($err ? ": $err" : ''));
putenv('CMS_TEST_ANON=1');
putenv('CMS_TEST_SESSION=' . json_encode(['kiosk_sign' => ['client' => 1, 'id' => 999999, 'lang' => '']]));
[$code, $html, $err] = $renderPage('login', []);
check(!str_contains($html, 'id="passkey-btn"') && str_contains($html, h(HANDOVER_PASSKEY_MSG)) && str_contains($html, 'name="password"'),
    'P2: the sign-in page on a handed-over session offers the password, not a passkey' . ($err ? ": $err" : ''));
putenv('CMS_TEST_SESSION');
[$code, $html, $err] = $renderPage('login', []);
check(str_contains($html, 'id="passkey-btn"') && !str_contains($html, h(HANDOVER_PASSKEY_MSG)), 'P2: otherwise the passkey button is there');
putenv('CMS_TEST_ANON');
$db->exec("DELETE FROM settings WHERE name IN ('entra_enabled','entra_tenant_id','entra_client_id','entra_auto_provision_group')");
foreach ($entraKeep429 as $k => $v) $setEntra429->execute([$k, $v]);

// ---- P14: a lost or replaced key file can be recovered from System health ----
require_once $root . '/public/inc/totp.php';
$keyFile429 = $tmp . '/meeting_tokens.key';
meeting_key(true);
$origKey429 = (string)file_get_contents($keyFile429);
// Everything else that needs the key is set aside, so only this test's rows are forgotten, and put back after.
$seedsKeep429 = $db->query("SELECT id, totp_enabled, totp_secret, totp_last_step FROM users WHERE totp_secret LIKE 'v1:%'")->fetchAll();
$meetKeep429 = $db->query('SELECT * FROM meeting_accounts')->fetchAll();
$qboKeep429 = $db->query('SELECT * FROM qbo_connection')->fetchAll();
$db->exec("UPDATE users SET totp_secret = NULL WHERE totp_secret LIKE 'v1:%'");
$db->exec('DELETE FROM meeting_accounts'); $db->exec('DELETE FROM qbo_connection');
$seed429 = totp_generate_secret();
$db->prepare("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('V429-AUTH Seed','v429-seed@example.invalid','x','instructor',1,?)")->execute([totp_secret_store($seed429)]);
$seedUid429 = (int)$db->lastInsertId();
meeting_store($seedUid429, 'zoom', ['access_token' => 'v429-access', 'refresh_token' => 'v429-refresh', 'expires_in' => 3600], 'v429-zoom@example.invalid');
$db->prepare("INSERT INTO qbo_connection (id, realm_id, sandbox, access_token, refresh_token, expires_at) VALUES (1,'429429',1,?,?,NOW())")->execute([meeting_seal('v429-qa'), meeting_seal('v429-qr')]);
// totp_secret_store never falls back to plain text.
$refused429 = '';
try { totp_secret_store('ABC'); } catch (TotpStoreRefused $e) { $refused429 = $e->getMessage(); }
rename($keyFile429, $keyFile429 . '.v429');
$keyRefused429 = false; $storeRefused429 = '';
try { meeting_key(true); } catch (MeetingKeyUnavailable $e) { $keyRefused429 = true; }
try { totp_secret_store($seed429); } catch (TotpStoreRefused $e) { $storeRefused429 = $e->getMessage(); }
check($refused429 !== '' && $keyRefused429 && $storeRefused429 === TOTP_STORE_REFUSED_MSG && totp_store_problem() === TOTP_STORE_REFUSED_MSG && !is_file($keyFile429),
    'P14: an authenticator seed is never stored as plain text: refused with a clear message when the key file is missing');
// QuickBooks reconnect with the key gone: the real reason, no server error.
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.qbo.client_id','v429-qbo-id'),('cfg.qbo.client_secret','v429-qbo-secret'),('cfg.qbo.sandbox','yes') ON DUPLICATE KEY UPDATE value=VALUES(value)");
putenv('CMS_TEST_QBO=' . json_encode(['https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => ['body' => ['access_token' => 'v429-new-access', 'refresh_token' => 'v429-new-refresh', 'expires_in' => 3600]]]));
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['qbo_oauth' => ['state' => 'v429state']]));
[$code, $out, $err] = $renderPage('qbo_connect', ['code' => 'v429code', 'state' => 'v429state', 'realmId' => '429429']);
putenv('CMS_TEST_SESSION_ADD'); putenv('CMS_TEST_QBO');
$db->exec("DELETE FROM settings WHERE name IN ('cfg.qbo.client_id','cfg.qbo.client_secret','cfg.qbo.sandbox')");
check(!str_contains($err, 'Uncaught') && !str_contains($err, 'Fatal') && $code === 0 && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='qbo.connect_fail' AND detail LIKE 'key file unusable%'")->fetchColumn() === 1
    && meeting_open((string)$db->query('SELECT refresh_token FROM qbo_connection WHERE id=1')->fetchColumn()) === null && !is_file($keyFile429),
    'P14: reconnecting QuickBooks without the key file says why, without a server error, and stores nothing' . ($err ? ": $err" : ''));
[$code, $html, $err] = $renderPage('system_health', []);
check(str_contains($html, 'meeting_tokens.key is missing') && str_contains($html, 'id="forget-keys-h"') && preg_match('~<label for="forget_confirm">Type FORGET to confirm <span aria-hidden="true">\*</span>~', $html) === 1,
    'P14: System health offers "Forget stored connections and keys" when the key file is missing' . ($err ? ": $err" : ''));
$renderPage('system_health', [], ['forget_keys' => '1', 'forget_confirm' => 'forgot']);
check((int)$db->query("SELECT totp_enabled FROM users WHERE id=$seedUid429")->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM meeting_accounts')->fetchColumn() === 1
    && !is_file($keyFile429), 'P14: without the typed word FORGET nothing changes');
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['field_errors' => ['forget_confirm' => 'That is not the word FORGET. Nothing was changed.']]));
[$code, $html, $err] = $renderPage('system_health', []);
putenv('CMS_TEST_SESSION_ADD');
check(str_contains($html, 'id="form-error-summary"') && str_contains($html, 'href="#forget_confirm"') && str_contains($html, 'aria-describedby="err-forget_confirm"'),
    'P14: the confirmation error is on its field, with a summary');
[$code, $out, $err] = $renderPage('system_health', [], ['forget_keys' => '1', 'forget_confirm' => ' forget ']);
$row429 = $db->query("SELECT totp_enabled, totp_secret FROM users WHERE id=$seedUid429")->fetch();
check(!str_contains($err, 'Uncaught') && (int)$row429['totp_enabled'] === 0 && $row429['totp_secret'] === null && !(int)$db->query('SELECT COUNT(*) FROM meeting_accounts')->fetchColumn()
    && !(int)$db->query('SELECT COUNT(*) FROM qbo_connection')->fetchColumn() && is_file($keyFile429) && (fileperms($keyFile429) & 0077) === 0
    && (string)file_get_contents($keyFile429) !== $origKey429
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='keys.forget' AND detail LIKE 'key file missing: 1 authenticator app(s) turned off, 1 meeting account(s) and 1 QuickBooks%'")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='user.reset_2fa' AND entity_id=$seedUid429")->fetchColumn() === 1,
    'P14: "Forget stored connections and keys" turns off unreadable authenticator apps, forgets the connections, makes a new key and audits it' . ($err ? ": $err" : ''));
meeting_key(true);
$new429 = totp_secret_store($seed429);
check(str_starts_with($new429, 'v1:') && totp_secret_plain($new429) === $seed429 && meeting_open(meeting_seal('v429')) === 'v429', 'P14: with the new key, authenticator apps and connections can be set up again');
[$code, $html, $err] = $renderPage('system_health', []);
check(!str_contains($html, 'meeting_tokens.key') && !str_contains($html, 'forget-keys-h'), 'P14: afterwards System health is quiet about the key file');
// A damaged key file is moved aside, never deleted; a good key readable by others is never forgotten.
$db->prepare('UPDATE users SET totp_enabled=1, totp_secret=? WHERE id=?')->execute([$new429, $seedUid429]);
file_put_contents($keyFile429, 'not a key'); chmod($keyFile429, 0600);
check(meeting_key_problem() === 'damaged', 'P14: a damaged key file is told apart');
$renderPage('system_health', [], ['forget_keys' => '1', 'forget_confirm' => 'FORGET']);
check(count(glob($keyFile429 . '.damaged-*') ?: []) === 1 && is_file($keyFile429) && (int)$db->query("SELECT totp_enabled FROM users WHERE id=$seedUid429")->fetchColumn() === 0,
    'P14: a damaged key file is kept aside under another name and a new one is made');
meeting_key(true);
$db->prepare('UPDATE users SET totp_enabled=1, totp_secret=? WHERE id=?')->execute([totp_secret_store($seed429), $seedUid429]);
chmod($keyFile429, 0644);
check(meeting_key_problem() === 'permissions', 'P14: a good key readable by others is told apart');
$renderPage('system_health', [], ['forget_keys' => '1', 'forget_confirm' => 'FORGET']);
check((int)$db->query("SELECT totp_enabled FROM users WHERE id=$seedUid429")->fetchColumn() === 1, 'P14: and nothing is forgotten for it (chmod 600 fixes it)');
chmod($keyFile429, 0600);
// A key file the site cannot open (another owner) may be a good key: never forgotten.
chmod($keyFile429, 0000);
if (is_readable($keyFile429)) {
    echo "SKIP: running as a user who can read a mode-000 file (root)\n";
} else {
    check(meeting_key_problem() === 'access', 'P14: a key file the site cannot open is told apart from a damaged one');
    [$code, $html, $err] = $renderPage('system_health', []);
    $shAccess429 = str_contains($html, 'cannot open the key file meeting_tokens.key') && !str_contains($html, 'id="forget-keys-h"');
    $renderPage('system_health', [], ['forget_keys' => '1', 'forget_confirm' => 'FORGET']);
    clearstatcache();
    check($shAccess429 && (int)$db->query("SELECT totp_enabled FROM users WHERE id=$seedUid429")->fetchColumn() === 1 && is_file($keyFile429) && count(glob($keyFile429 . '.damaged-*') ?: []) === 1,
        'P14: System health says to fix the owner and offers no forgetting; a forced forget changes nothing');
}
chmod($keyFile429, 0600);
// A replaced key: only what it cannot read is forgotten; the key in use is kept.
$keyNow429 = (string)file_get_contents($keyFile429);
meeting_store($seedUid429, 'zoom', ['access_token' => 'v429-access-2', 'refresh_token' => 'v429-refresh-2', 'expires_in' => 3600], 'v429-zoom@example.invalid');
$db->prepare("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('V429-AUTH Old Seed','v429-oldseed@example.invalid','x','instructor',1,?)")
    ->execute(['v1:' . base64_encode(random_bytes(44))]);
$oldSeedUid429 = (int)$db->lastInsertId();
check(meeting_key_problem() === 'mismatch', 'P14: a key that cannot read some stored values is told apart');
$renderPage('system_health', [], ['forget_keys' => '1', 'forget_confirm' => 'FORGET']);
check((int)$db->query("SELECT totp_enabled FROM users WHERE id=$oldSeedUid429")->fetchColumn() === 0 && (int)$db->query("SELECT totp_enabled FROM users WHERE id=$seedUid429")->fetchColumn() === 1
    && (int)$db->query('SELECT COUNT(*) FROM meeting_accounts')->fetchColumn() === 1 && (string)file_get_contents($keyFile429) === $keyNow429,
    'P14: with a replaced key, only the values it cannot read are forgotten and the key is kept');
// Put everything back as it was.
foreach (glob($keyFile429 . '.damaged-*') ?: [] as $f) unlink($f);
unlink($keyFile429); rename($keyFile429 . '.v429', $keyFile429);
meeting_key(true);
$db->exec('DELETE FROM meeting_accounts'); $db->exec('DELETE FROM qbo_connection');
$db->exec("DELETE FROM users WHERE id IN ($seedUid429, $oldSeedUid429)");
$putSeed429 = $db->prepare('UPDATE users SET totp_enabled=?, totp_secret=?, totp_last_step=? WHERE id=?');
foreach ($seedsKeep429 as $r) $putSeed429->execute([$r['totp_enabled'], $r['totp_secret'], $r['totp_last_step'], $r['id']]);
foreach ([['meeting_accounts', $meetKeep429], ['qbo_connection', $qboKeep429]] as [$t, $rows]) foreach ($rows as $r) {
    $db->prepare("INSERT INTO `$t` (`" . implode('`,`', array_keys($r)) . '`) VALUES (' . implode(',', array_fill(0, count($r), '?')) . ')')->execute(array_values($r));
}
check((string)file_get_contents($keyFile429) === $origKey429, 'P14 test: the original key file is back');

// ---- Lows: staff training ticks after a refused save; credential and hold form error summaries ----
$db->exec("INSERT INTO training_modules (title, body, questions, pass_pct, active, required, counts_as_privacy) VALUES ('V429-AUTH Training', 'Reading', '? Q\n* A\n- B', 80, 1, 1, 0)");
$tm429 = (int)$db->lastInsertId();
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['old_input' => ['save_module' => '1', 'module_id' => (string)$tm429, 'title' => 'V429-AUTH Training', 'body' => 'Reading', 'questions' => "? Q\n* A\n- B", 'pass_pct' => '45', 'small_correction' => '1', 'counts_as_privacy' => '1', 'active' => '1'],
    'field_errors' => ['pass_pct_' . $tm429 => 'Enter the pass mark as a percent from 50 to 100.']]));
[$code, $html, $err] = $renderPage('staff_training', ['edit' => (string)$tm429]);
putenv('CMS_TEST_SESSION_ADD');
check(preg_match('~name="small_correction" value="1" checked~', $html) === 1 && preg_match('~name="required" value="1" checked~', $html) === 0
    && preg_match('~name="counts_as_privacy" value="1" checked~', $html) === 1 && preg_match('~name="active" value="1" checked~', $html) === 1,
    'Staff training: a refused save keeps "small correction", privacy and On ticked, and "Required" unticked' . ($err ? ": $err" : ''));
[$code, $html, $err] = $renderPage('staff_training', ['edit' => (string)$tm429]);
check(preg_match('~name="small_correction" value="1" checked~', $html) === 0 && preg_match('~name="required" value="1" checked~', $html) === 1, 'Staff training: a fresh edit form shows the saved ticks');
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['field_errors' => ['access_hold_days' => 'Enter a number of days from 1 to 90 (new staff are held at once either way).']]));
[$code, $html, $err] = $renderPage('staff_training', []);
putenv('CMS_TEST_SESSION_ADD');
check(str_contains($html, 'id="form-error-summary"') && str_contains($html, 'href="#hold_days"'), 'Staff training: the hold form error has a focused summary linked to its field' . ($err ? ": $err" : ''));
$db->exec("DELETE FROM training_modules WHERE id=$tm429");
$db->exec("INSERT INTO staff_credentials (user_id, name, expires_on) VALUES (1, 'V429-AUTH Credential', DATE_ADD(CURDATE(), INTERVAL 100 DAY))");
$cr429 = (int)$db->lastInsertId();
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['old_input' => ['credential_id' => (string)$cr429, 'expires_on' => '2026-02-31'], 'field_errors' => ['expires_on_' . $cr429 => 'Enter the expiry date as a real date, or leave it empty if it does not expire.', 'notes_' . $cr429 => 'Keep this to 255 characters or fewer.']]));
[$code, $html, $err] = $renderPage('credentials', []);
putenv('CMS_TEST_SESSION_ADD');
check(substr_count($html, 'id="form-error-summary"') === 1 && str_contains($html, 'href="#eo_' . $cr429 . '"') && str_contains($html, 'href="#no_' . $cr429 . '"'),
    'Credentials: field errors have one focused summary, in that credential\'s form, linked to each field' . ($err ? ": $err" : ''));
$db->exec("DELETE FROM staff_credentials WHERE id=$cr429");

// ---- Hints inside labels on My Account: the password-change note is not joined to the button ----
[$code, $html, $err] = $renderPage('account', []);
check(!str_contains($html, 'pw-effects') && str_contains($html, 'Changing your password'), 'My Account: the password-change note sits above the form, joined to no control' . ($err ? ": $err" : ''));
$db->prepare('UPDATE users SET role = ?, group_id = ? WHERE id = 1')->execute([$admin429['role'], $admin429['group_id']]);
