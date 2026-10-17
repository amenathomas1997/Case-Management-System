<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/client_merge.php'; // send_session_move_notices()
csrf_check();
$user = require_login();
require_perm('clients');

$id = (int)($_GET['id'] ?? 0);
$client = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
    scope_check_client($client, $user);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') $errors['name'] = 'Client name is required.';
    // Over-length text gets a message on its field here instead of failing the
    // insert (limits are the clients / client_status_history column sizes).
    $limits = ['name' => [120, 'Full name'], 'email' => [190, 'Email'], 'phone' => [40, 'Phone'], 'address' => [255, 'Address'],
        'school' => [190, 'School / district'], 'status_note' => [255, 'Reason for status change'],
        'counselor_name' => [120, 'Counselor name'], 'counselor_agency' => [190, 'Agency / district'],
        'counselor_email' => [190, 'Counselor email'], 'counselor_phone' => [40, 'Counselor phone'],
        'a11y_primary_tech' => [120, 'Primary assistive technology'],
        'guardian_name' => [120, 'Guardian name'], 'guardian_phone' => [40, 'Guardian phone'], 'guardian_email' => [190, 'Guardian email'],
        'vr_case_number' => [60, 'VR case number'], 'ipe_goal' => [255, 'IPE employment goal']];
    foreach ($limits as $k => [$max, $label]) {
        if (is_string($_POST[$k] ?? null) && mb_strlen(trim($_POST[$k])) > $max) {
            $errors[$k] = $label . ' is too long (' . number_format($max) . ' characters maximum).';
        }
    }
    // Access notes: the real limit is the TEXT column (65,535 bytes), the same rule
    // the app uses, so notes saved from the app never block a web edit. Browsers
    // post line breaks as CR LF; they are stored as LF so a re-save never grows.
    $a11yNotes = str_replace("\r\n", "\n", trim(is_string($_POST['a11y_notes'] ?? null) ? $_POST['a11y_notes'] : ''));
    if (strlen($a11yNotes) > 65535) {
        $errors['a11y_notes'] = 'Access notes is too long (65,535 bytes maximum; accented letters and symbols count as 2 to 4 bytes each).';
    }
    // A new or changed address must be a real one (an untouched stored value never blocks other edits).
    foreach (['email' => 'Enter a valid email address, for example name@example.org.',
              'counselor_email' => 'Enter a valid counselor email address, for example name@agency.gov.',
              'guardian_email' => 'Enter a valid guardian email address, for example name@example.org.'] as $k => $msg) {
        $in = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
        if (!isset($errors[$k]) && $in !== '' && $in !== (string)($client[$k] ?? '') && !filter_var($in, FILTER_VALIDATE_EMAIL)) $errors[$k] = $msg;
    }
    $dobIn = trim($_POST['dob'] ?? '');
    // Same bounds as the app's update_client: a mistyped year would otherwise
    // reach RSA-911 as a plausible age.
    if ($dobIn !== '' && !valid_date($dobIn, 1900, (int)date('Y'))) $errors['dob'] = 'Enter the date of birth as a valid date (YYYY-MM-DD) from the year 1900 onwards.';
    elseif ($dobIn !== '' && $dobIn > date('Y-m-d')) $errors['dob'] = 'The date of birth cannot be in the future. Check the year.';
    $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
    if (caseload_scoped()) {
        $assignedTo = (int)$user['id']; // scoped staff own what they create
    } elseif ($assignedTo !== null && $assignedTo !== (int)($client['assigned_to'] ?? 0)) {
        // A new assignee must be an active staff member who can open client
        // records: their tasks, alerts and portal messages follow the client
        // (an unchanged one may since have been deactivated).
        $au = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $au->execute([$assignedTo]);
        $auRow = $au->fetch();
        if (!$auRow) $errors['assigned_to'] = 'Choose an active staff member to assign this client to.';
        elseif (!in_array('clients', resolve_perms($auRow)['perms'], true)) {
            $errors['assigned_to'] = 'That staff member does not have access to client records, so they could not open this client. Choose someone with client access.';
        }
    }
    set_form_errors($errors);
    $status = in_array($_POST['status'] ?? '', CLIENT_STATUSES, true) ? $_POST['status'] : 'Pending Assessment';
    // Closing a case records why, and what the person came away with (3.0.35).
    $closing = str_starts_with($status, 'Closed') && (!$client || $client['status'] !== $status);
    $closureData = null;
    if ($closing) {
        [$closureData, $closureErrs] = closure_input($_POST);
        if ($closureErrs) { $errors += $closureErrs; set_form_errors($errors); }
    }
    $clientType = in_array($_POST['client_type'] ?? '', cms_choices('client_types', $client['client_type'] ?? null), true) ? $_POST['client_type'] : 'VR';
    // On Hold needs a reason; a return date is optional (3.0.44).
    [$holdErr, $holdUntil] = on_hold_input($status, (string)($client['status'] ?? ''), is_string($_POST['status_note'] ?? null) ? $_POST['status_note'] : '',
        is_string($_POST['on_hold_until'] ?? null) ? $_POST['on_hold_until'] : '');
    if ($holdErr !== null) { $errors[str_contains($holdErr, 'return date') ? 'on_hold_until' : 'status_note'] = $holdErr; set_form_errors($errors); }
    // Guardian details (3.0.37): saved with their own statement, after the main save.
    $gIn = fn(string $k): ?string => (is_string($_POST[$k] ?? null) && trim($_POST[$k]) !== '') ? trim($_POST[$k]) : null;
    $guardianVals = [$gIn('guardian_name'), $gIn('guardian_phone'), $gIn('guardian_email')];
    // The plan (3.0.45): VR case number, IPE employment goal, IPE date and latest amendment.
    $planDates = [];
    foreach (['ipe_date' => 'IPE date', 'ipe_amended_on' => 'IPE amendment date'] as $pk => $pl) {
        $raw = $gIn($pk);
        $planDates[$pk] = $raw === null ? null : valid_date($raw, 1990, (int)date('Y'));
        if ($raw !== null && $planDates[$pk] === null) { $errors[$pk] = $pl . ': enter a valid date, today or earlier, or leave it blank.'; set_form_errors($errors); }
    }
    $planVals = [$gIn('vr_case_number'), $gIn('ipe_goal'), $planDates['ipe_date'], $planDates['ipe_amended_on']];
    // Vision status and age of onset (3.0.47), for the Outcome report's breakdowns.
    // A value since removed from the list stays when it is sent back unchanged (Lists, 4.4.0).
    $visionIn = in_array($_POST['vision_status'] ?? '', array_merge(cms_list_items('vision_statuses'), $client && $client['vision_status'] !== null ? [(string)$client['vision_status']] : []), true) ? $_POST['vision_status'] : null;
    $onsetRaw = $gIn('vision_onset_age');
    $onsetIn = $onsetRaw !== null && ctype_digit($onsetRaw) && (int)$onsetRaw <= 120 ? (int)$onsetRaw : null;
    if ($onsetRaw !== null && $onsetIn === null) { $errors['vision_onset_age'] = 'Age when vision loss began: enter a whole number of years from 0 to 120, or leave it blank.'; set_form_errors($errors); }
    $visionVals = [$visionIn, $onsetIn];
    // Where the client lives and their time zone (4.1.0). Only a zone someone picked is stored:
    // with none, the state's usual zone applies, and follows the state if it changes.
    $stateIn = us_state(is_string($_POST['state'] ?? null) ? $_POST['state'] : null);
    $zoneIn = us_zone(is_string($_POST['time_zone'] ?? null) ? $_POST['time_zone'] : null);
    $placeVals = [$stateIn, $zoneIn];
    $vals = [
        $name,
        valid_date($dobIn),
        trim($_POST['email'] ?? '') ?: null,
        trim($_POST['phone'] ?? '') ?: null,
        trim($_POST['address'] ?? '') ?: null,
        $status,
        $clientType,
        $clientType === 'Pre-ETS' ? (trim($_POST['school'] ?? '') ?: null) : null,
        $assignedTo,
        trim($_POST['counselor_name'] ?? '') ?: null,
        trim($_POST['counselor_email'] ?? '') ?: null,
        trim($_POST['counselor_phone'] ?? '') ?: null,
        trim($_POST['counselor_agency'] ?? '') ?: null,
        !empty($_POST['a11y_screen_reader']) ? 1 : 0,
        in_array($_POST['a11y_format'] ?? '', A11Y_FORMATS, true) ? $_POST['a11y_format'] : null,
        trim($_POST['a11y_primary_tech'] ?? '') ?: null,
        in_array($_POST['a11y_contact'] ?? '', A11Y_CONTACTS, true) ? $_POST['a11y_contact'] : null,
        $a11yNotes !== '' ? $a11yNotes : null,
        // The language the portal opens in and the client's emails are sent in.
        ($_POST['preferred_language'] ?? 'en') === 'es' ? 'es' : 'en',
    ];
    // Automatic progress reports, the counselor portal and email filing use the
    // counselor email only once it is confirmed (counselor_verified). An address
    // staff type here themselves counts as confirmed; one that arrived through
    // the public referral form stays unconfirmed until staff tick the box below.
    $counselorIn = $vals[10];
    $wasVerified = $client && (int)($client['counselor_verified'] ?? 0) === 1;
    if ($counselorIn === null) $counselorVerified = 0;
    elseif (!$client || strcasecmp($counselorIn, (string)($client['counselor_email'] ?? '')) !== 0) $counselorVerified = 1;
    else $counselorVerified = $wasVerified || !empty($_POST['confirm_counselor']) ? 1 : 0;
    $vals[] = $counselorVerified;
    // The client's own email the same way (email_verified): session reminders, the
    // outcome survey and the portal link go to it only once it is confirmed. One
    // copied from the public referral form stays unconfirmed until staff tick
    // "Confirmed with the client"; an address typed here counts as confirmed.
    $emailIn = $vals[2];
    $wasEmailVerified = !$client || (int)$client['email_verified'] === 1;
    if ($emailIn === null || !$client || strcasecmp($emailIn, (string)($client['email'] ?? '')) !== 0) $emailVerified = 1;
    else $emailVerified = $wasEmailVerified || !empty($_POST['confirm_email']) ? 1 : 0;
    $vals[] = $emailVerified;
    // RSA guidance: Pre-ETS serves students with disabilities, generally ages 14–21.
    // Advisory only — state rules vary, so we flag rather than block. It is added
    // to the success message, so a save that went through is never announced as an error.
    $ageNote = '';
    if (!$errors && $clientType === 'Pre-ETS') {
        $dob = trim($_POST['dob'] ?? '');
        if ($dob !== '') {
            $age = (new DateTime($dob))->diff(new DateTime('today'))->y; // valid_date() checked it above
            if ($age < 14 || $age > 21) {
                $ageNote = " Note: this client is {$age} years old — Pre-ETS generally serves students ages 14–21 under RSA rules. Double-check eligibility with your state agency.";
            }
        }
    }
    // A new client who may already have a record (3.0.49): said before saving, and saved
    // once staff tick that it is a different person.
    $dupWarning = false;
    if (!$errors && !$client && ($_POST['not_duplicate'] ?? '') !== '1') {
        $dups = possible_duplicate_clients($name, valid_date($dobIn) ?: null, trim((string)($_POST['phone'] ?? '')) ?: null, trim((string)($_POST['email'] ?? '')) ?: null);
        if ($dups) { $errors['name'] = duplicate_warning_text($dups, caseload_scoped() ? (int)$user['id'] : null) . ' If this is a different person, tick "This is a different person" below the name and save again.'; $dupWarning = true; set_form_errors($errors); }
    }
    if (!$errors) {
        if ($client) {
            // Re-opening an archived record (any status other than Closed) returns
            // it to the active lists; otherwise it would vanish from every list.
            $unarchive = $client['archived_at'] !== null && !str_starts_with($status, 'Closed');
            $vals[] = $client['id'];
            $oldAssignee = $client['assigned_to'] !== null ? (int)$client['assigned_to'] : null;
            $movedTasks = 0;
            $movedReferrals = 0;
            $movedSessions = 0;
            $movedSessionRows = [];
            $portalRevoked = 0;
            $redraftedInvoices = 0;
            // Draft invoices bill whoever the client's counselor is now (the same
            // Bill to that generate_invoice() writes); sent ones keep what was sent.
            // A counselor email not yet confirmed (counselor_verified = 0) is left off.
            $billTo = fn(array $c): string => implode("\n", array_filter(array_map(fn($v) => trim((string)$v),
                [$c['counselor_name'] ?? null, $c['counselor_agency'] ?? null, (int)($c['counselor_verified'] ?? 0) === 1 ? ($c['counselor_email'] ?? null) : null]), fn($v) => $v !== ''));
            $newBillTo = $billTo(['counselor_name' => $vals[9], 'counselor_agency' => $vals[12], 'counselor_email' => $vals[10], 'counselor_verified' => $counselorVerified]);
            $pdo = db();
            $pdo->beginTransaction();
            try {
                record_status_change((int)$client['id'], $client['status'], $status, $user['id'], trim($_POST['status_note'] ?? '') ?: ($closing ? 'Closed: ' . $closureData['reason'] : null));
                on_hold_save((int)$client['id'], $status, (string)$client['status'], trim((string)($_POST['status_note'] ?? '')), $holdUntil);
                if ($closing) client_closure_record((int)$client['id'], $status, $closureData, (int)$user['id']);
                $pdo->prepare('UPDATE clients SET name=?, dob=?, email=?, phone=?, address=?, status=?, client_type=?, school=?, assigned_to=?, counselor_name=?, counselor_email=?, counselor_phone=?, counselor_agency=?, a11y_screen_reader=?, a11y_format=?, a11y_primary_tech=?, a11y_contact=?, a11y_notes=?, preferred_language=?, counselor_verified=?, email_verified=?'
                    . ($unarchive ? ', archived_at=NULL' : '') . ' WHERE id=?')->execute($vals);
                $pdo->prepare('UPDATE clients SET guardian_name=?, guardian_phone=?, guardian_email=? WHERE id=?')->execute([...$guardianVals, (int)$client['id']]);
                $pdo->prepare('UPDATE clients SET vr_case_number=?, ipe_goal=?, ipe_date=?, ipe_amended_on=? WHERE id=?')->execute([...$planVals, (int)$client['id']]);
                $pdo->prepare('UPDATE clients SET vision_status=?, vision_onset_age=? WHERE id=?')->execute([...$visionVals, (int)$client['id']]);
                $pdo->prepare('UPDATE clients SET state=?, time_zone=? WHERE id=?')->execute([...$placeVals, (int)$client['id']]);
                // A portal link already emailed to the old address stops working when
                // the address changes: it may belong to someone else now.
                if (trim((string)($client['email'] ?? '')) !== '' && strcasecmp((string)($emailIn ?? ''), trim((string)$client['email'])) !== 0) {
                    $pr = $pdo->prepare('DELETE FROM portal_tokens WHERE client_id = ?');
                    $pr->execute([(int)$client['id']]);
                    $portalRevoked = $pr->rowCount();
                }
                if ($newBillTo !== $billTo($client)) {
                    $rb = $pdo->prepare("UPDATE invoices i JOIN training_tracks t ON t.id = i.track_id SET i.bill_to = ?
                        WHERE t.client_id = ? AND i.status = 'Draft' AND NOT (i.bill_to <=> ?)");
                    $rb->execute([$newBillTo, (int)$client['id'], $newBillTo]);
                    $redraftedInvoices = $rb->rowCount();
                }
                // The previous instructor's open tasks for this client follow the
                // client, so nobody is left holding work on a record they may no
                // longer be able to open. Tasks held by anyone else stay put.
                if ($oldAssignee !== null && $oldAssignee !== $assignedTo) {
                    $mv = $pdo->prepare("UPDATE tasks SET assigned_to = ? WHERE client_id = ? AND status = 'Open' AND assigned_to = ?");
                    $mv->execute([$assignedTo, (int)$client['id'], $oldAssignee]);
                    $movedTasks = $mv->rowCount();
                }
                // Upcoming sessions follow too (the rule users.php's caseload
                // transfer uses): otherwise the former instructor keeps getting
                // reminders for a client they may no longer be able to open.
                // A session needs an instructor, so they stay put when unassigning.
                // reminder_sent goes back to 0, so the new instructor still gets the
                // day-before reminder when the old one had already been sent it.
                if ($oldAssignee !== null && $assignedTo !== null && $oldAssignee !== $assignedTo) {
                    $sq = $pdo->prepare("SELECT id, start_at, end_at FROM scheduled_sessions WHERE client_id = ? AND user_id = ? AND status = 'Scheduled' AND start_at >= NOW() ORDER BY start_at, id FOR UPDATE");
                    $sq->execute([(int)$client['id'], $oldAssignee]);
                    $movedSessionRows = $sq->fetchAll();
                    if ($movedSessionRows) {
                        $ids = array_map(fn($r) => (int)$r['id'], $movedSessionRows);
                        $pdo->prepare('UPDATE scheduled_sessions SET user_id = ?, reminder_sent = 0 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                            ->execute([$assignedTo, ...$ids]);
                        $movedSessions = count($ids);
                    }
                }
                // A converted referral follows its client's instructor, so it stays
                // visible to whoever now holds the case.
                if ($oldAssignee !== $assignedTo) {
                    $mr = $pdo->prepare("UPDATE referrals SET assigned_to = ? WHERE client_id = ? AND status = 'Converted'");
                    $mr->execute([$assignedTo, (int)$client['id']]);
                    $movedReferrals = $mr->rowCount();
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            audit($user['id'], 'client.update', 'client', (int)$client['id'], $name
                . ($oldAssignee !== $assignedTo ? ' (instructor #' . ($oldAssignee ?? 'none') . ' -> #' . ($assignedTo ?? 'none') . ')' : ''));
            if ($counselorVerified === 1 && !$wasVerified) {
                audit($user['id'], 'client.counselor_confirm', 'client', (int)$client['id'],
                    strcasecmp((string)$counselorIn, (string)($client['counselor_email'] ?? '')) === 0 ? 'counselor email confirmed by staff' : 'counselor email entered by staff');
            }
            if ($emailIn !== null && $emailVerified === 1 && !$wasEmailVerified) {
                audit($user['id'], 'client.email_confirm', 'client', (int)$client['id'],
                    strcasecmp($emailIn, (string)($client['email'] ?? '')) === 0 ? 'client email confirmed with the client' : 'client email entered by staff');
            }
            if ($portalRevoked > 0) {
                audit($user['id'], 'portal.revoke', 'client', (int)$client['id'], 'client email changed');
            }
            if ($redraftedInvoices > 0) {
                audit($user['id'], 'invoice.bill_to', 'client', (int)$client['id'], $redraftedInvoices . ' draft invoice(s) re-addressed to the current counselor');
            }
            if ($movedTasks > 0) {
                audit($user['id'], 'task.reassign', 'client', (int)$client['id'], $movedTasks . ' open task(s) moved from instructor #'
                    . $oldAssignee . ' to ' . ($assignedTo !== null ? '#' . $assignedTo : 'unassigned'));
            }
            if ($movedSessions > 0) {
                audit($user['id'], 'session.reassign', 'client', (int)$client['id'], $movedSessions . ' upcoming session(s) moved from instructor #'
                    . $oldAssignee . ' to #' . $assignedTo);
            }
            if ($movedReferrals > 0) {
                audit($user['id'], 'referral.reassign', 'client', (int)$client['id'], $movedReferrals . ' converted referral(s) moved to '
                    . ($assignedTo !== null ? 'instructor #' . $assignedTo : 'unassigned'));
            }
            if ($unarchive) {
                audit($user['id'], 'client.unarchive', 'client', (int)$client['id'], 'reopened as ' . $status);
                flash('This record was archived; re-opening it returned it to the active client lists.');
            }
            // Every closure is surveyed (3.0.47), not only Closed - Rehabilitated.
            if (str_starts_with($status, 'Closed') && !str_starts_with((string)$client['status'], 'Closed')) {
                // One survey per closure: re-opening and re-closing a case must
                // not email the client again within 30 days of the last survey.
                $recentSurvey = db()->prepare('SELECT COUNT(*) FROM surveys WHERE client_id = ? AND sent_at > DATE_SUB(NOW(), INTERVAL 30 DAY)');
                $recentSurvey->execute([(int)$client['id']]);
                if ((int)$recentSurvey->fetchColumn() === 0) {
                    if (send_outcome_survey((int)$client['id'])) {
                        flash('An outcome survey was emailed to the client.');
                    } elseif (client_survey_by_phone((int)$client['id'])) {
                        flash('The client prefers phone or post, so the outcome survey was not emailed. Ask the questions by phone and enter the answers under After closure on the client page.', 'info');
                    } elseif (filter_var($vals[2] ?? '', FILTER_VALIDATE_EMAIL) && $emailVerified !== 1) {
                        // send_outcome_survey() skips an address not yet confirmed with the
                        // client: nothing failed, so this is not reported as an error.
                        flash('No outcome survey was sent because the client\'s email has not been confirmed.', 'info');
                    } elseif (filter_var($vals[2] ?? '', FILTER_VALIDATE_EMAIL)) {
                        // The failed survey row is removed, so closing the case again retries it.
                        flash('The outcome survey email could not be sent. Close the case again later or check the email settings.', 'error');
                    }
                }
            }
            $told = ['to' => false, 'from' => false];
            if ($movedSessionRows) {
                try {
                    $told = send_session_move_notices($movedSessionRows, $oldAssignee, $assignedTo, $name, (int)$user['id']);
                } catch (Throwable $e) {
                    // The change is saved: a notice that fails must not report it as lost.
                    error_log('Reassignment session notices failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
                }
            }
            flash('Client updated.' . ($movedTasks > 0 ? ' ' . $movedTasks . ' open task' . ($movedTasks === 1 ? '' : 's')
                . ' for this client ' . ($movedTasks === 1 ? 'was' : 'were') . ' moved from the previous instructor'
                . ($assignedTo !== null ? ' to the new one.' : ' and ' . ($movedTasks === 1 ? 'is' : 'are') . ' now unassigned.') : '')
                . ($movedSessions > 0 ? ' ' . $movedSessions . ' upcoming session' . ($movedSessions === 1 ? '' : 's') . ' moved to the new instructor.'
                    . ($told['to'] ? ' The new instructor was sent the list.' : '') . ($told['from'] ? ' The previous instructor was told they were moved.' : '') : '')
                . ($portalRevoked > 0 ? ' The email address changed, so the portal link sent to the old address was turned off. Send a new portal link when the client needs one.' : '')
                . ($redraftedInvoices > 0 ? ' ' . $redraftedInvoices . ' draft invoice' . ($redraftedInvoices === 1 ? ' now bills' : 's now bill') . ' the counselor as entered here.' : '')
                . $ageNote
                . ($status === 'Active Training' && $client['status'] !== 'Active Training' && ($an = active_without_authorization((int)$client['id'])) ? ' ' . $an : ''));
            redirect('client.php?id=' . (int)$client['id']);
        } else {
            // The same form sent twice (Enter pressed again while it loads) creates the
            // client once; the second answer goes to the client the first created.
            $clientKey = web_idem_key($_POST);
            if (($firstId = web_once_get($clientKey)) !== null) {
                flash('This client was already created (the form was sent twice), so it was saved once.', 'info');
                redirect('client.php?id=' . $firstId);
            }
            db()->prepare('INSERT INTO clients (name, dob, email, phone, address, status, client_type, school, assigned_to, counselor_name, counselor_email, counselor_phone, counselor_agency, a11y_screen_reader, a11y_format, a11y_primary_tech, a11y_contact, a11y_notes, preferred_language, counselor_verified, email_verified) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
            $newId = (int)db()->lastInsertId();
            db()->prepare('UPDATE clients SET guardian_name=?, guardian_phone=?, guardian_email=? WHERE id=?')->execute([...$guardianVals, $newId]);
            db()->prepare('UPDATE clients SET vr_case_number=?, ipe_goal=?, ipe_date=?, ipe_amended_on=? WHERE id=?')->execute([...$planVals, $newId]);
            db()->prepare('UPDATE clients SET vision_status=?, vision_onset_age=? WHERE id=?')->execute([...$visionVals, $newId]);
            db()->prepare('UPDATE clients SET state=?, time_zone=? WHERE id=?')->execute([...$placeVals, $newId]);
            web_once_set($clientKey, $newId);
            record_status_change($newId, null, $status, $user['id'], 'Client created');
            on_hold_save($newId, $status, '', trim((string)($_POST['status_note'] ?? '')), $holdUntil);
            if ($closing) client_closure_record($newId, $status, $closureData, (int)$user['id']);
            audit($user['id'], 'client.create', 'client', $newId, $name);
            flash('Client created.' . $ageNote);
            redirect('client.php?id=' . $newId);
        }
    }
}

// The form shows what was just posted; otherwise a draft kept while the user
// signed in again (old input, WCAG 3.3.7); otherwise the stored record.
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($GLOBALS['OLD_INPUT'] ?: null);
$raw = fn(string $k): ?string => is_string($src[$k] ?? null) ? $src[$k] : null;
$v = fn(string $k) => h($raw($k) ?? (string)($client[$k] ?? ''));
page_header(($errors ? 'Error — ' : '') . ($client ? 'Edit ' . $client['name'] : 'Add client'), $user, 'clients', $client ? [['client.php?id=' . (int)$client['id'], $client['name']]] : []);
?>
<h1><?= $client ? 'Edit ' . h($client['name']) : 'Add client' ?></h1>
<?php if ($client && $client['archived_at'] !== null): ?>
<p class="muted">This record is archived and hidden from day-to-day lists. Changing its status to anything other than Closed re-opens it and returns it to the active lists.</p>
<?php endif; ?>
<?php // Focus moves to this summary once on load; each entry links to its field.
// (The messages already name their field, so no label is put in front of them.) ?>
<?= form_error_summary(array_fill_keys(array_keys($errors), '')) ?>

<p class="hint">Fields marked with an asterisk (*) are required.</p>
<form method="post" class="stacked" novalidate>
  <?= csrf_field() ?><?= web_idem_field() ?>
  <fieldset>
    <legend>Demographics</legend>
    <div class="field">
      <label for="name">Full name <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="text" id="name" name="name" required value="<?= $v('name') ?>"
        <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="err-name"' : '' ?>>
      <?php if (isset($errors['name'])): ?><p class="field-error" id="err-name"><?= h($errors['name']) ?></p><?php endif; ?>
      <?php if (!empty($dupWarning)): ?><label><input type="checkbox" name="not_duplicate" value="1"> This is a different person</label><?php endif; ?>
    </div>
    <div class="field"><label for="dob">Date of birth</label>
      <input type="date" id="dob" name="dob" max="<?= date('Y-m-d') ?>" value="<?= $v('dob') ?>" <?= field_aria('dob') ?>><?= field_error('dob') ?></div>
    <div class="field"><label for="vision_status">Vision status</label>
      <select id="vision_status" name="vision_status"><option value="">Not recorded</option><?= select_options(array_values(array_unique(array_merge(cms_list_items('vision_statuses'), ($vsNow = html_entity_decode($v('vision_status'), ENT_QUOTES)) !== '' ? [$vsNow] : []))), $vsNow ?: null) ?></select></div>
    <div class="field"><label for="vision_onset_age">Age when vision loss began</label>
      <input type="number" id="vision_onset_age" name="vision_onset_age" min="0" max="120" value="<?= $v('vision_onset_age') ?>" <?= field_aria('vision_onset_age') ?>><?= field_error('vision_onset_age') ?></div>
    <div class="field"><label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= $v('email') ?>" <?= field_aria('email') ?>><?= field_error('email') ?></div>
    <?php if ($client && trim((string)($client['email'] ?? '')) !== '' && (int)$client['email_verified'] !== 1): ?>
    <?php // An address typed into the public referral form is not checked: nothing is sent to it until confirmed. ?>
    <div class="field">
      <p class="hint" id="email-unverified"><strong>Unverified — confirm before anything is sent.</strong> <?= h($client['email']) ?> came from the public referral form and has not been checked with the client, so no session reminder, outcome survey or portal link goes to it yet. Confirm it with the client first. Typing a different address above also confirms the new one.</p>
      <label><input type="checkbox" name="confirm_email" value="1" aria-describedby="email-unverified"<?= $raw('confirm_email') !== null ? ' checked' : '' ?>> Confirmed with the client</label>
    </div>
    <?php endif; ?>
    <div class="field"><label for="phone">Phone</label>
      <input type="tel" id="phone" name="phone" value="<?= $v('phone') ?>" <?= field_aria('phone') ?>><?= field_error('phone') ?></div>
    <div class="field"><label for="address">Address</label>
      <input type="text" id="address" name="address" value="<?= $v('address') ?>" <?= field_aria('address') ?>><?= field_error('address') ?></div>
    <div class="field"><label for="state">State</label>
      <select id="state" name="state"><?= kv_options(US_STATES, html_entity_decode($v('state'), ENT_QUOTES), 'Not recorded') ?></select></div>
    <div class="field"><label for="time_zone">Time zone for the client's emails and portal</label>
      <select id="time_zone" name="time_zone"><?= kv_options(US_TIME_ZONES, html_entity_decode($v('time_zone'), ENT_QUOTES), 'The state\'s usual zone, or the office\'s') ?></select></div>
  </fieldset>

  <fieldset>
    <legend>Case type</legend>
    <div class="field">
      <label for="client_type">Client type <span class="hint">VR = vocational rehabilitation. Pre-ETS = student with a disability receiving pre-employment transition services (Section 113)</span></label>
      <select id="client_type" name="client_type"><?= select_options(cms_choices('client_types', $client['client_type'] ?? null), $raw('client_type') ?? $client['client_type'] ?? 'VR') ?></select>
    </div>
    <div class="field">
      <label for="school">School / district <span class="hint">For Pre-ETS students</span></label>
      <input type="text" id="school" name="school" value="<?= $v('school') ?>" <?= field_aria('school') ?>><?= field_error('school') ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Case status</legend>
    <div class="field">
      <label for="status">Status</label>
      <?php if ($client): ?>
      <span class="hint" id="status-hint">Changing the status to Closed - Rehabilitated emails the client an outcome survey when you save, unless one was sent in the last 30 days.</span>
      <?php endif; ?>
      <select id="status" name="status"<?= $client ? ' aria-describedby="status-hint" data-was="' . h($client['status']) . '"' : '' ?>><?= select_options(CLIENT_STATUSES, $raw('status') ?? $client['status'] ?? 'Pending Assessment') ?></select>
    </div>
    <?php // Asked only when the case is being closed: the script shows it when a Closed status is
    // chosen; it is shown from the start when that was already chosen or refused.
    $cv = fn(string $k): string => h($raw($k) ?? '');
    $showClosure = str_starts_with((string)($raw('status') ?? ''), 'Closed') && ($raw('status') !== ($client['status'] ?? null)) || array_filter(array_keys(form_errors()), fn($k) => str_starts_with((string)$k, 'closure_')); ?>
    <fieldset id="closure-fields"<?= $showClosure ? '' : ' hidden' ?>>
      <legend>Closing the case</legend>
      <?php if ($client && ($loose = closure_checklist((int)$client['id']))): ?>
      <p><strong>Still open on this record:</strong></p>
      <ul><?php foreach ($loose as [$txt, $href]): ?><li><a href="<?= h($href) ?>"><?= h($txt) ?></a></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <div class="field"><label for="closure_reason">Why is the case closing?</label>
        <select id="closure_reason" name="closure_reason" <?= field_aria('closure_reason') ?>><option value="">Choose a reason</option>
          <?php foreach (cms_list_items('closure_reasons') as $cr): ?><option value="<?= h($cr) ?>"<?= ($raw('closure_reason') ?? '') === $cr ? ' selected' : '' ?>><?= h($cr) ?></option><?php endforeach; ?>
        </select><?= field_error('closure_reason') ?></div>
      <div class="field"><label for="closure_note">Note (required for Other)</label>
        <input type="text" id="closure_note" name="closure_note" maxlength="255" value="<?= $cv('closure_note') ?>" <?= field_aria('closure_note') ?>><?= field_error('closure_note') ?></div>
      <fieldset><legend>Employed at closure?</legend>
        <?php $ce = $raw('closure_employed') ?? ''; ?>
        <label><input type="radio" name="closure_employed" value="yes"<?= $ce === 'yes' ? ' checked' : '' ?>> Yes</label>
        <label><input type="radio" name="closure_employed" value="no"<?= $ce === 'no' ? ' checked' : '' ?>> No</label>
        <label><input type="radio" name="closure_employed" value=""<?= $ce === '' ? ' checked' : '' ?>> Not recorded</label>
      </fieldset>
      <div class="grid grid-2">
        <div class="field"><label for="closure_employer">Employer</label><input type="text" id="closure_employer" name="closure_employer" maxlength="190" value="<?= $cv('closure_employer') ?>" <?= field_aria('closure_employer') ?>><?= field_error('closure_employer') ?></div>
        <div class="field"><label for="closure_job_title">Job title</label><input type="text" id="closure_job_title" name="closure_job_title" maxlength="120" value="<?= $cv('closure_job_title') ?>" <?= field_aria('closure_job_title') ?>><?= field_error('closure_job_title') ?></div>
        <div class="field"><label for="closure_job_start">Job start date</label><input type="date" id="closure_job_start" name="closure_job_start" value="<?= $cv('closure_job_start') ?>" <?= field_aria('closure_job_start') ?>><?= field_error('closure_job_start') ?></div>
        <div class="field"><label for="closure_hours">Hours a week</label><input type="text" inputmode="decimal" id="closure_hours" name="closure_hours" maxlength="6" value="<?= $cv('closure_hours') ?>" <?= field_aria('closure_hours') ?>><?= field_error('closure_hours') ?></div>
        <div class="field"><label for="closure_wage">Hourly wage (dollars)</label><input type="text" inputmode="decimal" id="closure_wage" name="closure_wage" maxlength="8" value="<?= $cv('closure_wage') ?>" <?= field_aria('closure_wage') ?>><?= field_error('closure_wage') ?></div>
      </div>
      <div class="field"><label for="closure_independence">Independence outcome (for a goal without a job, such as reading mail or using a phone on their own)</label>
        <input type="text" id="closure_independence" name="closure_independence" maxlength="500" value="<?= $cv('closure_independence') ?>" <?= field_aria('closure_independence') ?>><?= field_error('closure_independence') ?></div>
    </fieldset>
    <script>(function(){var s=document.getElementById('status'),f=document.getElementById('closure-fields');if(!s||!f)return;var was=s.getAttribute('data-was')||'';function sync(){f.hidden=!(s.value.indexOf('Closed')===0&&s.value!==was);}s.addEventListener('change',sync);sync();<?= $showClosure ? 'f.hidden=false;' : '' ?>})();</script>
    <div class="field">
      <label for="status_note">Reason for status change <span class="hint">Required for On Hold; recorded in the history</span></label>
      <input type="text" id="status_note" name="status_note" maxlength="255" value="<?= h($raw('status_note') ?? '') ?>" <?= field_aria('status_note') ?>><?= field_error('status_note') ?>
    </div>
    <div class="field">
      <label for="on_hold_until">Expected return date (On Hold only, optional)</label>
      <input type="date" id="on_hold_until" name="on_hold_until" value="<?= h($raw('on_hold_until') ?? (string)($client['on_hold_until'] ?? '')) ?>" <?= field_aria('on_hold_until') ?>><?= field_error('on_hold_until') ?>
    </div>
    <?php if (caseload_scoped()): ?>
    <p class="field"><strong>Assigned instructor:</strong> you (<?= h((string)($user['name'] ?? '')) ?>).
      <span class="hint">Clients you add or edit stay assigned to you; ask an administrator to reassign one.</span></p>
    <?php else: ?>
    <div class="field">
      <label for="assigned_to">Assigned instructor</label>
      <select id="assigned_to" name="assigned_to" <?= field_aria('assigned_to') ?>>
        <option value="0">— Unassigned —</option>
        <?php $selAssignee = (int)($raw('assigned_to') ?? $client['assigned_to'] ?? 0);
        // Only staff who can open client records are offered (one permission
        // lookup per group/role, not per user).
        $clientAccess = [];
        $assigneeOpts = [];
        foreach (db()->query('SELECT * FROM users WHERE is_active = 1 ORDER BY name') as $u) {
            if ($clientAccess[($u['group_id'] ?? '') . '|' . ($u['role'] ?? '')] ??= in_array('clients', resolve_perms($u)['perms'], true)) {
                $assigneeOpts[] = ['id' => $u['id'], 'name' => $u['name']];
            }
        }
        // A deactivated (or no longer permitted) instructor still assigned to this
        // client stays selected, so saving another change never silently unassigns the client.
        if (!empty($client['assigned_to']) && !in_array((int)$client['assigned_to'], array_map('intval', array_column($assigneeOpts, 'id')), true)) {
            $ia = db()->prepare('SELECT id, name, is_active FROM users WHERE id = ?');
            $ia->execute([(int)$client['assigned_to']]);
            if ($inactive = $ia->fetch()) $assigneeOpts[] = ['id' => $inactive['id'], 'name' => $inactive['name'] . ((int)$inactive['is_active'] ? ' (no client access)' : ' (inactive)')];
        }
        foreach ($assigneeOpts as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $selAssignee === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select><?= field_error('assigned_to') ?>
    </div>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend>Vocational rehabilitation (VR) counselor</legend>
    <div class="field"><label for="counselor_name">Counselor name</label>
      <input type="text" id="counselor_name" name="counselor_name" value="<?= $v('counselor_name') ?>" <?= field_aria('counselor_name') ?>><?= field_error('counselor_name') ?></div>
    <div class="field"><label for="counselor_agency">Agency / district</label>
      <input type="text" id="counselor_agency" name="counselor_agency" value="<?= $v('counselor_agency') ?>" <?= field_aria('counselor_agency') ?>><?= field_error('counselor_agency') ?></div>
    <div class="field"><label for="counselor_email">Counselor email</label>
      <input type="email" id="counselor_email" name="counselor_email" value="<?= $v('counselor_email') ?>" <?= field_aria('counselor_email') ?>><?= field_error('counselor_email') ?></div>
    <?php if ($client && trim((string)($client['counselor_email'] ?? '')) !== '' && (int)($client['counselor_verified'] ?? 0) !== 1): ?>
    <?php // An unconfirmed address (e.g. from the public referral form): no report goes to it until confirmed. ?>
    <div class="field">
      <p class="hint" id="counselor-unverified"><strong>Unverified — confirm before reports are sent.</strong> <?= h($client['counselor_email']) ?> has not been confirmed (an address from the public referral form, for example, is not checked), so no monthly progress report, counselor portal link or email filing uses it yet. Check it against the authorization paperwork or the agency first. Typing a different address above also confirms the new one.</p>
      <label><input type="checkbox" name="confirm_counselor" value="1" aria-describedby="counselor-unverified"<?= $raw('confirm_counselor') !== null ? ' checked' : '' ?>> Confirm this is the VR counselor's email address</label>
    </div>
    <?php endif; ?>
    <div class="field"><label for="counselor_phone">Counselor phone</label>
      <input type="tel" id="counselor_phone" name="counselor_phone" value="<?= $v('counselor_phone') ?>" <?= field_aria('counselor_phone') ?>><?= field_error('counselor_phone') ?></div>
  </fieldset>

  <fieldset>
    <legend>Plan for employment (IPE)</legend>
    <div class="field"><label for="vr_case_number">VR case or participant number</label>
      <input type="text" id="vr_case_number" name="vr_case_number" maxlength="60" value="<?= $v('vr_case_number') ?>" <?= field_aria('vr_case_number') ?>><?= field_error('vr_case_number') ?></div>
    <div class="field"><label for="ipe_goal">IPE employment goal</label>
      <input type="text" id="ipe_goal" name="ipe_goal" maxlength="255" value="<?= $v('ipe_goal') ?>" <?= field_aria('ipe_goal') ?>><?= field_error('ipe_goal') ?></div>
    <div class="field"><label for="ipe_date">IPE date</label>
      <input type="date" id="ipe_date" name="ipe_date" value="<?= $v('ipe_date') ?>" <?= field_aria('ipe_date') ?>><?= field_error('ipe_date') ?></div>
    <div class="field"><label for="ipe_amended_on">Latest IPE amendment</label>
      <input type="date" id="ipe_amended_on" name="ipe_amended_on" value="<?= $v('ipe_amended_on') ?>" <?= field_aria('ipe_amended_on') ?>><?= field_error('ipe_amended_on') ?></div>
  </fieldset>

  <fieldset>
    <legend>Parent or guardian</legend>
    <div class="field"><label for="guardian_name">Guardian name</label>
      <input type="text" id="guardian_name" name="guardian_name" maxlength="120" value="<?= $v('guardian_name') ?>" <?= field_aria('guardian_name') ?>><?= field_error('guardian_name') ?></div>
    <div class="field"><label for="guardian_phone">Guardian phone</label>
      <input type="tel" id="guardian_phone" name="guardian_phone" maxlength="40" value="<?= $v('guardian_phone') ?>" <?= field_aria('guardian_phone') ?>><?= field_error('guardian_phone') ?></div>
    <div class="field"><label for="guardian_email">Guardian email</label>
      <input type="email" id="guardian_email" name="guardian_email" maxlength="190" value="<?= $v('guardian_email') ?>" <?= field_aria('guardian_email') ?>><?= field_error('guardian_email') ?></div>
  </fieldset>

  <fieldset>
    <legend>Accessibility &amp; access needs</legend>
    <p class="hint">How this client prefers to receive information and what assistive technology they use, so staff and deliverables can meet their needs.</p>
    <div class="field">
      <?php // An unchecked box posts nothing, so on a re-shown form only the submitted state counts. ?>
      <label><input type="checkbox" name="a11y_screen_reader" value="1" <?= ($src !== null ? !empty($src['a11y_screen_reader']) : !empty($client['a11y_screen_reader'] ?? 0)) ? 'checked' : '' ?>> This client uses a screen reader</label>
    </div>
    <div class="field">
      <label for="a11y_format">Preferred document format</label>
      <select id="a11y_format" name="a11y_format">
        <option value="">— No preference recorded —</option>
        <?= select_options(A11Y_FORMATS, $raw('a11y_format') ?? $client['a11y_format'] ?? null) ?>
      </select>
    </div>
    <div class="field">
      <label for="a11y_primary_tech">Primary assistive technology <span class="hint">e.g. JAWS, ZoomText, Dragon, a specific AAC device</span></label>
      <input type="text" id="a11y_primary_tech" name="a11y_primary_tech" maxlength="120" value="<?= $v('a11y_primary_tech') ?>" <?= field_aria('a11y_primary_tech') ?>><?= field_error('a11y_primary_tech') ?>
    </div>
    <div class="field">
      <label for="a11y_contact">Preferred contact method</label>
      <select id="a11y_contact" name="a11y_contact">
        <option value="">— No preference recorded —</option>
        <?= select_options(A11Y_CONTACTS, $raw('a11y_contact') ?? $client['a11y_contact'] ?? null) ?>
      </select>
    </div>
    <div class="field">
      <label for="preferred_language">Preferred language</label>
      <p class="hint" id="lang-hint">The client's portal opens in this language, and their emails (portal link, session reminders, outcome survey, guardian consent request) are sent in it.</p>
      <?php $curLang = ($raw('preferred_language') ?? $client['preferred_language'] ?? 'en') === 'es' ? 'es' : 'en'; ?>
      <select id="preferred_language" name="preferred_language" aria-describedby="lang-hint">
        <option value="en" <?= $curLang === 'en' ? 'selected' : '' ?>>English</option>
        <option value="es" <?= $curLang === 'es' ? 'selected' : '' ?> lang="es">Español</option>
      </select>
    </div>
    <div class="field">
      <label for="a11y_notes">Access notes <span class="hint">communication preferences and accommodations</span></label>
      <p class="hint" id="a11y-notes-hint">Shown to the client on their portal, where they can also change it. Keep internal remarks in case notes.</p>
      <textarea id="a11y_notes" name="a11y_notes" rows="3" <?= field_aria('a11y_notes', 'a11y-notes-hint') ?>><?= $v('a11y_notes') ?></textarea><?= field_error('a11y_notes') ?>
    </div>
  </fieldset>

  <div class="page-actions">
    <?php // Closing a case as rehabilitated emails the client: confirm that once, on the button
    // (not the form's onsubmit, which session-forms.js would run a second time). ?>
    <button type="submit" class="btn btn-primary"<?= $client ? ' onclick="var s=document.getElementById(\'status\');return !s||s.value!==\'Closed - Rehabilitated\'||s.getAttribute(\'data-was\')===\'Closed - Rehabilitated\'||confirm(\'Save with the status Closed - Rehabilitated? This emails the client an outcome survey, unless one was sent in the last 30 days.\');"' : '' ?>><?= $client ? 'Save changes' : 'Create client' ?></button>
    <a class="btn btn-secondary" href="<?= $client ? 'client.php?id=' . (int)$client['id'] : 'clients.php' ?>">Cancel<span class="visually-hidden"> and return to <?= $client ? h($client['name']) : 'the client list' ?></span></a>
  </div>
</form>
<?php page_footer();
