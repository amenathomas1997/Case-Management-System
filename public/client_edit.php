<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/client_merge.php'; // send_session_move_notices()
csrf_check();
$user = require_login();
require_perm('clients');

/** A save refused because the record changed since the form was opened (4.27.0). */
final class ClientEditConflict extends RuntimeException {}

/** The record's fields the edit form shows, with the names said when someone else changed them (4.27.0). */
const CLIENT_EDIT_TRACKED = ['name' => 'Full name', 'dob' => 'Date of birth', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address',
    'status' => 'Status', 'client_type' => 'Client type', 'school' => 'School', 'assigned_to' => 'Instructor',
    'counselor_name' => 'Counselor name', 'counselor_email' => 'Counselor email', 'counselor_phone' => 'Counselor phone', 'counselor_agency' => 'Counselor agency',
    'a11y_screen_reader' => 'Uses a screen reader', 'a11y_format' => 'Preferred format', 'a11y_primary_tech' => 'Primary assistive technology',
    'a11y_contact' => 'Preferred contact', 'a11y_notes' => 'Access notes', 'preferred_language' => 'Language',
    'guardian_name' => 'Guardian name', 'guardian_phone' => 'Guardian phone', 'guardian_email' => 'Guardian email',
    'vr_case_number' => 'VR case number', 'ipe_goal' => 'IPE employment goal', 'ipe_date' => 'IPE date', 'ipe_amended_on' => 'IPE amendment date',
    'vision_status' => 'Vision status', 'vision_onset_age' => 'Age when vision loss began', 'state' => 'State', 'time_zone' => 'Time zone', 'office_id' => 'Office'];
