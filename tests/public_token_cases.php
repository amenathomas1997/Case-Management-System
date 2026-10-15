<?php
// Included by full_schema.php. The routes a visitor reaches without a staff
// account: the client portal and the counselor portal (by their link tokens), and
// the sign-in steps (password, emailed code, reset link). Every request runs as an
// anonymous visitor whose session holds only what that visitor would really have.
// A documentation address (192.0.2.0/24) keeps these requests' sign-in limits
// apart from every other test's, and its rows are removed at the end. Expiry
// times are days away from now, so a time-zone difference between this
// connection and the page's cannot make a valid link look expired or the reverse.
$anonIp = '192.0.2.44';
$anon = function (string $page, array $query, ?array $post = null, array $session = []) use ($root, $tmp, $anonIp, $noSecret): array {
    $source = swap_required("require __DIR__ . '/inc/bootstrap.php';", 'require ' . var_export($tmp . '/bootstrap.php', true) . ';', file_get_contents($root . '/public/' . $page . '.php'), "public/$page.php");
    file_put_contents($tmp . '/anon_' . $page . '.php', str_replace('__DIR__', var_export($root . '/public', true), $source));
    $args = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'sendmail_path=/usr/bin/true', $root . '/tests/full_schema.php', $post === null ? 'page' : 'page_post', $tmp . '/anon_' . $page . '.php', json_encode($query)];
    if ($post !== null) $args[] = json_encode($post);
    $env = array_merge(getenv(), ['CMS_TEST_ANON' => '1', 'CMS_TEST_SESSION' => json_encode($session ?: new stdClass()), 'REMOTE_ADDR' => $anonIp]);
    $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p);
    // No stored secret on a public page either; the page is kept for the browser checks.
    $noSecret("public $page.php", $out . $err);
    snapshot_html($page, $query, $post, $out);
    return [$code, $out, $err];
};
$clean = fn(int $code, string $err): bool => $code === 0 && !preg_match('/Fatal error|Warning|Notice|Uncaught|Deprecated/', $err);

