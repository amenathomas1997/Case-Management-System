<?php
declare(strict_types=1);
/*
 * Shared report queries and the scheduled report emails.
 *
 * The outcome report (outcomes.php) and the funder outcome report
 * (funder_report.php) read their numbers through the functions here, so the
 * monthly emails that cron/monthly_reports.php sends carry exactly the figures
 * the pages show. The page passes its caseload-scoped table sources
 * (report_table()); the cron passes the bare tables, since it reports agency-wide.
 *
 * Settings (saved on the Administration page, read by the cron):
 *   scheduled_reports_outcomes_enabled  '1' or '0'
 *   scheduled_reports_funder_enabled    '1' or '0'
 *   scheduled_reports_recipients        comma-separated email addresses
 *   scheduled_reports_day               day of the month, 1–28
 *   scheduled_reports_period            'last_month' or 'last_quarter'
 *   scheduled_reports_sent_outcomes     the period stamp last sent (2026-09 or 2026-Q3)
 *   scheduled_reports_sent_funder       the same for the funder report
 *
 * Functions only: the caller loads core.php (db(), fmt_*, setting()) first.
 */

const SCHEDULED_REPORTS = ['outcomes' => 'Outcome report', 'funder' => 'Funder outcome report'];
const SCHEDULED_REPORT_PERIODS = ['last_month' => 'Previous month', 'last_quarter' => 'Previous quarter'];

/* ---------- Shared report queries ---------- */

/**
 * The outcome report's numbers for a period: clients served by type, hours and
 * value by RSA category and by program, closures, the rehabilitation rate,
 * average hours per rehabilitated client, totals, expenses and surveys.
 * $rt, $rh, $rs are the (possibly caseload-scoped) sources for training_tracks,
 * client_status_history and surveys.
 */
