<?php
// Included by full_schema.php after v434_parity_cases.php, against its synthetic database only:
// 4.35.0, field casework in the apps (plan-4350 Q1-Q14, the server parts Q1, Q2, Q3, Q6, Q7, Q9,
// Q10, Q11 and Q13). Each block is one item. Every request goes through api.php's own input
// parsing (the JSON body as the iPhone sends it, and the comma-separated lists Android sends),
// and each helper the website pages now share is checked once through its page too. Synthetic
// people and records only; every setting this file changes is put back at its end.
$GLOBALS['CURRENT_PERMS'] = array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED'] = false;
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$settings435 = $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("DELETE FROM login_attempts");
// The website pages are opened as user 1, whose sign-in may have been renewed by an earlier file
// (a session epoch): the page session carries the current credential version.
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['credential_version' => credential_version($db->query('SELECT * FROM users WHERE id=1')->fetch())]));
$key435 = fn(): string => 'v435-' . bin2hex(random_bytes(8));
// A GET request, answered as the apps read it (ok or a refusal, with its status kept in the body).
file_put_contents($tmp . '/v435_get.php', '<?php $_GET = json_decode($argv[1], true); $_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . str_repeat("a", 64); $_SERVER["REQUEST_METHOD"] = "GET"; require ' . var_export($tmp . '/api.php', true) . ';');
$get435 = function (string $action, array $query = [], bool $expectOk = true) use ($tmp): array {
    putenv('CMS_TEST_INPUT={}');
    $p = proc_open([PHP_BINARY, '-d', 'sendmail_path=/usr/bin/true', $tmp . '/v435_get.php', json_encode(['action' => $action] + $query)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $d = json_decode($out, true);
    if (!is_array($d) || (bool)($d['ok'] ?? false) !== $expectOk) throw new RuntimeException("GET $action: $err $out");
    return $d;
};
// Any request with its HTTP status (the child prints the status it answered with to stderr).
file_put_contents($tmp . '/v435_req.php', '<?php register_shutdown_function(function () { fwrite(STDERR, "\nV435STATUS:" . (int)http_response_code()); }); $_GET = json_decode($argv[1], true); $_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . str_repeat("a", 64); $_SERVER["REQUEST_METHOD"] = $argv[2]; require ' . var_export($tmp . '/api.php', true) . ';');
$req435 = function (string $method, string $action, array $body = [], array $query = []) use ($tmp): array {
    putenv('CMS_TEST_INPUT=' . json_encode($body === [] ? new stdClass() : $body));
    $p = proc_open([PHP_BINARY, '-d', 'sendmail_path=/usr/bin/true', $tmp . '/v435_req.php', json_encode(['action' => $action] + $query), $method], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $status = preg_match('/V435STATUS:(\d+)/', $err, $m) ? (int)$m[1] : 0;
    $d = json_decode($out, true);
    if (!is_array($d)) throw new RuntimeException("$method $action: $err $out");
    return [$status, $d];
};
// Each page opened here starts with no session files left over, so the one it leaves is the only one.
$renderFresh435 = function (string $name, array $query, ?array $post = null) use ($renderPage, $tmp): array {
    foreach (glob($tmp . '/sess_*') ?: [] as $f) @unlink($f);
    return $renderPage($name, $query, $post);
};
// What the last page left in its session (its field errors and kept input, after a redirect).
$pageSession435 = function () use ($tmp): string {
    $files = glob($tmp . '/sess_*') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    return $files ? (string)file_get_contents($files[0]) : '';
};
// A caseload-scoped group for user 1 (as the website's "own caseload only"), put back by $unscope435.
$db->exec("INSERT INTO `groups` (name, perms, own_caseload_only) VALUES ('V435 scoped', 'clients;schedule', 1)"); $g435 = (int)$db->lastInsertId();
$scope435 = fn() => $db->exec("UPDATE users SET group_id=$g435 WHERE id=1");
$unscope435 = fn() => $db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V435 Synthetic Instructor','v435-instructor@example.invalid','test','instructor')"); $u435 = (int)$db->lastInsertId();
$client435 = function (string $name, array $cols = []) use ($db, $u435): int {
    $cols = ['name' => $name] + $cols + ['status' => 'Active Training', 'assigned_to' => $u435, 'state' => 'IA'];
    $db->prepare('INSERT INTO clients (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$track435 = function (int $client, array $cols = []) use ($db): int {
    $cols = $cols + ['client_id' => $client, 'title' => 'V435 synthetic track', 'auth_number' => 'V435-AUTH', 'funding_agency' => 'State VR', 'authorized_hours' => 500, 'hourly_rate' => 50, 'status' => 'Active'];
    $db->prepare('INSERT INTO training_tracks (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$session435 = function (int $client, ?int $track, int $startTs, float $hours = 1.0, array $extra = []) use ($db, $u435): int {
    $cols = ['client_id' => $client, 'track_id' => $track, 'user_id' => $u435, 'start_at' => date('Y-m-d H:i:s', $startTs), 'end_at' => date('Y-m-d H:i:s', $startTs + (int)round($hours * 3600))] + $extra;
    $db->prepare('INSERT INTO scheduled_sessions (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$status435 = fn(int $sid): string => (string)$db->query("SELECT status FROM scheduled_sessions WHERE id=$sid")->fetchColumn();

/* ---- Q1: schedule a group session ---- */
$ga = $client435('V435 Group Alpha'); $gaOld = $track435($ga, ['title' => 'V435 Alpha older', 'created_at' => '2026-01-01 00:00:00']); $gaNew = $track435($ga, ['title' => 'V435 Alpha newer']);
$gb = $client435('V435 Group Bravo'); $gbT = $track435($gb);
$gDate = date('Y-m-d', strtotime('+20 days'));
$groupRows435 = fn(string $uid): array => $db->query('SELECT client_id, track_id, user_id FROM scheduled_sessions WHERE group_uid=' . $db->quote($uid) . ' ORDER BY client_id')->fetchAll();
$choices = $get435('group_choices');
$alphaChoice = current(array_filter($choices['clients'], fn($c) => $c['id'] === $ga));
check($alphaChoice && array_column($alphaChoice['tracks'], 'id') === [$gaNew, $gaOld] && in_array($u435, array_column($choices['staff'], 'id'), true) && $choices['default_staff_id'] === 1,
    'Q1: group_choices lists bookable clients with their active tracks newest first, the active staff and the default instructor');
$wait = $client435('V435 Group Waitlisted', ['status' => 'Waitlist']);
check(!in_array($wait, array_column($get435('group_choices')['clients'], 'id'), true), 'Q1: group_choices leaves out a Waitlist client, as the website form does');
// iPhone shape: client_ids as a JSON int array, tracks as an object with string keys.
$k1 = $key435();
$r = $call('schedule_group', ['client_ids' => [$ga, $gb], 'tracks' => [(string)$ga => $gaOld], 'staff_id' => $u435, 'date' => $gDate, 'time' => '07:15', 'duration' => 1.5, 'location' => 'V435 room', 'is_remote' => false, 'idem_key' => $k1]);
$rows = $groupRows435((string)$r['group_uid']);
check($r['sessions_created'] === 2 && count($rows) === 2 && (int)$rows[0]['track_id'] === $gaOld && (int)$rows[1]['track_id'] === $gbT && (int)$rows[0]['user_id'] === $u435
    && str_starts_with($r['message'], 'Group session scheduled for 2 clients.') && $r['no_track'] === [],
    'Q1: schedule_group books one row per client under one group_uid, a tracks pick used and the newest active track otherwise');
// 4.38.0: a retry is the identical request (a key reused for a different body is refused).
$again = $call('schedule_group', ['client_ids' => [$ga, $gb], 'tracks' => [(string)$ga => $gaOld], 'staff_id' => $u435, 'date' => $gDate, 'time' => '07:15', 'duration' => 1.5, 'location' => 'V435 room', 'is_remote' => false, 'idem_key' => $k1]);
check(!empty($again['repeat']) && $again['group_uid'] === $r['group_uid'] && count($groupRows435((string)$r['group_uid'])) === 2
    && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$ga AND start_at=" . $db->quote("$gDate 07:15:00"))->fetchColumn() === 1,
    'Q1: the same idem_key sent again answers the first booking and books nothing more');
$dup = $call('schedule_group', ['client_ids' => "$ga,$gb", 'staff_id' => (string)$u435, 'date' => $gDate, 'time' => '07:15', 'duration' => '1.5', 'idem_key' => $key435()], [], false, 'already on the schedule');
check(!empty($dup['duplicate']) && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$ga AND start_at=" . $db->quote("$gDate 07:15:00"))->fetchColumn() === 1,
    'Q1: the same group sent again with a new key (Android\'s comma-separated client_ids) is a 409 duplicate, with no new rows');
$one = $call('schedule_group', ['client_ids' => [$ga], 'staff_id' => $u435, 'date' => $gDate, 'time' => '09:00', 'duration' => 1], [], false, 'Choose at least two clients.');
check(($one['errors']['clients'] ?? '') === 'Choose at least two clients.', 'Q1: one client is refused on errors.clients');
$w = $call('schedule_group', ['client_ids' => [$ga, $wait], 'staff_id' => $u435, 'date' => $gDate, 'time' => '09:00', 'duration' => 1], [], false, 'V435 Group Waitlisted is on the waitlist or closed');
check(isset($w['errors']['clients']), 'Q1: a Waitlist client is refused, named, on errors.clients');
$bt = $call('schedule_group', ['client_ids' => [$ga, $gb], 'tracks' => [(string)$ga => $gbT], 'staff_id' => $u435, 'date' => $gDate, 'time' => '09:00', 'duration' => 1], [], false, 'active training tracks');
check(isset($bt['errors']['track_' . $ga]), 'Q1: a track that is not the client\'s is refused on errors.track_<client id>');
$bad = $call('schedule_group', ['client_ids' => [$ga, $gb], 'staff_id' => $u435, 'date' => '0026-01-01', 'time' => '25:00', 'duration' => 30], [], false);
check(isset($bad['errors']['date'], $bad['errors']['time'], $bad['errors']['duration']), 'Q1: the shared fields are refused on their own keys (date, time, duration), without the website\'s g_ prefix');
// A track with no authorization number raises a warning: refused with its hash, then booked with it.
$gc = $client435('V435 Group Charlie'); $track435($gc, ['auth_number' => '']);
$gd = $client435('V435 Group Delta'); $track435($gd);
$warn = $call('schedule_group', ['client_ids' => [$gc, $gd], 'staff_id' => $u435, 'date' => $gDate, 'time' => '11:00', 'duration' => 1], [], false, 'Authorization review:');
check(is_array($warn['authorization_warnings']) && is_string($warn['authorization_warnings'][0]) && str_contains($warn['authorization_warnings'][0], 'V435 Group Charlie: The track has no authorization number.')
    && is_string($warn['authorization_hash']) && !isset($warn['errors']),
    'Q1: a track warning is refused with authorization_warnings (strings) and authorization_hash, as schedule_session refuses one');
$wrong = $call('schedule_group', ['client_ids' => [$gc, $gd], 'staff_id' => $u435, 'date' => $gDate, 'time' => '11:00', 'duration' => 1, 'authorization_ack_hash' => str_repeat('0', 64)], [], false, 'not the ones you approved');
$ok = $call('schedule_group', ['client_ids' => [$gc, $gd], 'staff_id' => $u435, 'date' => $gDate, 'time' => '11:00', 'duration' => 1, 'authorization_ack' => true, 'authorization_ack_hash' => $warn['authorization_hash'], 'idem_key' => $key435()]);
check($ok['sessions_created'] === 2 && $audits434('session.schedule', '%authorization exception approved: V435 Group Charlie%') === 1,
    'Q1: sent back with that hash the group is booked, and the audit records "authorization exception approved:" with the warnings');
// Caseload-scoped staff send only their own clients.
$own = $client435('V435 Group Own', ['assigned_to' => 1]); $track435($own);
$scope435();
try {
    $call('schedule_group', ['client_ids' => [$own, $ga], 'staff_id' => 1, 'date' => $gDate, 'time' => '13:00', 'duration' => 1], [], false, 'not on your caseload');
    $sc = $get435('group_choices');
    check(in_array($own, array_column($sc['clients'], 'id'), true) && !in_array($ga, array_column($sc['clients'], 'id'), true), 'Q1: caseload-scoped staff are refused another instructor\'s client (403), and group_choices lists only their own');
} finally { $unscope435(); }
// The same client already booked at that time with others (a partial duplicate) is refused, naming them.
$gx = $client435('V435 Group Xray'); $track435($gx);
$pd = $call('schedule_group', ['client_ids' => [$ga, $gb, $gx], 'staff_id' => $u435, 'date' => $gDate, 'time' => '07:15', 'duration' => 1.5], [], false, 'Already scheduled with V435 Synthetic Instructor at that time: V435 Group Alpha, V435 Group Bravo');
check(isset($pd['errors']['clients']) && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$gx")->fetchColumn() === 0, 'Q1: a partial duplicate is refused on errors.clients and nothing is saved');
// Item 4 of the review: tracks must be keyed by client id; a list is refused.
$tl = $call('schedule_group', ['client_ids' => [$ga, $gb], 'tracks' => [$gaOld], 'staff_id' => $u435, 'date' => $gDate, 'time' => '17:00', 'duration' => 1], [], false, 'send each client\'s track by client id');
check(isset($tl['errors']['tracks']), 'Q1: tracks sent as a list is refused on errors.tracks');
// Item 3: a boolean where a number belongs is refused on its field, never read as 1.
$bd = $call('schedule_group', ['client_ids' => [$ga, $gb], 'staff_id' => $u435, 'date' => $gDate, 'time' => '17:00', 'duration' => true], [], false, 'Duration (hours): this value could not be read');
check(isset($bd['errors']['duration']) && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$ga AND start_at=" . $db->quote("$gDate 17:00:00"))->fetchColumn() === 0, 'Q1: duration true is refused on errors.duration');
// Item 7: a repeat rebuilds no_track and the message from the database.
$gn = $client435('V435 Group NoTrack');
$kn = $key435();
$first = $call('schedule_group', ['client_ids' => [$gb, $gn], 'staff_id' => $u435, 'date' => $gDate, 'time' => '18:00', 'duration' => 1, 'idem_key' => $kn]);
$rpt = $call('schedule_group', ['client_ids' => [$gb, $gn], 'staff_id' => $u435, 'date' => $gDate, 'time' => '18:00', 'duration' => 1, 'idem_key' => $kn]);
check($first['no_track'] === ['V435 Group NoTrack'] && !empty($rpt['repeat']) && $rpt['no_track'] === $first['no_track'] && $rpt['message'] === $first['message']
    && $rpt['sessions_created'] === 2 && $rpt['group_uid'] === $first['group_uid'], 'Q1: a repeated schedule_group answers the same lists and message as the first reply');
// The website's handler books through the same helper.
$ge = $client435('V435 Group Echo'); $geT = $track435($ge);
$gf = $client435('V435 Group Foxtrot'); $gfT = $track435($gf);
[$code, , $err] = $renderFresh435('schedule', [], ['add_group_session' => '1', 'group_clients' => [(string)$ge, (string)$gf], 'g_staff_id' => (string)$u435, 'g_date' => $gDate, 'g_time' => '15:00', 'g_duration' => '1.5', 'g_location' => '', 'g_notes' => 'V435 web group']);
$webRows = $db->query("SELECT client_id, track_id, group_uid FROM scheduled_sessions WHERE notes='V435 web group' ORDER BY client_id")->fetchAll();
check($code === 0 && $err === '' && count($webRows) === 2 && $webRows[0]['group_uid'] !== null && $webRows[0]['group_uid'] === $webRows[1]['group_uid']
    && (int)$webRows[0]['track_id'] === $geT && (int)$webRows[1]['track_id'] === $gfT, 'Q1: the Schedule page\'s group form books the same rows through the shared helper' . ($err ? ": $err" : ''));
[$code, , $err] = $renderFresh435('schedule', [], ['add_group_session' => '1', 'group_clients' => [(string)$ge], 'g_staff_id' => (string)$u435, 'g_date' => $gDate, 'g_time' => '16:30', 'g_duration' => '1']);
check($code === 0 && $err === '' && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$ge AND start_at=" . $db->quote("$gDate 16:30:00"))->fetchColumn() === 0,
    'Q1: the page still refuses a single client and books nothing' . ($err ? ": $err" : ''));
$gwc = $client435('V435 Web Warn One'); $track435($gwc, ['auth_number' => '']); $gwd = $client435('V435 Web Warn Two'); $track435($gwd);
$webPost = ['add_group_session' => '1', 'group_clients' => [(string)$gwc, (string)$gwd], 'g_staff_id' => (string)$u435, 'g_date' => $gDate, 'g_time' => '19:00', 'g_duration' => '1'];
[$code, , $err] = $renderFresh435('schedule', [], $webPost);
$sess = $pageSession435();
$hashOk = preg_match('/g_authorization_hash";s:64:"([0-9a-f]{64})"/', $sess, $hm);
check($code === 0 && $err === '' && $hashOk && str_contains($sess, 'V435 Web Warn One: The track has no authorization number.')
    && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$gwc")->fetchColumn() === 0, 'Q1: the page shows the group\'s warnings with the approval hash and books nothing' . ($err ? ": $err" : ''));
[$code, , $err] = $renderFresh435('schedule', [], $webPost + ['g_authorization_ack' => '1', 'g_authorization_hash' => $hm[1] ?? '']);
check($code === 0 && $err === '' && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$gwc AND group_uid IS NOT NULL")->fetchColumn() === 1
    && $audits434('session.schedule', '%authorization exception approved: V435 Web Warn One%') === 1, 'Q1: ticked with that hash, the page books the group and audits the approval' . ($err ? ": $err" : ''));

/* ---- Q2: record a group's attendance ---- */
$past = time() - 30 * 3600;
$uid2 = 'f435a' . bin2hex(random_bytes(3));
$m1 = $client435('V435 Attend One', ['counselor_name' => 'V435 Counselor', 'counselor_email' => 'v435-counselor@example.invalid', 'counselor_verified' => 1]); $m1T = $track435($m1);
$m2 = $client435('V435 Attend Two'); $m2T = $track435($m2);
$m3 = $client435('V435 Attend Three', ['counselor_name' => 'V435 Counselor', 'counselor_email' => 'v435-counselor@example.invalid', 'counselor_verified' => 1]); $m3T = $track435($m3);
$db->prepare("INSERT INTO vrc_tokens (counselor_email, counselor_name, token) VALUES ('v435-counselor@example.invalid', 'V435 Counselor', ?)")->execute([bin2hex(random_bytes(24))]);
$s1 = $session435($m1, $m1T, $past, 1.5, ['group_uid' => $uid2, 'notes' => 'V435 group notes']);
$s2 = $session435($m2, $m2T, $past, 1.5, ['group_uid' => $uid2, 'notes' => 'V435 group notes']);
$s3 = $session435($m3, $m3T, $past, 1.5, ['group_uid' => $uid2, 'notes' => 'V435 group notes']);
$gs = $get435('group_session', ['group_uid' => $uid2]);
check($gs['started'] === true && $gs['can_record'] === true && $gs['default_hours'] === '1.5' && $gs['instructor'] === 'V435 Synthetic Instructor'
    && array_column($gs['members'], 'session_id') === [$s1, $s3, $s2] && $gs['members'][0]['track_id'] === $m1T,
    'Q2: group_session gives the members in name order, started, can_record and the website\'s hours prefill');
$r = $call('complete_group', ['group_uid' => $uid2, 'listed' => [$s1, $s2, $s3], 'attended' => [$s1, $s2], 'hours' => '30'], [], false, 'as a number from 0 to 24');
check(isset($r['errors']['hours']) && $status435($s1) === 'Scheduled', 'Q2: hours out of range are refused on errors.hours, nothing completed');
$r = $call('complete_group', ['group_uid' => $uid2, 'listed' => [$s1, $s2, $s3], 'attended' => [$s1, $s2], 'hours' => true], [], false, 'as a number from 0 to 24');
check(isset($r['errors']['hours']) && $status435($s1) === 'Scheduled', 'Q2: hours true is refused on errors.hours, not read as 1');
$r = $call('complete_group', ['group_uid' => $uid2, 'listed' => [$ga, $gb], 'attended' => [$ga], 'hours' => ''], [], false, 'no session in that group is still waiting for an outcome');
check($status435($s1) === 'Scheduled', 'Q2: session ids from another group record nothing');
$kg = $key435();
$r = $call('complete_group', ['group_uid' => $uid2, 'listed' => [$s1, $s2, $s3], 'attended' => [$s1, $s2], 'hours' => '1.25', 'idem_key' => $kg]);
$firstComplete = $r;
$logs = $db->query("SELECT w.hours, w.created_by, w.description FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id IN ($s1,$s2)")->fetchAll();
check($r['completed'] === 2 && $r['hours_logged_for'] === 2 && $r['no_show'] === ['V435 Attend Three'] && $status435($s3) === 'No Show'
    && count($logs) === 2 && !array_filter($logs, fn($l) => (float)$l['hours'] !== 1.25 || (int)$l['created_by'] !== $u435 || $l['description'] !== 'Group session: V435 group notes')
    && str_starts_with($r['message'], 'Group session of ') && count($r['notes_to_write']) === 2 && $r['notes_to_write'][0]['note_subject'] === sessnote_subject(date('Y-m-d H:i:s', $past)),
    'Q2: complete_group completes those who attended with 1.25 hours each credited to the instructor, the website\'s description, a No Show for the absent one, and the notes to write');
check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='noshow-$s3'")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=$u435 AND message LIKE '%recorded as a No Show%'")->fetchColumn() >= 1,
    'Q2: the counselor is emailed about the No Show and the instructor is told');
$rep = $call('complete_group', ['group_uid' => $uid2, 'listed' => [$s1, $s2, $s3], 'attended' => [$s1, $s2], 'hours' => '1.25', 'idem_key' => $kg]);
check(!empty($rep['repeat']) && $rep['completed'] === 2 && $rep['hours_logged_for'] === 2 && $rep['no_show'] === $firstComplete['no_show']
    && array_column($rep['notes_to_write'], 'session_id') === array_column($firstComplete['notes_to_write'], 'session_id') && str_starts_with($rep['message'], 'Group session of '),
    'Q2: the same idem_key answers the first reply, its lists (no_show, notes_to_write) and message rebuilt from the database');
$future = $client435('V435 Future One'); $fT = $track435($future); $future2 = $client435('V435 Future Two'); $f2T = $track435($future2);
$uidF = 'f435b' . bin2hex(random_bytes(3));
$fs1 = $session435($future, $fT, time() + 5 * 86400, 1, ['group_uid' => $uidF]); $fs2 = $session435($future2, $f2T, time() + 5 * 86400, 1, ['group_uid' => $uidF]);
$r = $call('complete_group', ['group_uid' => $uidF, 'listed' => [$fs1, $fs2], 'attended' => [$fs1, $fs2], 'hours' => ''], [], false, 'has not started yet');
check(!empty($r['not_started']) && $get435('group_session', ['group_uid' => $uidF])['can_record'] === false, 'Q2: a future group is refused with not_started, and group_session says it cannot be recorded yet');
// Hours already logged that day leave that client Scheduled.
$uidL = 'f435c' . bin2hex(random_bytes(3));
$l1 = $client435('V435 Logged One'); $l1T = $track435($l1); $l2 = $client435('V435 Logged Two'); $l2T = $track435($l2);
$ls1 = $session435($l1, $l1T, $past, 1, ['group_uid' => $uidL]); $ls2 = $session435($l2, $l2T, $past, 1, ['group_uid' => $uidL]);
$db->prepare("INSERT INTO work_logs (track_id, work_date, hours, description, created_by, hourly_rate) VALUES (?,?,1,'V435 logged first',1,50)")->execute([$l1T, date('Y-m-d', $past)]);
$r = $call('complete_group', ['group_uid' => $uidL, 'listed' => [$ls1, $ls2], 'attended' => [$ls1, $ls2], 'hours' => '']);
check($r['already_logged'] === ['V435 Logged One'] && $status435($ls1) === 'Scheduled' && $status435($ls2) === 'Completed', 'Q2: a member with hours already logged that day is left Scheduled and named in already_logged');
// Scoped staff list and complete only their own members, as the website's card (two or more).
$uidS = 'f435d' . bin2hex(random_bytes(3));
$sOwn = $client435('V435 Scoped Own', ['assigned_to' => 1]); $sOwnT = $track435($sOwn); $sOwn2 = $client435('V435 Scoped Own Two', ['assigned_to' => 1]); $sOwn2T = $track435($sOwn2);
$sOther = $client435('V435 Scoped Other'); $sOtherT = $track435($sOther);
$ss1 = $session435($sOwn, $sOwnT, $past, 1, ['group_uid' => $uidS]); $ss1b = $session435($sOwn2, $sOwn2T, $past, 1, ['group_uid' => $uidS]); $ss2 = $session435($sOther, $sOtherT, $past, 1, ['group_uid' => $uidS]);
$uidOne = 'f435f' . bin2hex(random_bytes(3));
$so1 = $session435($sOwn, $sOwnT, $past - 86400, 1, ['group_uid' => $uidOne]); $so2 = $session435($sOther, $sOtherT, $past - 86400, 1, ['group_uid' => $uidOne]);
$uidNone = 'f435a' . bin2hex(random_bytes(3));
$sn1 = $session435($sOther, $sOtherT, $past - 2 * 86400, 1, ['group_uid' => $uidNone]); $sn2 = $session435($gb, $gbT, $past - 2 * 86400, 1, ['group_uid' => $uidNone]);
$scope435();
try {
    $sg = $get435('group_session', ['group_uid' => $uidS]);
    $r = $call('complete_group', ['group_uid' => $uidS, 'listed' => [$ss1, $ss1b, $ss2], 'attended' => [$ss1, $ss1b, $ss2], 'hours' => '']);
    check(array_column($sg['members'], 'session_id') === [$ss1, $ss1b] && $sg['can_record'] === true && $r['completed'] === 2 && $status435($ss1) === 'Completed' && $status435($ss2) === 'Scheduled',
        'Q2: caseload-scoped staff see and complete only their own members of a group');
    $one1 = $get435('group_session', ['group_uid' => $uidOne]);
    [$st1, $one2] = $req435('POST', 'complete_group', ['group_uid' => $uidOne, 'listed' => [$so1, $so2], 'attended' => [$so1], 'hours' => '']);
    check($one1['can_record'] === false && $st1 === 422 && str_contains($one2['error'], 'is not recorded as a group. Record V435 Scoped Own\'s session on its own.') && $status435($so1) === 'Scheduled',
        'Q2: with one member on their caseload, group_session says it cannot be recorded and complete_group is refused (the website\'s card rule)');
    [$st0, $none] = $req435('GET', 'group_session', [], ['group_uid' => $uidNone]);
    check($st0 === 404 && $none['error'] === 'That group session is no longer scheduled.', 'Q2: a group with none of their clients is 404 for a caseload-scoped user');
} finally { $unscope435(); }
// The website's card completes through the same helper.
$uidW = 'f435e' . bin2hex(random_bytes(3));
$w1 = $client435('V435 Web Attend One'); $w1T = $track435($w1); $w2 = $client435('V435 Web Attend Two'); $w2T = $track435($w2);
$ws1 = $session435($w1, $w1T, $past, 2, ['group_uid' => $uidW]); $ws2 = $session435($w2, $w2T, $past, 2, ['group_uid' => $uidW]);
[$code, , $err] = $renderFresh435('schedule', [], ['complete_group' => '1', 'group_uid' => $uidW, 'attendance_form' => '1', 'listed' => [(string)$ws1, (string)$ws2], 'attended' => [(string)$ws1], 'g_hours' => '']);
check($code === 0 && $err === '' && $status435($ws1) === 'Completed' && $status435($ws2) === 'No Show'
    && (float)$db->query("SELECT w.hours FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$ws1")->fetchColumn() === 2.0,
    'Q2: the Schedule page\'s group card records the same outcome through the shared helper' . ($err ? ": $err" : ''));
[$code, , $err] = $renderFresh435('schedule', [], ['complete_group' => '1', 'group_uid' => $uidL, 'attendance_form' => '1', 'listed' => [(string)$ls1], 'attended' => [(string)$ls1], 'g_hours' => '99']);
check($code === 0 && $err === '' && $status435($ls1) === 'Scheduled' && str_contains($pageSession435(), SESSION_GROUP_HOURS_ERROR), 'Q2: the page still refuses hours out of range on a started group, with the shared sentence' . ($err ? ": $err" : ''));

/* ---- Q3: goal worked on and service delivered when completing a session ---- */
$db->exec("INSERT INTO service_bundles (name, categories) VALUES ('V435 bundle', 'V435 Category A;V435 Category B')"); $bundle435 = (int)$db->lastInsertId();
$q3c = $client435('V435 Visit Client'); $q3t = $track435($q3c, ['bundle_id' => $bundle435]); $q3other = $track435($q3c, ['title' => 'V435 other track']);
$db->prepare("INSERT INTO smart_goals (track_id, specific_goal, measurable_metric) VALUES (?, 'V435 goal', 'Synthetic')")->execute([$q3t]); $goal435 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO smart_goals (track_id, specific_goal, measurable_metric) VALUES (?, 'V435 other goal', 'Synthetic')")->execute([$q3other]); $goalOther = (int)$db->lastInsertId();
$v1 = $session435($q3c, $q3t, time() - 100 * 3600);
$call('complete_session', ['session_id' => $v1, 'outcome' => 'completed', 'goal_id' => $goal435, 'rsa_category' => 'V435 Category B']);
$wl = $db->query("SELECT w.goal_id, w.rsa_category FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$v1")->fetch();
check((int)$wl['goal_id'] === $goal435 && $wl['rsa_category'] === 'V435 Category B', 'Q3: complete_session saves the goal worked on and the service delivered on the work log');
$v2 = $session435($q3c, $q3t, time() - 130 * 3600);
$r = $call('complete_session', ['session_id' => $v2, 'outcome' => 'completed', 'goal_id' => $goalOther], [], false, 'Goal this session advanced: that goal is not on this track.');
$r2 = $call('complete_session', ['session_id' => $v2, 'outcome' => 'completed', 'rsa_category' => 'V435 Not offered'], [], false, 'is not offered on this track');
check(isset($r['errors']['goal_id']) && isset($r2['errors']['rsa_category']) && $status435($v2) === 'Scheduled', 'Q3: a goal or category that is not the track\'s is refused on its field, and the session stays Scheduled');
$call('complete_session', ['session_id' => $v2, 'outcome' => 'completed']);
$wl = $db->query("SELECT w.goal_id, w.rsa_category FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$v2")->fetch();
check($wl['goal_id'] === null && $wl['rsa_category'] === 'V435 Category A', 'Q3: without them (4.34.0 apps) the log has no goal and the track\'s first category, as before');
$call('log_hours', ['track_id' => $q3t, 'hours' => 1, 'description' => 'V435 null category', 'rsa_category' => null, 'idem_key' => strtoupper(sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)))]);
check($db->query("SELECT rsa_category FROM work_logs WHERE description='V435 null category'")->fetchColumn() === 'V435 Category A', 'Q3: log_hours with rsa_category null stores the track\'s first category');
$r = $call('log_hours', ['track_id' => $q3t, 'hours' => 1, 'description' => 'V435 log', 'goal_id' => $goalOther], [], false, 'Goal this session advanced: that goal is not on this track. It may have been removed. Choose another goal, or none.');
check(isset($r['errors']['goal_id']), 'Q3: log_hours still refuses a goal from another track with the same text, now tied to errors.goal_id');
$v3 = $session435($q3c, $q3t, time() - 160 * 3600);
[$code, , $err] = $renderFresh435('schedule', [], ['set_status' => '1', 'session_id' => (string)$v3, 'to' => 'Completed', 'session_goal' => (string)$goalOther, 'session_category' => 'V435 Category B']);
$wl = $db->query("SELECT s.status, w.goal_id, w.rsa_category FROM scheduled_sessions s LEFT JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$v3")->fetch();
check($code === 0 && $err === '' && $wl['status'] === 'Completed' && $wl['goal_id'] === null && $wl['rsa_category'] === 'V435 Category B',
    'Q3: the Schedule page still ignores a goal that is not the track\'s and keeps a valid service (shared helper)' . ($err ? ": $err" : ''));

/* ---- Q6: offer times for a client request ---- */
$oc = $client435('V435 Offer Client', ['email' => 'v435-offer@example.invalid', 'email_verified' => 1]); $ocT = $track435($oc);
$db->prepare('INSERT INTO portal_tokens (client_id, token) VALUES (?, ?)')->execute([$oc, bin2hex(random_bytes(24))]);
$db->prepare("INSERT INTO portal_requests (client_id, type, details) VALUES (?, 'appointment', 'V435 please')")->execute([$oc]); $req1 = (int)$db->lastInsertId();
$og = $get435('offer_times', ['id' => $req1]);
check($og['can_offer'] === true && $og['defaults']['staff_id'] === $u435 && $og['defaults']['minutes'] === 60 && $og['keeps_length'] === false
    && $og['lengths'] === [30, 45, 60, 90, 120, 180] && $og['tracks'][0]['id'] === $ocT && $og['defaults']['track_id'] === $ocT,
    'Q6: offer_times GET gives the website\'s defaults: the client\'s instructor, 60 minutes and their track');
$moveSess = $session435($oc, $ocT, time() + 9 * 86400, 1.5, ['location' => 'V435 old room']);
$db->prepare("INSERT INTO portal_requests (client_id, type, session_id) VALUES (?, 'reschedule', ?)")->execute([$oc, $moveSess]); $req2 = (int)$db->lastInsertId();
$og2 = $get435('offer_times', ['id' => $req2]);
check($og2['keeps_length'] === true && $og2['defaults']['location'] === 'V435 old room' && $og2['tracks'] === [], 'Q6: a reschedule keeps the session\'s length and starts from its location');
$t1 = date('Y-m-d', strtotime('+12 days')) . ' 10:00'; $t2 = date('Y-m-d', strtotime('+13 days')) . ' 10:00';
$r = $call('offer_times', ['id' => $req1, 'times' => ['', 'not a time'], 'staff_id' => $u435], [], false, 'This time could not be read');
check(isset($r['errors']['time2']), 'Q6: an unreadable time is refused on its own field (errors.time2)');
$r = $call('offer_times', ['id' => $req1, 'times' => [date('Y-m-d H:i', time() + 1800)], 'staff_id' => $u435, 'minutes' => 60], [], false, 'None of the times could be offered');
check(isset($r['errors']['time1']) && str_contains($r['refused'][0] ?? '', 'less than an hour from now'), 'Q6: a time 30 minutes ahead is refused, and all refused is errors.time1');
$ko = $key435();
$mailBefore = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='portal_request.offer'")->fetchColumn();
$r = $call('offer_times', ['id' => $req1, 'times' => [$t1, $t2], 'staff_id' => $u435, 'minutes' => 60, 'track_id' => $ocT, 'remote' => false, 'location' => 'V435 room', 'idem_key' => $ko]);
// 4.38.0: a retry is the identical request (a key reused for a different body is refused).
$r2 = $call('offer_times', ['id' => $req1, 'times' => [$t1, $t2], 'staff_id' => $u435, 'minutes' => 60, 'track_id' => $ocT, 'remote' => false, 'location' => 'V435 room', 'idem_key' => $ko]);
check($r['offered'] === 2 && str_starts_with($r['message'], '2 times offered to V435 Offer Client. They choose one in their portal.') && !empty($r2['repeat'])
    && (int)$db->query("SELECT COUNT(*) FROM portal_request_offers WHERE request_id=$req1 AND status='Offered'")->fetchColumn() === 2
    && $db->query("SELECT status FROM portal_requests WHERE id=$req1")->fetchColumn() === 'In Progress'
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='portal_request.offer'")->fetchColumn() === $mailBefore + 1,
    'Q6: two good times are offered once (the same idem_key answers again), the request is In Progress, with the website\'s message');
$pr = $call('portal_requests');
$row = current(array_filter($pr['requests'], fn($x) => $x['id'] === $req1));
check($row['can_offer'] === true && count($row['offered']) === 2, 'Q6: portal_requests rows carry can_offer and the times offered');
check($r2['message'] === $r['message'] && $r2['offered'] === 2 && count($r2['offered_times']) === 2, 'Q6: a repeated offer_times answers the first message, rebuilt from the database');
$t3 = date('Y-m-d', strtotime('+14 days')) . ' 11:00'; $t4 = date('Y-m-d', strtotime('+15 days')) . ' 11:00';
$rc = $call('offer_times', ['id' => $req1, 'times' => "$t3,$t4", 'staff_id' => (string)$u435, 'minutes' => '60', 'track_id' => (string)$ocT, 'idem_key' => $key435()]);
check($rc['offered'] === 2 && (int)$db->query("SELECT COUNT(*) FROM portal_request_offers WHERE request_id=$req1 AND status='Offered' AND start_at=" . $db->quote("$t3:00"))->fetchColumn() === 1,
    'Q6: times sent as one comma-separated text are read as a list, as client_ids are');
$tb = $call('offer_times', ['id' => $req1, 'times' => [true], 'staff_id' => $u435], [], false, 'This time could not be read');
check(isset($tb['errors']['time1']), 'Q6: a time that is not text is refused on its field');
$r = $call('offer_times', ['id' => $req1, 'times' => [$t1], 'staff_id' => $u435, 'track_id' => $q3t], [], false, 'Choose one of this client');
check(isset($r['errors']['track_id']), 'Q6: another client\'s track is refused on errors.track_id (the shared mapping)');
[$code, , $err] = $renderFresh435('portal_requests', [], ['offer_times' => '1', 'id' => (string)$req1, 'time1' => $t1, 'staff' => (string)$u435, 'minutes' => '60', 'track_id' => (string)$q3t]);
check($code === 0 && $err === '' && (int)$db->query("SELECT COUNT(*) FROM portal_request_offers WHERE request_id=$req1 AND status='Offered'")->fetchColumn() === 2,
    'Q6: the page refuses the same track through the same mapping and offers nothing' . ($err ? ": $err" : ''));
$scope435();
try {
    [$sg1, $og3] = $req435('GET', 'offer_times', [], ['id' => $req1]);
    [$sp1, $op3] = $req435('POST', 'offer_times', ['id' => $req1, 'times' => [$t1], 'staff_id' => $u435]);
    check($sg1 === 403 && $og3['error'] === 'That request is not on your caseload.' && $sp1 === 403 && $op3['error'] === 'That request is not on your caseload.',
        'Q6: a caseload-scoped user is refused another caseload\'s request (GET and POST, 403)');
} finally { $unscope435(); }

/* ---- Q7: tech assessments ---- */
$ac = $client435('V435 Assess Client');
$before = (int)$db->query("SELECT COUNT(*) FROM tech_assessments WHERE client_id=$ac")->fetchColumn();
$r = $call('add_assessment', ['client_id' => $ac, 'assessment_date' => ''], [], false, 'Assessment date is required.');
$r2 = $call('add_assessment', ['client_id' => $ac, 'assessment_date' => date('Y-m-d'), 'hardware_details' => str_repeat('h', 300)], [], false, 'Hardware details is 300 characters; shorten it to 255 or fewer');
$r3 = $call('add_assessment', ['client_id' => $ac, 'assessment_date' => date('Y-m-d', strtotime('+3 days'))], [], false, 'cannot be in the future');
check(($r['errors']['assessment_date'] ?? '') === 'Assessment date is required.' && isset($r2['errors']['hardware_details']) && isset($r3['errors']['assessment_date'])
    && (int)$db->query("SELECT COUNT(*) FROM tech_assessments WHERE client_id=$ac")->fetchColumn() === $before, 'Q7: add_assessment refuses with the website\'s sentences, tied to their fields');
$call('add_assessment', ['client_id' => $ac, 'assessment_date' => date('Y-m-d', strtotime('+1 day')), 'primary_tech' => 'Screen Reader', 'idem_key' => $key435()]);
$as = $get435('assessments', ['client_id' => $ac]);
check(count($as['assessments']) === 1 && in_array('Screen Reader', $as['tech_types'], true), 'Q7: the app keeps its one day of time-zone slack, and assessments carries tech_types');
[$code, , $err] = $renderFresh435('client', ['id' => $ac], ['add_assessment' => '1', 'assessment_date' => date('Y-m-d', strtotime('+1 day')), 'primary_tech' => 'Screen Reader']);
check($code === 0 && $err === '' && (int)$db->query("SELECT COUNT(*) FROM tech_assessments WHERE client_id=$ac")->fetchColumn() === 1
    && str_contains($pageSession435(), 'The assessment date cannot be in the future. Check the year.'), 'Q7: the client page still refuses tomorrow (no slack on the website), with the shared sentence' . ($err ? ": $err" : ''));
foreach (['ai_enabled' => '1', 'ai_provider' => 'anthropic', 'ai_api_key' => 'synthetic-unused-key', 'ai_feat_intake' => '1'] as $sk => $sv) set_setting($sk, $sv);
[$code, $out, $err] = $renderFresh435('intake', ['client' => $ac], ['save_assessment' => '1', 'assessment_date' => date('Y-m-d', strtotime('+2 days')), 'primary_tech' => 'Other', 'summary' => 'V435 intake']);
check($code === 0 && $err === '' && (int)$db->query("SELECT COUNT(*) FROM tech_assessments WHERE client_id=$ac")->fetchColumn() === 1
    && str_contains($out . $pageSession435(), 'The assessment date cannot be in the future. Check the year.'), 'Q7: the intake still refuses a future date, with the shared sentence' . ($err ? ": $err" : ''));

/* ---- Q9: after closure ---- */
$cc = $client435('V435 Closed Client', ['status' => 'Closed - Other', 'email' => 'v435-closed@example.invalid', 'email_verified' => 1]);
foreach ([30, 60, 90] as $d) $db->prepare('INSERT INTO closure_followups (client_id, interval_days, due_on) VALUES (?,?,?)')->execute([$cc, $d, date('Y-m-d', strtotime("+$d days"))]);
$fuIds = $db->query("SELECT id FROM closure_followups WHERE client_id=$cc ORDER BY interval_days")->fetchAll(PDO::FETCH_COLUMN);
$cl = $get435('client', ['id' => $cc]);
check(is_array($cl['after_closure']) && count($cl['after_closure']['followups']) === 3 && !array_filter($cl['after_closure']['followups'], fn($f) => $f['status'] !== 'Pending' || $f['due_now'])
    && $cl['after_closure']['followups'][0]['text'] === '30-day check, due ' . fmt_date(date('Y-m-d', strtotime('+30 days'))) . '.' && $cl['after_closure']['by_phone'] === false,
    'Q9: a closed client\'s record carries after_closure with three pending checks and the website\'s lines');
check($get435('client', ['id' => $q3c])['after_closure'] === null, 'Q9: an open client with nothing after closure has after_closure null');
$s = $call('send_survey', ['client_id' => $cc, 'idem_key' => $key435()]);
check(in_array($s['status'], ['sent', 'unknown'], true), 'Q9: send_survey emails the survey to a confirmed address');
$again = $call('send_survey', ['client_id' => $cc, 'idem_key' => $key435()], [], false, 'is not answered yet');
check(!empty($again['already_sent']), 'Q9: sent again within 30 days is a 409 with already_sent');
$phoneC = $client435('V435 Phone Client', ['status' => 'Closed - Other', 'email' => 'v435-phone@example.invalid', 'email_verified' => 1, 'a11y_contact' => 'Phone']);
$call('send_survey', ['client_id' => $phoneC], [], false, 'prefers phone or post');
check($get435('client', ['id' => $phoneC])['after_closure']['by_phone'] === true, 'Q9: a phone-preference client is refused with the website\'s sentence, and by_phone says so');
$r = $call('phone_survey', ['client_id' => $cc, 'satisfaction' => 11, 'goals_met' => 5], [], false, 'Satisfaction: enter a rating from 1 to 10.');
check(isset($r['errors']['satisfaction']), 'Q9: phone_survey 11 is refused on errors.satisfaction');
$kp = $key435();
$call('phone_survey', ['client_id' => $cc, 'satisfaction' => 8, 'goals_met' => '7', 'comments' => 'V435 by phone', 'idem_key' => $kp]);
$call('phone_survey', ['client_id' => $cc, 'satisfaction' => 8, 'goals_met' => 7, 'comments' => 'V435 by phone', 'idem_key' => $kp]);
check((int)$db->query("SELECT COUNT(*) FROM surveys WHERE client_id=$cc AND channel='Phone' AND satisfaction=8 AND goals_met=7")->fetchColumn() === 1, 'Q9: phone_survey 8/7 records one Phone survey (the same idem_key once)');
$call('closure_followup', ['client_id' => $cc, 'followup_id' => (int)$fuIds[1], 'needs_service' => 'yes'], [], false, 'That follow-up is not due until');
$db->exec('UPDATE closure_followups SET due_on=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=' . (int)$fuIds[0]);
$r = $call('closure_followup', ['client_id' => $cc, 'followup_id' => (int)$fuIds[0], 'still_employed' => 'yes', 'still_using_tech' => 'no', 'independent' => '', 'needs_service' => 'yes', 'notes' => 'V435 notes', 'idem_key' => $key435()]);
$fr = $db->query('SELECT * FROM closure_followups WHERE id=' . (int)$fuIds[0])->fetch();
check($r['task_added'] === true && $r['message'] === 'Follow-up recorded. Because the client needs more service, a re-referral task was added.' && $fr['status'] === 'Completed'
    && (int)$fr['still_employed'] === 1 && (int)$fr['still_using_tech'] === 0 && $fr['independent'] === null
    && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='closefu-need-" . (int)$fuIds[0] . "'")->fetchColumn() === 1,
    'Q9: closure_followup records a due check with the answers and adds the re-referral task');
$call('closure_followup', ['client_id' => $cc, 'followup_id' => (int)$fuIds[0], 'needs_service' => 'no', 'idem_key' => $key435()], [], false, 'That follow-up was already recorded.');
$fb = $call('closure_followup', ['client_id' => $cc, 'followup_id' => (int)$fuIds[2], 'still_employed' => true], [], false, 'Still employed? Choose Yes, No or Not asked.');
check(isset($fb['errors']['still_employed']), 'Q9: an answer that is not "yes", "no" or "" (as both apps send) is refused on its field');
$fo = $call('closure_followup', ['client_id' => $phoneC, 'followup_id' => (int)$fuIds[2], 'needs_service' => 'no'], [], false, 'not on this client\'s record');
check($db->query('SELECT status FROM closure_followups WHERE id=' . (int)$fuIds[2])->fetchColumn() === 'Pending', 'Q9: another client\'s follow-up id is refused and records nothing');
$sb = $call('phone_survey', ['client_id' => $cc, 'satisfaction' => true, 'goals_met' => 5], [], false, 'Satisfaction: enter a rating from 1 to 10.');
check(isset($sb['errors']['satisfaction']), 'Q9: satisfaction true is refused on its field, not read as 1');
[$code, $out, $err] = $renderFresh435('client', ['id' => $cc]);
check($code === 0 && $err === '' && str_contains($out, 'Answered ') && str_contains($out, 'by phone: satisfaction 8 of 10, goals met 7 of 10') && str_contains($out, '<strong>30-day check</strong>, recorded ')
    && str_contains($out, '<strong>60-day check</strong>, due '), 'Q9: the client page draws the same lines from the shared state' . ($err ? ": $err" : ''));
[$code, , $err] = $renderFresh435('client', ['id' => $cc], ['closure_followup' => '1', 'followup_id' => (string)$fuIds[2], 'needs_service' => 'yes']);
check($code === 0 && $err === '' && $db->query('SELECT status FROM closure_followups WHERE id=' . (int)$fuIds[2])->fetchColumn() === 'Pending', 'Q9: the page also refuses a check not yet due' . ($err ? ": $err" : ''));
[$code, , $err] = $renderFresh435('client', ['id' => $cc], ['send_survey' => '1']);
check($code === 0 && $err === '' && (int)$db->query("SELECT COUNT(*) FROM surveys WHERE client_id=$cc AND channel='Email'")->fetchColumn() === 1, 'Q9: the page\'s Email the survey uses the same 30-day rule' . ($err ? ": $err" : ''));
$other = $client435('V435 Other Closed', ['status' => 'Closed - Other']);
$scope435();
try {
    $refusals = [];
    foreach ([['phone_survey', ['client_id' => $other, 'satisfaction' => 5, 'goals_met' => 5]], ['send_survey', ['client_id' => $cc]],
              ['closure_followup', ['client_id' => $cc, 'followup_id' => (int)$fuIds[2], 'needs_service' => 'no']]] as [$act, $body]) {
        [$stS, $dS] = $req435('POST', $act, $body);
        $refusals[] = $stS === 403 && $dS['error'] === 'That client is not on your caseload.';
    }
    check(!in_array(false, $refusals, true) && $db->query('SELECT status FROM closure_followups WHERE id=' . (int)$fuIds[2])->fetchColumn() === 'Pending',
        'Q9: a caseload-scoped user is refused another caseload\'s client for phone_survey, send_survey and closure_followup (403)');
} finally { $unscope435(); }

/* ---- Q10 and Q11: language, vision, On Hold, and the overview ---- */
$vc = $client435('V435 Vision Client', ['preferred_language' => 'en']);
$visionList = cms_list_items('vision_statuses');
$r = $call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'vision_onset_age' => 130], [], false, 'Age when vision loss began');
check(isset($r['errors']['vision_onset_age']), 'Q10: save_client refuses an onset age of 130 on errors.vision_onset_age');
$call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'preferred_language' => 'es', 'vision_status' => $visionList[1], 'vision_onset_age' => '12']);
$cv = $get435('client', ['id' => $vc])['client'];
check($cv['preferred_language'] === 'es' && $cv['vision_status'] === $visionList[1] && $cv['vision_onset_age'] === 12 && is_string($cv['created_at']),
    'Q10: language and vision are saved and returned by client (onset age as a number)');
$call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'phone' => '555-0135']);
$row = $db->query("SELECT preferred_language, vision_status, vision_onset_age FROM clients WHERE id=$vc")->fetch();
check($row['preferred_language'] === 'es' && $row['vision_status'] === $visionList[1] && (int)$row['vision_onset_age'] === 12, 'Q10: a 4.34.0-style save without the new keys leaves language and vision as they were');
$r = $call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'status' => 'On Hold', 'status_note' => 'V435 waiting', 'on_hold_until' => '2020-01-01'], [], false, 'return date');
$r2 = $call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'status' => 'On Hold'], [], false, 'Say why the client is going On Hold');
check(isset($r['errors']['on_hold_until']) && isset($r2['errors']['status_note']), 'Q10: the On Hold refusals are tied to on_hold_until and status_note');
$until = date('Y-m-d', strtotime('+14 days'));
$call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'status' => 'On Hold', 'status_note' => 'V435 waiting', 'on_hold_until' => $until]);
$db->exec("UPDATE clients SET on_hold_until='2020-01-01' WHERE id=$vc");   // overdue since
$call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'status' => 'On Hold', 'on_hold_until' => '', 'phone' => '555-0136']);
$cv = $get435('client', ['id' => $vc])['client'];
check($cv['on_hold_reason'] === 'V435 waiting' && $cv['on_hold_until'] === '2020-01-01' && $db->query("SELECT phone FROM clients WHERE id=$vc")->fetchColumn() === '555-0136',
    'Q10: an overdue stored return date never blocks another edit that sends on_hold_until "" (the date is kept), and client returns the reason and date');
