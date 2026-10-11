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
}

const CLIENT_MERGE_TABLES = [
    'client_notes', 'training_tracks', 'tech_assessments', 'client_documents',
    'scheduled_sessions', 'equipment_loans', 'equipment_followups', 'messages',
    'incidents', 'consent_forms', 'surveys', 'report_acks', 'client_status_history',
    'referrals', 'tasks', 'client_scored_assessments', 'access_bookings',
    'client_competency', 'device_requests', 'equipment_reservations',
    'portal_requests', 'worksite_assessments', 'at_records',
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