function outcome_metrics(string $from, string $to, string $rt = 'training_tracks', string $rh = 'client_status_history', string $rs = 'surveys'): array {
    /* Clients served: anyone with a logged visit in the period, by client type */
    $served = db()->prepare("SELECT c.client_type, COUNT(DISTINCT c.id) n
        FROM work_logs w JOIN {$rt} t ON t.id = w.track_id JOIN clients c ON c.id = t.client_id
        WHERE w.work_date BETWEEN ? AND ? GROUP BY c.client_type");
    $served->execute([$from, $to]);
    $served = $served->fetchAll();
    $servedTotal = array_sum(array_column($served, 'n'));

    /* Hours + value by RSA category (value = hours and mileage, each line rounded to the
       cent as the invoices round it — the same expression as the totals below, so the
       category values add up to the total service value) */
    $byCat = db()->prepare("SELECT COALESCE(w.rsa_category, t.rsa_category, 'Uncategorized') AS cat,
            COUNT(DISTINCT t.client_id) clients, COALESCE(SUM(w.hours),0) hrs,
            COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) val
        FROM work_logs w JOIN {$rt} t ON t.id = w.track_id
        WHERE w.work_date BETWEEN ? AND ? GROUP BY cat ORDER BY hrs DESC");
    $byCat->execute([$from, $to]);
    $byCat = $byCat->fetchAll();

    /* Hours by program (bundle name, else the track's single category) */
    $byProg = db()->prepare("SELECT COALESCE(b.name, t.rsa_category, t.service_type, 'Unlabeled') AS program,
            COUNT(DISTINCT t.client_id) clients, COALESCE(SUM(w.hours),0) hrs
        FROM work_logs w JOIN {$rt} t ON t.id = w.track_id
        LEFT JOIN service_bundles b ON b.id = t.bundle_id
        WHERE w.work_date BETWEEN ? AND ? GROUP BY program ORDER BY hrs DESC");
    $byProg->execute([$from, $to]);
    $byProg = $byProg->fetchAll();

    /* Closures in the period from status history. Each client counts once, by their
       LAST status change in the period, and only if that change closed the case — a
       closure later corrected to another closed status, or reopened, in the same
       period counts as where it ended up. A client is counted only when the case was
       closed (moved into a Closed status from an open one) in the period, so correcting
       an earlier period's closure to another closed status is not a new closure. Rows
       carried over from a merged duplicate ('[merged] ' note) are that duplicate's
       timeline, so they are left out. */
    $finalInPeriod = "DATE(h.changed_at) BETWEEN ? AND ?
        AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')
        AND NOT EXISTS (SELECT 1 FROM client_status_history h2 WHERE h2.client_id = h.client_id
            AND DATE(h2.changed_at) BETWEEN ? AND ?
            AND (h2.note IS NULL OR h2.note NOT LIKE '[merged] %')
            AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id)))
        AND EXISTS (SELECT 1 FROM client_status_history h3 WHERE h3.client_id = h.client_id
            AND DATE(h3.changed_at) BETWEEN ? AND ?
            AND (h3.note IS NULL OR h3.note NOT LIKE '[merged] %')
            AND h3.new_status LIKE 'Closed%' AND (h3.old_status IS NULL OR h3.old_status NOT LIKE 'Closed%'))";
    $closures = db()->prepare("SELECT h.new_status, COUNT(*) n
        FROM {$rh} h
        WHERE h.new_status LIKE 'Closed%' AND {$finalInPeriod}
        GROUP BY h.new_status");
    $closures->execute([$from, $to, $from, $to, $from, $to]);
    $closures = $closures->fetchAll();
    // One row per client, so the per-status counts add up to the distinct total.
    $closedTotal = (int)array_sum(array_column($closures, 'n'));
    $rehab = 0;
    foreach ($closures as $c) if ($c['new_status'] === 'Closed - Rehabilitated') $rehab = (int)$c['n'];

    /* Average total hours per client closed rehabilitated in the period (same cohort) */
    $avgHours = db()->prepare("SELECT AVG(client_hours) FROM (
        SELECT h.client_id, (SELECT COALESCE(SUM(w.hours),0) FROM work_logs w
            JOIN {$rt} t ON t.id = w.track_id WHERE t.client_id = h.client_id) AS client_hours
        FROM {$rh} h
        WHERE h.new_status = 'Closed - Rehabilitated' AND {$finalInPeriod}
        GROUP BY h.client_id) x");
    $avgHours->execute([$from, $to, $from, $to, $from, $to]);
    $avgHours = (float)$avgHours->fetchColumn();

    /* Totals */
    $totals = db()->prepare("SELECT COALESCE(SUM(w.hours),0) hrs, COALESCE(SUM(w.mileage),0) mi,
            COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) val
        FROM work_logs w JOIN {$rt} t ON t.id = w.track_id WHERE w.work_date BETWEEN ? AND ?");
    $totals->execute([$from, $to]);
    $totals = $totals->fetch();
    // The value above is hours and mileage. Track expenses dated in the period are billed on
    // the same invoices (generate_invoice) and are in the RSA-911 export's Service Value, so
    // they are shown beside it, with the total of both, and the two reports reconcile.
    $exq = db()->prepare("SELECT COALESCE(SUM(e.amount),0) FROM track_expenses e JOIN {$rt} t ON t.id = e.track_id WHERE e.expense_date BETWEEN ? AND ?");
    $exq->execute([$from, $to]);
    $expenses = (float)$exq->fetchColumn();
    $valueAll = round((float)$totals['val'] + $expenses, 2);

    /* Survey results in the period */
    $sv = db()->prepare("SELECT COUNT(*) n, AVG(satisfaction) sat, AVG(goals_met) goals
        FROM {$rs} surveys WHERE completed_at IS NOT NULL AND DATE(completed_at) BETWEEN ? AND ?");
    $sv->execute([$from, $to]);
    $sv = $sv->fetch();

    return compact('served', 'servedTotal', 'byCat', 'byProg', 'closures', 'closedTotal', 'rehab', 'avgHours', 'totals', 'expenses', 'valueAll', 'sv');
}

/**
 * The funding agencies on tracks now (n = how many tracks), plus those snapshotted on
 * invoices, so work billed under an agency a track has since moved away from can still
 * be selected (as on the agency summary). $rt / $ri: the training_tracks and invoices sources.
 */
function funder_agencies(string $rt = 'training_tracks', string $ri = 'invoices'): array {
    return db()->query("SELECT funding_agency, SUM(n) n FROM (
            SELECT funding_agency, 1 n FROM {$rt} training_tracks WHERE funding_agency <> ''
            UNION ALL
            SELECT DISTINCT funding_agency, 0 FROM {$ri} inv WHERE funding_agency IS NOT NULL AND funding_agency <> ''
        ) x GROUP BY funding_agency ORDER BY funding_agency")->fetchAll();
}

/** Metrics for one funding agency over the period. $rt: the training_tracks source. */
function funder_metrics(string $agency, string $from, string $to, string $rt = 'training_tracks'): array {
    $clientsOfAgency = "client_id IN (SELECT DISTINCT client_id FROM {$rt} training_tracks WHERE funding_agency = ?)";
    // Billed lines count under the agency snapshotted on their invoice (as agency_report.php
    // and analytics.php attribute them), so editing a track's agency later does not move
    // work already invoiced; unbilled lines, and older invoices, use the track's.
    $agencyExpr = 'COALESCE(i.funding_agency, t.funding_agency)';
    $logs = "work_logs w JOIN {$rt} t ON t.id = w.track_id LEFT JOIN invoices i ON i.id = w.invoice_id";

    $served = db()->prepare("SELECT COUNT(DISTINCT t.client_id) n FROM {$logs} WHERE {$agencyExpr} = ? AND w.work_date BETWEEN ? AND ?");
    $served->execute([$agency, $from, $to]); $servedTotal = (int)$served->fetchColumn();

    $tot = db()->prepare("SELECT COALESCE(SUM(w.hours),0) hrs, COALESCE(SUM(w.mileage),0) mi,
            COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate,t.hourly_rate),2) + ROUND(w.mileage * COALESCE(w.mileage_rate,t.mileage_rate),2)),0) val
        FROM {$logs} WHERE {$agencyExpr} = ? AND w.work_date BETWEEN ? AND ?");
    $tot->execute([$agency, $from, $to]); $totals = $tot->fetch();

    // Track expenses are billed on the same invoices as the hours and mileage
    // (generate_invoice), so they are reported beside them — by expense date, under the
    // agency snapshotted on their invoice — and the total matches the agency billing
    // summary for the same agency and dates.
    $exp = db()->prepare("SELECT COALESCE(SUM(e.amount),0) FROM track_expenses e JOIN {$rt} t ON t.id = e.track_id
        LEFT JOIN invoices i ON i.id = e.invoice_id WHERE {$agencyExpr} = ? AND e.expense_date BETWEEN ? AND ?");
    $exp->execute([$agency, $from, $to]); $expenses = (float)$exp->fetchColumn();

    // Same value expression as the headline total (hours + mileage, each line rounded
    // to the cent as the invoices round it), so the category values add up to it.
    $byCat = db()->prepare("SELECT COALESCE(w.rsa_category, t.rsa_category, 'Uncategorized') cat, COUNT(DISTINCT t.client_id) clients,
            COALESCE(SUM(w.hours),0) hrs, COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate,t.hourly_rate),2) + ROUND(w.mileage * COALESCE(w.mileage_rate,t.mileage_rate),2)),0) val
        FROM {$logs} WHERE {$agencyExpr} = ? AND w.work_date BETWEEN ? AND ? GROUP BY cat ORDER BY hrs DESC");
    $byCat->execute([$agency, $from, $to]); $byCat = $byCat->fetchAll();

    $goals = db()->prepare("SELECT COUNT(*) total, SUM(g.status = 'Achieved') achieved, AVG(g.progress_pct) avgpct
        FROM smart_goals g JOIN {$rt} t ON t.id = g.track_id
        WHERE t.funding_agency = ? AND g.created_at BETWEEN ? AND ?");
    $goals->execute([$agency, $from, $to . ' 23:59:59']); $goals = $goals->fetch();

    $ret = db()->prepare("SELECT COUNT(*) done, SUM(f.in_use = 1) in_use FROM equipment_followups f
        WHERE f.status = 'Completed' AND f.in_use IS NOT NULL AND f.completed_at BETWEEN ? AND ? AND f.$clientsOfAgency");
    $ret->execute([$from, $to . ' 23:59:59', $agency]); $ret = $ret->fetch();

    // Average score gain (percentage points) within the period for clients with
    // >=2 administrations of the same instrument. The sign is flipped for any
    // "lower is better" instrument so a genuine improvement always reads positive.
    $gain = db()->prepare("SELECT AVG(gain) FROM (
        SELECT ((SELECT x.pct FROM client_scored_assessments x WHERE x.client_id = sa.client_id AND x.instrument_id = sa.instrument_id AND x.assessed_on BETWEEN ? AND ? ORDER BY x.assessed_on DESC, x.id DESC LIMIT 1)
             - (SELECT y.pct FROM client_scored_assessments y WHERE y.client_id = sa.client_id AND y.instrument_id = sa.instrument_id AND y.assessed_on BETWEEN ? AND ? ORDER BY y.assessed_on ASC, y.id ASC LIMIT 1))
             * (CASE WHEN i.higher_is_better = 0 THEN -1 ELSE 1 END) AS gain,
             COUNT(*) c
        FROM client_scored_assessments sa JOIN assessment_instruments i ON i.id = sa.instrument_id
        WHERE sa.assessed_on BETWEEN ? AND ? AND sa.$clientsOfAgency
        GROUP BY sa.client_id, sa.instrument_id, i.higher_is_better HAVING c >= 2) z");
    $gain->execute([$from, $to, $from, $to, $from, $to, $agency]); $avgGain = $gain->fetchColumn();
    $avgGain = $avgGain === null ? null : (float)$avgGain;

    // Each client counts once, by their LAST status change in the period, and only
    // if that change closed the case (a closure corrected or reopened in the same
    // period counts as where it ended up) — so the per-status counts add up. Only a
    // case closed (moved into a Closed status from an open one) in the period counts,
    // so correcting an earlier period's closure is not a new closure (as outcomes.php).
    // Rows carried over from a merged duplicate ('[merged] ' note) are left out.
    $closures = db()->prepare("SELECT h.new_status, COUNT(*) n FROM client_status_history h
        WHERE h.new_status LIKE 'Closed%' AND DATE(h.changed_at) BETWEEN ? AND ? AND h.$clientsOfAgency
          AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')
          AND NOT EXISTS (SELECT 1 FROM client_status_history h2 WHERE h2.client_id = h.client_id
              AND DATE(h2.changed_at) BETWEEN ? AND ?
              AND (h2.note IS NULL OR h2.note NOT LIKE '[merged] %')
              AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id)))
          AND EXISTS (SELECT 1 FROM client_status_history h3 WHERE h3.client_id = h.client_id
              AND DATE(h3.changed_at) BETWEEN ? AND ?
              AND (h3.note IS NULL OR h3.note NOT LIKE '[merged] %')
              AND h3.new_status LIKE 'Closed%' AND (h3.old_status IS NULL OR h3.old_status NOT LIKE 'Closed%'))
        GROUP BY h.new_status");
    $closures->execute([$from, $to, $agency, $from, $to, $from, $to]); $closures = $closures->fetchAll();
    $closedTotal = (int)array_sum(array_column($closures, 'n'));
    $rehab = 0; foreach ($closures as $c) if ($c['new_status'] === 'Closed - Rehabilitated') $rehab = (int)$c['n'];

    return compact('servedTotal', 'totals', 'expenses', 'byCat', 'goals', 'ret', 'avgGain', 'closures', 'closedTotal', 'rehab');
}