[$code, $out, $err] = $renderFresh435('client_edit', ['id' => $vc], ['name' => 'V435 Vision Client', 'status' => 'On Hold', 'client_type' => 'VR', 'vision_status' => $visionList[0], 'vision_onset_age' => '130', 'preferred_language' => 'en']);
check($code === 0 && $err === '' && $db->query("SELECT vision_status FROM clients WHERE id=$vc")->fetchColumn() === $visionList[1]
    && str_contains($out . $pageSession435(), 'Age when vision loss began: enter a whole number of years from 0 to 120, or leave it blank.'),
    'Q10: the client form still refuses 130 on its field (shared helper) and saves nothing' . ($err ? ": $err" : ''));
$vt = $call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'vision_status' => 5], [], false, 'Vision status: choose one of the listed statuses');
$vf = $call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'vision_onset_age' => 12.5], [], false, 'Age when vision loss began');
$call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'vision_onset_age' => 14.0]);
check(isset($vt['errors']['vision_status']) && isset($vf['errors']['vision_onset_age']) && (int)$db->query("SELECT vision_onset_age FROM clients WHERE id=$vc")->fetchColumn() === 14,
    'Q10: a number as the vision status and 12.5 as the age are refused on their fields; 14.0 is saved as 14');
$loaded = (string)$db->query("SELECT updated_at FROM clients WHERE id=$vc")->fetchColumn();
$db->exec("UPDATE clients SET vision_status=" . $db->quote($visionList[2]) . ", updated_at=DATE_ADD(updated_at, INTERVAL 1 MINUTE) WHERE id=$vc");
$cf409 = $call('save_client', ['id' => $vc, 'name' => 'V435 Vision Client', 'loaded_at' => $loaded, 'orig' => ['vision_status' => $visionList[1]], 'vision_status' => $visionList[0]], [], false, 'Vision status');
check(!empty($cf409['conflict']) && in_array('Vision status', $cf409['changed'], true), 'Q10: a stale save names Vision status among the fields someone else changed');
// Q11: attendance.
$atc = $client435('V435 Attendance Client'); $atT = $track435($atc);
$session435($atc, $atT, time() - 300 * 3600, 1, ['status' => 'Completed']); $session435($atc, $atT, time() - 320 * 3600, 1, ['status' => 'No Show']);
$ar = $get435('client', ['id' => $atc]);
check($ar['client']['attendance'] === ['completed' => 1, 'no_show' => 1, 'cancelled' => 0] && $ar['attendance'] === $ar['client']['attendance'],
    'Q11: client carries attendance (one Completed, one No Show), inside the client and beside it');

