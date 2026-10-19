<?php
declare(strict_types=1);
// Moving a session, and cancelling the rest of a weekly series or a whole group (3.0.43).
// A move keeps the session (its notes, check-in and history) and changes its time; the
// client's and the instructor's calendar entries are updated in place (same calendar ID,
// a higher SEQUENCE). Used by schedule.php, the app's API and voice.

/**
 * What to warn about before moving a session to [$start, $end): the instructor already
 * booked then, and times outside the track's authorization. Empty when nothing.
 */
function session_move_warnings(array $sess, int $start, int $end): array {
    $out = [];
    $o = db()->prepare("SELECT COUNT(*) FROM scheduled_sessions WHERE user_id = ? AND status = 'Scheduled' AND id <> ?
        AND start_at < ? AND end_at > ? AND (group_uid IS NULL OR group_uid <> ?)");
    $o->execute([(int)$sess['user_id'], (int)$sess['id'], date('Y-m-d H:i:s', $end), date('Y-m-d H:i:s', $start), (string)($sess['group_uid'] ?? '')]);
    if ((int)$o->fetchColumn() > 0) $out[] = 'The instructor already has a session at that time.';
    elseif (function_exists('calendar_busy_overlaps') && calendar_busy_overlaps((int)$sess['user_id'], $start, $end)) $out[] = 'The instructor\'s Outlook calendar shows them busy then.';
    if (!empty($sess['track_id'])) {
        $t = db()->prepare('SELECT status, start_date, expiration_date FROM training_tracks WHERE id = ?');
        $t->execute([(int)$sess['track_id']]);
        if ($tr = $t->fetch()) {
            if ($tr['status'] !== 'Active') $out[] = 'The training track is not active.';
            if ($tr['start_date'] && date('Y-m-d', $start) < $tr['start_date']) $out[] = 'The new time is before the authorization starts.';
            if ($tr['expiration_date'] && date('Y-m-d', $end - 1) > $tr['expiration_date']) $out[] = 'The new time is after the authorization expires.';
        }
    }
    return $out;
}

/** A fingerprint of the move warnings a form was shown, with the time they were about (4.28.0).
 *  The "Move anyway" box carries it, so it approves exactly those warnings for exactly that
 *  time: a box ticked and then sent with another time, or warnings that have changed since,
 *  approve nothing and the new warnings are shown. */
function session_move_hash(array $warnings, int $start, int $end): string {
    return hash('sha256', json_encode([array_values($warnings), $start, $end]));
}

/**
 * Move a Scheduled session. With warnings and no $confirmed, nothing changes and the
 * warnings come back. $confirmed is either true (the caller showed the warnings itself) or
 * the session_move_hash() the "Move anyway" box carried, which must match the warnings
 * found now for this time (4.28.0). Returns ['error' => ?string, 'warnings' => [],
 * 'moved' => bool, 'hash' => string]. A session moved on its own out of a group leaves
 * the group (it is now a make-up), and the move runs under the instructor's lock that
 * every booking path takes, so a booking at the same moment cannot double-book them.
 */
function session_move(int $sid, int $start, int $end, int $actor, bool|string $confirmed, string $via = ''): array {
    if ($end <= $start || $end - $start > 12 * 3600) return ['error' => 'Choose a length from 15 minutes to 12 hours.', 'warnings' => [], 'moved' => false, 'hash' => ''];
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled'");
        $q->execute([$sid]);
        $sess = $q->fetch();
        if ($sess) {
            if (function_exists('session_lock_instructor')) session_lock_instructor((int)$sess['user_id']);
            else $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([(int)$sess['user_id']]);
            // Read again under the lock: the session may have changed while waiting.
            $q = $pdo->prepare("SELECT * FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled' FOR UPDATE");
            $q->execute([$sid]);
            $sess = $q->fetch();
        }
        if (!$sess) { if ($own) $pdo->rollBack(); return ['error' => 'That session is no longer scheduled, so it was not moved.', 'warnings' => [], 'moved' => false, 'hash' => '']; }
        if ($start === strtotime((string)$sess['start_at']) && $end === strtotime((string)$sess['end_at'])) {
            if ($own) $pdo->rollBack();
            return ['error' => 'That is the session\'s current time; nothing was changed.', 'warnings' => [], 'moved' => false, 'hash' => ''];
        }
        $warnings = session_move_warnings($sess, $start, $end);
        $hash = $warnings ? session_move_hash($warnings, $start, $end) : '';
        $approved = $confirmed === true || (is_string($confirmed) && $confirmed !== '' && $hash !== '' && hash_equals($hash, $confirmed));
        if ($warnings && !$approved) { if ($own) $pdo->rollBack(); return ['error' => null, 'warnings' => $warnings, 'moved' => false, 'hash' => $hash]; }
        $leftGroup = !empty($sess['group_uid']);
        $u = $pdo->prepare("UPDATE scheduled_sessions SET start_at = ?, end_at = ?, reminder_sent = 0, client_reminded_at = NULL, staff_reminded_at = NULL, sms_reminder_sent = 0, client_reply = NULL, client_reply_at = NULL, ics_sequence = ics_sequence + 1"
            . ($leftGroup ? ', group_uid = NULL' : '') . " WHERE id = ? AND status = 'Scheduled'");
        $u->execute([date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end), $sid]);
        if ($u->rowCount() !== 1) { if ($own) $pdo->rollBack(); return ['error' => 'That session changed meanwhile, so it was not moved.', 'warnings' => [], 'moved' => false, 'hash' => '']; }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($actor, 'session.move', 'session', $sid, 'client #' . (int)$sess['client_id'] . ': ' . date('Y-m-d H:i', strtotime((string)$sess['start_at']))
        . ' → ' . date('Y-m-d H:i', $start) . ($warnings ? ' (confirmed: ' . implode(' ', $warnings) . ')' : '')
        . ($leftGroup ? ' (left its group session)' : '') . ($via !== '' ? ' (' . $via . ')' : ''));
    client_session_notice([$sid], 'moved');
    session_tell_instructor($sid, 'moved', $actor, (string)$sess['start_at']);
    return ['error' => null, 'warnings' => $warnings, 'moved' => true, 'hash' => '', 'left_group' => $leftGroup];
}