/* ---- Client portal ---- */
$db->exec("INSERT INTO clients(name,status) VALUES('Portaltest Synthetic','Active Training')"); $portalClient = (int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status) VALUES('Portalother Synthetic','Active Training')"); $portalOther = (int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,archived_at) VALUES('Portalarchived Synthetic','Closed - Other',NOW())"); $portalArchived = (int)$db->lastInsertId();
$portalToken = function (int $client, ?string $expires) use ($db): string {
    $token = bin2hex(random_bytes(24));
    $db->prepare('INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,?)')->execute([$client, $token, $expires]);
    return $token;
};
$validPortal = $portalToken($portalClient, date('Y-m-d H:i:s', strtotime('+30 days')));
$expiredPortal = $portalToken($portalOther, date('Y-m-d H:i:s', strtotime('-2 days')));
$archivedPortal = $portalToken($portalArchived, null);
[$code, $out, $err] = $anon('portal', ['t' => $validPortal]);
check($clean($code, $err) && str_contains($out, 'Portaltest') && !str_contains($out, 'Link not recognized'), 'The client portal opens for a valid link, without a staff session' . ($err ? ": $err" : ''));
foreach (['an expired link' => $expiredPortal, "an archived client's link" => $archivedPortal, 'an unknown link' => str_repeat('ab', 24)] as $what => $token) {
    [$code, $out, $err] = $anon('portal', ['t' => $token]);
    check($clean($code, $err) && str_contains($out, 'Link not recognized') && !str_contains($out, 'Portalother') && !str_contains($out, 'Portalarchived'), "The client portal refuses $what and shows no client" . ($err ? ": $err" : ''));
}
// Documents: a client's own resource downloads; another client's never does, even by its id.
file_put_contents($tmp . '/portal_own.txt', 'PORTAL-OWN-DOCUMENT-BYTES'); file_put_contents($tmp . '/portal_other.txt', 'PORTAL-OTHER-DOCUMENT-BYTES');
$db->prepare("INSERT INTO client_documents(client_id,category,title,orig_name,path) VALUES(?,'Resource','Own guide','own.txt','portal_own.txt')")->execute([$portalClient]); $ownDoc = (int)$db->lastInsertId();
$db->prepare("INSERT INTO client_documents(client_id,category,title,orig_name,path) VALUES(?,'Resource','Other guide','other.txt','portal_other.txt')")->execute([$portalOther]); $otherDoc = (int)$db->lastInsertId();
[$code, $out, $err] = $anon('portal', ['t' => $validPortal, 'download' => (string)$ownDoc]);
check($clean($code, $err) && str_contains($out, 'PORTAL-OWN-DOCUMENT-BYTES'), 'A portal link downloads its own client\'s resource' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('portal', ['t' => $validPortal, 'download' => (string)$otherDoc]);
check($clean($code, $err) && !str_contains($out, 'PORTAL-OTHER-DOCUMENT-BYTES'), 'A portal link never downloads another client\'s document, even by its id' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('portal', ['t' => $expiredPortal, 'download' => (string)$otherDoc]);
check($clean($code, $err) && !str_contains($out, 'PORTAL-OTHER-DOCUMENT-BYTES'), 'An expired portal link downloads nothing' . ($err ? ": $err" : ''));
// A message from the portal lands on that client's own thread only.
$messages = fn(int $client): int => (int)$db->query("SELECT COUNT(*) FROM messages WHERE client_id=$client AND channel='client' AND sender='client'")->fetchColumn();
[$code, $out, $err] = $anon('portal', ['t' => $validPortal], ['send_message' => '1', 'message' => 'Synthetic portal message']);
check($clean($code, $err) && $messages($portalClient) === 1 && $messages($portalOther) === 0, 'A portal message is stored on the link\'s own client and no other' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('portal', ['t' => $expiredPortal], ['send_message' => '1', 'message' => 'Synthetic refused message']);
check($clean($code, $err) && $messages($portalOther) === 0, 'An expired portal link cannot post a message' . ($err ? ": $err" : ''));
// One "Ask us something" form: the topic chosen is stored as the request's type.
[$code, $out, $err] = $anon('portal', ['t' => $validPortal]);
check($clean($code, $err) && str_contains($out, 'Ask us something') && str_contains($out, 'value="appointment"') && str_contains($out, 'value="device"') && !str_contains($out, 'Request an appointment'),
    'The portal offers one request form with a topic choice' . ($err ? ": $err" : ''));
$requests = fn(int $client, string $type): int => (int)$db->query("SELECT COUNT(*) FROM portal_requests WHERE client_id=$client AND type='$type'")->fetchColumn();
[$code, $out, $err] = $anon('portal', ['t' => $validPortal], ['new_request' => '1', 'req_type' => 'appointment', 'details' => 'Synthetic appointment request']);
check($clean($code, $err) && $requests($portalClient, 'appointment') === 1, 'An appointment topic is stored as an appointment request' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('portal', ['t' => $validPortal], ['new_request' => '1', 'req_type' => 'device', 'details' => 'Synthetic device request']);
check($clean($code, $err) && $requests($portalClient, 'device') === 1, 'A device-help topic is stored as a device request' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('portal', ['t' => $validPortal], ['new_request' => '1', 'req_type' => 'other', 'details' => '']);
check($clean($code, $err) && $requests($portalClient, 'other') === 0, 'A request with no details is refused' . ($err ? ": $err" : ''));
// Practice is logged from the homework section (one place), with the same validation.
[$code, $out, $err] = $anon('portal', ['t' => $validPortal], ['log_practice' => '1', 'practice' => 'Synthetic practice', 'minutes' => '15']);
check($clean($code, $err) && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$portalClient AND subject='Practice logged by client (portal)'")->fetchColumn() === 1,
    'Practice logged from the homework section is recorded' . ($err ? ": $err" : ''));
// A client who prefers Spanish gets the portal in Spanish from the first visit.
$db->exec("UPDATE clients SET preferred_language='es' WHERE id=$portalClient");
[$code, $out, $err] = $anon('portal', ['t' => $validPortal]);
check($clean($code, $err) && str_contains($out, '<html lang="es"') && str_contains($out, 'Pregúntenos algo'), 'The portal opens in Spanish for a client who prefers Spanish' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('portal', ['t' => $validPortal, 'lang' => 'en']);
check($clean($code, $err) && str_contains($out, '<html lang="en"'), 'The language toggle still switches a Spanish-speaking client\'s portal to English' . ($err ? ": $err" : ''));
$db->exec("UPDATE clients SET preferred_language='en' WHERE id=$portalClient");

/* ---- Counselor portal ---- */
$vrcEmail = 'vrc-synthetic@example.invalid';
$db->prepare("INSERT INTO clients(name,status,counselor_email,counselor_verified) VALUES('Vrcown Synthetic','Active Training',?,1)")->execute([$vrcEmail]); $vrcOwn = (int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,counselor_email,counselor_verified) VALUES('Vrcforeign Synthetic','Active Training','other-counselor@example.invalid',1)"); $vrcForeign = (int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,counselor_email,counselor_verified,archived_at) VALUES('Vrcarchived Synthetic','Closed - Other',?,1,NOW())")->execute([$vrcEmail]);
$vrcToken = bin2hex(random_bytes(24));
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token,expires_at) VALUES(?,'Synthetic Counselor',?,?)")->execute([$vrcEmail, $vrcToken, date('Y-m-d H:i:s', strtotime('+30 days'))]); $vrcId = (int)$db->lastInsertId();
$expiredVrc = bin2hex(random_bytes(24));
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token,expires_at) VALUES('expired-counselor@example.invalid','Expired Counselor',?,?)")->execute([$expiredVrc, date('Y-m-d H:i:s', strtotime('-2 days'))]);
$verified = ['vrc_ok_' . $vrcId => true, 'vrc_ok_' . $vrcId . '_at' => time()];
[$code, $out, $err] = $anon('vrc', ['t' => $vrcToken]);
check($clean($code, $err) && str_contains($out, 'Verify it') && !str_contains($out, 'Vrcown'), 'The counselor portal asks for the emailed code before showing any client' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('vrc', ['t' => $expiredVrc], null, ['vrc_ok_' . ($vrcId + 1) => true, 'vrc_ok_' . ($vrcId + 1) . '_at' => time()]);
check($clean($code, $err) && str_contains($out, 'Link not recognized'), 'The counselor portal refuses an expired link, even in a verified browser session' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('vrc', ['t' => $vrcToken], null, $verified);
check($clean($code, $err) && str_contains($out, 'Welcome, Synthetic Counselor') && str_contains($out, 'Vrcown Synthetic') && !str_contains($out, 'Vrcforeign') && !str_contains($out, 'Vrcarchived'),
    'A verified counselor sees their own current clients only, not another counselor\'s or an archived one' . ($err ? ": $err" : ''));
$counselorMessages = fn(int $client): int => (int)$db->query("SELECT COUNT(*) FROM messages WHERE client_id=$client AND channel='counselor'")->fetchColumn();
[$code, $out, $err] = $anon('vrc', ['t' => $vrcToken], ['send_message' => '1', 'message' => 'Synthetic counselor message', 'about_client' => (string)$vrcForeign], $verified);
check($clean($code, $err) && $counselorMessages($vrcForeign) === 0 && $counselorMessages($vrcOwn) === 0, 'A counselor message naming another counselor\'s client is never filed on that client' . ($err ? ": $err" : ''));

/* ---- Sign-in: password, emailed code, reset link ---- */
$password = 'Synthetic-password-1';
$hash = password_hash($password, PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users(name,email,password_hash,role) VALUES('Token Flow User','tokenflow@example.invalid',?,'instructor')")->execute([$hash]); $flowUser = (int)$db->lastInsertId();
$openCodes = fn(): int => (int)$db->query("SELECT COUNT(*) FROM login_otps WHERE user_id=$flowUser AND consumed=0 AND channel='web'")->fetchColumn();
[$code, $out, $err] = $anon('login', [], ['email' => 'tokenflow@example.invalid', 'password' => 'Wrong-password-1']);
check($clean($code, $err) && str_contains($out, 'Incorrect email or password') && $openCodes() === 0
    && (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='login:tokenflow@example.invalid' AND success=0")->fetchColumn() === 1,
    'A wrong password is refused, counted as a failed sign-in, and issues no code' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('login', [], ['email' => 'tokenflow@example.invalid', 'password' => $password]);
check($clean($code, $err) && $openCodes() === 1, 'The right password issues exactly one emailed sign-in code' . ($err ? ": $err" : ''));
// The code itself is never readable, so the check below uses a code of known value.
$newCode = function () use ($db, $flowUser): int {
    $db->prepare("UPDATE login_otps SET consumed=1 WHERE user_id=? AND consumed=0")->execute([$flowUser]);
    $db->prepare("INSERT INTO login_otps(user_id,code_hash,expires_at,channel) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 2 DAY),'web')")->execute([$flowUser, password_hash('246810', PASSWORD_DEFAULT)]);
    return (int)$db->lastInsertId();
};
$pending = ['pending_user_id' => $flowUser, 'pending_at' => time(), 'pending_credential_version' => hash('sha256', $hash)];
$signedIn = fn(): int => (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE user_id=$flowUser AND action='login'")->fetchColumn();
$otp = $newCode();
[$code, $out, $err] = $anon('verify', [], ['code' => '246810']);
check($clean($code, $err) && $signedIn() === 0 && (int)$db->query("SELECT consumed FROM login_otps WHERE id=$otp")->fetchColumn() === 0,
    'A code posted without a password step in this session is never checked' . ($err ? ": $err" : ''));
for ($i = 1; $i <= 5; $i++) {
    [$code, $out, $err] = $anon('verify', [], ['code' => '135791'], $pending);
    check($clean($code, $err) && str_contains($out, 'not correct') && $signedIn() === 0, "Wrong code $i of 5 is refused" . ($err ? ": $err" : ''));
}
[$code, $out, $err] = $anon('verify', [], ['code' => '246810'], $pending);
check($clean($code, $err) && str_contains($out, 'Too many incorrect attempts') && $signedIn() === 0 && (int)$db->query("SELECT consumed FROM login_otps WHERE id=$otp")->fetchColumn() === 1,
    'After five wrong guesses even the right code is refused and that code is used up' . ($err ? ": $err" : ''));
$otp = $newCode();
[$code, $out, $err] = $anon('verify', [], ['code' => '246810'], $pending);
check($clean($code, $err) && $signedIn() === 1 && (int)$db->query("SELECT consumed FROM login_otps WHERE id=$otp")->fetchColumn() === 1, 'The right code completes the sign-in once' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('verify', [], ['code' => '246810'], $pending);
check($clean($code, $err) && $signedIn() === 1, 'A used code cannot sign in again' . ($err ? ": $err" : ''));
// Reset link: a mismatch changes nothing; a match changes the password once and
// ends every waiting code; the link then stops working.
$resetToken = bin2hex(random_bytes(32));
$db->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 2 DAY))')->execute([$flowUser, hash('sha256', $resetToken)]);
$expiredReset = bin2hex(random_bytes(32));
$db->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 2 DAY))')->execute([$flowUser, hash('sha256', $expiredReset)]);
$storedHash = fn(): string => (string)$db->query("SELECT password_hash FROM users WHERE id=$flowUser")->fetchColumn();
[$code, $out, $err] = $anon('reset', ['t' => $expiredReset], ['new_password' => 'Expired-link-pass-1', 'confirm_password' => 'Expired-link-pass-1']);
check($clean($code, $err) && str_contains($out, 'Link expired or invalid') && $storedHash() === $hash, 'An expired reset link changes nothing' . ($err ? ": $err" : ''));
[$code, $out, $err] = $anon('reset', ['t' => $resetToken], ['new_password' => 'Mismatch-pass-1', 'confirm_password' => 'Mismatch-pass-2']);
check($clean($code, $err) && $storedHash() === $hash, 'A reset whose confirmation does not match changes nothing' . ($err ? ": $err" : ''));
$newCode();
[$code, $out, $err] = $anon('reset', ['t' => $resetToken], ['new_password' => 'Reset-synthetic-pass-1', 'confirm_password' => 'Reset-synthetic-pass-1']);
check($clean($code, $err) && password_verify('Reset-synthetic-pass-1', $storedHash()) && $openCodes() === 0
    && (int)$db->query("SELECT used FROM password_resets WHERE token_hash=" . $db->quote(hash('sha256', $resetToken)))->fetchColumn() === 1,
    'A reset link sets the new password, ends every waiting code and is used up' . ($err ? ": $err" : ''));
$afterReset = $storedHash();
[$code, $out, $err] = $anon('reset', ['t' => $resetToken], ['new_password' => 'Second-use-pass-1', 'confirm_password' => 'Second-use-pass-1']);
check($clean($code, $err) && str_contains($out, 'Link expired or invalid') && $storedHash() === $afterReset, 'A used reset link cannot change the password again' . ($err ? ": $err" : ''));

/* ---- Confirmation, consent and survey forms behind a valid link ---- */
// Rendered as the visitor holding the link sees them (the page loop only reaches
// their "not recognized" state), so their real forms are snapshotted for the
// browser accessibility checks too.
foreach ([
    'ack' => ["INSERT INTO report_acks(client_id,track_id,token,sent_to) VALUES(1,1,?,'snapshot@example.invalid')", 'name="confirm"'],
    'consent' => ["INSERT INTO consent_forms(client_id,guardian_name,guardian_email,token) VALUES(1,'Snapshot Guardian','snapshot@example.invalid',?)", 'name="signature"'],
    'survey' => ["INSERT INTO surveys(client_id,token) VALUES(1,?)", 'name="satisfaction"'],
] as $page => [$insert, $field]) {
    $linkToken = bin2hex(random_bytes(24));
    $db->prepare($insert)->execute([$linkToken]);
    [$code, $out, $err] = $anon($page, ['t' => $linkToken]);
    check($clean($code, $err) && str_contains($out, $field) && !str_contains($out, 'not recognized'), "The $page page shows its form for a valid link" . ($err ? ": $err" : ''));
}
$db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$anonIp]);