/** Shared row set for the funder report's exports (label, value). */
function funder_stat_rows(string $agency, string $from, string $to, array $m, ?int $retentionPct, ?int $goalPct): array {
    $rows = [
        ['Funding agency', $agency],
        ['Period', fmt_date($from) . ' to ' . fmt_date($to)],
        ['Clients served', (string)$m['servedTotal']],
        ['Service hours', number_format((float)$m['totals']['hrs'], 2) . ' (' . fmt_money((float)$m['totals']['val']) . ' for hours and mileage)'],
        ['Track expenses', fmt_money($m['expenses'])],
        ['Total billable value', fmt_money(round((float)$m['totals']['val'] + $m['expenses'], 2)) . ' (hours, mileage and expenses)'],
        ['Goals achieved', $goalPct === null ? 'n/a' : $goalPct . '% (' . (int)$m['goals']['achieved'] . ' of ' . (int)$m['goals']['total'] . ', avg progress ' . round((float)$m['goals']['avgpct']) . '%)'],
        ['Device retention (still in use)', $retentionPct === null ? 'n/a' : $retentionPct . '% of ' . (int)$m['ret']['done'] . ' follow-ups'],
        // A signed number with the unit in the label: "+3" stays a number in the CSV
        // (csv_cell() passes numeric values), where "+3 points" would gain a formula guard.
        ['Avg assessment gain (points)', $m['avgGain'] === null ? 'n/a' : sprintf('%+d', (int)round($m['avgGain']))],
        ['Cases closed', (string)$m['closedTotal']],
        ['Rehabilitation rate', $m['closedTotal'] ? round($m['rehab'] / $m['closedTotal'] * 100) . '% (' . $m['rehab'] . ' rehabilitated)' : 'n/a'],
    ];
    return $rows;
}

