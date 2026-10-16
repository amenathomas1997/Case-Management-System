<?php
declare(strict_types=1);
// Management reports and data quality (3.0.49): the fiscal year, the business summary with
// comparisons, attendance and no-show rates, unused authorizations, and the data-quality
// checks with duplicate warnings. Every figure here is caseload-scoped by the caller
// through report_table() or the $scopeUser argument.

/** The month the organisation's fiscal year starts (1 to 12; Administration, Reports). October, the federal year, by default. */
function fiscal_year_start_month(): int {
    $m = (int)(setting('fiscal_year_start') ?? 10);
    return $m >= 1 && $m <= 12 ? $m : 10;
}

/** [from, to] of the fiscal year containing $date. */
function fiscal_year_of(string $date): array {
    $start = fiscal_year_start_month();
    $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2);
    $fy = $m >= $start ? $y : $y - 1;
    $from = sprintf('%04d-%02d-01', $fy, $start);
    return [$from, date('Y-m-d', strtotime($from . ' +1 year -1 day'))];
}

/** Track status changes: an ended track (Completed or Cancelled) keeps the first time it ended; reopened, the date is cleared. */
function track_mark_ended(int $trackId, string $status): void {
    try {
        db()->prepare("UPDATE training_tracks SET completed_at = CASE WHEN ? IN ('Completed', 'Cancelled') THEN COALESCE(completed_at, NOW()) ELSE NULL END WHERE id = ?")
            ->execute([$status, $trackId]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S22') throw $e; // mig_3.0.49 not run yet
    }
}

/** The same length of time just before [from, to], and the same dates a year earlier. */
function comparison_periods(string $from, string $to): array {
    $days = (int)(new DateTime($from))->diff(new DateTime($to))->days;
    $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
    return [
        'previous' => [date('Y-m-d', strtotime($prevTo . ' -' . $days . ' days')), $prevTo],
        // A month's last day maps to that month's last day a year earlier (February 29 to the 28th).
        'last_year' => [date('Y-m-d', strtotime($from . ' -1 year')),
            $to === date('Y-m-t', strtotime($to)) ? date('Y-m-t', strtotime(substr($to, 0, 7) . '-01 -1 year')) : date('Y-m-d', strtotime($to . ' -1 year'))],
    ];
}

/**
 * The business figures for a period: referrals received and converted, new clients, clients
 * served, sessions held and missed, hours, value, cases closed and rehabilitated. $rt, $rc
 * $rh and $rs (scheduled_sessions) are the caseload-scoped tables; $scopeUser limits referrals to an instructor's.
 */
function business_figures(string $from, string $to, string $rt, string $rc, string $rh, string $rs, ?int $scopeUser): array {
    $one = function (string $sql, array $p) { $q = db()->prepare($sql); $q->execute($p); return $q->fetch(); };
    // A converted referral counts for its client's instructor now, as the referrals page scopes it.
    $ref = $one("SELECT COUNT(*) n, COALESCE(SUM(r.status = 'Converted'), 0) conv FROM referrals r LEFT JOIN clients c ON c.id = r.client_id WHERE DATE(r.created_at) BETWEEN ? AND ?"
        . ($scopeUser ? ' AND COALESCE(c.assigned_to, r.assigned_to) = ?' : ''), $scopeUser ? [$from, $to, $scopeUser] : [$from, $to]);
    $newClients = $one("SELECT COUNT(*) n FROM {$rc} c WHERE DATE(c.created_at) BETWEEN ? AND ?", [$from, $to]);
    $work = $one("SELECT COUNT(DISTINCT t.client_id) served, COALESCE(SUM(w.hours), 0) hrs,
            COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)), 0) val
        FROM work_logs w JOIN {$rt} t ON t.id = w.track_id WHERE w.work_date BETWEEN ? AND ?", [$from, $to]);
    $ses = $one("SELECT COALESCE(SUM(s.status = 'Completed'), 0) done, COALESCE(SUM(s.status = 'No Show'), 0) noshow, COALESCE(SUM(s.status = 'Cancelled'), 0) cancelled
        FROM {$rs} s WHERE DATE(s.start_at) BETWEEN ? AND ?", [$from, $to]);
    $m = outcome_metrics($from, $to, $rt, $rh);
    $held = (int)$ses['done'] + (int)$ses['noshow'];
    return [
        'referrals' => (int)$ref['n'], 'referrals_converted' => (int)$ref['conv'], 'new_clients' => (int)$newClients['n'],
        'clients_served' => (int)$work['served'], 'hours' => round((float)$work['hrs'], 2), 'value' => round((float)$work['val'], 2),
        'sessions_completed' => (int)$ses['done'], 'no_shows' => (int)$ses['noshow'], 'cancelled' => (int)$ses['cancelled'],
        'no_show_rate' => $held ? (int)round(100 * (int)$ses['noshow'] / $held) : null,
        'cases_closed' => (int)$m['closedTotal'], 'rehabilitated' => (int)$m['rehab'],
        'rehab_rate' => $m['closedTotal'] ? (int)round(100 * $m['rehab'] / $m['closedTotal']) : null,
    ];
}

