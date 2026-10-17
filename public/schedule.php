<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__.'/inc/workflows.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('schedule');

/** Scoped users can only schedule/complete for their own caseload. */
function schedule_scope_check(int $clientId, array $user): void {
    if (!caseload_scoped()) return;
    $s = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?');
    $s->execute([$clientId]);
    if ((int)$s->fetchColumn() !== (int)$user['id']) {
        flash('That client is not on your caseload.', 'error');
        redirect('schedule.php');
    }
}

/**
 * Read and check the fields the single and group forms share. $p is the group
 * form's "g_" name prefix, so a refused group post never refills the single
 * form. Returns [field errors, values]: nothing is cut, coerced or dropped, and
 * the rules match the app's schedule_session.
 */
function schedule_input(string $p, array $user): array {
    $in = fn(string $k): string => is_string($_POST[$p . $k] ?? null) ? trim($_POST[$p . $k]) : '';
    $e = [];
    // A year from 2000 to five years ahead, as the app's api_date() and track.php:
    // a year typed as 26 (0026) or 2062 would book, invite and later bill that date.
    $date = valid_date($in('date'), 2000, (int)date('Y') + 5);
    $time = $in('time');
    if ($date === null) $e[$p . 'date'] = 'Enter a valid date with a four-digit year from 2000 to ' . ((int)date('Y') + 5) . '.';
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) $e[$p . 'time'] = 'Enter a valid start time.';
    $startTs = ($date !== null && !isset($e[$p . 'time'])) ? strtotime($date . ' ' . substr($time, 0, 5) . ':00') : false;
    if ($startTs === false && !isset($e[$p . 'date']) && !isset($e[$p . 'time'])) $e[$p . 'date'] = 'Enter a valid date.';
    $duration = $in('duration');
    if (!is_numeric($duration) || (float)$duration < 0.25 || (float)$duration > 12) {
        $e[$p . 'duration'] = 'Enter a duration between 0.25 and 12 hours.';
    }
    $location = $in('location');
    $notes = $in('notes');
    if (mb_strlen($location) > 190) $e[$p . 'location'] = 'Location is too long (190 characters maximum).';
    if (mb_strlen($notes) > 255) $e[$p . 'notes'] = 'Notes are too long (255 characters maximum).';
    $url = $in('meeting_url');
    [$isRemote, $meetingUrl] = session_remote_input(['is_remote' => $in('is_remote'), 'meeting_url' => $url]);
    // A typed link that would otherwise be dropped is refused, so it is never lost unseen.
    if ($url !== '' && $meetingUrl === null) {
        $e[$p . 'meeting_url'] = $isRemote
            ? 'Enter the meeting link as a full web address starting with https:// (500 characters maximum).'
            : 'A meeting link was entered but "Remote / telepractice session" is not ticked. Tick it, or clear the link.';
    }
    // The session must belong to a real, active staff member or nobody would see it.
    $s = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $s->execute([(int)$in('staff_id') ?: (int)$user['id']]);
    $staff = $s->fetch() ?: null;
    if (!$staff) $e[$p . 'staff_id'] = 'Choose an active instructor.';
    return [$e, [
        'start' => $startTs ?: 0,
        'end' => $startTs ? $startTs + (int)round((float)$duration * 3600) : 0,
        'location' => $location,
        'notes' => $notes,
        'is_remote' => $isRemote,
        'meeting_url' => $meetingUrl,
        'staff' => $staff,
        // An instructor limited to their own caseload never sees (or can complete)
        // a session for a client assigned to someone else.
        'staff_scoped' => $staff ? resolve_perms($staff)['scoped'] : false,
    ]];
}

/** Hours a session logs on completion, to the quarter hour. */
function schedule_hours(array $sess): float {
    return round((strtotime($sess['end_at']) - strtotime($sess['start_at'])) / 3600 * 4) / 4;
}

/** First name + last initial, as the calendar feed shows a client: an emailed
 *  invite ends up in the calendar provider's storage too. */
function schedule_short_name(string $n): string {
    $parts = preg_split('/\s+/', trim($n)) ?: [];
    return count($parts) < 2 ? trim($n) : $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.';
}

/** The work log text for a completed session. A sitting of a group says so, however
 *  it is completed: the invoice line is the only place a group service shows. */
function schedule_log_description(array $sess): string {
    if (!empty($sess['group_uid'])) return 'Group session' . ($sess['notes'] ? ': ' . $sess['notes'] : '');
    return 'Training session' . ($sess['notes'] ? ': ' . $sess['notes'] : '') . ($sess['location'] ? ' (' . $sess['location'] . ')' : '');
}

/** Unbilled hours already logged on the session's track for the session's day that no
 *  session is linked to. Voice, the app and the track page log hours without closing
 *  the session, so these may be this session's hours: completing it would log them twice. */
