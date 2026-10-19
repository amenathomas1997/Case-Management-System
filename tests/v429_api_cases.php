<?php
// Included by full_schema.php after the v428 cases, against its synthetic database only:
// 4.29.0, the app's server side (api.php, inc/api_parity.php): the retry guard for the
// parity actions, the app's stale track edit, the instructor's other sessions on a booking,
// offline notes dated when written, retries of contract spending, deliverables and snoozes,
// client search by email and phone, and totals for the capped lists.

$v429oldGroup = $db->query('SELECT group_id FROM users WHERE id=1')->fetchColumn();
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');   // the administrator, with every permission
$v429attemptsFrom = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM login_attempts')->fetchColumn();
$v429req = array_map('intval', $db->query('SELECT id FROM custom_fields WHERE required=1 AND is_active=1')->fetchAll(PDO::FETCH_COLUMN));
if ($v429req) $db->exec('UPDATE custom_fields SET is_active=0 WHERE id IN (' . implode(',', $v429req) . ')');
try {
    $db->exec("INSERT INTO clients(name,status,assigned_to,email,phone) VALUES('V429-API Client Synthetic','Active Training',1,'v429-api-client@example.invalid','(555) 014-2901')");
    $v429c = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V429-API Other Synthetic','Active Training',1)");
    $v429other = (int)$db->lastInsertId();

    // ---- P4: the retry guard runs for the parity actions too ----
    $v429tk = 'v429-api-task-' . bin2hex(random_bytes(6));
    $v429t1 = $call('save_task', ['title' => 'V429-API Task Synthetic', 'idem_key' => $v429tk]);
    $v429t2 = $call('save_task', ['title' => 'V429-API Task Synthetic', 'idem_key' => $v429tk]);
    check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE title='V429-API Task Synthetic'")->fetchColumn() === 1
        && (int)$v429t1['id'] === (int)($v429t2['id'] ?? 0) && ($v429t2['repeat'] ?? null) === true,
        'A task sent again with the same retry key is added once, and the first reply is answered again');

    $v429trIn = ['client_id' => $v429c, 'title' => 'V429-API Track Synthetic', 'auth_number' => 'V429-API-AUTH', 'funding_agency' => 'Synthetic VR',
        'authorized_hours' => '20', 'hourly_rate' => '95', 'mileage_rate' => '0.5', 'idem_key' => 'v429-api-track-' . bin2hex(random_bytes(6))];
    $v429tr1 = $call('save_track', $v429trIn);
    $v429tr2 = $call('save_track', $v429trIn);
    $v429track = (int)$v429tr1['id'];
    check((int)$db->query("SELECT COUNT(*) FROM training_tracks WHERE title='V429-API Track Synthetic'")->fetchColumn() === 1
        && (int)($v429tr2['id'] ?? 0) === $v429track && ($v429tr2['repeat'] ?? null) === true,
        'A new track sent again with the same retry key is answered by the retry guard, not made twice');

    $v429consent = ['client_id' => $v429c, 'guardian_name' => 'V429-API Guardian', 'guardian_email' => 'v429-api-guardian@example.invalid',
        'idem_key' => 'v429-api-consent-' . bin2hex(random_bytes(6))];
    $call('request_consent', $v429consent);
    $v429tok1 = (string)$db->query("SELECT token FROM consent_forms WHERE client_id=$v429c")->fetchColumn();
    $v429cr2 = $call('request_consent', $v429consent);
    check(($v429cr2['repeat'] ?? null) === true && (int)$db->query("SELECT COUNT(*) FROM consent_forms WHERE client_id=$v429c")->fetchColumn() === 1
        && (string)$db->query("SELECT token FROM consent_forms WHERE client_id=$v429c")->fetchColumn() === $v429tok1,
        'A consent request sent again with the same retry key emails once and the first link keeps working');

    // ---- P3: a stale app track edit is refused ----
    // The harness sends every call as POST, so the form's values come from a save that changes
    // nothing: its reply carries the track as the GET form gives it (parity_track_values).
    $v429same = ['id' => $v429track, 'title' => 'V429-API Track Synthetic', 'auth_number' => 'V429-API-AUTH', 'funding_agency' => 'Synthetic VR',
        'authorized_hours' => '20', 'hourly_rate' => '95', 'mileage_rate' => '0.5'];
    $v429form = $call('save_track', $v429same);
    $v429loaded = (string)($v429form['track']['updated_at'] ?? '');
    check($v429loaded !== '', 'The track form answers its saved version as updated_at');
    $v429orig = $v429form['track'];
    $db->exec("UPDATE training_tracks SET authorized_hours=30, hourly_rate=110 WHERE id=$v429track");   // a colleague's amendment on the website
    $v429edit = ['id' => $v429track, 'title' => 'V429-API Track Renamed', 'auth_number' => 'V429-API-AUTH', 'funding_agency' => 'Synthetic VR',
        'authorized_hours' => '20', 'hourly_rate' => '95', 'mileage_rate' => '0.5'];
    $v429stale = $call('save_track', $v429edit + ['loaded_at' => $v429loaded, 'orig' => $v429orig], [], false, 'Someone else changed');
    check(($v429stale['conflict'] ?? null) === true && in_array('Authorized hours', (array)($v429stale['changed'] ?? []), true)
        && in_array('Hourly billing rate', (array)$v429stale['changed'], true) && !in_array('Track title', (array)$v429stale['changed'], true)
        && is_string($v429stale['updated_at'] ?? null) && $v429stale['updated_at'] !== $v429loaded
        && $db->query("SELECT CONCAT(title,'|',authorized_hours,'|',hourly_rate) FROM training_tracks WHERE id=$v429track")->fetchColumn() === 'V429-API Track Synthetic|30.00|110.00'
        && (int)$db->query("SELECT COUNT(*) FROM auth_amendments WHERE track_id=$v429track")->fetchColumn() === 0,
        'A title-only save from an app form opened before a colleague\'s amendment is refused, names their fields and changes nothing');
    $call('save_track', $v429edit + ['loaded_at' => $v429loaded], [], false, 'Someone else changed this track');
    check(true, 'With loaded_at alone a stale save is refused too');
    $v429fresh = $call('save_track', ['authorized_hours' => '30', 'hourly_rate' => '110'] + $v429same)['track'];
    $v429ok = $call('save_track', ['id' => $v429track, 'title' => 'V429-API Track Renamed', 'auth_number' => 'V429-API-AUTH', 'funding_agency' => 'Synthetic VR',
        'authorized_hours' => '30', 'hourly_rate' => '110', 'mileage_rate' => '0.5', 'loaded_at' => $v429fresh['updated_at'], 'orig' => $v429fresh]);
    check($db->query("SELECT CONCAT(title,'|',authorized_hours,'|',hourly_rate) FROM training_tracks WHERE id=$v429track")->fetchColumn() === 'V429-API Track Renamed|30.00|110.00'
        && ($v429ok['track']['updated_at'] ?? '') !== $v429fresh['updated_at'] && ($v429ok['amended'] ?? null) === false,
        'A save from a current form goes through and answers the new version');
    $call('save_track', ['id' => $v429track, 'title' => 'V429-API Track Older App', 'auth_number' => 'V429-API-AUTH', 'funding_agency' => 'Synthetic VR',
        'authorized_hours' => '30', 'hourly_rate' => '110', 'mileage_rate' => '0.5']);
    check($db->query("SELECT title FROM training_tracks WHERE id=$v429track")->fetchColumn() === 'V429-API Track Older App', 'An older app that sends no version saves as before');

    // ---- P9: the instructor's other sessions are a warning to approve ----
    $v429day = date('Y-m-d', strtotime('+15 days'));
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at) VALUES(?,1,?,?)")->execute([$v429other, "$v429day 05:00:00", "$v429day 06:30:00"]); // a quiet hour: earlier tests book 10:00 sessions
    $v429book = ['client_id' => $v429c, 'staff_id' => 1, 'date' => $v429day, 'time' => '05:30', 'duration' => 1, 'authorization_ack' => false];
    $v429b1 = $call('schedule_session', $v429book, [], false, 'already has a session');
    check(in_array('Test Admin already has a session from 5:00 to 6:30 with another client.', (array)($v429b1['authorization_warnings'] ?? []), true)
        && is_string($v429b1['authorization_hash'] ?? null)
        && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v429c")->fetchColumn() === 0,
        'Booking an instructor over their own session is held with the warning, and nothing is booked');
    $call('schedule_session', ['client_id' => $v429c, 'staff_id' => 1, 'date' => $v429day, 'time' => '13:00', 'duration' => 1]);
    check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v429c")->fetchColumn() === 1, 'A time the instructor is free books with no warning');
    $call('schedule_session', ['authorization_ack' => true, 'authorization_ack_hash' => $v429b1['authorization_hash']] + $v429book);
    check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v429c AND start_at='$v429day 05:30:00'")->fetchColumn() === 1,
        'Approving the warning shown books the overlapping session');

    // ---- Offline notes keep the time they were written ----
    $v429when = time() - 2 * 86400;
    $call('add_note', ['client_id' => $v429c, 'subject' => 'V429-API Offline Note', 'body' => 'Synthetic offline text', 'recorded_at' => date('c', $v429when),
        'idem_key' => '42942942-0000-4000-8000-' . substr(bin2hex(random_bytes(6)), 0, 12)]);
    $v429n = $db->query("SELECT created_at, body FROM client_notes WHERE subject='V429-API Offline Note'")->fetch();
    check(abs(strtotime($v429n['created_at']) - $v429when) <= 1 && str_contains($v429n['body'], 'Written on the phone at ' . date('M j, Y g:i A', $v429when) . ', received '),
        'A note written offline two days ago is dated when it was written and says when it was received');
    $call('add_note', ['client_id' => $v429c, 'subject' => 'V429-API Old Note', 'body' => 'Synthetic old text', 'recorded_at' => date('c', time() - 10 * 86400)]);
    $call('add_note', ['client_id' => $v429c, 'subject' => 'V429-API Future Note', 'body' => 'Synthetic future text', 'recorded_at' => date('c', time() + 86400)]);
    $call('add_note', ['client_id' => $v429c, 'subject' => 'V429-API Prompt Note', 'body' => 'Synthetic prompt text', 'recorded_at' => date('c', time() - 120)]);
    check((int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject IN ('V429-API Old Note','V429-API Future Note') AND created_at > NOW() - INTERVAL 5 MINUTE AND body NOT LIKE '%Written on the phone%'")->fetchColumn() === 2
        && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='V429-API Prompt Note' AND body NOT LIKE '%Written on the phone%'")->fetchColumn() === 1,
        'A written time older than 7 days or in the future is dated on arrival, and one a few minutes old adds no line');

    // ---- Contract spending and deliverables are done once per retry key ----
    $v429con = (int)$call('add_contract', ['name' => 'V429-API Contract Synthetic'])['id'];
    $v429spend = ['contract_id' => $v429con, 'op' => 'add_spend', 'amount' => '1250.00', 'idem_key' => 'v429-api-spend-' . bin2hex(random_bytes(6))];
    $call('contract_update', $v429spend); $v429s2 = $call('contract_update', $v429spend);
    $v429del = ['contract_id' => $v429con, 'op' => 'add_deliverable', 'title' => 'V429-API Deliverable', 'idem_key' => 'v429-api-deliv-' . bin2hex(random_bytes(6))];
    $call('contract_update', $v429del); $call('contract_update', $v429del);
    check(($v429s2['repeat'] ?? null) === true && (int)$db->query("SELECT COUNT(*) FROM contract_spend WHERE contract_id=$v429con")->fetchColumn() === 1
        && (int)$db->query("SELECT COUNT(*) FROM contract_deliverables WHERE contract_id=$v429con")->fetchColumn() === 1,
        'Spending and a deliverable sent again with the same retry key are recorded once');

    // ---- Snooze moves a follow-up once for the date shown ----
    $v429eq = (int)$call('add_equipment', ['name' => 'V429-API Equipment Synthetic'])['id'];
    $db->prepare("INSERT INTO equipment_loans(equipment_id,client_id,loaned_at,returned_at) VALUES(?,?,CURDATE(),NOW())")->execute([$v429eq, $v429c]); $v429loan = (int)$db->lastInsertId();
    $v429due = date('Y-m-d', strtotime('-1 day'));
    $db->prepare("INSERT INTO equipment_followups(loan_id,client_id,equipment_id,interval_days,due_date) VALUES(?,?,?,30,?)")->execute([$v429loan, $v429c, $v429eq, $v429due]); $v429fu = (int)$db->lastInsertId();
    $v429z1 = $call('save_followup', ['id' => $v429fu, 'op' => 'snooze', 'due_date' => $v429due]);
    $v429z2 = $call('save_followup', ['id' => $v429fu, 'op' => 'snooze', 'due_date' => $v429due]);
    $v429moved = date('Y-m-d', strtotime('+14 days'));
    check(($v429z1['due_date'] ?? '') === $v429moved && ($v429z2['already'] ?? null) === true && ($v429z2['due_date'] ?? '') === $v429moved
        && $db->query("SELECT due_date FROM equipment_followups WHERE id=$v429fu")->fetchColumn() === $v429moved,
        'A snooze sent twice for the date shown moves the follow-up 14 days once');

    // ---- Client search by email and phone; totals for capped lists ----
    $v429byEmail = array_column($call('clients', [], ['q' => 'v429-api-client@example'])['clients'], 'id');
    $v429byPhone = $call('clients', [], ['q' => '1-555-014-2901']);
    check(in_array($v429c, array_map('intval', $v429byEmail), true) && in_array($v429c, array_map('intval', array_column($v429byPhone['clients'], 'id')), true)
        && (int)$v429byPhone['total'] === count($v429byPhone['clients']),
        'Client search finds a client by email and by phone digits, and answers the total');
    check((int)$call('clients')['total'] === (int)$db->query('SELECT COUNT(*) FROM clients WHERE archived_at IS NULL')->fetchColumn(), 'The client list answers how many clients match in all');
    $db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by) VALUES(?,CURDATE(),1,'V429-API log',1)")->execute([$v429track]);
    check((int)($call('work_logs', [], ['track_id' => $v429track])['total'] ?? -1) === 1, 'The track\'s work entries answer their total');
    $db->prepare("INSERT INTO smart_goals(track_id,specific_goal,measurable_metric) VALUES(?,'V429-API goal','Synthetic')")->execute([$v429track]); $v429g = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO goal_progress(goal_id,progress_pct,recorded_by) VALUES(?,10,1),(?,20,1)")->execute([$v429g, $v429g]);
    check((int)($call('goal_progress', [], ['goal' => $v429g])['total'] ?? -1) === 2, 'Goal progress answers its total');
    $db->prepare("INSERT INTO equipment_maintenance(equipment_id,service_date) VALUES(?,CURDATE())")->execute([$v429eq]);
    $v429ed = $call('equipment_detail', [], ['id' => $v429eq]);
    check((int)($v429ed['maintenance_total'] ?? -1) === 1 && (int)($v429ed['total'] ?? -1) === 1, 'Equipment service history answers its total');
    $v429co = (int)$call('add_cohort', ['name' => 'V429-API Cohort Synthetic'])['id'];
    $db->prepare("INSERT INTO cohort_sessions(cohort_id,session_date,activity) VALUES(?,CURDATE(),'Synthetic')")->execute([$v429co]);
    $v429coR = $call('cohort', [], ['id' => $v429co]);
    check((int)($v429coR['sessions_total'] ?? -1) === 1 && (int)($v429coR['total'] ?? -1) === 1, 'A group\'s sessions answer their total');

    // ---- An email change redoes only the client's reminder, and only for a confirmed address ----
    $db->exec("INSERT INTO clients(name,status,assigned_to,email,email_verified) VALUES('V429-API Remind Synthetic','Active Training',1,'v429-api-old@example.invalid',1)"); $v429rc = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,reminder_sent,client_reminded_at) VALUES(?,1,?,?,1,NOW())")
        ->execute([$v429rc, date('Y-m-d 10:00:00', strtotime('+3 days')), date('Y-m-d 11:00:00', strtotime('+3 days'))]); $v429rs = (int)$db->lastInsertId();
    $call('save_client', ['id' => $v429rc, 'name' => 'V429-API Remind Synthetic', 'email' => 'v429-api-new@example.invalid']);
    check($db->query("SELECT CONCAT(reminder_sent,'|',COALESCE(client_reminded_at,'none'),'|',staff_reminded_at IS NOT NULL) FROM scheduled_sessions WHERE id=$v429rs")->fetchColumn() === '0|none|1',
        'A corrected client email redoes the client\'s reminder and keeps the instructor\'s as sent');
    $db->exec("UPDATE scheduled_sessions SET reminder_sent=1, client_reminded_at=NOW() WHERE id=$v429rs");
    $call('save_client', ['id' => $v429rc, 'name' => 'V429-API Remind Synthetic', 'email' => '']);
    check((int)$db->query("SELECT reminder_sent FROM scheduled_sessions WHERE id=$v429rs")->fetchColumn() === 1, 'A cleared client email redoes no reminder');

    // ---- A note edited from an older copy is refused ----
    $call('add_note', ['client_id' => $v429rc, 'subject' => 'V429-API Edit Note', 'body' => 'Synthetic first text']);
    $v429nid = (int)$db->query("SELECT id FROM client_notes WHERE subject='V429-API Edit Note'")->fetchColumn();
    $v429nr = array_values(array_filter($call('client', [], ['id' => $v429rc])['notes'], fn($n) => (int)$n['id'] === $v429nid))[0] ?? [];
    $v429nv = (string)($v429nr['note_version'] ?? '');
    $db->exec("UPDATE client_notes SET body='Synthetic colleague text', updated_by=1, updated_at=NOW() + INTERVAL 1 SECOND WHERE id=$v429nid");
    $v429ne = $call('update_note', ['id' => $v429nid, 'subject' => 'V429-API Edit Note', 'body' => 'Synthetic stale text', 'note_version' => $v429nv], [], false, 'Test Admin changed this note');
    check(strlen($v429nv) === 64 && ($v429ne['conflict'] ?? null) === true
        && $db->query("SELECT body FROM client_notes WHERE id=$v429nid")->fetchColumn() === 'Synthetic colleague text',
        'A note edit from a copy opened before a colleague\'s save is refused, names who changed it and replaces nothing');
    $v429ok2 = $call('update_note', ['id' => $v429nid, 'subject' => 'V429-API Edit Note', 'body' => 'Synthetic current text', 'note_version' => $v429ne['note_version']]);
    check($db->query("SELECT body FROM client_notes WHERE id=$v429nid")->fetchColumn() === 'Synthetic current text' && strlen((string)($v429ok2['note_version'] ?? '')) === 64,
        'An edit from the current version is saved and answers the new version');
    $v429same2 = $call('update_note', ['id' => $v429nid, 'subject' => 'V429-API Edit Note', 'body' => 'Synthetic current text', 'note_version' => $v429ne['note_version']]);
    check(($v429same2['unchanged'] ?? null) === true && ($v429same2['note_version'] ?? '') === $v429ok2['note_version']
        && (int)$db->query("SELECT COUNT(*) FROM client_note_revisions WHERE note_id=$v429nid")->fetchColumn() === 1,
        'The same edit sent again after a lost reply is answered as saved, with no false conflict and no extra version');

    // ---- Voice booking refuses a time the speaker already has a session ----
    $db->exec("INSERT INTO settings(name,value) VALUES('ai_feat_voice','1') ON DUPLICATE KEY UPDATE value='1'");
    $v429vday = date('Y-m-d', strtotime('+16 days'));
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at) VALUES(?,1,?,?)")->execute([$v429other, "$v429vday 04:00:00", "$v429vday 05:30:00"]); // a quiet hour
    $v429v = $call('voice_command', ['do' => 'schedule_session', 'client_id' => $v429rc, 'start_at' => "$v429vday 04:30", 'duration' => 60], [], false, 'already has a session from 4:00 to 5:30 with another client');
    $call('voice_command', ['do' => 'schedule_session', 'client_id' => $v429rc, 'start_at' => "$v429vday 13:00", 'duration' => 60]);
    check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v429rc AND DATE(start_at)='$v429vday'")->fetchColumn() === 1
        && str_contains((string)$v429v['error'], 'Nothing was booked'),
        'Voice refuses to book over the speaker\'s own session and books a free time');
    $db->exec("DELETE FROM settings WHERE name='ai_feat_voice'");

    // ---- A private-pay track from the app names the payer ----
    $v429pp = $call('save_track', ['client_id' => $v429rc, 'title' => 'V429-API Private Track', 'billing_mode' => 'private', 'funding_agency' => 'State VR', 'hourly_rate' => '40']);
    check($db->query('SELECT funding_agency FROM training_tracks WHERE id=' . (int)$v429pp['id'])->fetchColumn() === 'Private pay', 'A private-pay track saved from the app has Private pay as its funder');

    // ---- In-person signing of a Spanish document is refused in Spanish ----
    $db->prepare("INSERT INTO client_signatures(client_id,kind,title,body,signer_name,signer_email,token,lang,method) VALUES(?,'consent','V429-API Documento','Synthetic','V429 Firmante','v429-api-sign@example.invalid',?,'es','Email')")
        ->execute([$v429rc, bin2hex(random_bytes(24))]); $v429sig = (int)$db->lastInsertId();
    $call('sign_in_person', ['client_id' => $v429rc, 'id' => $v429sig, 'typed_name' => 'V429 Firmante'], [], false, 'Este documento se envió por correo');
    check(true, 'A Spanish document\'s in-person refusal is given in Spanish');

    // ---- P14 follow-up: no authenticator setup key is handed out while the key file is damaged ----
    $v429keyFile = $tmp . '/meeting_tokens.key';
    $v429keyWas = is_file($v429keyFile) ? file_get_contents($v429keyFile) : null;
    file_put_contents($v429keyFile, 'V429-API damaged key'); chmod($v429keyFile, 0600);
    try {
        $db->exec("DELETE FROM settings WHERE name='totp_pending_1'");
        $call('totp_begin', [], [], false, 'meeting_tokens.key');
        check((int)$db->query("SELECT COUNT(*) FROM settings WHERE name='totp_pending_1'")->fetchColumn() === 0,
            'With a damaged key file the app is refused an authenticator setup key and nothing is kept');
    } finally {
        if ($v429keyWas === null) @unlink($v429keyFile); else { file_put_contents($v429keyFile, $v429keyWas); chmod($v429keyFile, 0600); }
    }
} finally {
    if ($v429req) $db->exec('UPDATE custom_fields SET is_active=1 WHERE id IN (' . implode(',', $v429req) . ')');
    $db->prepare('UPDATE users SET group_id=? WHERE id=1')->execute([$v429oldGroup === false ? null : $v429oldGroup]);
    $db->prepare("DELETE FROM login_attempts WHERE id > ? AND (email LIKE 'authshown:%' OR email LIKE 'consent-mail-%')")->execute([$v429attemptsFrom]);
}
