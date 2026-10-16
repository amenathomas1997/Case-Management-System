<?php
declare(strict_types=1);

/** Reassign dependent records inside the caller's merge transaction. */
function move_client_records(PDO $pdo, int $keepId, int $mergeId): void {
    if (!$pdo->inTransaction()) throw new LogicException('Client merge requires a transaction.');
    if ($keepId <= 0 || $mergeId <= 0 || $keepId === $mergeId) throw new DomainException('Pick two different clients.');
    $lock = $pdo->prepare('SELECT id FROM clients WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
    $lock->execute([$keepId, $mergeId]);
    if (count($lock->fetchAll()) !== 2) throw new DomainException('One of these clients no longer exists.');

    // Membership carries its dates only. Preserve the earlier join date; the merged
    // person is a current member if either record is, otherwise left at the later date.
    $pdo->prepare('INSERT INTO cohort_members (cohort_id, client_id, added_at, left_at)
        SELECT src.cohort_id, ?, src.added_at, src.left_at FROM cohort_members src WHERE src.client_id = ?
        ON DUPLICATE KEY UPDATE added_at = LEAST(cohort_members.added_at, VALUES(added_at)),
            left_at = IF(cohort_members.left_at IS NULL OR VALUES(left_at) IS NULL, NULL, GREATEST(cohort_members.left_at, VALUES(left_at)))')->execute([$keepId, $mergeId]);
    $pdo->prepare('DELETE FROM cohort_members WHERE client_id = ?')->execute([$mergeId]);
    // Either record attending means the merged person attended; keep one attendance.
    $pdo->prepare('INSERT INTO cohort_attendance (session_id, client_id, present)
        SELECT src.session_id, ?, src.present FROM cohort_attendance src WHERE src.client_id = ?
        ON DUPLICATE KEY UPDATE present = GREATEST(cohort_attendance.present, VALUES(present))')->execute([$keepId, $mergeId]);
    $pdo->prepare('DELETE FROM cohort_attendance WHERE client_id = ?')->execute([$mergeId]);

    // The duplicate's status history is a separate timeline: mark its rows so the
    // reports' "last status change in the period" rule judges the kept client by
    // its own history only (note is varchar(255); the prefix takes 9 of it).
    $pdo->prepare("UPDATE client_status_history SET note = CONCAT('[merged] ', LEFT(COALESCE(note, ''), 246))
        WHERE client_id = ? AND (note IS NULL OR note NOT LIKE '[merged] %')")->execute([$mergeId]);

    foreach (CLIENT_MERGE_TABLES as $table) {
        $pdo->prepare("UPDATE `$table` SET client_id = ? WHERE client_id = ?")->execute([$keepId, $mergeId]);
    }
    // A privacy incident that lists both records keeps one entry (3.0.38); the duplicate's
    // own entries move to the kept record.
    try {
        $pdo->prepare('UPDATE IGNORE privacy_incident_clients SET client_id = ? WHERE client_id = ?')->execute([$keepId, $mergeId]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S02') throw $e; // mig_3.0.38 not run yet
    }
}

// Sessions move before training tracks: completing a session locks the session
// row and then its track, so the merge takes them in the same order.
const CLIENT_MERGE_TABLES = [
    'client_notes', 'scheduled_sessions', 'training_tracks', 'tech_assessments', 'client_documents',
    'equipment_loans', 'equipment_followups', 'messages',
    'incidents', 'consent_forms', 'surveys', 'report_acks', 'client_status_history',
    'referrals', 'tasks', 'client_scored_assessments', 'access_bookings',
    'client_competency', 'device_requests', 'equipment_reservations',
    'portal_requests', 'worksite_assessments', 'at_records', 'client_closures',
    'client_note_revisions', 'client_releases',
];

// Blank profile fields on the kept record that the duplicate can fill. Each fill
// is shown and chosen on the confirmation; the three email addresses (which then
// receive session reminders, portal links, progress reports and guardian mail)
// are never filled unless ticked.
const MERGE_FILL_FIELDS = ['dob' => 'Date of birth', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address',
    'school' => 'School / district', 'guardian_name' => 'Guardian name', 'guardian_phone' => 'Guardian phone',
    'guardian_email' => 'Guardian email', 'counselor_name' => 'Counselor name', 'counselor_email' => 'Counselor email',
    'counselor_phone' => 'Counselor phone', 'counselor_agency' => 'Counselor agency', 'assigned_to' => 'Assigned instructor'];
const MERGE_FILL_OPT_IN = ['email', 'guardian_email', 'counselor_email'];
/** The fields blank on $t and set on $s, as field => duplicate's value. */
function merge_fillable(array $t, array $s): array {
    $out = [];
    foreach (array_keys(MERGE_FILL_FIELDS) as $f) {
        if ((($t[$f] ?? null) === null || $t[$f] === '') && ($s[$f] ?? null) !== null && $s[$f] !== '') $out[$f] = $s[$f];
    }
    return $out;
}

/**
 * After a commit that moved one client's upcoming sessions ($sessions: rows with
 * start_at and end_at) from instructor $fromId to $toId (a reassignment on the
 * edit page, or a merge): the new instructor is emailed the list and told in the
 * app, the previous one is emailed a notice that they were moved. Calendar
 * invitations went to the previous instructor and no invitation id is kept, so
 * this plain text is the only notice. Only the dates, times and the client's
 * first name go into the email. Inactive accounts and the person who made the
 * change are not written to. Returns ['to' => bool, 'from' => bool]: who was told.
 */
function send_session_move_notices(array $sessions, ?int $fromId, ?int $toId, string $clientName, int $actorId): array {
    $told = ['to' => false, 'from' => false]; // who was told, for the page's message
    if (!$sessions || $fromId === null || $toId === null || $fromId === $toId) return $told;
    $q = db()->prepare('SELECT id, name, email FROM users WHERE id IN (?, ?) AND is_active = 1');
    $q->execute([$fromId, $toId]);
    $people = [];
    foreach ($q->fetchAll() as $u) $people[(int)$u['id']] = $u;
    $first = explode(' ', trim($clientName))[0];
    if ($first === '') $first = 'the client';
    $lines = [];
    foreach (array_slice($sessions, 0, 50) as $s) {
        $start = strtotime((string)$s['start_at']);
        $end = strtotime((string)($s['end_at'] ?? ''));
        $lines[] = '- ' . ($start !== false ? date('D, M j, Y g:i A', $start) : (string)$s['start_at'])
            . ($end !== false ? ' to ' . date('g:i A', $end) : '');
    }
    if (count($sessions) > 50) $lines[] = '- and ' . (count($sessions) - 50) . ' more on the Schedule page';
    $n = count($sessions);
    $what = $n . ' upcoming session' . ($n === 1 ? '' : 's') . ' with ' . $first;
    $fromName = (string)($people[$fromId]['name'] ?? 'the previous instructor');
    if (isset($people[$toId]) && $toId !== $actorId) {
        $told['to'] = true;
        send_app_mail((string)$people[$toId]['email'], 'Upcoming sessions were moved to you',
            "Hello {$people[$toId]['name']},\n\n{$what} " . ($n === 1 ? 'was' : 'were') . " moved from {$fromName} to you in " . cfg('name') . ":\n\n"
            . implode("\n", $lines) . "\n\n"
            . 'The full details are on your Schedule page: ' . cfg('base_url') . "/schedule.php\n"
            . "Calendar invitations for these sessions went to {$fromName}, so they are not in your calendar yet: add them, or subscribe to your calendar address under My Account.\n");
        notify_user($toId, $what . ' ' . ($n === 1 ? 'was' : 'were') . ' moved to you from ' . $fromName . '.', 'schedule.php');
    }
    if (isset($people[$fromId]) && $fromId !== $actorId) {
        $told['from'] = send_app_mail((string)$people[$fromId]['email'], 'Your upcoming sessions were moved to another instructor',
            "Hello {$people[$fromId]['name']},\n\n{$what} " . ($n === 1 ? 'was' : 'were') . ' moved to ' . (string)($people[$toId]['name'] ?? 'another instructor') . ' in ' . cfg('name') . ".\n"
            . "Remove them from your calendar if they are there; they are no longer yours:\n\n"
            . implode("\n", $lines) . "\n");
    }
    return $told;
}