/** The two derived percentages the funder report shows (null = not applicable). */
function funder_pcts(array $m): array {
    $retentionPct = (int)$m['ret']['done'] > 0 ? (int)round(100 * (int)$m['ret']['in_use'] / (int)$m['ret']['done']) : null;
    $goalPct = (int)$m['goals']['total'] > 0 ? (int)round(100 * (int)$m['goals']['achieved'] / (int)$m['goals']['total']) : null;
    return [$retentionPct, $goalPct];
}

/* ---------- Scheduled report settings and periods ---------- */

/** The saved schedule, with the defaults for anything not set yet. */
function scheduled_reports_config(): array {
    $day = (int)(setting('scheduled_reports_day') ?? '1');
    $period = (string)(setting('scheduled_reports_period') ?? 'last_month');
    return [
        'outcomes' => setting('scheduled_reports_outcomes_enabled') === '1',
        'funder' => setting('scheduled_reports_funder_enabled') === '1',
        'recipients' => scheduled_reports_parse_recipients((string)(setting('scheduled_reports_recipients') ?? ''))['valid'],
        'day' => $day >= 1 && $day <= 28 ? $day : 1,
        'period' => isset(SCHEDULED_REPORT_PERIODS[$period]) ? $period : 'last_month',
    ];
}

/**
 * Addresses typed one per line or separated by commas or semicolons:
 * ['valid' => [...lowercased, unique], 'invalid' => [...as typed]].
 */
