<?php
declare(strict_types=1);
// Times offered on a portal request (3.0.52). For an appointment or reschedule request,
// staff offer two or three times; each is checked against the instructor's calendar and the
// authorization before it is offered. The client confirms one in the portal: a new session
// is booked (or the session to reschedule is moved), the client gets the calendar invite,
// the other times are withdrawn, and the request and its task close.

/** The types of request times can be offered for. */
const OFFER_REQUEST_TYPES = ['appointment', 'reschedule'];

/** Why a time cannot be offered, or null when it can. $moving is the session a reschedule moves. */
function offer_time_problem(int $start, int $end, int $userId, ?int $trackId, ?array $moving): ?string {
    if ($start < time() + 3600) return 'is less than an hour from now';
    $o = db()->prepare("SELECT COUNT(*) FROM scheduled_sessions WHERE user_id = ? AND status = 'Scheduled' AND start_at < ? AND end_at > ?" . ($moving ? ' AND id <> ' . (int)$moving['id'] : ''));
    $o->execute([$userId, date('Y-m-d H:i:s', $end), date('Y-m-d H:i:s', $start)]);
    if ((int)$o->fetchColumn() > 0) return 'clashes with another session for the instructor';
    if (function_exists('calendar_busy_overlaps') && calendar_busy_overlaps($userId, $start, $end)) return 'clashes with the instructor\'s Outlook calendar';
    if ($trackId) {
        if ($moving) {
            // A move uses the hours already reserved, so only the dates and the track are checked.
            $w = session_move_warnings($moving + ['user_id' => $userId], $start, $end);
            $w = array_values(array_filter($w, fn($x) => !str_contains($x, 'already has a session')));
        } else {
            require_once __DIR__ . '/workflows.php';
            $w = workflow_authorization($trackId, $start, $end);
        }
        if ($w) return 'is not within the authorization (' . rtrim(implode(' ', $w), '.') . ')';
    }
    return null;
}

/**
 * Offer times on a request: each start (a timestamp) for $minutes, with the instructor, the
 * track and the setting. Times that fail a check are refused with the reason. Earlier offers
 * still open are withdrawn. Returns ['error' => ?string, 'offered' => n, 'refused' => [text]].
 */
