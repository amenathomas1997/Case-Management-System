<?php
declare(strict_types=1);
// A client's timeline (3.0.50): every dated case event, newest first, each linked to where
// it lives, and the days spent in each status. Only for staff who can open the client.

/** The client's events: each ['at' => datetime, 'what' => sentence, 'link' => page or null]. */
function client_timeline(int $cid): array {
    $ev = [];
    $add = function (string $sql, callable $fmt) use (&$ev, $cid) {
        try {
            $q = db()->prepare($sql); $q->execute([$cid]);
            foreach ($q->fetchAll() as $r) if (!empty($r['at'])) $ev[] = ['at' => (string)$r['at']] + $fmt($r);
        } catch (PDOException $e) { /* a table from a later migration: nothing to show yet */ }
    };
    $client = 'client.php?id=' . $cid;
    $add('SELECT created_at at, status, referral_source FROM referrals WHERE client_id = ?', fn($r) => ['what' => 'Referral received (' . $r['referral_source'] . ')', 'link' => null]);
    $add("SELECT changed_at at, old_status, new_status, note FROM client_status_history WHERE client_id = ? AND (note IS NULL OR note NOT LIKE '[merged] %')",
        fn($r) => ['what' => ($r['old_status'] === null ? 'Record created as ' . $r['new_status'] : ($r['old_status'] === $r['new_status'] ? 'Status confirmed as ' . $r['new_status'] : 'Status changed from ' . $r['old_status'] . ' to ' . $r['new_status'])) . ($r['note'] ? ': ' . $r['note'] : ''), 'link' => $client . '&tab=history']);
    $add('SELECT created_at at, title, auth_number FROM training_tracks WHERE client_id = ?', fn($r) => ['what' => 'Training track started: ' . $r['title'] . ' (authorization ' . $r['auth_number'] . ')', 'link' => null]);
    $add('SELECT completed_at at, id, title, status FROM training_tracks WHERE client_id = ?', fn($r) => ['what' => 'Training track ' . mb_strtolower($r['status']) . ': ' . $r['title'], 'link' => 'track.php?id=' . (int)$r['id']]);
    $add('SELECT w.work_date at, w.hours, w.description, t.id FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE t.client_id = ?',
        fn($r) => ['what' => 'Session logged, ' . fmt_hours((float)$r['hours']) . ($r['description'] ? ': ' . mb_strimwidth((string)$r['description'], 0, 120, '…') : ''), 'link' => 'track.php?id=' . (int)$r['id']]);
    $add("SELECT start_at at, status FROM scheduled_sessions WHERE client_id = ? AND status IN ('No Show', 'Cancelled')", fn($r) => ['what' => $r['status'] === 'No Show' ? 'Missed session (no-show)' : 'Session cancelled', 'link' => null]);
    $add('SELECT gp.created_at at, gp.status, gp.progress_pct, g.specific_goal, t.id FROM goal_progress gp JOIN smart_goals g ON g.id = gp.goal_id JOIN training_tracks t ON t.id = g.track_id WHERE t.client_id = ?',
        fn($r) => ['what' => 'Goal progress: ' . mb_strimwidth((string)$r['specific_goal'], 0, 100, '…') . ', ' . $r['status'] . ', ' . (int)$r['progress_pct'] . '%', 'link' => 'track.php?id=' . (int)$r['id']]);
    $add('SELECT a.assessed_on at, a.pct, i.name FROM client_scored_assessments a JOIN assessment_instruments i ON i.id = a.instrument_id WHERE a.client_id = ?',
        fn($r) => ['what' => 'Assessment scored: ' . $r['name'] . ', ' . (float)$r['pct'] . '%', 'link' => $client . '&tab=assessments#scored-h']);
    $add('SELECT assessment_date at, primary_tech FROM tech_assessments WHERE client_id = ?', fn($r) => ['what' => 'Technology assessment recorded (' . $r['primary_tech'] . ')', 'link' => $client . '&tab=assessments#addassess-h']);
    $add('SELECT l.loaned_at at, e.name FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id WHERE l.client_id = ?', fn($r) => ['what' => 'Device loaned: ' . $r['name'], 'link' => null]);
    $add('SELECT l.returned_at at, e.name FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id WHERE l.client_id = ?', fn($r) => ['what' => 'Device returned: ' . $r['name'], 'link' => null]);
    $add("SELECT f.completed_at at, f.interval_days, f.in_use, e.name FROM equipment_followups f JOIN equipment e ON e.id = f.equipment_id WHERE f.client_id = ? AND f.status = 'Completed'",
        fn($r) => ['what' => (int)$r['interval_days'] . '-day device follow-up: ' . $r['name'] . ($r['in_use'] === null ? '' : ((int)$r['in_use'] === 1 ? ', in use' : ', not in use')), 'link' => null]);
    $add('SELECT sent_at at, sent_to FROM report_acks WHERE client_id = ?', fn($r) => ['what' => 'Report emailed to ' . $r['sent_to'], 'link' => null]);
    $add('SELECT acked_at at FROM report_acks WHERE client_id = ?', fn($r) => ['what' => 'Counselor confirmed receiving a report', 'link' => null]);
    $add('SELECT sent_at at FROM consent_forms WHERE client_id = ?', fn($r) => ['what' => 'Guardian consent form sent', 'link' => null]);
    $add('SELECT signed_at at FROM consent_forms WHERE client_id = ?', fn($r) => ['what' => 'Guardian consent signed', 'link' => null]);
    $add('SELECT COALESCE(signed_at, sent_at) at, recipient_name, signed_at FROM client_releases WHERE client_id = ?', fn($r) => ['what' => 'Release of information ' . ($r['signed_at'] ? 'signed' : 'sent') . ' for ' . $r['recipient_name'], 'link' => $client . '#releases-h']);
    $add('SELECT COALESCE(signed_at, sent_at) at, title, signed_at FROM client_signatures WHERE client_id = ?', fn($r) => ['what' => $r['title'] . ($r['signed_at'] ? ' signed' : ' sent to sign'), 'link' => 'signatures.php?client=' . $cid]);
    // Incidents as the incidents page shows them: to an administrator, the person who reported
    // it, or the client's instructor.
    $add('SELECT i.incident_date at, i.type, i.severity FROM incidents i JOIN clients c ON c.id = i.client_id WHERE i.client_id = ?'
        . (can('admin') ? '' : ' AND (i.reported_by = ' . (int)(current_user()['id'] ?? 0) . ' OR c.assigned_to = ' . (int)(current_user()['id'] ?? 0) . ')'),
        fn($r) => ['what' => 'Incident: ' . $r['type'] . ' (' . $r['severity'] . ')', 'link' => null]);
    $add('SELECT created_at at, type FROM portal_requests WHERE client_id = ?', fn($r) => ['what' => 'Portal request: ' . $r['type'], 'link' => null]);
    $add('SELECT sent_at at, channel FROM surveys WHERE client_id = ? AND channel = \'Email\'', fn($r) => ['what' => 'Outcome survey emailed', 'link' => $client . '#after-h']);
    $add('SELECT completed_at at, satisfaction FROM surveys WHERE client_id = ?', fn($r) => ['what' => 'Outcome survey answered: satisfaction ' . (int)$r['satisfaction'] . ' of 10', 'link' => $client . '#after-h']);
    $add('SELECT closed_at at, reason FROM client_closures WHERE client_id = ?', fn($r) => ['what' => 'Case closed: ' . $r['reason'], 'link' => $client . '#after-h']);
    $add("SELECT completed_at at, interval_days FROM closure_followups WHERE client_id = ? AND status = 'Completed'", fn($r) => ['what' => (int)$r['interval_days'] . '-day closure follow-up recorded', 'link' => $client . '#after-h']);
    $add('SELECT created_at at, note_type, subject FROM client_notes WHERE client_id = ?', fn($r) => ['what' => $r['note_type'] . ': ' . mb_strimwidth((string)$r['subject'], 0, 120, '…'), 'link' => $client . '&tab=notes#notes-h']);
    // Date-only events (a session date) sort as the start of their day.
    usort($ev, fn($a, $b) => strcmp(strlen($b['at']) === 10 ? $b['at'] . ' 00:00:00' : $b['at'], strlen($a['at']) === 10 ? $a['at'] . ' 00:00:00' : $a['at']));
    return $ev;
}

/** Days in each status, from the status history: [status => days], and the total length of stay. */
function client_status_days(int $cid): array {
    $q = db()->prepare("SELECT new_status, changed_at FROM client_status_history WHERE client_id = ? AND (note IS NULL OR note NOT LIKE '[merged] %') ORDER BY changed_at, id");
    $q->execute([$cid]);
    $rows = $q->fetchAll();
    $days = [];
    foreach ($rows as $i => $r) {
        $end = $rows[$i + 1]['changed_at'] ?? date('Y-m-d H:i:s');
        $d = (int)(new DateTime(substr((string)$r['changed_at'], 0, 10)))->diff(new DateTime(substr((string)$end, 0, 10)))->days;
        $days[$r['new_status']] = ($days[$r['new_status']] ?? 0) + $d;
    }
    $total = $rows ? (int)(new DateTime(substr((string)$rows[0]['changed_at'], 0, 10)))->diff(new DateTime('today'))->days : 0;
    return ['days' => $days, 'total' => $total, 'since' => $rows[0]['changed_at'] ?? null];
}
