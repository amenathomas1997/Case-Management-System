<?php
declare(strict_types=1);
// Spreadsheet downloads of the everyday lists (3.0.50). Each list keeps the permission and
// caseload limit of its own page: a caseload-scoped user gets rows for their own clients
// (and their own tasks and sessions), and a loaned item out with someone else's client
// shows "Another caseload" instead of the name, as the equipment page does.

/** The lists: key => [label, permission (null = everyone), what is in it]. */
const LIST_EXPORTS = [
    'clients' => ['Clients', 'clients', 'Every current client with status, type, instructor, counselor, contact details, date of birth, school and vision status.'],
    'referrals' => ['Referrals', 'referrals', 'Every referral with date, status, source, counselor and outcome.'],
    'tasks' => ['Tasks', null, 'Open and done tasks with client, due date and who they are assigned to.'],
    'sessions' => ['Sessions', 'schedule', 'Booked sessions from a year ago onwards, with status, instructor and setting.'],
    'authorizations' => ['Authorizations', 'clients', 'Every training track with its authorization, hours and dollars used, and dates.'],
    'equipment' => ['Equipment inventory', 'equipment', 'Every item with category, serial number, condition, value and status.'],
    'loans' => ['Equipment loans', 'equipment', 'Every loan with item, borrower, dates and whether it was returned.'],
    'followups' => ['Device follow-ups', 'clients', 'Device follow-up checks with due date, result and barriers.'],
    'incidents' => ['Incidents', 'clients', 'Incident reports with date, type, severity and status.'],
    'portal_requests' => ['Client portal requests', 'clients', 'Requests clients sent from their portal, with status and outcome.'],
    'contracts' => ['Grants and contracts', 'billing', 'Every grant or contract with funder, number, amount, dates and status.'],
];

/** Whether this account may download a list. */
function list_export_allowed(string $key): bool {
    if (!isset(LIST_EXPORTS[$key])) return false;
    $perm = LIST_EXPORTS[$key][1];
    return $perm === null || can($perm);
}