/** Cancel this session and every later Scheduled one in its weekly series. Returns how many. */
function session_cancel_series(int $sid, int $actor): int {
    $q = db()->prepare("SELECT client_id, series_uid, start_at FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled'");
    $q->execute([$sid]);
    $s = $q->fetch();
    if (!$s || !$s['series_uid']) return 0;
    $ids = db()->prepare("SELECT id FROM scheduled_sessions WHERE series_uid = ? AND status = 'Scheduled' AND start_at >= ? ORDER BY start_at");
    $ids->execute([$s['series_uid'], $s['start_at']]);
    return session_cancel_many(array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)), $actor, 'rest of a weekly series');
}

/**
 * Why a caseload-limited user may not cancel this session's whole group (4.27.0): every
 * Scheduled session of the group cancels together, and its clients and instructors are
 * emailed, so a member on another caseload refuses the whole cancellation. Null when the
 * user may cancel it (an unrestricted user, or every member is on their caseload).
 */
function session_group_scope_error(int $sid, int $actor): ?string {
    if (!caseload_scoped()) return null;
    $q = db()->prepare("SELECT COUNT(*) FROM scheduled_sessions g
        JOIN scheduled_sessions s ON s.id = ? AND s.group_uid IS NOT NULL AND g.group_uid = s.group_uid
        JOIN clients c ON c.id = g.client_id
        WHERE g.status = 'Scheduled' AND (c.assigned_to IS NULL OR c.assigned_to <> ?)");
    $q->execute([$sid, $actor]);
    $n = (int)$q->fetchColumn();
    if ($n === 0) return null;
    return 'Nothing was cancelled: ' . ($n === 1 ? 'one client' : $n . ' clients') . ' in this group ' . ($n === 1 ? 'is' : 'are')
        . ' not on your caseload. Cancel your own client\'s session on its own, or ask someone who can see the whole group to cancel it.';
}

/** Cancel every Scheduled session of this session's group. Returns how many. */
function session_cancel_group(int $sid, int $actor): int {
    // A caseload-limited user cannot cancel other caseloads' sessions (4.27.0); callers
    // show session_group_scope_error() first, this is the backstop.
    if (session_group_scope_error($sid, $actor) !== null) return 0;
    $q = db()->prepare("SELECT group_uid FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled'");
    $q->execute([$sid]);
    $g = $q->fetchColumn();
    if (!$g) return 0;
    $ids = db()->prepare("SELECT id FROM scheduled_sessions WHERE group_uid = ? AND status = 'Scheduled'");
    $ids->execute([$g]);
    return session_cancel_many(array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)), $actor, 'whole group');
}