function offer_times_save(int $requestId, array $starts, int $minutes, int $userId, ?int $trackId, bool $remote, string $location, int $actor): array {
    $q = db()->prepare('SELECT pr.*, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ?');
    $q->execute([$requestId]);
    $req = $q->fetch();
    if (!$req || !in_array($req['type'], OFFER_REQUEST_TYPES, true) || in_array($req['status'], ['Done', 'Declined'], true)) return ['error' => 'Times can be offered only on an open appointment or reschedule request.', 'offered' => 0, 'refused' => []];
    if ($minutes < 15 || $minutes > 480) return ['error' => 'Choose a length from 15 minutes to 8 hours.', 'offered' => 0, 'refused' => []];
    $moving = null;
    if ($req['type'] === 'reschedule' && $req['session_id']) {
        $m = db()->prepare("SELECT * FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled'");
        $m->execute([(int)$req['session_id']]);
        $moving = $m->fetch() ?: null;
        if (!$moving) return ['error' => 'The session to reschedule is no longer booked. Offer new appointment times instead, or close the request.', 'offered' => 0, 'refused' => []];
        $trackId = $moving['track_id'] ? (int)$moving['track_id'] : $trackId;
        // A move keeps the session's length, so the hours already reserved still cover it.
        $minutes = (int)round((strtotime((string)$moving['end_at']) - strtotime((string)$moving['start_at'])) / 60);
    }
    $pt = db()->prepare('SELECT COUNT(*) FROM portal_tokens WHERE client_id = ? AND (expires_at IS NULL OR expires_at > NOW())');
    $pt->execute([(int)$req['client_id']]);
    if (!(int)$pt->fetchColumn()) return ['error' => 'The client has no working portal link, so they could not see the times. Send them a portal link from their page first, or book the session yourself.', 'offered' => 0, 'refused' => []];
    if ($trackId) {
        $t = db()->prepare('SELECT client_id FROM training_tracks WHERE id = ?');
        $t->execute([$trackId]);
        if ((int)$t->fetchColumn() !== (int)$req['client_id']) return ['error' => 'Choose one of this client\'s training tracks.', 'offered' => 0, 'refused' => []];
    }
    $starts = array_values(array_unique(array_filter($starts, fn($s) => $s > 0)));
    if (!$starts) return ['error' => 'Enter at least one date and time to offer.', 'offered' => 0, 'refused' => []];
    if (count($starts) > 3) return ['error' => 'Offer at most three times.', 'offered' => 0, 'refused' => []];
    $good = []; $refused = [];
    foreach ($starts as $s) {
        $e = $s + $minutes * 60;
        $why = offer_time_problem($s, $e, $userId, $trackId, $moving);
        if ($why !== null) $refused[] = date('l, F j \a\t g:i A', $s) . ' ' . $why . '.'; else $good[] = [$s, $e];
    }
    if (!$good) return ['error' => 'None of the times could be offered: ' . implode(' ', $refused), 'offered' => 0, 'refused' => $refused];
    $location = mb_substr(trim($location), 0, 190);
    db()->beginTransaction();
    try {
        db()->prepare("UPDATE portal_request_offers SET status = 'Withdrawn' WHERE request_id = ? AND status = 'Offered'")->execute([$requestId]);
        $ins = db()->prepare('INSERT INTO portal_request_offers (request_id, start_at, end_at, user_id, track_id, is_remote, location, created_by) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($good as [$s, $e]) $ins->execute([$requestId, date('Y-m-d H:i:s', $s), date('Y-m-d H:i:s', $e), $userId, $trackId, $remote ? 1 : 0, $location !== '' ? $location : null, $actor]);
        db()->prepare("UPDATE portal_requests SET status = 'In Progress', handled_by = ? WHERE id = ? AND status = 'New'")->execute([$actor, $requestId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($actor, 'portal_request.offer', 'client', (int)$req['client_id'], 'request #' . $requestId . ': ' . count($good) . ' time(s) offered');
    $emailed = offer_times_notice($requestId);
    return ['error' => null, 'offered' => count($good), 'refused' => $refused, 'emailed' => $emailed];
}

/** The times on a request; only those still open unless $all. */
function request_offers(int $requestId, bool $all = false): array {
    try {
        $q = db()->prepare('SELECT o.*, u.name AS staff_name FROM portal_request_offers o LEFT JOIN users u ON u.id = o.user_id WHERE o.request_id = ?'
            . ($all ? '' : " AND o.status = 'Offered' AND o.start_at > NOW()") . ' ORDER BY o.start_at');
        $q->execute([$requestId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return []; // mig_3.0.52 not run yet
    }
}

/** Email the client that times are waiting in their portal (an email client with a working link only). */
function offer_times_notice(int $requestId): bool {
    try {
        $q = db()->prepare('SELECT c.*, p.token AS portal_token FROM portal_requests pr JOIN clients c ON c.id = pr.client_id
            LEFT JOIN portal_tokens p ON p.client_id = c.id AND (p.expires_at IS NULL OR p.expires_at > NOW()) WHERE pr.id = ?');
        $q->execute([$requestId]);
        $r = $q->fetch();
        if (!$r || !$r['portal_token'] || (int)$r['email_verified'] !== 1 || !filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL)
            || in_array($r['a11y_contact'] ?? '', ['Phone', 'Postal mail'], true)) return false;
        [$subj, $body] = email_template_for_client('times_offered', $r, ['name' => $r['name'],
            'link' => cfg('base_url') . '/portal.php?t=' . $r['portal_token'] . (client_lang($r) === 'es' ? '&lang=es' : '') . '#req-h']);
        return send_app_mail((string)$r['email'], $subj, $body);
    } catch (Throwable $e) {
        error_log('offer_times_notice: ' . get_class($e) . ' ' . $e->getCode());
        return false;
    }
}

/**
 * The client confirms an offered time. In one transaction, with the request locked so two
 * tabs cannot both book: the time is checked again, then booked (or the session moved), the
 * other times are withdrawn and the request is done. The client's calendar invitation and the
 * instructor's notice go after it commits. Returns ['error' => ?string, 'session_id' => ?int].
 */
function offer_confirm(int $offerId, int $clientId): array {
    $pdo = db();
    $after = null; $sid = null; $gone = null;
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT pr.* FROM portal_request_offers o JOIN portal_requests pr ON pr.id = o.request_id WHERE o.id = ? AND pr.client_id = ? FOR UPDATE');
        $q->execute([$offerId, $clientId]);
        $req = $q->fetch();
        $o = null;
        if ($req) { $oq = $pdo->prepare('SELECT * FROM portal_request_offers WHERE id = ? FOR UPDATE'); $oq->execute([$offerId]); $o = $oq->fetch(); }
        if (!$req || !$o || $o['status'] !== 'Offered' || in_array($req['status'], ['Done', 'Declined'], true)) {
            $pdo->rollBack();
            return ['error' => 'That time is no longer offered.', 'session_id' => null];
        }
        $start = strtotime((string)$o['start_at']); $end = strtotime((string)$o['end_at']);
        $instructor = (int)$o['user_id'];
        session_lock_instructor($instructor); // one booking at a time for this instructor, whatever the path (4.20.0)
        $moving = null;
        if ($req['type'] === 'reschedule' && $req['session_id']) {
            $m = $pdo->prepare('SELECT * FROM scheduled_sessions WHERE id = ? FOR UPDATE');
            $m->execute([(int)$req['session_id']]);
            $moving = $m->fetch() ?: null;
            if (!$moving || $moving['status'] !== 'Scheduled') $gone = 'The session to move is no longer booked. Your instructor has been told.';
        }
        if ($gone === null) {
            $why = offer_time_problem($start, $end, $instructor, $o['track_id'] ? (int)$o['track_id'] : null, $moving);
            if ($why !== null) $gone = str_contains($why, 'less than an hour')
                ? 'That time is too soon to book now. Your instructor has been told and will offer new times.'
                : 'That time is no longer free. Your instructor has been told and will offer new times.';
        }
        if ($gone !== null) {
            $pdo->prepare("UPDATE portal_request_offers SET status = 'Expired' WHERE id = ?")->execute([$offerId]);
            $pdo->commit();
            notify_user($instructor, 'A client chose ' . date('D, M j g:i A', $start) . ', but it could not be booked: offer new times', 'portal_requests.php');
            return ['error' => $gone, 'session_id' => null];
        }
        if ($moving) {
            $pdo->prepare("UPDATE scheduled_sessions SET start_at = ?, end_at = ?, reminder_sent = 0, sms_reminder_sent = 0, client_reply = NULL, client_reply_at = NULL, ics_sequence = ics_sequence + 1 WHERE id = ?")
                ->execute([$o['start_at'], $o['end_at'], (int)$moving['id']]);
            $sid = (int)$moving['id'];
            $after = ['moved', (string)$moving['start_at']];
        } else {
            $pdo->prepare("INSERT INTO scheduled_sessions (client_id, track_id, user_id, start_at, end_at, location, is_remote, status) VALUES (?,?,?,?,?,?,?, 'Scheduled')")
                ->execute([$clientId, $o['track_id'], $instructor, $o['start_at'], $o['end_at'], $o['location'], (int)$o['is_remote']]);
            $sid = (int)$pdo->lastInsertId();
            $after = ['booked', null];
        }
        $pdo->prepare("UPDATE portal_request_offers SET status = 'Chosen', chosen_at = NOW(), session_id = ? WHERE id = ?")->execute([$sid, $offerId]);
        $pdo->prepare("UPDATE portal_request_offers SET status = 'Withdrawn' WHERE request_id = ? AND status = 'Offered'")->execute([(int)$req['id']]);
        // The client reads this on their portal: in their own time zone, named (4.1.0).
        $cq = $pdo->prepare('SELECT time_zone, state, office_id FROM clients WHERE id = ?');
        $cq->execute([$clientId]);
        $bookedTz = client_zone($cq->fetch() ?: []);
        $pdo->prepare("UPDATE portal_requests SET status = 'Done', outcome = ? WHERE id = ?")->execute([mb_substr('Booked for ' . zone_date($bookedTz, 'l, F j, Y \a\t g:i A T', $start) . '.', 0, 500), (int)$req['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // After the commit: the task, the client's invitation, and the instructor (whose calendar
    // is updated for a move; the client, not the instructor, made the change).
    complete_task_by_key('preq-' . (int)$req['id'], null);
    client_session_notice([$sid], $after[0]);
    if ($after[0] === 'moved') session_tell_instructor($sid, 'moved', 0, $after[1]);
    notify_user($instructor, 'A client chose an offered time: ' . date('D, M j g:i A', $start), 'schedule.php#s-' . $sid);
    audit(null, 'portal_request.confirm', 'client', $clientId, 'request #' . (int)$req['id'] . ': ' . ($after[0] === 'moved' ? 'moved' : 'booked') . ' session #' . $sid . ' (client chose an offered time)');
    return ['error' => null, 'session_id' => $sid];
}