/* ---- Q13: client list filters ---- */
$whoKey = 'clients_filter_1';
$db->exec("DELETE FROM settings WHERE name='$whoKey'");
$total = (int)$db->query('SELECT COUNT(*) FROM clients WHERE archived_at IS NULL')->fetchColumn();
$cf = $call('clients', [], ['with_filters' => '1']);
check($cf['filters']['who'] === '' && $cf['total'] === $total && $cf['filters']['who_choices'][0] === ['value' => '', 'label' => 'Everyone\'s clients']
    && in_array(['value' => 'u' . $u435, 'label' => 'V435 Synthetic Instructor'], $cf['filters']['who_choices'], true) && in_array('On Hold', $cf['filters']['statuses'], true),
    'Q13: clients?with_filters=1 with nothing remembered shows everyone\'s clients and the website\'s choices');
$plain = $call('clients');
check(!isset($plain['filters']) && $plain['total'] === $total, 'Q13: without with_filters (4.34.0 apps) the reply is unchanged: no filters key, no caseload filter');
$call('clients_filter', ['who' => 'nobody'], [], false, 'Caseload: choose one of the listed caseloads.');
$call('clients_filter', ['who' => 'me']);
$mine = $call('clients', [], ['with_filters' => '1']);
check($mine['filters']['who'] === 'me' && $mine['total'] === (int)$db->query('SELECT COUNT(*) FROM clients WHERE archived_at IS NULL AND assigned_to=1')->fetchColumn()
    && $call('clients')['total'] === $total, 'Q13: clients_filter remembers "My clients"; the next with_filters list opens on it, and an older app\'s list does not change');
