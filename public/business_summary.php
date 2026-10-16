<?php
// Business summary (3.0.49): how the business is doing in a period, compared with the
// period before and the same dates last year, month by month, with the open caseload by
// instructor and status. CSV and accessible HTML downloads; an AI narrative from the figures.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require_once __DIR__ . '/inc/report_period.php';
require_once __DIR__ . '/inc/scheduled_reports.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$uid = (int)$user['id'];
$rt = report_table('training_tracks', $uid); $rc = report_table('clients', $uid);
$rh = report_table('client_status_history', $uid); $rs = report_table('scheduled_sessions', $uid);
$scopeUser = caseload_scoped() ? $uid : null;

$period = report_period($user, fiscal_year_of(date('Y-m-d'))[0], date('Y-m-d'));
$from = $period['from']; $to = $period['to'];
// Figures stop at today: a period that runs on (this quarter, the whole fiscal year) is counted to date.
if ($to > date('Y-m-d') && $from <= date('Y-m-d')) { $to = date('Y-m-d'); $period['notes'][] = 'Counted to today, ' . fmt_date($to) . '.'; }
$cmp = comparison_periods($from, $to);
$fig = fn(array $p) => business_figures($p[0], $p[1], $rt, $rc, $rh, $rs, $scopeUser);
$now = $fig([$from, $to]); $prev = $fig($cmp['previous']); $ly = $fig($cmp['last_year']);
$months = [];
$monthStarts = months_between($from, $to);
if (count($monthStarts) === 24 && $monthStarts[23] < substr($to, 0, 7) . '-01') $period['notes'][] = 'Month by month shows the first 24 months.';
foreach ($monthStarts as $ms) $months[$ms] = $fig([max($ms, $from), min(date('Y-m-t', strtotime($ms)), $to)]);
$caseload = open_caseload($rc);
$monthCols = ['referrals', 'new_clients', 'clients_served', 'sessions_completed', 'no_shows', 'hours', 'cases_closed', 'rehab_rate'];
$range = fn(array $p) => fmt_date($p[0]) . ' to ' . fmt_date($p[1]);
$cmpRows = array_map(fn($k) => [BUSINESS_FIGURE_LABELS[$k][0], business_figure_text($now[$k], BUSINESS_FIGURE_LABELS[$k][1]),
    business_figure_text($prev[$k], BUSINESS_FIGURE_LABELS[$k][1]), business_change_text($now[$k], $prev[$k], BUSINESS_FIGURE_LABELS[$k][1]),
    business_figure_text($ly[$k], BUSINESS_FIGURE_LABELS[$k][1]), business_change_text($now[$k], $ly[$k], BUSINESS_FIGURE_LABELS[$k][1])], array_keys(BUSINESS_FIGURE_LABELS));
$cmpHead = ['Figure', 'This period', 'Previous period', 'Change', 'Same dates last year', 'Change'];
$monthRows = array_map(fn($ms, $f) => array_merge([date('F Y', strtotime($ms))], array_map(fn($k) => business_figure_text($f[$k], BUSINESS_FIGURE_LABELS[$k][1]), $monthCols)), array_keys($months), $months);
$monthHead = array_merge(['Month'], array_map(fn($k) => BUSINESS_FIGURE_LABELS[$k][0], $monthCols));

