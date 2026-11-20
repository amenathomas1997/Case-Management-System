<?php
declare(strict_types=1);
// Group sessions (4.35.0): booking one time slot for several clients, and recording who
// attended. Moved from schedule.php unchanged, so the website's Schedule page and the app's
// schedule_group, group_session and complete_group (inc/api_435.php) follow the same rules,
// say the same sentences and audit the same way. Each caller keeps its own scope check and
// its own way of answering (a flash and redirect, or a JSON reply).
/** Hours a session logs on completion, to the quarter hour. */
function schedule_hours(array $sess): float {
    return round((strtotime($sess['end_at']) - strtotime($sess['start_at'])) / 3600 * 4) / 4;
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

/**
 * Book a group session (schedule.php's add_group_session, moved). $clientIds are the ticked
 * clients (already scope-checked by the caller), $trackPicks [client id => track id] the track
 * chosen for a client with more than one, $f what session_booking_input() returned and
 * $fieldErrors its errors with the prefix taken off. $approvalHash is the hash of the warnings
 * the user approved (null when nothing was approved). $actor needs id and name.
 *
 * Returns errors (unprefixed keys: clients, staff_id, date, time, duration, location, notes,
 * meeting_url, track_<client id>, authorization_ack), the warnings to approve with their hash,
 * whether a track's authorization raised one, duplicate (the same group already booked: nothing
 * added), and on success the new session ids, the group's uid, whether the instructor's invite
 * was sent, the clients with no track and the message the website shows.
 */
function session_group_book(array $clientIds, array $trackPicks, array $f, ?string $approvalHash, array $actor, string $channel, array $fieldErrors = []): array {
    $res = ['errors' => [], 'warnings' => [], 'hash' => null, 'authorization' => false, 'duplicate' => false, 'ids' => [], 'group_uid' => null,
        'message' => '', 'no_track' => [], 'invite_sent' => false];
    require_once __DIR__ . '/workflows.php'; // workflow_authorization()
    $approved = fn(array $lines): bool => $approvalHash !== null && hash_equals(schedule_auth_hash($lines), $approvalHash);
    $errors = $fieldErrors;
    $members = [];
    if ($clientIds) {
        $q = db()->prepare('SELECT id, name, assigned_to, status FROM clients WHERE id IN (' . implode(',', array_fill(0, count($clientIds), '?')) . ')');
        $q->execute($clientIds);
        foreach ($q->fetchAll() as $row) $members[(int)$row['id']] = $row;
    }
    if (count($members) !== count($clientIds)) {
        $errors['clients'] = 'One of the chosen clients is no longer on file. Review the list and choose again.';
    } elseif (count($clientIds) < 2) {
        $errors['clients'] = 'Choose at least two clients.';
    } else {
        // Only clients a session can be booked for (the form offers only those; a post is checked too).
        $closed = array_values(array_map(fn($m) => (string)$m['name'], array_filter($members, fn($m) => !in_array((string)$m['status'], schedule_bookable_statuses(), true))));
        if ($closed) {
            $errors['clients'] = implode(', ', $closed) . (count($closed) === 1 ? ' is on the waitlist or closed, so a session cannot be booked. Change the client\'s status first.'
                : ' are on the waitlist or closed, so a session cannot be booked for them. Change their status first.');
        }
    }
    if ($f['staff'] && $f['staff_scoped']) {
        $outside = array_filter($members, fn($m) => (int)$m['assigned_to'] !== (int)$f['staff']['id']);
        if ($outside) {
            $errors['staff_id'] = $f['staff']['name'] . ' only sees clients on their own caseload, and these clients are not assigned to them: '
                . implode(', ', array_column($outside, 'name')) . '. Choose another instructor, or untick those clients.';
        }
    }
    // Each client's hours log to the track picked for them on the form (offered
    // when they have more than one active track), otherwise to their newest one.
    $newest = db()->prepare("SELECT id FROM training_tracks WHERE client_id = ? AND status = 'Active' ORDER BY created_at DESC, id DESC LIMIT 1");
    $ownTrack = db()->prepare("SELECT 1 FROM training_tracks WHERE id = ? AND client_id = ? AND status = 'Active'");
    $trackIds = [];
    foreach ($members as $cid => $m) {
        $picked = (int)($trackPicks[$cid] ?? 0);
        if ($picked > 0) {
            $ownTrack->execute([$picked, $cid]);
            if (!$ownTrack->fetchColumn()) {
                $errors['track_' . $cid] = 'Choose one of ' . $m['name'] . '\'s active training tracks.';
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
    $gAuthorization = (bool)$authLines;
    $res['authorization'] = $gAuthorization;
    // The instructor's Outlook calendar (4.20.0), approved with the rest.
    if (!$errors && $f['staff'] && function_exists('calendar_busy_overlaps') && calendar_busy_overlaps((int)$f['staff']['id'], $f['start'], $f['end'])) {
        $authLines[] = $f['staff']['name'] . '\'s calendar shows them busy then.';
    }
    // The instructor's own sessions at that time (4.29.0), approved with the rest; read again
    // under the instructor's lock below, so a booking made meanwhile is seen.
    $baseLines = $authLines;
    if (!$errors && $f['staff']) $authLines = array_merge($baseLines, session_instructor_overlaps((int)$f['staff']['id'], (string)$f['staff']['name'], [[$f['start'], $f['end']]], $clientIds));
    if ($authLines && !$approved($authLines)) {
        $errors['authorization_ack'] = schedule_review_message($gAuthorization, $authLines, true);
        $res['warnings'] = array_values($authLines);
        $res['hash'] = schedule_auth_hash($authLines);
    }
    if ($errors) { $res['errors'] = $errors; return $res; }
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
        // The instructor first, as every booking path does (4.20.0), then the clients.
        session_lock_instructor((int)$staff['id']);
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
                $res['duplicate'] = true;
                $res['message'] = 'That group session is already on the schedule (' . $staff['name'] . ', ' . date('D, M j g:i A', $startTs) . '), so it was not added again.';
                return $res;
            }
            $res['errors'] = ['clients' => 'Already scheduled with ' . $staff['name'] . ' at that time: ' . implode(', ', $booked)
                . '. Untick ' . (count($booked) === 1 ? 'that client' : 'those clients') . ', or choose another time. Nothing was saved.'];
            return $res;
        }
        // Under the lock: the instructor's sessions now. A session booked since the form was
        // checked brings the approval box back with it, and nothing is saved.
        $lockedLines = array_merge($baseLines, session_instructor_overlaps((int)$staff['id'], (string)$staff['name'], [[$startTs, $endTs]], $clientIds));
        if ($lockedLines && !$approved($lockedLines)) {
            db()->rollBack();
            $res['errors'] = ['authorization_ack' => schedule_review_message($gAuthorization, $lockedLines, true)];
            $res['warnings'] = array_values($lockedLines);
            $res['hash'] = schedule_auth_hash($lockedLines);
            return $res;
        }
        $authLines = $lockedLines;
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
    audit($actor['id'], 'session.schedule', 'group', null, 'group of ' . count($clientIds)
        . ($authLines ? ' — authorization exception approved: ' . implode(' ', $authLines) : '') . ($channel !== 'website' ? ' (' . $channel . ')' : ''));
    $noTrack = [];
    foreach ($clientIds as $cid) if ($trackIds[$cid] === null) $noTrack[] = $members[$cid]['name'];
    $res['ids'] = $groupSessionIds;
    $res['group_uid'] = $groupUid;
    $res['no_track'] = $noTrack;
    $res['invite_sent'] = (bool)$sent;
    $res['message'] = session_group_book_message(count($clientIds), $noTrack, (bool)$sent, (string)$staff['name']);
    return $res;
}

/** What is said once a group is booked (the website's flash; also an app's repeated request). */
function session_group_book_message(int $count, array $noTrack, bool $sent, string $staffName): string {
    return 'Group session scheduled for ' . $count . ' clients. Completing it logs hours to each client\'s training track.'
        . ($noTrack ? ' ' . implode(', ', $noTrack) . (count($noTrack) === 1 ? ' has' : ' have') . ' no active training track, so no hours will be logged for them.' : '')
        . ($sent ? ' A calendar invite was emailed to ' . $staffName . '.' : ' (Calendar invite email could not be sent to ' . $staffName . '.)');
}

/** The hours typed for each client who attended (3.0.32): null when blank (each client's own
 *  scheduled length), false when out of range, otherwise the number (under 0.01 is 0). */
function session_group_hours_input(string $raw): float|null|false {
    $raw = trim(str_replace(',', '.', $raw));
    $h = $raw === '' ? null : (is_numeric($raw) ? round((float)$raw, 2) : -1.0);
    if ($h !== null && ($h < 0 || $h > 24)) return false;
    if ($h !== null && $h < 0.01) $h = 0.0;
    return $h;
}
/** The website's sentence for hours out of range on the group card. */
const SESSION_GROUP_HOURS_ERROR = 'Nothing was completed: enter the hours for each client who attended as a number from 0 to 24, such as 1.5.';

/** A group's sessions still waiting for an outcome, in name order (the card's members), with the
 *  track's title and the instructor's name; $scopeUser limits them to that user's caseload. */
function session_group_members(string $uid, ?int $scopeUser): array {
    $rows = db()->prepare("SELECT s.*, c.name AS client_name, c.time_zone AS client_time_zone, c.state AS client_state, c.office_id AS client_office_id,
            t.title AS track_title, u.name AS staff_name
        FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id LEFT JOIN training_tracks t ON t.id = s.track_id LEFT JOIN users u ON u.id = s.user_id
        WHERE s.group_uid = ? AND s.status = 'Scheduled'" . ($scopeUser !== null ? ' AND c.assigned_to = ?' : '') . ' ORDER BY c.name, s.id');
    $rows->execute($scopeUser !== null ? [$uid, $scopeUser] : [$uid]);
    return $rows->fetchAll();
}

/** The card's hours prefill: the first member on a track (in the card's order), blank when none is. */
function session_group_default_hours(array $members): string {
    usort($members, fn($a, $b) => [(string)$a['start_at'], (int)$a['id']] <=> [(string)$b['start_at'], (int)$b['id']]);
    $first = current(array_filter($members, fn($m) => !empty($m['track_id'])));
    return $first ? rtrim(rtrim(number_format(schedule_hours($first), 2, '.', ''), '0'), '.') : '';
}

/**
 * Record a group's attendance and complete it (schedule.php's complete_group, moved). $listed are
 * the session ids the card showed, $attended those ticked; anyone listed but not ticked is a No
 * Show. $hours is session_group_hours_input()'s number, or null for each client's own length.
 * Caseload-scoped staff complete only their own clients' sessions. Each attended session is
 * marked as waiting for its case note. 'error' is the website's "Nothing was completed" sentence
 * when nothing was recorded (then nobody is told and nothing is audited); 'message' otherwise.
 */
function session_group_complete(string $uid, array $listed, array $attended, ?float $hours, array $actor, string $channel): array {
    $gHours = $hours;
    $listed = array_map('intval', $listed);
    $attended = array_map('intval', $attended);
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
    $doneSess = []; // completed (attended): each one's case note is to be written (4.31.0)
    $startAt = null;
    $claim = db()->prepare('UPDATE scheduled_sessions SET status = "Completed" WHERE id = ? AND status = "Scheduled"');
    $claimNoShow = db()->prepare('UPDATE scheduled_sessions SET status = "No Show" WHERE id = ? AND status = "Scheduled"');
    $setLog = db()->prepare('UPDATE scheduled_sessions SET work_log_id = ? WHERE id = ?');
    // Scoped staff complete only their own clients' rows of the group, instead of
    // stopping part-way at the first client that belongs to someone else.
    foreach (session_group_members($uid, caseload_scoped() ? (int)$actor['id'] : null) as $sess) {
        // Only the clients the card showed are recorded.
        if (!in_array((int)$sess['id'], $listed, true)) continue;
        if (strtotime($sess['start_at']) > time()) { $notStarted++; continue; }
        $startAt = $sess['start_at'];
        if (!in_array((int)$sess['id'], $attended, true)) {
            $claimNoShow->execute([(int)$sess['id']]);
            if ($claimNoShow->rowCount() === 1) { $noShows[] = $sess; $noShowIds[] = (int)$sess['id']; }
            continue;
        }
        $h = $sess['track_id'] ? ($gHours ?? schedule_hours($sess)) : 0.0;
        // Hours already logged for that day (by voice, the app or the track page) are
        // not logged again: that client's session is left for a one-by-one outcome.
        if ($h > 0 && schedule_logged_candidates($sess)) { $alreadyLogged[] = $sess['client_name']; continue; }
        $authIssue = null;
        // Per session, the claim (double-submit guard) and its work log commit
        // together, so an error part-way can't leave it Completed with no hours.
        db()->beginTransaction();
        try {
            $claim->execute([(int)$sess['id']]);
            if ($claim->rowCount() !== 1) { db()->rollBack(); continue; }
            if ($h > 0) {
                // The hours are the instructor's who taught the session, whoever records it.
                $logId = auto_log_session((int)$sess['track_id'], date('Y-m-d', strtotime($sess['start_at'])), $h,
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
            if ($done > 0 || $noShows) audit($actor['id'], 'session.complete', 'group', null, "group {$uid}, {$done} attended, hours logged for {$logged}, "
                . count($noShows) . ' No Show (stopped by an error)');
            throw $e;
        }
        $done++;
        $doneSess[] = $sess;
        if ($h > 0) $logged++;
        else $notLogged[] = $sess['client_name'] . ($sess['track_id'] ? ' (session too short to log hours)' : ' (no training track linked)');
        if ($authIssue) $authNotes[] = $sess['client_name'] . ': ' . $authIssue;
    }
    $res = ['done' => $done, 'logged' => $logged, 'no_shows' => $noShows, 'not_logged' => $notLogged, 'refused' => $refused,
        'already_logged' => $alreadyLogged, 'not_started' => $notStarted, 'done_sessions' => $doneSess, 'message' => '', 'error' => null];
    $refusedMsg = $refused ? ' Not completed (still Scheduled): ' . implode(', ', $refused) . '. '
        . implode(' ', array_keys($refusedWhy)) . ' Then complete '
        . (count($refused) === 1 ? 'that session' : 'those sessions') . '.' : '';
    $loggedMsg = $alreadyLogged ? ' Not completed (still Scheduled), because hours are already logged on their training track for that day: '
        . implode(', ', $alreadyLogged) . '. Record ' . (count($alreadyLogged) === 1 ? 'that session' : 'each of those sessions')
        . ' under "Ended sessions awaiting an outcome": choose "Completed — hours already logged" if those are its hours, or "Completed — log hours as well" if it was a separate visit.' : '';
    if ($done === 0 && !$noShows) {
        $res['error'] = ($refused || $alreadyLogged) ? 'Nothing was completed.' . $refusedMsg . $loggedMsg
            : ($notStarted ? 'Nothing was completed: that group session has not started yet. Complete it once it has taken place, or cancel each client\'s session if it will not happen.'
            : 'Nothing was completed: no session in that group is still waiting for an outcome.');
        return $res;
    }
    if ($noShows) schedule_tell_instructors($noShows, 'No Show', $actor);
    foreach ($noShowIds as $nsId) counselor_noshow_notice($nsId); // 3.0.41: each counselor is emailed
    audit($actor['id'], 'session.complete', 'group', null, "group {$uid}, {$done} attended, hours logged for {$logged}"
        . ($noShows ? ', No Show: client #' . implode(', #', array_map(fn($s) => (int)$s['client_id'], $noShows)) : '')
        . ($refused ? ', ' . count($refused) . ' left Scheduled (track has no hourly rate)' : '')
        . ($alreadyLogged ? ', ' . count($alreadyLogged) . ' left Scheduled (hours already logged that day)' : '')
        . ($channel !== 'website' ? ' (' . $channel . ')' : ''));
    $res['message'] = 'Group session of ' . fmt_datetime($startAt) . ': '
        . ($done > 0 ? "completed for {$done} client(s) — hours logged for {$logged}." : 'nobody was recorded as attending.')
        . ($noShows ? ' Recorded as No Show, with no hours logged: ' . implode(', ', array_column($noShows, 'client_name')) . '.' : '')
        . ($notLogged ? ' No hours were logged for ' . implode(', ', $notLogged) . '.' : '')
        . ($authNotes ? ' ' . implode(' ', $authNotes) : '') . $refusedMsg . $loggedMsg;
    // One case note per client who attended (4.31.0): each is marked as waiting for its note.
    foreach ($doneSess as $ds) sessnote_mark_pending((int)$ds['id'], (int)$actor['id']);
    return $res;
}