[$code, $out, $err] = $renderFresh435('clients', []);
check($code === 0 && $err === '' && str_contains($out, '<option value="me" selected>') && str_contains($out, 'V435 Group Own') && !str_contains($out, 'V435 Group Alpha'),
    'Q13: the website opens on the same remembered caseload' . ($err ? ": $err" : ''));
$st = $call('clients', [], ['with_filters' => '1', 'who' => '', 'status' => 'On Hold']);
check(in_array($vc, array_column($st['clients'], 'id'), true) && !array_filter($st['clients'], fn($c) => $c['status'] !== 'On Hold'), 'Q13: status filters the list');
$db->prepare("UPDATE training_tracks SET funding_agency='V435 Agency' WHERE id=?")->execute([$atT]);
$ag = $call('clients', [], ['with_filters' => '1', 'who' => '', 'agency' => 'V435 Agency']);
[$code, $out, $err] = $renderFresh435('clients', ['who' => '', 'agency' => 'V435 Agency']);
check(array_column($ag['clients'], 'id') === [$atc] && in_array('V435 Agency', $ag['filters']['agencies'], true) && $code === 0 && $err === '' && str_contains($out, 'V435 Attendance Client') && str_contains($out, 'Clients (1)'),
    'Q13: agency matches a client by their track, in the app and on the website (shared filter SQL)' . ($err ? ": $err" : ''));
$scope435();
try {
    $sc = $call('clients', [], ['with_filters' => '1', 'who' => 'u' . $u435]);
    check($sc['filters']['who'] === 'me' && count($sc['filters']['who_choices']) === 3 && !array_filter($sc['clients'], fn($c) => (int)$db->query('SELECT assigned_to FROM clients WHERE id=' . (int)$c['id'])->fetchColumn() !== 1),
        'Q13: a caseload-scoped user cannot choose another instructor (falls back to "My clients") and sees only their own clients');
    $call('clients_filter', ['who' => 'u' . $u435], [], false, 'Caseload: choose one of the listed caseloads.');
    check($call('clients_filter', ['who' => 'unassigned'])['who'] === 'unassigned', 'Q13: clients_filter refuses another instructor for a caseload-scoped user and accepts their own choices');
} finally { $unscope435(); }

// Put back every setting this file changed (the remembered caseload, the AI switches).
$db->exec('DELETE FROM settings');
$restore435 = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
foreach ($settings435 as $name => $value) $restore435->execute([$name, $value]);
$db->exec("DELETE FROM `groups` WHERE id=$g435");
putenv('CMS_TEST_SESSION_ADD');
check($db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) == $settings435, 'v435: every setting is as it was before this file');