if (isset($_GET['export'])) {
    audit($uid, 'report.export', 'client', null, 'Business summary CSV ' . $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="business_summary_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['BUSINESS SUMMARY — ' . cfg('name')]);
    write_csv_row($out, ['Period', $range([$from, $to]), 'Previous period', $range($cmp['previous']), 'Same dates last year', $range($cmp['last_year'])]);
    write_csv_row($out, []);
    write_csv_row($out, $cmpHead);
    foreach ($cmpRows as $r) write_csv_row($out, array_map('csv_cell', $r));
    write_csv_row($out, []);
    write_csv_row($out, $monthHead);
    foreach ($monthRows as $r) write_csv_row($out, array_map('csv_cell', $r));
    write_csv_row($out, []);
    write_csv_row($out, ['OPEN CASELOAD NOW']);
    write_csv_row($out, ['Instructor', 'Status', 'Clients']);
    foreach ($caseload as $r) write_csv_row($out, [csv_cell($r['instructor']), csv_cell($r['status']), (int)$r['n']]);
    fclose($out);
    exit;
}
if (isset($_GET['html'])) {
    audit($uid, 'report.export', 'client', null, 'Business summary HTML ' . $from . ' to ' . $to);
    $doc = new AccessibleDocument('Business summary — ' . brand('org'));
    $doc->properties(['Period' => $range([$from, $to]), 'Previous period' => $range($cmp['previous']), 'Same dates last year' => $range($cmp['last_year'])]);
    $doc->heading('This period compared');
    $doc->table('Figures for this period, the previous period and the same dates last year', $cmpHead, $cmpRows);
    $doc->heading('Month by month');
    $doc->table('Figures by month', $monthHead, $monthRows);
    $doc->heading('Open caseload now');
    if ($caseload) $doc->table('Open clients by instructor and status', ['Instructor', 'Status', 'Clients'], array_map(fn($r) => [$r['instructor'], $r['status'], (string)$r['n']], $caseload));
    else $doc->paragraph('No open clients.');
    $doc->paragraph('Prepared ' . date('F j, Y') . ' by ' . $user['name'] . ' — ' . cfg('name'));
    $doc->download('business_summary_' . $from . '_' . $to . '.html');
}

$aiNarrative = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ai_narrative'])) {
    csrf_check();
    $facts = 'Period: ' . $range([$from, $to]) . "\n" . implode("\n", array_map(fn($r) => $r[0] . ': ' . $r[1] . ' (previous period ' . $r[2] . ', ' . $r[3] . '; last year ' . $r[4] . ', ' . $r[5] . ')', $cmpRows));
    $nr = report_ai_narrative('business summary', $facts, $uid);
    if ($nr['ok']) { $aiNarrative = $nr['text']; audit($uid, 'ai.draft', 'report', null, 'business summary narrative'); } else flash($nr['error'], 'error');
}

page_header('Business summary', $user, 'analytics');
$downloads = '<button type="submit" name="export" value="1" class="btn btn-secondary btn-sm">Download business summary (CSV)<span class="visually-hidden"> for the period chosen above</span></button>'
    . '<button type="submit" name="html" value="1" class="btn btn-secondary btn-sm">Download accessible HTML summary<span class="visually-hidden"> for the period chosen above</span></button>';
$table = function (string $id, string $caption, array $head, array $rows): string {
    $o = '<div class="table-scroll" tabindex="0" role="region" aria-labelledby="' . $id . '-cap"><table><caption class="visually-hidden" id="' . $id . '-cap">' . h($caption) . '</caption><thead><tr>';
    foreach ($head as $i => $hd) $o .= '<th scope="col"' . ($i ? ' class="num"' : '') . '>' . h($hd) . '</th>';
    $o .= '</tr></thead><tbody>';
    foreach ($rows as $r) { $o .= '<tr><th scope="row">' . h($r[0]) . '</th>'; foreach (array_slice($r, 1) as $c) $o .= '<td class="num">' . h($c) . '</td>'; $o .= '</tr>'; }
    return $o . '</tbody></table></div>';
};
?>
<h1>Business summary</h1>
<p>Showing <?= h($range([$from, $to])) ?>, compared with <?= h($range($cmp['previous'])) ?> and <?= h($range($cmp['last_year'])) ?>.<?php foreach ($period['notes'] as $pn): ?> <?= h($pn) ?><?php endforeach; ?></p>
<?= report_period_form('business_summary.php', $period, '', $downloads) ?>
<?= report_ai_narrative_card('business_summary.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to), $aiNarrative, 'this business summary') ?>
<section class="card" aria-labelledby="bs-cmp"><h2 id="bs-cmp">This period compared</h2>
  <?= $table('bs-cmp-t', 'Figures for this period, the previous period and the same dates last year', $cmpHead, $cmpRows) ?></section>
<section class="card" aria-labelledby="bs-month"><h2 id="bs-month">Month by month</h2>
  <?= $table('bs-month-t', 'Figures by month', $monthHead, $monthRows) ?></section>
<section class="card" aria-labelledby="bs-case"><h2 id="bs-case">Open caseload now</h2>
  <?= $caseload ? $table('bs-case-t', 'Open clients by instructor and status', ['Instructor and status', 'Clients'], array_map(fn($r) => [$r['instructor'] . ', ' . $r['status'], (string)$r['n']], $caseload)) : '<p>No open clients.</p>' ?></section>
<?php page_footer();