function scheduled_reports_parse_recipients(string $raw): array {
    $valid = []; $invalid = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (filter_var($part, FILTER_VALIDATE_EMAIL) && strlen($part) <= 190) {
            $valid[strtolower($part)] = true;
        } else {
            $invalid[] = $part;
        }
    }
    return ['valid' => array_keys($valid), 'invalid' => $invalid];
}

/**
 * The period a scheduled report covers when run on $today (Y-m-d): the whole
 * previous month or the whole previous calendar quarter.
 * ['from', 'to', 'label', 'stamp'] — the stamp (2026-09, 2026-Q3) names the
 * period in the sent marker, so a period is emailed once however often the cron runs.
 */
function scheduled_report_period(string $period, ?string $today = null): array {
    $t = strtotime(($today ?? date('Y-m-d')) . ' 12:00:00');
    if ($period === 'last_quarter') {
        $y = (int)date('Y', $t); $m = (int)date('n', $t);
        $qStartMonth = (int)(floor(($m - 1) / 3) * 3 + 1);
        $prevEnd = strtotime(sprintf('%04d-%02d-01', $y, $qStartMonth) . ' -1 day');
        $py = (int)date('Y', $prevEnd); $pq = (int)ceil((int)date('n', $prevEnd) / 3);
        $pm = ($pq - 1) * 3 + 1;
        $from = sprintf('%04d-%02d-01', $py, $pm);
        $to = date('Y-m-t', mktime(12, 0, 0, $pm + 2, 1, $py));
        return ['from' => $from, 'to' => $to, 'label' => 'Quarter ' . $pq . ' ' . $py . ' (' . fmt_date($from) . ' to ' . fmt_date($to) . ')', 'stamp' => $py . '-Q' . $pq];
    }
    $prev = strtotime('first day of last month', $t);
    return ['from' => date('Y-m-01', $prev), 'to' => date('Y-m-t', $prev), 'label' => date('F Y', $prev), 'stamp' => date('Y-m', $prev)];
}

