<?php
// Staff billable-utilization dashboard: for a period, each staff member's logged
// (billable) service hours and value against their expected capacity
// (weekly_hours x weeks), with a utilization rate. Retrospective companion to the
// forward-looking Capacity page.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require_once __DIR__ . '/inc/utilization.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
// The shared period picker (remembered per user); the last four weeks when nothing is
// chosen. A repeated parameter (from[]=…) arrives as an array; it falls back to the default.
$period = report_period($user, date('Y-m-d', strtotime('-28 days')), date('Y-m-d'));
$from = $period['from']; $to = $period['to'];
// 4.38.0: the hours, value and percentages from inc/utilization.php, shared with the apps.
$u = utilization_report($user, $from, $to);
$weeks = $u['weeks'];
$rows = $u['rows'];
$totDeliv = $u['delivered']; $totExp = $u['expected']; $totVal = $u['value'];
$activeDeliv = $u['active_delivered']; $noTargetDeliv = $u['no_target_delivered'];
$orgUtil = $u['team_util'];
// Former / unattributed staff, listed last.
if ($u['former'] !== null) {
    $rows[] = ['name' => 'Former or unrecorded staff', 'weekly' => 0.0, 'delivered' => $u['former']['delivered'],
        'value' => $u['former']['value'], 'clients' => $u['former']['clients'], 'expected' => 0.0, 'util' => null];
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
    if ($u['former'] !== null || $noTargetDeliv > 0) {
        $left = [];
        if ($noTargetDeliv > 0) $left[] = round($noTargetDeliv, 2) . ' hours by active staff with no weekly target';
        if ($u['former'] !== null) $left[] = round($u['former']['delivered'], 2) . ' hours by former or unrecorded staff';
        write_csv_row($out, ['NOTE', 'Utilization % compares the ' . round($activeDeliv, 2) . ' hours delivered by active staff with a weekly target with their expected hours. The '
            . implode(' and the ', $left) . ' are included in Delivered hours and Billable value but not in Utilization %.']);
    }
    fclose($out); exit;
}

page_header('Utilization', $user, 'analytics');
?>
<h1>Staff billable utilization</h1>
<?php if (caseload_scoped()): ?><p><?= h(UTILIZATION_SCOPED_NOTE) ?></p><?php endif;?>
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
<p class="muted"><?= h(UTILIZATION_FOOTER) ?></p>
<?php page_footer();