/** Labels for business_figures(), in reading order, with whether each is money or a percent. */
const BUSINESS_FIGURE_LABELS = [
    'referrals' => ['Referrals received', ''], 'referrals_converted' => ['Referrals that became clients', ''], 'new_clients' => ['New client records', ''],
    'clients_served' => ['Clients served (a logged session)', ''], 'sessions_completed' => ['Sessions completed', ''], 'no_shows' => ['No-shows', ''],
    'cancelled' => ['Cancelled sessions', ''], 'no_show_rate' => ['No-show rate', '%'], 'hours' => ['Service hours', 'h'], 'value' => ['Value of hours and mileage', '$'],
    'cases_closed' => ['Cases closed', ''], 'rehabilitated' => ['Closed rehabilitated', ''], 'rehab_rate' => ['Rehabilitation rate', '%'],
];

/** One figure as text. */
function business_figure_text($v, string $kind): string {
    if ($v === null) return 'n/a';
    return match ($kind) { '$' => fmt_money((float)$v), '%' => $v . '%', 'h' => number_format((float)$v, 2), default => (string)$v };
}

/** The change from $old to $new in words ("up 3", "down 12%", "no change"). */
function business_change_text($new, $old, string $kind): string {
    if ($new === null || $old === null) return 'n/a';
    $d = round((float)$new - (float)$old, 2);
    if ($d == 0.0) return 'no change';
    $abs = $kind === '%' ? abs($d) . ' points' : ($kind === '$' ? fmt_money(abs($d)) : ($kind === 'h' ? number_format(abs($d), 2) : (string)abs((int)$d)));
    return ($d > 0 ? 'up ' : 'down ') . $abs;
}

/** Month starts (Y-m-01) covering [from, to], at most 24. */
function months_between(string $from, string $to): array {
    $out = [];
    for ($t = strtotime(substr($from, 0, 7) . '-01'); $t <= strtotime($to) && count($out) < 24; $t = strtotime('+1 month', $t)) $out[] = date('Y-m-01', $t);
    return $out;
}

