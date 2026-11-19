<?php
// Included by full_schema.php after parity_api_cases.php, against its synthetic database only:
// 4.34.0, the apps behave as the website does (PARITY-AUDIT-2026-11-19). Each block is one plan
// item's server part. AI cases use a substituted transport (as at_extended_cases.php): nothing is
// sent anywhere, and the reply the "provider" gives is set per case.
require_once $root . '/public/inc/curriculum_ai.php';
require_once $root . '/public/inc/kb_search.php';
require_once $root . '/public/inc/risk_ai.php';
$GLOBALS['CURRENT_PERMS'] = array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED'] = false;
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
// Every setting this file changes is put back at its end, so later files never inherit them.
$settings434 = $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("DELETE FROM login_attempts");
$today434 = date('Y-m-d');
$audits434 = fn(string $action, string $like = '%'): int => (int)$db->query('SELECT COUNT(*) FROM audit_log WHERE action=' . $db->quote($action) . ' AND detail LIKE ' . $db->quote($like))->fetchColumn();
// A GET request (the API's form actions answer GET differently from POST).
file_put_contents($tmp . '/v434_get.php', '<?php $_GET = json_decode($argv[1], true); $_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . str_repeat("a", 64); $_SERVER["REQUEST_METHOD"] = "GET"; require ' . var_export($tmp . '/api.php', true) . ';');
$get434 = function (string $action, array $query = []) use ($tmp): array {
    putenv('CMS_TEST_INPUT={}');
    $p = proc_open([PHP_BINARY, '-d', 'sendmail_path=/usr/bin/true', $tmp . '/v434_get.php', json_encode(['action' => $action] + $query)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $d = json_decode($out, true);
    if (!is_array($d) || empty($d['ok'])) throw new RuntimeException("GET $action failed: $err $out");
    return $d;
};
// Synthetic people and records for this file.
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V434 Synthetic Instructor','v434-instructor@example.invalid','test','instructor')"); $u434 = (int)$db->lastInsertId();
$newClient434 = function (string $name, array $cols = []) use ($db): int {
    $cols = ['name' => $name] + $cols;
    $db->prepare('INSERT INTO clients (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$newTrack434 = function (int $client, array $cols = []) use ($db): int {
    $cols = $cols + ['client_id' => $client, 'title' => 'V434 synthetic track', 'auth_number' => 'V434-AUTH', 'funding_agency' => 'State VR', 'authorized_hours' => 200, 'hourly_rate' => 50, 'status' => 'Active'];
    $db->prepare('INSERT INTO training_tracks (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$c434 = $newClient434('V434 Synthetic Client', ['assigned_to' => $u434]);
$t434 = $newTrack434($c434);

/* ---- P2: free text with section text is refused (client.php's wording) ---- */
$freeMsg = 'Free text is chosen as the note format, but the structured sections still hold text';
$notesBefore = (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$c434")->fetchColumn();
$r = $call('add_note', ['client_id' => $c434, 'subject' => 'V434 free note', 'note_format' => 'free', 'body' => 'x', 'sec1' => 'y'], [], false, $freeMsg);
check(isset($r['errors']['note_content']) && str_contains($r['errors']['note_content'], $freeMsg)
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$c434")->fetchColumn() === $notesBefore,
    'P2: add_note refuses free text with a section still holding text, tied to note_content, and saves nothing');
$db->prepare("INSERT INTO client_notes (client_id,subject,body,note_format,note_type,birp_behavior,birp_intervention,birp_response,birp_plan,author_id) VALUES (?,'V434 structured','Behavior:\nb','BIRP','Case Note','b','i','r','p',1)")->execute([$c434]);
$structNote = (int)$db->lastInsertId();
$call('update_note', ['id' => $structNote, 'subject' => 'V434 structured', 'note_format' => 'free', 'body' => 'Now free text']);
check($db->query("SELECT note_format FROM client_notes WHERE id=$structNote")->fetchColumn() === 'free',
    'P2: update_note from structured to free text that sends no sections is saved (stored sections are not counted)');
$call('update_note', ['id' => $structNote, 'subject' => 'V434 structured', 'note_format' => 'free', 'body' => 'Now free text', 'sec2' => 'left over'], [], false, $freeMsg);

/* ---- P43: a homework due date that cannot be used is refused on its item ---- */
$r = $call('add_note', ['client_id' => $c434, 'track_id' => $t434, 'subject' => 'V434 homework note', 'body' => 'Synthetic', 'homework' => [['title' => 'Good', 'due' => ''], ['title' => 'Bad date', 'due' => '0026-01-01']]], [], false, 'Homework item 2: enter a valid due date');
check(isset($r['errors']['hw_due_2']) && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='V434 homework note'")->fetchColumn() === 0,
    'P43: add_note refuses an invalid homework due date, naming the item (errors.hw_due_2), and saves nothing');

/* ---- P3: client dates checked by save_client ---- */
$r = $call('save_client', ['id' => $c434, 'name' => 'V434 Synthetic Client', 'ipe_date' => date('Y-m-d', strtotime('+3 days'))], [], false, 'IPE date');
$call('save_client', ['id' => $c434, 'name' => 'V434 Synthetic Client', 'dob' => '2001-02-03', 'ipe_date' => '2025-01-15', 'ipe_amended_on' => '2025-06-01']);
$row = $db->query("SELECT dob, ipe_date, ipe_amended_on FROM clients WHERE id=$c434")->fetch();
check($row['dob'] === '2001-02-03' && $row['ipe_date'] === '2025-01-15' && $row['ipe_amended_on'] === '2025-06-01', 'P3: save_client refuses a future IPE date naming it, and stores valid dob and IPE dates');

/* ---- P4: the new-client duplicate check ---- */
// A required custom field left by an earlier case file would refuse every new client here.
$cfRequired434 = $db->query('SELECT id FROM custom_fields WHERE required = 1')->fetchAll(PDO::FETCH_COLUMN);
$db->exec('UPDATE custom_fields SET required = 0');
$clientsBefore = (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$r = $call('save_client', ['name' => 'V434 Synthetic Client', 'dup_check' => 1, 'state' => 'IA'], [], false, 'This is a different person');
check(!empty($r['duplicate']) && isset($r['errors']['name']) && (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn() === $clientsBefore,
    'P4: a new client matching an existing one is refused with duplicate:true and errors.name when dup_check is sent; nothing is saved');
$r = $call('save_client', ['name' => 'V434 Synthetic Client', 'dup_check' => 1, 'not_duplicate' => 1, 'state' => 'IA']);
check($r['client_id'] > 0 && (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn() === $clientsBefore + 1, 'P4: with not_duplicate the new client is saved');
$r = $call('save_client', ['name' => 'V434 Synthetic Client', 'state' => 'IA']);
check($r['client_id'] > 0 && is_string($r['duplicate_warning']) && $r['duplicate_warning'] !== '', 'P4: without dup_check (older apps) the client is saved and duplicate_warning says so');
$r = $call('save_client', ['name' => 'V434 Pre-ETS Synthetic', 'client_type' => 'Pre-ETS', 'dob' => date('Y-m-d', strtotime('-30 years')), 'state' => 'IA']);
check(is_string($r['advisory']) && str_contains($r['advisory'], 'this client is 30 years old') && str_contains($r['advisory'], 'Pre-ETS generally serves students ages 14–21'),
    'P4: a Pre-ETS client aged 30 gets the website\'s advisory');
$preEts = (int)$r['client_id'];
$db->exec("UPDATE clients SET email='v434-preets@example.invalid', email_verified=0 WHERE id=$preEts");
$call('save_client', ['id' => $preEts, 'name' => 'V434 Pre-ETS Synthetic', 'email' => 'v434-preets@example.invalid', 'email_verified' => 1]);
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='client.email_confirm' AND entity_id=$preEts")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='client.update' AND entity_id=$preEts AND detail LIKE '%client email confirmed%'")->fetchColumn() === 0,
    'P4: confirming the client\'s email writes its own client.email_confirm entry, as client_edit.php does');

if ($cfRequired434) $db->exec('UPDATE custom_fields SET required = 1 WHERE id IN (' . implode(',', array_map('intval', $cfRequired434)) . ')');

/* ---- P6: guardian consent: the pending id, and a stale consent_seen refused ---- */
$db->prepare("INSERT INTO consent_forms (client_id,guardian_name,guardian_email,token) VALUES (?,'V434 Guardian','v434-guardian@example.invalid',?)")->execute([$c434, str_repeat('b', 48)]);
$pend434 = (int)$db->lastInsertId();
$g = $get434('request_consent', ['client_id' => $c434]);
check(($g['consent']['pending_id'] ?? null) === $pend434, 'P6: request_consent GET carries the pending request\'s id');
$formsBefore = (int)$db->query("SELECT COUNT(*) FROM consent_forms WHERE client_id=$c434")->fetchColumn();
$r = $call('request_consent', ['client_id' => $c434, 'guardian_name' => 'V434 Guardian', 'guardian_email' => 'v434-guardian@example.invalid', 'consent_seen' => 0], [], false, 'already emailed');
check(!empty($r['consent_changed']) && (int)$db->query("SELECT COUNT(*) FROM consent_forms WHERE client_id=$c434")->fetchColumn() === $formsBefore,
    'P6: a stale consent_seen is refused with consent_changed and no second request');
$sent6 = $call('request_consent', ['client_id' => $c434, 'guardian_name' => 'V434 Guardian', 'guardian_email' => 'v434-guardian@example.invalid', 'consent_seen' => $pend434]);
check(!empty($sent6['ok']) && (int)$db->query("SELECT MAX(id) FROM consent_forms WHERE client_id=$c434")->fetchColumn() > $pend434,
    'P6: consent_seen equal to the pending id sends the new request');

/* ---- P7: search as search.php ---- */
$db->prepare("UPDATE clients SET counselor_name='Vcounsel Synthetic434' WHERE id=?")->execute([$c434]);
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name) VALUES('Synthetic','Synthetic','Body mentions zorblat434','R','A','r@example.invalid','5','V434 referral')");
$db->prepare("INSERT INTO smart_goals (track_id,specific_goal,measurable_metric) VALUES (?,'V434 goal','Metric quuxmetric434')")->execute([$t434]);
$goal434 = (int)$db->lastInsertId();
$ins = $db->prepare("INSERT INTO client_notes (client_id,subject,body,author_id) VALUES (?,?,'Synthetic capnote434 body',1)");
for ($i = 0; $i < 30; $i++) $ins->execute([$c434, 'V434 capped ' . $i]);
$groups = fn(array $r): array => array_column($r['results'], null, 'group');
$s1 = $groups($call('search', [], ['q' => 'Synthetic434']));
check(in_array($c434, array_column($s1['Clients']['items'] ?? [], 'id'), true), 'P7: search finds a client by counselor name');
$s2 = $groups($call('search', [], ['q' => 'zorblat434']));
check(count($s2['Referrals']['items'] ?? []) === 1, 'P7: search finds a referral by its body');
$s3 = $groups($call('search', [], ['q' => 'quuxmetric434']));
$gi = $s3['Goals']['items'][0] ?? [];
check(($gi['id'] ?? 0) === $goal434 && ($gi['kind'] ?? '') === 'goal' && ($gi['track_id'] ?? 0) === $t434 && ($gi['client_id'] ?? 0) === $c434, 'P7: search finds a goal by its measure, with its track and client');
$s4 = $groups($call('search', [], ['q' => 'capnote434']));
check(count($s4['Notes']['items']) === 25 && $s4['Notes']['capped'] === true && ($s3['Goals']['capped'] ?? null) === false, 'P7: 30 matching notes give 25 and capped:true; a short group is not capped');
$allItems = array_merge(...array_map(fn($g) => $g['items'], array_merge(array_values($s1), array_values($s2), array_values($s3), array_values($s4))));
check($allItems && !array_filter($allItems, fn($it) => !is_int($it['id']) || !is_string($it['title'])), 'P7: every search item has an int id and a string title');

/* ---- P8: completing a session credits the instructor with the website's description ---- */
$addSess434 = function (int $client, int $track, int $user, int $hoursAgo, array $extra = []) use ($db): int {
    $cols = ['client_id' => $client, 'track_id' => $track, 'user_id' => $user, 'start_at' => date('Y-m-d H:i:s', time() - $hoursAgo * 3600), 'end_at' => date('Y-m-d H:i:s', time() - ($hoursAgo - 1) * 3600)] + $extra;
    $db->prepare('INSERT INTO scheduled_sessions (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$s434 = $addSess434($c434, $t434, $u434, 30, ['notes' => 'Synthetic notes', 'location' => 'Synthetic room']);
$call('complete_session', ['session_id' => $s434, 'outcome' => 'completed']);
$wl = $db->query("SELECT w.created_by, w.description FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$s434")->fetch();
check($wl && (int)$wl['created_by'] === $u434 && $wl['description'] === 'Training session: Synthetic notes (Synthetic room)',
    'P8: an admin completing an instructor\'s session credits the instructor, with "Training session: notes (location)" and no "(logged from mobile)"');
$sg434 = $addSess434($c434, $t434, $u434, 60, ['notes' => 'Group notes', 'group_uid' => 'v434group']);
$call('complete_session', ['session_id' => $sg434, 'outcome' => 'completed']);
check(str_starts_with((string)$db->query("SELECT w.description FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$sg434")->fetchColumn(), 'Group session: Group notes'),
    'P8: a group sitting is described as "Group session"');
// Voice describes it the same way and audits the completion against the session.
$sv434 = $addSess434($c434, $t434, $u434, 90, ['notes' => 'Voice notes']);
$vr = voice_execute('complete_session', ['session_id' => $sv434], ['id' => 1, 'name' => 'Test Admin', 'role' => 'admin']);
check(!empty($vr['ok']) && $db->query("SELECT w.description FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$sv434")->fetchColumn() === 'Training session: Voice notes'
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='session.complete' AND entity='session' AND entity_id=$sv434")->fetchColumn() === 1,
    'P8: voice completion uses the website\'s description and audits against the session' . (empty($vr['ok']) ? ': ' . json_encode($vr) : ''));

/* ---- P10: closing a client request withdraws its offered times ---- */
$db->prepare("INSERT INTO portal_requests (client_id,type,details) VALUES (?,'appointment','V434 request')")->execute([$c434]); $pr434 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO portal_request_offers (request_id,start_at,end_at,user_id) VALUES (?,?,?,1)")->execute([$pr434, date('Y-m-d 10:00:00', strtotime('+5 days')), date('Y-m-d 11:00:00', strtotime('+5 days'))]); $off434 = (int)$db->lastInsertId();
$call('portal_request_update', ['id' => $pr434, 'to' => 'Done']);
$w1 = $db->query("SELECT status FROM portal_request_offers WHERE id=$off434")->fetchColumn();
$call('portal_request_update', ['id' => $pr434, 'to' => 'In Progress']);
check($w1 === 'Withdrawn' && $db->query("SELECT status FROM portal_request_offers WHERE id=$off434")->fetchColumn() === 'Withdrawn',
    'P10: closing a request withdraws its offered time, and reopening it leaves the offer withdrawn');

/* ---- P11: the track form's billing choices and defaults; money typed with "$" ---- */
$cFee = $newClient434('V434 Fee Synthetic');
$db->prepare("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,client_id,quoted_fee) VALUES('S','S','S','R','A','r@example.invalid','5','V434 Fee Synthetic',?,40)")->execute([$cFee]);
$tf = $get434('save_track', ['client_id' => $cFee]);
$rsaOk = true; foreach ($tf['service_types'] as $st) $rsaOk = $rsaOk && ($tf['rsa_defaults'][$st] ?? null) === rsa_default_for($st);
check(($tf['defaults']['billing_mode'] ?? '') === 'private' && ($tf['defaults']['hourly_rate'] ?? '') === '40.00' && count($tf['billing_modes']) === 3
    && $tf['billing_modes'][0] === ['value' => 'vr', 'label' => BILLING_MODES['vr']] && is_string($tf['private_rate']) && $rsaOk && track_new_defaults($cFee)['billing_mode'] === 'private',
    'P11: the new-track form gives Who pays choices, the private rate, RSA defaults by service type, and starts Private pay at the quoted fee');
$tAmt = $call('save_track', ['client_id' => $c434, 'title' => 'V434 money track', 'auth_number' => 'V434-M', 'funding_agency' => 'State VR', 'authorized_hours' => '10', 'hourly_rate' => '$1,050.00', 'authorized_amount' => '$4,500', 'mileage_rate' => '0']);
$tr = $db->query('SELECT authorized_amount, hourly_rate FROM training_tracks WHERE id=' . (int)$tAmt['id'])->fetch();
check((float)$tr['authorized_amount'] === 4500.0 && (float)$tr['hourly_rate'] === 1050.0, 'P11: "$4,500" and "$1,050.00" are stored as 4500.00 and 1050.00');
$db->prepare("INSERT INTO amendment_requests (track_id,client_id,requested_on,status) VALUES (?,?,CURDATE(),'Approved')")->execute([(int)$tAmt['id'], $c434]); $ar434 = (int)$db->lastInsertId();
$call('save_track', ['id' => (int)$tAmt['id'], 'title' => 'V434 money track', 'auth_number' => 'V434-M', 'funding_agency' => 'State VR', 'authorized_hours' => '20', 'hourly_rate' => '1050', 'mileage_rate' => '0', 'amend_request' => $ar434]);
check((int)$db->query("SELECT amendment_id FROM amendment_requests WHERE id=$ar434")->fetchColumn() === (int)$db->query('SELECT MAX(id) FROM auth_amendments WHERE track_id=' . (int)$tAmt['id'])->fetchColumn()
    && (int)$db->query("SELECT amendment_id FROM amendment_requests WHERE id=$ar434")->fetchColumn() > 0, 'P11: an amendment entered from an approved request is linked to it');
$db->prepare("INSERT INTO incoming_authorizations (auth_number,client_id) VALUES ('V434-INC',?)")->execute([$c434]); $inc434 = (int)$db->lastInsertId();
$tInc = $call('save_track', ['client_id' => $c434, 'title' => 'V434 from email', 'auth_number' => 'V434-INC', 'funding_agency' => 'State VR', 'authorized_hours' => '5', 'hourly_rate' => '50', 'mileage_rate' => '0', 'incoming_auth' => $inc434]);
$ia = $db->query("SELECT status, track_id FROM incoming_authorizations WHERE id=$inc434")->fetch();
check($ia['status'] === 'Track created' && (int)$ia['track_id'] === (int)$tInc['id'], 'P11: a track created from an authorization email marks the email handled');

/* ---- P12 / P13 / P14: booking ---- */
$day = date('Y-m-d', strtotime('+9 days'));
$book = fn(array $extra = []) => $extra + ['client_id' => $c434, 'staff_id' => $u434, 'date' => $day, 'time' => '09:00', 'duration' => 1, 'idem_key' => bin2hex(random_bytes(8))];
$call('schedule_session', $book());
$r = $call('schedule_session', $book(), [], false, 'already on the schedule');
check(!empty($r['duplicate']) && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$c434 AND start_at=" . $db->quote($day . ' 09:00:00'))->fetchColumn() === 1,
    'P12: the same booking sent again with a new key is refused 409 duplicate, one session kept');
$sessBefore = (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$c434")->fetchColumn();
$r = $call('schedule_session', $book(['date' => date('Y-m-d', strtotime($day . ' -7 days')), 'repeat_weeks' => 2]), [], false, 'already has a session with');
check(isset($r['errors']['date']) && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$c434")->fetchColumn() === $sessBefore,
    'P12: a series overlapping one booked date is refused naming the date (errors.date), and nothing is saved');
$vDup = voice_execute('schedule_session', ['client_id' => $c434, 'start_at' => $day . ' 09:00', 'duration' => 60], ['id' => $u434, 'name' => 'V434 Synthetic Instructor', 'role' => 'instructor']);
check(empty($vDup['ok']) && str_contains((string)($vDup['error'] ?? ''), 'already on the schedule'), 'P12: voice refuses a session already on the schedule' . (empty($vDup['ok']) ? '' : ': ' . json_encode($vDup)));
$cWait = $newClient434('V434 Waitlist Synthetic', ['status' => 'Waitlist']);
$choices = array_column($call('client_choices')['clients'], null, 'id');
check(($choices[$cWait]['bookable'] ?? null) === false && ($choices[$c434]['bookable'] ?? null) === true, 'P13: client_choices marks a Waitlist client not bookable and an open one bookable');
$r = $call('schedule_session', ['client_id' => $cWait, 'date' => $day, 'time' => '13:00', 'duration' => 1], [], false, 'on the waitlist or closed');
check(isset($r['errors']['client_id']), 'P13: booking a Waitlist client is refused, tied to client_id');
$call('schedule_session', $book(['time' => '15:00', 'is_remote' => true, 'location' => 'Synthetic remote room']));
check($db->query("SELECT location FROM scheduled_sessions WHERE client_id=$c434 AND start_at=" . $db->quote($day . ' 15:00:00'))->fetchColumn() === 'Synthetic remote room', 'P14: a remote booking keeps its location');
$tExp = $newTrack434($c434, ['expiration_date' => date('Y-m-d', strtotime('-1 day'))]);
$r = $call('schedule_session', $book(['time' => '17:00', 'track_id' => $tExp, 'authorization_ack' => false]), [], false, 'Authorization review');
$call('schedule_session', $book(['time' => '17:00', 'track_id' => $tExp, 'authorization_ack' => true, 'authorization_ack_hash' => $r['authorization_hash']]));
check($audits434('session.schedule', '%authorization exception approved:%') >= 1, 'P14: an approved booking is audited "authorization exception approved:" with the warnings');
check(schedule_review_prefix(false) === 'Check before booking: ' && str_starts_with(schedule_review_message(true, ['x']), 'Authorization review: '), 'P14: the review prefix is shared with the website');

/* ---- P16: log_hours: travel only, note only, the website's messages, the homework reminder ---- */
$r = $call('log_hours', ['track_id' => $t434, 'hours' => 0, 'mileage' => 12, 'description' => 'Synthetic travel', 'date' => $today434]);
check($r['hours_logged'] === true && (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$t434 AND hours=0 AND mileage=12")->fetchColumn() === 1, 'P16: 0 hours with miles is logged as travel only');
check(is_string($r['reminder']) && str_starts_with($r['reminder'], 'Reminder: no homework is assigned on this track.'), 'P16: a track with no homework gets the reminder');
$logsBefore = (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$t434")->fetchColumn();
$r = $call('log_hours', ['track_id' => $t434, 'hours' => '', 'session_note' => 'V434 note only', 'date' => $today434]);
check($r['note_saved'] === true && $r['hours_logged'] === false && (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$t434")->fetchColumn() === $logsBefore
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE body='V434 note only'")->fetchColumn() === 1, 'P16: hours blank with a session note saves only the note');
$noteKey = '44444444-4444-4444-8444-434343434343';
$call('log_hours', ['track_id' => $t434, 'hours' => '', 'session_note' => 'V434 note sent twice', 'date' => $today434, 'idem_key' => $noteKey]);
$again16 = $call('log_hours', ['track_id' => $t434, 'hours' => '', 'session_note' => 'V434 note sent twice', 'date' => $today434, 'idem_key' => $noteKey]);
check(!empty($again16['deduplicated']) && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE body='V434 note sent twice'")->fetchColumn() === 1,
    'P16: a note-only entry sent twice with the same key saves one note');
$r = $call('log_hours', ['track_id' => $t434, 'hours' => 0, 'description' => 'x'], [], false, 'or 0 with miles for travel only');
check(isset($r['errors']['hours']), 'P16: 0 hours with no miles is refused with the website\'s text, tied to hours');
$r = $call('log_hours', ['track_id' => $t434, 'hours' => 1], [], false, 'Describe the activity');
check(isset($r['errors']['description']), 'P16: hours with no description are refused, tied to description');
$db->prepare("INSERT INTO homework (track_id,title,assigned_at) VALUES (?,'V434 practice',CURDATE())")->execute([$t434]);
check($call('log_hours', ['track_id' => $t434, 'hours' => 1, 'description' => 'Synthetic', 'date' => $today434])['reminder'] === null, 'P16: no reminder once the track has homework');

/* ---- P17 / P18: device follow-ups ---- */
$db->exec("INSERT INTO equipment (name,status) VALUES ('V434 synthetic device','On Loan')"); $eq434 = (int)$db->lastInsertId();
$fu434 = function () use ($db, $eq434, $c434): int {
    $db->prepare("INSERT INTO equipment_loans (equipment_id,client_id,loaned_at) VALUES (?,?,CURDATE())")->execute([$eq434, $c434]); $loan = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO equipment_followups (loan_id,client_id,equipment_id,interval_days,due_date) VALUES (?,?,?,30,CURDATE())")->execute([$loan, $c434, $eq434]);
    return (int)$db->lastInsertId();
};
$f1 = $fu434();
$r = $call('save_followup', ['id' => $f1], [], false, 'Still in use: choose Yes, No or Unknown.');
check(isset($r['errors']['in_use']) && $db->query("SELECT status FROM equipment_followups WHERE id=$f1")->fetchColumn() === 'Pending', 'P18: a follow-up with no answer is refused, tied to in_use');
$call('save_followup', ['id' => $f1, 'in_use' => 'unknown']);
$f2 = $fu434(); $call('save_followup', ['id' => $f2, 'in_use' => '']);
check($db->query("SELECT CONCAT(status, '|', COALESCE(in_use, 'null')) FROM equipment_followups WHERE id=$f1")->fetchColumn() === 'Completed|null'
    && $db->query("SELECT CONCAT(status, '|', COALESCE(in_use, 'null')) FROM equipment_followups WHERE id=$f2")->fetchColumn() === 'Completed|null', 'P18: "unknown" and "" are saved as use unknown');
$db->exec("UPDATE users SET is_active=0 WHERE id=$u434");
$f3 = $fu434(); $call('save_followup', ['id' => $f3, 'in_use' => 'no']);
check((int)$db->query("SELECT assigned_to FROM tasks WHERE auto_key='abandon-$f3'")->fetchColumn() === 1, 'P17: the abandoned-device task goes to the recorder when the instructor is deactivated');
$db->exec("UPDATE users SET is_active=1 WHERE id=$u434");

/* ---- P19: the website's sentence for alerts left ---- */
$db->exec("INSERT INTO notifications(user_id,message) VALUES(1,'V434 alert one'),(1,'V434 alert two')"); $al434 = (int)$db->lastInsertId();
$r = $call('mark_notifications_read', ['ids' => (string)$al434]);
check(str_contains($r['message'], ' more unread alert') && str_ends_with($r['message'], ' listed now.'), 'P19: the reply says how many more unread alerts are listed now');
$call('mark_notifications_read', ['all' => true]);

/* ---- P22: incident types from the agency's list; no future date ---- */
$db->prepare("INSERT INTO settings(name,value) VALUES ('list_incident_types',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([json_encode(['Synthetic434 type', 'Injury', 'Other'])]);
$types434 = $call('incidents')['types'];
check($types434 === cms_list_items('incident_types') && in_array('Synthetic434 type', $types434, true) && !in_array('Near miss', $types434, true), 'P22: incidents lists the agency\'s own types');
$call('add_incident', ['incident_date' => $today434, 'type' => 'Synthetic434 type', 'severity' => 'Low', 'description' => 'V434 incident', 'idem_key' => bin2hex(random_bytes(8))]);
$r = $call('add_incident', ['incident_date' => $today434, 'type' => 'Near miss', 'severity' => 'Low', 'description' => 'V434 incident', 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Choose a type from the list.');
check(isset($r['errors']['type']), 'P22: an agency-added type is accepted and a removed built-in one is refused');
$r = $call('add_incident', ['incident_date' => date('Y-m-d', strtotime('+2 days')), 'type' => 'Other', 'severity' => 'Low', 'description' => 'V434 incident', 'idem_key' => bin2hex(random_bytes(8))], [], false, 'cannot be in the future');
$call('add_incident', ['incident_date' => date('Y-m-d', strtotime('+1 day')), 'type' => 'Other', 'severity' => 'Low', 'description' => 'V434 incident tomorrow', 'idem_key' => bin2hex(random_bytes(8))]);
check(isset($r['errors']['incident_date']), 'P22: an incident date after tomorrow is refused, tied to the date; tomorrow passes (time-zone slack for queued entries)');
$call('add_incident', ['incident_date' => $today434, 'severity' => 'Low', 'description' => 'V434 incident', 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Choose a type from the list.');
$db->exec("DELETE FROM settings WHERE name='list_incident_types'");

/* ---- P23: a guardian consent signed before the client turned 18 ---- */
$cAdult = $newClient434('V434 Adult Synthetic', ['dob' => date('Y-m-d', strtotime('-19 years'))]);
$db->prepare("INSERT INTO consent_forms (client_id,guardian_name,guardian_email,token,signed_at,signed_name) VALUES (?,'V434 Guardian','g@example.invalid',?,?,'V434 Guardian')")->execute([$cAdult, str_repeat('c', 48), date('Y-m-d H:i:s', strtotime('-2 years'))]);
$adultRow = array_values(array_filter($call('consents_due')['consents'], fn($r) => $r['client_id'] === $cAdult))[0] ?? null;
check($adultRow && $adultRow['kind'] === 'Adult' && $adultRow['date'] === date('Y-m-d', strtotime(date('Y-m-d', strtotime('-19 years')) . ' +18 years'))
    && $adultRow['label'] === 'Signed by a guardian before the client turned 18' && $adultRow['action'] === "Request the client's own consent",
    'P23: a guardian consent signed before the 18th birthday is listed as Adult, dated the birthday');

/* ---- P27: a sign-in code that is not 6 digits uses no try ---- */
$pw434 = 'Synthetic-pass-434!';
$db->prepare("INSERT INTO users (name,email,password_hash,role) VALUES ('V434 Sign-in Synthetic','v434-signin@example.invalid',?,'instructor')")->execute([password_hash($pw434, PASSWORD_DEFAULT)]);
$signUser = (int)$db->lastInsertId();
$db->exec("DELETE FROM login_attempts");
$login = $call('login', ['email' => 'v434-signin@example.invalid', 'password' => $pw434]);
$r = $call('verify', ['email' => 'v434-signin@example.invalid', 'code' => '1234567', 'login_challenge' => $login['login_challenge'] ?? ''], [], false, 'Enter the 6-digit code from the email or from your authenticator app.');
$tries = fn() => (int)$db->query("SELECT attempts FROM login_otps WHERE user_id=$signUser ORDER BY id DESC LIMIT 1")->fetchColumn();
check(isset($r['errors']['code']) && $tries() === 0, 'P27: a 7-digit code is refused 422 and uses none of the code\'s tries');
$r = $call('verify', ['email' => 'v434-signin@example.invalid', 'code' => '000000', 'login_challenge' => $login['login_challenge'] ?? ''], [], false, 'That code is not correct.');
check(($r['tries_left'] ?? null) === 4 && str_contains($r['error'], '4 tries left with this code.'), 'P27: a wrong code says how many tries are left (tries_left), in verify.php\'s words');
// Without this sign-in's challenge (wrong or missing) the reply is the same whoever the address
// belongs to: an account with a sign-in pending, an unknown address, an authenticator account.
$db->prepare("INSERT INTO users (name,email,password_hash,role,totp_enabled,totp_secret) VALUES ('V434 Authenticator Synthetic','v434-totp@example.invalid',?,'instructor',1,'JBSWY3DPEHPK3PXP')")->execute([password_hash($pw434, PASSWORD_DEFAULT)]);
try { $call('login', ['email' => 'v434-totp@example.invalid', 'password' => $pw434]); } catch (RuntimeException $e) { /* the sign-in need not start for this check */ }
$db->exec("DELETE FROM login_attempts");
$replies27 = [];
foreach (['v434-signin@example.invalid', 'v434-nobody@example.invalid', 'v434-totp@example.invalid'] as $who) {
    foreach (['wrong-challenge', null] as $ch) {
        $body = ['email' => $who, 'code' => '000000'] + ($ch !== null ? ['login_challenge' => $ch] : []);
        $replies27['wrong'][] = json_encode($call('verify', $body, [], false, 'not correct'));
        $replies27['short'][] = json_encode($call('verify', ['code' => '12345'] + $body, [], false, 'Enter the 6-digit code'));
    }
}
$db->exec("DELETE FROM login_attempts");
check(count(array_unique($replies27['wrong'])) === 1 && !str_contains($replies27['wrong'][0], 'tries_left') && count(array_unique($replies27['short'])) === 1
    && str_contains($replies27['short'][0], 'from the email or from your authenticator app') && $tries() === 1,
    'P27: without the sign-in\'s challenge the reply is identical for a pending, unknown or authenticator account, with no tries_left and no try used');
$db->exec("DELETE FROM login_attempts");

/* ---- P33 / P42: me and dashboard ---- */
$me = $call('me');
check(array_key_exists('ai_redaction', $me) && $me['ai_redaction'] === ai_redaction_on(), 'P33: me says whether client identifiers are stripped (ai_redaction)');
check($call('dashboard')['auth_soon_days'] === (int)cms_number('auth_soon_days'), 'P42: dashboard carries auth_soon_days');

/* ---- P36: reopening a device request at its furthest step ---- */
$db->prepare("INSERT INTO device_requests (client_id,device_name,status,ordered_at) VALUES (?,'V434 synthetic device','Cancelled',CURDATE())")->execute([$c434]); $dr434 = (int)$db->lastInsertId();
$r = $call('device_request_update', ['id' => $dr434, 'op' => 'status', 'to' => 'Requested', 'idem_key' => bin2hex(random_bytes(8))]);
check($r['status'] === 'Ordered' && $db->query("SELECT status FROM device_requests WHERE id=$dr434")->fetchColumn() === 'Ordered'
    && $audits434('device.status', 'reopened as Ordered (mobile)') === 1, 'P36: a cancelled request with an order date reopens as Ordered, said and audited');

/* ---- P37: the loan period and the return date ---- */
$db->exec("INSERT INTO settings(name,value) VALUES ('num_loan_due_days','14') ON DUPLICATE KEY UPDATE value='14'");
$loanDays = (int)cms_number('loan_due_days');
check($call('form_options')['numbers']['loan_due_days'] === $loanDays, 'P37: form_options carries numbers.loan_due_days');
$db->exec("INSERT INTO equipment (name,status) VALUES ('V434 loan item A','Available'),('V434 loan item B','Available')"); $eqA = (int)$db->lastInsertId(); $eqB = $eqA + 1;
$call('loan_equipment', ['equipment_id' => $eqA, 'client_id' => $c434, 'idem_key' => bin2hex(random_bytes(8))]);
$call('loan_equipment', ['equipment_id' => $eqB, 'client_id' => $c434, 'due_date' => '', 'idem_key' => bin2hex(random_bytes(8))]);
check($db->query("SELECT due_date FROM equipment_loans WHERE equipment_id=$eqA")->fetchColumn() === date('Y-m-d', strtotime('+' . $loanDays . ' days'))
    && $db->query("SELECT due_date FROM equipment_loans WHERE equipment_id=$eqB")->fetchColumn() === null, 'P37: no due_date gets today plus the loan period; a blank one means no return date');
$db->exec("INSERT INTO equipment (name,status) VALUES ('V434 loan item C','Available')"); $eqC = (int)$db->lastInsertId();
$r = $call('loan_equipment', ['equipment_id' => $eqC, 'client_id' => $c434, 'due_date' => date('Y-m-d', strtotime('-1 day')), 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Return due cannot be before today.');
check(isset($r['errors']['due_date']), 'P37: a past return date is refused, tied to due_date');

/* ---- P38: reservations ---- */
$db->exec("INSERT INTO equipment (name,status) VALUES ('V434 reserve item','On Loan')"); $eqR = (int)$db->lastInsertId();
$r = $call('add_reservation', ['equipment_id' => $eqR, 'client_id' => $c434, 'needed_until' => date('Y-m-d', strtotime('-1 day')), 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Needed until cannot be in the past.');
check(isset($r['errors']['needed_until']), 'P38: a past "needed until" is refused');
$db->exec("UPDATE equipment SET status='Available' WHERE id=$eqR");
$first = $call('add_reservation', ['equipment_id' => $eqR, 'client_id' => $c434, 'idem_key' => bin2hex(random_bytes(8))]);
$cR2 = $newClient434('V434 Waiter Synthetic');
$second = $call('add_reservation', ['equipment_id' => $eqR, 'client_id' => $cR2, 'needed_from' => $today434, 'idem_key' => bin2hex(random_bytes(8))]);
$ids = array_column(array_values(array_filter($call('reservations')['reservations'], fn($x) => $x['item_name'] === 'V434 reserve item')), 'client_id');
check($first['status'] === 'Reserved' && $second['status'] === 'Waitlist' && $ids === [$c434, $cR2], 'P38: reservations list in queue order');
$resId = (int)$db->query("SELECT id FROM equipment_reservations WHERE equipment_id=$eqR AND client_id=$c434")->fetchColumn();
check($call('cancel_reservation', ['reservation_id' => $resId])['promoted'] === true
    && $db->query("SELECT status FROM equipment_reservations WHERE equipment_id=$eqR AND client_id=$cR2")->fetchColumn() === 'Reserved', 'P38: cancelling the hold promotes the waiter and says promoted');

/* ---- P39: equipment and assessment inputs refused as the website refuses them ---- */
$r = $call('set_equipment_interval', ['equipment_id' => $eqA, 'interval' => '5000'], [], false, 'Service every: enter a whole number of days from 0 to 3650');
check(isset($r['errors']['interval']), 'P39: an interval of 5000 days is refused, not clamped');
$call('add_equipment', ['name' => 'V434 interval item', 'maintenance_interval_days' => '12.5', 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Service interval: enter a whole number');
$call('equipment_service', ['equipment_id' => $eqA, 'service_date' => '', 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Service date is required.');
$call('equipment_service', ['equipment_id' => $eqA, 'service_date' => date('Y-m-d', strtotime('+2 days')), 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Service date cannot be in the future.');
$call('equipment_service', ['equipment_id' => $eqA, 'service_date' => date('Y-m-d', strtotime('+1 day')), 'service_type' => 'Maintenance', 'idem_key' => bin2hex(random_bytes(8))]);
check(true, 'P39: a service date after tomorrow is refused; tomorrow passes (time-zone slack for queued entries)');
$call('equipment_service', ['equipment_id' => $eqA, 'service_date' => $today434, 'service_type' => 'Maintenance', 'cost' => '$2,995.00', 'idem_key' => bin2hex(random_bytes(8))]);
check((float)$db->query("SELECT cost FROM equipment_maintenance WHERE equipment_id=$eqA ORDER BY id DESC LIMIT 1")->fetchColumn() === 2995.0
    && $audits434('equipment.service', 'Maintenance on ' . $today434 . ' (mobile)') === 1, 'P39: a service date is required, never future; "$2,995.00" is accepted; the audit names type and date');
$call('add_equipment', ['name' => 'V434 bad money', 'value' => '12,50', 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Value: enter the amount as digits');
$db->exec("INSERT INTO assessment_instruments (name,scale_min,scale_max,active) VALUES ('V434 retired instrument',0,3,0)"); $inst434 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO assessment_instrument_items (instrument_id,label) VALUES (?,'V434 item')")->execute([$inst434]); $item434 = (int)$db->lastInsertId();
$call('save_scored', ['client_id' => $c434, 'instrument_id' => $inst434, 'assessed_on' => '', 'scores' => [(string)$item434 => 2], 'idem_key' => bin2hex(random_bytes(8))], [], false, 'Assessment date is required.');
$call('save_scored', ['client_id' => $c434, 'instrument_id' => $inst434, 'assessed_on' => $today434, 'scores' => [(string)$item434 => 2], 'idem_key' => bin2hex(random_bytes(8))]);
check((int)$db->query("SELECT COUNT(*) FROM client_scored_assessments WHERE instrument_id=$inst434")->fetchColumn() === 1 && $audits434('assessment.scored', 'V434 retired instrument: 2/3 (mobile)') === 1,
    'P39: a retired instrument still saves (blank date refused), audited with its name');

/* ---- P40: utilization leaves out uninvoiced no-charge work ---- */
$uFree = $u434;
$db->exec("UPDATE users SET weekly_hours=10 WHERE id=$uFree");
$tFree = $newTrack434($c434, ['billing_mode' => 'none', 'hourly_rate' => 80, 'title' => 'V434 free track']);
$valueOf = fn() => (float)(array_values(array_filter($call('utilization', [], ['from' => $today434, 'to' => $today434])['rows'], fn($r) => $r['name'] === 'V434 Synthetic Instructor'))[0]['value'] ?? 0);
$before = $valueOf();
$db->prepare("INSERT INTO work_logs (track_id,work_date,hours,description,created_by,hourly_rate) VALUES (?,?,2,'V434 free work',?,80)")->execute([$tFree, $today434, $uFree]); $freeLog = (int)$db->lastInsertId();
$mid = $valueOf();
$db->prepare("INSERT INTO invoices (track_id,invoice_number) VALUES (?,'V434-INV-1')")->execute([$tFree]); $inv434 = (int)$db->lastInsertId();
$db->exec("UPDATE work_logs SET invoice_id=$inv434 WHERE id=$freeLog");
check(abs($mid - $before) < 0.001 && abs($valueOf() - $before - 160.0) < 0.001, 'P40: an uninvoiced log on a No charge track adds nothing to value; once invoiced it counts');

/* ---- P41: submit_invoice is done once for one key ---- */
$tInv = $newTrack434($c434, ['title' => 'V434 invoice track']);
$db->prepare("INSERT INTO invoices (track_id,invoice_number,status,created_at) VALUES (?,'V434-INV-2','Draft',?)")->execute([$tInv, date('Y-m-d H:i:s', strtotime('-20 days'))]); $inv2 = (int)$db->lastInsertId();
$key = bin2hex(random_bytes(8)); $sentOn = date('Y-m-d', strtotime('-10 days'));
$a1 = $call('submit_invoice', ['id' => $inv2, 'submitted_on' => $sentOn, 'idem_key' => $key]);
$a2 = $call('submit_invoice', ['id' => $inv2, 'submitted_on' => $sentOn, 'idem_key' => $key]);
check($a1['submitted_on'] === $sentOn && $a2['due_date'] === $a1['due_date'] && !empty($a2['repeat']) && $a1['due_date'] >= $sentOn, 'P41: submit_invoice dates the due date from the day sent, and the same key answers the first reply again');
$r = $call('submit_invoice', ['id' => $inv2, 'idem_key' => bin2hex(random_bytes(8))], [], false, 'no longer a draft');
check(!empty($r['changed']), 'P41: a stale submit says changed:true');

/* ---- P35: the progress report as report.php sends it ---- */
$db->prepare("UPDATE clients SET counselor_email='v434-counselor@example.invalid', counselor_verified=1 WHERE id=?")->execute([$c434]);
$db->prepare("UPDATE users SET email='admin@example.invalid' WHERE id=1")->execute();
$form = $get434('report_form', ['track_id' => $t434]);
check($form['recipient'] === 'v434-counselor@example.invalid' && $form['last_report_at'] === null && $form['my_email'] === 'admin@example.invalid' && $form['counselor_verified'] === true,
    'P35: report_form gives the counselor, the last report date, the sender\'s email and whether the counselor is confirmed');
$adminName = (string)$db->query('SELECT name FROM users WHERE id=1')->fetchColumn();
$mails = fn() => (int)$db->query('SELECT COUNT(*) FROM mail_log')->fetchColumn();
$m0 = $mails();
$sent = $call('send_report', ['track_id' => $t434, 'signature' => $adminName, 'report_kind' => 'final', 'cc_self' => 1, 'share_portal' => 1, 'idem_key' => bin2hex(random_bytes(8))]);
check($sent['copied'] === true && $sent['portal_shared'] === true && $mails() - $m0 === 2
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$c434 AND subject='Final report emailed to VRC' AND body LIKE '%(cc: admin@example.invalid)%'")->fetchColumn() === 1,
    'P35: a final report sends the copy to the sender (two emails), shares it to the portal, and notes "Final report emailed to VRC" with the cc');
$db->prepare('UPDATE training_tracks SET last_report_at = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-5 days')), $t434]);
$pv = $call('report_preview', ['track_id' => $t434, 'report_kind' => 'progress', 'period' => 1, 'barriers' => 'V434 barrier text']);
$pvAll = $call('report_preview', ['track_id' => $t434, 'report_kind' => 'progress', 'period' => 0]);
check(is_string($pv['text']) && str_contains($pv['text'], 'V434 barrier text') && $pv['text'] !== $pvAll['text'], 'P35: report_preview returns the report text, with the period and barriers');
$old = $call('send_report', ['track_id' => $t434, 'signature' => $adminName, 'idem_key' => bin2hex(random_bytes(8))]);
$pvOld = $call('report_preview', ['track_id' => $t434]);
check($pvOld['text'] === $pvAll['text'], 'P35: with no report_kind (an older app) the report covers the whole track, as before');
check($old['copied'] === false && $old['portal_shared'] === null && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$c434 AND subject='Training report emailed to VRC'")->fetchColumn() >= 1,
    'P35: without the new fields an older app sends no copy and no portal copy, as before');

/* ---- AI (P28-P32, P34, P35 drafts): a substituted transport, the reply set per case ---- */
$originalCore434 = file_get_contents($tmp . '/core.php'); $aiSource = file_get_contents($root . '/public/inc/ai.php');
$aiStart = strpos($aiSource, 'function ai_http('); $aiEnd = $aiStart === false ? false : strpos($aiSource, "/**\n * Send a system", $aiStart);
if ($aiStart === false || $aiEnd === false) throw new RuntimeException('Test harness: could not locate ai_http() in inc/ai.php. Refusing to continue: the real network transport would be used.');
$replyFile = $tmp . '/ai434_reply.json'; $sentFile = $tmp . '/ai434_request.json';
$stub = 'function ai_http(string $url,array $headers,string $body): array { file_put_contents(' . var_export($sentFile, true) . ', $body); $r = json_decode((string)@file_get_contents(' . var_export($replyFile, true) . '), true) ?: ["content" => "Synthetic reply", "finish" => "stop"]; return [200,["choices"=>[["message"=>["content"=>$r["content"]],"finish_reason"=>$r["finish"]]]],""]; }' . "\n\n";
file_put_contents($tmp . '/ai434.php', substr($aiSource, 0, $aiStart) . $stub . substr($aiSource, $aiEnd));
file_put_contents($tmp . '/core.php', swap_required(var_export($root . '/public/inc', true) . " . '/ai.php'", var_export($tmp . '/ai434.php', true), $originalCore434, 'the temporary core.php (AI transport stub)'));
$reply434 = fn(string $content, string $finish = 'stop') => file_put_contents($replyFile, json_encode(['content' => $content, 'finish' => $finish]));
$sent434 = fn(): string => (string)@file_get_contents($sentFile);
foreach (['ai_enabled' => '1', 'ai_api_key' => $secrets['ai'], 'ai_provider' => 'openai', 'ai_redact' => '1', 'ai_feat_draft' => '1', 'ai_feat_ask' => '1', 'ai_feat_translate' => '1',
          'ai_feat_kb' => '1', 'ai_feat_curriculum' => '1', 'ai_feat_risk' => '1', 'ai_feat_progress' => '1'] as $k => $v)
    $db->prepare('INSERT INTO settings(`name`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$k, $v]);
try {
    // P28: Ask your caseload.
    $reply434('C' . $c434 . ' is listed', 'length');
    $ask = $call('ai_ask', ['question' => 'Who has recent notes?']);
    check(str_contains($sent434(), 'last note ' . $today434) && str_contains($sent434(), 'one client per line') && $ask['truncated'] === true && is_bool($ask['caseload_cut']),
        'P28: the caseload context carries last-note dates and ask.php\'s prompt; the reply says truncated and caseload_cut');
    // P29: Translate with a code from a released Android build.
    $reply434('Qoraal');
    $tr = $call('ai_translate', ['content' => 'Synthetic text', 'lang' => 'so']);
    check(str_contains($sent434(), 'into Somali') && $tr['lang'] === 'so', 'P29: lang "so" is sent to the provider as Somali, and the reply lang is so');
    $call('ai_translate', ['content' => 'Synthetic text', 'lang' => 'Somali']);
    check(str_contains($sent434(), 'into Somali'), 'P29: the language name is still accepted');
    // P30: the knowledge base as kb_ask.php searches it.
    $db->exec("INSERT INTO kb_articles (title,category,body) VALUES ('V434 Profile setup','Guides','Synthetic body one'),('V434 Other article','Guides','Mentions profile in the body')");
    $reply434('From the articles');
    $kb = $call('ai_kb', ['question' => 'How do I set up profiles?']);
    $titles = array_column($kb['sources'], 'title');
    check(in_array('V434 Profile setup', $titles, true) && array_search('V434 Profile setup', $titles, true) < array_search('V434 Other article', $titles, true)
        && str_contains($sent434(), 'Article: V434 Profile setup (Guides)'), 'P30: "profiles" finds "Profile setup", a title match ranks above a body match, with the category in the context');
    $r = $call('ai_kb', ['question' => 'zzqv434 xxkr434'], [], false, 'No article in the Staff knowledge base mentions');
    check(str_contains($r['error'], '“zzqv434”') && str_contains($r['error'], '“xxkr434”') && isset($r['errors']['question']), 'P30: no match names the terms');
    $call('ai_kb', ['question' => 'how is it'], [], false, 'That question has no words to search the articles for.');
    // P31: the curriculum generator's lesson plan.
    $reply434("Lesson: Start the screen reader\nObjectives:\n- Turn JAWS on\n- Find the desktop\nSteps to complete the task:\n1. Press Control Alt J\nHow you know it's done:\n- JAWS speaks\n\nLesson: Read a line\nObjectives:\n- Read the current line\nSteps to complete the task:\n1. Press Insert Up Arrow\nHow you know it's done:\n- The line is read");
    $cur = $call('ai_curriculum', ['tech' => 'JAWS', 'level' => 'Beginner']);
    check(count($cur['lessons']) === 2 && $cur['lessons'][0]['title'] === 'Start the screen reader' && $cur['lessons'][0]['steps'] === ['Press Control Alt J']
        && $cur['skills'] === "Turn JAWS on\nFind the desktop\nRead the current line" && $cur['truncated'] === false && str_contains($sent434(), 'Steps to complete the task'),
        'P31: the lesson plan is parsed into lessons and skills from curriculum_ai.php\'s prompt');
    $call('ai_curriculum', ['tech' => str_repeat('x', 20001)], [], false, 'can be up to 20,000 characters');
    // P32: risk flags for the period.
    $db->prepare("UPDATE clients SET status='Active Training' WHERE id=?")->execute([$c434]);
    $reply434('1. C' . $c434 . ' reason action');
    $risk = $call('ai_risk', []);
    check(str_contains($sent434(), 'sessions in the period') && str_contains($sent434(), 'The period: ' . risk_quarter_start() . ' to ' . $today434)
        && $risk['period_from'] === risk_quarter_start() && $risk['period_to'] === $today434 && $risk['truncated'] === false,
        'P32: risk flags count sessions in the period (this quarter by default) and say the period');
    $risk2 = $call('ai_risk', ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => $today434]);
    check($risk2['period_from'] === date('Y-m-d', strtotime('-7 days')), 'P32: a chosen period is used');
    // P34: progress review with the website's context.
    $reply434('## V434 goal' . "\n- advancing");
    $pr = $call('ai_progress', ['client_id' => $c434]);
    check(str_contains($sent434(), 'Recent sessions:') && str_contains($sent434(), '(measure: Metric quuxmetric434)') && $pr['truncated'] === false, 'P34: the progress review sends the goals with their measures and the recent sessions');
    $cNoGoal = $newClient434('V434 No Goal Synthetic');
    $call('ai_progress', ['client_id' => $cNoGoal], [], false, 'No active goals to review.');
    // P35: the report drafts.
    $reply434('Cover note about [CLIENT] and [COUNSELOR_PHONE]');
    $di = $call('ai_draft', ['type' => 'report_intro', 'track_id' => $t434, 'report_kind' => 'progress', 'period' => 1]);
    check(str_contains($di['text'], 'V434 Synthetic Client') && !str_contains($di['text'], '[CLIENT]') && is_string($di['placeholder_warning']) && str_contains($sent434(), '[CLIENT]'),
        'P35: report_intro restores [CLIENT] in the draft and warns about a placeholder left');
    $reply434("BARRIERS:\n- Transport for [CLIENT]\nRECOMMENDATIONS:\n- Keep practising");
    $ds = $call('ai_draft', ['type' => 'report_sections', 'track_id' => $t434, 'report_kind' => 'final']);
    check($ds['barriers'] === 'Transport for V434 Synthetic Client' && $ds['recommendations'] === 'Keep practising', 'P35: report_sections drafts barriers and recommendations, details restored');
    // P35: the report drafts need the reports permission, as report.php does.
    $db->exec("INSERT INTO `groups` (name, perms) VALUES ('V434 no reports', 'clients')"); $g434 = (int)$db->lastInsertId();
    $db->exec("UPDATE users SET group_id=$g434 WHERE id=1");
    try {
        @unlink($sentFile);
        $call('ai_draft', ['type' => 'report_intro', 'track_id' => $t434, 'report_kind' => 'progress'], [], false, 'does not have access');
        check(!file_exists($sentFile), 'P35: report_intro without the reports permission is refused before anything is sent to the provider');
    } finally { $db->exec('UPDATE users SET group_id=NULL WHERE id=1'); $db->exec("DELETE FROM `groups` WHERE id=$g434"); }
    // P28: caseload_cut only when more clients exist than were sent.
    $openCount = (int)$db->query("SELECT COUNT(*) FROM clients WHERE status NOT LIKE 'Closed%'")->fetchColumn();
    if ($openCount < 80) { $ins80 = $db->prepare("INSERT INTO clients (name) VALUES (?)"); for ($i = $openCount; $i < 80; $i++) $ins80->execute(['V434 Fill ' . $i]); }
    // Exactly 80 open clients for this check; the statuses are put back after it.
    $closed434 = $db->query("SELECT id, status FROM clients WHERE status NOT LIKE 'Closed%' ORDER BY name LIMIT 1000000 OFFSET 80")->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($closed434) $db->exec("UPDATE clients SET status='Closed - Other' WHERE id IN (" . implode(',', array_map('intval', array_keys($closed434))) . ')');
    try {
        $reply434('Short answer');
        $ask80 = $call('ai_ask', ['question' => 'Who?']);
        $long80 = mb_strlen($sent434()) > 12000;
        $db->exec("INSERT INTO clients (name) VALUES ('V434 One More')"); $oneMore = (int)$db->lastInsertId();
        $ask81 = $call('ai_ask', ['question' => 'Who?']);
        check(($ask80['caseload_cut'] === false || $long80) && $ask81['caseload_cut'] === true,
            'P28: caseload_cut is set when more clients exist than the 80 sent, not when exactly 80 exist');
    } finally {
        $restoreStatus = $db->prepare('UPDATE clients SET status = ? WHERE id = ?');
        foreach ($closed434 as $cid => $st) $restoreStatus->execute([$st, $cid]);
        if (isset($oneMore)) $db->exec("UPDATE clients SET status='Closed - Other' WHERE id=$oneMore");
    }
    // P35: every ai_draft type needs the drafting feature; report_intro is refused before the provider.
    $db->exec("UPDATE settings SET value='0' WHERE `name`='ai_feat_draft'");
    @unlink($sentFile);
    $call('ai_draft', ['type' => 'report_intro', 'track_id' => $t434, 'report_kind' => 'progress'], [], false, 'That AI feature is turned off.');
    $call('ai_draft', ['type' => 'note', 'client_id' => $c434, 'text' => 'raw'], [], false, 'That AI feature is turned off.');
    check(!file_exists($sentFile), 'P35: with drafting turned off, report_intro and the other ai_draft types are refused and nothing reaches the provider');
} finally {
    file_put_contents($tmp . '/core.php', $originalCore434);
    @unlink($replyFile);
}
// The shared helpers the website pages now call are the ones the API uses.
check(curriculum_skills_text("Lesson: A\nObjectives:\n- One") === 'One' && kb_search_terms('Setting up the profiles') === ['setting', 'profile'],
    'P30/P31: the shared helpers the website pages call give the same terms and skills');

/* ---- Small items both apps rely on ---- */
check($old['sent_to'] === 'v434-counselor@example.invalid', 'send_report with no recipient goes to the counselor on file');
$sLog = $addSess434($c434, $t434, $u434, 200);
$sLogDate = date('Y-m-d', time() - 200 * 3600);
$db->prepare("INSERT INTO work_logs (track_id,work_date,hours,description,created_by,hourly_rate) VALUES (?,?,1,'V434 logged first',1,50)")->execute([$t434, $sLogDate]); $preLog = (int)$db->lastInsertId();
$call('complete_session', ['session_id' => $sLog, 'outcome' => 'completed_logged', 'work_log_id' => $preLog]);
check((int)$db->query("SELECT work_log_id FROM scheduled_sessions WHERE id=$sLog")->fetchColumn() === $preLog && $db->query("SELECT status FROM scheduled_sessions WHERE id=$sLog")->fetchColumn() === 'Completed',
    'complete_session with completed_logged and no hours or mileage links the hours already logged');
$r = $call('save_client', ['id' => $c434, 'name' => 'V434 Synthetic Client', 'dob' => '2001-02-30'], [], false, 'Date of birth must be a valid date');
$r2 = $call('save_client', ['id' => $c434, 'name' => 'V434 Synthetic Client', 'dob' => date('Y-m-d', strtotime('+2 days'))], [], false, 'The date of birth cannot be in the future. Check the year.');
check(isset($r['errors']['dob']) && isset($r2['errors']['dob']), 'save_client date-of-birth refusals are tied to errors.dob, wording kept');
$db->prepare("INSERT INTO invoices (track_id,invoice_number,status,created_at) VALUES (?,'V434-INV-3','Draft',NOW())")->execute([$tInv]); $inv3 = (int)$db->lastInsertId();
$r = $call('submit_invoice', ['id' => $inv3, 'submitted_on' => '2020-01-01', 'idem_key' => bin2hex(random_bytes(8))], [], false, 'The date sent must be from the day the invoice was created to today.');
check(($r['errors']['submitted_on'] ?? '') === 'The date sent must be from the day the invoice was created to today.', 'submit_invoice\'s date refusal is tied to errors.submitted_on, wording kept');
// Last, as an active agreement holds back client records until it is signed.
$db->exec("INSERT INTO staff_agreements (title, body, version, active, activated_at) VALUES ('V434 synthetic agreement', 'Synthetic text', 1, 1, NOW())"); $ag434 = (int)$db->lastInsertId();
try {
    $agHash = staff_agreement_hash('V434 synthetic agreement', 'Synthetic text');
    $sig = fn(string $name) => $call('sign_agreement', ['id' => $ag434, 'version' => 1, 'text_hash' => $agHash, 'agree' => true, 'signed_name' => $name], [], false);
    $a1 = $sig(''); $a2 = $sig(str_repeat('x', 121)); $a3 = $sig('Not The Admin');
    check($a1['error'] === 'Type your full name to sign.' && $a1['errors']['signed_name'] === $a1['error']
        && $a2['error'] === 'Type your full name as it is on your account (120 characters at most).' && isset($a2['errors']['signed_name'])
        && str_starts_with($a3['error'], 'The name typed does not match the name on your account, ') && $a3['errors']['signed_name'] === $a3['error'],
        'sign_agreement name refusals keep their sentences and are tied to errors.signed_name');
    $a4 = $call('sign_agreement', ['id' => $ag434, 'version' => 2, 'text_hash' => $agHash, 'agree' => true, 'signed_name' => 'x'], [], false, 'was changed while you had it open');
    check(!isset($a4['errors']), 'a changed agreement is not tied to the name field');
} finally { $db->exec("UPDATE staff_agreements SET active = 0 WHERE id = $ag434"); }

// Put back every setting this file changed (AI switches, the loan period, lists...).
$db->exec('DELETE FROM settings');
$restore434 = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
foreach ($settings434 as $name => $value) $restore434->execute([$name, $value]);
check($db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) == $settings434, 'v434: every setting is as it was before this file');
