<?php
declare(strict_types=1);
// Amendment requests followed from sent to answered (3.0.44). Recording a request as sent
// keeps what was asked for and the letter (as a track case note), and opens a follow-up
// task. The reply (approved, partly approved, denied) is recorded with its date; an
// approved change is then entered on the track (track_edit.php), which links the
// auth_amendments row back to the request.

const AMENDMENT_REPLY_STATUSES = ['Approved', 'Partly approved', 'Denied', 'Withdrawn'];

/** The open (unanswered) request on a track, or null. */
function amendment_open(int $trackId): ?array {
    try {
        $q = db()->prepare("SELECT * FROM amendment_requests WHERE track_id = ? AND status = 'Awaiting reply' ORDER BY requested_on DESC, id DESC LIMIT 1");
        $q->execute([$trackId]);
        return $q->fetch() ?: null;
    } catch (PDOException $e) {
        return null; // mig_3.0.44 not run yet
    }
}

/** Every request on a track, newest first. */
function amendment_history(int $trackId): array {
    try {
        $q = db()->prepare('SELECT a.*, u.name AS by_name FROM amendment_requests a LEFT JOIN users u ON u.id = a.created_by WHERE a.track_id = ? ORDER BY a.requested_on DESC, a.id DESC');
        $q->execute([$trackId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** "Amendment requested on <date>, awaiting reply" for a track, or ''. */
function amendment_waiting_text(int $trackId): string {
    $a = amendment_open($trackId);
    return $a ? 'Amendment requested on ' . fmt_date($a['requested_on']) . ', awaiting reply' : '';
}

/**
 * Record a request as sent. $in: requested_on, sent_to, hours, amount, new_end, letter.
 * Returns [errors, id].
 */
function amendment_record(int $trackId, array $in, int $actor): array {
    $str = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $e = [];
    $on = valid_date($str('requested_on'), 2000, (int)date('Y'));
    if ($on === null || $on > date('Y-m-d')) $e['requested_on'] = 'Enter the date the request was sent (today or earlier).';
    $hours = $str('hours') === '' ? null : (is_numeric($str('hours')) && (float)$str('hours') > 0 && (float)$str('hours') < 10000 ? round((float)$str('hours'), 2) : false);
    $amount = $str('amount') === '' ? null : (is_numeric(str_replace(['$', ','], '', $str('amount'))) && (float)str_replace(['$', ','], '', $str('amount')) > 0 ? round((float)str_replace(['$', ','], '', $str('amount')), 2) : false);
    $end = $str('new_end') === '' ? null : valid_date($str('new_end'), 2000, (int)date('Y') + 5);
    if ($hours === false) $e['hours'] = 'Enter the additional hours as a number, or leave it blank.';
    if ($amount === false) $e['amount'] = 'Enter the additional dollars as a number, or leave it blank.';
    if ($str('new_end') !== '' && $end === null) $e['new_end'] = 'Enter the new end date with a four-digit year, or leave it blank.';
    if (!$e && $hours === null && $amount === null && $end === null) $e['hours'] = 'Say what was asked for: more hours, more dollars or a later end date.';
    $sentTo = mb_substr($str('sent_to'), 0, 190);
    $letter = str_replace("\r\n", "\n", $str('letter'));
    if (strlen($letter) > 60000) $e['letter'] = 'The letter is too long to keep.';
    if ($e) return [$e, 0];
    $t = db()->prepare('SELECT t.*, c.id AS cid, c.name AS client_name, c.assigned_to FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
    $t->execute([$trackId]);
    $track = $t->fetch();
    if (!$track) return [['hours' => 'Training track not found.'], 0];
    $asked = array_filter([$hours !== null ? fmt_hours((float)$hours) . ' more' : '', $amount !== null ? fmt_money((float)$amount) . ' more' : '',
        $end !== null ? 'end date ' . fmt_date($end) : '']);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO amendment_requests (track_id, client_id, requested_on, sent_to, hours_requested, amount_requested, new_end_date, letter, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$trackId, (int)$track['cid'], $on, $sentTo !== '' ? $sentTo : null, $hours, $amount, $end, $letter !== '' ? $letter : null, $actor]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO client_notes (client_id, track_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$track['cid'], $trackId, 'Case Note', mb_substr('Authorization amendment requested (' . ($track['auth_number'] ?: $track['title']) . ')', 0, 255),
                'Amendment requested on ' . fmt_date($on) . ($sentTo !== '' ? ' from ' . $sentTo : '') . ': ' . implode(', ', $asked) . '.'
                . ($letter !== '' ? "\n\nLetter sent:\n" . $letter : ''), $actor]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    add_task('Follow up on amendment request: ' . $track['client_name'] . ' (' . ($track['auth_number'] ?: $track['title']) . ')',
        'Requested on ' . fmt_date($on) . ': ' . implode(', ', $asked) . '. Record the reply on the amendment page.',
        (int)$track['cid'], null, business_days_from(10), $track['assigned_to'] ? (int)$track['assigned_to'] : $actor, $actor, 'amend-' . $id);
    audit($actor, 'auth.amend_request', 'track', $trackId, implode(', ', $asked));
    return [[], $id];
}

/** Record the reply to a request. Returns an error, or null. */
function amendment_reply(int $id, int $trackId, string $status, string $on, string $note, int $actor): ?string {
    if (!in_array($status, AMENDMENT_REPLY_STATUSES, true)) return 'Choose the reply.';
    $d = valid_date($on, 2000, (int)date('Y'));
    if ($d === null || $d > date('Y-m-d')) return 'Enter the date of the reply (today or earlier).';
    $note = mb_substr(trim($note), 0, 255);
    $u = db()->prepare("UPDATE amendment_requests SET status = ?, replied_on = ?, reply_note = ? WHERE id = ? AND track_id = ? AND status = 'Awaiting reply'");
    $u->execute([$status, $d, $note !== '' ? $note : null, $id, $trackId]);
    if ($u->rowCount() !== 1) return 'That request already has a reply.';
    complete_task_by_key('amend-' . $id, $actor);
    audit($actor, 'auth.amend_reply', 'track', $trackId, 'request #' . $id . ': ' . $status . ' ' . $d);
    return null;
}
