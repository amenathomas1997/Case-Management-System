<?php
// Date-range billing summary per funding agency (quarterly reconciliation for state VR).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('billing');

$qStart = date('Y-m-d', strtotime(date('Y') . '-' . (floor((date('n') - 1) / 3) * 3 + 1) . '-01'));
// Validate dates (matches rsa_report/outcomes/funder_report); prevents an attacker-controlled
// value from reaching the CSV/filename and enables formula-injection via the exported cell.
$from = valid_date($_GET['from'] ?? '') ?: $qStart;
$to = valid_date($_GET['to'] ?? '') ?: date('Y-m-d');
// Any date the page could not read, or a swapped pair, is said in words on the page
// (as outcomes.php does), so an unreadable or reversed period never shows an
// unexplained empty $0 report.
$periodNotes = [];
foreach (['from' => ['From', 'the start of this quarter'], 'to' => ['To', 'today']] as $k => [$label, $fallback]) {
    $raw = $_GET[$k] ?? '';
    if (!is_string($raw) || (trim($raw) !== '' && valid_date($raw) === null)) $periodNotes[] = 'The ' . $label . ' date could not be read, so ' . $fallback . ' is used.';
}
if ($to < $from) { [$from, $to] = [$to, $from]; $periodNotes[] = 'The From date was after the To date, so the two were swapped.'; }
$agency = is_string($_GET['agency'] ?? null) ? trim($_GET['agency']) : '';

$rt = report_table('training_tracks', (int)$user['id']);
$ri = report_table('invoices', (int)$user['id']);
// The agencies on tracks now, plus those snapshotted on invoices, so work billed under
// an agency a track has since moved away from can still be selected.
$agencies = db()->query("SELECT funding_agency FROM {$rt} training_tracks
    UNION SELECT funding_agency FROM {$ri} inv WHERE funding_agency IS NOT NULL
    ORDER BY funding_agency")->fetchAll(PDO::FETCH_COLUMN);

// Billed lines are reported under the agency and authorization snapshotted on their
// invoice (generate_invoice), so editing a track later does not move work already
// invoiced; unbilled lines, and invoices from before the snapshot, use the track's.
$agencyExpr = 'COALESCE(i.funding_agency, t.funding_agency)';
$authExpr = 'COALESCE(i.auth_number, t.auth_number)';

// Each log line is rounded to the cent before summing, exactly as track_rollup(),
// log_line_amounts() and generate_invoice() price it, so these amounts reconcile
// with the invoices.
$sql = "SELECT t.id AS track_id, c.name AS client_name, t.title, {$authExpr} AS auth_number, {$agencyExpr} AS funding_agency,
        COALESCE(w.service_type, t.service_type) AS service_type,
        COALESCE(w.rsa_category, t.rsa_category) AS rsa_category,
        b.name AS program,
        SUM(w.hours) hrs, SUM(w.mileage) mi,
        SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2)) hours_amt,
        SUM(ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)) miles_amt,
        0 AS exp_amt
    FROM work_logs w
    JOIN training_tracks t ON t.id = w.track_id
    JOIN clients c ON c.id = t.client_id
    LEFT JOIN invoices i ON i.id = w.invoice_id
    LEFT JOIN service_bundles b ON b.id = t.bundle_id
    WHERE w.work_date BETWEEN ? AND ?";
