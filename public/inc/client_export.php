<?php
declare(strict_types=1);
require_once __DIR__ . '/at_services.php';

/** Complete structured case data. Caller must authorize access to this client first. */
function client_export_records(PDO $pdo, int $clientId): array {
    $client = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $client->execute([$clientId]);
    $row = $client->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('Client not found.');
    $result = ['client' => $row];
    foreach (CLIENT_EXPORT_TABLES as $table) {
        $query = $pdo->prepare("SELECT * FROM `$table` WHERE client_id = ? ORDER BY id");
        $query->execute([$clientId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        // Export records, never reusable access links or replay keys.
        foreach ($rows as &$record) unset($record['token'], $record['token_hash'], $record['idem_key']);
        unset($record);
        $result[$table] = $rows;
    }
    // Invoices are billing records, shown in the app only with billing access.
    $trackTables = ['smart_goals', 'work_logs', 'track_expenses', 'homework', 'auth_amendments'];
    if (can('billing')) $trackTables[] = 'invoices';
    else $result['invoices_not_included'] = 'Invoices are not in this export: they need billing access, which this account does not have.';
    foreach ($trackTables as $table) {
        $query = $pdo->prepare("SELECT r.* FROM `$table` r JOIN training_tracks t ON t.id = r.track_id WHERE t.client_id = ? ORDER BY r.id");
        $query->execute([$clientId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$record) unset($record['idem_key']);
        unset($record);
        $result[$table] = $rows;
    }
    foreach ([
        'goal_progress' => 'SELECT r.* FROM goal_progress r JOIN smart_goals g ON g.id=r.goal_id JOIN training_tracks t ON t.id=g.track_id WHERE t.client_id=? ORDER BY r.id',
        'client_scored_assessment_items' => 'SELECT r.*, i.label FROM client_scored_assessment_items r JOIN client_scored_assessments a ON a.id=r.scored_id JOIN assessment_instrument_items i ON i.id=r.item_id WHERE a.client_id=? ORDER BY r.id',
        'device_request_quotes' => 'SELECT r.* FROM device_request_quotes r JOIN device_requests d ON d.id=r.request_id WHERE d.client_id=? ORDER BY r.id',
        'cohort_sessions' => 'SELECT DISTINCT s.id, s.cohort_id, s.session_date, s.activity, s.topic, s.duration_min FROM cohort_sessions s JOIN cohort_attendance a ON a.session_id=s.id WHERE a.client_id=? ORDER BY s.id',
    ] as $key => $sql) {
        $query = $pdo->prepare($sql); $query->execute([$clientId]);
        $result[$key] = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    // Internal labor costs are administrative data, not part of a client case export.
    $q = $pdo->prepare("SELECT * FROM at_records WHERE client_id=? AND kind NOT IN ('cost','rate') ORDER BY id");
    $q->execute([$clientId]);
    // Each kind goes only to staff who could open it in the app (as at_services
    // does): authorization-to-payment ('funding') records need billing access.
    $catalog = at_catalog();
    $atRows = $q->fetchAll(PDO::FETCH_ASSOC);
    $result['at_services'] = array_values(array_filter($atRows,
        fn($r) => isset($catalog[$r['kind']]) && at_can((string)$catalog[$r['kind']]['perm'])));
    if (($omitted = count($atRows) - count($result['at_services'])) > 0) {
        $result['at_services_not_included'] = $omitted . ' assistive-technology service record(s) of a kind your account cannot open (for example authorization-to-payment records, which need billing access) are not in this export.';
    }
    foreach ($result['at_services'] as &$service) {
        unset($service['source_key']);
        $service['details'] = json_decode($service['details'], true);
    }
    unset($service);
    return $result;
}

const CLIENT_EXPORT_TABLES = [
    'client_notes', 'training_tracks', 'tech_assessments', 'client_documents',
    'scheduled_sessions', 'equipment_loans', 'equipment_followups', 'messages',
    'incidents', 'consent_forms', 'surveys', 'report_acks', 'client_status_history',
    'referrals', 'tasks', 'client_scored_assessments', 'access_bookings',
    'client_competency', 'device_requests', 'equipment_reservations',
    'portal_requests', 'worksite_assessments', 'cohort_members', 'cohort_attendance',
];
