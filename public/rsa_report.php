<?php
// RSA-911 aligned case-service export. One row per client served in the period,
// mapping the data the system captures to the case-service fields state VR asks
// for at reconciliation: demographics, funding authorization, RSA service
// categories and hours, and the closure / employment outcome. Provided as a
// screen preview plus a spreadsheet-safe CSV to import into the state system.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('billing');

$scoped = caseload_scoped();
$scopeCond = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeArg = $scoped ? [(int)$user['id']] : [];

// The shared period picker (remembered per user); the current federal fiscal year
// (Oct 1 – Sep 30) to date when nothing is chosen.
$fyStart = (date('n') >= 10 ? date('Y') : date('Y') - 1) . '-10-01';
$period = report_period($user, $fyStart, date('Y-m-d'));
$from = $period['from']; $to = $period['to'];

// Clients with logged service or a track expense in the period, with funding, RSA
// categories, hours. Service value = hours and mileage at the logged rates plus the
// client's track expenses dated in the period — the same amounts the invoices bill,
// each hours and mileage amount rounded to the cent per line as generate_invoice()
// rounds it — so a client whose only billable item in the period is an expense has a
// row, as on the agency billing summary. Billed lines are reported under the agency
// and authorization snapshotted on their invoice (as agency_report.php does), so
// editing a track later does not move work already invoiced; unbilled lines use the
// track's. 4.29.0: VR work only (vr_work_sql()): a private-pay or no-charge client is not
// the state's case and is not sent to it, even when the track still names a VR agency.
$sql = "SELECT c.id, c.name, c.dob, c.client_type, c.status,
        (SELECT a.primary_disability FROM tech_assessments a WHERE a.client_id = c.id AND a.primary_disability <> '' ORDER BY a.assessment_date DESC LIMIT 1) AS disability,
        GROUP_CONCAT(DISTINCT NULLIF(x.agency,'') ORDER BY x.agency SEPARATOR '; ') AS agencies,
        GROUP_CONCAT(DISTINCT NULLIF(x.auth,'') ORDER BY x.auth SEPARATOR '; ') AS auths,
        GROUP_CONCAT(DISTINCT x.cat SEPARATOR '; ') AS cats,
        COALESCE(SUM(x.hrs),0) AS hrs,
        COALESCE(SUM(x.val),0) AS val,
        MIN(x.work_date) AS first_service, MAX(x.work_date) AS last_service
    FROM (
        SELECT t.client_id, COALESCE(i.funding_agency, t.funding_agency) AS agency, COALESCE(i.auth_number, t.auth_number) AS auth,
            COALESCE(NULLIF(w.rsa_category,''), NULLIF(t.rsa_category,'')) AS cat, w.hours AS hrs,
            ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2) AS val,
            w.work_date
        FROM work_logs w
        JOIN training_tracks t ON t.id = w.track_id
        LEFT JOIN invoices i ON i.id = w.invoice_id
        WHERE w.work_date BETWEEN ? AND ? AND " . vr_work_sql('t', 'i') . "
        UNION ALL
        SELECT t.client_id, COALESCE(i.funding_agency, t.funding_agency), COALESCE(i.auth_number, t.auth_number),
            NULLIF(t.rsa_category,''), 0, e.amount, NULL
        FROM track_expenses e
        JOIN training_tracks t ON t.id = e.track_id
        LEFT JOIN invoices i ON i.id = e.invoice_id
        WHERE e.expense_date BETWEEN ? AND ? AND " . vr_work_sql('t', 'i') . "
    ) x
    JOIN clients c ON c.id = x.client_id
    WHERE 1=1" . $scopeCond . "
    GROUP BY c.id ORDER BY c.name";
$st = db()->prepare($sql);
$st->execute(array_merge([$from, $to, $from, $to], $scopeArg));
$rows = $st->fetchAll();

