<?php
require __DIR__ . '/inc/bootstrap.php';
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

/* ---- POST: schedule a GROUP session (one time slot, many clients) ---- */
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
    if ($errors) {
        keep_input();
        // The ticked clients are an array, which keep_input() does not keep.
        $_SESSION['old_input']['g_clients'] = implode(',', $clientIds);
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
        foreach ($clientIds as $cid) {
            $ins->execute([$cid, $trackIds[$cid], (int)$staff['id'], date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs), $location ?: null, $notes ?: null, $groupUid, $isRemote ? 1 : 0, $meetingUrl]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    $ics = build_ics('cms-group-' . $groupUid . '@' . (parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost'),
        ($isRemote ? 'Remote group session (' : 'Group session (') . count($clientIds) . ' clients)',
        "Group training session with " . count($clientIds) . " clients." . ($notes !== '' ? "\nNotes: {$notes}" : ''),
        $location, $startTs, $endTs, cfg('mail_from'), $staff['email'], [], $meetingUrl ?? '');
    $sent = send_app_mail_attach($staff['email'],
        'Group session scheduled — ' . date('D, M j g:i A T', $startTs),
        "A group session with " . count($clientIds) . " clients has been scheduled.\nOpen the attached invite to add it to your calendar.\n",
        [['name' => 'invite.ics', 'mime' => 'text/calendar; method=REQUEST', 'bytes' => $ics]]);
    audit($user['id'], 'session.schedule', 'group', null, 'group of ' . count($clientIds));
    $noTrack = [];
    foreach ($clientIds as $cid) if ($trackIds[$cid] === null) $noTrack[] = $members[$cid]['name'];
    flash('Group session scheduled for ' . count($clientIds) . ' clients. Completing it logs hours to each client\'s training track.'
        . ($noTrack ? ' ' . implode(', ', $noTrack) . (count($noTrack) === 1 ? ' has' : ' have') . ' no active training track, so no hours will be logged for them.' : '')
        . ($sent ? ' A calendar invite was emailed to ' . $staff['name'] . '.' : ' (Calendar invite email could not be sent to ' . $staff['name'] . '.)'));
    redirect('schedule.php');
}

/* ---- POST: complete an entire group ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_group'])) {
    $uid = preg_replace('/[^a-f0-9]/', '', is_string($_POST['group_uid'] ?? '') ? $_POST['group_uid'] : '');
    // Scoped staff complete only their own clients' rows of the group, instead of
    // stopping part-way at the first client that belongs to someone else.
    $rows = db()->prepare("SELECT s.*, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
        WHERE s.group_uid = ? AND s.status = 'Scheduled'" . (caseload_scoped() ? ' AND c.assigned_to = ?' : ''));
    $rows->execute(caseload_scoped() ? [$uid, (int)$user['id']] : [$uid]);
    $done = 0;
    $logged = 0;
    $notLogged = [];
    $refused = []; // left Scheduled: their track has no hourly rate yet
    $refusedWhy = []; // auto_log_session()'s own explanation, each once
    $notStarted = 0; // a session that has not begun cannot be completed (or billed)
    $claim = db()->prepare('UPDATE scheduled_sessions SET status = "Completed" WHERE id = ? AND status = "Scheduled"');
    $setLog = db()->prepare('UPDATE scheduled_sessions SET work_log_id = ? WHERE id = ?');
    foreach ($rows->fetchAll() as $sess) {
        schedule_scope_check((int)$sess['client_id'], $user);
        if (strtotime($sess['start_at']) > time()) { $notStarted++; continue; }
        $hours = $sess['track_id'] ? schedule_hours($sess) : 0.0;
        // Per session, the claim (double-submit guard) and its work log commit
        // together, so an error part-way can't leave it Completed with no hours.
        db()->beginTransaction();
        try {
            $claim->execute([(int)$sess['id']]);
            if ($claim->rowCount() !== 1) { db()->rollBack(); continue; }
            if ($hours > 0) {
                $logId = auto_log_session((int)$sess['track_id'], date('Y-m-d', strtotime($sess['start_at'])), $hours,
                    'Group session' . ($sess['notes'] ? ': ' . $sess['notes'] : ''), $user['id']);
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
            if ($done > 0) audit($user['id'], 'session.complete', 'group', null, "group {$uid}, {$done} clients, hours logged for {$logged} (stopped by an error)");
            throw $e;
        }
        $done++;
        if ($hours > 0) $logged++;
        else $notLogged[] = $sess['client_name'] . ($sess['track_id'] ? ' (session too short to log hours)' : ' (no training track linked)');
    }
    $refusedMsg = $refused ? ' Not completed (still Scheduled): ' . implode(', ', $refused) . '. '
        . implode(' ', array_keys($refusedWhy)) . ' Then complete '
        . (count($refused) === 1 ? 'that session' : 'those sessions') . '.' : '';
    if ($done === 0) {
        flash($refused ? 'Nothing was completed.' . $refusedMsg
            : ($notStarted ? 'Nothing was completed: that group session has not started yet. Complete it once it has taken place, or cancel each client\'s session if it will not happen.'
            : 'Nothing was completed: no session in that group is still waiting for an outcome.'), 'error');
        redirect('schedule.php');
    }
    audit($user['id'], 'session.complete', 'group', null, "group {$uid}, {$done} clients, hours logged for {$logged}"
        . ($refused ? ', ' . count($refused) . ' left Scheduled (track has no hourly rate)' : ''));
    flash("Group completed for {$done} client(s) — hours logged for {$logged}."
        . ($notLogged ? ' No hours were logged for ' . implode(', ', $notLogged) . '.' : '') . $refusedMsg, $refused ? 'error' : 'success');
    redirect('schedule.php');
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
    $repeatIn = is_string($_POST['repeat_weeks'] ?? null) ? trim($_POST['repeat_weeks']) : '';
    $repeatWeeks = $repeatIn === '' ? 1 : (ctype_digit($repeatIn) ? (int)$repeatIn : 0);
    if ($repeatWeeks < 1 || $repeatWeeks > 26) $errors['repeat_weeks'] = 'Enter a number of weeks from 1 to 26 (1 is a single session).';
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
    if ($errors) {
        keep_input();
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
    $occurrences = [];
    // The whole series is saved or none of it: a failure part-way through must
    // not leave a partial series that a retry would then duplicate.
    db()->beginTransaction();
    try {
        for ($w = 0; $w < $repeatWeeks; $w++) {
            // "+N week" keeps the local start time across daylight-saving changes
            $s0 = $w === 0 ? $startTs : strtotime("+{$w} week", $startTs);
            $e0 = $w === 0 ? $endTs : strtotime("+{$w} week", $endTs);
            $occurrences[] = [$s0, $e0];
            $ins->execute([$clientId, $trackId, $staffId, date('Y-m-d H:i:s', $s0), date('Y-m-d H:i:s', $e0), $location ?: null, $notes ?: null, $isRemote ? 1 : 0, $meetingUrl]);
            if ($w === 0) $sessionId = (int)db()->lastInsertId();
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($user['id'], 'session.schedule', 'client', $clientId, $repeatWeeks > 1 ? "series of {$repeatWeeks} weekly sessions" : 'single session');

    // Calendar invite to the instructor (renders as an Outlook meeting request)
    $clientName = (string)$client['name'];
    $staff = $f['staff'];
    if ($staff) {
        $seriesNote = $repeatWeeks > 1 ? " (repeats weekly, {$repeatWeeks} sessions)" : '';
        $ics = build_ics(
            'cms-session-' . $sessionId . '@' . (parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost'),
            ($isRemote ? 'Remote AT Training: ' : 'AT Training: ') . $clientName,
            "Training session with {$clientName}.{$seriesNote}" . ($notes !== '' ? "\nNotes: {$notes}" : ''),
            $location, $startTs, $endTs,
            cfg('mail_from'), $staff['email'], $occurrences, $meetingUrl ?? ''
        );
        $sent = send_app_mail_attach($staff['email'],
            ($isRemote ? 'Remote session' : 'Session') . ($repeatWeeks > 1 ? ' series' : '') . ' scheduled: ' . $clientName . ' — ' . date('D, M j g:i A T', $startTs),
            "A " . ($isRemote ? 'remote ' : '') . "training session{$seriesNote} has been scheduled.\n\nClient: {$clientName}\nWhen: " . date('l, F j, Y g:i A T', $startTs)
            . ' to ' . date('g:i A T', $endTs)
            . ($isRemote ? "\nFormat: Remote / telepractice" . ($meetingUrl ? "\nJoin online: {$meetingUrl}" : '') : ($location !== '' ? "\nWhere: {$location}" : ''))
            . "\n\nOpen the attached invite to add it to your calendar" . ($repeatWeeks > 1 ? ' — it repeats weekly automatically.' : '.') . "\n",
            [['name' => 'invite.ics', 'mime' => 'text/calendar; method=REQUEST', 'bytes' => $ics]]);
        flash(($repeatWeeks > 1 ? "Series of {$repeatWeeks} weekly sessions scheduled." : 'Session scheduled.')
            . ($sent ? ' A calendar invite was emailed to ' . $staff['name'] . '.' : ' (Calendar invite email could not be sent.)'));
    }
    redirect('schedule.php');
}

/* ---- POST: update session status (Completed logs hours to the track) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $sid = (int)($_POST['session_id'] ?? 0);
    $to = in_array($_POST['to'] ?? '', array_diff(SESSION_STATUSES, ['Scheduled']), true) ? $_POST['to'] : null;
    $st = db()->prepare('SELECT * FROM scheduled_sessions WHERE id = ?');
    $st->execute([$sid]);
    $sess = $st->fetch();
    if ($sess && $to === null) {
        flash('Choose an outcome for the session: Completed, Cancelled or No Show.', 'error');
        redirect('schedule.php#s-' . $sid);
    }
    if ($sess && $to && $sess['status'] === 'Scheduled') {
        schedule_scope_check((int)$sess['client_id'], $user);
        // A session that has not started cannot be completed: it would log billable
        // hours for a session that never took place (one in progress can).
        if ($to === 'Completed' && strtotime($sess['start_at']) > time()) {
            flash('That session has not started yet (' . fmt_datetime($sess['start_at']) . '), so it cannot be marked Completed. Nothing was changed.', 'error');
            redirect('schedule.php#s-' . $sid);
        }
        $hours = ($to === 'Completed' && $sess['track_id']) ? schedule_hours($sess) : 0.0;
        // One transaction, as in the app: the claim (double-submit guard) and the
        // hour logging succeed or fail together, so an error can't strand a
        // Completed session with no work log.
        db()->beginTransaction();
        try {
            $claim = db()->prepare('UPDATE scheduled_sessions SET status = ? WHERE id = ? AND status = "Scheduled"');
            $claim->execute([$to, $sid]);
            $claimed = $claim->rowCount() === 1;
            if ($claimed && $hours > 0) {
                $logId = auto_log_session((int)$sess['track_id'], date('Y-m-d', strtotime($sess['start_at'])), $hours,
                    'Training session' . ($sess['notes'] ? ': ' . $sess['notes'] : '') . ($sess['location'] ? ' (' . $sess['location'] . ')' : ''),
                    $user['id']);
                db()->prepare('UPDATE scheduled_sessions SET work_log_id = ? WHERE id = ?')
                    ->execute([$logId, $sid]);
            }
            db()->commit();
        } catch (DomainException $e) {
            // auto_log_session() refuses a track with no hourly rate: nothing is saved,
            // the session stays Scheduled, and its own message says what to fix.
            if (db()->inTransaction()) db()->rollBack();
            flash($e->getMessage(), 'error');
            redirect('schedule.php');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        if ($claimed) {
            $act = ['Completed' => 'session.complete', 'Cancelled' => 'session.cancel', 'No Show' => 'session.no_show'][$to] ?? 'session.status';
            audit($user['id'], $act, 'session', $sid, 'client #' . (int)$sess['client_id'] . ($hours > 0 ? ', ' . fmt_hours($hours) . ' logged' : ''));
            if ($hours > 0) {
                flash('Session completed — ' . fmt_hours($hours) . ' logged to the training track.');
            } elseif ($to === 'Completed') {
                flash('Session marked Completed' . ($sess['track_id'] ? ' (it was too short to log any hours).' : ' (no track linked, so no hours were logged).'));
            } else {
                flash('Session marked ' . $to . '.');
            }
        } else {
            flash('That session was already closed, so nothing changed.', 'error');
        }
    } elseif ($sess && $to) {
        flash('That session was already closed, so nothing changed.', 'error');
    }
    redirect('schedule.php');
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
$uq = db()->prepare("SELECT s.*, c.name AS client_name, u.name AS staff_name, t.title AS track_title
    FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
    WHERE s.status = 'Scheduled' AND s.end_at >= NOW()" . $upAnd . " ORDER BY s.start_at LIMIT 100");
$uq->execute($upParams);
$upcoming = $uq->fetchAll();
// Every group still awaiting an outcome, whatever the list limit or filter, so a
// group cut off at the end of the upcoming list still gets its card.
$gq = db()->prepare("SELECT s.*, c.name AS client_name, u.name AS staff_name, t.title AS track_title
    FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
    WHERE s.status = 'Scheduled' AND s.group_uid IS NOT NULL" . $scopeAnd . " ORDER BY s.start_at, s.id");
$gq->execute($scopeParams);
$groupRows = $gq->fetchAll();

// Sessions whose time has passed but that are still 'Scheduled'. Every one stays
// listed (no limit) until an outcome is recorded, so a session can never scroll
// out of reach with its hours unlogged.
$oq = db()->prepare("SELECT s.*, c.name AS client_name, u.name AS staff_name, t.title AS track_title
    FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
    WHERE s.status = 'Scheduled' AND s.end_at < NOW()" . $scopeAnd . " ORDER BY s.start_at");
$oq->execute($scopeParams);
$overdue = $oq->fetchAll();

$rq = db()->prepare("SELECT s.*, c.name AS client_name, u.name AS staff_name, t.title AS track_title
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
    $completeMsg = !$s['track_id'] ? 'Mark completed? No training track is linked, so no hours will be logged.'
        : ($hrs > 0 ? 'Mark completed? This logs ' . fmt_hours($hrs) . ' to the training track' . ($s['track_title'] ? ' "' . $s['track_title'] . '"' : '') . '.'
            : 'Mark completed? The session is too short to log any hours to the training track.'); ?>
    <tr>
      <th scope="row" id="s-<?= (int)$s['id'] ?>" tabindex="-1"><?= fmt_datetime($s['start_at']) ?><br><span class="muted">to <?= date('g:i A', strtotime($s['end_at'])) ?></span></th>
      <td><a href="client.php?id=<?= (int)$s['client_id'] ?>"><?= h($s['client_name']) ?></a>
          <?= $s['track_title'] ? '<br><span class="muted">' . h($s['track_title']) . '</span>' : '' ?></td>
      <td><?= h($s['staff_name'] ?? '—') ?></td>
      <td><?php if (!empty($s['is_remote'])): ?><span class="badge badge-active">Remote</span><?= !empty($s['meeting_url']) ? ' <a href="' . h($s['meeting_url']) . '" target="_blank" rel="noopener">Join<span class="visually-hidden"> ' . $which . ' (opens in a new tab)</span></a>' : '' ?><?php else: ?><?= h($s['location'] ?: '—') ?><?php endif; ?></td>
      <td><?= status_badge($s['status']) ?></td>
      <td>
        <?php if ($s['status'] === 'Scheduled'): ?>
        <form method="post" action="schedule.php" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
          <label class="visually-hidden" for="st-<?= (int)$s['id'] ?>">Update status for <?= $which ?></label>
          <?php // A session that has not started yet cannot be completed, and nothing is
          // preselected for it, so Save never records an outcome by default. ?>
          <select id="st-<?= (int)$s['id'] ?>" name="to">
            <?php if ($started): ?><option value="Completed">Completed<?= $s['track_id'] ? ' (log hours)' : '' ?></option><?php else: ?><option value="">— Choose an outcome —</option><?php endif; ?>
            <option value="Cancelled">Cancelled</option>
            <option value="No Show">No Show</option>
          </select>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm"
            onclick="var s=document.getElementById('st-<?= (int)$s['id'] ?>').value;return s===''||confirm(s==='Completed'?<?= h(json_encode($completeMsg, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>:'Record this session as '+s+'? This cannot be changed back. Choose OK to record it, or Cancel to leave the session as it is.');">Save<span class="visually-hidden"> status for <?= $which ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
<?php }
?>
<h1>Session schedule</h1>
<div class="page-actions"><a class="btn btn-secondary" href="capacity.php">Capacity planning</a>
  <a class="btn btn-secondary" href="access_bookings.php">Interpreter, CART (real-time captioning) and transport bookings</a></div>

<section class="card" aria-labelledby="add-h">
  <h2 id="add-h">Schedule a session</h2>
  <p class="hint">Fields marked with an asterisk (*) are required.</p>
  <form method="post" action="schedule.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['client_id' => 'Client', 'track_id' => 'Training track', 'staff_id' => 'Instructor', 'date' => 'Date',
        'time' => 'Start time', 'duration' => 'Duration (hours)', 'repeat_weeks' => 'Repeat weekly', 'location' => 'Location',
        'notes' => 'Notes', 'meeting_url' => 'Meeting link']) ?>
    <div class="grid grid-2">
      <div class="field">
        <label for="client_id">Client <span aria-hidden="true">*</span></label>
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
      <div class="field"><label for="date">Date <span aria-hidden="true">*</span></label>
        <input type="date" id="date" name="date" required min="2000-01-01" max="<?= (int)date('Y') + 5 ?>-12-31" value="<?= h(old_in('date', date('Y-m-d'))) ?>" <?= field_aria('date') ?>><?= field_error('date') ?></div>
      <div class="field"><label for="time">Start time <span aria-hidden="true">*</span></label>
        <input type="time" id="time" name="time" required value="<?= h(old_in('time', '10:00')) ?>" <?= field_aria('time') ?>><?= field_error('time') ?></div>
      <div class="field"><label for="duration">Duration (hours) <span aria-hidden="true">*</span></label>
        <input type="number" id="duration" name="duration" step="0.25" min="0.25" max="12" required value="<?= h(old_in('duration', '1.5')) ?>" <?= field_aria('duration') ?>><?= field_error('duration') ?></div>
      <div class="field"><label for="repeat_weeks">Repeat weekly <span class="hint">1 = single session; e.g., 8 = every week for 8 weeks</span></label>
        <input type="number" id="repeat_weeks" name="repeat_weeks" min="1" max="26" value="<?= h(old_in('repeat_weeks', '1')) ?>" <?= field_aria('repeat_weeks') ?>><?= field_error('repeat_weeks') ?></div>
      <div class="field"><label for="location">Location</label>
        <input type="text" id="location" name="location" maxlength="190" value="<?= h(old_in('location')) ?>" <?= field_aria('location') ?>><?= field_error('location') ?></div>
      <div class="field"><label for="notes">Notes</label>
        <input type="text" id="notes" name="notes" maxlength="255" value="<?= h(old_in('notes')) ?>" <?= field_aria('notes') ?>><?= field_error('notes') ?></div>
    </div>
    <div class="field"><label><input type="checkbox" id="is_remote" name="is_remote" value="1" <?= old_in('is_remote') ? 'checked' : '' ?>> Remote / telepractice session</label></div>
    <div class="field" id="meeting_url_wrap">
      <label for="meeting_url">Meeting link <span class="hint">the video/phone link clients and you will join (https://…); tick Remote above to use it</span></label>
      <input type="url" id="meeting_url" name="meeting_url" maxlength="500" placeholder="https://" value="<?= h(old_in('meeting_url')) ?>" <?= field_aria('meeting_url') ?>><?= field_error('meeting_url') ?></div>
    <button type="submit" name="add_session" value="1" class="btn btn-primary"
      onclick="var w=parseInt(document.getElementById('repeat_weeks').value||'1',10);return w<=1||confirm('Schedule '+w+' weekly sessions? Each must be cancelled individually if plans change.');">Schedule &amp; send calendar invite</button>
  </form>
</section>

<section class="card" aria-labelledby="group-h">
  <h2 id="group-h">Schedule a group session</h2>
  <p class="muted">One time slot, multiple clients (for example, a Pre-Employment Transition Services (Pre-ETS) workshop). Each client's hours log to their own training track on completion; when a client has more than one active track, choose which one.</p>
  <p class="hint">Fields marked with an asterisk (*) are required.</p>
  <?php // The group form's fields carry a g_ prefix so neither form refills the other after an error.
  // Ticks come back after a validation error (g_clients) and after a lapsed
  // session and sign-in (the posted group_clients[] list).
  $keptGroup = array_map('intval', array_filter(array_merge(explode(',', old_in('g_clients')), old_in_list('group_clients'))));
  $gLabels = ['g_clients' => 'Clients', 'g_staff_id' => 'Instructor', 'g_date' => 'Date', 'g_time' => 'Start', 'g_duration' => 'Duration (hours)',
      'g_location' => 'Location', 'g_notes' => 'Notes', 'g_meeting_url' => 'Meeting link'];
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
      <div class="field"><label for="g_staff_id">Instructor</label>
        <select id="g_staff_id" name="g_staff_id" <?= field_aria('g_staff_id') ?>><?php $pickStaff = old_in('g_staff_id', (string)$user['id']);
          foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $pickStaff ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?></select><?= field_error('g_staff_id') ?></div>
      <div class="field"><label for="g_date">Date <span aria-hidden="true">*</span></label>
        <input type="date" id="g_date" name="g_date" required min="2000-01-01" max="<?= (int)date('Y') + 5 ?>-12-31" value="<?= h(old_in('g_date', date('Y-m-d'))) ?>" <?= field_aria('g_date') ?>><?= field_error('g_date') ?></div>
      <div class="field"><label for="g_time">Start <span aria-hidden="true">*</span></label>
        <input type="time" id="g_time" name="g_time" required value="<?= h(old_in('g_time', '10:00')) ?>" <?= field_aria('g_time') ?>><?= field_error('g_time') ?></div>
      <div class="field"><label for="g_duration">Duration (hours) <span aria-hidden="true">*</span></label>
        <input type="number" id="g_duration" name="g_duration" step="0.25" min="0.25" max="12" required value="<?= h(old_in('g_duration', '1.5')) ?>" <?= field_aria('g_duration') ?>><?= field_error('g_duration') ?></div>
      <div class="field"><label for="g_location">Location</label>
        <input type="text" id="g_location" name="g_location" maxlength="190" value="<?= h(old_in('g_location')) ?>" <?= field_aria('g_location') ?>><?= field_error('g_location') ?></div>
      <div class="field"><label for="g_notes">Notes</label>
        <input type="text" id="g_notes" name="g_notes" maxlength="255" value="<?= h(old_in('g_notes')) ?>" <?= field_aria('g_notes') ?>><?= field_error('g_notes') ?></div>
    </div>
    <div class="field"><label><input type="checkbox" id="g_is_remote" name="g_is_remote" value="1" <?= old_in('g_is_remote') ? 'checked' : '' ?>> Remote / telepractice session</label></div>
    <div class="field"><label for="g_meeting_url">Meeting link <span class="hint">https://…; tick Remote above to use it</span></label>
      <input type="url" id="g_meeting_url" name="g_meeting_url" maxlength="500" placeholder="https://" value="<?= h(old_in('g_meeting_url')) ?>" <?= field_aria('g_meeting_url') ?>><?= field_error('g_meeting_url') ?></div>
    <button type="submit" name="add_group_session" value="1" class="btn btn-primary">Schedule group session</button>
  </form>
</section>

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
  <ul class="muted" aria-label="Clients, and the training track each one's hours log to">
    <?php foreach ($members as $m): ?><li><?= h($m['client_name']) ?> — <?= $m['track_title'] ? h($m['track_title']) : 'no training track, so no hours will be logged' ?></li><?php endforeach; ?>
  </ul>
  <?php if (strtotime($members[0]['start_at']) <= time()): // a group that has not started cannot be completed ?>
  <form method="post" action="schedule.php" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="group_uid" value="<?= h($uid) ?>">
    <button type="submit" name="complete_group" value="1" class="btn btn-secondary btn-sm"
      onclick="return confirm('Mark the whole group completed and log hours for every client?');">Complete whole group<span class="visually-hidden"> — session of <?= fmt_datetime($members[0]['start_at']) ?>, <?= count($members) ?> clients</span></button>
  </form>
  <?php else: ?><p class="hint">It can be completed once it has started. To call it off, cancel each client's session in the upcoming list.</p><?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($overdue): ?>
<h2 id="overdue-h">Ended sessions awaiting an outcome (<?= count($overdue) ?>)</h2>
<p class="muted">These sessions have ended but are still marked Scheduled. Record each one as Completed, Cancelled, or No Show — completing a session linked to a track logs its hours.</p>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="overdue-h">
<table>
  <caption class="visually-hidden">Ended sessions still awaiting an outcome</caption>
  <thead><tr><th scope="col">When</th><th scope="col">Client</th><th scope="col">Instructor</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($overdue as $s) session_row($s, csrf_token()); ?>
  </tbody>
</table>
</div>
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
<?php page_footer();