/** The open caseload now: clients not closed or archived, by instructor and status. */
function open_caseload(string $rc): array {
    return db()->query("SELECT COALESCE(u.name, 'Unassigned') instructor, c.status, COUNT(*) n FROM {$rc} c LEFT JOIN users u ON u.id = c.assigned_to
        WHERE c.status NOT LIKE 'Closed%' AND c.archived_at IS NULL GROUP BY instructor, c.status ORDER BY instructor, c.status")->fetchAll();
}

/**
 * Sessions in a period by status, grouped by client, instructor, service type or setting
 * (remote or in person). $rs is the caseload-scoped scheduled_sessions table.
 */
function attendance_rows(string $from, string $to, string $by, string $rs): array {
    $group = match ($by) {
        'instructor' => "COALESCE(u.name, 'Unknown')",
        'service' => "COALESCE(t.service_type, 'No track')",
        'setting' => "CASE WHEN s.is_remote = 1 THEN 'Remote' ELSE 'In person' END",
        default => 'c.name',
    };
    $q = db()->prepare("SELECT {$group} AS label, " . ($by === 'client' ? 'MIN(c.id)' : 'NULL') . " AS client_id,
            SUM(s.status = 'Completed') done, SUM(s.status = 'No Show') noshow, SUM(s.status = 'Cancelled') cancelled, SUM(s.status = 'Scheduled') still
        FROM {$rs} s JOIN clients c ON c.id = s.client_id LEFT JOIN users u ON u.id = s.user_id LEFT JOIN training_tracks t ON t.id = s.track_id
        WHERE DATE(s.start_at) BETWEEN ? AND ?
        GROUP BY " . ($by === 'client' ? 'c.id, c.name' : 'label') . ' ORDER BY label');
    $q->execute([$from, $to]);
    return array_map(function ($r) {
        $held = (int)$r['done'] + (int)$r['noshow'];
        $r['rate'] = $held ? (int)round(100 * (int)$r['noshow'] / $held) : null;
        return $r;
    }, $q->fetchAll());
}

/** Clients with two or more no-shows in the last 60 days. */
function repeated_no_shows(string $rs): array {
    return db()->query("SELECT c.id, c.name, COUNT(*) n, MAX(s.start_at) last_at FROM {$rs} s JOIN clients c ON c.id = s.client_id
        WHERE s.status = 'No Show' AND s.start_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) GROUP BY c.id, c.name HAVING n >= 2 ORDER BY n DESC, c.name")->fetchAll();
}

/** One client's session counts by status (all time). */
function client_attendance(int $clientId): array {
    $q = db()->prepare("SELECT SUM(status = 'Completed') done, SUM(status = 'No Show') noshow, SUM(status = 'Cancelled') cancelled FROM scheduled_sessions WHERE client_id = ?");
    $q->execute([$clientId]);
    $r = $q->fetch() ?: [];
    return ['done' => (int)($r['done'] ?? 0), 'noshow' => (int)($r['noshow'] ?? 0), 'cancelled' => (int)($r['cancelled'] ?? 0)];
}

/**
 * Authorizations that ended in a period (the track was marked Completed or Cancelled then, or
 * its expiration date fell in it), with hours and dollars authorized, delivered, invoiced and
 * left unused. $rt is the caseload-scoped training_tracks table.
 */
function unused_authorizations(string $from, string $to, string $rt): array {
    $q = db()->prepare("SELECT t.id, t.title, t.auth_number, t.funding_agency, t.service_type, t.status, t.authorized_hours, t.authorized_amount, t.hourly_rate,
            DATE(COALESCE(t.completed_at, t.expiration_date)) AS ended_on, c.name AS client_name, c.id AS client_id, COALESCE(u.name, 'Unassigned') AS instructor,
            (SELECT COALESCE(SUM(w.hours), 0) FROM work_logs w WHERE w.track_id = t.id) AS hours_done,
            (SELECT COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)), 0) FROM work_logs w WHERE w.track_id = t.id)
              + (SELECT COALESCE(SUM(e.amount), 0) FROM track_expenses e WHERE e.track_id = t.id) AS value_all,
            (SELECT COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2)), 0) FROM work_logs w WHERE w.track_id = t.id) AS value_hours,
            (SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i WHERE i.track_id = t.id AND i.status IN ('Submitted', 'Paid')) AS invoiced
        FROM {$rt} t JOIN clients c ON c.id = t.client_id LEFT JOIN users u ON u.id = c.assigned_to
        WHERE t.billing_mode = 'vr' AND (
            (t.status IN ('Completed', 'Cancelled') AND DATE(COALESCE(t.completed_at, t.expiration_date)) BETWEEN ? AND ?)
            OR (t.status = 'Active' AND t.expiration_date BETWEEN ? AND ? AND t.expiration_date < CURDATE()))
        ORDER BY ended_on, c.name");
    $q->execute([$from, $to, $from, $to]);
    return array_map(function ($r) {
        // A dollar limit covers hours, mileage and expenses (as the authorization check counts
        // them); without one, the limit is hours at the rate, so only the hours count against it.
        $authValue = $r['authorized_amount'] !== null ? (float)$r['authorized_amount'] : round((float)$r['authorized_hours'] * (float)$r['hourly_rate'], 2);
        $r['value_done'] = $r['authorized_amount'] !== null ? (float)$r['value_all'] : (float)$r['value_hours'];
        $r['authorized_value'] = $authValue;
        $r['hours_unused'] = max(0.0, round((float)$r['authorized_hours'] - (float)$r['hours_done'], 2));
        $r['value_unused'] = max(0.0, round($authValue - (float)$r['value_done'], 2));
        $r['pct_used'] = $authValue > 0 ? (int)round(100 * (float)$r['value_done'] / $authValue) : null;
        return $r;
    }, $q->fetchAll());
}