// Closures within the period, judged as the outcome report (outcomes.php) judges them:
// each client by their LAST status change in the period, kept only if that change
// closed the case. A closure later reopened, or corrected to another closed status, in
// the same period counts as where it ended up — so the two linked reports agree.
// Only a case closed (moved into a Closed status from an open one) in the period
// counts, so correcting an earlier period's closure is not a new closure.
// Rows carried over from a merged duplicate ('[merged] ' note) are left out.
$cl = db()->prepare("SELECT h.client_id, h.new_status, h.changed_at AS closed_at
    FROM client_status_history h JOIN clients c ON c.id = h.client_id
    WHERE h.new_status LIKE 'Closed%' AND DATE(h.changed_at) BETWEEN ? AND ?
      AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')
      AND NOT EXISTS (SELECT 1 FROM client_status_history h2 WHERE h2.client_id = h.client_id
          AND DATE(h2.changed_at) BETWEEN ? AND ?
          AND (h2.note IS NULL OR h2.note NOT LIKE '[merged] %')
          AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id)))
      AND EXISTS (SELECT 1 FROM client_status_history h3 WHERE h3.client_id = h.client_id
          AND DATE(h3.changed_at) BETWEEN ? AND ?
          AND (h3.note IS NULL OR h3.note NOT LIKE '[merged] %')
          AND h3.new_status LIKE 'Closed%' AND (h3.old_status IS NULL OR h3.old_status NOT LIKE 'Closed%'))" . $scopeCond);
$cl->execute(array_merge([$from, $to, $from, $to, $from, $to], $scopeArg));
$closures = [];
foreach ($cl->fetchAll() as $c) {
    // Keep the most recent closed status per client.
    if (!isset($closures[$c['client_id']]) || $c['closed_at'] > $closures[$c['client_id']]['closed_at']) {
        $closures[$c['client_id']] = $c;
    }
}

// Blank, not a positive age, when the date of birth is after the period end.
$ageOn = function (?string $dob) use ($to) {
    if (!$dob || !($d = date_create($dob))) return '';
    $diff = date_diff($d, date_create($to));
    return $diff->invert ? '' : (int)$diff->y;
};
$outcome = function (?array $cl): string {
    if (!$cl) return '';
    return $cl['new_status'] === 'Closed - Rehabilitated' ? 'Employment outcome (rehabilitated)' : $cl['new_status'];
};

