<?php
// Unused authorizations (3.0.49): VR authorizations that ended in a period (the track was
// marked Completed or Cancelled then, or its expiration date fell in it), with hours and
// dollars authorized, delivered, invoiced and left unused, overall and by funding agency,
// service type and instructor. Caseload-scoped. CSV download.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$rt = report_table('training_tracks', (int)$user['id']);
$period = report_period($user, fiscal_year_of(date('Y-m-d'))[0], date('Y-m-d'));
$from = $period['from']; $to = $period['to'];
// Figures stop at today: a period that runs on (this quarter, the whole fiscal year) is counted to date.
if ($to > date('Y-m-d') && $from <= date('Y-m-d')) { $to = date('Y-m-d'); $period['notes'][] = 'Counted to today, ' . fmt_date($to) . '.'; }
$rows = unused_authorizations($from, $to, $rt);
$all = unused_totals($rows)['All'] ?? null;
$groups = ['funding_agency' => 'Funding agency', 'service_type' => 'Service type', 'instructor' => 'Instructor'];
$totRow = fn(string $label, array $g) => [$label, (string)$g['tracks'], number_format($g['hours_authorized'], 2), number_format($g['hours_done'], 2), number_format($g['hours_unused'], 2),
    fmt_money($g['authorized_value']), fmt_money($g['value_done']), fmt_money($g['invoiced']), fmt_money($g['value_unused'])];
$totHead = ['', 'Authorizations', 'Hours authorized', 'Hours delivered', 'Hours unused', 'Dollars authorized', 'Value delivered', 'Invoiced', 'Dollars unused'];

if (isset($_GET['export'])) {
    audit((int)$user['id'], 'report.export', 'client', null, 'Unused authorizations CSV ' . $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="unused_authorizations_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['Ended', 'Client', 'Authorization', 'Funding agency', 'Service type', 'Instructor', 'Status', 'Hours authorized', 'Hours delivered', 'Hours unused',
        'Dollars authorized', 'Value delivered', 'Invoiced', 'Dollars unused', 'Percent used']);
    foreach ($rows as $r) write_csv_row($out, [$r['ended_on'], csv_cell($r['client_name']), csv_cell($r['auth_number']), csv_cell($r['funding_agency']), $r['service_type'], csv_cell($r['instructor']), $r['status'],
        number_format((float)$r['authorized_hours'], 2, '.', ''), number_format((float)$r['hours_done'], 2, '.', ''), number_format($r['hours_unused'], 2, '.', ''),
        number_format($r['authorized_value'], 2, '.', ''), number_format((float)$r['value_done'], 2, '.', ''), number_format((float)$r['invoiced'], 2, '.', ''), number_format($r['value_unused'], 2, '.', ''), $r['pct_used'] ?? '']);
    fclose($out);
    exit;
}

page_header('Unused authorizations', $user, 'analytics');
$download = '<button type="submit" name="export" value="1" class="btn btn-secondary btn-sm">Download unused authorizations (CSV)<span class="visually-hidden"> for the period chosen above</span></button>';
$table = function (string $id, string $caption, array $head, array $rows): string {
    $o = '<div class="table-scroll" tabindex="0" role="region" aria-labelledby="' . $id . '-cap"><table><caption class="visually-hidden" id="' . $id . '-cap">' . h($caption) . '</caption><thead><tr>';
    foreach ($head as $i => $hd) $o .= '<th scope="col"' . ($i ? ' class="num"' : '') . '>' . h($hd) . '</th>';
    $o .= '</tr></thead><tbody>';
    foreach ($rows as $r) { $o .= '<tr><th scope="row">' . $r[0] . '</th>'; foreach (array_slice($r, 1) as $c) $o .= '<td class="num">' . h($c) . '</td>'; $o .= '</tr>'; }
    return $o . '</tbody></table></div>';
};
?>
<h1>Unused authorizations</h1>
<p>VR authorizations that ended from <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?>: the track was marked Completed or Cancelled then, or its expiration date fell in the period.<?php foreach ($period['notes'] as $pn): ?> <?= h($pn) ?><?php endforeach; ?></p>
<?= report_period_form('unused_authorizations.php', $period, '', $download) ?>
<?php if (!$all): ?><p>No authorizations ended in this period.</p><?php else: ?>
<p><?= $all['tracks'] ?> authorization<?= $all['tracks'] === 1 ? '' : 's' ?>: <?= fmt_money($all['value_unused']) ?> and <?= number_format($all['hours_unused'], 2) ?> hours left unused of <?= fmt_money($all['authorized_value']) ?> authorized.</p>
<?php foreach ($groups as $key => $label): $g = unused_totals($rows, $key); ?>
<section class="card" aria-labelledby="ua-<?= $key ?>"><h2 id="ua-<?= $key ?>">By <?= h(mb_strtolower($label)) ?></h2>
  <?= $table('ua-' . $key . '-t', 'Unused authorizations by ' . mb_strtolower($label), array_merge([$label], array_slice($totHead, 1)), array_map(fn($k, $v) => $totRow(h($k), $v), array_keys($g), $g)) ?></section>
<?php endforeach; ?>
<section class="card" aria-labelledby="ua-each"><h2 id="ua-each">Each authorization</h2>
  <?= $table('ua-each-t', 'Each authorization that ended, with what was left unused', ['Client and authorization', 'Ended', 'Hours unused', 'Dollars unused', 'Percent used'],
      array_map(fn($r) => ['<a href="track.php?id=' . (int)$r['id'] . '">' . h($r['client_name'] . ', ' . $r['auth_number']) . '</a>', fmt_date($r['ended_on']), number_format($r['hours_unused'], 2), fmt_money($r['value_unused']), $r['pct_used'] === null ? 'n/a' : $r['pct_used'] . '%'], $rows)) ?></section>
<?php endif; ?>
<?php page_footer();
