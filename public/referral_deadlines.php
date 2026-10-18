<?php
// Referral deadlines (4.25.0, referrals): how referrals received in a period met their agencies' deadlines to
// contact the person and to hold the first assessment, overall and by agency, with each one late or overdue
// (inc/referral_deadlines.php). Caseload-scoped. CSV download.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('referrals');
$period = report_period($user, date('Y-m-d', strtotime('-90 days')), date('Y-m-d'));
$from = $period['from']; $to = $period['to'];
$rep = referral_deadline_report($from, $to, caseload_scoped() ? (int)$user['id'] : null);
$pct = fn(?int $p) => $p === null ? 'n/a' : $p . '%';
$judged = fn(int $on, int $late, int $over) => $on + $late + $over;

if (isset($_GET['download'])) {
    audit((int)$user['id'], 'report.export', 'referral', null, 'Referral deadlines CSV ' . $from . ' to ' . $to . ' (' . count($rep['rows']) . ' rows)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="referral_deadlines_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['Referral', 'Person referred', 'Agency', 'Received', 'Contact due', 'Contacted', 'Contact', 'Assessment due', 'Assessment held', 'Assessment']);
    foreach ($rep['rows'] as $r) write_csv_row($out, array_map('csv_cell', [referral_ref($r['id']), $r['client_name'], $r['agency'], $r['received'], (string)$r['contact_due'], (string)$r['contact_done'],
        DEADLINE_WORDS[$r['contact']], (string)$r['assess_due'], (string)$r['assess_done'], DEADLINE_WORDS[$r['assess']]]));
    exit;
}
$download = '<a class="btn btn-secondary btn-sm" href="referral_deadlines.php?' . h(http_build_query(['from' => $from, 'to' => $to, 'download' => 1])) . '">Download as a spreadsheet</a>';
$problems = array_values(array_filter($rep['rows'], fn($r) => in_array($r['contact'], ['late', 'overdue'], true) || in_array($r['assess'], ['late', 'overdue'], true)));
page_header('Referral deadlines', $user, 'referrals', [['analytics.php', 'Reports']]);
?>
<h1>Referral deadlines</h1>
<p>Referrals received from <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?>, against each agency's deadlines to contact the person and to hold the first assessment once the referral is accepted (set on Funding agencies; 3 and 10 business days when an agency has none).<?php foreach ($period['notes'] as $n) echo ' ' . h($n); ?></p>
<?= report_period_form('referral_deadlines.php', $period, '', $download) ?>
<?php if (!$rep['referrals']): ?><p>No referrals were received in this period.</p><?php else: ?>
<section class="card" aria-labelledby="sum-h"><h2 id="sum-h">Summary</h2>
  <ul>
    <li><?= $rep['referrals'] ?> referral<?= $rep['referrals'] === 1 ? '' : 's' ?> received.</li>
    <li>Contacted on time: <?= $rep['contacted_on_time'] ?> of <?= $judged($rep['contacted_on_time'], $rep['contacted_late'], $rep['overdue_now']) ?>, late: <?= $rep['contacted_late'] ?>, still not contacted and overdue: <?= $rep['overdue_now'] ?>, not due yet: <?= $rep['waiting'] ?>.</li>
    <li>First assessment on time: <?= $rep['assessed_on_time'] ?> of <?= $judged($rep['assessed_on_time'], $rep['assessed_late'], $rep['assess_overdue']) ?>, late: <?= $rep['assessed_late'] ?>, overdue now: <?= $rep['assess_overdue'] ?>.</li>
  </ul>
</section>
<section class="card" aria-labelledby="ag-h"><h2 id="ag-h">By agency</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="ag-cap"><table>
    <caption class="visually-hidden" id="ag-cap">Referral deadlines met, by agency</caption>
    <thead><tr><th scope="col">Agency</th><th scope="col" class="num">Referrals</th><th scope="col" class="num">Contacted on time</th><th scope="col" class="num">Assessed on time</th></tr></thead>
    <tbody><?php foreach ($rep['by_agency'] as $a): ?><tr><th scope="row"><?= h($a['agency']) ?></th><td class="num"><?= (int)$a['referrals'] ?></td><td class="num"><?= h($pct($a['contact_on_time_pct'])) ?></td><td class="num"><?= h($pct($a['assess_on_time_pct'])) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<section class="card" aria-labelledby="late-h"><h2 id="late-h">Late or overdue (<?= count($problems) ?>)</h2>
  <?php if (!$problems): ?><p>None: every deadline due was met.</p><?php else: ?>
  <ul><?php foreach ($problems as $r): ?>
    <li><a href="referrals.php?view=<?= (int)$r['id'] ?>"><?= h($r['client_name']) ?></a>, <?= h($r['agency']) ?>, received <?= h(fmt_date($r['received'])) ?>.
      Contact: <?= h(DEADLINE_WORDS[$r['contact']]) ?><?= $r['contact_due'] ? ' (due ' . h(fmt_date($r['contact_due'])) . ($r['contact_done'] ? ', done ' . h(fmt_date($r['contact_done'])) : '') . ')' : '' ?>.
      Assessment: <?= h(DEADLINE_WORDS[$r['assess']]) ?><?= $r['assess_due'] ? ' (due ' . h(fmt_date($r['assess_due'])) . ($r['assess_done'] ? ', done ' . h(fmt_date($r['assess_done'])) : '') . ')' : '' ?>.</li>
  <?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_footer();