/** [headers, rows] for a list, limited to the user's caseload when they are scoped. */
function list_export_rows(string $key, int $uid): array {
    $s = caseload_scoped();
    $q = function (string $sql, array $args = []) { $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_NUM); };
    $mine = $s ? [$uid] : [];
    switch ($key) {
        case 'clients':
            return [['Name', 'Status', 'Client type', 'Instructor', 'VR counselor', 'Counselor agency', 'Counselor email', 'Email', 'Phone', 'Date of birth', 'School', 'Vision status', 'VR case number', 'Added'],
                $q("SELECT c.name, c.status, c.client_type, u.name, c.counselor_name, c.counselor_agency, c.counselor_email, c.email, c.phone, c.dob, c.school, c.vision_status, c.vr_case_number, DATE(c.created_at)
                    FROM clients c LEFT JOIN users u ON u.id = c.assigned_to WHERE c.archived_at IS NULL" . ($s ? ' AND c.assigned_to = ?' : '') . ' ORDER BY c.name', $mine)];
        case 'referrals':
            return [['Received', 'Client name', 'Status', 'Source', 'Requested service', 'Counselor', 'Counselor agency', 'Assigned to', 'Closed because', 'Closed'],
                $q("SELECT DATE(r.created_at), r.client_name, r.status, r.referral_source, r.requested_service, r.counselor_name, r.counselor_agency, u.name, r.closed_reason, DATE(r.closed_at)
                    FROM referrals r LEFT JOIN clients c ON c.id = r.client_id LEFT JOIN users u ON u.id = r.assigned_to"
                    // The referrals page's rule: a converted referral shows only to the client's current instructor.
                    . ($s ? " WHERE ((r.status <> 'Converted' AND (r.assigned_to = ? OR r.assigned_to IS NULL)) OR (r.status = 'Converted' AND c.assigned_to = ?))" : '')
                    . ' ORDER BY r.created_at DESC', $s ? [$uid, $uid] : [])];
        case 'tasks':
            // The task list's own rule (inc/tasks.php task_visibility_sql).
            $tp = [];
            $tw = task_visibility_sql(['id' => $uid], $tp);
            return [['Task', 'Client', 'Due', 'Status', 'Assigned to', 'Created', 'Done'],
                $q("SELECT t.title, c.name, t.due_date, t.status, u.name, DATE(t.created_at), DATE(t.done_at) FROM tasks t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN users u ON u.id = t.assigned_to
                    WHERE " . $tw . ' ORDER BY t.status, t.due_date', $tp)];
        case 'sessions':
            return [['Start', 'End', 'Client', 'Instructor', 'Status', 'Setting', 'Location', 'Track'],
                $q("SELECT s.start_at, s.end_at, c.name, u.name, s.status, IF(s.is_remote = 1, 'Remote', 'In person'), s.location, t.title FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
                    LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id WHERE s.start_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)"
                    . ($s ? ' AND c.assigned_to = ?' : '') . ' ORDER BY s.start_at', $mine)];
        case 'authorizations':
            return [['Client', 'Track', 'Status', 'Authorization', 'Funding agency', 'Who pays', 'Hours authorized', 'Hours used', 'Dollars authorized', 'Start', 'Expires', 'Ended'],
                $q("SELECT c.name, t.title, t.status, t.auth_number, t.funding_agency, t.billing_mode, t.authorized_hours,
                        (SELECT COALESCE(SUM(w.hours), 0) FROM work_logs w WHERE w.track_id = t.id), COALESCE(t.authorized_amount, ROUND(t.authorized_hours * t.hourly_rate, 2)),
                        t.start_date, t.expiration_date, DATE(t.completed_at)
                    FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE c.archived_at IS NULL" . ($s ? ' AND c.assigned_to = ?' : '') . ' ORDER BY c.name, t.start_date', $mine)];
        case 'equipment':
            return [['Item', 'Category', 'Serial or asset tag', 'Condition', 'Value', 'Purchased', 'Status', 'Last service'],
                $q('SELECT name, category, serial, item_condition, value, purchase_date, status, last_service_date FROM equipment ORDER BY name')];
        case 'loans':
            return [['Item', 'Serial or asset tag', 'Borrower', 'Loaned', 'Due', 'Returned'],
                $q("SELECT e.name, e.serial, " . ($s ? "IF(c.assigned_to = ?, c.name, 'Another caseload')" : 'c.name') . ", l.loaned_at, l.due_date, l.returned_at
                    FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id JOIN clients c ON c.id = l.client_id ORDER BY l.loaned_at DESC", $mine)];
        case 'followups':
            return [['Client', 'Device', 'Check', 'Due', 'Status', 'Still in use', 'Satisfaction (1 to 5)', 'Barriers', 'Recorded'],
                $q("SELECT c.name, e.name, CONCAT(f.interval_days, '-day'), f.due_date, f.status, CASE f.in_use WHEN 1 THEN 'Yes' WHEN 0 THEN 'No' ELSE '' END, f.satisfaction, f.barriers, DATE(f.completed_at)
                    FROM equipment_followups f JOIN clients c ON c.id = f.client_id JOIN equipment e ON e.id = f.equipment_id" . ($s ? ' WHERE c.assigned_to = ?' : '') . ' ORDER BY f.due_date', $mine)];
        case 'incidents':
            return [['Date', 'Client', 'Type', 'Severity', 'Location', 'Status', 'Reported by'],
                $q("SELECT i.incident_date, c.name, i.type, i.severity, i.location, i.status, u.name FROM incidents i LEFT JOIN clients c ON c.id = i.client_id LEFT JOIN users u ON u.id = i.reported_by"
                    // The incidents page's rule: an administrator sees all; others what they reported or on their caseload.
                    . (!can('admin') ? ' WHERE (i.reported_by = ? OR c.assigned_to = ?)' : '') . ' ORDER BY i.incident_date DESC', !can('admin') ? [$uid, $uid] : [])];
        case 'portal_requests':
            return [['Received', 'Client', 'Request', 'Status', 'Due', 'Outcome'],
                $q("SELECT DATE(p.created_at), c.name, p.type, p.status, p.due_on, p.outcome FROM portal_requests p JOIN clients c ON c.id = p.client_id"
                    . ($s ? ' WHERE c.assigned_to = ?' : '') . ' ORDER BY p.created_at DESC', $mine)];
        case 'contracts':
            return [['Name', 'Funder', 'Contract number', 'Amount', 'Start', 'End', 'Status'],
                $q('SELECT name, funder, contract_number, amount, start_date, end_date, status FROM contracts ORDER BY start_date DESC')];
    }
    return [[], []];
}

/** Send a list as a CSV download (audited) and stop. */
function list_export_send(string $key, int $uid, array $headers, array $rows, string $suffix = ''): never {
    audit($uid, 'report.export', 'client', null, LIST_EXPORTS[$key][0] . ' list CSV' . $suffix . ' (' . count($rows) . ' rows)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $key . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, $headers);
    foreach ($rows as $r) write_csv_row($out, array_map(fn($v) => is_string($v) ? csv_cell($v) : $v, $r));
    fclose($out);
    exit;
}
