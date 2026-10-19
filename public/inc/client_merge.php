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

    // One live hold per person and item (4.29.0): where both records hold or wait for the same
    // item, the stronger entry stays (a reservation over a waitlist place, then the older one) and
    // the other is cancelled, so cancelling the person's hold later never re-holds it for them.
    $holds = $pdo->prepare("SELECT id, equipment_id, client_id, status, created_at FROM equipment_reservations
        WHERE client_id IN (?, ?) AND status IN ('Reserved','Waitlist') ORDER BY equipment_id, id FOR UPDATE");
    $holds->execute([$keepId, $mergeId]);
    $byItem = [];
    foreach ($holds->fetchAll() as $h) $byItem[(int)$h['equipment_id']][] = $h;
    $cancelHold = $pdo->prepare("UPDATE equipment_reservations SET status = 'Cancelled' WHERE id = ?");
    foreach ($byItem as $rows) {
        if (count(array_unique(array_map(fn($r) => (int)$r['client_id'], $rows))) < 2) continue;
        usort($rows, fn($a, $b) => [$a['status'] === 'Reserved' ? 0 : 1, (string)$a['created_at'], (int)$a['id']]
            <=> [$b['status'] === 'Reserved' ? 0 : 1, (string)$b['created_at'], (int)$b['id']]);
        foreach (array_slice($rows, 1) as $r) $cancelHold->execute([(int)$r['id']]);
    }

    foreach (CLIENT_MERGE_TABLES as $table) {
        $pdo->prepare("UPDATE `$table` SET client_id = ? WHERE client_id = ?")->execute([$keepId, $mergeId]);
    }
    // Custom fields (4.24.0): one answer per field; the kept record's own answer stays, the duplicate's
    // fills in only the fields it left empty.
    try {
        $pdo->prepare('UPDATE IGNORE custom_values SET client_id = ? WHERE client_id = ?')->execute([$keepId, $mergeId]);
        $pdo->prepare('DELETE FROM custom_values WHERE client_id = ?')->execute([$mergeId]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S02') throw $e; // mig_4.24.0 not run yet
    }
    // The duplicate's audit entries follow the person (4.29.0), so a later purge of the kept
    // record clears the details they carry (name, note subjects, guardian email) too.
    $pdo->prepare("UPDATE audit_log SET entity_id = ? WHERE entity = 'client' AND entity_id = ?")->execute([$keepId, $mergeId]);
    // A STOP on either record holds for the merged one (4.17.0): texts never resume after it.
    $pdo->prepare('UPDATE clients k JOIN clients m ON m.id = ? SET k.sms_opted_out_at = COALESCE(k.sms_opted_out_at, m.sms_opted_out_at) WHERE k.id = ?')->execute([$mergeId, $keepId]);
    // A privacy incident that lists both records keeps one entry (3.0.38); the duplicate's
    // own entries move to the kept record.
    try {
        // An incident that listed both (4.28.0): the kept entry takes the earlier notification
        // date, and the duplicate's entry is removed, so the person is counted once and can be
        // marked notified (deleting the duplicate client would otherwise leave an orphan row).
        $pdo->prepare('UPDATE privacy_incident_clients k JOIN privacy_incident_clients d ON d.incident_id = k.incident_id AND d.client_id = ?
            SET k.notified_at = CASE WHEN k.notified_at IS NULL THEN d.notified_at WHEN d.notified_at IS NULL THEN k.notified_at ELSE LEAST(k.notified_at, d.notified_at) END
            WHERE k.client_id = ?')->execute([$mergeId, $keepId]);
        $pdo->prepare('UPDATE IGNORE privacy_incident_clients SET client_id = ? WHERE client_id = ?')->execute([$keepId, $mergeId]);
        $pdo->prepare('DELETE FROM privacy_incident_clients WHERE client_id = ?')->execute([$mergeId]);
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
    'client_note_revisions', 'client_releases', 'client_signatures', 'amendment_requests', 'closure_followups', 'incoming_authorizations', 'sms_log',
];

// Blank profile fields on the kept record that the duplicate can fill. Each fill
// is shown and chosen on the confirmation; the three email addresses (which then
// receive session reminders, portal links, progress reports and guardian mail)
// are never filled unless ticked.
const MERGE_FILL_FIELDS = ['dob' => 'Date of birth', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address',
    'school' => 'School / district', 'guardian_name' => 'Guardian name', 'guardian_phone' => 'Guardian phone',
    'guardian_email' => 'Guardian email', 'counselor_name' => 'Counselor name', 'counselor_email' => 'Counselor email',
    'counselor_phone' => 'Counselor phone', 'counselor_agency' => 'Counselor agency', 'assigned_to' => 'Assigned instructor', 'state' => 'State', 'office_id' => 'Office',
    // 4.27.0: the plan, vision, time zone, language and privacy notice were dropped by a merge before.
    'vr_case_number' => 'VR case number', 'ipe_goal' => 'IPE employment goal', 'ipe_date' => 'IPE date', 'ipe_amended_on' => 'IPE amendment date',
    'vision_status' => 'Vision status', 'vision_onset_age' => 'Age when vision loss began', 'time_zone' => 'Time zone',
    'preferred_language' => 'Preferred language', 'privacy_notice_at' => 'Privacy notice given'];
const MERGE_FILL_OPT_IN = ['email', 'guardian_email', 'counselor_email'];
/** Fields copied together with the one named: the privacy notice is one fact (when, by whom, how). */
const MERGE_FILL_WITH = ['privacy_notice_at' => ['privacy_notice_by', 'privacy_notice_how']];
/** The fields blank on $t and set on $s, as field => duplicate's value. The preferred
 *  language is never empty: English, the default, counts as blank when the duplicate
 *  has another language. */
function merge_fillable(array $t, array $s): array {
    $out = [];
    foreach (array_keys(MERGE_FILL_FIELDS) as $f) {
        if (!array_key_exists($f, $s)) continue; // a column from a later migration
        $blank = ($t[$f] ?? null) === null || $t[$f] === '' || ($f === 'preferred_language' && $t[$f] === 'en');
        if ($blank && ($s[$f] ?? null) !== null && $s[$f] !== '' && !($f === 'preferred_language' && $s[$f] === 'en')) $out[$f] = $s[$f];
    }
    return $out;
}
/** The chosen fills as column => value, with the columns that travel with them. */
function merge_fill_columns(array $fill, array $s): array {
    foreach (MERGE_FILL_WITH as $f => $with) {
        if (!isset($fill[$f])) continue;
        foreach ($with as $w) if (array_key_exists($w, $s)) $fill[$w] = $s[$w];
    }
    return $fill;
}
/** The legal hold the kept record takes from the duplicate (4.27.0): the duplicate's
 *  hold_at, hold_by and hold_reason when the kept record has none, else []. */
function merge_hold_carry(array $t, array $s): array {
    if (!array_key_exists('hold_at', $s) || empty($s['hold_at']) || !empty($t['hold_at'])) return [];
    return ['hold_at' => $s['hold_at'], 'hold_by' => $s['hold_by'] ?? null, 'hold_reason' => $s['hold_reason'] ?? null];
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