/** Cancel the given sessions (each claimed once); clients and instructors are told. */
function session_cancel_many(array $ids, int $actor, string $what): int {
    $done = [];
    $claim = db()->prepare("UPDATE scheduled_sessions SET status = 'Cancelled' WHERE id = ? AND status = 'Scheduled'");
    foreach ($ids as $id) { $claim->execute([$id]); if ($claim->rowCount() === 1) $done[] = $id; }
    if (!$done) return 0;
    audit($actor, 'session.cancel', 'session', $done[0], $what . ': ' . count($done) . ' session(s) #' . implode(', #', $done));
    // One cancellation email per client (future sessions only), one notice per instructor.
    $q = db()->prepare('SELECT id, client_id, start_at FROM scheduled_sessions WHERE id IN (' . implode(',', array_fill(0, count($done), '?')) . ')');
    $q->execute($done);
    $byClient = [];
    foreach ($q->fetchAll() as $r) if (strtotime((string)$r['start_at']) > time()) $byClient[(int)$r['client_id']][] = (int)$r['id'];
    foreach ($byClient as $sids) client_session_notice($sids, 'cancelled');
    foreach ($done as $id) session_tell_instructor($id, 'cancelled', $actor, null);
    return count($done);
}

/**
 * Tell the session's instructor (when someone else made the change) with a calendar
 * update for their invite (ics_uid('session', id)). The first name only, as elsewhere.
 */