function schedule_logged_candidates(array $sess): array {
    if (empty($sess['track_id'])) return [];
    $q = db()->prepare('SELECT w.id, w.hours, w.description FROM work_logs w
        WHERE w.track_id = ? AND w.work_date = ? AND w.is_billed = 0
          AND NOT EXISTS (SELECT 1 FROM scheduled_sessions s2 WHERE s2.work_log_id = w.id) ORDER BY w.id LIMIT 5');
    $q->execute([(int)$sess['track_id'], date('Y-m-d', strtotime($sess['start_at']))]);
    return $q->fetchAll();
}

/** A fingerprint of the authorization warnings a booking form was shown. The approval
 *  box is drawn only beside those warnings and carries this, so it approves exactly
 *  them: a box ticked before any warning was shown, or for warnings that have since
 *  changed, approves nothing. $p is the group form's "g_" prefix. */
function schedule_auth_hash(array $warnings): string {
    return hash('sha256', json_encode(array_values($warnings)));
}
function schedule_auth_approved(string $p, array $warnings): bool {
    $posted = $_POST[$p . 'authorization_hash'] ?? null;
    return ($_POST[$p . 'authorization_ack'] ?? '') === '1' && is_string($posted) && hash_equals(schedule_auth_hash($warnings), $posted);
}

/**
 * Tell each session's instructor when someone else cancels it or records a No Show:
 * an in-app alert and one plain-text email naming only the date, the time and the
 * client's first name. The emailed invite has no stored id to withdraw, so the email
 * asks them to remove a cancelled session from their calendar themselves.
 */
function schedule_tell_instructors(array $sessions, string $outcome, array $user): void {
    $byInstructor = [];
    foreach ($sessions as $s) if ((int)$s['user_id'] !== (int)$user['id']) $byInstructor[(int)$s['user_id']][] = $s;
    if (!$byInstructor) return;
    $uq = db()->prepare('SELECT name, email FROM users WHERE id = ? AND is_active = 1');
    $what = $outcome === 'Cancelled' ? 'cancelled' : 'recorded as a No Show';
    foreach ($byInstructor as $instructorId => $list) {
        $uq->execute([$instructorId]);
        $instr = $uq->fetch();
        if (!$instr) continue;
        $lines = [];
        $future = false; // a cancelled one-to-one session still ahead, whose invite should go
        $inGroup = false; // a group's invite stays: the rest of the group still meets
        foreach ($list as $s) {
            $ts = strtotime($s['start_at']);
            $lines[] = date('l, F j, Y \a\t g:i A T', $ts) . ' with ' . (explode(' ', trim((string)$s['client_name']))[0] ?: 'a client');
            if (!empty($s['group_uid'])) $inGroup = true;
            elseif ($ts > time()) $future = true;
        }
        $one = count($lines) === 1;
        notify_user($instructorId, ($one ? 'Session ' : count($lines) . ' sessions ') . $what . ' by ' . $user['name'] . ': ' . implode('; ', $lines), 'schedule.php');
        send_app_mail((string)$instr['email'],
            ($one ? 'Session ' . $what : 'Sessions ' . $what) . ' — ' . date('D, M j g:i A', strtotime($list[0]['start_at'])),
            "Hello {$instr['name']},\n\n{$user['name']} {$what} " . ($one ? 'this training session' : 'these training sessions') . " of yours:\n\n"
            . implode("\n", $lines) . "\n\n"
            . ($outcome === 'Cancelled'
                ? ($one ? 'It is' : 'They are') . ' no longer on your schedule.' . ($future ? ' If you added the emailed invite to your calendar, delete it there too, so its reminder does not go off.' : '')
                    . ($inGroup ? ' The rest of the group session is unchanged.' : '')
                : 'No hours were logged for ' . ($one ? 'it.' : 'them.'))
            . "\n\nThe schedule: " . cfg('base_url') . "/schedule.php\n");
    }
}

/** Where to return after recording an outcome: beside the ended sessions still
 *  waiting for one (so the next is close at hand), otherwise the upcoming list. */
function schedule_outcome_back(array $user): string {
    $q = db()->prepare("SELECT 1 FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
        WHERE s.status = 'Scheduled' AND s.end_at < NOW()" . (caseload_scoped() ? ' AND c.assigned_to = ?' : '') . ' LIMIT 1');
    $q->execute(caseload_scoped() ? [(int)$user['id']] : []);
    return $q->fetchColumn() ? 'schedule.php#overdue-h' : 'schedule.php#upcoming-h';
}

/* ---- POST: schedule a GROUP session (one time slot, many clients) ---- */
/* Several ended sessions recorded as No Show or Cancelled at once (4.0.0). Completed ones are
   recorded one by one, because each logs hours. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_outcome'])) {
    $to = ($_POST['bulk_to'] ?? '') === 'Cancelled' ? 'Cancelled' : 'No Show';
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['bulk_ids'] ?? [])))));
    $done = 0; $failed = 0;
    foreach (array_slice($ids, 0, 200) as $sid) {
        $own = db()->prepare("SELECT c.assigned_to FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id WHERE s.id = ? AND s.end_at < NOW() AND s.group_uid IS NULL");
        $own->execute([$sid]);
        $owner = $own->fetchColumn();
        if ($owner === false || (caseload_scoped() && (int)$owner !== (int)$user['id'])) { $failed++; continue; }
        if (session_set_outcome($sid, $to, (int)$user['id'], 'website, several at once') === null) $done++; else $failed++;
    }
    flash($done ? $done . ' session' . ($done === 1 ? '' : 's') . ' recorded as ' . $to . '.' . ($failed ? ' ' . $failed . ' could not be (already recorded, or not on your caseload).' : '') : 'Nothing was recorded: choose at least one session.', $done ? 'success' : 'error');
    redirect('schedule.php#overdue-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group_session'])) {
    // array_unique: a client posted twice would be booked, and later billed, twice.
    $clientIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['group_clients'] ?? [])))));
    foreach ($clientIds as $cid) schedule_scope_check($cid, $user);
    [$errors, $f] = schedule_input('g_', $user);
    $members = [];
    if ($clientIds) {
        $q = db()->prepare('SELECT id, name, assigned_to FROM clients WHERE id IN (' . implode(',', array_fill(0, count($clientIds), '?')) . ')');
        $q->execute($clientIds);
        foreach ($q->fetchAll() as $row) $members[(int)$row['id']] = $row;
    }
    if (count($members) !== count($clientIds)) {
        $errors['g_clients'] = 'One of the chosen clients is no longer on file. Review the list and choose again.';
    } elseif (count($clientIds) < 2) {
        $errors['g_clients'] = 'Choose at least two clients.';
    }
    if ($f['staff'] && $f['staff_scoped']) {
        $outside = array_filter($members, fn($m) => (int)$m['assigned_to'] !== (int)$f['staff']['id']);
        if ($outside) {
            $errors['g_staff_id'] = $f['staff']['name'] . ' only sees clients on their own caseload, and these clients are not assigned to them: '
                . implode(', ', array_column($outside, 'name')) . '. Choose another instructor, or untick those clients.';
        }
    }
    // Each client's hours log to the track picked for them on the form (offered
    // when they have more than one active track), otherwise to their newest one.
    $newest = db()->prepare("SELECT id FROM training_tracks WHERE client_id = ? AND status = 'Active' ORDER BY created_at DESC, id DESC LIMIT 1");
    $ownTrack = db()->prepare("SELECT 1 FROM training_tracks WHERE id = ? AND client_id = ? AND status = 'Active'");
    $trackIds = [];
    foreach ($members as $cid => $m) {
        $picked = (int)($_POST['g_track_' . $cid] ?? 0);
        if ($picked > 0) {
            $ownTrack->execute([$picked, $cid]);
            if (!$ownTrack->fetchColumn()) {
                $errors['g_track_' . $cid] = 'Choose one of ' . $m['name'] . '\'s active training tracks.';
                continue;
            }
            $trackIds[$cid] = $picked;
        } else {
            $newest->execute([$cid]);
            $trackIds[$cid] = (int)$newest->fetchColumn() ?: null;
        }
    }
    // Each client's authorization warnings, named, all shown together: the approval
    // then covers exactly the warnings the user read (and is recorded with them).
    $authLines = [];
    if (!$errors) foreach ($trackIds as $cid => $tid) if ($tid) {
        $warnings = workflow_authorization($tid, $f['start'], $f['end']);
        if ($warnings) $authLines[] = $members[$cid]['name'] . ': ' . implode(' ', $warnings);
    }
    if ($authLines && !schedule_auth_approved('g_', $authLines)) {
        $errors['g_authorization_ack'] = 'Authorization review. ' . implode(' ', $authLines)
            . ' Review the tracks, then tick the approval box below if this booking is still appropriate.';
    }
    if ($errors) {
        keep_input();
        // The ticked clients are an array, which keep_input() does not keep.
        $_SESSION['old_input']['g_clients'] = implode(',', $clientIds);
        // The approval box comes back only beside the warnings it approves.
        if (isset($errors['g_authorization_ack'])) $_SESSION['old_input']['g_authorization_hash'] = schedule_auth_hash($authLines);
        else unset($_SESSION['old_input']['g_authorization_hash']);
        unset($_SESSION['old_input']['g_authorization_ack']);
        keep_field_errors($errors);
        redirect('schedule.php');
    }
    $startTs = $f['start'];
    $endTs = $f['end'];
    $location = $f['location'];
    $notes = $f['notes'];
    $isRemote = $f['is_remote'];
    $meetingUrl = $f['meeting_url'];
    $staff = $f['staff'];
    $groupUid = substr(bin2hex(random_bytes(8)), 0, 12);
    $ins = db()->prepare('INSERT INTO scheduled_sessions (client_id, track_id, user_id, start_at, end_at, location, notes, group_uid, is_remote, meeting_url) VALUES (?,?,?,?,?,?,?,?,?,?)');
    // Every client's row is saved or none is, so a retry can't duplicate part of the group.
    db()->beginTransaction();
    try {
        // The client rows are locked first, so a second press of the button (the invite
        // email can take a while) waits for the first and then finds its sessions.
        $sorted = $clientIds;
        sort($sorted);
        db()->prepare('SELECT id FROM clients WHERE id IN (' . implode(',', array_fill(0, count($sorted), '?')) . ') ORDER BY id FOR UPDATE')->execute($sorted);
        $dupQ = db()->prepare("SELECT 1 FROM scheduled_sessions WHERE client_id = ? AND user_id = ? AND start_at = ? AND status = 'Scheduled' LIMIT 1");
        $booked = [];
        foreach ($clientIds as $cid) {
            $dupQ->execute([$cid, (int)$staff['id'], date('Y-m-d H:i:s', $startTs)]);
            if ($dupQ->fetchColumn()) $booked[] = $members[$cid]['name'];
        }
        if ($booked) {
            db()->rollBack();
            if (count($booked) === count($clientIds)) {
                // The same group, already saved (a repeated submit): nothing is added twice.
                flash('That group session is already on the schedule (' . $staff['name'] . ', ' . date('D, M j g:i A', $startTs) . '), so it was not added again.', 'info');
                redirect('schedule.php#upcoming-h');
            }
            keep_input();
            $_SESSION['old_input']['g_clients'] = implode(',', $clientIds);
            unset($_SESSION['old_input']['g_authorization_hash'], $_SESSION['old_input']['g_authorization_ack']);
            keep_field_errors(['g_clients' => 'Already scheduled with ' . $staff['name'] . ' at that time: ' . implode(', ', $booked)
                . '. Untick ' . (count($booked) === 1 ? 'that client' : 'those clients') . ', or choose another time. Nothing was saved.']);
            redirect('schedule.php');
        }
        $groupSessionIds = [];
        foreach ($clientIds as $cid) {
            $ins->execute([$cid, $trackIds[$cid], (int)$staff['id'], date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs), $location ?: null, $notes ?: null, $groupUid, $isRemote ? 1 : 0, $meetingUrl]);
            $groupSessionIds[] = (int)db()->lastInsertId();
        }
        db()->commit();
        // Each client is emailed their own calendar invite (3.0.41).
        foreach ($groupSessionIds as $gsId) client_session_notice([$gsId], 'booked');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    // No session notes in the invite: calendar providers store it (as with the feed).
    $ics = build_ics(ics_uid('group', $groupUid),
        ($isRemote ? 'Remote group session (' : 'Group session (') . count($clientIds) . ' clients)',
        "Group training session with " . count($clientIds) . " clients.\nDetails: " . cfg('base_url') . '/schedule.php',
        $location, $startTs, $endTs, cfg('mail_from'), $staff['email'], [], $meetingUrl ?? '');
    $sent = send_app_mail_attach($staff['email'],
        'Group session scheduled — ' . date('D, M j g:i A T', $startTs),
        "A group session with " . count($clientIds) . " clients has been scheduled.\nOpen the attached invite to add it to your calendar.\n",
        [['name' => 'invite.ics', 'mime' => 'text/calendar; method=REQUEST', 'bytes' => $ics]]);
    // An approval is recorded only when there were warnings to approve, with them.
    audit($user['id'], 'session.schedule', 'group', null, 'group of ' . count($clientIds)
        . ($authLines ? ' — authorization exception approved: ' . implode(' ', $authLines) : ''));
    $noTrack = [];
    foreach ($clientIds as $cid) if ($trackIds[$cid] === null) $noTrack[] = $members[$cid]['name'];
    flash('Group session scheduled for ' . count($clientIds) . ' clients. Completing it logs hours to each client\'s training track.'
        . ($noTrack ? ' ' . implode(', ', $noTrack) . (count($noTrack) === 1 ? ' has' : ' have') . ' no active training track, so no hours will be logged for them.' : '')
        . ($sent ? ' A calendar invite was emailed to ' . $staff['name'] . '.' : ' (Calendar invite email could not be sent to ' . $staff['name'] . '.)'));
    redirect('schedule.php');
}

/* ---- POST: record a group's attendance and complete it ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_group'])) {
    $uid = preg_replace('/[^a-f0-9]/', '', is_string($_POST['group_uid'] ?? '') ? $_POST['group_uid'] : '');
    // Attendance is marked on the group card: each client listed there is Completed,
    // with hours logged, when ticked as attended, and No Show, with nothing logged,
    // when not. A post from a card drawn before it asked who attended is refused,
    // so an absent client is never billed unseen.
    if (($_POST['attendance_form'] ?? '') !== '1') {
        flash('Nothing was completed: the page was out of date. Mark who attended on the group card, then choose Record attendance again.', 'error');
        redirect('schedule.php');
    }
    $postedIds = fn(string $k): array => array_map('intval', array_filter(is_array($_POST[$k] ?? null) ? $_POST[$k] : [], 'is_string'));
    // The hours each client who attended is logged (3.0.32): prefilled with the
    // scheduled length; left blank, each client's own scheduled length is used.
    $gHoursRaw = is_string($_POST['g_hours'] ?? null) ? trim(str_replace(',', '.', $_POST['g_hours'])) : '';
    $gHours = $gHoursRaw === '' ? null : (is_numeric($gHoursRaw) ? round((float)$gHoursRaw, 2) : -1.0);
    if ($gHours !== null && ($gHours < 0 || $gHours > 24)) {
        flash('Nothing was completed: enter the hours for each client who attended as a number from 0 to 24, such as 1.5.', 'error');
        redirect('schedule.php');
    }
    if ($gHours !== null && $gHours < 0.01) $gHours = 0.0;
    $listed = $postedIds('listed');
    $attended = $postedIds('attended');
    // Scoped staff complete only their own clients' rows of the group, instead of
    // stopping part-way at the first client that belongs to someone else.
    $rows = db()->prepare("SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
        WHERE s.group_uid = ? AND s.status = 'Scheduled'" . (caseload_scoped() ? ' AND c.assigned_to = ?' : '') . ' ORDER BY c.name, s.id');
    $rows->execute(caseload_scoped() ? [$uid, (int)$user['id']] : [$uid]);
    $done = 0;
    $logged = 0;
    $notLogged = [];
    $refused = []; // left Scheduled: their track has no hourly rate yet
    $refusedWhy = []; // auto_log_session()'s own explanation, each once
    $alreadyLogged = []; // left Scheduled: hours for that day are already on their track
    $noShows = []; // not ticked as attended: recorded as No Show, nothing logged
    $noShowIds = [];
    $authNotes = []; // logged, but outside the track's authorization (named per client)
    $notStarted = 0; // a session that has not begun cannot be completed (or billed)
    $startAt = null;
    $claim = db()->prepare('UPDATE scheduled_sessions SET status = "Completed" WHERE id = ? AND status = "Scheduled"');
    $claimNoShow = db()->prepare('UPDATE scheduled_sessions SET status = "No Show" WHERE id = ? AND status = "Scheduled"');
    $setLog = db()->prepare('UPDATE scheduled_sessions SET work_log_id = ? WHERE id = ?');
    foreach ($rows->fetchAll() as $sess) {
        // Only the clients the card showed are recorded.
        if (!in_array((int)$sess['id'], $listed, true)) continue;
        schedule_scope_check((int)$sess['client_id'], $user);
        if (strtotime($sess['start_at']) > time()) { $notStarted++; continue; }
        $startAt = $sess['start_at'];
        if (!in_array((int)$sess['id'], $attended, true)) {
            $claimNoShow->execute([(int)$sess['id']]);
            if ($claimNoShow->rowCount() === 1) { $noShows[] = $sess; $noShowIds[] = (int)$sess['id']; }
            continue;
        }
        $hours = $sess['track_id'] ? ($gHours ?? schedule_hours($sess)) : 0.0;
        // Hours already logged for that day (by voice, the app or the track page) are
        // not logged again: that client's session is left for a one-by-one outcome.
        if ($hours > 0 && schedule_logged_candidates($sess)) { $alreadyLogged[] = $sess['client_name']; continue; }
        $authIssue = null;
        // Per session, the claim (double-submit guard) and its work log commit
        // together, so an error part-way can't leave it Completed with no hours.
        db()->beginTransaction();
        try {
            $claim->execute([(int)$sess['id']]);
            if ($claim->rowCount() !== 1) { db()->rollBack(); continue; }
            if ($hours > 0) {
                // The hours are the instructor's who taught the session, whoever records it.
                $logId = auto_log_session((int)$sess['track_id'], date('Y-m-d', strtotime($sess['start_at'])), $hours,
                    schedule_log_description($sess), (int)$sess['user_id'], $authIssue);
                $setLog->execute([$logId, (int)$sess['id']]);
            }
            db()->commit();
        } catch (DomainException $e) {
            // A track with no hourly rate: this client's session stays Scheduled, and
            // the rest of the group is still completed (each commits on its own).
            if (db()->inTransaction()) db()->rollBack();
            $refused[] = $sess['client_name'];
            $refusedWhy[$e->getMessage()] = true;
            continue;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            // Sessions already committed above stay completed: record them before failing.
            if ($done > 0 || $noShows) audit($user['id'], 'session.complete', 'group', null, "group {$uid}, {$done} attended, hours logged for {$logged}, "
                . count($noShows) . ' No Show (stopped by an error)');
            throw $e;
        }
        $done++;
        if ($hours > 0) $logged++;
        else $notLogged[] = $sess['client_name'] . ($sess['track_id'] ? ' (session too short to log hours)' : ' (no training track linked)');
        if ($authIssue) $authNotes[] = $sess['client_name'] . ': ' . $authIssue;
    }
    $refusedMsg = $refused ? ' Not completed (still Scheduled): ' . implode(', ', $refused) . '. '
        . implode(' ', array_keys($refusedWhy)) . ' Then complete '
        . (count($refused) === 1 ? 'that session' : 'those sessions') . '.' : '';
    $loggedMsg = $alreadyLogged ? ' Not completed (still Scheduled), because hours are already logged on their training track for that day: '
        . implode(', ', $alreadyLogged) . '. Record ' . (count($alreadyLogged) === 1 ? 'that session' : 'each of those sessions')
        . ' under "Ended sessions awaiting an outcome": choose "Completed — hours already logged" if those are its hours, or "Completed — log hours as well" if it was a separate visit.' : '';
    if ($done === 0 && !$noShows) {
        flash(($refused || $alreadyLogged) ? 'Nothing was completed.' . $refusedMsg . $loggedMsg
            : ($notStarted ? 'Nothing was completed: that group session has not started yet. Complete it once it has taken place, or cancel each client\'s session if it will not happen.'
            : 'Nothing was completed: no session in that group is still waiting for an outcome.'), 'error');
        redirect('schedule.php');
    }
    if ($noShows) schedule_tell_instructors($noShows, 'No Show', $user);
    foreach ($noShowIds as $nsId) counselor_noshow_notice($nsId); // 3.0.41: each counselor is emailed
    audit($user['id'], 'session.complete', 'group', null, "group {$uid}, {$done} attended, hours logged for {$logged}"
        . ($noShows ? ', No Show: client #' . implode(', #', array_map(fn($s) => (int)$s['client_id'], $noShows)) : '')
        . ($refused ? ', ' . count($refused) . ' left Scheduled (track has no hourly rate)' : '')
        . ($alreadyLogged ? ', ' . count($alreadyLogged) . ' left Scheduled (hours already logged that day)' : ''));
    flash('Group session of ' . fmt_datetime($startAt) . ': '
        . ($done > 0 ? "completed for {$done} client(s) — hours logged for {$logged}." : 'nobody was recorded as attending.')
        . ($noShows ? ' Recorded as No Show, with no hours logged: ' . implode(', ', array_column($noShows, 'client_name')) . '.' : '')
        . ($notLogged ? ' No hours were logged for ' . implode(', ', $notLogged) . '.' : '')
        . ($authNotes ? ' ' . implode(' ', $authNotes) : '') . $refusedMsg . $loggedMsg, ($refused || $alreadyLogged) ? 'error' : 'success');
    redirect(schedule_outcome_back($user));
}

/* ---- POST: schedule a session ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_session'])) {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $trackId = (int)($_POST['track_id'] ?? 0) ?: null;
    if ($clientId > 0) schedule_scope_check($clientId, $user);
    [$errors, $f] = schedule_input('', $user);
    $c = db()->prepare('SELECT id, name, assigned_to FROM clients WHERE id = ?');
    $c->execute([$clientId]);
    $client = $c->fetch();
    if (!$client) $errors['client_id'] = 'Choose a client.';
    // The sessions to book (4.9.0): once or weekly, several days each week, a list of dates, or
    // spread over the authorization (inc/session_plan.php).
    $planPattern = isset(PLAN_PATTERNS[$_POST['plan_pattern'] ?? '']) ? (string)$_POST['plan_pattern'] : 'weekly';
    $occurrences = [];
    if (!isset($errors['date']) && !isset($errors['time']) && !isset($errors['duration']) && $f['start'] && $f['end']) {
        $planTrack = null;
        // Only this client's own track (its hours are not read for anyone else's).
        if ($trackId) { $pt = db()->prepare('SELECT * FROM training_tracks WHERE id = ? AND client_id = ?'); $pt->execute([$trackId, $clientId]); $planTrack = $pt->fetch() ?: null; }
        [$occurrences, $planErr, $planField] = session_plan($_POST, (int)$f['start'], (int)$f['end'], $planTrack);
        if ($planErr !== null) $errors[$planField] = $planErr;
    }
    $repeatWeeks = $planPattern === 'weekly' ? max(1, count($occurrences)) : 1;
    // Make the meeting link in the instructor's own Zoom or Teams (4.10.0).
    $meetMake = is_string($_POST['meeting_make'] ?? null) && isset(MEETING_PROVIDERS[$_POST['meeting_make']]) ? $_POST['meeting_make'] : '';
    if ($meetMake !== '') {
        if (!$f['is_remote']) $errors['meeting_make'] = 'A ' . MEETING_PROVIDERS[$meetMake] . ' link is only for a remote session: tick Remote / telepractice, or choose No.';
        elseif ($f['meeting_url'] !== null) $errors['meeting_make'] = 'A meeting link is typed above and ' . MEETING_PROVIDERS[$meetMake] . ' would make another: clear the link, or choose No.';
        elseif (!meeting_app($meetMake)) $errors['meeting_make'] = MEETING_PROVIDERS[$meetMake] . ' is not set up here any more. Choose No, or type a link.';
        // Only your own account, unless you are an administrator booking for someone (owner rule, 4.10.0).
        elseif ($f['staff'] && (int)$f['staff']['id'] !== (int)$user['id'] && ($user['role'] ?? '') !== 'admin') $errors['meeting_make'] = 'A meeting can be made only in your own ' . MEETING_PROVIDERS[$meetMake]
            . ' account. ' . $f['staff']['name'] . ' can make it, or an administrator; otherwise choose No and type a link.';
        elseif ($f['staff'] && !meeting_account((int)$f['staff']['id'], $meetMake)) $errors['meeting_make'] = $f['staff']['name'] . ' has not connected ' . MEETING_PROVIDERS[$meetMake]
            . ((int)$f['staff']['id'] === (int)$user['id'] ? '. Connect it on My Account, Online meetings, then schedule again.' : '. They connect it on their My Account page; until then choose No, or type a link.');
    }
    if ($client && $f['staff'] && $f['staff_scoped'] && (int)$client['assigned_to'] !== (int)$f['staff']['id']) {
        $errors['staff_id'] = $f['staff']['name'] . ' only sees clients on their own caseload, and ' . $client['name']
            . ' is not assigned to them. Choose another instructor, or assign the client to them first.';
    }
    // A linked track must belong to the same client — completing the session
    // bills hours against that track's authorization.
    if ($trackId !== null && $client) {
        $tc = db()->prepare('SELECT client_id FROM training_tracks WHERE id = ?');
        $tc->execute([$trackId]);
        if ((int)$tc->fetchColumn() !== $clientId) {
            $errors['track_id'] = 'That training track belongs to a different client. Pick the client\'s own track.';
        }
    }
    $authWarnings = [];
    if (!$errors && $trackId) {
        $authWarnings = workflow_authorization($trackId, $f['start'], $f['end'], $repeatWeeks, $occurrences);
        if ($authWarnings && !schedule_auth_approved('', $authWarnings)) {
            $errors['authorization_ack'] = 'Authorization review: ' . implode(' ', $authWarnings)
                . ' Review the track, then tick the approval box below if this booking is still appropriate.';
        }
    }
    // A plan of several days, dates or the whole authorization is shown first, and booked only
    // once the person ticks "Book these" for that exact list.
    // Checked together with the authorization review, so both boxes can be ticked in one go.
    $planHash = '';
    if (!array_diff_key($errors, ['authorization_ack' => 1]) && $planPattern !== 'weekly') {
        $planHash = session_plan_hash($occurrences, $clientId, (int)$f['staff']['id'], (int)$trackId, $planPattern);
        if (($_POST['plan_ok'] ?? '') !== $planHash) {
            $errors['plan_ok'] = 'Check the ' . count($occurrences) . ' session' . (count($occurrences) === 1 ? '' : 's') . ' listed below (' . rtrim(rtrim(number_format(session_plan_hours($occurrences), 2), '0'), '.') . ' hours), then tick Book these ' . count($occurrences) . ' sessions and schedule again.';
            $_SESSION['plan_preview'] = ['hash' => $planHash, 'list' => array_map(fn($o) => date('l, F j, Y g:i A', $o[0]) . ' to ' . date('g:i A', $o[1]), $occurrences)];
        }
    }
    if ($errors) {
        keep_input();
        unset($_SESSION['old_input']['plan_ok']);
        if (!isset($errors['plan_ok'])) unset($_SESSION['plan_preview']);
        // The approval box comes back beside the warnings it approves: while they are unapproved,
        // or (ticked, kept ticked) while the plan still waits to be confirmed.
        if ($authWarnings && (isset($errors['authorization_ack']) || isset($errors['plan_ok']))) $_SESSION['old_input']['authorization_hash'] = schedule_auth_hash($authWarnings);
        else unset($_SESSION['old_input']['authorization_hash']);
        if (isset($errors['authorization_ack']) || !$authWarnings) unset($_SESSION['old_input']['authorization_ack']);
        keep_field_errors($errors);
        redirect('schedule.php');
    }
    $startTs = $f['start'];
    $endTs = $f['end'];
    $location = $f['location'];
    $notes = $f['notes'];
    $isRemote = $f['is_remote'];
    $meetingUrl = $f['meeting_url'];
    $staffId = (int)$f['staff']['id'];
    $ins = db()->prepare('INSERT INTO scheduled_sessions (client_id, track_id, user_id, start_at, end_at, location, notes, is_remote, meeting_url) VALUES (?,?,?,?,?,?,?,?,?)');
    $sessionId = 0;
    $newSessionIds = [];
    unset($_SESSION['plan_preview']);
    $count = count($occurrences);
    // How the series is described in the invite, the flash and the audit log.
    $seriesWords = $count <= 1 ? '' : ($planPattern === 'weekly' ? "{$count} weekly sessions" : "{$count} sessions (" . mb_strtolower(PLAN_PATTERNS[$planPattern]) . ')');
    $startTs = $occurrences[0][0] ?? $startTs; $endTs = $occurrences[0][1] ?? $endTs;
    // The whole series is saved or none of it: a failure part-way through must
    // not leave a partial series that a retry would then duplicate.
    db()->beginTransaction();
    try {
        // The client row is locked first, so a second press of the button (the invite
        // email can take a while) waits for the first and then finds its sessions.
        db()->prepare('SELECT id FROM clients WHERE id = ? FOR UPDATE')->execute([$clientId]);
        $dupQ = db()->prepare("SELECT 1 FROM scheduled_sessions WHERE client_id = ? AND user_id = ? AND start_at = ? AND status = 'Scheduled' LIMIT 1");
        $booked = [];
        foreach ($occurrences as [$s0, $e0]) {
            $dupQ->execute([$clientId, $staffId, date('Y-m-d H:i:s', $s0)]);
            if ($dupQ->fetchColumn()) $booked[] = date('D, M j, Y g:i A', $s0);
        }
        if ($booked) {
            db()->rollBack();
            if (count($booked) === count($occurrences)) {
                // The same booking, already saved (a repeated submit): nothing is added twice.
                flash(($count > 1 ? 'That series is' : 'That session is') . ' already on the schedule (' . $client['name'] . ' with ' . $f['staff']['name']
                    . ', ' . $booked[0] . '), so it was not added again.', 'info');
                redirect('schedule.php#upcoming-h');
            }
            keep_input();
            unset($_SESSION['old_input']['authorization_hash'], $_SESSION['old_input']['authorization_ack']);
            keep_field_errors(['date' => $client['name'] . ' already has a session with ' . $f['staff']['name'] . ' at this time on '
                . implode('; ', $booked) . '. Choose another start date or number of weeks. Nothing was saved.']);
            redirect('schedule.php');
        }
        foreach ($occurrences as $w => [$s0, $e0]) {
            $ins->execute([$clientId, $trackId, $staffId, date('Y-m-d H:i:s', $s0), date('Y-m-d H:i:s', $e0), $location ?: null, $notes ?: null, $isRemote ? 1 : 0, $meetingUrl]);
            $newSessionIds[] = (int)db()->lastInsertId();
            if ($w === 0) $sessionId = (int)db()->lastInsertId();
        }
        // A weekly series shares one ID, so its rest can be cancelled in one step (3.0.43).
        if (count($newSessionIds) > 1) db()->prepare('UPDATE scheduled_sessions SET series_uid = ? WHERE id IN (' . implode(',', $newSessionIds) . ')')->execute([bin2hex(random_bytes(16))]);
        db()->commit();
        // The meeting is made once the sessions are saved, so a refused booking never leaves
        // one behind; every session of the series gets its one link (4.10.0).
        $meetWhy = null;
        if ($meetMake !== '') {
            try {
                $link = meeting_create($staffId, $meetMake, ($count > 1 ? 'AT Training series: ' : 'AT Training: ') . schedule_short_name((string)$client['name']),
                    $occurrences[0][0], $occurrences[$count - 1][1], $count > 1, $meetWhy);
                if ($link !== null) {
                    db()->prepare('UPDATE scheduled_sessions SET meeting_url = ? WHERE id IN (' . implode(',', $newSessionIds) . ')')->execute([$link]);
                    $meetingUrl = $link;
                    audit($user['id'], 'meeting.create', 'client', $clientId, MEETING_PROVIDERS[$meetMake] . ' link for ' . count($newSessionIds) . ' session(s), in ' . $f['staff']['name'] . "'s account");
                }
            } catch (Throwable $me) {
                // The sessions are booked whatever happens here.
                error_log('meeting_create: ' . get_class($me) . ' ' . $me->getMessage());
                $meetWhy = MEETING_PROVIDERS[$meetMake] . ' could not be reached, so the session is booked without a link; add one by moving the session.';
            }
        }
        client_session_notice($newSessionIds, 'booked'); // 3.0.41: the client's calendar invite
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    // An approval is recorded only when there were warnings to approve, with them.
    audit($user['id'], 'session.schedule', 'client', $clientId, ($count > 1 ? "series of {$seriesWords}" : 'single session')
        . ($authWarnings ? ' — authorization exception approved: ' . implode(' ', $authWarnings) : ''));

    // Calendar invite to the instructor (renders as an Outlook meeting request)
    $clientName = (string)$client['name'];
    $staff = $f['staff'];
    if ($staff) {
        $seriesNote = $count > 1 ? " ({$seriesWords})" : '';
        // The invite is stored by the calendar provider, so it carries what the calendar
        // feed does: the client's first name and last initial, no session notes, and a
        // link to the session in the app for the rest.
        $calName = schedule_short_name($clientName);
        $ics = build_ics(
            ics_uid('session', $sessionId),
            ($isRemote ? 'Remote AT Training: ' : 'AT Training: ') . $calName,
            "Training session with {$calName}.{$seriesNote}\nDetails: " . cfg('base_url') . '/schedule.php#s-' . $sessionId,
            $location, $startTs, $endTs,
            cfg('mail_from'), $staff['email'], $occurrences, $meetingUrl ?? ''
        );
        $sent = send_app_mail_attach($staff['email'],
            ($isRemote ? 'Remote session' : 'Session') . ($count > 1 ? ' series' : '') . ' scheduled — ' . date('D, M j g:i A T', $startTs),
            "A " . ($isRemote ? 'remote ' : '') . "training session{$seriesNote} has been scheduled.\n\nClient: {$clientName}\nWhen: " . date('l, F j, Y g:i A T', $startTs)
            . ' to ' . date('g:i A T', $endTs)
            . ($isRemote ? "\nFormat: Remote / telepractice" . ($meetingUrl ? "\nJoin online: {$meetingUrl}" : '') : ($location !== '' ? "\nWhere: {$location}" : ''))
            . "\n\nOpen the attached invite to add it to your calendar" . ($count > 1 ? ': it holds every session in the series.' : '.') . "\n",
            [['name' => 'invite.ics', 'mime' => 'text/calendar; method=REQUEST', 'bytes' => $ics]]);
        flash(($count > 1 ? 'Series of ' . $seriesWords . ' scheduled.' : 'Session scheduled.')
            . ($meetMake !== '' && $meetWhy === null ? ' The ' . MEETING_PROVIDERS[$meetMake] . ' link was made in ' . ($staffId === (int)$user['id'] ? 'your' : $staff['name'] . "'s") . ' account.' : '')
            . ($sent ? ' A calendar invite was emailed to ' . $staff['name'] . '.' : ' (Calendar invite email could not be sent.)')
            . ($meetWhy !== null ? ' ' . $meetWhy : ''), $meetWhy !== null ? 'error' : 'success');
    }
    redirect('schedule.php');
}

/* ---- POST: move a session, or cancel the rest of its series or its whole group (3.0.43) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['move_session']) || isset($_POST['cancel_series']) || isset($_POST['cancel_group']))) {
    $sid = (int)($_POST['session_id'] ?? 0);
    $q = db()->prepare("SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id WHERE s.id = ? AND s.status = 'Scheduled'");
    $q->execute([$sid]);
    $sess = $q->fetch();
    if (!$sess) { flash('That session is no longer scheduled.', 'error'); redirect('schedule.php'); }
    schedule_scope_check((int)$sess['client_id'], $user);
    if (isset($_POST['cancel_series']) || isset($_POST['cancel_group'])) {
        $n = isset($_POST['cancel_series']) ? session_cancel_series($sid, (int)$user['id']) : session_cancel_group($sid, (int)$user['id']);
        flash($n ? 'Cancelled ' . $n . ' session' . ($n === 1 ? '' : 's') . '. Clients with a confirmed email were sent a calendar cancellation.' : 'Nothing was cancelled: those sessions are no longer scheduled.', $n ? 'success' : 'error');
        redirect('schedule.php#upcoming-h');
    }
    $key = 'move_' . $sid;
    $date = valid_date(is_string($_POST['move_date'] ?? null) ? $_POST['move_date'] : '', 2000, (int)date('Y') + 5);
    $time = is_string($_POST['move_time'] ?? null) ? trim($_POST['move_time']) : '';
    $dur = is_string($_POST['move_duration'] ?? null) ? trim($_POST['move_duration']) : '';
    if ($date === null || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || !is_numeric($dur) || (float)$dur < 0.25 || (float)$dur > 12) {
        keep_input(); keep_field_errors([$key => 'Enter the new date, a start time and a length from 0.25 to 12 hours.']);
        redirect('schedule.php#s-' . $sid);
    }
    $start = strtotime($date . ' ' . $time . ':00');
    $r = session_move($sid, $start, $start + (int)round((float)$dur * 3600), (int)$user['id'], !empty($_POST['move_confirm']));
    if ($r['error'] !== null) { keep_input(); keep_field_errors([$key => $r['error']]); redirect('schedule.php#s-' . $sid); }
    if (!$r['moved']) {
        keep_input();
        keep_field_errors([$key => 'Not moved yet: ' . implode(' ', $r['warnings']) . ' Tick "Move anyway" and choose Move again if this time is right.']);
        redirect('schedule.php#s-' . $sid);
    }
    flash('Moved the session with ' . $sess['client_name'] . ' to ' . date('l, F j \a\t g:i A', $start) . '. The client\'s calendar invite was updated if they have a confirmed email.');
    redirect('schedule.php#s-' . (int)$sid);
}

/* ---- POST: update session status (Completed logs hours to the track) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $sid = (int)($_POST['session_id'] ?? 0);
    // Beside the plain outcomes: "Completed — hours already logged" (links the work
    // log chosen, logs nothing) and "Completed — log hours as well" (a separate visit
    // on a day that already has hours logged).
    $toIn = is_string($_POST['to'] ?? null) ? $_POST['to'] : '';
    $linkLogId = preg_match('/^Completed_logged_(\d+)$/D', $toIn, $lm) ? (int)$lm[1] : 0;
    $separate = $toIn === 'Completed_separate';
    $to = ($linkLogId > 0 || $separate) ? 'Completed' : (in_array($toIn, array_diff(SESSION_STATUSES, ['Scheduled']), true) ? $toIn : null);
    $st = db()->prepare('SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state, t.title AS track_title FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
        LEFT JOIN training_tracks t ON t.id = s.track_id WHERE s.id = ?');
    $st->execute([$sid]);
    $sess = $st->fetch();
    if ($sess && $to === null) {
        flash('Choose an outcome for the session: Completed, Cancelled or No Show.', 'error');
        redirect('schedule.php#s-' . $sid);
    }
    if ($sess && $to && $sess['status'] === 'Scheduled') {
        schedule_scope_check((int)$sess['client_id'], $user);
        // Every message names the session, as the row's controls do.
        $which = 'session with ' . $sess['client_name'] . ' on ' . fmt_datetime($sess['start_at']);
        $onTrack = $sess['track_title'] ? ' on the training track "' . $sess['track_title'] . '"' : '';
        // A session that has not started cannot be completed: it would log billable
        // hours for a session that never took place (one in progress can). Nor can it
        // be a No Show yet: a client who says ahead that they will not come cancelled.
        if (($to === 'Completed' || $to === 'No Show') && strtotime($sess['start_at']) > time()) {
            flash('The ' . $which . ' has not started yet, so it cannot be '
                . ($to === 'Completed' ? 'marked Completed.' : 'recorded as a No Show. If the client said they will not come, record it as Cancelled.')
                . ' Nothing was changed.', 'error');
            redirect('schedule.php#s-' . $sid);
        }
        // The session note typed in the row (optional): saved as a free-text case note
        // on the session's track, in the same transaction as the completion. It is
        // never dropped: typed with another outcome, or for a session with no track,
        // the outcome is refused with the note kept in the row.
        $sNote = is_string($_POST['session_note'] ?? null) ? trim($_POST['session_note']) : '';
        if ($sNote !== '') {
            $sNoteErr = $to !== 'Completed' ? 'A session note is saved only when the session is marked Completed. Choose Completed, or clear the note to record it as ' . $to . '.'
                : (!$sess['track_id'] ? 'No training track is linked to this session, so its note cannot be saved from here. Clear the note and record the outcome, then write the note on the client\'s Notes tab.'
                : (strlen($sNote) > 65535 ? 'The session note is too long to save. A note can hold 65,535 bytes, and accented letters and symbols count as 2 to 4 bytes each. Shorten it, or add the rest on the training track.' : null));
            if ($sNoteErr !== null) {
                keep_input();
                keep_field_errors(['session_note_' . $sid => $sNoteErr]);
                flash('Nothing was changed for the ' . $which . ': ' . $sNoteErr, 'error');
                redirect('schedule.php#s-' . $sid);
            }
        }
        // Hours and miles typed in the row (prefilled with the scheduled length) are
        // what gets logged; left blank, the scheduled length is. Checked as the track
        // page checks them; miles typed for an outcome that logs no hours are refused
        // with the row kept, never dropped.
        $logsHours = $to === 'Completed' && $sess['track_id'] && !$linkLogId;
        $hRaw = is_string($_POST['session_hours'] ?? null) ? trim($_POST['session_hours']) : '';
        $mRaw = is_string($_POST['session_miles'] ?? null) ? trim($_POST['session_miles']) : '';
        $miles = $mRaw === '' ? 0.0 : (is_numeric($mRaw) ? round((float)$mRaw, 1) : -1.0);
        $hours = !$logsHours ? 0.0 : ($hRaw === '' ? schedule_hours($sess) : (is_numeric($hRaw) ? round((float)$hRaw, 2) : -1.0));
        $rowErrs = [];
        // 0 (a session too short to bill, as its prefill says) completes it without hours.
        if ($logsHours && ($hours < 0 || $hours > 24)) $rowErrs['session_hours_' . $sid] = 'Enter the hours worked as a number from 0 to 24, such as 1.5.';
        if ($hours < 0.01) $hours = 0.0;
        if (!$rowErrs && $logsHours && $hours === 0.0 && $miles > 0) $rowErrs['session_miles_' . $sid] = 'Miles are logged with hours: enter the hours worked, or clear the miles.';
        if ($miles < 0 || $miles > 1000) $rowErrs['session_miles_' . $sid] = 'Mileage must be a number from 0 to 1000 miles.';
        elseif ($miles > 0 && !$logsHours) $rowErrs['session_miles_' . $sid] = 'Miles are logged with the session\'s hours, so only when it is marked Completed and logs hours. Clear the miles to record this outcome.';
        if ($rowErrs) {
            keep_input();
            keep_field_errors($rowErrs);
            flash('Nothing was changed for the ' . $which . ': ' . implode(' ', $rowErrs), 'error');
            redirect('schedule.php#s-' . $sid);
        }
        // Hours already logged for that day (by voice, the app or the track page) are
        // not logged a second time without the user saying which case this is.
        if ($hours > 0 && !$separate && ($cands = schedule_logged_candidates($sess))) {
            flash('Not completed: ' . implode(' and ', array_map(fn($w) => fmt_hours((float)$w['hours']), $cands))
                . ' already logged' . $onTrack . ' for ' . fmt_date($sess['start_at']) . '. If those are this session\'s hours, choose "Completed — hours already logged" for the '
                . $which . '. If it was a separate visit, choose "Completed — log hours as well". Nothing was changed.', 'error');
            redirect('schedule.php#s-' . $sid);
        }
        $authIssue = null;
        $linked = null;
        // One transaction, as in the app: the claim (double-submit guard) and the
        // hour logging succeed or fail together, so an error can't strand a
        // Completed session with no work log.
        db()->beginTransaction();
        try {
            if ($linkLogId) {
                // The chosen log must still be unbilled, on this track and day, and not
                // already another session's.
                $lq = db()->prepare('SELECT id, hours FROM work_logs WHERE id = ? AND track_id = ? AND work_date = ? AND is_billed = 0 FOR UPDATE');
                $lq->execute([$linkLogId, (int)$sess['track_id'], date('Y-m-d', strtotime($sess['start_at']))]);
                $linked = $lq->fetch();
                $taken = db()->prepare('SELECT 1 FROM scheduled_sessions WHERE work_log_id = ? LIMIT 1');
                $taken->execute([$linkLogId]);
                if (!$linked || $taken->fetchColumn()) {
                    db()->rollBack();
                    flash('Not completed: the hours chosen for the ' . $which . ' can no longer be linked (they were billed, removed, or linked to another session). Choose the outcome again.', 'error');
                    redirect('schedule.php#s-' . $sid);
                }
                $claim = db()->prepare('UPDATE scheduled_sessions SET status = "Completed", work_log_id = ? WHERE id = ? AND status = "Scheduled"');
                $claim->execute([$linkLogId, $sid]);
            } else {
                $claim = db()->prepare('UPDATE scheduled_sessions SET status = ? WHERE id = ? AND status = "Scheduled"');
                $claim->execute([$to, $sid]);
            }
            $claimed = $claim->rowCount() === 1;
            if ($claimed && $hours > 0) {
                // The hours are the instructor's who taught the session, whoever records it.
                $logId = auto_log_session((int)$sess['track_id'], date('Y-m-d', strtotime($sess['start_at'])), $hours,
                    schedule_log_description($sess), (int)$sess['user_id'], $authIssue, $miles);
                db()->prepare('UPDATE scheduled_sessions SET work_log_id = ? WHERE id = ?')
                    ->execute([$logId, $sid]);
                // The goal worked on and the service delivered (3.0.46): only this track's own.
                $sGoal = (int)($_POST['session_goal'] ?? 0);
                if ($sGoal > 0) {
                    $gq = db()->prepare('SELECT id FROM smart_goals WHERE id = ? AND track_id = ?');
                    $gq->execute([$sGoal, (int)$sess['track_id']]);
                    if ($gq->fetchColumn()) db()->prepare('UPDATE work_logs SET goal_id = ? WHERE id = ?')->execute([$sGoal, $logId]);
                }
                $sCat = is_string($_POST['session_category'] ?? null) ? $_POST['session_category'] : '';
                if ($sCat !== '') {
                    $tq2 = db()->prepare('SELECT * FROM training_tracks WHERE id = ?');
                    $tq2->execute([(int)$sess['track_id']]);
                    if (($trow = $tq2->fetch()) && in_array($sCat, track_visit_categories($trow), true)) db()->prepare('UPDATE work_logs SET rsa_category = ? WHERE id = ?')->execute([$sCat, $logId]);
                }
            }
            // The note is by the person recording the outcome, as on the track page.
            $noteSaved = false;
            if ($claimed && $sNote !== '') {
                track_note_create((int)$sess['client_id'], (int)$sess['track_id'], 'Session on ' . fmt_date($sess['start_at']),
                    assemble_note(['note_type' => 'Case Note', 'body' => $sNote]), (int)$user['id']);
                $noteSaved = true;
            }
            db()->commit();
        } catch (DomainException $e) {
            // auto_log_session() refuses a track with no hourly rate: nothing is saved,
            // the session stays Scheduled, and its own message says what to fix.
            if (db()->inTransaction()) db()->rollBack();
            flash('Not completed: the ' . $which . $onTrack . '. ' . $e->getMessage(), 'error');
            redirect('schedule.php#s-' . $sid);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        if ($claimed) {
            $act = ['Completed' => 'session.complete', 'Cancelled' => 'session.cancel', 'No Show' => 'session.no_show'][$to] ?? 'session.status';
            if ($to === 'No Show') counselor_noshow_notice($sid); // 3.0.41: the counselor is emailed
            // A future session cancelled: the client's calendar entry is removed (3.0.41).
            if ($to === 'Cancelled' && strtotime((string)$sess['start_at']) > time()) client_session_notice([$sid], 'cancelled');
            audit($user['id'], $act, 'session', $sid, 'client #' . (int)$sess['client_id']
                . ($hours > 0 ? ', ' . fmt_hours($hours) . ' logged' . ($miles > 0 ? ' and ' . $miles . ' mi' : '') : '') . ($linkLogId ? ', linked to work log #' . $linkLogId . ' (hours already logged)' : ''));
            // Flash text is escaped, so the "write the session note" link is drawn by
            // the page — when the note was not written in the row.
            if ($to === 'Completed' && $sess['track_id'] && !$noteSaved) {
                $_SESSION['session_note_link'] = ['track' => (int)$sess['track_id'], 'title' => (string)($sess['track_title'] ?? '')];
            }
            $noteMsg = $noteSaved ? ' The session note was saved' . ($onTrack ?: ' on the training track') . '.' : '';
            if ($linkLogId) {
                flash('Completed: the ' . $which . ', linked to the ' . fmt_hours((float)$linked['hours']) . ' already logged' . $onTrack . '. No more hours were logged.' . $noteMsg);
            } elseif ($hours > 0) {
                flash('Completed: the ' . $which . ' — ' . fmt_hours($hours) . ($miles > 0 ? ' and ' . rtrim(rtrim(number_format($miles, 1), '0'), '.') . ' miles' : '') . ' logged' . ($onTrack ?: ' to the training track') . '.' . ($authIssue ? ' ' . $authIssue : '') . $noteMsg);
            } elseif ($to === 'Completed') {
                flash('Completed: the ' . $which . ($sess['track_id'] ? ' (it was too short to log any hours).' : ' (no track linked, so no hours were logged).') . $noteMsg);
            } else {
                flash('Recorded as ' . $to . ': the ' . $which . '.');
                schedule_tell_instructors([$sess], $to, $user);
            }
        } else {
            flash('That session was already closed, so nothing changed.', 'error');
        }
    } elseif ($sess && $to) {
        flash('That session was already closed, so nothing changed.', 'error');
    }
    redirect(schedule_outcome_back($user));
}

$scoped = caseload_scoped();
$scopeAnd = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeParams = $scoped ? [(int)$user['id']] : [];

$cq = db()->prepare("SELECT id, name FROM clients c WHERE status IN ('Pending Assessment','Active Training','On Hold')" . $scopeAnd . " ORDER BY name");
$cq->execute($scopeParams);
$clients = $cq->fetchAll();
$tq = db()->prepare("SELECT t.id, t.client_id, t.title, t.created_at, c.name AS client_name FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.status = 'Active'" . $scopeAnd . " ORDER BY c.name, t.title");
$tq->execute($scopeParams);
$tracks = $tq->fetchAll();
// Each client's active tracks, newest first (the group form's default, as on the server).
$tracksByClient = [];
foreach ($tracks as $t) $tracksByClient[(int)$t['client_id']][] = $t;
foreach ($tracksByClient as &$list) usort($list, fn($a, $b) => [$b['created_at'], (int)$b['id']] <=> [$a['created_at'], (int)$a['id']]);
unset($list);
$staff = db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();

// The upcoming list shows the next 100; a client filter reaches any later session
// (this page is the only place on the website a scheduled session can be cancelled).
$upClients = db()->prepare("SELECT DISTINCT c.id, c.name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    WHERE s.status = 'Scheduled' AND s.end_at >= NOW()" . $scopeAnd . " ORDER BY c.name");
$upClients->execute($scopeParams);
$upClients = $upClients->fetchAll();
$upClient = is_string($_GET['client'] ?? null) && ctype_digit($_GET['client']) ? (int)$_GET['client'] : 0;
$upAnd = $scopeAnd . ($upClient > 0 ? ' AND s.client_id = ?' : '');
$upParams = $upClient > 0 ? array_merge($scopeParams, [$upClient]) : $scopeParams;
$ucq = db()->prepare("SELECT COUNT(*) FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    WHERE s.status = 'Scheduled' AND s.end_at >= NOW()" . $upAnd);
$ucq->execute($upParams);
$upcomingTotal = (int)$ucq->fetchColumn();
$uq = db()->prepare("SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state, u.name AS staff_name, t.title AS track_title
    FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
    WHERE s.status = 'Scheduled' AND s.end_at >= NOW()" . $upAnd . " ORDER BY s.start_at LIMIT 100");
$uq->execute($upParams);
$upcoming = $uq->fetchAll();
// Every group still awaiting an outcome, whatever the list limit or filter, so a
// group cut off at the end of the upcoming list still gets its card.
$gq = db()->prepare("SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state, u.name AS staff_name, t.title AS track_title
    FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
    WHERE s.status = 'Scheduled' AND s.group_uid IS NOT NULL" . $scopeAnd . " ORDER BY s.start_at, s.id");
$gq->execute($scopeParams);
$groupRows = $gq->fetchAll();

// Sessions whose time has passed but that are still 'Scheduled'. Every one stays
// listed (no limit) until an outcome is recorded, so a session can never scroll
// out of reach with its hours unlogged.
$oq = db()->prepare("SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state, u.name AS staff_name, t.title AS track_title
    FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
    WHERE s.status = 'Scheduled' AND s.end_at < NOW()" . $scopeAnd . " ORDER BY s.start_at");
$oq->execute($scopeParams);
$overdue = $oq->fetchAll();

$rq = db()->prepare("SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state, u.name AS staff_name, t.title AS track_title
    FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
    WHERE s.status <> 'Scheduled'" . $scopeAnd . " ORDER BY s.start_at DESC LIMIT 30");
$rq->execute($scopeParams);
$recent = $rq->fetchAll();

page_header('Schedule', $user, 'schedule');

function session_row(array $s, string $csrf): void {
    // Hidden text for the row's controls: a client can have several sessions
    // listed, so the name alone does not say which one a control belongs to.
    $which = 'session with ' . h($s['client_name']) . ' on ' . fmt_datetime($s['start_at']);
    $started = strtotime($s['start_at']) <= time();
    // The confirm says what completing this session actually logs, and where.
    $hrs = $s['track_id'] ? schedule_hours($s) : 0.0;
    // Hours already logged on the track for that day, which this session may account
    // for: it is then completed by linking them, or by logging more for a separate visit.
    $cands = ($started && $hrs > 0 && $s['status'] === 'Scheduled') ? schedule_logged_candidates($s) : [];
    $onTrack = $s['track_title'] ? ' "' . $s['track_title'] . '"' : '';
    // %H% is replaced by the hours typed in the row (the scheduled length unless changed).
    $completeMsg = !$s['track_id'] ? 'Mark completed? No training track is linked, so no hours will be logged.'
        : 'Mark completed? This logs %H% to the training track' . $onTrack . '.';
    $separateMsg = 'Mark completed and log hours as well? This logs %H% to the training track' . $onTrack . ', beside the hours already logged for that day.';
    $linkedMsg = 'Mark completed as the hours already logged? The session is linked to them and no more hours are logged.';
    $js = fn(string $m): string => h(json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); ?>
    <tr>
      <th scope="row" id="s-<?= (int)$s['id'] ?>" tabindex="-1"><?= fmt_datetime($s['start_at']) ?><br><span class="muted">to <?= date('g:i A', strtotime($s['end_at'])) ?></span></th>
      <?php // The client links to their record only for staff who can open it. ?>
      <td><?php if (can('clients')): ?><a href="client.php?id=<?= (int)$s['client_id'] ?>"><?= h($s['client_name']) ?></a><?php else: ?><?= h($s['client_name']) ?><?php endif; ?>
          <?= $s['track_title'] ? '<br><span class="muted">' . h($s['track_title']) . '</span>' : '' ?><?php
          // A client in another time zone (4.1.0): their clock, so nobody books 8 AM for them by mistake.
          if ($cz = client_zone($s)): ?><br><span class="muted">Client's time <?= h(zone_date($cz, 'g:i A T', strtotime($s['start_at']))) ?></span><?php endif; ?></td>
      <td><?= h($s['staff_name'] ?? '—') ?></td>
      <td><?php if (!empty($s['is_remote'])): ?><span class="badge badge-active">Remote</span><?= !empty($s['meeting_url']) ? ' <a href="' . h($s['meeting_url']) . '" target="_blank" rel="noopener">Join<span class="visually-hidden"> ' . $which . ' (opens in a new tab)</span></a>' : '' ?><?php else: ?><?= trim((string)$s['location']) !== '' ? h($s['location']) . ' <a href="https://maps.apple.com/?daddr=' . rawurlencode((string)$s['location']) . '" target="_blank" rel="noopener">Directions<span class="visually-hidden"> ' . $which . ' (opens maps in a new tab)</span></a>' : '—' ?><?php endif; ?></td>
      <td><?= status_badge($s['status']) ?><?php // Checked in from the app (API check_in_session): the time the client arrived.
          if (!empty($s['checked_in_at'])): ?><br><span class="muted">Arrived <?= date('H:i', strtotime($s['checked_in_at'])) ?><span class="visually-hidden"> for the <?= $which ?></span></span><?php endif; ?></td>
      <td>
        <?php if ($s['status'] === 'Scheduled'): ?>
        <form method="post" action="schedule.php" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
          <label class="visually-hidden" for="st-<?= (int)$s['id'] ?>">Update status for <?= $which ?></label>
          <?php // A session that has not started yet can only be cancelled, and nothing is
          // preselected for it (or when hours for that day are already logged), so Save
          // never records an outcome by default. ?>
          <select id="st-<?= (int)$s['id'] ?>" name="to">
            <?php if (!$started || $cands): ?><option value="">— Choose an outcome —</option><?php endif; ?>
            <?php if ($started && $cands): foreach ($cands as $w): ?>
            <option value="Completed_logged_<?= (int)$w['id'] ?>">Completed — hours already logged (<?= h(fmt_hours((float)$w['hours']) . (trim((string)$w['description']) !== '' ? ': ' . mb_strimwidth(trim((string)$w['description']), 0, 60, '…') : '')) ?>)</option>
            <?php endforeach; ?>
            <option value="Completed_separate">Completed — log hours as well (a separate visit)</option>
            <?php elseif ($started): ?><option value="Completed">Completed<?= $s['track_id'] ? ' (log hours)' : '' ?></option><?php endif; ?>
            <option value="Cancelled">Cancelled</option>
            <?php if ($started): ?><option value="No Show">No Show</option><?php endif; ?>
          </select>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm"
            onclick="var s=document.getElementById('st-<?= (int)$s['id'] ?>').value,t=document.getElementById('sn-<?= (int)$s['id'] ?>'),hf=document.getElementById('sh-<?= (int)$s['id'] ?>'),hv=hf&&hf.value.trim()!==''?hf.value.trim():'<?= h(rtrim(rtrim(number_format($hrs, 2, '.', ''), '0'), '.')) ?>',hs=hv+(Number(hv)===1?' hour':' hours'),n=(t&&t.value.trim()!=='')?' The session note is saved with it.':'';return s===''||confirm(s==='Completed'?(<?= $s['track_id'] ? '1' : '0' ?>&&!(Number(hv)>0)?'Mark completed? No hours are logged.':<?= $js($completeMsg) ?>.replace('%H%',hs))+n:(s==='Completed_separate'?<?= $js($separateMsg) ?>.replace('%H%',hs)+n:(s.indexOf('Completed_logged_')===0?<?= $js($linkedMsg) ?>+n:'Record this session as '+s+'? This cannot be changed back. Choose OK to record it, or Cancel to leave the session as it is.')));">Save<span class="visually-hidden"> status for <?= $which ?></span></button>
          <?php // Hours, miles and the session note, recorded with the outcome (the hours
          // prefilled with the scheduled length; all saved on the track when the session
          // is marked Completed). Collapsed to keep the row short; open when something
          // was refused or holds what was typed. No track: nowhere to save them.
          if ($started && $s['track_id']): $sidS = (string)(int)$s['id']; $snErr = 'session_note_' . $sidS;
            $snMine = old_in('session_id') === $sidS;
            $snOpen = has_field_error($snErr) || has_field_error('session_hours_' . $sidS) || has_field_error('session_miles_' . $sidS)
                || ($snMine && (trim(old_in('session_note')) !== '' || trim(old_in('session_miles')) !== '')); ?>
          <details<?= $snOpen ? ' open' : '' ?>>
            <summary>Hours, miles, goal and session note<span class="visually-hidden"> for the <?= $which ?></span></summary>
            <div class="field"><label for="sh-<?= $sidS ?>">Hours worked<span class="visually-hidden"> for the <?= $which ?></span></label>
              <input type="text" inputmode="decimal" autocomplete="off" id="sh-<?= $sidS ?>" name="session_hours" value="<?= h($snMine && old_in('session_hours') !== '' ? old_in('session_hours') : rtrim(rtrim(number_format($hrs, 2, '.', ''), '0'), '.')) ?>" <?= field_aria('session_hours_' . $sidS) ?>><?= field_error('session_hours_' . $sidS) ?></div>
            <div class="field"><label for="sm-<?= $sidS ?>">Miles driven<span class="visually-hidden"> for the <?= $which ?></span></label>
              <input type="text" inputmode="decimal" autocomplete="off" id="sm-<?= $sidS ?>" name="session_miles" value="<?= $snMine ? h(old_in('session_miles')) : '' ?>" <?= field_aria('session_miles_' . $sidS) ?>><?= field_error('session_miles_' . $sidS) ?></div>
            <?php // The goal worked on and the service delivered (3.0.46).
            $sGoals = db()->prepare("SELECT id, specific_goal FROM smart_goals WHERE track_id = ? AND status <> 'Achieved' ORDER BY id");
            $sGoals->execute([(int)$s['track_id']]);
            $sGoals = $sGoals->fetchAll();
            $sTr = db()->prepare('SELECT * FROM training_tracks WHERE id = ?'); $sTr->execute([(int)$s['track_id']]);
            $sCats = ($sTrRow = $sTr->fetch()) ? track_visit_categories($sTrRow) : []; ?>
            <?php if ($sGoals): ?>
            <div class="field"><label for="sg-<?= $sidS ?>">Goal worked on<span class="visually-hidden"> for the <?= $which ?></span></label>
              <select id="sg-<?= $sidS ?>" name="session_goal"><option value="0">None</option><?php foreach ($sGoals as $sg): ?><option value="<?= (int)$sg['id'] ?>"><?= h(mb_strimwidth((string)$sg['specific_goal'], 0, 80, '…')) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <?php if (count($sCats) > 1): ?>
            <div class="field"><label for="sc-<?= $sidS ?>">Service delivered<span class="visually-hidden"> for the <?= $which ?></span></label>
              <select id="sc-<?= $sidS ?>" name="session_category"><?= select_options($sCats, $sCats[0]) ?></select></div>
            <?php endif; ?>
            <div class="field"><label for="sn-<?= $sidS ?>">Session note<span class="visually-hidden"> for the <?= $which ?></span></label>
              <textarea id="sn-<?= $sidS ?>" name="session_note" rows="4" <?= field_aria($snErr) ?>><?= $snMine ? h(old_in('session_note')) : '' ?></textarea><?= field_error($snErr) ?></div>
          </details>
          <?php endif; ?>
        </form>
        <?php // Move it, or cancel the rest of its series or its whole group (3.0.43). ?>
        <?php $sidM = (int)$s['id']; $mKey = 'move_' . $sidM; $mMine = old_in('session_id') === (string)$sidM && old_in('move_session') !== ''; ?>
        <details<?= has_field_error($mKey) ? ' open' : '' ?>>
          <summary>Move or cancel more<span class="visually-hidden"> for the <?= $which ?></span></summary>
          <form method="post" action="schedule.php" class="stacked" novalidate>
            <?= csrf_field() ?><input type="hidden" name="session_id" value="<?= $sidM ?>">
            <?= field_error($mKey) ?>
            <div class="field"><label for="md-<?= $sidM ?>">New date<span class="visually-hidden"> for the <?= $which ?></span></label>
              <input type="date" id="md-<?= $sidM ?>" name="move_date" value="<?= h($mMine ? old_in('move_date') : date('Y-m-d', strtotime($s['start_at']))) ?>" <?= field_aria($mKey) ?>></div>
            <div class="field"><label for="mt-<?= $sidM ?>">New start time</label>
              <input type="time" id="mt-<?= $sidM ?>" name="move_time" value="<?= h($mMine ? old_in('move_time') : date('H:i', strtotime($s['start_at']))) ?>"></div>
            <div class="field"><label for="ml-<?= $sidM ?>">Length in hours</label>
              <input type="text" inputmode="decimal" id="ml-<?= $sidM ?>" name="move_duration" value="<?= h($mMine ? old_in('move_duration') : rtrim(rtrim(number_format((strtotime($s['end_at']) - strtotime($s['start_at'])) / 3600, 2, '.', ''), '0'), '.')) ?>"></div>
            <?php if (has_field_error($mKey) && str_starts_with(form_errors()[$mKey] ?? '', 'Not moved yet')): ?>
            <div class="field"><label><input type="checkbox" name="move_confirm" value="1"> Move anyway</label></div>
            <?php endif; ?>
            <button type="submit" name="move_session" value="1" class="btn btn-secondary btn-sm">Move<span class="visually-hidden"> the <?= $which ?></span></button>
          </form>
          <?php if (!empty($s['series_uid']) || !empty($s['group_uid'])): ?>
          <form method="post" action="schedule.php" class="inline-form"><?= csrf_field() ?><input type="hidden" name="session_id" value="<?= $sidM ?>">
            <?php if (!empty($s['series_uid'])): ?><button type="submit" name="cancel_series" value="1" class="btn btn-secondary btn-sm" onclick="return confirm('Cancel this session and every later session in its weekly series? The client is sent a calendar cancellation.');">Cancel this and later sessions in the series<span class="visually-hidden"> (<?= $which ?>)</span></button><?php endif; ?>
            <?php if (!empty($s['group_uid'])): ?><button type="submit" name="cancel_group" value="1" class="btn btn-secondary btn-sm" onclick="return confirm('Cancel this group session for every client in it? Each client is sent a calendar cancellation.');">Cancel the whole group<span class="visually-hidden"> (<?= $which ?>)</span></button><?php endif; ?>
          </form>
          <?php endif; ?>
        </details>
        <?php endif; ?>
      </td>
    </tr>
<?php }
?>
<h1>Session schedule</h1>
<?php // After a session on a track is completed: the next step, writing its note. It
// joins the flash confirmation (moved and focused with it); without scripts it stays here.
$noteLink = $_SESSION['session_note_link'] ?? null; unset($_SESSION['session_note_link']);
if (is_array($noteLink) && can('clients')): ?>
<div class="alert alert-info" id="session-note-next"><a href="track.php?id=<?= (int)$noteLink['track'] ?>#log-session-h">Write the session note<?= $noteLink['title'] !== '' ? ' on the training track "' . h($noteLink['title']) . '"' : ' on its training track' ?></a></div>
<script>(function(){var a=document.getElementById('flash-messages'),p=document.getElementById('session-note-next');if(a&&p)a.appendChild(p);})();</script>
<?php endif; ?>
<div class="page-actions"><a class="btn btn-secondary" href="capacity.php">Capacity planning</a>
  <a class="btn btn-secondary" href="access_bookings.php">Interpreter, CART (real-time captioning) and transport bookings</a></div>

<?php // Both booking forms start collapsed so the session lists come first; each opens
// after its own refused or restored entry, or a link to its heading.
$addOpen = old_in('add_session') !== '' || array_intersect_key(form_errors(), array_flip(['client_id', 'track_id', 'staff_id', 'date', 'time', 'duration', 'repeat_weeks', 'location', 'notes', 'meeting_url', 'meeting_make', 'authorization_ack']));
$groupOpen = old_in('add_group_session') !== '' || array_filter(array_keys(form_errors()), fn($k) => str_starts_with((string)$k, 'g_')); ?>
<section class="card" aria-labelledby="add-h">
  <h2 class="visually-hidden">Schedule a session</h2>
  <details id="add-session"<?= $addOpen ? ' open' : '' ?>>
  <summary accesskey="n" id="add-h">Schedule a session</summary>
  <p class="hint">Fields marked with an asterisk (*) are required.</p>
  <form method="post" action="schedule.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['client_id' => 'Client', 'track_id' => 'Training track', 'staff_id' => 'Instructor', 'date' => 'Date',
        'time' => 'Start time', 'duration' => 'Duration (hours)', 'repeat_weeks' => 'Repeat weekly', 'location' => 'Location',
        'notes' => 'Notes', 'meeting_url' => 'Meeting link', 'meeting_make' => 'Make the meeting link', 'authorization_ack' => 'Authorization approval',
        'plan_days' => 'Days of the week', 'plan_until' => 'Last date', 'plan_dates' => 'Dates', 'plan_ok' => 'Book these sessions'],
        ['plan_days' => 'plan-day-1', 'plan_ok' => 'plan-preview-h']) ?>
    <div class="grid grid-2">
      <div class="field">
        <label for="client_id">Client <?= required_mark() ?></label>
        <select id="client_id" name="client_id" required <?= field_aria('client_id') ?>>
          <option value="">— Choose a client —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= old_in('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select><?= field_error('client_id') ?>
      </div>
      <div class="field">
        <label for="track_id">Training track <span class="hint">Link a track so completed sessions auto-log hours</span></label>
        <select id="track_id" name="track_id" <?= field_aria('track_id') ?>>
          <option value="">— None —</option>
          <?php foreach ($tracks as $t): ?><option value="<?= (int)$t['id'] ?>" <?= old_in('track_id') === (string)$t['id'] ? 'selected' : '' ?>><?= h($t['client_name'] . ' — ' . $t['title']) ?></option><?php endforeach; ?>
        </select><?= field_error('track_id') ?>
      </div>
      <div class="field">
        <label for="staff_id">Instructor</label>
        <select id="staff_id" name="staff_id" <?= field_aria('staff_id') ?>>
          <?php $pickStaff = old_in('staff_id', (string)$user['id']);
          foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $pickStaff ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
        </select><?= field_error('staff_id') ?>
      </div>
      <div class="field"><label for="date">Date <?= required_mark() ?></label>
        <input type="date" id="date" name="date" required min="2000-01-01" max="<?= (int)date('Y') + 5 ?>-12-31" value="<?= h(old_in('date', date('Y-m-d'))) ?>" <?= field_aria('date') ?>><?= field_error('date') ?></div>
      <div class="field"><label for="time">Start time <?= required_mark() ?></label>
        <input type="time" id="time" name="time" required value="<?= h(old_in('time', '10:00')) ?>" <?= field_aria('time') ?>><?= field_error('time') ?></div>
      <div class="field"><label for="duration">Duration (hours) <?= required_mark() ?></label>
        <input type="number" id="duration" name="duration" step="0.25" min="0.25" max="12" required value="<?= h(old_in('duration', '1.5')) ?>" <?= field_aria('duration') ?>><?= field_error('duration') ?></div>
      <div class="field"><label for="repeat_weeks">Repeat weekly <span class="hint">1 = single session; e.g., 8 = every week for 8 weeks</span></label>
        <input type="number" id="repeat_weeks" name="repeat_weeks" min="1" max="52" value="<?= h(old_in('repeat_weeks', '1')) ?>" <?= field_aria('repeat_weeks') ?>><?= field_error('repeat_weeks') ?></div>
      <div class="field"><label for="location">Location</label>
        <input type="text" id="location" name="location" maxlength="190" value="<?= h(old_in('location')) ?>" <?= field_aria('location') ?>><?= field_error('location') ?></div>
      <div class="field"><label for="notes">Notes</label>
        <input type="text" id="notes" name="notes" maxlength="255" value="<?= h(old_in('notes')) ?>" <?= field_aria('notes') ?>><?= field_error('notes') ?></div>
    </div>
    <?php // Several sessions at once (4.9.0): collapsed, so a single booking has no extra stops.
    $planIn = old_in('plan_pattern', 'weekly'); $planDays = array_map('intval', old_in_list('plan_days'));
    $planOpen = $planIn !== 'weekly' || has_field_error('plan_days') || has_field_error('plan_until') || has_field_error('plan_dates') || has_field_error('plan_ok'); ?>
    <details<?= $planOpen ? ' open' : '' ?>><summary>More than one session: several days a week, a list of dates, or the whole authorization</summary>
      <div class="field"><label for="plan_pattern">How often</label>
        <select id="plan_pattern" name="plan_pattern"><?php foreach (PLAN_PATTERNS as $pk => $pl): ?><option value="<?= $pk ?>"<?= $planIn === $pk ? ' selected' : '' ?>><?= h($pl) ?></option><?php endforeach; ?></select></div>
      <fieldset id="plan_days"<?= has_field_error('plan_days') ? ' aria-describedby="err-plan_days"' : '' ?>><legend>Days of the week (several days, or the whole authorization)</legend>
        <?= field_error('plan_days') ?>
        <?php foreach (PLAN_WEEKDAYS as $dn => $dl): ?><label class="check"><input type="checkbox" id="plan-day-<?= $dn ?>" name="plan_days[]" value="<?= $dn ?>"<?= in_array($dn, $planDays, true) ? ' checked' : '' ?>> <?= $dl ?></label><?php endforeach; ?>
      </fieldset>
      <div class="field"><label for="plan_until">Last date (or a number of weeks in Repeat weekly)</label>
        <input type="date" id="plan_until" name="plan_until" value="<?= h(old_in('plan_until')) ?>" <?= field_aria('plan_until') ?>><?= field_error('plan_until') ?></div>
      <div class="field"><label for="plan_dates">Dates, one per line (2026-11-03, or 2026-11-03 2:30 PM)</label>
        <textarea id="plan_dates" name="plan_dates" rows="4" <?= field_aria('plan_dates') ?>><?= h(old_in('plan_dates')) ?></textarea><?= field_error('plan_dates') ?></div>
      <?php $preview = $_SESSION['plan_preview'] ?? null; if ($preview && has_field_error('plan_ok')): ?>
      <h3 id="plan-preview-h" tabindex="-1">The sessions this books (<?= count($preview['list']) ?>)</h3>
      <ul aria-labelledby="plan-preview-h"><?php foreach ($preview['list'] as $pl): ?><li><?= h($pl) ?></li><?php endforeach; ?></ul>
      <div class="field"><label><input type="checkbox" id="plan_ok" name="plan_ok" value="<?= h($preview['hash']) ?>" <?= field_aria('plan_ok') ?>> Book these <?= count($preview['list']) ?> sessions</label><?= field_error('plan_ok') ?></div>
      <?php endif; ?>
    </details>
    <div class="field"><label><input type="checkbox" id="is_remote" name="is_remote" value="1" <?= old_in('is_remote') ? 'checked' : '' ?>> Remote / telepractice session</label></div>
    <div class="field" id="meeting_url_wrap">
      <label for="meeting_url">Meeting link <span class="hint">the video/phone link clients and you will join (https://…); tick Remote above to use it</span></label>
      <input type="url" id="meeting_url" name="meeting_url" maxlength="500" placeholder="https://" value="<?= h(old_in('meeting_url')) ?>" <?= field_aria('meeting_url') ?>><?= field_error('meeting_url') ?></div>
    <?php $meetApps = array_filter(array_keys(MEETING_PROVIDERS), fn($p) => meeting_app($p) !== null); if ($meetApps): ?>
    <div class="field"><label for="meeting_make">Make the meeting link <span class="hint">in the instructor's own connected account, instead of typing one</span></label>
      <select id="meeting_make" name="meeting_make" <?= field_aria('meeting_make') ?>>
        <option value="">No</option>
        <?php foreach ($meetApps as $mp): ?><option value="<?= h($mp) ?>"<?= old_in('meeting_make') === $mp ? ' selected' : '' ?>>In <?= h(MEETING_PROVIDERS[$mp]) ?></option><?php endforeach; ?>
      </select><?= field_error('meeting_make') ?></div>
    <?php endif; ?>
    <?php // The approval box is drawn only beside the authorization warnings it approves
    // (their fingerprint goes with it), never before any warning has been shown. ?>
    <?php if (old_in('add_session') !== '' && old_in('authorization_hash') !== '' && (has_field_error('authorization_ack') || has_field_error('plan_ok'))): ?>
    <input type="hidden" name="authorization_hash" value="<?= h(old_in('authorization_hash')) ?>">
    <div class="field"><label><input type="checkbox" id="authorization_ack" name="authorization_ack" value="1"<?= old_in('authorization_ack') !== '' ? ' checked' : '' ?> <?= field_aria('authorization_ack') ?>> I reviewed these authorization warnings and approve this booking despite them.</label><?= field_error('authorization_ack') ?></div>
    <?php endif; ?>
    <button type="submit" name="add_session" value="1" class="btn btn-primary"
      onclick="var w=parseInt(document.getElementById('repeat_weeks').value||'1',10),p=document.getElementById('plan_pattern');return (p&&p.value!=='weekly')||w<=1||confirm('Schedule '+w+' weekly sessions? Each must be cancelled individually if plans change.');">Schedule &amp; send calendar invite</button>
  </form>
  </details>
</section>

<section class="card" aria-labelledby="group-h">
  <h2 class="visually-hidden">Schedule a group session</h2>
  <details id="add-group"<?= $groupOpen ? ' open' : '' ?>>
  <summary id="group-h">Schedule a group session</summary>
  <p class="muted">One time slot, multiple clients (for example, a Pre-Employment Transition Services (Pre-ETS) workshop). Each client's hours log to their own training track on completion; when a client has more than one active track, choose which one.</p>
  <p class="hint">Fields marked with an asterisk (*) are required.</p>
  <?php // The group form's fields carry a g_ prefix so neither form refills the other after an error.
  // Ticks come back after a validation error (g_clients) and after a lapsed
  // session and sign-in (the posted group_clients[] list).
  $keptGroup = array_map('intval', array_filter(array_merge(explode(',', old_in('g_clients')), old_in_list('group_clients'))));
  $gLabels = ['g_clients' => 'Clients', 'g_staff_id' => 'Instructor', 'g_date' => 'Date', 'g_time' => 'Start', 'g_duration' => 'Duration (hours)',
      'g_location' => 'Location', 'g_notes' => 'Notes', 'g_meeting_url' => 'Meeting link', 'g_authorization_ack' => 'Authorization approval'];
  foreach ($clients as $c) if (count($tracksByClient[(int)$c['id']] ?? []) > 1) $gLabels['g_track_' . (int)$c['id']] = 'Training track for ' . $c['name'];
  // A track error for a client no longer listed here is still reported.
  foreach (array_keys(form_errors()) as $k) if (str_starts_with($k, 'g_track_') && !isset($gLabels[$k])) $gLabels[$k] = 'Training track'; ?>
  <form method="post" action="schedule.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary($gLabels, $clients ? ['g_clients' => 'gc-' . (int)$clients[0]['id']] : []) ?>
    <fieldset id="g_clients"<?= has_field_error('g_clients') ? ' aria-describedby="err-g_clients"' : '' ?>>
      <legend>Clients (choose two or more)</legend>
      <?= field_error('g_clients') ?>
      <?php foreach ($clients as $c): $gcid = (int)$c['id']; $ct = $tracksByClient[$gcid] ?? []; ?>
      <div class="field"><label><input type="checkbox" id="gc-<?= $gcid ?>" name="group_clients[]" value="<?= $gcid ?>" <?= in_array($gcid, $keptGroup, true) ? 'checked' : '' ?>> <?= h($c['name']) ?><?php
        if (count($ct) === 1): ?><span class="muted"> — <?= h($ct[0]['title']) ?></span><?php
        elseif (!$ct): ?><span class="muted"> — no active training track, so no hours will be logged</span><?php endif; ?></label>
        <?php if (count($ct) > 1): $tk = 'g_track_' . $gcid; $pickTrack = old_in($tk, (string)$ct[0]['id']); ?>
        <div class="field"><label for="<?= $tk ?>">Training track for <?= h($c['name']) ?> <span class="hint">their hours log here; the newest track is preselected</span></label>
          <select id="<?= $tk ?>" name="<?= $tk ?>" <?= field_aria($tk) ?>><?php foreach ($ct as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (string)$t['id'] === $pickTrack ? 'selected' : '' ?>><?= h($t['title']) ?></option><?php endforeach; ?></select><?= field_error($tk) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <div class="grid grid-4">
      <div class="field"><label for="g_staff_id">Group session instructor</label>
        <select id="g_staff_id" name="g_staff_id" <?= field_aria('g_staff_id') ?>><?php $pickStaff = old_in('g_staff_id', (string)$user['id']);
          foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $pickStaff ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?></select><?= field_error('g_staff_id') ?></div>
      <div class="field"><label for="g_date">Group session date <?= required_mark() ?></label>
        <input type="date" id="g_date" name="g_date" required min="2000-01-01" max="<?= (int)date('Y') + 5 ?>-12-31" value="<?= h(old_in('g_date', date('Y-m-d'))) ?>" <?= field_aria('g_date') ?>><?= field_error('g_date') ?></div>
      <div class="field"><label for="g_time">Group session start time <?= required_mark() ?></label>
        <input type="time" id="g_time" name="g_time" required value="<?= h(old_in('g_time', '10:00')) ?>" <?= field_aria('g_time') ?>><?= field_error('g_time') ?></div>
      <div class="field"><label for="g_duration">Group session duration (hours) <?= required_mark() ?></label>
        <input type="number" id="g_duration" name="g_duration" step="0.25" min="0.25" max="12" required value="<?= h(old_in('g_duration', '1.5')) ?>" <?= field_aria('g_duration') ?>><?= field_error('g_duration') ?></div>
      <div class="field"><label for="g_location">Group session location</label>
        <input type="text" id="g_location" name="g_location" maxlength="190" value="<?= h(old_in('g_location')) ?>" <?= field_aria('g_location') ?>><?= field_error('g_location') ?></div>
      <div class="field"><label for="g_notes">Group session notes</label>
        <input type="text" id="g_notes" name="g_notes" maxlength="255" value="<?= h(old_in('g_notes')) ?>" <?= field_aria('g_notes') ?>><?= field_error('g_notes') ?></div>
    </div>
    <div class="field"><label><input type="checkbox" id="g_is_remote" name="g_is_remote" value="1" <?= old_in('g_is_remote') ? 'checked' : '' ?>> Group session is remote / telepractice</label></div>
    <div class="field"><label for="g_meeting_url">Meeting link <span class="hint">https://…; tick Remote above to use it</span></label>
      <input type="url" id="g_meeting_url" name="g_meeting_url" maxlength="500" placeholder="https://" value="<?= h(old_in('g_meeting_url')) ?>" <?= field_aria('g_meeting_url') ?>><?= field_error('g_meeting_url') ?></div>
    <?php if (old_in('add_group_session') !== '' && old_in('g_authorization_hash') !== '' && has_field_error('g_authorization_ack')): ?>
    <input type="hidden" name="g_authorization_hash" value="<?= h(old_in('g_authorization_hash')) ?>">
    <div class="field"><label><input type="checkbox" id="g_authorization_ack" name="g_authorization_ack" value="1" <?= field_aria('g_authorization_ack') ?>> I reviewed these authorization warnings and approve this group booking despite them.</label><?= field_error('g_authorization_ack') ?></div>
    <?php endif; ?>
    <button type="submit" name="add_group_session" value="1" class="btn btn-primary">Schedule group session</button>
  </form>
  </details>
</section>
<script>(function(){var m={'#add-h':'add-session','#group-h':'add-group'},d=document.getElementById(m[location.hash]||'');if(d)d.open=true;})();</script>

<?php
$groupsPending = [];
// Ended groups first (the rows are in start order): they still need their hours logged.
foreach ($groupRows as $s) $groupsPending[$s['group_uid']][] = $s;
?>
<?php if (array_filter($groupsPending, fn($m) => count($m) >= 2)): ?><h2>Group sessions awaiting completion</h2><?php endif; ?>
<?php $endedIds = array_column($overdue, 'id'); // ended and upcoming groups share this list, so each card says which it is
foreach ($groupsPending as $uid => $members): if (count($members) < 2) continue;
    $groupEnded = (bool)array_intersect(array_column($members, 'id'), $endedIds); ?>
<div class="card">
  <h3>Group session — <?= fmt_datetime($members[0]['start_at']) ?> (<?= count($members) ?> clients)
    <?= $groupEnded ? '<span class="badge badge-pending">Ended — not yet completed</span>' : '<span class="badge badge-active">Upcoming</span>' ?></h3>
  <?php if (strtotime($members[0]['start_at']) <= time()): // a group that has not started cannot be completed
    $gWhen = fmt_datetime($members[0]['start_at']); ?>
  <?php // Attendance is recorded with the outcome: a client not ticked is recorded as No
  // Show with no hours logged, so an absent client is never billed for the sitting. ?>
  <form method="post" action="schedule.php" class="stacked"><?= csrf_field() ?>
    <input type="hidden" name="group_uid" value="<?= h($uid) ?>">
    <input type="hidden" name="attendance_form" value="1">
    <fieldset aria-describedby="gatt-hint-<?= h($uid) ?>">
      <legend>Who attended?</legend>
      <p class="hint" id="gatt-hint-<?= h($uid) ?>">Untick anyone who did not come: they are recorded as No Show, and no hours are logged for them.</p>
      <?php foreach ($members as $m): $mh = $m['track_id'] ? schedule_hours($m) : 0.0; ?>
      <input type="hidden" name="listed[]" value="<?= (int)$m['id'] ?>">
      <div class="field"><label><input type="checkbox" class="att-box" name="attended[]" value="<?= (int)$m['id'] ?>" data-bill="<?= $m['track_id'] ? '1' : '0' ?>" checked> <?= h($m['client_name']) ?> attended<span class="visually-hidden"> the group session of <?= $gWhen ?></span></label>
        <span class="muted">(<?= $m['track_id'] ? 'hours logged to ' . ($m['track_title'] ? h($m['track_title']) : 'their training track') : 'no training track, so no hours are logged' ?>)</span></div>
      <?php endforeach; ?>
    </fieldset>
    <?php // Prefilled from the first member on a track (a member without one logs no hours);
    // blank when none is, which means each client's own scheduled length.
    $gFirst = current(array_filter($members, fn($m) => !empty($m['track_id'])));
    $gDefault = $gFirst ? rtrim(rtrim(number_format(schedule_hours($gFirst), 2, '.', ''), '0'), '.') : ''; ?>
    <div class="field"><label for="gh-<?= h($uid) ?>">Hours for each client who attended<span class="visually-hidden"> the group session of <?= $gWhen ?></span></label>
      <input type="text" inputmode="decimal" autocomplete="off" id="gh-<?= h($uid) ?>" name="g_hours" value="<?= h($gDefault) ?>"></div>
    <button type="submit" name="complete_group" value="1" class="btn btn-secondary btn-sm"
      onclick="var b=this.form.querySelectorAll('.att-box'),a=0,p=0,g=this.form.querySelector('[name=g_hours]'),hv=g&&g.value.trim()!==''?g.value.trim():'',none=hv!==''&&!(Number(hv.replace(',','.'))>0);b.forEach(function(x){if(x.checked){a++;if(x.getAttribute('data-bill')==='1')p++;}});return confirm('Record attendance for this group session? '+a+' of '+b.length+' clients attended and will be marked Completed'+(none?', with no hours logged. ':', and '+p+' of them will have '+(hv!==''?hv+' hours':'hours')+' logged to their training track and billed. ')+(b.length-a)+' will be recorded as No Show, with no hours logged.');">Record attendance and complete<span class="visually-hidden"> — group session of <?= $gWhen ?>, <?= count($members) ?> clients</span></button>
  </form>
  <?php else: ?>
  <ul class="muted" aria-label="Clients, and the training track each one's hours log to">
    <?php foreach ($members as $m): ?><li><?= h($m['client_name']) ?> — <?= $m['track_title'] ? h($m['track_title']) : 'no training track, so no hours will be logged' ?></li><?php endforeach; ?>
  </ul>
  <p class="hint">It can be completed once it has started, with who attended. To call it off, cancel each client's session in the upcoming list.</p><?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($overdue): ?>
<h2 id="overdue-h">Ended sessions awaiting an outcome (<?= count($overdue) ?>)</h2>
<p class="muted">These sessions have ended but are still marked Scheduled. Record each one as Completed, Cancelled, or No Show — completing a session linked to a track logs its hours. When hours for that day are already logged on the track (by voice, in the app or on the track page), choose "Completed — hours already logged" so they are not logged twice.</p>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="overdue-h">
<table>
  <caption class="visually-hidden">Ended sessions still awaiting an outcome</caption>
  <thead><tr><th scope="col">When</th><th scope="col">Client</th><th scope="col">Instructor</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($overdue as $s) session_row($s, csrf_token()); ?>
  </tbody>
</table>
</div>
<?php $bulkable = array_values(array_filter($overdue, fn($s) => empty($s['group_uid']))); if (count($bulkable) > 1): ?>
<details><summary>Record several as no-show or cancelled</summary>
  <form method="post" action="schedule.php" class="stacked" onsubmit="return confirm('Record the chosen sessions? Each client\'s counselor is emailed about a no-show.');"><?= csrf_field() ?>
    <fieldset><legend>Sessions</legend>
    <?php foreach ($bulkable as $s): ?><label><input type="checkbox" name="bulk_ids[]" value="<?= (int)$s['id'] ?>"> <?= h($s['client_name']) ?>, <?= h(date('D M j, g:i A', strtotime($s['start_at']))) ?></label><?php endforeach; ?>
    </fieldset>
    <fieldset><legend>Record them as</legend>
      <label><input type="radio" name="bulk_to" value="No Show" checked> No show</label>
      <label><input type="radio" name="bulk_to" value="Cancelled"> Cancelled</label>
    </fieldset>
    <button type="submit" name="bulk_outcome" value="1" class="btn btn-secondary">Record the chosen sessions</button>
  </form>
</details>
<?php endif; ?>
<?php endif; ?>

<h2 id="upcoming-h">Upcoming sessions (<?= count($upcoming) < $upcomingTotal ? 'showing the next ' . count($upcoming) . ' of ' . $upcomingTotal : $upcomingTotal ?>)</h2>
<?php if ($upClients): ?>
<form method="get" action="schedule.php#upcoming-h" class="page-actions" role="search" aria-label="Filter upcoming sessions">
  <label for="up_client">Client</label>
  <select id="up_client" name="client">
    <option value="">All clients</option>
    <?php foreach ($upClients as $uc): ?><option value="<?= (int)$uc['id'] ?>" <?= $upClient === (int)$uc['id'] ? 'selected' : '' ?>><?= h($uc['name']) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary btn-sm">Show upcoming sessions</button>
</form>
<?php endif; ?>
<?php if (count($upcoming) < $upcomingTotal): ?><p class="muted">Only the next 100 upcoming sessions are listed. Choose a client above to list all of that client's upcoming sessions.</p><?php endif; ?>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="upcoming-h">
<table>
  <caption class="visually-hidden">Upcoming scheduled sessions</caption>
  <thead><tr><th scope="col">When</th><th scope="col">Client</th><th scope="col">Instructor</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$upcoming): ?><tr><td colspan="6" class="muted">Nothing scheduled<?= $upClient > 0 ? ' for that client' : '' ?>.</td></tr><?php endif; ?>
  <?php foreach ($upcoming as $s) session_row($s, csrf_token()); ?>
  </tbody>
</table>
</div>

<h2 id="past-h">Closed sessions (latest 30)</h2>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="past-h">
<table>
  <caption class="visually-hidden">Closed sessions: completed, cancelled, or no show</caption>
  <thead><tr><th scope="col">When</th><th scope="col">Client</th><th scope="col">Instructor</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$recent): ?><tr><td colspan="6" class="muted">No closed sessions yet.</td></tr><?php endif; ?>
  <?php foreach ($recent as $s) session_row($s, csrf_token()); ?>
  </tbody>
</table>
</div>
<?php // Arriving at a session row (#s-<id>, from the action center or a portal request)
// lands on that row's first button, so a screen-reader user starts at the action
// rather than the top of the page; a closed row has no button and keeps its heading. ?>
<script>(function(){if(!/^#s-\d+$/.test(location.hash))return;var h=document.getElementById(location.hash.slice(1)),r=h&&h.closest('tr'),b=r&&r.querySelector('button');if(b)b.focus();})();</script>
<?php page_footer();