$params = [$from, $to];
// Caseload-scoped billing users see only their own clients' billing (mirrors report.php / capacity.php).
if (caseload_scoped()) { $sql .= ' AND c.assigned_to = ?'; $params[] = (int)$user['id']; }
if ($agency !== '') { $sql .= " AND {$agencyExpr} = ?"; $params[] = $agency; }
$sql .= " GROUP BY t.id, {$agencyExpr}, {$authExpr}, COALESCE(w.rsa_category, t.rsa_category), COALESCE(w.service_type, t.service_type) ORDER BY {$agencyExpr}, c.name, t.id";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Track expenses are invoiced with the track's hours and mileage (generate_invoice),
// so the reconciliation includes those dated in the range: each track's total (per
// agency and authorization, snapshotted from the invoice as above) goes on that
// track's first row with the same agency and authorization, or on a row of its own
// when there is no such row.
$esql = "SELECT t.id AS track_id, c.name AS client_name, t.title, {$authExpr} AS auth_number, {$agencyExpr} AS funding_agency,
        t.service_type, t.rsa_category, b.name AS program,
        0 AS hrs, 0 AS mi, 0 AS hours_amt, 0 AS miles_amt, SUM(e.amount) AS exp_amt
    FROM track_expenses e
    JOIN training_tracks t ON t.id = e.track_id
    JOIN clients c ON c.id = t.client_id
    LEFT JOIN invoices i ON i.id = e.invoice_id
    LEFT JOIN service_bundles b ON b.id = t.bundle_id
    WHERE e.expense_date BETWEEN ? AND ?";
$eparams = [$from, $to];
if (caseload_scoped()) { $esql .= ' AND c.assigned_to = ?'; $eparams[] = (int)$user['id']; }
if ($agency !== '') { $esql .= " AND {$agencyExpr} = ?"; $eparams[] = $agency; }
$esql .= " GROUP BY t.id, {$agencyExpr}, {$authExpr}";
$estmt = db()->prepare($esql);
$estmt->execute($eparams);
$rowKey = fn(array $r): string => (int)$r['track_id'] . "\0" . $r['funding_agency'] . "\0" . $r['auth_number'];
$firstRow = [];
foreach ($rows as $i => $r) $firstRow[$rowKey($r)] ??= $i;
$expenseOnly = false;
foreach ($estmt->fetchAll() as $e) {
    $key = $rowKey($e);
    if (isset($firstRow[$key])) { $rows[$firstRow[$key]]['exp_amt'] = $e['exp_amt']; continue; }
    $rows[] = $e;
    $expenseOnly = true;
}
// Keep the agency/client order when expense-only rows were appended (usort is stable).
if ($expenseOnly) usort($rows, fn($a, $b) => strcasecmp((string)$a['funding_agency'], (string)$b['funding_agency'])
    ?: strcasecmp((string)$a['client_name'], (string)$b['client_name']));

$rowAmt = fn(array $r): float => (float)$r['hours_amt'] + (float)$r['miles_amt'] + (float)$r['exp_amt'];
$totHrs = array_sum(array_map(fn($r) => (float)$r['hrs'], $rows));
$totMi = array_sum(array_map(fn($r) => (float)$r['mi'], $rows));
$totHoursAmt = array_sum(array_map(fn($r) => (float)$r['hours_amt'], $rows));
$totMilesAmt = array_sum(array_map(fn($r) => (float)$r['miles_amt'], $rows));
$totExp = array_sum(array_map(fn($r) => (float)$r['exp_amt'], $rows));
$totAmt = array_sum(array_map($rowAmt, $rows));

/* CSV export */
if (isset($_GET['export'])) {
    // Plain numbers (no thousands separator) so spreadsheets and accounting imports read them as numbers.
    $num = fn(float $v, int $dp = 2): string => number_format($v, $dp, '.', '');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="agency_summary_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['AGENCY BILLING SUMMARY — ' . cfg('name')]);
    write_csv_row($out, ['Period', csv_cell($from . ' to ' . $to), 'Agency', csv_cell($agency ?: 'All agencies')]);
    write_csv_row($out, []);
    write_csv_row($out, ['Agency', 'Client', 'Training Track', 'RSA Program', 'RSA Category', 'Service Type', 'Auth #', 'Hours', 'Mileage', 'Hours Amount', 'Mileage Amount', 'Expenses', 'Total']);
    foreach ($rows as $r) {
        write_csv_row($out, [csv_cell($r['funding_agency']), csv_cell($r['client_name']), csv_cell($r['title']), csv_cell($r['program'] ?? ($r['rsa_category'] ?? '')), csv_cell($r['rsa_category'] ?? ''), csv_cell($r['service_type'] ?? ''), csv_cell($r['auth_number']),
            $num((float)$r['hrs']), $num((float)$r['mi'], 1),
            $num((float)$r['hours_amt']), $num((float)$r['miles_amt']), $num((float)$r['exp_amt']),
            $num($rowAmt($r))]);
    }
    write_csv_row($out, []);
    // Each total sits under its own column.
    write_csv_row($out, ['TOTAL', '', '', '', '', '', '', $num($totHrs), $num($totMi, 1), $num($totHoursAmt), $num($totMilesAmt), $num($totExp), $num($totAmt)]);
    fclose($out);
    exit;
}