function session_tell_instructor(int $sid, string $what, int $actor, ?string $oldStart): void {
    try {
        $q = db()->prepare('SELECT s.*, c.name AS client_name, u.email, u.name AS staff_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
            JOIN users u ON u.id = s.user_id AND u.is_active = 1 WHERE s.id = ?');
        $q->execute([$sid]);
        $s = $q->fetch();
        if (!$s || (int)$s['user_id'] === $actor || !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) return;
        $first = preg_split('/\s+/u', trim((string)$s['client_name']))[0] ?? '';
        $st = strtotime((string)$s['start_at']); $en = strtotime((string)$s['end_at']);
        $host = parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost';
        $ics = build_ics(ics_uid('session', $sid), 'AT Training: ' . $first, 'Training session with ' . $first . ".\nDetails: " . cfg('base_url') . '/schedule.php#s-' . $sid,
            (string)($s['location'] ?? ''), $st, $en, (string)cfg('mail_from'), (string)$s['email']);
        if ($what === 'cancelled') $ics = str_replace(["METHOD:REQUEST", "STATUS:CONFIRMED"], ["METHOD:CANCEL", "STATUS:CANCELLED"], $ics);
        $ics = str_replace("DTSTAMP:", 'SEQUENCE:' . ((int)$s['ics_sequence'] + ($what === 'cancelled' ? 1 : 0)) . "\r\nDTSTAMP:", $ics);
        $text = $what === 'moved'
            ? "Your training session with {$first} was moved" . ($oldStart ? ' from ' . date('l, F j \a\t g:i A', strtotime($oldStart)) : '') . ' to ' . date('l, F j \a\t g:i A', $st) . ".\n\nThe attached update moves it in your calendar. If your calendar still shows the old time, delete that entry.\n"
            : "Your training session with {$first} on " . date('l, F j \a\t g:i A', $st) . " was cancelled.\n\nThe attachment removes it from your calendar.\n";
        notify_user((int)$s['user_id'], 'Session ' . ($what === 'moved' ? 'moved' : 'cancelled') . ': ' . $first . ', ' . date('D, M j g:i A', $st), 'schedule.php#s-' . $sid);
        send_app_mail_attach((string)$s['email'], 'Session ' . ($what === 'moved' ? 'moved' : 'cancelled') . ' — ' . date('D, M j g:i A', $st), $text,
            [['name' => $what === 'moved' ? 'invite.ics' : 'cancel.ics', 'mime' => 'text/calendar; method=' . ($what === 'moved' ? 'REQUEST' : 'CANCEL'), 'bytes' => $ics]]);
    } catch (Throwable $e) {
        error_log('session_tell_instructor: ' . get_class($e) . ' ' . $e->getCode());
    }
}

/**
 * Mark a Scheduled session Cancelled or No Show (4.0.0: shared by the app and voice). The
 * counselor is told of a no-show, the client of a cancellation still ahead, and the
 * session's instructor when someone else closed it. Returns an error, or null.
 */
function session_set_outcome(int $sid, string $to, int $userId, string $via): ?string {
    if (!in_array($to, ['Cancelled', 'No Show'], true)) return 'Status is not a valid choice.';
    $st = db()->prepare("SELECT s.client_id, s.user_id, s.start_at, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id WHERE s.id = ? AND s.status = 'Scheduled'");
    $st->execute([$sid]);
    $sess = $st->fetch();
    if (!$sess) return 'Session not found or already closed.';
    // As on the website: a session that has not started yet can be cancelled, not marked a no-show.
    if ($to === 'No Show' && strtotime((string)$sess['start_at']) > time()) return 'A session can be marked No Show only once it has started.';
        $claim = db()->prepare("UPDATE scheduled_sessions SET status = ? WHERE id = ? AND status = 'Scheduled'");
        $claim->execute([$to, $sid]);
        if ($claim->rowCount() !== 1) return 'Session was already closed.';
        // The website's codes (schedule.php), so a search of the audit log finds both channels.
        audit($userId, $to === 'Cancelled' ? 'session.cancel' : 'session.no_show', 'session', $sid, $to . ' (' . $via . ')');
        if ($to === 'No Show') counselor_noshow_notice($sid); // 3.0.41: the counselor is emailed
        if ($to === 'Cancelled' && strtotime((string)$sess['start_at']) > time()) client_session_notice([$sid], 'cancelled');
        // The session's instructor is told when someone else closed it. No calendar
        // cancellation can be sent (no invite UID is stored), so a plain notice says
        // which session it was: the date, the time and the client's first name only.
        $sessInstructor = (int)$sess['user_id'];
        if ($sessInstructor !== $userId) {
            $iu = db()->prepare('SELECT email FROM users WHERE id = ? AND is_active = 1');
            $iu->execute([$sessInstructor]);
            $instructorEmail = $iu->fetchColumn();
            if ($instructorEmail !== false) {
                $sessTs = strtotime((string)$sess['start_at']);
                $firstName = preg_split('/\s+/u', trim((string)$sess['client_name']))[0] ?? '';
                $outcome = $to === 'Cancelled' ? 'cancelled' : 'marked No Show';
                notify_user($sessInstructor, 'Session ' . $outcome . ': ' . $firstName . ', ' . date('D, M j g:i A', $sessTs), 'schedule.php');
                send_app_mail((string)$instructorEmail, 'Session ' . $outcome . ': ' . date('D, M j g:i A', $sessTs),
                    "Your training session with {$firstName} on " . date('l, F j, Y \a\t g:i A', $sessTs) . " was {$outcome}.\n"
                    . ($to === 'Cancelled' ? "\nIf it is in your calendar, remove it there: no calendar update was sent.\n" : ''));
            }
        }
    return null;
}
