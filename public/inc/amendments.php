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

/** What a request asked for and what came of it, as the "Requests and replies" rows say it (4.37.0:
 *  shared by amendment_request.php and the apps' amendment history): the hours, dollars and end date
 *  asked for, who it was sent to, the reply, and whether the approved change is on the track. */
function amendment_row_body(array $a): string {
    $asked = implode(', ', array_filter([$a['hours_requested'] !== null ? fmt_hours((float)$a['hours_requested']) . ' more' : '',
        $a['amount_requested'] !== null ? fmt_money((float)$a['amount_requested']) . ' more' : '', $a['new_end_date'] ? 'end date ' . fmt_date($a['new_end_date']) : '']));
    return $asked . ($a['sent_to'] ? ', sent to ' . $a['sent_to'] : '') . '.'
        . ($a['replied_on'] ? ' Reply ' . fmt_date($a['replied_on']) . ($a['reply_note'] ? ': ' . $a['reply_note'] : '') . '.' : '')
        . ($a['amendment_id'] ? ' The approved change is recorded on the track.' : '');
}

/** One request as one line: "Requested <date>: <status>. <what was asked and answered>"; without the
 *  heading part when the page shows it as a heading of its own. */
function amendment_row_text(array $a, bool $withHeading = true): string {
    return ($withHeading ? 'Requested ' . fmt_date($a['requested_on']) . ': ' . $a['status'] . '. ' : '') . amendment_row_body($a);
}

/** Whether an approved (or partly approved) request still needs its change entered on the track. */
function amendment_needs_change(array $a): bool {
    return in_array($a['status'], ['Approved', 'Partly approved'], true) && empty($a['amendment_id']);
}

/** The amendment reason track_edit.php fills in when the approved change is entered from a request
 *  (4.37.0: shared with the apps' amendment_reply). */
function amendment_reason_prefill(array $r): string {
    return 'VR ' . strtolower((string)$r['status']) . ' the amendment requested ' . fmt_date($r['requested_on']) . (!empty($r['replied_on']) ? ' (reply ' . fmt_date($r['replied_on']) . ')' : '');
}

/** The facts the AI letter is drafted from (amendment_request.php and the apps' amendment, 4.37.0):
 *  the burn-down figures only, with [COUNSELOR] and [STAFF] for the names. $n holds auth, worked,
 *  pct, remaining, funds_limit, funds_auth, funds_used, funds_pct, other_costs, funds_left, run_out,
 *  outlasts_expiry, exp_date, expired, suggest_funds and suggest_add. */
function amendment_ai_facts(array $track, array $n): string {
    return "Facts for an authorization amendment request to a vocational rehabilitation counselor:\n"
        . "- Service: " . $track['title'] . "\n- Hours authorized: " . fmt_hours($n['auth']) . "\n- Hours used: " . fmt_hours($n['worked']) . " (" . $n['pct'] . "%)\n- Hours remaining: " . fmt_hours($n['remaining']) . "\n"
        . ($n['funds_limit'] ? "- Funds authorized: " . fmt_money($n['funds_auth']) . "\n- Funds used: " . fmt_money($n['funds_used']) . " (" . $n['funds_pct'] . "%), including " . fmt_money($n['other_costs']) . " of mileage and expenses\n- Funds remaining: " . fmt_money($n['funds_left']) . "\n"
            . "- The authorized funds, not the hours, are the limit: they will run out before the hours do.\n" : '')
        . ($n['run_out'] ? "- Projected run-out " . ($n['funds_limit'] ? 'of funds ' : '') . "at current pace: " . $n['run_out'] . "\n"
            : ($n['outlasts_expiry'] ? "- At the current pace the authorization period ends before the " . ($n['funds_limit'] ? 'funds' : 'hours') . " are used up\n" : ''))
        . ($n['exp_date'] ? ($n['expired'] ? "- Authorization already expired on: " : "- Authorization expires: ") . $n['exp_date'] . "\n" : '')
        . ($n['funds_limit'] ? "- Requesting about " . fmt_money($n['suggest_funds']) . " in additional authorized funds" : "- Requesting about " . $n['suggest_add'] . " additional hours") . ($n['exp_date'] ? " and a period extension" : "") . ".\n"
        . "- Counselor: [COUNSELOR]\n- Prepared by: [STAFF] at " . brand('org') . "\n";
}

/**
 * The amendment request letter drafted by AI from amendment_ai_facts() (4.37.0: moved from
 * amendment_request.php, shared with the apps' amendment). The prompt carries only facts and uses
 * [CLIENT], [COUNSELOR] and [STAFF], substituted back here: no client, counselor or staff name leaves
 * the system (the track title is free text, so with redaction on it is scrubbed of those names too).
 * $track needs client, counselor_name and counselor_email. Returns ['ok', 'text', 'error'].
 */
function amendment_ai_draft(array $track, string $facts, string $staffName, int $uid): array {
    $sys = ai_system_base() . ' Write a brief, professional authorization amendment request letter to a vocational rehabilitation counselor. '
        . 'Use [CLIENT] wherever the individual\'s name belongs, [COUNSELOR] for the counselor\'s name and [STAFF] for the sender\'s name, exactly as written — do not invent names. '
        . 'Facts only; no promises of outcomes. Keep it under 200 words.';
    $res = ai_run('draft', $sys, $facts, 600,
        ['name' => $track['client'], 'counselor_name' => $track['counselor_name'], 'counselor_email' => $track['counselor_email']], $uid);
    if (!$res['ok']) return ['ok' => false, 'text' => '', 'error' => (string)$res['error']];
    return ['ok' => true, 'error' => null, 'text' => str_replace(['[CLIENT]', '[COUNSELOR]', '[STAFF]'],
        [$track['client'], $track['counselor_name'] ?: 'Counselor', $staffName], (string)$res['text'])];
}
