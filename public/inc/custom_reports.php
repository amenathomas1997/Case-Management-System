<?php
declare(strict_types=1);
// The custom report builder (4.7.0, custom_reports.php): pick what to report on, the columns,
// the filters and an optional grouping, see it as a table, download it, and save it to run
// again. Only the fields listed here can be chosen; nothing typed reaches SQL except bound
// values. Every subject is limited to the caseload for someone who sees only their own
// (report_table), as the other reports are.

/**
 * Subjects: key => [label, FROM (with {rc} {rt} {ri} {rs} for the scoped tables), date column,
 * fields => [key => [label, SQL, kind ('text', 'number', 'money', 'date')]], status column or null].
 */
function custom_report_subjects(): array {
    return [
        'clients' => ['Clients', '{rc} c LEFT JOIN users u ON u.id = c.assigned_to', 'c.created_at', [
            'name' => ['Client', 'c.name', 'text'],
            'status' => ['Status', 'c.status', 'text'],
            'client_type' => ['Client type', 'c.client_type', 'text'],
            'state' => ['State', 'c.state', 'text'],
            'vision_status' => ['Vision status', 'c.vision_status', 'text'],
            'counselor_agency' => ['Counselor agency', 'c.counselor_agency', 'text'],
            'counselor_name' => ['Counselor', 'c.counselor_name', 'text'],
            'staff' => ['Assigned staff', 'u.name', 'text'],
            'created' => ['Added', 'DATE(c.created_at)', 'date'],
        ], 'c.status'],
        'tracks' => ['Training tracks and authorizations', '{rt} t JOIN clients c ON c.id = t.client_id LEFT JOIN users u ON u.id = c.assigned_to', 't.start_date', [
            'client' => ['Client', 'c.name', 'text'],
            'title' => ['Track', 't.title', 'text'],
            'service_type' => ['Service type', 't.service_type', 'text'],
            'status' => ['Status', 't.status', 'text'],
            'auth_number' => ['Authorization', 't.auth_number', 'text'],
            'funding_agency' => ['Funding agency', 't.funding_agency', 'text'],
            'authorized_hours' => ['Authorized hours', 't.authorized_hours', 'number'],
            'hours_used' => ['Hours logged', '(SELECT COALESCE(SUM(w.hours), 0) FROM work_logs w WHERE w.track_id = t.id)', 'number'],
            'hourly_rate' => ['Hourly rate', 't.hourly_rate', 'money'],
            'start_date' => ['Start', 't.start_date', 'date'],
            'expiration_date' => ['Ends', 't.expiration_date', 'date'],
            'staff' => ['Assigned staff', 'u.name', 'text'],
        ], 't.status'],
        'work' => ['Hours and mileage logged', 'work_logs w JOIN {rt} t ON t.id = w.track_id JOIN clients c ON c.id = t.client_id LEFT JOIN users u ON u.id = w.created_by', 'w.work_date', [
            'date' => ['Date', 'w.work_date', 'date'],
            'client' => ['Client', 'c.name', 'text'],
            'track' => ['Track', 't.title', 'text'],
            'funding_agency' => ['Funding agency', 't.funding_agency', 'text'],
            'service_type' => ['Service type', 'COALESCE(w.service_type, t.service_type)', 'text'],
            'hours' => ['Hours', 'w.hours', 'number'],
            'mileage' => ['Miles', 'w.mileage', 'number'],
            'value' => ['Value', 'ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)', 'money'],
            'billed' => ['Invoiced', "IF(w.is_billed = 1, 'Yes', 'No')", 'text'],
            'staff' => ['Logged by', 'u.name', 'text'],
        ], null],
        'invoices' => ['Invoices', '{ri} i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id', 'i.created_at', [
            'number' => ['Invoice', 'i.invoice_number', 'text'],
            'client' => ['Client', 'c.name', 'text'],
            'funding_agency' => ['Funding agency', 'i.funding_agency', 'text'],
            'status' => ['Status', 'i.status', 'text'],
            'total' => ['Total', 'i.total_amount', 'money'],
            'paid' => ['Paid', 'COALESCE(i.paid_amount, 0)', 'money'],
            'owed' => ['Still owed', "CASE WHEN i.status IN ('Submitted','Paid') THEN GREATEST(i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off, 0) ELSE 0 END", 'money'],
            'created' => ['Created', 'DATE(i.created_at)', 'date'],
            'submitted' => ['Sent', 'i.submitted_at', 'date'],
            'due' => ['Due', 'i.due_date', 'date'],
        ], 'i.status'],
        'sessions' => ['Scheduled sessions', '{rs} s JOIN clients c ON c.id = s.client_id LEFT JOIN users u ON u.id = s.user_id', 's.start_at', [
            'date' => ['Date', 'DATE(s.start_at)', 'date'],
            'time' => ['Time', 'TIME(s.start_at)', 'time'],
            'client' => ['Client', 'c.name', 'text'],
            'staff' => ['Instructor', 'u.name', 'text'],
            'status' => ['Status', 's.status', 'text'],
            'setting' => ['Setting', "IF(s.is_remote = 1, 'Remote', 'In person')", 'text'],
            'hours' => ['Booked hours', 'ROUND(TIMESTAMPDIFF(MINUTE, s.start_at, s.end_at) / 60, 2)', 'number'],
        ], 's.status'],
        'referrals' => ['Referrals', 'referrals r LEFT JOIN users u ON u.id = r.assigned_to', 'r.created_at', [
            'client' => ['Person referred', 'r.client_name', 'text'],
            'status' => ['Status', 'r.status', 'text'],
            'source' => ['Referred by', 'r.referral_source', 'text'],
            'agency' => ['Referrer\'s agency or organisation', 'r.counselor_agency', 'text'],
            'state' => ['State', 'r.client_state', 'text'],
            'quoted' => ['Quoted fee', 'r.quoted_fee', 'money'],
            'staff' => ['Assigned staff', 'u.name', 'text'],
            'created' => ['Received', 'DATE(r.created_at)', 'date'],
        ], 'r.status'],
    ];
}

