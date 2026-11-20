<?php
// Included by full_schema.php after v435_cases.php, against its synthetic database only:
// 4.36.0, the daily workspace and safety in the apps (plan-4360 R1-R28, the server parts R1, R2,
// R3, R5, R6, R7, R9, R10, R11, R12, R15, R17, R18, R19, R20, R22, R23, R24, R26, R27 and R28).
// Each block is one item. Every request goes through api.php's own input parsing, with its HTTP
// status, and each helper the website pages now share is checked once through its page too.
// Synthetic people and records only; every setting this file changes is put back at its end.
$GLOBALS['CURRENT_PERMS'] = array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED'] = false;
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$settings436 = $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$user1_436 = $db->query('SELECT mobile, alert_sms, alert_email, calendar_kind, calendar_sync, calendar_error, calendar_synced_at FROM users WHERE id=1')->fetch();
$db->exec("DELETE FROM login_attempts");
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['credential_version' => credential_version($db->query('SELECT * FROM users WHERE id=1')->fetch())]));
$key436 = fn(): string => 'v436-' . bin2hex(random_bytes(8));
$audits436 = fn(string $action, string $like = '%'): int => (int)$db->query('SELECT COUNT(*) FROM audit_log WHERE action=' . $db->quote($action) . ' AND detail LIKE ' . $db->quote($like))->fetchColumn();
// Any request with its HTTP status, with the caller's token ($token: 64 hex characters, '' for none).
file_put_contents($tmp . '/v436_req.php', '<?php register_shutdown_function(function () { fwrite(STDERR, "\nV436STATUS:" . (int)http_response_code()); }); $_GET = json_decode($argv[1], true); if ($argv[3] !== "") $_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . $argv[3]; $_SERVER["REQUEST_METHOD"] = $argv[2]; require ' . var_export($tmp . '/api.php', true) . ';');
$req436 = function (string $method, string $action, array $body = [], array $query = [], ?string $token = null) use ($tmp): array {
    putenv('CMS_TEST_INPUT=' . json_encode($body === [] ? new stdClass() : $body));
    $p = proc_open([PHP_BINARY, '-d', 'sendmail_path=/usr/bin/true', $tmp . '/v436_req.php', json_encode(['action' => $action] + $query), $method, $token ?? str_repeat('a', 64)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $status = preg_match('/V436STATUS:(\d+)/', $err, $m) ? (int)$m[1] : 0;
    $d = json_decode($out, true);
    if (!is_array($d)) throw new RuntimeException("$method $action: $err $out");
    if (($d['error'] ?? '') === 'Something went wrong on the server. Nothing was saved.') throw new RuntimeException("$method $action crashed: $err");
    return [$status, $d];
};
$get436 = function (string $action, array $query = []) use ($req436): array {
    [$st, $d] = $req436('GET', $action, [], $query);
    if ($st !== 200 || empty($d['ok'])) throw new RuntimeException("GET $action: $st " . json_encode($d));
    return $d;
};
$post436 = function (string $action, array $body = []) use ($req436): array {
    [$st, $d] = $req436('POST', $action, $body);
    if ($st !== 200 || empty($d['ok'])) throw new RuntimeException("POST $action: $st " . json_encode($d));
    return $d;
};
$renderFresh436 = function (string $name, array $query, ?array $post = null) use ($renderPage, $tmp): array {
    foreach (glob($tmp . '/sess_*') ?: [] as $f) @unlink($f);
    return $renderPage($name, $query, $post);
};
$pageSession436 = function () use ($tmp): string {
    $files = glob($tmp . '/sess_*') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    return $files ? (string)file_get_contents($files[0]) : '';
};
// Groups for user 1: caseload-scoped, not an administrator, and without the clients permission.
$db->exec("INSERT INTO `groups` (name, perms, own_caseload_only) VALUES ('V436 scoped', 'clients;schedule;referrals', 1)"); $gScoped436 = (int)$db->lastInsertId();
$db->exec("INSERT INTO `groups` (name, perms, own_caseload_only) VALUES ('V436 staff', 'clients;schedule;equipment;billing', 0)"); $gStaff436 = (int)$db->lastInsertId();
$db->exec("INSERT INTO `groups` (name, perms, own_caseload_only) VALUES ('V436 no clients', 'schedule', 0)"); $gNoClients436 = (int)$db->lastInsertId();
$as436 = fn(?int $g) => $db->exec('UPDATE users SET group_id=' . ($g === null ? 'NULL' : $g) . ' WHERE id=1');
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V436 Synthetic Colleague','v436-colleague@example.invalid','test','instructor')"); $u436 = (int)$db->lastInsertId();
$client436 = function (string $name, array $cols = []) use ($db, $u436): int {
    $cols = ['name' => $name] + $cols + ['status' => 'Active Training', 'assigned_to' => $u436, 'state' => 'IA'];
    $db->prepare('INSERT INTO clients (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$admins436 = count(effective_admin_user_ids());
$notes436 = fn(string $message, ?string $link = null): int => (int)$db->query('SELECT COUNT(*) FROM notifications WHERE message=' . $db->quote($message) . ($link !== null ? ' AND link=' . $db->quote($link) : ''))->fetchColumn();
$myToken436 = (int)$db->query("SELECT id FROM api_tokens WHERE token_hash=" . $db->quote(hash('sha256', str_repeat('a', 64))))->fetchColumn();

/* ---- R1: phones signed in to the app ---- */
// Reporting a phone lost from the app re-checks the password: user 1 gets a synthetic one for
// this block only (its old hash, and so the website test session, is put back after it).
$hash436 = (string)$db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();
$pw436 = 'V436-synthetic-' . bin2hex(random_bytes(6));
$db->prepare('UPDATE users SET password_hash=? WHERE id=1')->execute([password_hash($pw436, PASSWORD_DEFAULT)]);
$raw436 = [];
$tok436 = function (string $name) use ($db, &$raw436): int {
    $raw = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO api_tokens (user_id,token_hash,expires_at,device_name) VALUES (1,?,DATE_ADD(NOW(), INTERVAL 1 DAY),?)")->execute([hash('sha256', $raw), $name]);
    $id = (int)$db->lastInsertId();
    $raw436[$id] = $raw;
    return $id;
};
$t1 = $tok436('V436 Test phone');
$dv = $get436('app_devices');
$mine = current(array_filter($dv['devices'], fn($d) => $d['id'] === $myToken436));
$other = current(array_filter($dv['devices'], fn($d) => $d['id'] === $t1));
check(str_starts_with($dv['intro'], 'If a phone is lost or stolen, choose Report lost and wipe') && $mine && $other && $mine['this_phone'] === true && $other['this_phone'] === false
    && $mine['can_sign_out'] === false && $mine['can_report_lost'] === false && $other['can_sign_out'] === true && $other['can_report_lost'] === true
    && str_starts_with($other['text'], 'V436 Test phone: signed in ') && $other['live'] === true && $other['wipe_requested'] === false && is_string($other['state']),
    'R1: app_devices lists both phones; this_phone only on the caller\'s, with neither button on it');
[$st, $d] = $req436('POST', 'app_devices');
check($st === 200 && !empty($d['ok']), 'R1: app_devices also answers a POST (it changes nothing)');
$r = $post436('device_action', ['device_id' => $t1, 'lost' => 'false', 'idem_key' => $key436()]);
check($r['message'] === 'That phone is signed out of the app.' && $r['lost'] === false && !in_array($t1, array_column($r['devices'], 'id'), true)
    && !$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$t1")->fetchColumn() && $audits436('user.device_signout', "app session #$t1 (mobile)") === 1,
    'R1: device_action signs the other phone out with no password (lost "false" read as no), audited user.device_signout "app session #<id> (mobile)"');
[$st, $d] = $req436('GET', 'dashboard', [], [], $raw436[$t1]);
check($st === 401 && empty($d['wipe']), 'R1: the signed-out phone\'s next request answers 401 (sign in again), not a wipe');
$t2 = $tok436('V436 Lost phone');
$k = $key436();
[$st, $d] = $req436('POST', 'device_action', ['device_id' => (string)$t2, 'lost' => 'true']);
[$st2, $d2] = $req436('POST', 'device_action', ['device_id' => (string)$t2, 'lost' => 1, 'password' => 'not-it']);
check($st === 422 && ($d['errors']['password'] ?? '') === 'Enter your password to confirm this change. Nothing was changed.'
    && $st2 === 422 && ($d2['errors']['password'] ?? '') === 'Password incorrect — the phone was not reported lost.' && $db->query("SELECT wipe_requested_at FROM api_tokens WHERE id=$t2")->fetchColumn() === null,
    'R1: Report lost and wipe from the app needs the password: none or a wrong one is 422 on errors.password, and nothing is flagged');
$db->exec("DELETE FROM login_attempts");
$r = $post436('device_action', ['device_id' => (string)$t2, 'lost' => true, 'password' => $pw436, 'idem_key' => $k]);
check($r['lost'] === true && str_starts_with($r['message'], 'Reported lost. The next time that phone connects') && $db->query("SELECT wipe_requested_at FROM api_tokens WHERE id=$t2")->fetchColumn() !== null
    && $audits436('user.device_lost', "app session #$t2 (mobile)") === 1, 'R1: with the password, Report lost and wipe flags the phone (wipe_requested_at), audited user.device_lost "… (mobile)"');
$again = $post436('device_action', ['device_id' => $t2, 'lost' => true, 'password' => $pw436, 'idem_key' => $k]);
check(!empty($again['repeat']) && $again['message'] === $r['message'] && is_array($again['devices']) && $audits436('user.device_lost', "app session #$t2%") === 1,
    'R1: the same idem_key again answers the first reply (message and list rebuilt), with one audit entry');
[$st, $d] = $req436('POST', 'device_action', ['device_id' => $t2, 'lost' => true, 'password' => $pw436, 'idem_key' => $key436()]);
check($st === 409 && $d['error'] === 'That phone was already signed out or reported lost.', 'R1: reporting it again with a new key is 409 with the website\'s text');
[$st, $d] = $req436('GET', 'dashboard', [], [], $raw436[$t2]);
check($st === 401 && !empty($d['wipe']), 'R1: the lost phone\'s next request answers 401 wipe: true (unchanged)');
[$st, $d] = $req436('POST', 'device_action', ['device_id' => $myToken436, 'lost' => true]);
check($st === 409 && $d['error'] === 'This is the phone you are using. Use Sign out to sign out of it.' && $db->query("SELECT wipe_requested_at FROM api_tokens WHERE id=$myToken436")->fetchColumn() === null,
    'R1: the phone in use is refused (409) and its session is untouched');
$db->prepare("INSERT INTO api_tokens (user_id,token_hash,expires_at,device_name) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 DAY),'V436 colleague phone')")->execute([$u436, hash('sha256', bin2hex(random_bytes(32)))]);
$tOther = (int)$db->lastInsertId();
[$st, $d] = $req436('POST', 'device_action', ['device_id' => $tOther, 'lost' => 0]);
check($st === 409 && (int)$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$tOther AND wipe_requested_at IS NULL")->fetchColumn() === 1, 'R1: another person\'s phone is refused (409) and untouched');
[$st, $d] = $req436('POST', 'device_action', ['device_id' => 'x']);
check($st === 422 && ($d['errors']['device_id'] ?? '') === 'Choose a phone.', 'R1: a device_id that is not a whole number is 422 on errors.device_id');
[$st, $d] = $req436('GET', 'device_action', ['device_id' => $t2]);
check($st === 405, 'R1: device_action needs a POST');
$db->prepare('UPDATE users SET password_hash=? WHERE id=1')->execute([$hash436]);
$db->exec("DELETE FROM login_attempts");
$t3 = $tok436('V436 Web phone');
[$code, $out, $err] = $renderFresh436('account', []);
check($code === 0 && $err === '' && str_contains($out, '<strong>V436 Web phone</strong>: signed in ') && str_contains($out, 'Sign out<span class="visually-hidden"> V436 Web phone</span>')
    && str_contains($out, '<strong>V436 Lost phone</strong>: signed in ') && str_contains($out, 'Reported lost: wiped the next time it connects.'),
    'R1: account.php still lists the phones with their buttons (shared helpers)' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderFresh436('account', [], ['device_signout' => '1', 'device_id' => (string)$t3]);
check($code === 0 && !$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$t3")->fetchColumn() && str_contains($pageSession436(), 'That phone is signed out of the app.') && $audits436('user.device_signout', "app session #$t3") === 1,
    'R1: account.php\'s Sign out still signs the phone out, with the same flash and audit' . ($err ? ": $err" : ''));
$db->exec("DELETE FROM api_tokens WHERE id IN ($t2, $tOther)");

/* ---- R2: report a privacy incident ---- */
$pc = $client436('V436 Privacy Client');
$ch = $get436('privacy_incident_choices');
check($ch['kinds'] === PRIVACY_INCIDENT_KINDS && $ch['notice_days'] === 60 && $ch['today'] === date('Y-m-d') && in_array(['id' => $pc, 'name' => 'V436 Privacy Client'], $ch['clients'], true),
    'R2: privacy_incident_choices gives the kinds, the clients, today and the 60 days');
$before = $notes436('Privacy incident reported: Lost or stolen device');
$k = $key436();
$good = ['kind' => 'Lost or stolen device', 'discovered_on' => date('Y-m-d'), 'occurred_on' => '', 'client_id' => $pc, 'description' => 'V436 synthetic phone left on a bus.', 'info_involved' => 'Names', 'idem_key' => $k];
$r = $post436('report_privacy_incident', $good);
$row = $db->query('SELECT * FROM privacy_incidents WHERE id=' . (int)$r['id'])->fetch();
$deadline = date('Y-m-d', strtotime(date('Y-m-d') . ' +60 days'));
check($row && $row['notice_deadline'] === $deadline && $r['notice_deadline'] === $deadline && $row['occurred_on'] === null && (int)$row['reported_by'] === 1
    && (int)$db->query('SELECT COUNT(*) FROM privacy_incident_clients WHERE incident_id=' . (int)$r['id'] . " AND client_id=$pc")->fetchColumn() === 1
    && $audits436('privacy_incident.add', 'Lost or stolen device (mobile)') === 1
    && $notes436('Privacy incident reported: Lost or stolen device', 'privacy_incidents.php?id=' . $r['id']) === $admins436 && $admins436 > 0
    && $r['message'] === 'Privacy incident reported. An administrator has been told. The notice deadline is ' . fmt_date($deadline) . '.',
    'R2: a report saves the incident (deadline +60 days) and its client, audits "<kind> (mobile)", tells every administrator with the link, and says the website\'s sentence');
$again = $post436('report_privacy_incident', $good);
check(!empty($again['repeat']) && $again['id'] === $r['id'] && $again['message'] === $r['message'] && $again['notice_deadline'] === $deadline
    && (int)$db->query("SELECT COUNT(*) FROM privacy_incidents WHERE description='V436 synthetic phone left on a bus.'")->fetchColumn() === 1
    && $notes436('Privacy incident reported: Lost or stolen device') === $before + $admins436, 'R2: the same idem_key gives one row, one notification each, and the same message (rebuilt)');
$refuse = function (array $over, string $key) use ($req436, $good): bool {
    [$st, $d] = $req436('POST', 'report_privacy_incident', array_merge($good, ['idem_key' => null], $over));
    return $st === 422 && isset($d['errors'][$key]) && $d['error'] === reset($d['errors']);
};
$long = str_repeat('x', 5001);
check($refuse(['kind' => ''], 'kind') && $refuse(['discovered_on' => date('Y-m-d', strtotime('+1 day'))], 'discovered_on') && $refuse(['occurred_on' => '2026-02-30'], 'occurred_on')
    && $refuse(['description' => '  '], 'description') && $refuse(['description' => $long], 'description') && $refuse(['info_involved' => str_repeat('y', 501)], 'info_involved')
    && $refuse(['client_id' => 99999999], 'client_id'), 'R2: each refusal is 422 on its key (kind, discovered_on, occurred_on, description twice, info_involved, client_id)');
$own = $client436('V436 Privacy Own', ['assigned_to' => 1]);
$as436($gScoped436);
try {
    check($refuse(['client_id' => $pc], 'client_id') && in_array($own, array_column($get436('privacy_incident_choices')['clients'], 'id'), true)
        && !in_array($pc, array_column($get436('privacy_incident_choices')['clients'], 'id'), true), 'R2: a caseload-scoped user is refused another caseload\'s client, and the choices list only their own');
} finally { $as436(null); }
$as436($gNoClients436);
try {
    [$st, $d] = $req436('POST', 'report_privacy_incident', $good);
    [$st2] = $req436('GET', 'privacy_incident_choices');
    check($st === 403 && $st2 === 403 && empty($d['repeat']), 'R2: without the clients permission both actions are 403, a replay of the first report\'s key included');
} finally { $as436(null); }
$db->exec('RENAME TABLE privacy_incident_clients TO v436_pic_aside');
try {
    [$st, $d] = $req436('POST', 'report_privacy_incident', ['idem_key' => null] + $good);
    [$st2, $d2] = $req436('GET', 'privacy_incident_choices');
    check($st === 503 && $st2 === 503 && str_contains($d['error'], 'migration 3.0.38'), 'R2: before the privacy-incident migration both actions refuse clearly (503), saving nothing');
} finally { $db->exec('RENAME TABLE v436_pic_aside TO privacy_incident_clients'); }
[$code, $out, $err] = $renderFresh436('privacy_incidents', [], ['report_incident' => '1', 'kind' => 'Paper records', 'discovered_on' => date('Y-m-d'), 'description' => 'V436 web report', 'client_id' => (string)$pc]);
$webRow = $db->query("SELECT * FROM privacy_incidents WHERE description='V436 web report'")->fetch();
check($code === 0 && $webRow && $webRow['notice_deadline'] === $deadline && $audits436('privacy_incident.add', 'Paper records') >= 1 && str_contains($pageSession436(), 'Privacy incident reported. An administrator has been told.'),
    'R2: privacy_incidents.php still reports one (shared helper), audited with the kind only' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderFresh436('privacy_incidents', [], ['report_incident' => '1', 'kind' => 'Nope', 'discovered_on' => date('Y-m-d'), 'description' => 'V436 refused web report']);
check(!$db->query("SELECT COUNT(*) FROM privacy_incidents WHERE description='V436 refused web report'")->fetchColumn() && str_contains($pageSession436(), 'Choose what kind of incident it is.'), 'R2: the page refuses the same way');

/* ---- R3: mobile number and how alerts reach you ---- */
$acct = $get436('account');
check(array_key_exists('mobile', $acct) && is_bool($acct['alert_sms']) && is_bool($acct['sms_enabled']) && in_array($acct['alert_email'], ['now', 'daily', 'off'], true)
    && $acct['alert_email_choices'] === [['value' => 'now', 'label' => 'Urgent ones at once, and daily'], ['value' => 'daily', 'label' => 'Once a day'], ['value' => 'off', 'label' => 'Never']]
    && isset($acct['app_sessions'], $acct['passkeys']), 'R3: account has the five new keys, and keeps app_sessions and passkeys');
$r = $post436('save_alert_settings', ['alert_email' => 'daily']);
check($r['message'] === 'You will get one email a day when you have unread alerts.' && $r['alert_email'] === 'daily' && $db->query('SELECT alert_email FROM users WHERE id=1')->fetchColumn() === 'daily',
    'R3: alert_email daily is stored, with the website\'s sentence');
[$st, $d] = $req436('POST', 'save_alert_settings', ['alert_email' => 'weekly']);
check($st === 422 && isset($d['errors']['alert_email']) && $db->query('SELECT alert_email FROM users WHERE id=1')->fetchColumn() === 'daily', 'R3: weekly is refused on errors.alert_email and nothing changes');
$mob = $audits436('user.mobile', 'urgent alerts by text on');
$r = $post436('save_alert_settings', ['mobile' => '515-555-0100', 'alert_sms' => true]);
$u = $db->query('SELECT mobile, alert_sms FROM users WHERE id=1')->fetch();
check($u['mobile'] === sms_number('515-555-0100') && (int)$u['alert_sms'] === 1 && $r['alert_sms'] === true && $r['mobile'] === $u['mobile']
    && $audits436('user.mobile', 'urgent alerts by text on') === $mob + 1 && $r['message'] === 'Mobile number saved. Urgent alerts are texted to it (at most one every 15 minutes, never with client details).',
    'R3: a mobile number with texts on is stored normalised, audited "urgent alerts by text on", with the website\'s sentence');
[$st, $d] = $req436('POST', 'save_alert_settings', ['mobile' => '12']);
check($st === 422 && ($d['errors']['mobile'] ?? '') === 'Enter a mobile number such as 515-555-0100, or leave it empty.' && $db->query('SELECT mobile FROM users WHERE id=1')->fetchColumn() === $u['mobile'],
    'R3: "12" is refused on errors.mobile with the website\'s text, unchanged');
[$st, $d] = $req436('POST', 'save_alert_settings', ['mobile' => '12', 'alert_email' => 'off']);
check($st === 422 && isset($d['errors']['mobile']) && $db->query('SELECT alert_email FROM users WHERE id=1')->fetchColumn() === 'daily', 'R3: one bad part saves neither');
$r = $post436('save_alert_settings', ['mobile' => '', 'alert_sms' => true]);
$u = $db->query('SELECT mobile, alert_sms FROM users WHERE id=1')->fetch();
check($u['mobile'] === null && (int)$u['alert_sms'] === 0 && $r['message'] === 'Mobile number removed.' && $r['mobile'] === null, 'R3: an empty number removes it and turns texts off');
[$st, $d] = $req436('POST', 'save_alert_settings', []);
[$st2, $d2] = $req436('POST', 'save_alert_settings', ['mobile' => null]);
check($st === 422 && $d['error'] === 'Nothing was changed.' && $st2 === 422 && $d2['error'] === 'Nothing was changed.', 'R3: neither part (or mobile null) is 422 "Nothing was changed."');
$r = $post436('save_alert_settings', ['mobile' => '515-555-0102', 'alert_sms' => 'true']);
$r2 = $post436('save_alert_settings', ['mobile' => null, 'alert_email' => 'daily']);
$u = $db->query('SELECT mobile, alert_sms FROM users WHERE id=1')->fetch();
$r3 = $post436('save_alert_settings', ['mobile' => '515-555-0102', 'alert_sms' => 'false']);
check($r['alert_sms'] === true && $u['mobile'] === sms_number('515-555-0102') && (int)$u['alert_sms'] === 1 && $r2['mobile'] === $u['mobile'] && $r3['alert_sms'] === false,
    'R3: alert_sms "true" and "false" are read as yes and no; mobile null leaves the number as it is');
$r = $post436('save_alert_settings', ['mobile' => '515-555-0101', 'alert_email' => 'now']);
check($r['message'] === 'Mobile number saved. Urgent alerts will be emailed to you as they happen (at most one email every 15 minutes), with one email a day when you have unread alerts.' && $r['alert_sms'] === false,
    'R3: both parts together, the messages joined; texts stay off when not asked');
[$code, $out, $err] = $renderFresh436('account', [], ['save_mobile' => '1', 'mobile' => 'abc']);
check(str_contains($pageSession436(), 'Enter a mobile number such as 515-555-0100, or leave it empty.') && $db->query('SELECT mobile FROM users WHERE id=1')->fetchColumn() === sms_number('515-555-0101'),
    'R3: account.php refuses the same number on its field (shared helper)');
[$code, $out, $err] = $renderFresh436('account', [], ['save_alert_email' => '1', 'alert_email' => 'off']);
check($db->query('SELECT alert_email FROM users WHERE id=1')->fetchColumn() === 'off' && str_contains($pageSession436(), 'Alerts will no longer be emailed'), 'R3: account.php saves the alert emails with the same sentence');
[$code, $out, $err] = $renderFresh436('account', []);
check($code === 0 && str_contains($out, '<option value="off" selected>Never</option>') && str_contains($out, '<option value="now">Urgent ones at once, and daily</option>'), 'R3: account.php\'s select lists the shared choices');

/* ---- R5: calendar and online meetings ---- */
$db->exec("UPDATE users SET calendar_kind='caldav', calendar_sync=1, calendar_error='V436 synthetic refusal', calendar_synced_at=NOW() WHERE id=1");
$acct = $get436('account');
check(is_array($acct['calendar']) && $acct['calendar']['kind'] === 'caldav' && $acct['calendar']['sync_on'] === true && $acct['calendar']['error'] === 'V436 synthetic refusal'
    && str_contains($acct['calendar']['text'], 'Sync is on, last brought up to date ') && str_contains($acct['calendar']['text'], 'Problem: V436 synthetic refusal') && !isset($acct['calendar']['kind_text']),
    'R5: with sync on and an error, account.calendar.text says "Problem: …"');
check($acct['meetings'] === [] || !array_filter($acct['meetings'], fn($m) => !in_array($m['state'], ['connected', 'ended', 'not_connected'], true)), 'R5: meetings is a list of known states');
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.zoom_client_id','v436-zoom-id'),('cfg.meet.zoom_client_secret','v436-zoom-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$db->exec("DELETE FROM settings WHERE name IN ('cfg.meet.ms_client_id','cfg.meet.ms_client_secret')");
$db->exec("DELETE FROM meeting_accounts WHERE user_id=1");
$acct = $get436('account');
check($acct['meetings'] === [['provider' => 'zoom', 'name' => 'Zoom', 'state' => 'not_connected', 'account_email' => null, 'text' => 'Zoom: not connected.']], 'R5: only a provider with an app set up is listed: Zoom, not connected');
$db->exec("INSERT INTO meeting_accounts (user_id, provider, account_email, access_token, refresh_token, expires_at, refused_at) VALUES (1,'zoom','v436@example.invalid','x','x',NOW(),'2026-11-01 10:00:00')");
$acct = $get436('account');
$zoomText = 'Zoom: the connection as v436@example.invalid ended on ' . fmt_date('2026-11-01') . ', so no links are made.';
check($acct['meetings'][0]['state'] === 'ended' && $acct['meetings'][0]['text'] === $zoomText, 'R5: a refused Zoom connection is "ended", in the website\'s sentence');
[$code, $out, $err] = $renderFresh436('account', []);
check($code === 0 && $err === '' && str_contains($out, h($zoomText)) && str_contains($out, 'Problem: V436 synthetic refusal') && str_contains($out, 'Sync is on, last brought up to date '),
    'R5: account.php shows the same sentences (shared helper)' . ($err ? ": $err" : ''));
$db->exec("DELETE FROM meeting_accounts WHERE user_id=1");
$db->exec("DELETE FROM settings WHERE name IN ('cfg.meet.zoom_client_id','cfg.meet.zoom_client_secret')");
check($get436('account')['meetings'] === [], 'R5: no meeting app set up: meetings is []');
$db->exec("UPDATE users SET calendar_kind=NULL, calendar_sync=0, calendar_error=NULL WHERE id=1");
check($get436('account')['calendar']['text'] === 'No calendar is connected yet.', 'R5: no calendar: "No calendar is connected yet." (calendar_available() is always true on this version, so calendar is never null here)');

/* ---- R6: my availability ---- */
$db->exec("DELETE FROM staff_availability WHERE user_id IN (1, $u436)");
$av = $get436('availability');
check($av['hours'] === [] && count($av['days']) === 7 && $av['days'][0] === ['value' => '1', 'label' => 'Monday'] && str_starts_with($av['intro'], 'The hours you take sessions.') && is_bool($av['self_booking_on']),
    'R6: availability gives the intro, no hours yet, and the seven days Monday first');
$k = $key436();
$r = $post436('add_availability', ['weekday' => '1', 'from' => '09:00', 'to' => '12:00', 'mode' => 'online', 'location' => '', 'user' => $u436, 'idem_key' => $k]);
check($r['message'] === 'Hours added.' && count($r['hours']) === 1 && $r['hours'][0]['from'] === '09:00' && $r['hours'][0]['to'] === '12:00' && $r['hours'][0]['weekday'] === 1 && $r['hours'][0]['remote'] === true
    && $r['hours'][0]['text'] === 'Mondays 9:00 AM to 12:00 PM, online' && $audits436('availability.add', '1 09:00-12:00') >= 1
    && !$db->query("SELECT COUNT(*) FROM staff_availability WHERE user_id=$u436")->fetchColumn(), 'R6: add_availability adds the caller\'s own hours (a user input is ignored), audited');
$again = $post436('add_availability', ['weekday' => '1', 'from' => '09:00', 'to' => '12:00', 'mode' => 'online', 'idem_key' => $k]);
check(!empty($again['repeat']) && count($again['hours']) === 1 && (int)$db->query('SELECT COUNT(*) FROM staff_availability WHERE user_id=1')->fetchColumn() === 1, 'R6: the same idem_key adds one row and answers the list again');
[$st, $d] = $req436('POST', 'add_availability', ['weekday' => '1', 'from' => '10:00', 'to' => '11:00', 'mode' => 'online']);
check($st === 422 && str_contains($d['errors']['from'] ?? '', 'overlap'), 'R6: overlapping hours are 422 on errors.from');
[$st, $d] = $req436('POST', 'add_availability', ['weekday' => '2', 'from' => '10:00', 'to' => '11:00', 'mode' => 'in_person', 'location' => '']);
[$st2, $d2] = $req436('POST', 'add_availability', ['weekday' => '2', 'from' => '10:00', 'to' => '09:00', 'mode' => 'online']);
[$st3, $d3] = $req436('POST', 'add_availability', ['weekday' => '9', 'from' => '10:00', 'to' => '11:00', 'mode' => 'online']);
check($st === 422 && isset($d['errors']['location']) && $st2 === 422 && isset($d2['errors']['to']) && $st3 === 422 && isset($d3['errors']['weekday']), 'R6: no place in person, an end before the start and a bad day are refused on their fields');
$hid = $r['hours'][0]['id'];
$db->prepare("INSERT INTO staff_availability (user_id, weekday, start_time, end_time, is_remote) VALUES (?, 3, '09:00:00', '10:00:00', 1)")->execute([$u436]); $hOther = (int)$db->lastInsertId();
[$st, $d] = $req436('POST', 'remove_availability', ['id' => $hOther]);
check($st === 409 && (int)$db->query("SELECT COUNT(*) FROM staff_availability WHERE id=$hOther")->fetchColumn() === 1, 'R6: another person\'s hours are refused (409) and kept');
$r = $post436('remove_availability', ['id' => $hid]);
check($r['message'] === 'Hours removed. Sessions already booked in them stay booked.' && $r['hours'] === [] && $audits436('availability.remove', "#$hid") === 1, 'R6: remove_availability removes them, audited');
[$st, $d] = $req436('POST', 'remove_availability', ['id' => $hid]);
check($st === 409 && $d['error'] === 'Nothing was removed: those hours were already removed.', 'R6: removing them again is 409');
[$code, $out, $err] = $renderFresh436('availability', [], ['add_hours' => '1', 'weekday' => '4', 'from' => '13:00', 'to' => '15:00', 'mode' => 'in_person', 'location' => 'V436 office']);
$webH = $db->query("SELECT id FROM staff_availability WHERE user_id=1 AND weekday=4")->fetchColumn();
check($webH !== false && $audits436('availability.add', '4 13:00-15:00') === 1 && str_contains($pageSession436(), 'Hours added.'), 'R6: availability.php adds the same way (shared helper)' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderFresh436('availability', [], ['remove_hours' => (string)$webH]);
check(!$db->query("SELECT COUNT(*) FROM staff_availability WHERE id=" . (int)$webH)->fetchColumn() && $audits436('availability.remove', '#' . (int)$webH) === 1, 'R6: availability.php removes the same way');
[$code, $out, $err] = $renderFresh436('availability', []);
check($code === 0 && str_contains($out, '<p>' . $av['intro'] . '</p>'), 'R6: the page shows the same intro');
$db->exec("DELETE FROM staff_availability WHERE user_id=$u436");

/* ---- R7: sign-in options, without a token ---- */
[$st, $d] = $req436('GET', 'sign_in_options', [], [], '');
check($st === 200 && $d === ['ok' => true, 'microsoft' => false], 'R7: sign_in_options without a token answers {ok, microsoft: false} and nothing else');
$db->exec("INSERT INTO settings(name,value) VALUES ('entra_enabled','1'),('entra_tenant_id','v436-tenant'),('entra_client_id','v436-client') ON DUPLICATE KEY UPDATE value=VALUES(value)");
[$st, $d] = $req436('GET', 'sign_in_options', [], [], '');
check($st === 200 && $d === ['ok' => true, 'microsoft' => true], 'R7: with Microsoft sign-in set up, microsoft is true (no setting is sent)');
$db->exec("DELETE FROM settings WHERE name IN ('entra_enabled','entra_tenant_id','entra_client_id')");
[$st] = $req436('GET', 'dashboard', [], [], '');
check($st === 401, 'R7: every other action still needs a token');

/* ---- R9, R10, R12: dashboard Today, My tasks, Your week, expiring client ---- */
$as436($gScoped436);
try {
    $base = $get436('dashboard');
    $kinds = fn(array $d): array => array_column($d['today'], 'count', 'kind');
    $b = $kinds($base);
    $dc = $client436('V436 Dash Client', ['assigned_to' => 1]);
    $db->prepare("INSERT INTO tasks (title, client_id, assigned_to, due_date, status) VALUES ('V436 overdue task', ?, 1, DATE_SUB(CURDATE(), INTERVAL 3000 DAY), 'Open'), ('V436 today task', NULL, 1, CURDATE(), 'Open')")->execute([$dc]);
    $db->prepare("INSERT INTO scheduled_sessions (client_id, user_id, start_at, end_at) VALUES (?, 1, CONCAT(CURDATE(), ' 00:00:01'), CONCAT(CURDATE(), ' 00:00:02'))")->execute([$dc]); $dSess = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO messages (client_id, channel, sender, body) VALUES (?, 'client', 'client', 'V436 unread from the client')")->execute([$dc]);
    $db->exec("INSERT INTO equipment (name) VALUES ('V436 dash device')"); $dEq = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO equipment_loans (equipment_id, client_id, loaned_at) VALUES (?, ?, CURDATE())")->execute([$dEq, $dc]); $dLoan = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO equipment_followups (loan_id, client_id, equipment_id, interval_days, due_date, status) VALUES (?, ?, ?, 30, CURDATE(), 'Pending')")->execute([$dLoan, $dc, $dEq]);
    $db->prepare("INSERT INTO portal_requests (client_id, type, status) VALUES (?, 'question', 'New')")->execute([$dc]);
    // Another caseload's client: never counted for the scoped user.
    $farC = $client436('V436 Far Client');
    $db->prepare("INSERT INTO messages (client_id, channel, sender, body) VALUES (?, 'client', 'client', 'V436 far unread')")->execute([$farC]);
    $dash = $get436('dashboard');
    $a = $kinds($dash);
    $order = ['tasks_overdue', 'tasks_today', 'sessions', 'messages', 'followups', 'portal_requests'];
    $sessItem = current(array_filter($dash['today'], fn($i) => $i['kind'] === 'sessions'));
    check(array_keys($a) === $order && $a['tasks_overdue'] === ($b['tasks_overdue'] ?? 0) + 1 && $a['tasks_today'] === ($b['tasks_today'] ?? 0) + 1 && $a['messages'] === ($b['messages'] ?? 0) + 1
        && $a['followups'] === ($b['followups'] ?? 0) + 1 && $a['portal_requests'] === ($b['portal_requests'] ?? 0) + 1
        && in_array(['id' => $dSess, 'text' => '12:00 AM — V436 Dash Client, ended, awaiting an outcome'], $sessItem['sessions'], true)
        && $dash['today_heading'] === 'Today (6 items)' && !isset($dash['today'][0]['href']),
        'R9: Today has the six kinds in the website\'s order, the session sub-line in its words, counted for the scoped user\'s caseload only');
    $mt = current(array_filter($dash['tasks']['items'], fn($t) => $t['title'] === 'V436 overdue task'));
    check($mt && $mt['client_name'] === 'V436 Dash Client' && $mt['client_id'] === $dc && $mt['days_left'] === -3000 && $mt['line'] === 'V436 overdue task — V436 Dash Client 3000 days overdue'
        && is_int($dash['tasks']['total']) && $dash['tasks']['overdue'] >= 1 && count($dash['tasks']['items']) <= 6
        && str_starts_with($dash['tasks']['heading'], $dash['tasks']['team'] ? 'Open tasks, yours and your team\'s (' : 'My tasks ('),
        'R9: My tasks rows carry the line the website shows, the totals and the heading');
    [$code, $out, $err] = $renderFresh436('index', []);
    $pageOk = $code === 0 && $err === '' && str_contains($out, '>' . h($dash['today_heading']) . '<') && str_contains($out, '<h2 id="tasks-heading">' . $dash['tasks']['heading'] . '</h2>');
    foreach ($dash['today'] as $i) $pageOk = $pageOk && str_contains($out, '>' . h($i['text']) . '</a>');
    check($pageOk, 'R9: index.php shows the same items and headings (shared helper)' . ($err ? ": $err" : ''));
    check($dash['week_text'] === $get436('my_week')['summary'] && $dash['week_text'] !== '', 'R10: dashboard.week_text equals my_week\'s summary');
} finally { $as436(null); }
$as436($gNoClients436);
try {
    $nc = $get436('dashboard');
    check(!array_intersect(array_column($nc['today'], 'kind'), ['messages', 'followups', 'portal_requests'])
        && !array_filter($nc['tasks']['items'], fn($t) => $t['client_name'] !== null || $t['client_id'] !== null), 'R9: without clients no client items, and no task carries a client name');
} finally { $as436(null); }
$as436($gStaff436);
$db->exec("UPDATE `groups` SET perms='clients' WHERE id=$gStaff436");
try {
    check(!in_array('sessions', array_column($get436('dashboard')['today'], 'kind'), true), 'R9: without schedule no sessions item');
} finally { $as436(null); $db->exec("UPDATE `groups` SET perms='clients;schedule;equipment;billing' WHERE id=$gStaff436"); }
$db->prepare("INSERT INTO tasks (title, assigned_to, status) VALUES ('V436 colleague task', ?, 'Open')")->execute([$u436]);
$adm = $get436('dashboard');
check($adm['tasks']['total'] >= 3 && ($adm['tasks']['team'] === true) === str_starts_with($adm['tasks']['heading'], 'Open tasks, yours and your team\'s'), 'R9: an administrator\'s totals cover the team, and team matches the heading');
$exTrack = (int)$db->query("SELECT MAX(id) FROM training_tracks")->fetchColumn() + 1;
$db->prepare("INSERT INTO training_tracks (client_id, title, auth_number, funding_agency, status, expiration_date, authorized_hours) VALUES (?, 'V436 expiring track', 'V436-A', 'State VR', 'Active', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 10)")->execute([$dc]);
$exp = current(array_filter($get436('dashboard')['expiring'], fn($e) => $e['title'] === 'V436 expiring track'));
check($exp && $exp['client_id'] === $dc, 'R12: dashboard.expiring rows carry client_id (a whole number)');

/* ---- R11: agreements, training and credentials banners ---- */
$db->exec("INSERT INTO settings(name,value) VALUES ('access_hold','off') ON DUPLICATE KEY UPDATE value='off'");
$d0 = $get436('dashboard');
check(is_int($d0['agreements_needed']) && is_int($d0['training_needed']) && is_array($d0['credentials_due']), 'R11: dashboard always sends agreements_needed, training_needed and credentials_due');
$db->exec("INSERT INTO staff_agreements (title, body, version, active, kind) VALUES ('V436 synthetic agreement', 'Synthetic text.', 1, 1, 'staff')"); $ag436 = (int)$db->lastInsertId();
$db->exec("INSERT INTO training_modules (title, body, questions, version, active, required) VALUES ('V436 synthetic training', 'Synthetic reading.', '', 1, 1, 1)"); $tm436 = (int)$db->lastInsertId();
$db->exec("INSERT INTO staff_credentials (user_id, name, expires_on) VALUES (1, 'V436 Synthetic licence', DATE_ADD(CURDATE(), INTERVAL 10 DAY))"); $cr436 = (int)$db->lastInsertId();
$d1 = $get436('dashboard');
check($d1['agreements_needed'] === $d0['agreements_needed'] + 1 && $d1['training_needed'] === $d0['training_needed'] + 1
    && in_array(['name' => 'V436 Synthetic licence', 'words' => 'expires ' . fmt_date(date('Y-m-d', strtotime('+10 days')))], $d1['credentials_due'], true),
    'R11: a needed agreement, a needed training and an expiring credential are counted, the credential with the website\'s words');
$db->exec("DELETE FROM staff_agreements WHERE id=$ag436"); $db->exec("DELETE FROM training_modules WHERE id=$tm436"); $db->exec("DELETE FROM staff_credentials WHERE id=$cr436");
$d2 = $get436('dashboard');
check($d2['agreements_needed'] === $d0['agreements_needed'] && $d2['training_needed'] === $d0['training_needed'] && $d2['credentials_due'] === $d0['credentials_due'], 'R11: removed again, the counts go back');

/* ---- R15: delete a task, complete several ---- */
$db->exec("INSERT INTO tasks (title, assigned_to, status) VALUES ('V436 delete me', 1, 'Open')"); $tDel = (int)$db->lastInsertId();
check($get436('tasks')['can_delete'] === true, 'R15: tasks says can_delete for an administrator');
$as436($gStaff436);
try {
    [$st, $d] = $req436('POST', 'delete_task', ['id' => $tDel]);
    check($st === 403 && $d['error'] === 'Only an administrator can delete a task.' && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE id=$tDel")->fetchColumn() === 1 && $get436('tasks')['can_delete'] === false,
        'R15: a non-administrator is refused (403), the task stays, and can_delete is false');
} finally { $as436(null); }
$kDel = $key436();
$r = $post436('delete_task', ['id' => $tDel, 'idem_key' => $kDel]);
$rAgain = $post436('delete_task', ['id' => $tDel, 'idem_key' => $kDel]);
check(!empty($rAgain['repeat']) && $rAgain['message'] === 'Task deleted.', 'R15: a delete_task retry with the same idem_key answers "Task deleted." again, not 404');
check($r['message'] === 'Task deleted.' && !$db->query("SELECT COUNT(*) FROM tasks WHERE id=$tDel")->fetchColumn() && $audits436('task.delete', 'V436 delete me') === 1, 'R15: delete_task removes it, audited task.delete with the title');
[$st, $d] = $req436('POST', 'delete_task', ['id' => $tDel]);
check($st === 404 && $d['error'] === 'That task no longer exists. It may already have been deleted.', 'R15: deleting it again is 404 with the website\'s text');
$db->exec("INSERT INTO tasks (title, assigned_to, status) VALUES ('V436 bulk a', 1, 'Open'), ('V436 bulk b', 1, 'Open'), ('V436 bulk c', 1, 'Done')");
$ta = (int)$db->query("SELECT id FROM tasks WHERE title='V436 bulk a'")->fetchColumn(); $tb = $ta + 1; $tc = $ta + 2;
$db->prepare("INSERT INTO tasks (title, assigned_to, status) VALUES ('V436 hidden task', ?, 'Open')")->execute([$u436]); $tHidden = (int)$db->lastInsertId();
$k = $key436();
$as436($gStaff436);
try {
    $r = $post436('complete_tasks', ['task_ids' => [$ta, $tb, $tc, $tHidden], 'idem_key' => $k]);
    check($r['completed'] === 2 && $r['message'] === '2 tasks completed.' && $db->query("SELECT status FROM tasks WHERE id=$tHidden")->fetchColumn() === 'Open'
        && $db->query("SELECT status FROM tasks WHERE id=$tb")->fetchColumn() === 'Done', 'R15: complete_tasks completes the two open ones the user can see; a colleague\'s task stays open');
    $again = $post436('complete_tasks', ['task_ids' => [$ta, $tb], 'idem_key' => $k]);
    check(!empty($again['repeat']) && $again['completed'] === 2 && $again['message'] === '2 tasks completed.', 'R15: the same idem_key answers the first reply');
    [$st, $d] = $req436('POST', 'complete_tasks', ['task_ids' => []]);
    check($st === 422 && $d['error'] === 'Nothing was completed: choose at least one open task.', 'R15: nothing to complete is 422 with the website\'s text');
    [$st, $d] = $req436('POST', 'complete_tasks', ['task_ids' => "$ta,$tb"]);
    check($st === 422, 'R15: the comma-separated form is read too (both already done: 422)');
} finally { $as436(null); }
$db->exec("INSERT INTO tasks (title, assigned_to, status) SELECT CONCAT('V436 many ', seq), 1, 'Open' FROM (SELECT @n := @n + 1 AS seq FROM information_schema.columns, (SELECT @n := 0) z LIMIT 201) s");
$many = array_map('intval', $db->query("SELECT id FROM tasks WHERE title LIKE 'V436 many %' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$r = $post436('complete_tasks', ['task_ids' => $many]);
check(count($many) === 201 && $r['completed'] === 200 && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE title LIKE 'V436 many %' AND status='Open'")->fetchColumn() === 1, 'R15: 201 ids: at most 200 are tried');
$db->exec("INSERT INTO tasks (title, assigned_to, status) VALUES ('V436 web bulk a', 1, 'Open'), ('V436 web bulk b', 1, 'Open'), ('V436 web delete', 1, 'Open')");
$wa = (int)$db->query("SELECT id FROM tasks WHERE title='V436 web bulk a'")->fetchColumn();
[$code, $out, $err] = $renderFresh436('tasks', [], ['bulk_done' => '1', 'bulk_ids' => [(string)$wa, (string)($wa + 1)]]);
check($db->query("SELECT COUNT(*) FROM tasks WHERE id IN ($wa, " . ($wa + 1) . ") AND status='Done'")->fetchColumn() == 2 && str_contains($pageSession436(), '2 tasks completed.'), 'R15: tasks.php completes several the same way (shared helper)' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderFresh436('tasks', [], ['delete_task' => '1', 'task_id' => (string)($wa + 2)]);
check(!$db->query('SELECT COUNT(*) FROM tasks WHERE id=' . ($wa + 2))->fetchColumn() && $audits436('task.delete', 'V436 web delete') === 1, 'R15: tasks.php deletes the same way' . ($err ? ": $err" : ''));

/* ---- R17: undo "mark as read" ---- */
$db->exec("DELETE FROM notifications WHERE user_id=1 AND read_at IS NULL");
$db->exec("INSERT INTO notifications (user_id, message) VALUES (1, 'V436 alert a'), (1, 'V436 alert b')");
$na = (int)$db->query("SELECT id FROM notifications WHERE message='V436 alert a'")->fetchColumn(); $nb = $na + 1;
$db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, 'V436 colleague alert')")->execute([$u436]); $nOther = (int)$db->lastInsertId();
$r = $post436('mark_notifications_read', ['ids' => "$na,$nb"]);
check($r['marked'] === 2 && $r['message'] === '2 alerts marked as read.' && $r['undo']['ids'] === "$na,$nb" && $r['undo']['count'] === 2 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $r['undo']['stamp'])
    && $db->query("SELECT read_at FROM notifications WHERE id=$na")->fetchColumn() === $r['undo']['stamp'], 'R17: mark_notifications_read hands back undo {ids, stamp, count}, one stamp for the batch');
$db->exec("UPDATE notifications SET read_at=" . $db->quote($r['undo']['stamp']) . " WHERE id=$nOther");
$u = $post436('mark_notifications_unread', ['ids' => "$na,$nb,$nOther", 'stamp' => $r['undo']['stamp']]);
check($u['restored'] === 2 && $u['message'] === '2 alerts are unread again.' && $u['unread'] >= 2 && $db->query("SELECT read_at FROM notifications WHERE id=$nOther")->fetchColumn() !== null,
    'R17: mark_notifications_unread restores that batch only, never another person\'s alert');
[$st, $d] = $req436('POST', 'mark_notifications_unread', ['ids' => "$na,$nb", 'stamp' => $r['undo']['stamp']]);
check($st === 409 && $d['error'] === 'There was nothing to undo: alerts can be marked unread again for 15 minutes after marking them read.', 'R17: again: 409 nothing to undo');
$r = $post436('mark_notifications_read', ['all' => true]);
$old = date('Y-m-d H:i:s', time() - 16 * 60);
$db->exec("UPDATE notifications SET read_at=" . $db->quote($old) . " WHERE id IN ($na, $nb)");
[$st, $d] = $req436('POST', 'mark_notifications_unread', ['ids' => "$na,$nb", 'stamp' => $old]);
check(isset($r['undo']) && $r['undo']['ids'] === '' && $st === 409 && $db->query("SELECT read_at FROM notifications WHERE id=$na")->fetchColumn() !== null,
    'R17: "all" gets an undo too; a batch older than 15 minutes is 409 and stays read');
[$st, $d] = $req436('POST', 'mark_notifications_read', []);
check($st === 422 && !isset($d['undo']), 'R17: nothing chosen is still 422');
$db->exec("UPDATE notifications SET read_at=NOW() WHERE user_id=1 AND read_at IS NULL");
$db->exec("INSERT INTO notifications (user_id, message) SELECT 1, CONCAT('V436 many alert ', seq) FROM (SELECT @m := @m + 1 AS seq FROM information_schema.columns, (SELECT @m := 0) z LIMIT 150) s");
$all = $post436('mark_notifications_read', ['all' => 'true']);
$un = $post436('mark_notifications_unread', ['ids' => $all['undo']['ids'], 'stamp' => $all['undo']['stamp']]);
check($all['marked'] === 150 && $all['undo']['ids'] === '' && $all['undo']['all'] === true && $all['undo']['count'] === 150 && $un['restored'] === 150
    && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND read_at IS NULL")->fetchColumn() === 150,
    'R17: "all" over 150 alerts marks them in one batch (undo ids "") and Undo puts back all 150, not 100');
$db->exec("DELETE FROM notifications WHERE message LIKE 'V436 many alert %'");
$db->exec("UPDATE notifications SET read_at=NULL WHERE id IN ($na, $nb)");
[$code, $out, $err] = $renderFresh436('notifications', [], ['mark_read' => '1', 'ids' => "$na,$nb"]);
$sess = $pageSession436();
check($db->query("SELECT COUNT(*) FROM notifications WHERE id IN ($na,$nb) AND read_at IS NOT NULL")->fetchColumn() == 2 && str_contains($sess, 'alerts_undo') && str_contains($sess, '2 alerts marked as read.'),
    'R17: notifications.php still marks read and keeps its undo (shared helper)' . ($err ? ": $err" : ''));

/* ---- R18 and R19: conversations and the thread ---- */
$m1 = $client436('V436 Msg Older'); $m2 = $client436('V436 Msg Unread');
$db->prepare("INSERT INTO messages (client_id, channel, sender, staff_id, body, created_at, read_at) VALUES (?, 'client', 'staff', 1, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR), NULL)")->execute([$m1, 'V436 my own reply that is well over sixty characters long, so it is cut short']);
$db->prepare("INSERT INTO messages (client_id, channel, sender, body, created_at) VALUES (?, 'counselor', 'counselor', 'V436 counselor asks', DATE_ADD(NOW(), INTERVAL 1 HOUR))")->execute([$m2]);
$th = $get436('message_threads');
$pos = array_flip(array_column($th['threads'], 'client_id'));
$t1r = $th['threads'][$pos[$m1]]; $t2r = $th['threads'][$pos[$m2]];
check($pos[$m2] < $pos[$m1] && $t2r['last_sender_label'] === '' && $t2r['last_body'] === 'V436 counselor asks' && $t2r['last_channel'] === 'counselor' && $t2r['unread_counselor'] === 1
    && $t1r['last_sender_label'] === 'You: ' && $t1r['last_body'] === mb_strimwidth('V436 my own reply that is well over sixty characters long, so it is cut short', 0, 60, '…')
    && is_int($th['total']) && $th['total'] >= 2 && $th['unread_total'] >= 1 && isset($t1r['client_name'], $t1r['last_at'], $t1r['total']),
    'R18: unread first, then newest; the last message cut at 60 with "You: " or nothing, the channel, and the totals');
$db->prepare("INSERT INTO messages (client_id, channel, sender, staff_id, body, created_at) VALUES (?, 'client', 'staff', ?, 'V436 colleague wrote', DATE_ADD(NOW(), INTERVAL 3 HOUR))")->execute([$m1, $u436]);
$t1r = current(array_filter($get436('message_threads')['threads'], fn($t) => $t['client_id'] === $m1));
check($t1r['last_sender_label'] === 'V436 Synthetic Colleague: ', 'R18: a colleague\'s message is labelled with their name');
[$code, $out, $err] = $renderFresh436('messages', []);
check($code === 0 && $err === '' && str_contains($out, h('V436 Synthetic Colleague: V436 colleague wrote')) && str_contains($out, 'V436 counselor asks') && str_contains($out, $th['unread_total'] . ' unread.'),
    'R18: messages.php shows the same rows by channel (shared helper)' . ($err ? ": $err" : ''));
$mc = $client436('V436 Msg Counselor', ['counselor_email' => 'v436-counselor@example.invalid', 'counselor_verified' => 0]);
$thr = $get436('message_thread', ['client_id' => $mc, 'channel' => 'counselor']);
check($thr['counselor_unconfirmed'] === true && $thr['counselor_unconfirmed_text'] === 'Not yet visible to the counselor: V436 Msg Counselor\'s counselor email has not been confirmed, so the counselor portal does not show this client. Replies are saved here and appear there once the counselor is confirmed on the client\'s record.',
    'R19: message_thread says the counselor email is unconfirmed, with the text (no link)');
check($get436('message_thread', ['client_id' => $m1, 'channel' => 'client'])['counselor_unconfirmed'] === false, 'R19: a client with no counselor email: false');
[$code, $out, $err] = $renderFresh436('messages', ['client' => $mc, 'channel' => 'counselor']);
check($code === 0 && str_contains($out, '<strong>Not yet visible to the counselor:</strong>') && str_contains($out, '<a href="client_edit.php?id=' . $mc . '">the client\'s edit page</a>.'), 'R19: messages.php keeps its link to the edit page (shared text)');
$mp = $client436('V436 Msg Portal', ['email' => 'v436-portal@example.invalid', 'email_verified' => 1]);
$db->prepare("INSERT INTO portal_tokens (client_id, token) VALUES (?, ?)")->execute([$mp, bin2hex(random_bytes(24))]);
$s1 = $post436('post_message', ['client_id' => $mp, 'channel' => 'client', 'body' => 'V436 first']);
$s2 = $post436('post_message', ['client_id' => $mp, 'channel' => 'client', 'body' => 'V436 second']);
check($s1['noticed'] === true && $s2['noticed'] === false && $s1['delivered'] === true, 'R19: the first send emails the notice (noticed true); the second the same day does not');

/* ---- R20: device follow-ups ---- */
$fc = $client436('V436 Followup Client');
$db->exec("INSERT INTO equipment (name) VALUES ('V436 follow device')"); $fEq = (int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_loans (equipment_id, client_id, loaned_at) VALUES (?, ?, CURDATE())")->execute([$fEq, $fc]); $fLoan = (int)$db->lastInsertId();
$ins = $db->prepare("INSERT INTO equipment_followups (loan_id, client_id, equipment_id, interval_days, due_date, status) VALUES (?, ?, ?, 30, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Pending')");
for ($i = 0; $i < 301; $i++) $ins->execute([$fLoan, $fc, $fEq]);
$db->prepare("INSERT INTO equipment_followups (loan_id, client_id, equipment_id, interval_days, due_date, status, notes, completed_at) VALUES (?, ?, ?, 90, CURDATE(), 'Skipped', 'Device returned', DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([$fLoan, $fc, $fEq]);
$db->prepare("INSERT INTO equipment_followups (loan_id, client_id, equipment_id, interval_days, due_date, status, in_use, satisfaction, client_reported, completed_at) VALUES (?, ?, ?, 60, CURDATE(), 'Completed', 1, 4, 1, DATE_ADD(NOW(), INTERVAL 2 HOUR))")->execute([$fLoan, $fc, $fEq]);
$fu = $get436('followups');
$byOutcome = array_column($fu['recent'], 'outcome', 'interval_days');
check($fu['due_total'] >= 301 && count($fu['due']) === 300 && is_int($fu['upcoming_total']) && $fu['recent'][0]['outcome'] === 'skipped — device returned'
    && $fu['recent'][1]['outcome'] === 'In use · 4/5 · reported by the client' && $fu['recent'][1]['client_reported'] === true && $fu['due'][0]['client_reported'] === false,
    'R20: due_total counts past the 300 listed; recent rows have the website\'s outcome words and client_reported');
[$code, $out, $err] = $renderFresh436('followups', []);
check($code === 0 && $err === '' && str_contains($out, '<span class="muted">skipped — device returned</span>') && str_contains($out, 'In use · 4/5 · reported by the client')
    && preg_match('~<span class="stat-num">' . $fu['due_total'] . '</span><span class="stat-label">Due now</span>~', $out), 'R20: followups.php shows the same words and totals (shared helper)' . ($err ? ": $err" : ''));

/* ---- R22 and R23: compliance ---- */
$cmp = function (array $cols = []) use ($client436): int { $name = $cols['name']; unset($cols['name']); return $client436($name, $cols + ['assigned_to' => 1, 'privacy_notice_at' => date('Y-m-d H:i:s'), 'dob' => '1980-01-01']); };
$cA = $cmp(['name' => 'V436 Cmp Expired']);
$db->prepare("INSERT INTO training_tracks (client_id, title, auth_number, funding_agency, status, expiration_date, authorized_hours, hourly_rate, report_cadence, start_date, created_at) VALUES (?, 'V436 cmp track', 'V436-C', 'State VR', 'Active', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 10, 50, 'monthly', '2025-01-01', '2025-01-01')")->execute([$cA]); $cT = (int)$db->lastInsertId();
$db->prepare("INSERT INTO client_releases (client_id, signer_name, recipient_type, recipient_name, info_scope, purpose, signed_at, expires_on, withdrawn_at) VALUES (?, 'V436 Cmp Expired', 'Employer', 'V436 Employer', 'All', 'Work', DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_ADD(CURDATE(), INTERVAL 300 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))")->execute([$cA]);
$db->prepare("INSERT INTO consent_forms (client_id, guardian_name, guardian_email, token, sent_at, signed_at) VALUES (?, 'V436 Guardian', 'v436-guardian@example.invalid', ?, DATE_SUB(NOW(), INTERVAL 400 DAY), DATE_SUB(NOW(), INTERVAL 400 DAY))")->execute([$cA, bin2hex(random_bytes(16))]);
$cNoRel = $cmp(['name' => 'V436 Cmp No Release']);
$cMinor = $cmp(['name' => 'V436 Cmp Minor', 'dob' => date('Y-m-d', strtotime('-16 years'))]);
$cNoNotice = $cmp(['name' => 'V436 Cmp No Notice', 'privacy_notice_at' => null]);
$db->exec("INSERT INTO equipment (name) VALUES ('V436 cmp loaned')"); $lEq = (int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_loans (equipment_id, client_id, loaned_at, due_date) VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY))")->execute([$lEq, $fc]);
$db->exec("INSERT INTO contracts (name, amount, status) VALUES ('V436 cmp contract', 1000, 'Active')"); $ctr = (int)$db->lastInsertId();
$db->exec("INSERT INTO contract_spend (contract_id, spent_on, amount) VALUES ($ctr, CURDATE(), 950)");
$c = $get436('compliance');
$has = fn(string $k, string $f, $v): bool => (bool)array_filter($c[$k] ?? [], fn($r) => ($r[$f] ?? null) === $v);
$ar = current(array_filter($c['auth_risk'], fn($r) => $r['id'] === $cT));
check($ar && $ar['client_id'] === $cA && $ar['expired'] === true && array_key_exists('funds_used', $ar) && array_key_exists('authorized_funds', $ar) && is_string($ar['text']) && isset($ar['pct'], $ar['title'], $ar['client'])
    && $has('reports_due', 'id', $cT) && $has('followups_due', 'client_id', $fc) && $c['followups_due_total'] >= 301 && $has('loans_overdue', 'id', $lEq) && $has('contracts_risk', 'id', $ctr)
    && $has('consents', 'client_id', $cA) && $has('releases_risk', 'client_id', $cA) && $has('no_release', 'client_id', $cNoRel) && $has('minors_no_consent', 'client_id', $cMinor) && $has('no_notice', 'client_id', $cNoNotice)
    && !$has('no_release', 'client_id', $cMinor) && is_array($c['equipment_due']) && $c['validity_days'] === (int)(setting('consent_validity_days') ?: 365) && is_int($c['counts']['equipment_due']),
    'R22/R23: compliance sends every section (each fixture in its place, the minor not also under no release), with client_id and funds on authorizations');
$rr = current(array_filter($c['releases_risk'], fn($r) => $r['client_id'] === $cA));
$ctRow = current(array_filter($c['contracts_risk'], fn($r) => $r['id'] === $ctr));
check($rr['kind'] === 'Withdrawn' && str_starts_with($rr['text'], 'V436 Cmp Expired: V436 Employer (Employer), withdrawn ') && $ctRow['pct'] === 95 && str_contains($ctRow['text'], '$950.00 of $1,000.00 (95%)'),
    'R22: rows carry their line in the page\'s words (a withdrawn release, a contract at 95%)' . (($rr['kind'] ?? '') === 'Withdrawn' && ($ctRow['pct'] ?? 0) === 95 ? '' : ': ' . json_encode([$rr, $ctRow])));
$cd = $get436('consents_due');
check($cd['consents'] === $c['consents'] && $cd['validity_days'] === $c['validity_days'] && isset($cd['consents'][0]['label'], $cd['consents'][0]['action']), 'R22: consents_due keeps its 4.34.0 shape, from the shared helper');
$as436($gScoped436);
try {
    $sc = $get436('compliance');
    $loan = current(array_filter($sc['loans_overdue'] ?? [['id' => 0]], fn($r) => $r['id'] === $lEq));
    check(!array_key_exists('loans_overdue', $sc) && !array_key_exists('contracts_risk', $sc) && $sc['equipment_due'] === [] && $sc['counts']['equipment_due'] === null && $sc['counts']['loans_overdue'] === null
        && !array_filter($sc['no_release'], fn($r) => (int)$db->query('SELECT assigned_to FROM clients WHERE id=' . (int)$r['client_id'])->fetchColumn() !== 1),
        'R22: without equipment and billing, loans_overdue and contracts_risk are absent (equipment_due kept empty for 4.35.0 apps); a scoped user sees only their caseload');
} finally { $as436(null); }
$db->exec("UPDATE `groups` SET perms='clients;equipment', own_caseload_only=1 WHERE id=$gScoped436");
$as436($gScoped436);
try {
    $loan = current(array_filter($get436('compliance')['loans_overdue'], fn($r) => $r['id'] === $lEq));
    check($loan && $loan['borrower'] === null && $loan['borrower_id'] === null && str_contains($loan['text'], 'with a client outside your caseload'), 'R22: a loan to another caseload\'s client hides the borrower');
} finally { $as436(null); $db->exec("UPDATE `groups` SET perms='clients;schedule;referrals', own_caseload_only=1 WHERE id=$gScoped436"); }
[$code, $out, $err] = $renderFresh436('compliance', []);
check($code === 0 && $err === '' && str_contains($out, 'Authorizations at risk (' . count($c['auth_risk']) . ')') && str_contains($out, 'Releases of information (' . $c['counts']['releases'] . ')')
    && str_contains($out, 'Device follow-ups due (' . $c['followups_due_total'] . ')') && str_contains($out, 'V436 Cmp No Notice') && str_contains($out, 'Overdue equipment loans (' . count($c['loans_overdue']) . ')'),
    'R22: compliance.php shows the same sections and counts (shared helper)' . ($err ? ": $err" : ''));

/* ---- R24: the corrections note on a signed copy ---- */
$db->exec("INSERT INTO staff_agreements (title, body, version, active, kind) VALUES ('V436 copy agreement', 'Text.', 1, 0, 'staff')"); $ca = (int)$db->lastInsertId();
$db->prepare("INSERT INTO staff_agreement_signatures (agreement_id, version, user_id, title, body, signed_name, signed_at) VALUES (?, 1, 1, 'V436 copy agreement', 'Text.', 'Test Admin', DATE_SUB(NOW(), INTERVAL 2 DAY))")->execute([$ca]); $sig = (int)$db->lastInsertId();
check($get436('agreement_copy', ['id' => $sig])['copy']['corrections_text'] === '', 'R24: no correction: corrections_text is ""');
$db->prepare("INSERT INTO staff_agreement_revisions (agreement_id, version, old_title, old_body, new_title, new_body, small_correction, changed_by, changed_at) VALUES (?, 1, 'a', 'b', 'a', 'c', 1, ?, DATE_SUB(NOW(), INTERVAL 1 DAY))")->execute([$ca, $u436]);
$ct = $get436('agreement_copy', ['id' => $sig])['copy']['corrections_text'];
check(str_starts_with($ct, 'Small corrections were made to the current text after you signed: ') && str_contains($ct, ' by V436 Synthetic Colleague') && str_ends_with($ct, '. Your copy above is unchanged.'),
    'R24: a small correction after signing gives corrections_text with its date and name');
[$code, $out, $err] = $renderFresh436('agreements', ['copy' => $sig]);
check($code === 0 && str_contains($out, h($ct)), 'R24: agreements.php shows the same sentence (shared helper)' . ($err ? ": $err" : ''));
$db->exec("DELETE FROM staff_agreements WHERE id=$ca");

/* ---- R26: credentials ---- */
$cred = fn(array $b) => $req436('POST', 'save_credential', $b);
[$st, $d] = $cred(['type' => 'Background check', 'number' => str_repeat('9', 81)]);
check($st === 422 && ($d['errors']['number'] ?? '') === 'Keep this to 80 characters or fewer.' && !$db->query("SELECT COUNT(*) FROM staff_credentials WHERE user_id=1 AND name='Background check'")->fetchColumn(), 'R26: an 81-character number is refused on errors.number, nothing saved');
[$st, $d] = $cred(['type' => 'Background check', 'issued_on' => date('Y-m-d', strtotime('+1 year'))]);
[$st2, $d2] = $cred(['type' => 'Other', 'other_name' => '']);
[$st3, $d3] = $cred(['type' => 'Background check', 'issued_on' => '2026-01-10', 'expires_on' => '2026-01-01']);
check($st === 422 && ($d['errors']['issued_on'] ?? '') === 'Enter the date issued as a real date, not in the future.' && $st2 === 422 && ($d2['errors']['other_name'] ?? '') === 'Name the credential, since Other is chosen.'
    && $st3 === 422 && ($d3['errors']['expires_on'] ?? '') === 'The expiry date is before the date issued.', 'R26: a future issue date, Other with no name and an expiry before issue are refused on their fields');
$ok = $post436('save_credential', ['name' => 'CPR / First Aid', 'expires_on' => '2030-01-01']);
$cut = $post436('save_credential', ['name' => 'V436 Long permit', 'number' => str_repeat('7', 90), 'issuer' => str_repeat('i', 130), 'notes' => str_repeat('n', 300)]);
$cutRow = $db->query('SELECT number, issuer, notes FROM staff_credentials WHERE id=' . (int)$cut['id'])->fetch();
[$stL, $dL] = $cred(['name' => str_repeat('x', 121)]);
check(mb_strlen($cutRow['number']) === 80 && mb_strlen($cutRow['issuer']) === 120 && mb_strlen($cutRow['notes']) === 255 && $stL === 422 && $dL['error'] === 'Name the credential (120 characters or fewer).',
    'R26: a 4.35.0-style save (name, no type) keeps its old rules: long values cut to length, a long name refused with the old message');
$old = $post436('save_credential', ['name' => 'V436 Synthetic permit']);
$both = $post436('save_credential', ['type' => 'Other', 'other_name' => 'V436 Both permit', 'name' => 'V436 Both permit', 'notes' => 'V436 note', 'idem_key' => $key436()]);
check($db->query('SELECT name FROM staff_credentials WHERE id=' . (int)$ok['id'])->fetchColumn() === 'CPR / First Aid' && $db->query('SELECT name FROM staff_credentials WHERE id=' . (int)$old['id'])->fetchColumn() === 'V436 Synthetic permit'
    && $db->query('SELECT notes FROM staff_credentials WHERE id=' . (int)$both['id'])->fetchColumn() === 'V436 note', 'R26: a 4.35.0 save with name only still saves (a listed type or Other); type, other_name and name together save, with notes');
$post436('save_credential', ['id' => $both['id'], 'type' => 'Other', 'other_name' => 'V436 Both permit']);
check($db->query('SELECT notes FROM staff_credentials WHERE id=' . (int)$both['id'])->fetchColumn() === 'V436 note', 'R26: an edit without notes keeps the stored notes');
[$code, $out, $err] = $renderFresh436('credentials', [], ['save_credential' => '1', 'credential_id' => '0', 'user_id' => '1', 'type' => 'Background check', 'number' => str_repeat('9', 81)]);
check(str_contains($pageSession436(), 'Keep this to 80 characters or fewer.') && !$db->query("SELECT COUNT(*) FROM staff_credentials WHERE user_id=1 AND name='Background check'")->fetchColumn(), 'R26: credentials.php refuses the same (shared helper)');
$db->exec("DELETE FROM staff_credentials WHERE user_id=1 AND name IN ('CPR / First Aid','V436 Synthetic permit','V436 Both permit','V436 Long permit')");

/* ---- R27: signing in person tells the administrators on both channels ---- */
$sc436 = $client436('V436 Sign Client');
$sigRow = function (string $method) use ($db, $sc436): int {
    $db->prepare("INSERT INTO client_signatures (client_id, kind, title, body, signer_name, signer_email, token, created_by, method) VALUES (?, 'other', ?, 'Synthetic text.', 'V436 Sign Client', 'v436-sign@example.invalid', ?, 1, ?)")
        ->execute([$sc436, 'V436 doc ' . $method . ' ' . bin2hex(random_bytes(3)), bin2hex(random_bytes(24)), $method]);
    return (int)$db->lastInsertId();
};
$s1 = $sigRow('In person'); $t1 = (string)$db->query("SELECT title FROM client_signatures WHERE id=$s1")->fetchColumn();
[$code, $out, $err] = $renderFresh436('signatures', ['client' => $sc436], ['sign_now' => (string)$s1, 'signature' => 'V436 Sign Client']);
check($db->query("SELECT signed_at FROM client_signatures WHERE id=$s1")->fetchColumn() !== null && $notes436('Signed in person: ' . $t1 . ' for V436 Sign Client', 'signatures.php?client=' . $sc436) === $admins436,
    'R27: signing in person on the website now tells every administrator "Signed in person: …"' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderFresh436('signatures', ['client' => $sc436], ['sign_now' => (string)$s1, 'signature' => 'V436 Sign Client']);
check($notes436('Signed in person: ' . $t1 . ' for V436 Sign Client') === $admins436, 'R27: a repeat (already signed) tells nobody again');
$s2 = $sigRow('In person'); $t2 = (string)$db->query("SELECT title FROM client_signatures WHERE id=$s2")->fetchColumn();
$post436('sign_in_person', ['client_id' => $sc436, 'id' => $s2, 'typed_name' => 'V436 Sign Client']);
$post436('sign_in_person', ['client_id' => $sc436, 'id' => $s2, 'typed_name' => 'V436 Sign Client']);
check($notes436('Signed in person: ' . $t2 . ' for V436 Sign Client', 'signatures.php?client=' . $sc436) === $admins436, 'R27: sign_in_person tells them the same way, once (a repeat tells nobody)');

/* ---- R28: the website's Check in button ---- */
$kc = $client436('V436 Checkin Client');
$sSoon = (int)(function () use ($db, $kc) { $db->prepare('INSERT INTO scheduled_sessions (client_id, user_id, start_at, end_at) VALUES (?, 1, ?, ?)')->execute([$kc, date('Y-m-d H:i:s', time() + 600), date('Y-m-d H:i:s', time() + 4200)]); return $db->lastInsertId(); })();
$sLater = (int)(function () use ($db, $kc) { $db->prepare('INSERT INTO scheduled_sessions (client_id, user_id, start_at, end_at) VALUES (?, 1, ?, ?)')->execute([$kc, date('Y-m-d H:i:s', time() + 7200), date('Y-m-d H:i:s', time() + 10800)]); return $db->lastInsertId(); })();
[$code, $out, $err] = $renderFresh436('schedule', []);
check($code === 0 && $err === '' && substr_count($out, 'name="check_in"') >= 1 && str_contains($out, 'Check in<span class="visually-hidden"> session with V436 Checkin Client'), 'R28: schedule.php shows Check in for a session about to start' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderFresh436('schedule', [], ['check_in' => '1', 'session_id' => (string)$sSoon]);
check($db->query("SELECT checked_in_at FROM scheduled_sessions WHERE id=$sSoon")->fetchColumn() !== null && $audits436('session.checkin', 'web') >= 1 && str_contains($pageSession436(), 'V436 Checkin Client checked in at '),
    'R28: Check in 10 minutes before the start records the arrival, audited "web"' . ($err ? ": $err" : ''));
[$code, $out, $err] = $renderFresh436('schedule', [], ['check_in' => '1', 'session_id' => (string)$sSoon]);
check(str_contains($pageSession436(), 'Already checked in at '), 'R28: again: the error flash');
[$code, $out, $err] = $renderFresh436('schedule', [], ['check_in' => '1', 'session_id' => (string)$sLater]);
check(str_contains($pageSession436(), 'check-in opens 30 minutes before it.') && $db->query("SELECT checked_in_at FROM scheduled_sessions WHERE id=$sLater")->fetchColumn() === null, 'R28: 2 hours before: refused with the 30-minute text');
[$st, $d] = $req436('POST', 'check_in_session', ['session_id' => $sLater]);
[$st2, $d2] = $req436('POST', 'check_in_session', ['session_id' => $sSoon]);
check($st === 422 && str_contains($d['error'], 'check-in opens 30 minutes before it.') && $st2 === 409 && str_starts_with($d2['error'], 'Already checked in at '), 'R28: check_in_session answers the same (shared helper)');
$db->exec("UPDATE scheduled_sessions SET start_at=DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id=$sLater");
$ci = $post436('check_in_session', ['session_id' => $sLater, 'checked_in_at' => date('c', time() - 60)]);
[$st, $d] = $req436('POST', 'check_in_session', ['session_id' => $sLater + 1000, 'checked_in_at' => 'garbage']);
check(str_ends_with($ci['message'], '.') && str_contains($ci['message'], 'V436 Checkin Client checked in at ') && $audits436('session.checkin', 'mobile, offline at %') >= 1 && $st === 422,
    'R28: an offline check-in still records its time (audited "mobile, offline at"); an unreadable time is 422');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id IN ($kc, $dc)");

// Put back every setting this file changed, the user's account fields, and the groups.
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$db->prepare('UPDATE users SET mobile=?, alert_sms=?, alert_email=?, calendar_kind=?, calendar_sync=?, calendar_error=?, calendar_synced_at=? WHERE id=1')
    ->execute([$user1_436['mobile'], $user1_436['alert_sms'], $user1_436['alert_email'], $user1_436['calendar_kind'], $user1_436['calendar_sync'], $user1_436['calendar_error'], $user1_436['calendar_synced_at']]);
$db->exec('DELETE FROM settings');
$restore436 = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
foreach ($settings436 as $name => $value) $restore436->execute([$name, $value]);
$db->exec("DELETE FROM `groups` WHERE id IN ($gScoped436, $gStaff436, $gNoClients436)");
putenv('CMS_TEST_SESSION_ADD');
check($db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) == $settings436 && (int)$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$myToken436")->fetchColumn() === 1,
    'v436: every setting is as it was before this file, and the test session is still signed in');