/** One tracked field as the form holds it: from a stored row ($fromPost false) or from what was posted. */
function client_edit_norm(array $src, string $f, bool $fromPost): string {
    $v = $src[$f] ?? null;
    if ($f === 'a11y_screen_reader') return !empty($v) ? '1' : '0';
    if ($f === 'assigned_to' || $f === 'office_id') return (string)(int)(is_scalar($v) ? $v : 0);
    if ($f === 'preferred_language') return $v === 'es' ? 'es' : 'en';
    return is_scalar($v) ? str_replace("\r\n", "\n", trim((string)$v)) : '';
}
/** The tracked fields of a stored row, as the form holds them. */
function client_edit_snapshot(array $row): array {
    $out = [];
    foreach (array_keys(CLIENT_EDIT_TRACKED) as $f) $out[$f] = client_edit_norm($row, $f, false);
    return $out;
}

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
    $status = in_array($_POST['status'] ?? '', cms_choices('client_statuses', $client['status'] ?? null), true) ? $_POST['status'] : 'Pending Assessment';
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
    // The office (4.22.0), when the organisation uses offices and the form sent one.
    $officeSent = array_key_exists('office_id', $_POST);
    $officeIn = $officeSent ? office_input($_POST['office_id'], isset($client['office_id']) ? (int)$client['office_id'] : null) : null;
    // One closed (or removed) since the form was opened is said, not quietly dropped.
    if ($officeSent && $officeIn === null && ($_POST['office_id'] ?? '') !== '') { $errors['office_id'] = 'Office: that office has been closed. Choose another, or No office.'; set_form_errors($errors); }
    // Required (4.19.0) for a new client, and for one whose state is already known: it sets the time zone
    // reminders and texts follow. An older record with none can still be saved (and closed); Data quality lists it.
    if ($stateIn === null && (!$client || !empty($client['state']))) { $errors['state'] = 'State: choose the state the client lives in. It sets their time zone for reminders and texts.'; set_form_errors($errors); }
    // Custom fields (4.24.0): those sent are checked here and saved with the record.
    [$cfVals, $cfErrs] = custom_input($_POST, custom_fields_all(true), false, $client ? custom_values_for((int)$client['id']) : []);
    if ($cfErrs) { $errors += $cfErrs; set_form_errors($errors); }
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
            $stale = false;
            try {
                // Two people editing the same client (4.27.0): the row is locked first, and a
                // save is refused when the record changed after this form was opened (its
                // updated_at then differs from the one the form carried), so the later save
                // never quietly overwrites the earlier one. A form opened before this
                // release carries no value and is saved as before.
                $lk = $pdo->prepare('SELECT * FROM clients WHERE id = ? FOR UPDATE');
                $lk->execute([(int)$client['id']]);
                $locked = $lk->fetch();
                if (!$locked) throw new RuntimeException('The client row is gone.');
                $loadedAt = is_string($_POST['loaded_at'] ?? null) ? $_POST['loaded_at'] : null;
                // "Closing" is judged on the locked row, so two simultaneous closures
                // cannot both record a closure (or both email the survey).
                $closingNow = str_starts_with($status, 'Closed') && $locked['status'] !== $status;
                if (($loadedAt !== null && $loadedAt !== (string)$locked['updated_at']) || ($closingNow && $closureData === null)) {
                    $freshLoadedAt = (string)$locked['updated_at'];
                    $conflictRow = $locked;
                    throw new ClientEditConflict();
                }
                $closing = $closingNow;
                $client['status'] = (string)$locked['status'];
                $unarchive = $locked['archived_at'] !== null && !str_starts_with($status, 'Closed');
                $oldAssignee = $locked['assigned_to'] !== null ? (int)$locked['assigned_to'] : null;
                record_status_change((int)$client['id'], $client['status'], $status, $user['id'], trim($_POST['status_note'] ?? '') ?: ($closing ? 'Closed: ' . $closureData['reason'] : null));
                on_hold_save((int)$client['id'], $status, (string)$client['status'], trim((string)($_POST['status_note'] ?? '')), $holdUntil);
                if ($closing) client_closure_record((int)$client['id'], $status, $closureData, (int)$user['id']);
                $pdo->prepare('UPDATE clients SET name=?, dob=?, email=?, phone=?, address=?, status=?, client_type=?, school=?, assigned_to=?, counselor_name=?, counselor_email=?, counselor_phone=?, counselor_agency=?, a11y_screen_reader=?, a11y_format=?, a11y_primary_tech=?, a11y_contact=?, a11y_notes=?, preferred_language=?, counselor_verified=?, email_verified=?'
                    . ($unarchive ? ', archived_at=NULL' : '') . ' WHERE id=?')->execute($vals);
                $pdo->prepare('UPDATE clients SET guardian_name=?, guardian_phone=?, guardian_email=? WHERE id=?')->execute([...$guardianVals, (int)$client['id']]);
                $pdo->prepare('UPDATE clients SET vr_case_number=?, ipe_goal=?, ipe_date=?, ipe_amended_on=? WHERE id=?')->execute([...$planVals, (int)$client['id']]);
                $pdo->prepare('UPDATE clients SET vision_status=?, vision_onset_age=? WHERE id=?')->execute([...$visionVals, (int)$client['id']]);
                $pdo->prepare('UPDATE clients SET state=?, time_zone=? WHERE id=?')->execute([...$placeVals, (int)$client['id']]);
                if ($officeSent) $pdo->prepare('UPDATE clients SET office_id=? WHERE id=?')->execute([$officeIn, (int)$client['id']]);
                custom_save($cfVals, (int)$client['id']);
                // A portal link already emailed to the old address stops working when
                // the address changes: it may belong to someone else now.
                // Texts (4.17.0): the agreement as recorded here; a new number needs it again.
                $smsNote = sms_consent_save((int)$client['id'], !empty($_POST['sms_consent']), (string)($_POST['sms_consent_source'] ?? ''), (string)($client['phone'] ?? ''), (int)$user['id']);
                if (trim((string)($client['email'] ?? '')) !== '' && strcasecmp((string)($emailIn ?? ''), trim((string)$client['email'])) !== 0) {
                    $pr = $pdo->prepare('DELETE FROM portal_tokens WHERE client_id = ?');
                    $pr->execute([(int)$client['id']]);
                    $portalRevoked = $pr->rowCount();
                }
                // 4.28.0: drafts are re-addressed the way generate_invoice() addresses them for
                // each draft's bill mode (agency billing address for VR, the client for private
                // pay), by the shared helper in inc/billing.php. It is called on every save, so a
                // changed client name, address or email re-addresses private-pay drafts too; it
                // writes only the drafts whose Bill to differs.
                if (function_exists('draft_invoices_readdress')) {
                    $redraftedInvoices = draft_invoices_readdress((int)$client['id']);
                } elseif ($newBillTo !== $billTo($client)) {
                    $rb = $pdo->prepare("UPDATE invoices i JOIN training_tracks t ON t.id = i.track_id SET i.bill_to = ?
                        WHERE t.client_id = ? AND i.status = 'Draft' AND NOT (i.bill_to <=> ?)");
                    $rb->execute([$newBillTo, (int)$client['id'], $newBillTo]);
                    $redraftedInvoices = $rb->rowCount();
                }
                // A corrected client email (4.28.0): reminders already marked as handled for the
                // old address (sent, or skipped because it was unusable) go out again to the new one.
                // Only the client's side is redone (4.29.0): the instructor's reminder, already sent
                // when reminder_sent is 1, is kept as sent; and an address that is cleared, or not
                // yet confirmed (the client is not written to), redoes nothing.
                if (strcasecmp(trim((string)($emailIn ?? '')), trim((string)($locked['email'] ?? ''))) !== 0
                    && trim((string)($emailIn ?? '')) !== '' && $emailVerified === 1) {
                    $pdo->prepare("UPDATE scheduled_sessions SET staff_reminded_at = IF(reminder_sent = 1, COALESCE(staff_reminded_at, NOW()), staff_reminded_at),
                        reminder_sent = 0, client_reminded_at = NULL
                        WHERE client_id = ? AND status = 'Scheduled' AND start_at >= NOW()")->execute([(int)$client['id']]);
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
                        $pdo->prepare('UPDATE scheduled_sessions SET user_id = ?, reminder_sent = 0, client_reminded_at = NULL, staff_reminded_at = NULL WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
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
            } catch (ClientEditConflict $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $stale = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            if ($stale) {
                // Nothing was saved. The form comes back with this person's own edits (the
                // fields that differ from the values the form was opened with) and the saved
                // values for everything else, so saving again never quietly undoes the other
                // person's change (a closure, a reassignment); the fields they changed are named.
                // It carries the record's current version, so a second save goes through.
                // Custom-field answers and the text-message agreement keep what was posted.
                $orig = json_decode(is_string($_POST['orig'] ?? null) ? $_POST['orig'] : '', true);
                $current = client_edit_snapshot($conflictRow);
                $theirs = [];
                if (is_array($orig)) {
                    foreach (CLIENT_EDIT_TRACKED as $f => $label) {
                        if (!array_key_exists($f, $orig)) continue;
                        if ($current[$f] !== (string)$orig[$f]) $theirs[] = $label;
                        $onForm = $f === 'a11y_screen_reader' || array_key_exists($f, $_POST);
                        if (!$onForm || client_edit_norm($_POST, $f, true) !== (string)$orig[$f]) continue; // this person's edit stays
                        if ($f === 'a11y_screen_reader') {
                            if ($current[$f] === '1') $_POST[$f] = '1'; else unset($_POST[$f]);
                        } else {
                            $_POST[$f] = $current[$f];
                        }
                    }
                }
                $freshOrig = $current;
                $who = $theirs ? (count($theirs) === 1 ? $theirs[0] : implode(', ', array_slice($theirs, 0, -1)) . ' and ' . end($theirs)) : '';
                $errors['stale'] = $who !== ''
                    ? 'Someone else changed ' . $who . ' since you opened this record. Those fields now show the saved values, and your own changes are kept; review and save again.'
                    : 'Someone else changed this record since you opened it. Your changes are below; review and save again.';
                set_form_errors($errors);
            } else {
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
                audit($user['id'], 'invoice.bill_to', 'client', (int)$client['id'], $redraftedInvoices . ' draft invoice(s) re-addressed (current counselor or client details)');
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
                    $GLOBALS['mail_last_outcome'] = null;
                    if (send_outcome_survey((int)$client['id'])) {
                        flash('An outcome survey was emailed to the client.');
                    } elseif (($GLOBALS['mail_last_outcome'] ?? null) === 'unknown') {
                        flash('The email service did not confirm the outcome survey email, so it may still arrive. Check later whether it was answered before sending it again.', 'info');
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
                . ($redraftedInvoices > 0 ? ' ' . $redraftedInvoices . ' draft invoice' . ($redraftedInvoices === 1 ? ' was' : 's were') . ' re-addressed to match these details.' : '')
                . $ageNote
                . (($smsNote ?? null) ? ' ' . $smsNote : '')
                . ($status === 'Active Training' && $client['status'] !== 'Active Training' && ($an = active_without_authorization((int)$client['id'])) ? ' ' . $an : ''));
            redirect('client.php?id=' . (int)$client['id']);
            }
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
            if ($officeSent) db()->prepare('UPDATE clients SET office_id=? WHERE id=?')->execute([$officeIn, $newId]);
            else office_place_new_client($newId, (int)$user['id']);
            custom_save($cfVals, $newId);
            web_once_set($clientKey, $newId);
            $smsNote = sms_consent_save($newId, !empty($_POST['sms_consent']), (string)($_POST['sms_consent_source'] ?? ''), null, (int)$user['id']);
            record_status_change($newId, null, $status, $user['id'], 'Client created');
            on_hold_save($newId, $status, '', trim((string)($_POST['status_note'] ?? '')), $holdUntil);
            if ($closing) client_closure_record($newId, $status, $closureData, (int)$user['id']);
            audit($user['id'], 'client.create', 'client', $newId, $name);
            flash('Client created.' . $ageNote . (($smsNote ?? null) ? ' ' . $smsNote : ''));
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
<?= form_error_summary(array_fill_keys(array_keys($errors), ''), ['stale' => 'name']) ?>

<p class="hint">Fields marked with an asterisk (*) are required.</p>
<form method="post" class="stacked" novalidate>
  <?= csrf_field() ?><?= web_idem_field() ?>
  <?php // The version of the record this form was filled from (4.27.0): after a refused
  // save it is the current one, so saving again applies the reviewed changes.
  if ($client): $formLoadedAt = $freshLoadedAt ?? ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['loaded_at'] ?? null) ? $_POST['loaded_at'] : (string)$client['updated_at']); ?>
  <input type="hidden" name="loaded_at" value="<?= h($formLoadedAt) ?>">
  <?php // The values the form was opened with, so a refused save can tell this person's edits from the other person's.
  $formOrig = $freshOrig ?? ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['orig'] ?? null) ? $_POST['orig'] : json_encode(client_edit_snapshot($client))); ?>
  <input type="hidden" name="orig" value="<?= h(is_string($formOrig) ? $formOrig : (string)json_encode($formOrig)) ?>">
  <?php endif; ?>
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
      <label><input type="checkbox" name="confirm_email" value="1"<?= $raw('confirm_email') !== null ? ' checked' : '' ?>> Confirmed with the client <span class="hint"><strong>Unverified — confirm before anything is sent.</strong> <?= h($client['email']) ?> came from the public referral form and has not been checked with the client, so no session reminder, outcome survey or portal link goes to it yet. Typing a different address above also confirms the new one.</span></label>
    </div>
    <?php endif; ?>
    <div class="field"><label for="phone">Phone</label>
      <input type="tel" id="phone" name="phone" value="<?= $v('phone') ?>" <?= field_aria('phone') ?>><?= field_error('phone') ?></div>
    <?php // Text messages (4.17.0): the client's agreement, for this number. ?>
    <?php if ($client && !empty($client['sms_opted_out_at'])): ?>
    <p>Text messages: <?= h(sms_client_words($client)) ?></p>
    <?php else: $smsOn = $src !== null ? $raw('sms_consent') !== null : (bool)($client['sms_consent_at'] ?? false); ?>
    <div class="field"><label><input type="checkbox" name="sms_consent" value="1" <?= $smsOn ? 'checked' : '' ?>> The client agreed to text messages at this number <span class="hint">reminders and notices about their sessions; they can reply STOP at any time<?= $client && !empty($client['sms_consent_at']) ? '. ' . h(sms_client_words($client)) : '' ?></span></label></div>
    <div class="field"><label for="sms_consent_source">How they agreed</label>
      <select id="sms_consent_source" name="sms_consent_source"><?= select_options(SMS_CONSENT_SOURCES, $raw('sms_consent_source') ?? $client['sms_consent_source'] ?? 'In person') ?></select></div>
    <?php endif; ?>
    <div class="field"><label for="address">Address</label>
      <input type="text" id="address" name="address" value="<?= $v('address') ?>" <?= field_aria('address') ?>><?= field_error('address') ?></div>
    <?php $stateRequired = !$client || !empty($client['state']); ?>
    <?php if (offices_on()): // 4.22.0: a new client starts in the office of the person adding them
      $myOffice = (int)(db()->query('SELECT o.id FROM users u JOIN offices o ON o.id = u.office_id AND o.is_active = 1 WHERE u.id = ' . (int)$user['id'])->fetchColumn() ?: 0);
      $officeNow = $raw('office_id') !== null ? (int)$raw('office_id') : ($client ? (int)($client['office_id'] ?? 0) : $myOffice); ?>
    <div class="field"><label for="office_id">Office</label>
      <select id="office_id" name="office_id" <?= field_aria('office_id') ?>><?= office_options($officeNow ?: null) ?></select><?= field_error('office_id') ?></div>
    <?php endif; ?>
    <div class="field"><label for="state">State<?= $stateRequired ? ' <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span>' : ' <span class="hint">not recorded yet: choose it, so reminders and texts follow their time zone</span>' ?></label>
      <select id="state" name="state" <?= $stateRequired ? 'required ' : '' ?><?= field_aria('state') ?>><?= kv_options(US_STATES, html_entity_decode($v('state'), ENT_QUOTES), 'Choose a state') ?></select><?= field_error('state') ?></div>
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
      <label for="status">Status<?php if ($client): ?> <span class="hint">Closing the case (any Closed status) emails the client an outcome survey when you save, unless one was sent in the last 30 days.</span><?php endif; ?></label>
      <select id="status" name="status"<?= $client ? ' data-was="' . h($client['status']) . '"' : '' ?>><?= select_options(cms_choices('client_statuses', $client['status'] ?? null), $raw('status') ?? $client['status'] ?? 'Pending Assessment') ?></select>
    </div>
    <?php // Asked only when the case is being closed: the script shows it when a Closed status is
    // chosen; it is shown from the start when that was already chosen or refused.
    $cv = fn(string $k): string => h($raw($k) ?? '');
    $showClosure = str_starts_with((string)($raw('status') ?? ''), 'Closed') && ($raw('status') !== ($client['status'] ?? null)) || array_filter(array_keys(form_errors()), fn($k) => str_starts_with((string)$k, 'closure_')); ?>
    <fieldset id="closure-fields"<?= $showClosure ? '' : ' hidden disabled' ?>>
      <legend>Closing the case</legend>
      <?php if ($client && ($loose = closure_checklist((int)$client['id']))): ?>
      <p><strong>Still open on this record:</strong></p>
      <ul><?php foreach ($loose as [$txt, $href]): ?><li><a href="<?= h($href) ?>"><?= h($txt) ?></a></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <div class="field"><label for="closure_reason">Why is the case closing? <?= required_mark() ?></label>
        <select id="closure_reason" name="closure_reason" required <?= field_aria('closure_reason') ?>><option value="">Choose a reason</option>
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
      <?php // Assistive technology outcomes (4.24.0), started from the device requests on the record.
      [$devRec, $devGot] = $client ? client_device_counts((int)$client['id']) : [0, 0];
      $atv = fn(string $k, int $d) => h($raw($k) ?? ($client && $d > 0 ? (string)$d : '')); ?>
      <div class="grid grid-2">
        <div class="field"><label for="closure_at_recommended">Devices recommended</label><input type="text" inputmode="numeric" id="closure_at_recommended" name="closure_at_recommended" maxlength="2" value="<?= $atv('closure_at_recommended', $devRec) ?>" <?= field_aria('closure_at_recommended') ?>><?= field_error('closure_at_recommended') ?></div>
        <div class="field"><label for="closure_at_acquired">Devices the client got</label><input type="text" inputmode="numeric" id="closure_at_acquired" name="closure_at_acquired" maxlength="2" value="<?= $atv('closure_at_acquired', $devGot) ?>" <?= field_aria('closure_at_acquired') ?>><?= field_error('closure_at_acquired') ?></div>
      </div>
      <fieldset><legend>Using their devices at closure?</legend>
        <?php $ciu = $raw('closure_at_in_use') ?? ''; ?>
        <label><input type="radio" name="closure_at_in_use" value="yes"<?= $ciu === 'yes' ? ' checked' : '' ?>> Yes</label>
        <label><input type="radio" name="closure_at_in_use" value="no"<?= $ciu === 'no' ? ' checked' : '' ?>> No</label>
        <label><input type="radio" name="closure_at_in_use" value=""<?= $ciu === '' ? ' checked' : '' ?>> Not recorded</label>
      </fieldset>
      <div class="field"><label for="closure_at_note">Technology note (such as a device still on order, or why one is not used)</label>
        <input type="text" id="closure_at_note" name="closure_at_note" maxlength="255" value="<?= $cv('closure_at_note') ?>" <?= field_aria('closure_at_note') ?>><?= field_error('closure_at_note') ?></div>
    </fieldset>
    <script>(function(){var s=document.getElementById('status'),f=document.getElementById('closure-fields');if(!s||!f)return;var was=s.getAttribute('data-was')||'';function sync(){f.hidden=f.disabled=!(s.value.indexOf('Closed')===0&&s.value!==was);}s.addEventListener('change',sync);sync();<?= $showClosure ? 'f.hidden=f.disabled=false;' : '' ?>})();</script>
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
      <label><input type="checkbox" name="confirm_counselor" value="1"<?= $raw('confirm_counselor') !== null ? ' checked' : '' ?>> Confirm this is the VR counselor's email address <span class="hint"><strong>Unverified — confirm before reports are sent.</strong> <?= h($client['counselor_email']) ?> has not been confirmed (an address from the public referral form, for example, is not checked), so no monthly progress report, counselor portal link or email filing uses it yet. Check it against the authorization paperwork or the agency first. Typing a different address above also confirms the new one.</span></label>
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
    <legend>Accessibility &amp; access needs <span class="hint">How this client prefers to receive information and what assistive technology they use, so staff and deliverables can meet their needs.</span></legend>
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
      <label for="preferred_language">Preferred language <span class="hint">The client's portal opens in this language, and their emails (portal link, session reminders, outcome survey, guardian consent request) are sent in it.</span></label>
      <?php $curLang = ($raw('preferred_language') ?? $client['preferred_language'] ?? 'en') === 'es' ? 'es' : 'en'; ?>
      <select id="preferred_language" name="preferred_language">
        <option value="en" <?= $curLang === 'en' ? 'selected' : '' ?>>English</option>
        <option value="es" <?= $curLang === 'es' ? 'selected' : '' ?> lang="es">Español</option>
      </select>
    </div>
    <div class="field">
      <label for="a11y_notes">Access notes <span class="hint">communication preferences and accommodations. Shown to the client on their portal, where they can also change it. Keep internal remarks in case notes.</span></label>
      <textarea id="a11y_notes" name="a11y_notes" rows="3" <?= field_aria('a11y_notes') ?>><?= $v('a11y_notes') ?></textarea><?= field_error('a11y_notes') ?>
    </div>
  </fieldset>
  <?php if ($cfFields = custom_fields_all(true)): // 4.24.0: the organisation's own fields
    $cfShown = $client ? custom_values_for((int)$client['id']) : [];
    foreach ($cfFields as $cf) if (($r = $raw('cf_' . (int)$cf['id'])) !== null) $cfShown[(int)$cf['id']] = $r; ?>
  <fieldset>
    <legend>More details</legend>
    <?= custom_fields_html($cfFields, $cfShown) ?>
  </fieldset>
  <?php endif; ?>

  <div class="page-actions">
    <?php // Closing a case as rehabilitated emails the client: confirm that once, on the button
    // (not the form's onsubmit, which session-forms.js would run a second time). ?>
    <button type="submit" class="btn btn-primary"<?= $client ? ' onclick="var s=document.getElementById(\'status\');return !s||s.value.indexOf(\'Closed\')!==0||(s.getAttribute(\'data-was\')||\'\').indexOf(\'Closed\')===0||confirm(\'Save and close the case? This emails the client an outcome survey, unless one was sent in the last 30 days.\');"' : '' ?>><?= $client ? 'Save changes' : 'Create client' ?></button>
    <a class="btn btn-secondary" href="<?= $client ? 'client.php?id=' . (int)$client['id'] : 'clients.php' ?>">Cancel<span class="visually-hidden"> and return to <?= $client ? h($client['name']) : 'the client list' ?></span></a>
  </div>
</form>
<?php page_footer();