/* ---------- Email bodies ---------- */

/**
 * A report as sections for the email renderers: each section has a 'heading'
 * and either 'rows' (label/value pairs) or a 'table' (['columns' => [...], 'rows' => [[...]]]).
 * The numbers come from the same functions the report pages use.
 */
function scheduled_report_sections(string $key, string $from, string $to): array {
    $sections = [];
    if ($key === 'outcomes') {
        $m = outcome_metrics($from, $to);
        $summary = [
            ['Period', fmt_date($from) . ' to ' . fmt_date($to)],
            ['Clients served', (string)$m['servedTotal']],
            ['Total service hours', fmt_hours((float)$m['totals']['hrs'])],
            ['Total service value (hours and mileage)', fmt_money((float)$m['totals']['val'])],
            ['Track expenses', fmt_money($m['expenses'])],
            ['Total value including expenses', fmt_money($m['valueAll'])],
            ['Cases closed', (string)$m['closedTotal']],
            ['Rehabilitation rate', $m['closedTotal'] ? round($m['rehab'] / $m['closedTotal'] * 100) . '% (' . $m['rehab'] . ' rehabilitated)' : 'n/a'],
        ];
        if ($m['avgHours'] > 0) $summary[] = ['Average hours per rehabilitated client', number_format($m['avgHours'], 1)];
        if ((int)$m['sv']['n'] > 0) $summary[] = ['Client satisfaction', round((float)$m['sv']['sat'], 1) . '/10 across ' . (int)$m['sv']['n'] . ' surveys (goals met ' . round((float)$m['sv']['goals'], 1) . '/10)'];
        $sections[] = ['heading' => 'Summary', 'rows' => $summary];
        $sections[] = ['heading' => 'Who was served', 'rows' => $m['served']
            ? array_map(fn($s) => [$s['client_type'] ?? 'VR', (string)(int)$s['n']], $m['served'])
            : [['No logged services in this period.', '']]];
        $sections[] = ['heading' => 'Hours by RSA service category', 'table' => ['columns' => ['Category', 'Clients', 'Hours', 'Value'],
            'rows' => array_map(fn($r) => [$r['cat'], (string)(int)$r['clients'], number_format((float)$r['hrs'], 2), fmt_money((float)$r['val'])], $m['byCat'])]];
        $sections[] = ['heading' => 'Hours by program', 'table' => ['columns' => ['Program', 'Clients', 'Hours'],
            'rows' => array_map(fn($r) => [$r['program'], (string)(int)$r['clients'], number_format((float)$r['hrs'], 2)], $m['byProg'])]];
        $sections[] = ['heading' => 'Closures', 'rows' => $m['closures']
            ? array_map(fn($c) => [$c['new_status'], (string)(int)$c['n']], $m['closures'])
            : [['No cases closed in this period.', '']]];
        return $sections;
    }
    if ($key === 'funder') {
        $agencies = funder_agencies();
        if (!$agencies) return [['heading' => 'Funding agencies', 'rows' => [['No funding agencies on record: they come from each training track\'s funding agency.', '']]]];
        foreach ($agencies as $a) {
            $agency = (string)$a['funding_agency'];
            $m = funder_metrics($agency, $from, $to);
            [$retentionPct, $goalPct] = funder_pcts($m);
            $sections[] = ['heading' => $agency, 'rows' => funder_stat_rows($agency, $from, $to, $m, $retentionPct, $goalPct)];
            $sections[] = ['heading' => $agency . ': hours by RSA service category', 'table' => ['columns' => ['Category', 'Clients', 'Hours', 'Value'],
                'rows' => array_map(fn($r) => [$r['cat'], (string)(int)$r['clients'], number_format((float)$r['hrs'], 2), fmt_money((float)$r['val'])], $m['byCat'])]];
            $sections[] = ['heading' => $agency . ': closures', 'rows' => $m['closures']
                ? array_map(fn($c) => [$c['new_status'], (string)(int)$c['n']], $m['closures'])
                : [['No cases closed in this period.', '']]];
        }
        return $sections;
    }
    throw new InvalidArgumentException('Unknown scheduled report.');
}