/** The permission each subject needs beyond reports (the page each comes from asks for it). */
const CUSTOM_REPORT_PERMS = ['invoices' => 'billing', 'referrals' => 'referrals'];

/** The subjects this person may report on. */
function custom_report_allowed_subjects(): array {
    return array_filter(custom_report_subjects(), fn($k) => !isset(CUSTOM_REPORT_PERMS[$k]) || can(CUSTOM_REPORT_PERMS[$k]), ARRAY_FILTER_USE_KEY);
}

/** A report definition from user input, checked against the lists above (unknown keys dropped). */
function custom_report_clean(array $in): array {
    $subjects = custom_report_allowed_subjects();
    $subject = isset($subjects[$in['subject'] ?? '']) ? (string)$in['subject'] : 'clients';
    $fields = $subjects[$subject][3];
    $cols = array_values(array_filter(array_unique(array_map('strval', is_array($in['cols'] ?? null) ? $in['cols'] : [])), fn($c) => isset($fields[$c])));
    if (!$cols) $cols = array_slice(array_keys($fields), 0, 3);
    $group = isset($fields[$in['group'] ?? '']) && $fields[$in['group']][2] !== 'number' && $fields[$in['group']][2] !== 'money' ? (string)$in['group'] : '';
    $sort = isset($fields[$in['sort'] ?? '']) ? (string)$in['sort'] : '';
    $from = valid_date(is_string($in['from'] ?? null) ? $in['from'] : '') ?: '';
    $to = valid_date(is_string($in['to'] ?? null) ? $in['to'] : '') ?: '';
    if ($from !== '' && $to !== '' && $to < $from) [$from, $to] = [$to, $from];
    return ['subject' => $subject, 'cols' => $cols, 'group' => $group, 'sort' => $sort, 'desc' => !empty($in['desc']),
        'from' => $from, 'to' => $to,
        'status' => mb_substr(trim(is_string($in['status'] ?? null) ? $in['status'] : ''), 0, 60),
        'staff' => (int)($in['staff'] ?? 0),
        'agency' => mb_substr(trim(is_string($in['agency'] ?? null) ? $in['agency'] : ''), 0, 190)];
}

/**
 * Run a cleaned definition. Returns ['head' => labels, 'kinds' => kinds, 'rows' => rows of
 * values, 'total' => matching rows, 'grouped' => bool]. Limited to $limit rows.
 */
