<?php
// Attendance and no-show rates (3.0.49): booked sessions in a period by status, grouped by
// client, instructor, service type or setting, with the clients who missed two or more in
// the last 60 days. Caseload-scoped. CSV download.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$rs = report_table('scheduled_sessions', (int)$user['id']);
$period = report_period($user, date('Y-m-01', strtotime('-2 months')), date('Y-m-d'));
$from = $period['from']; $to = $period['to'];
const ATTENDANCE_BY = ['client' => 'Client', 'instructor' => 'Instructor', 'service' => 'Service type', 'setting' => 'Remote or in person'];
$by = isset(ATTENDANCE_BY[$_GET['by'] ?? '']) ? $_GET['by'] : 'client';
$rows = attendance_rows($from, $to, $by, $rs);
$tot = ['done' => 0, 'noshow' => 0, 'cancelled' => 0, 'still' => 0];
foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (int)$r[$k];
$held = $tot['done'] + $tot['noshow'];
$repeat = repeated_no_shows($rs);
$cell = fn($r) => [(string)(int)$r['done'], (string)(int)$r['noshow'], (string)(int)$r['cancelled'], (string)(int)$r['still'], $r['rate'] === null ? 'n/a' : $r['rate'] . '%'];
$head = [ATTENDANCE_BY[$by], 'Completed', 'No-shows', 'Cancelled', 'Still booked', 'No-show rate'];

if (isset($_GET['export'])) {
    audit((int)$user['id'], 'report.export', 'client', null, 'Attendance CSV ' . $from . ' to ' . $to . ' by ' . $by);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attendance_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, $head);
    foreach ($rows as $r) write_csv_row($out, array_merge([csv_cell((string)$r['label'])], $cell($r)));
    write_csv_row($out, array_merge(['Total'], $cell($tot + ['rate' => $held ? (int)round(100 * $tot['noshow'] / $held) : null])));
    fclose($out);
    exit;
}

page_header('Attendance and no-shows', $user, 'analytics');
$filter = '<label for="by">Count by</label><select id="by" name="by">';
foreach (ATTENDANCE_BY as $k => $l) $filter .= '<option value="' . $k . '"' . ($by === $k ? ' selected' : '') . '>' . h($l) . '</option>';
$filter .= '</select>';
$download = '<button type="submit" name="export" value="1" class="btn btn-secondary btn-sm">Download attendance (CSV)<span class="visually-hidden"> for the period chosen above</span></button>';
?>
<h1>Attendance and no-shows</h1>
<p>Showing <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?>: <?= $tot['done'] ?> completed, <?= $tot['noshow'] ?> no-shows<?= $held ? ' (' . round(100 * $tot['noshow'] / $held) . '% of sessions held or missed)' : '' ?>, <?= $tot['cancelled'] ?> cancelled.<?php foreach ($period['notes'] as $pn): ?> <?= h($pn) ?><?php endforeach; ?></p>
<?= report_period_form('attendance.php', $period, $filter, $download) ?>
<section class="card" aria-labelledby="att-h">
  <h2 id="att-h">Sessions by <?= h(mb_strtolower(ATTENDANCE_BY[$by])) ?></h2>
  <?php if (!$rows): ?><p>No sessions were booked in this period.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="att-cap">
  <table>
    <caption class="visually-hidden" id="att-cap">Sessions by status, by <?= h(mb_strtolower(ATTENDANCE_BY[$by])) ?></caption>
    <thead><tr><?php foreach ($head as $i => $hd): ?><th scope="col"<?= $i ? ' class="num"' : '' ?>><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><th scope="row"><?= $r['client_id'] ? '<a href="client.php?id=' . (int)$r['client_id'] . '">' . h((string)$r['label']) . '</a>' : h((string)$r['label']) ?></th>
        <?php foreach ($cell($r) as $c): ?><td class="num"><?= h($c) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<section class="card" aria-labelledby="rep-h">
  <h2 id="rep-h">Two or more no-shows in the last 60 days</h2>
  <?php if (!$repeat): ?><p>None.</p><?php else: ?>
  <ul><?php foreach ($repeat as $r): ?><li><a href="client.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>: <?= (int)$r['n'] ?> no-shows, the last on <?= fmt_date($r['last_at']) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php page_footer();