page_header('Agency summary', $user, 'analytics');
?>
<h1>Agency billing summary</h1>
<p class="muted">All billable work in a date range, grouped by authorization — matches how state VR reconciles quarterly.
   Hours and mileage are by session date; expenses (billed on the same invoices) are by expense date and included in the total.</p>
<?php if ($periodNotes): ?><p><?php foreach ($periodNotes as $i => $pn): ?><?= $i ? ' ' : '' ?><?= h($pn) ?><?php endforeach; ?></p><?php endif; ?>

<form method="get" action="agency_report.php" class="card" role="search" aria-label="Report filters">
  <div class="grid grid-4">
    <div class="field"><label for="from">From</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
    <div class="field"><label for="to">To</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
    <div class="field">
      <label for="agency">Funding agency</label>
      <select id="agency" name="agency">
        <option value="">All agencies</option>
        <?php foreach ($agencies as $a): ?>
        <option value="<?= h($a) ?>" <?= $agency === $a ? 'selected' : '' ?>><?= h($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="align-self:end;">
      <button type="submit" class="btn btn-primary">Run report</button>
    </div>
  </div>
</form>

<div class="page-actions">
  <a class="btn btn-secondary" href="agency_report.php?from=<?= h($from) ?>&amp;to=<?= h($to) ?>&amp;agency=<?= h(urlencode($agency)) ?>&amp;export=1">Export CSV</a>
  <a class="btn btn-secondary" href="rsa_report.php?from=<?= h($from) ?>&amp;to=<?= h($to) ?>">RSA-911 case-service export</a>
</div>

<div class="table-scroll" tabindex="0" role="region" aria-label="<?= h('Billable work ' . fmt_date($from) . ' to ' . fmt_date($to)) ?>">
<table>
  <caption>Billable work <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?><?= $agency ? ' · ' . h($agency) : '' ?></caption>
  <thead><tr>
    <th scope="col">Agency</th><th scope="col">Client</th><th scope="col">Track / Auth</th>
    <th scope="col" class="num">Hours</th><th scope="col" class="num">Mileage</th><th scope="col" class="num">Expenses</th><th scope="col" class="num">Total</th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No billable work in this range.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['funding_agency']) ?></td>
      <td><?= h($r['client_name']) ?></td>
      <td><?= h($r['title']) ?><?= $r['program'] ? ' <span class="badge badge-active"><span class="visually-hidden">Program: </span>' . h($r['program']) . '</span>' : '' ?><br>
          <span class="muted">Auth <?= h($r['auth_number']) ?><?= $r['rsa_category'] ? ' · RSA: ' . h($r['rsa_category']) : '' ?></span></td>
      <td class="num"><?= number_format((float)$r['hrs'], 2) ?></td>
      <td class="num"><?= number_format((float)$r['mi'], 1) ?></td>
      <td class="num"><?= fmt_money((float)$r['exp_amt']) ?></td>
      <td class="num"><?= fmt_money($rowAmt($r)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th scope="row" colspan="3">Totals</th>
        <td class="num"><strong><?= number_format($totHrs, 2) ?></strong></td><td class="num"><strong><?= number_format($totMi, 1) ?></strong></td>
        <td class="num"><strong><?= fmt_money($totExp) ?></strong></td><td class="num"><strong><?= fmt_money($totAmt) ?></strong></td></tr>
  </tfoot>
</table>
</div>
<?php page_footer();