/** The sections as a plain-text email body (the only form Graph mail sends). */
function scheduled_report_text(string $title, string $periodLabel, array $sections): string {
    $out = strtoupper($title) . ' — ' . brand('org') . "\n" . 'Period: ' . $periodLabel . "\n"
        . 'Abbreviations: VR is Vocational Rehabilitation; RSA is the Rehabilitation Services Administration.' . "\n";
    foreach ($sections as $s) {
        $out .= "\n" . $s['heading'] . "\n" . str_repeat('-', mb_strlen($s['heading'])) . "\n";
        if (isset($s['table'])) {
            if (!$s['table']['rows']) { $out .= "Nothing in this period.\n"; continue; }
            $cols = $s['table']['columns'];
            foreach ($s['table']['rows'] as $row) {
                $cells = [];
                foreach ($row as $i => $v) $cells[] = ($i === 0 ? '' : $cols[$i] . ' ') . $v;
                $out .= '- ' . implode('; ', $cells) . "\n";
            }
        } else {
            foreach ($s['rows'] as [$label, $value]) $out .= $value === '' ? $label . "\n" : $label . ': ' . $value . "\n";
        }
    }
    $out .= "\nPrepared automatically on " . date('F j, Y') . ' by ' . cfg('name') . ". The same report, with downloads, is on the Business analytics page (sign-in required): " . cfg('base_url') . "/analytics.php\n";
    return $out;
}

/** The sections as an accessible HTML email body (for a mail path that accepts HTML). */
function scheduled_report_html(string $title, string $periodLabel, array $sections): string {
    $out = '<h1>' . h($title) . ' — ' . h(brand('org')) . '</h1><p>Period: ' . h($periodLabel) . '</p>'
        . '<p>Abbreviations: VR is Vocational Rehabilitation; RSA is the Rehabilitation Services Administration.</p>';
    foreach ($sections as $s) {
        $out .= '<h2>' . h($s['heading']) . '</h2>';
        if (isset($s['table'])) {
            if (!$s['table']['rows']) { $out .= '<p>Nothing in this period.</p>'; continue; }
            $out .= '<table><caption>' . h($s['heading']) . '</caption><thead><tr>';
            foreach ($s['table']['columns'] as $c) $out .= '<th scope="col">' . h($c) . '</th>';
            $out .= '</tr></thead><tbody>';
            foreach ($s['table']['rows'] as $row) {
                $out .= '<tr>';
                foreach ($row as $i => $v) $out .= $i === 0 ? '<th scope="row">' . h($v) . '</th>' : '<td>' . h($v) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
        } else {
            $out .= '<dl>';
            foreach ($s['rows'] as [$label, $value]) $out .= $value === '' ? '<dt>' . h($label) . '</dt>' : '<dt>' . h($label) . '</dt><dd>' . h($value) . '</dd>';
            $out .= '</dl>';
        }
    }
    return $out . '<p>Prepared automatically on ' . h(date('F j, Y')) . ' by ' . h((string)cfg('name')) . '.</p>';
}
