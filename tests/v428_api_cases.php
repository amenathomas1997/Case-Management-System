<?php
// Included by full_schema.php after the v427 cases, against its synthetic database only:
// 4.28.0, the app's server side (api.php): referral conversion with texts agreed, stale
// client and worksite saves, retries after a lost reply, the sign-in code's challenge,
// required custom fields, literal search, "Move anyway", reminders after an email change
// and draft invoices' Bill to.

$v428oldGroup = $db->query('SELECT group_id FROM users WHERE id=1')->fetchColumn();
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');   // the administrator, with every permission
$v428attemptsFrom = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM login_attempts')->fetchColumn();
// Required custom fields left by earlier suites would refuse the new clients made here; set aside until the end.
$v428req = array_map('intval', $db->query('SELECT id FROM custom_fields WHERE required=1 AND is_active=1')->fetchAll(PDO::FETCH_COLUMN));
if ($v428req) $db->exec('UPDATE custom_fields SET is_active=0 WHERE id IN (' . implode(',', $v428req) . ')');
try {
    // ---- N15: a referral whose person agreed to texts converts ----
    $db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,referral_source,status,sms_ok,client_phone)
        VALUES('Synthetic','V428 texts','Synthetic','V428 Referrer','Agency','referrer428@example.invalid','555','V428 Texts Synthetic','counselor','New',1,'555-0100')");
    $v428ref = (int)$db->lastInsertId();
    $v428conv = $call('convert_referral', ['id' => $v428ref]);
    $v428cid = (int)($v428conv['client_id'] ?? 0);
    check($v428cid > 0 && $db->query("SELECT sms_consent_at FROM clients WHERE id=$v428cid")->fetchColumn() !== null
        && $db->query("SELECT status FROM referrals WHERE id=$v428ref")->fetchColumn() === 'Converted'
        && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$v428cid AND note_type='Intake'")->fetchColumn() === 1,
        'A referral whose person agreed to texts converts in the app, and the agreement goes with the new client');

    // ---- N16: a stale app form never reopens a closed case ----
    $db->exec("INSERT INTO clients(name,status,assigned_to,email) VALUES('V428 Stale Synthetic','Active Training',1,'old428@example.invalid')"); $v428c = (int)$db->lastInsertId();
    $v428read = $call('client', [], ['id' => $v428c]);
    $v428loaded = (string)($v428read['client']['updated_at'] ?? '');
    check($v428loaded !== '', 'The client action answers the record\'s updated_at');
    $db->exec("UPDATE clients SET status='Closed - Other', updated_at = updated_at + INTERVAL 5 SECOND WHERE id=$v428c");
    $v428stale = $call('save_client', ['id' => $v428c, 'name' => 'V428 Stale Synthetic', 'status' => 'Active Training', 'phone' => '555-0428', 'loaded_at' => $v428loaded], [], false, 'Someone else changed');
    check(($v428stale['conflict'] ?? null) === true && in_array('Status', (array)($v428stale['changed'] ?? []), true)
        && $db->query("SELECT status FROM clients WHERE id=$v428c")->fetchColumn() === 'Closed - Other'
        && $db->query("SELECT phone FROM clients WHERE id=$v428c")->fetchColumn() === null,
        'A save from an app form opened before the case was closed is refused, names the changed field and saves nothing');
    $v428named = $call('save_client', ['id' => $v428c, 'name' => 'V428 Stale Synthetic', 'status' => 'Active Training', 'loaded_at' => $v428loaded,
        'orig' => ['status' => 'Active Training', 'name' => 'V428 Stale Synthetic']], [], false, 'Someone else changed Status since you opened this client');
    check(($v428named['changed'] ?? null) === ['Status'], 'With the values the form was opened with, only the other person\'s change is named');
    $v428fresh = (string)$db->query("SELECT updated_at FROM clients WHERE id=$v428c")->fetchColumn();
    $v428ok = $call('save_client', ['id' => $v428c, 'name' => 'V428 Stale Synthetic', 'phone' => '555-0428', 'loaded_at' => $v428fresh]);
    check($db->query("SELECT CONCAT(status,'|',phone) FROM clients WHERE id=$v428c")->fetchColumn() === 'Closed - Other|555-0428' && is_string($v428ok['updated_at'] ?? null),
        'A save from a current form goes through; a status the app did not send stays as saved');

    // ---- Email change: upcoming reminders go to the new address ----
    $db->exec("UPDATE clients SET status='Active Training' WHERE id=$v428c");
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,reminder_sent,client_reminded_at) VALUES(?,1,?,?,1,NOW())")
        ->execute([$v428c, date('Y-m-d 10:00:00', strtotime('+2 days')), date('Y-m-d 11:00:00', strtotime('+2 days'))]); $v428rem = (int)$db->lastInsertId();
    $call('save_client', ['id' => $v428c, 'name' => 'V428 Stale Synthetic', 'email' => 'new428@example.invalid']);
    check($db->query("SELECT CONCAT(reminder_sent,'|',COALESCE(client_reminded_at,'none')) FROM scheduled_sessions WHERE id=$v428rem")->fetchColumn() === '0|none',
        'Changing the client\'s email in the app lets the upcoming session\'s reminder go to the new address');

    // ---- N7: a private-pay draft stays billed to the client when the counselor changes ----
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate) VALUES(?,'V428 track','V428-AUTH','Synthetic VR',50)")->execute([$v428c]); $v428t = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO invoices(track_id,invoice_number,status,bill_mode,bill_to) VALUES(?,'V428-INV-1','Draft','private','V428 Stale Synthetic')")->execute([$v428t]); $v428inv = (int)$db->lastInsertId();
    $call('save_client', ['id' => $v428c, 'name' => 'V428 Stale Synthetic', 'counselor_name' => 'V428 New Counselor', 'counselor_agency' => 'Synthetic VR']);
    $v428bill = (string)$db->query("SELECT bill_to FROM invoices WHERE id=$v428inv")->fetchColumn();
    check(!str_contains($v428bill, 'V428 New Counselor') && str_contains($v428bill, 'V428 Stale Synthetic'),
        'A private-pay draft invoice stays addressed to the client when the app changes the counselor');

    // ---- N10: a stale worksite save is refused ----
    $v428ws = (int)$call('add_worksite', ['client_id' => $v428c, 'employer' => 'V428 Employer'])['id'];
    $v428wsLoaded = (string)$call('worksite_assessment', [], ['id' => $v428ws])['assessment']['updated_at'];
    $db->exec("UPDATE worksite_assessments SET barriers='Other editor synthetic text', updated_at = updated_at + INTERVAL 5 SECOND WHERE id=$v428ws");
    $call('save_worksite', ['id' => $v428ws, 'employer' => 'V428 Employer', 'barriers' => 'Stale synthetic text', 'loaded_at' => $v428wsLoaded], [], false, 'Someone else changed');
    check($db->query("SELECT barriers FROM worksite_assessments WHERE id=$v428ws")->fetchColumn() === 'Other editor synthetic text',
        'A worksite save from a form opened before someone else\'s save keeps their text');
    $v428wsFresh = (string)$db->query("SELECT updated_at FROM worksite_assessments WHERE id=$v428ws")->fetchColumn();
    $call('save_worksite', ['id' => $v428ws, 'employer' => 'V428 Employer', 'barriers' => 'Current synthetic text', 'loaded_at' => $v428wsFresh]);
    check($db->query("SELECT barriers FROM worksite_assessments WHERE id=$v428ws")->fetchColumn() === 'Current synthetic text', 'A worksite save from a current form is saved');

    // ---- N4: the same create or send sent again after a lost reply is done once ----
    $v428key = 'v428-' . bin2hex(random_bytes(8));
    $v428e1 = $call('add_equipment', ['name' => 'V428 Idem Equipment', 'idem_key' => $v428key]);
    $v428e2 = $call('add_equipment', ['name' => 'V428 Idem Equipment', 'idem_key' => $v428key]);
    check((int)$db->query("SELECT COUNT(*) FROM equipment WHERE name='V428 Idem Equipment'")->fetchColumn() === 1
        && (int)$v428e1['id'] === (int)($v428e2['id'] ?? 0) && ($v428e2['repeat'] ?? null) === true,
        'An item added again with the same retry key is added once, and the first reply is answered again');
    $call('add_equipment', ['name' => 'V428 Idem Equipment', 'idem_key' => $v428key . 'x']);
    check((int)$db->query("SELECT COUNT(*) FROM equipment WHERE name='V428 Idem Equipment'")->fetchColumn() === 2, 'A different retry key is a new item');
    $v428book = ['client_id' => $v428c, 'staff_id' => 1, 'date' => date('Y-m-d', strtotime('+12 days')), 'time' => '09:00', 'duration' => 1, 'idem_key' => 'v428-book-' . bin2hex(random_bytes(6))];
    $call('schedule_session', $v428book); $call('schedule_session', $v428book);
    check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v428c AND start_at='" . $v428book['date'] . " 09:00:00'")->fetchColumn() === 1,
        'A booking sent again with the same retry key books one session and invites once');
    $v428cl = ['id' => 0, 'name' => 'V428 Idem Client', 'idem_key' => 'v428-client-' . bin2hex(random_bytes(6))];
    $v428n1 = $call('save_client', $v428cl); $v428n2 = $call('save_client', $v428cl);
    check((int)$db->query("SELECT COUNT(*) FROM clients WHERE name='V428 Idem Client'")->fetchColumn() === 1 && (int)$v428n1['client_id'] === (int)($v428n2['client_id'] ?? 0),
        'A new client sent again with the same retry key is created once');

    // ---- Required custom fields on an app create ----
    $db->exec("INSERT INTO custom_fields(label,type,required,is_active) VALUES('V428 Required Answer','text',1,1)"); $v428f = (int)$db->lastInsertId();
    $call('save_client', ['id' => 0, 'name' => 'V428 Custom Synthetic'], [], false, 'V428 Required Answer: this is required');
    check((int)$db->query("SELECT COUNT(*) FROM clients WHERE name='V428 Custom Synthetic'")->fetchColumn() === 0, 'A new client without a required answer is refused in the app');
    $v428cc = $call('save_client', ['id' => 0, 'name' => 'V428 Custom Synthetic', 'custom' => [(string)$v428f => 'Given']]);
    check($db->query("SELECT value FROM custom_values WHERE field_id=$v428f AND client_id=" . (int)$v428cc['client_id'])->fetchColumn() === 'Given', 'With the required answer it is saved');
    $call('save_client', ['id' => $v428c, 'name' => 'V428 Stale Synthetic']);
    check(true, 'Editing an existing client that does not send the custom fields is not refused');
    $db->exec("UPDATE custom_fields SET is_active=0 WHERE id=$v428f");

    // ---- Search treats % and _ literally ----
    $db->exec("INSERT INTO clients(name,assigned_to) VALUES('V428 50% Synthetic',1),('V428 50x Synthetic',1)");
    $v428s = array_column($call('clients', [], ['q' => 'V428 50%'])['clients'], 'name');
    $v428c2 = array_column($call('client_choices', [], ['q' => 'V428 50%'])['clients'], 'name');
    $v428all = [];
    foreach ($call('search', [], ['q' => 'V428 50%'])['results'] as $g) foreach ($g['items'] as $it) $v428all[] = $it['title'];
    check($v428s === ['V428 50% Synthetic'] && $v428c2 === ['V428 50% Synthetic'] && $v428all === ['V428 50% Synthetic'],
        'Client lists and search in the app match % literally');

    // ---- "Move anyway" approves only the warnings shown ----
    $v428day = date('Y-m-d', strtotime('+20 days'));
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at) VALUES(?,1,?,?)")->execute([$v428c, "$v428day 09:00:00", "$v428day 10:00:00"]); $v428mv = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at) VALUES(?,1,?,?)")->execute([$v428cid, "$v428day 14:00:00", "$v428day 15:00:00"]);
    $v428m1 = $call('move_session', ['session_id' => $v428mv, 'start_at' => "$v428day 14:00", 'duration' => 1]);
    check(($v428m1['moved'] ?? null) === false && is_string($v428m1['warnings_hash'] ?? null), 'A move with a warning is answered with the warnings and their fingerprint');
    $v428m2 = $call('move_session', ['session_id' => $v428mv, 'start_at' => "$v428day 14:30", 'duration' => 1, 'confirm' => true, 'confirm_hash' => $v428m1['warnings_hash']]);
    check(($v428m2['moved'] ?? null) === false && $db->query("SELECT start_at FROM scheduled_sessions WHERE id=$v428mv")->fetchColumn() === "$v428day 09:00:00",
        'Approving the warnings shown for one time does not move the session to another time');
    $v428m3 = $call('move_session', ['session_id' => $v428mv, 'start_at' => "$v428day 14:00", 'duration' => 1, 'confirm' => true, 'confirm_hash' => $v428m1['warnings_hash']]);
    check(($v428m3['moved'] ?? null) === true && $db->query("SELECT start_at FROM scheduled_sessions WHERE id=$v428mv")->fetchColumn() === "$v428day 14:00:00",
        'Approving the warnings shown moves the session');

    // ---- Verify: a guess without the sign-in's challenge takes none of the code's tries ----
    $db->exec("INSERT INTO users(name,email,password_hash,role) VALUES('V428 Verify Synthetic','v428-verify@example.invalid','x','instructor')"); $v428u = (int)$db->lastInsertId();
    $v428hash = password_hash('135790', PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO login_otps(user_id,code_hash,expires_at,channel) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE),'api')")->execute([$v428u, $v428hash]); $v428otp = (int)$db->lastInsertId();
    for ($i = 0; $i < 3; $i++) $call('verify', ['email' => 'v428-verify@example.invalid', 'code' => '135790', 'login_challenge' => str_repeat('0', 64)], [], false, 'not correct');
    check((int)$db->query("SELECT attempts FROM login_otps WHERE id=$v428otp")->fetchColumn() === 0
        && (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='verify:v428-verify@example.invalid'")->fetchColumn() === 0,
        'Guesses without the sign-in\'s challenge use none of the code\'s tries and do not count against the account');
    $v428tok = $call('verify', ['email' => 'v428-verify@example.invalid', 'code' => '135790', 'login_challenge' => hash_hmac('sha256', 'api-login-challenge:' . $v428otp, $v428hash)]);
    check(is_string($v428tok['token'] ?? null), 'The real user\'s code with the challenge still signs in');

    // ---- An authenticator account whose seed cannot be read is refused, never sent an email code ----
    {
        $db->prepare("INSERT INTO users(name,email,password_hash,role,totp_enabled,totp_secret) VALUES('V428 Seed Synthetic','v428-seed@example.invalid',?,'instructor',1,?)")
            ->execute([password_hash('V428-seed-pw-1', PASSWORD_DEFAULT), (defined('TOTP_SEAL_PREFIX') ? TOTP_SEAL_PREFIX : 'v1:') . 'not-a-sealed-seed']); $v428su = (int)$db->lastInsertId();
        $call('login', ['email' => 'v428-seed@example.invalid', 'password' => 'V428-seed-pw-1'], [], false, 'can\'t be checked on this server');
        check((int)$db->query("SELECT COUNT(*) FROM login_otps WHERE user_id=$v428su")->fetchColumn() === 0,
            'An authenticator account whose seed cannot be read is refused at sign-in and no emailed code is started');
    }
} finally {
    if ($v428req) $db->exec('UPDATE custom_fields SET is_active=1 WHERE id IN (' . implode(',', $v428req) . ')');
    $db->prepare('UPDATE users SET group_id=? WHERE id=1')->execute([$v428oldGroup === false ? null : $v428oldGroup]);
    // The sign-in guesses above must not count toward later suites' limits on this address.
    $db->prepare("DELETE FROM login_attempts WHERE id > ? AND (email = 'verify-anon' OR email LIKE 'verify:%' OR email LIKE 'moveshown:%' OR email LIKE 'authshown:%')")->execute([$v428attemptsFrom]);
}