/* ---- CSV export ---- */
if (isset($_GET['export'])) {
    // The file names every client served in the period, with age and disability, so the
    // download is audited (as audit.php's own export is).
    audit($user['id'], 'report.export', 'client', null, 'RSA-911 CSV ' . $from . ' to ' . $to . ' (' . count($rows) . ' clients)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rsa911_case_service_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // The header row is the first row, so spreadsheet and import tools read the columns;
    // the title and period follow the data.
    write_csv_row($out, ['Client ID', 'Client Name', 'Age', 'Client Type', 'Primary Disability', 'Funding Agency',
        'Authorization #', 'RSA Service Categories', 'Service Hours', 'Service Value', 'First Service', 'Last Service',
        'Current Status', 'Closed Status (period)', 'Closed Date', 'Employment Outcome',
        'Closure Reason', 'Employed at Closure', 'Employer', 'Job Title', 'Hours a Week', 'Hourly Wage', 'Devices Recommended', 'Devices Acquired', 'Devices In Use']);
    $yn = fn($v) => $v === null ? '' : ((int)$v === 1 ? 'Y' : 'N');
    foreach ($rows as $r) {
        $c = $closures[$r['id']] ?? null;
        // The details recorded when that case was closed in the period (4.24.0), not a later closure.
        $k = $c ? client_closure_at((int)$r['id'], (string)$c['closed_at']) : null;
        write_csv_row($out, [
            (int)$r['id'], csv_cell($r['name']), $ageOn($r['dob']), csv_cell($r['client_type'] ?? 'VR'),
            csv_cell($r['disability'] ?? ''), csv_cell($r['agencies'] ?? ''), csv_cell($r['auths'] ?? ''),
            // Plain numbers (no thousands separator), as agency_report.php writes them, so
            // the state system's import and spreadsheets read them as numbers.
            csv_cell($r['cats'] ?? ''), number_format((float)$r['hrs'], 2, '.', ''), number_format((float)$r['val'], 2, '.', ''),
            $r['first_service'], $r['last_service'], csv_cell($r['status']),
            $c ? csv_cell($c['new_status']) : '', $c ? date('Y-m-d', strtotime($c['closed_at'])) : '',
            $c && $c['new_status'] === 'Closed - Rehabilitated' ? 'Y' : '',
            $k ? csv_cell($k['reason']) : '', $k ? $yn($k['employed']) : '', $k ? csv_cell((string)$k['employer']) : '', $k ? csv_cell((string)$k['job_title']) : '',
            $k && $k['hours_per_week'] !== null ? number_format((float)$k['hours_per_week'], 2, '.', '') : '', $k && $k['hourly_wage'] !== null ? number_format((float)$k['hourly_wage'], 2, '.', '') : '',
            $k['at_recommended'] ?? '', $k['at_acquired'] ?? '', $k ? $yn($k['at_in_use'] ?? null) : '',
        ]);
    }
    fclose($out);
    exit;
}

$served = count($rows);
// Counted over the same clients as the table and CSV (clients served in the period),
// so the tiles reconcile with the download.
$closedInPeriod = count(array_filter($rows, fn($r) => isset($closures[$r['id']])));
$rehab = count(array_filter($rows, fn($r) => ($closures[$r['id']]['new_status'] ?? '') === 'Closed - Rehabilitated'));
$totalHrs = array_sum(array_map(fn($r) => (float)$r['hrs'], $rows));

page_header('RSA-911 export', $user, 'analytics');
?>
<h1>RSA-911 case-service export</h1>
<p class="muted">Case-service data for a reporting period, aligned to the fields of the RSA-911 (the Rehabilitation Services Administration's
   case service report) that state vocational rehabilitation (VR) agencies reconcile —
   one row per client served (with logged service or a track expense in the period)<?= $scoped ? ' on your caseload' : '' ?>. Preview here, then download the CSV to import into your state system.</p>

<div class="alert alert-info" role="note">
  <p>This export maps the data this system captures (demographics, funding authorization, RSA service categories, hours,
     and closure/employment outcome) to RSA-911-style columns. Fields the federal record requires but this system does
     not store (e.g. Social Security number (SSN), detailed federal codes) are not included — fill those from your state system as usual.</p>
  <p>The CSV's Service Value is the hours and mileage at the rates logged plus the client's track expenses dated in the period,
     as invoiced; the funding agency and authorization of work already invoiced are those on its invoice. First and Last Service are the first and last session dates.
     Closures and employment outcomes are counted for the clients served in the period (the rows below).</p>
</div>

<h2 id="rsa-period-h">Reporting period</h2>
<?= report_period_form('rsa_report.php', $period) ?>
<?php if ($period['notes']): ?><p><?= h(implode(' ', $period['notes'])) ?></p><?php endif; ?>

<h2 class="visually-hidden">Totals for this period</h2>
<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= $served ?></span><span class="stat-label">Clients served</span></div>
  <div class="card stat"><span class="stat-num"><?= number_format($totalHrs, 1) ?></span><span class="stat-label">Service hours</span></div>
  <div class="card stat"><span class="stat-num"><?= $closedInPeriod ?></span><span class="stat-label">Clients served and closed in period</span></div>
  <div class="card stat"><span class="stat-num"><?= $rehab ?></span><span class="stat-label">Employment outcomes (clients served)</span></div>
</div>

<h2 id="rsa-rows-h">Clients served <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?></h2>
<div class="page-actions">
  <a class="btn btn-secondary" href="rsa_report.php?from=<?= h($from) ?>&amp;to=<?= h($to) ?>&amp;export=1">Download RSA-911 CSV</a>
</div>

<div class="table-scroll" tabindex="0" role="region" aria-labelledby="rsa-rows-h">
<table>
  <caption class="visually-hidden">Clients served <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?></caption>
  <thead><tr>
    <th scope="col">Client</th><th scope="col">Type</th><th scope="col">Disability</th>
    <th scope="col">Funding / Authorization</th><th scope="col" class="num">Hours</th>
    <th scope="col">RSA categories</th><th scope="col">Outcome</th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No clients with logged service or track expenses in this period.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $c = $closures[$r['id']] ?? null; ?>
    <tr>
      <td><a href="client.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
      <td><?= h($r['client_type'] ?? 'VR') ?></td>
      <td><?= h($r['disability'] ?? '—') ?></td>
      <td><?= h($r['agencies'] ?? '—') ?><?= $r['auths'] ? '<br><span class="muted">Authorization ' . h($r['auths']) . '</span>' : '' ?></td>
      <td class="num"><?= number_format((float)$r['hrs'], 2) ?></td>
      <td><?= h($r['cats'] ?? '—') ?></td>
      <?php // No closure in the period does not mean the case is open: the current status says which (as the CSV's Current Status column does). ?>
      <td><?= $c ? h($outcome($c)) : '<span class="muted">No closure in this period (now ' . h((string)$r['status']) . ')</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