/** Totals of unused_authorizations() rows, overall or grouped by a key. */
function unused_totals(array $rows, ?string $by = null): array {
    $out = [];
    foreach ($rows as $r) {
        $k = $by ? (trim((string)$r[$by]) !== '' ? (string)$r[$by] : 'Not recorded') : 'All';
        $g = &$out[$k];
        $g ??= ['tracks' => 0, 'hours_authorized' => 0.0, 'hours_done' => 0.0, 'hours_unused' => 0.0, 'authorized_value' => 0.0, 'value_done' => 0.0, 'invoiced' => 0.0, 'value_unused' => 0.0];
        $g['tracks']++;
        $g['hours_authorized'] += (float)$r['authorized_hours']; $g['hours_done'] += (float)$r['hours_done']; $g['hours_unused'] += $r['hours_unused'];
        $g['authorized_value'] += $r['authorized_value']; $g['value_done'] += (float)$r['value_done']; $g['invoiced'] += (float)$r['invoiced']; $g['value_unused'] += $r['value_unused'];
        unset($g);
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Possible duplicates of a client being added or edited: same date of birth with the same
 * last name, the same email, the same phone, or the same full name. Never the client itself.
 */
function possible_duplicate_clients(string $name, ?string $dob, ?string $phone, ?string $email, int $excludeId = 0): array {
    $words = preg_split('/\s+/', trim(mb_strtolower($name))) ?: [];
    $last = (string)end($words);
    $digits = preg_replace('/\D/', '', (string)$phone) ?? '';
    $digits = strlen($digits) >= 10 ? substr($digits, -10) : '';
    $conds = []; $args = [];
    if (trim($name) !== '') { $conds[] = 'LOWER(TRIM(name)) = ?'; $args[] = mb_strtolower(trim($name)); }
    if ($dob && $last !== '') { $conds[] = '(dob = ? AND LOWER(name) LIKE ?)'; $args[] = $dob; $args[] = '%' . $last; }
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) { $conds[] = 'LOWER(email) = ?'; $args[] = mb_strtolower(trim($email)); }
    if ($digits !== '') { $conds[] = "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 10) = ?"; $args[] = $digits; }
    if (!$conds) return [];
    $q = db()->prepare('SELECT id, name, dob, status, archived_at, assigned_to FROM clients WHERE id <> ? AND (' . implode(' OR ', $conds) . ') ORDER BY name LIMIT 10');
    $q->execute(array_merge([$excludeId], $args));
    return $q->fetchAll();
}

/**
 * A duplicate warning sentence for possible_duplicate_clients() rows, or ''. A caseload-scoped
 * user ($scopeUser) is told only the names on their own caseload; others are a count, as on
 * the referrals page.
 */
function duplicate_warning_text(array $dups, ?int $scopeUser = null): string {
    if (!$dups) return '';
    $mine = $scopeUser ? array_values(array_filter($dups, fn($d) => (int)$d['assigned_to'] === $scopeUser)) : $dups;
    $others = count($dups) - count($mine);
    $parts = array_map(fn($d) => $d['name'] . ($d['dob'] ? ', born ' . fmt_date($d['dob']) : '') . ' (' . $d['status'] . (!empty($d['archived_at']) ? ', archived' : '') . ')', $mine);
    if ($others) $parts[] = $others . ' record' . ($others === 1 ? '' : 's') . ' on another caseload';
    return 'Possible duplicate: ' . implode('; ', $parts) . '. Check it is not the same person before adding; Merge duplicate records can join them.';
}

/**
 * The data-quality checks: each [key, heading, explanation, rows], where every row has
 * client_id, name and a detail. $scopeUser limits them to an instructor's caseload.
 */
function data_quality_checks(?int $scopeUser): array {
    $scope = $scopeUser ? ' AND c.assigned_to = ' . (int)$scopeUser : '';
    $rows = fn(string $sql) => db()->query($sql)->fetchAll();
    $checks = [];
    // Likely duplicates: pairs on the same full name, the same email, or the same date of birth and last name.
    $dups = $rows("SELECT a.id client_id, a.name, b.id other_id, b.name other_name,
            CASE WHEN LOWER(TRIM(a.name)) = LOWER(TRIM(b.name)) THEN 'same name' WHEN a.email IS NOT NULL AND LOWER(a.email) = LOWER(b.email) THEN 'same email'
                 ELSE 'same date of birth and last name' END AS detail
        FROM clients a JOIN clients b ON b.id > a.id
        WHERE a.archived_at IS NULL AND b.archived_at IS NULL" . ($scopeUser ? ' AND (a.assigned_to = ' . (int)$scopeUser . ' AND b.assigned_to = ' . (int)$scopeUser . ')' : '') . "
          AND (LOWER(TRIM(a.name)) = LOWER(TRIM(b.name))
            OR (a.email IS NOT NULL AND a.email <> '' AND LOWER(a.email) = LOWER(b.email))
            OR (a.dob IS NOT NULL AND a.dob = b.dob AND SUBSTRING_INDEX(LOWER(TRIM(a.name)), ' ', -1) = SUBSTRING_INDEX(LOWER(TRIM(b.name)), ' ', -1)))
        ORDER BY a.name LIMIT 200");
    $checks[] = ['duplicates', 'Likely duplicate clients', 'Two records that may be the same person. Open Merge duplicate records to join them.',
        array_map(fn($d) => ['client_id' => (int)$d['client_id'], 'name' => $d['name'], 'detail' => $d['detail'] . ' as ' . $d['other_name'], 'link' => 'client_merge.php?keep=' . (int)$d['client_id'] . '&merge=' . (int)$d['other_id']], $dups)];
    $checks[] = ['closed_active', 'Closed clients with a track still Active', 'Mark the track Completed or Cancelled.',
        $rows("SELECT c.id client_id, c.name, CONCAT(COUNT(*), ' active track', IF(COUNT(*) = 1, '', 's')) detail FROM clients c JOIN training_tracks t ON t.client_id = c.id
            WHERE c.status LIKE 'Closed%' AND t.status = 'Active'" . $scope . ' GROUP BY c.id, c.name ORDER BY c.name')];
    $checks[] = ['training_no_track', 'Active Training clients with no active track', 'Add the training track, or change the status.',
        $rows("SELECT c.id client_id, c.name, 'no active track' detail FROM clients c WHERE c.status = 'Active Training' AND c.archived_at IS NULL" . $scope . "
            AND NOT EXISTS (SELECT 1 FROM training_tracks t WHERE t.client_id = c.id AND t.status = 'Active') ORDER BY c.name")];
    $checks[] = ['missing', 'Open clients missing key details', 'Add them on the client\'s edit page.',
        $rows("SELECT c.id client_id, c.name, CONCAT_WS(', ', IF(c.dob IS NULL, 'date of birth', NULL), IF(COALESCE(c.counselor_email, '') = '' AND c.client_type = 'VR', 'VR counselor', NULL),
                IF(NOT EXISTS (SELECT 1 FROM tech_assessments a WHERE a.client_id = c.id AND COALESCE(a.primary_disability, '') <> '') AND COALESCE(c.vision_status, '') = '', 'disability or vision status', NULL)) detail
            FROM clients c WHERE c.status NOT LIKE 'Closed%' AND c.archived_at IS NULL" . $scope . "
            HAVING detail <> '' ORDER BY c.name")];
    $checks[] = ['no_category', 'Hours with no service category', 'Older entries logged before categories were required. Set the track\'s RSA category, or the entry\'s.',
        $rows("SELECT c.id client_id, c.name, CONCAT(COUNT(*), ' entr', IF(COUNT(*) = 1, 'y', 'ies'), ' on ', t.title) detail FROM work_logs w JOIN training_tracks t ON t.id = w.track_id JOIN clients c ON c.id = t.client_id
            WHERE COALESCE(w.rsa_category, t.rsa_category, '') = '' AND t.bundle_id IS NULL" . $scope . ' GROUP BY c.id, c.name, t.id, t.title ORDER BY c.name')];
    $checks[] = ['minors', 'Minors with no consent requested', 'Under 18 with no guardian consent form sent or signed.',
        $rows("SELECT c.id client_id, c.name, CONCAT('born ', DATE_FORMAT(c.dob, '%M %e, %Y')) detail FROM clients c
            WHERE c.dob > DATE_SUB(CURDATE(), INTERVAL 18 YEAR) AND c.status NOT LIKE 'Closed%' AND c.archived_at IS NULL" . $scope . "
            AND NOT EXISTS (SELECT 1 FROM consent_forms f WHERE f.client_id = c.id) ORDER BY c.name")];
    return $checks;
}
