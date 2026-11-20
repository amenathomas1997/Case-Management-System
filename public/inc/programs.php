<?php
declare(strict_types=1);
// Pre-ETS cohorts (4.37.0: moved from programs.php, shared with the apps' add_cohort, cohorts, cohort,
// log_cohort_session, cohort_choices, delete_cohort_session and set_cohort_active): the activities,
// a new cohort's fields, the coordinators offered, a logged session deleted, a cohort archived or
// reactivated, and a session's line. The page and the apps give the same sentences and audit.

// The five federally required Pre-ETS activities (Section 113), plus Other.
const PREETS_ACTIVITIES = [
    'Job Exploration Counseling',
    'Work-Based Learning',
    'Postsecondary Counseling',
    'Workplace Readiness',
    'Self-Advocacy Instruction',
    'Other',
];

/**
 * A new cohort's fields as programs.php checks them: the name (required, 190 characters), the school,
 * the start and end dates (optional, a real date from 2000 to five years ahead, the end not before the
 * start), the coordinator (an active staff member, or none) and notes (2,000 characters at most,
 * refused when longer). $unreadable lists the dates the browser could not read; $coordinatorDefault
 * is used when the input has no coordinator_id at all. Returns [errors, values].
 */
function cohort_input(array $in, array $unreadable = [], ?int $coordinatorDefault = null): array {
    $str = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : (is_int($in[$k] ?? null) ? (string)$in[$k] : '');
    $name = $str('name');
    $maxYear = (int)date('Y') + 5;
    $startIn = $str('start_date');
    $endIn = $str('end_date');
    $start = valid_date($startIn, 2000, $maxYear);
    $end = valid_date($endIn, 2000, $maxYear);
    $notes = str_replace("\r\n", "\n", $str('notes'));
    $errors = [];
    if ($name === '') $errors['name'] = 'Cohort name is required.';
    elseif (mb_strlen($name) > 190) $errors['name'] = 'Cohort name: use 190 characters or fewer.';
    if (in_array('start_date', $unreadable, true) || ($startIn !== '' && $start === null)) $errors['start_date'] = 'Enter the start date as a real date with a year from 2000 to ' . $maxYear . ' (for example 2026-09-15), or leave it blank.';
    if (in_array('end_date', $unreadable, true) || ($endIn !== '' && $end === null)) $errors['end_date'] = 'Enter the end date as a real date with a year from 2000 to ' . $maxYear . ' (for example 2026-09-15), or leave it blank.';
    elseif ($start !== null && $end !== null && $end < $start) $errors['end_date'] = 'The end date cannot be before the start date.';
    $coordinatorId = array_key_exists('coordinator_id', $in) ? ((int)$str('coordinator_id') ?: null) : $coordinatorDefault;
    if ($coordinatorId !== null) {
        $cu = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $cu->execute([$coordinatorId]);
        if (!$cu->fetchColumn()) $errors['coordinator_id'] = 'Choose an active staff member as the coordinator.';
    }
    // Owner decision (4.37.0): a longer note is refused, never cut.
    if (mb_strlen($notes) > 2000) $errors['notes'] = 'Notes: use 2,000 characters or fewer.';
    return [$errors, ['name' => $name, 'school' => mb_substr($str('school'), 0, 190) ?: null, 'coordinator_id' => $coordinatorId,
        'start_date' => $start, 'end_date' => $end, 'notes' => $notes !== '' ? $notes : null]];
}

/** The staff a cohort's coordinator can be chosen from: active users, by name. */
function cohort_coordinator_choices(): array {
    return array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name']], db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll());
}

/** One logged session as a line: "<activity>, <date>, <N> minutes[, topic <topic>]; <n> present". */
function cohort_session_text(array $s): string {
    return $s['activity'] . ', ' . fmt_date($s['session_date']) . ', ' . (int)$s['duration_min'] . ' minutes'
        . (trim((string)($s['topic'] ?? '')) !== '' ? ', topic ' . $s['topic'] : '') . '; ' . (int)($s['present_n'] ?? 0) . ' present.';
}

/** The confirmation once a session is logged. */
function cohort_session_logged_message(string $activity, string $date, int $minutes): string {
    return 'Session logged with attendance: ' . $activity . ', ' . fmt_date($date) . ', ' . $minutes . ' minutes. If anything is wrong, delete it under Recent sessions and log it again.';
}

/**
 * Delete a session logged by mistake, with its attendance (programs.php's Delete): the audit entry keeps
 * what was removed, and it can then be logged again correctly. Returns ['ok' => true, 'changed' =>
 * bool, 'message'] (an already deleted session changes nothing and says so).
 */
function cohort_session_delete(int $cohortId, int $sid, int $actor, string $channel = 'web'): array {
    db()->beginTransaction();
    $presentIds = [];
    try {
        $sq = db()->prepare('SELECT id, session_date, activity, duration_min FROM cohort_sessions WHERE id = ? AND cohort_id = ? FOR UPDATE');
        $sq->execute([$sid, $cohortId]);
        $sess = $sq->fetch();
        if ($sess) {
            $pq = db()->prepare('SELECT client_id FROM cohort_attendance WHERE session_id = ? AND present = 1 ORDER BY client_id');
            $pq->execute([$sid]);
            $presentIds = array_map('intval', $pq->fetchAll(PDO::FETCH_COLUMN));
            db()->prepare('DELETE FROM cohort_attendance WHERE session_id = ?')->execute([$sid]);
            db()->prepare('DELETE FROM cohort_sessions WHERE id = ?')->execute([$sid]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    if (!$sess) return ['ok' => true, 'changed' => false, 'message' => 'That session was already deleted, so nothing changed.'];
    audit($actor, 'cohort.session_delete', 'cohort', $cohortId, 'session #' . $sid . ': ' . $sess['activity'] . ' ' . $sess['session_date'] . ', '
        . (int)$sess['duration_min'] . ' min; present: ' . ($presentIds ? 'client #' . implode(', #', $presentIds) : 'nobody') . ($channel === 'mobile' ? ' (mobile)' : ''));
    return ['ok' => true, 'changed' => true, 'message' => 'Session deleted: ' . $sess['activity'] . ', ' . fmt_date($sess['session_date']) . '. Its hours are no longer in the report. Log it again below if it took place.'];
}

/**
 * Archive a cohort ($active false) or reactivate it. Only a cohort not already in that state changes,
 * so a double submit or a stale screen never does the opposite of what was asked, and racing
 * submits audit it once. Returns ['ok' => true, 'changed' => bool, 'message'].
 */
function cohort_set_active(int $cohortId, bool $active, int $actor, string $channel = 'web'): array {
    $to = $active ? 1 : 0;
    $upd = db()->prepare('UPDATE cohorts SET active = ? WHERE id = ? AND active <> ?');
    $upd->execute([$to, $cohortId, $to]);
    if ($upd->rowCount() !== 1) return ['ok' => true, 'changed' => false, 'message' => $active ? 'That cohort is already active.' : 'That cohort is already archived.'];
    $nq = db()->prepare('SELECT name FROM cohorts WHERE id = ?');
    $nq->execute([$cohortId]);
    audit($actor, $active ? 'cohort.reactivate' : 'cohort.archive', 'cohort', $cohortId, (string)$nq->fetchColumn() . ($channel === 'mobile' ? ' (mobile)' : ''));
    return ['ok' => true, 'changed' => true, 'message' => $active ? 'Cohort reactivated.' : 'Cohort archived.'];
}