function custom_report_run(array $d, int $actor, int $limit = 500): array {
    $s = custom_report_allowed_subjects()[$d['subject']] ?? custom_report_subjects()['clients'];
    [, $from, $dateCol, $fields, $statusCol] = $s;
    $from = strtr($from, ['{rc}' => report_table('clients', $actor), '{rt}' => report_table('training_tracks', $actor),
        '{ri}' => report_table('invoices', $actor), '{rs}' => report_table('scheduled_sessions', $actor)]);
    $where = ['1=1']; $args = [];
    // Referrals are scoped exactly as the referrals page scopes them (referral_in_scope()): an open
    // one that is yours or unassigned; a converted one only for its client's current instructor.
    if ($d['subject'] === 'referrals' && caseload_scoped()) {
        $where[] = "((r.status <> 'Converted' AND (r.assigned_to = ? OR r.assigned_to IS NULL)) OR (r.status = 'Converted' AND EXISTS (SELECT 1 FROM clients cc WHERE cc.id = r.client_id AND cc.assigned_to = ?)))";
        array_push($args, $actor, $actor);
    }
    if ($d['from'] !== '') { $where[] = "$dateCol >= ?"; $args[] = $d['from']; }
    if ($d['to'] !== '') { $where[] = "$dateCol < DATE_ADD(?, INTERVAL 1 DAY)"; $args[] = $d['to']; }
    if ($d['status'] !== '' && $statusCol) { $where[] = "$statusCol = ?"; $args[] = $d['status']; }
    if ($d['staff'] > 0 && isset($fields['staff'])) { $where[] = 'u.id = ?'; $args[] = $d['staff']; }
    if ($d['agency'] !== '' && isset($fields['funding_agency'])) { $where[] = $fields['funding_agency'][1] . ' = ?'; $args[] = $d['agency']; }
    $sql = ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where);
    $cnt = db()->prepare('SELECT COUNT(*)' . $sql);
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();
    if ($d['group'] !== '') {
        // One row per value of the grouping field: how many, and the sum of each number chosen.
        $g = $fields[$d['group']];
        $sums = array_values(array_filter($d['cols'], fn($c) => in_array($fields[$c][2], ['number', 'money'], true)));
        $sel = [$g[1] . ' AS g', 'COUNT(*) AS n'];
        foreach ($sums as $i => $c) $sel[] = 'SUM(' . $fields[$c][1] . ') AS s' . $i;
        $q = db()->prepare('SELECT ' . implode(', ', $sel) . $sql . ' GROUP BY g ORDER BY ' . ($d['sort'] === $d['group'] || $d['sort'] === '' ? 'g' : 'n') . ($d['desc'] ? ' DESC' : '') . ' LIMIT ' . (int)$limit);
        $q->execute($args);
        $rows = array_map(fn($r) => array_merge([$r['g'], (int)$r['n']], array_map(fn($i) => $r['s' . $i], array_keys($sums))), $q->fetchAll());
        return ['head' => array_merge([$g[0], 'How many'], array_map(fn($c) => 'Total ' . mb_strtolower($fields[$c][0]), $sums)),
            'kinds' => array_merge([$g[2], 'number'], array_map(fn($c) => $fields[$c][2], $sums)), 'rows' => $rows, 'total' => count($rows), 'grouped' => true];
    }
    $sel = array_map(fn($c, $i) => $fields[$c][1] . ' AS c' . $i, $d['cols'], array_keys($d['cols']));
    $order = $d['sort'] !== '' ? $fields[$d['sort']][1] . ($d['desc'] ? ' DESC' : '') : $dateCol . ' DESC';
    $q = db()->prepare('SELECT ' . implode(', ', $sel) . $sql . ' ORDER BY ' . $order . ' LIMIT ' . (int)$limit);
    $q->execute($args);
    $rows = array_map(fn($r) => array_values($r), $q->fetchAll());
    return ['head' => array_map(fn($c) => $fields[$c][0], $d['cols']), 'kinds' => array_map(fn($c) => $fields[$c][2], $d['cols']), 'rows' => $rows, 'total' => $total, 'grouped' => false];
}

/** A value as the table and the download show it. */
function custom_report_cell($v, string $kind): string {
    if ($v === null || $v === '') return '';
    return match ($kind) {
        'money' => fmt_money((float)$v),
        'number' => ($n = rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.')) === '' || $n === '-' ? '0' : $n,
        'date' => fmt_date((string)$v),
        'time' => date('g:i A', strtotime('2000-01-01 ' . $v)),
        default => (string)$v,
    };
}

/** A value for the spreadsheet: numbers and money as plain numbers, so they add up. */
function custom_report_csv_value($v, string $kind): string {
    if ($v === null || $v === '') return '';
    return in_array($kind, ['number', 'money'], true) ? (string)round((float)$v, 2) : custom_report_cell($v, $kind);
}

/** The reports this person can open: their own, and those shared by anyone. */
function custom_reports_saved(int $actor): array {
    try {
        $q = db()->prepare('SELECT r.*, u.name AS owner_name FROM saved_reports r LEFT JOIN users u ON u.id = r.owner_id WHERE r.owner_id = ? OR r.shared = 1 ORDER BY r.name');
        $q->execute([$actor]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];   // before migration 4.7.0
    }
}
