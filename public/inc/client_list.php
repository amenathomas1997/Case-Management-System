<?php
declare(strict_types=1);
// The client list's filters (4.35.0: moved from clients.php, shared with the app's clients and
// clients_filter): case status, caseload, client type, funding agency, school and office. The
// caseload choice is remembered per user in the setting clients_filter_<user id>, the same one
// for the website and the apps.

/** The instructors offered as caseloads: anyone who sees every caseload gets each other active
 *  instructor with clients; caseload-scoped staff get none. */
function client_list_instructors(array $user): array {
    if (caseload_scoped()) return [];
    return array_values(array_filter(db()->query("SELECT DISTINCT u.id, u.name FROM users u JOIN clients c ON c.assigned_to = u.id WHERE u.is_active = 1 AND c.archived_at IS NULL ORDER BY u.name")->fetchAll(),
        fn($i) => (int)$i['id'] !== (int)$user['id']));
}

/** The Caseload choices as [value, label] pairs, in the form's order. */
function client_list_who_choices(array $user): array {
    $out = [['', 'Everyone\'s clients'], ['me', 'My clients'], ['unassigned', 'Unassigned']];
    foreach (client_list_instructors($user) as $i) $out[] = ['u' . (int)$i['id'], (string)$i['name']];
    return $out;
}

/** The setting that remembers a user's caseload choice. */
function client_list_who_key(array $user): string {
    return 'clients_filter_' . (int)$user['id'];
}

/** The caseload to show: the one sent when it is one of the choices, else the one remembered,
 *  else "My clients" for caseload-scoped staff and "Everyone's clients" for the rest. */
function client_list_who(array $user, ?string $sent): string {
    $values = array_column(client_list_who_choices($user), 0);
    if ($sent !== null && in_array($sent, $values, true)) return $sent;
    $saved = setting(client_list_who_key($user));
    return $saved !== null && in_array($saved, $values, true) ? $saved : (caseload_scoped() ? 'me' : '');
}

/** What each filter offers: the statuses, caseloads, client types, funding agencies (every one
 *  on a track except blank, Private pay and No charge) and, when offices are on, the offices. */
function client_list_filters(array $user): array {
    return [
        'statuses' => array_values(client_statuses()),
        'who_choices' => array_map(fn($c) => ['value' => $c[0], 'label' => $c[1]], client_list_who_choices($user)),
        'types' => array_values(cms_list_items('client_types')),
        'agencies' => array_map('strval', db()->query("SELECT DISTINCT funding_agency FROM training_tracks WHERE funding_agency NOT IN ('', 'Private pay', 'No charge') ORDER BY funding_agency")->fetchAll(PDO::FETCH_COLUMN)),
        'offices' => offices_on() ? array_values(array_map(fn($o) => ['id' => (int)$o['id'], 'name' => (string)$o['name']], array_filter(offices_all(), fn($o) => (int)$o['is_active'] === 1))) : [],
    ];
}

/**
 * The SQL for the filters, on the clients table as c: [" AND …", params]. $f holds status, who,
 * type, agency, school and office as sent (a value that is not one of the choices filters
 * nothing, as on the website). Caseload-scoped staff always see only their own clients.
 */
function client_list_where(array $f, array $user): array {
    $str = fn(string $k): string => is_string($f[$k] ?? null) ? $f[$k] : '';
    $sql = '';
    $params = [];
    $statusFilter = $str('status');
    if (in_array($statusFilter, client_statuses(), true)) { $sql .= ' AND c.status = ?'; $params[] = $statusFilter; }
    $who = $str('who');
    if ($who === 'me') { $sql .= ' AND c.assigned_to = ?'; $params[] = $user['id']; }
    elseif ($who === 'unassigned') { $sql .= ' AND c.assigned_to IS NULL'; }
    elseif (str_starts_with($who, 'u')) { $sql .= ' AND c.assigned_to = ?'; $params[] = (int)substr($who, 1); }
    // Client type, funding agency (any of the client's tracks) and school (3.0.50).
    $typeFilter = in_array($f['type'] ?? '', cms_list_items('client_types'), true) ? $f['type'] : '';
    $agencyFilter = trim($str('agency'));
    $schoolFilter = trim($str('school'));
    if ($typeFilter !== '') { $sql .= ' AND c.client_type = ?'; $params[] = $typeFilter; }
    if ($agencyFilter !== '') { $sql .= ' AND EXISTS (SELECT 1 FROM training_tracks ft WHERE ft.client_id = c.id AND ft.funding_agency = ?)'; $params[] = $agencyFilter; }
    if ($schoolFilter !== '') { $sql .= ' AND c.school LIKE ?'; $params[] = '%' . $schoolFilter . '%'; }
    // One office's clients (4.22.0).
    $officeFilter = client_list_office($f);
    if ($officeFilter > 0) { $sql .= ' AND c.office_id = ?'; $params[] = $officeFilter; }
    if (caseload_scoped()) { $sql .= ' AND c.assigned_to = ?'; $params[] = $user['id']; }
    return [$sql, $params];
}

/** The office filter as sent, when offices are on and it is one: its id, else 0. */
function client_list_office(array $f): int {
    $o = $f['office'] ?? null;
    if (is_int($o)) $o = (string)$o;
    return offices_on() && is_string($o) && ctype_digit($o) && office_get((int)$o) ? (int)$o : 0;
}
