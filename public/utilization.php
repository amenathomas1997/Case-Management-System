<?php
// Staff billable-utilization dashboard: for a period, each staff member's logged
// (billable) service hours and value against their expected capacity
// (weekly_hours x weeks), with a utilization rate. Retrospective companion to the
// forward-looking Capacity page.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$rt = report_table('training_tracks', (int)$user['id']);

// The shared period picker (remembered per user); the last four weeks when nothing is
// chosen. A repeated parameter (from[]=…) arrives as an array; it falls back to the default.
$period = report_period($user, date('Y-m-d', strtotime('-28 days')), date('Y-m-d'));
$from = $period['from']; $to = $period['to'];
// Calendar days, counted as equipment.php and contracts.php count them: subtracting two
// local midnights gives a fractional day across a daylight-saving change.
$days = (new DateTime($from))->diff(new DateTime($to))->days + 1;
$weeks = max(0.1, $days / 7);

$staff = db()->query('SELECT id, name, weekly_hours FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();

// Delivered billable hours + value per staff, from the work logs they recorded.
// Logs by a deactivated user (or with no recorded user) are grouped under 0, so the
// totals still include them and reconcile with invoices and track hours.
// Each log's value is its hours and mileage, each rounded to the cent first, as
// generate_invoice(), unbilled_totals() and track_rollup() price it.
$dq = db()->prepare("SELECT COALESCE(u.id, 0) AS uid, COALESCE(SUM(w.hours),0) AS hrs,
        COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) AS val,
        COUNT(DISTINCT t.client_id) AS clients
    FROM work_logs w JOIN {$rt} t ON t.id = w.track_id
    LEFT JOIN users u ON u.id = w.created_by AND u.is_active = 1
    WHERE w.work_date BETWEEN ? AND ? GROUP BY COALESCE(u.id, 0)");
$dq->execute([$from, $to]);
$byUser = [];
foreach ($dq->fetchAll() as $r) $byUser[(int)$r['uid']] = $r;

$rows = [];
$totDeliv = 0.0; $totExp = 0.0; $totVal = 0.0;
// Hours by active staff who have a weekly target (the utilization numerator), and by
// active staff whose target is 0 (no target: like former staff, their hours count in
// the totals but not in the percentage).
$activeDeliv = 0.0; $noTargetDeliv = 0.0;
foreach ($staff as $s) {
    $d = $byUser[(int)$s['id']] ?? ['hrs' => 0, 'val' => 0, 'clients' => 0];
    $expected = (float)$s['weekly_hours'] * $weeks;
    $util = $expected > 0 ? round((float)$d['hrs'] / $expected * 100) : null;
    $rows[] = ['name' => $s['name'], 'weekly' => (float)$s['weekly_hours'], 'delivered' => (float)$d['hrs'],
        'value' => (float)$d['val'], 'clients' => (int)$d['clients'], 'expected' => $expected, 'util' => $util];
    $totDeliv += (float)$d['hrs']; $totExp += $expected; $totVal += (float)$d['val'];
    if ($expected > 0) $activeDeliv += (float)$d['hrs']; else $noTargetDeliv += (float)$d['hrs'];
}
// Highest utilization first; staff with no target last.
usort($rows, fn($a, $b) => [$a['util'] === null, $b['util'] ?? 0] <=> [$b['util'] === null, $a['util'] ?? 0]);
// Team utilization compares the delivered hours of active staff who have a target
// with their own capacity.
$orgUtil = $totExp > 0 ? round($activeDeliv / $totExp * 100) : 0;
// Former / unattributed staff: their delivered work counts in the hour and value
// totals, but there is no capacity target to measure it against. Listed last.
if (isset($byUser[0])) {
    $d = $byUser[0];
    $rows[] = ['name' => 'Former or unrecorded staff', 'weekly' => 0.0, 'delivered' => (float)$d['hrs'],
        'value' => (float)$d['val'], 'clients' => (int)$d['clients'], 'expected' => 0.0, 'util' => null];
    $totDeliv += (float)$d['hrs']; $totVal += (float)$d['val'];
}

if (isset($_GET['export'])) {
    audit((int)$user['id'], 'report.export', 'client', null, 'Utilization CSV ' . $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="utilization_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['STAFF UTILIZATION', $from . ' to ' . $to]);
    // Same caveat as the page: a forwarded file must not read as the whole team's activity.
    if (caseload_scoped()) write_csv_row($out, ['SCOPE', 'Only work logged on your assigned clients; expected hours are each staff member\'s full capacity']);
    write_csv_row($out, ['Staff', 'Weekly target', 'Expected hours', 'Delivered hours', 'Utilization %', 'Billable value', 'Clients']);
    foreach ($rows as $r) write_csv_row($out, [csv_cell($r['name']), $r['weekly'], round($r['expected'], 1), round($r['delivered'], 2), $r['util'] === null ? '' : $r['util'] . '%', round($r['value'], 2), $r['clients']]);
    write_csv_row($out, ['TOTAL', '', round($totExp, 1), round($totDeliv, 2), $orgUtil . '%', round($totVal, 2), '']);
    // Same explanation as the page: Delivered includes former staff and staff with no
    // weekly target, the utilization % does not.
    if (isset($byUser[0]) || $noTargetDeliv > 0) {
        $left = [];
        if ($noTargetDeliv > 0) $left[] = round($noTargetDeliv, 2) . ' hours by active staff with no weekly target';
        if (isset($byUser[0])) $left[] = round((float)$byUser[0]['hrs'], 2) . ' hours by former or unrecorded staff';
        write_csv_row($out, ['NOTE', 'Utilization % compares the ' . round($activeDeliv, 2) . ' hours delivered by active staff with a weekly target with their expected hours. The '
            . implode(' and the ', $left) . ' are included in Delivered hours and Billable value but not in Utilization %.']);
    }
    fclose($out); exit;
}

page_header('Utilization', $user, 'analytics');
?>
<h1>Staff billable utilization</h1>
<?php if (caseload_scoped()): ?><p>Activity is limited to your assigned clients. Expected hours remain the staff members’ full capacity targets.</p><?php endif;?>
<p class="muted">Billable service hours delivered against expected capacity (weekly target × weeks in the period). A companion to <a href="capacity.php">Capacity planning</a>.</p>

<?= report_period_form('utilization.php', $period, '', '<a class="btn btn-secondary btn-sm" href="utilization.php?from=' . h($from) . '&amp;to=' . h($to) . '&amp;export=1">Download utilization report (CSV)</a>') ?>
<?php if ($period['notes']): ?><p><?= h(implode(' ', $period['notes'])) ?></p><?php endif; ?>

<?php $periodText = fmt_date($from) . ' to ' . fmt_date($to); ?>
<h2 id="util-sum-h">Summary for <?= h($periodText) ?></h2>
<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= $orgUtil ?>%</span><span class="stat-label">Team utilization</span></div>
  <div class="card stat"><span class="stat-num"><?= fmt_hours($totDeliv) ?></span><span class="stat-label">Billable hours delivered</span></div>
  <div class="card stat"><span class="stat-num"><?= fmt_money($totVal) ?></span><span class="stat-label">Billable value</span></div>
  <div class="card stat"><span class="stat-num"><?= round($weeks, 1) ?></span><span class="stat-label">Weeks in period</span></div>
</div>

<h2 id="util-h">Staff utilization</h2>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="util-h">
<table>
  <caption class="visually-hidden">Staff billable utilization, <?= h($periodText) ?></caption>
  <thead><tr><th scope="col">Staff</th><th scope="col" class="num">Weekly target (hours)</th><th scope="col" class="num">Expected</th><th scope="col" class="num">Delivered</th><th scope="col">Utilization</th><th scope="col" class="num">Value</th><th scope="col" class="num">Clients</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No active staff.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?= h($r['name']) ?></th>
      <td class="num"><?= rtrim(rtrim(number_format($r['weekly'], 2), '0'), '.') ?></td>
      <td class="num"><?= fmt_hours($r['expected']) ?></td>
      <td class="num"><?= fmt_hours($r['delivered']) ?></td>
      <td><?php if ($r['util'] === null): ?><span class="muted">no target</span><?php else: ?>
        <strong<?= $r['util'] >= 100 ? ' class="field-error"' : '' ?>><?= $r['util'] ?>%</strong><?= $r['util'] >= 100 ? ' <span class="muted">at or above target</span>' : '' ?>
        <div class="progress-wrap" aria-hidden="true"><progress class="hours" max="100" value="<?= min(100, $r['util']) ?>"><?= $r['util'] ?>%</progress></div><?php endif; ?></td>
      <td class="num"><?= fmt_money($r['value']) ?></td>
      <td class="num"><?= $r['clients'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted">Billable hours are the service hours each staff member recorded in their work logs; value is those hours and their logged mileage at the logged rates, as invoiced. Utilization over 100% means more was delivered than the weekly target implies.
   Hours logged by staff who are no longer active (or with no recorded staff member) are shown as “Former or unrecorded staff”: they are included in the hours and value totals, but not in team utilization, which compares active staff with their own targets.
   Staff whose weekly target is 0 show “no target”: their hours are likewise in the totals but not in team utilization.</p>
<?php page_footer();
