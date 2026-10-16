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

/**
 * Move a Scheduled session. With warnings and no $confirmed, nothing changes and the
 * warnings come back. Returns ['error' => ?string, 'warnings' => [], 'moved' => bool].
 */
function session_move(int $sid, int $start, int $end, int $actor, bool $confirmed, string $via = ''): array {
    if ($end <= $start || $end - $start > 12 * 3600) return ['error' => 'Choose a length from 15 minutes to 12 hours.', 'warnings' => [], 'moved' => false];
    $q = db()->prepare("SELECT * FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled'");
    $q->execute([$sid]);
    $sess = $q->fetch();
    if (!$sess) return ['error' => 'That session is no longer scheduled, so it was not moved.', 'warnings' => [], 'moved' => false];
    if ($start === strtotime((string)$sess['start_at']) && $end === strtotime((string)$sess['end_at'])) {
        return ['error' => 'That is the session\'s current time; nothing was changed.', 'warnings' => [], 'moved' => false];
    }
    $warnings = session_move_warnings($sess, $start, $end);
    if ($warnings && !$confirmed) return ['error' => null, 'warnings' => $warnings, 'moved' => false];
    $u = db()->prepare("UPDATE scheduled_sessions SET start_at = ?, end_at = ?, reminder_sent = 0, ics_sequence = ics_sequence + 1 WHERE id = ? AND status = 'Scheduled'");
    $u->execute([date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end), $sid]);
    if ($u->rowCount() !== 1) return ['error' => 'That session changed meanwhile, so it was not moved.', 'warnings' => [], 'moved' => false];
    audit($actor, 'session.move', 'session', $sid, 'client #' . (int)$sess['client_id'] . ': ' . date('Y-m-d H:i', strtotime((string)$sess['start_at']))
        . ' → ' . date('Y-m-d H:i', $start) . ($warnings ? ' (confirmed: ' . implode(' ', $warnings) . ')' : '') . ($via !== '' ? ' (' . $via . ')' : ''));
    client_session_notice([$sid], 'moved');
    session_tell_instructor($sid, 'moved', $actor, (string)$sess['start_at']);
    return ['error' => null, 'warnings' => $warnings, 'moved' => true];
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

/** Cancel every Scheduled session of this session's group. Returns how many. */
function session_cancel_group(int $sid, int $actor): int {
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
 * update for their invite (cms-session-<id>). The first name only, as elsewhere.
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
        $ics = build_ics('cms-session-' . $sid . '@' . $host, 'AT Training: ' . $first, 'Training session with ' . $first . ".\nDetails: " . cfg('base_url') . '/schedule.php#s-' . $sid,
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
