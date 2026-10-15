<?php
// Capacity planning: committed session hours vs available hours per instructor,
// plus authorized-but-unscheduled hours per active track.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('schedule');
$rs = report_table('scheduled_sessions', (int)$user['id']);

$staff = db()->query('SELECT id, name, weekly_hours FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();

// The shared period picker (remembered per user); this week and the three after it
// when nothing is chosen. The weeks shown are the Monday-to-Sunday weeks that cover
// the period, at most 13 (a longer period shows its first 13 weeks and says so).
$thisMonday = strtotime('monday this week');
$period = report_period($user, date('Y-m-d', $thisMonday), date('Y-m-d', $thisMonday + 27 * 86400));
$weeks = [];
$start = strtotime('monday this week', strtotime($period['from']));
$weeksCapped = false;
while (date('Y-m-d', $start) <= $period['to']) {
    if (count($weeks) === 13) { $weeksCapped = true; break; }
    $weeks[] = ['start' => date('Y-m-d', $start), 'end' => date('Y-m-d', $start + 6 * 86400),
                'label' => date('M j', $start) . ' – ' . date('M j', $start + 6 * 86400)];
    $start = strtotime('+1 week', $start);
}

// An instructor's committed hours for the week. A group session is stored as one row
// per participant (same instructor, time and group_uid), so it is collapsed to one
// sitting before summing. Sessions already held (Completed / No Show) still used the
// instructor's time this week; only cancelled ones are left out.
$sched = db()->prepare("SELECT user_id, SUM(mins) / 60 AS hrs FROM (
        SELECT user_id, MAX(TIMESTAMPDIFF(MINUTE, start_at, end_at)) AS mins
        FROM {$rs} scheduled_sessions
        WHERE status IN ('Scheduled','Completed','No Show') AND DATE(start_at) BETWEEN ? AND ?
        GROUP BY user_id, start_at, COALESCE(group_uid, CONCAT('s', id))
    ) x GROUP BY user_id");

$byWeek = [];
foreach ($weeks as $i => $wk) {
    $sched->execute([$wk['start'], $wk['end']]);
    foreach ($sched->fetchAll() as $row) $byWeek[$i][(int)$row['user_id']] = (float)$row['hrs'];
}

$scoped = caseload_scoped();
$tq = db()->prepare("SELECT t.id, t.title, t.authorized_hours, c.name AS client_name, c.id AS cid,
        (SELECT COALESCE(SUM(hours),0) FROM work_logs WHERE track_id = t.id) AS worked,
        (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, start_at, end_at)),0) / 60 FROM scheduled_sessions
            WHERE track_id = t.id AND status = 'Scheduled' AND end_at >= NOW()) AS upcoming
    FROM training_tracks t JOIN clients c ON c.id = t.client_id
    WHERE t.status = 'Active'" . ($scoped ? ' AND c.assigned_to = ?' : '') . " ORDER BY c.name");
$tq->execute($scoped ? [(int)$user['id']] : []);
$tracks = $tq->fetchAll();

page_header('Capacity', $user, 'schedule');
?>
<p><a href="schedule.php"><span aria-hidden="true">&larr; </span>Back to schedule</a></p>
<h1>Capacity planning</h1>
<div class="page-actions"><a class="btn btn-secondary btn-sm" href="utilization.php">Billable utilization (retrospective)</a></div>
<?php if ($scoped): ?><p>Committed hours are limited to sessions with your assigned clients. Available-hours targets remain the staff members’ full capacity.</p><?php endif; ?>
<?= report_period_form('capacity.php', $period) ?>
<?php if ($period['notes'] || $weeksCapped): ?><p><?= h(implode(' ', $period['notes'])) ?><?= $weeksCapped ? ' Only the first 13 weeks of the period are shown; choose a shorter period to see later weeks.' : '' ?></p><?php endif; ?>

<section class="card" aria-labelledby="cap-h">
  <h2 id="cap-h">Committed vs available hours (<?= count($weeks) ?> week<?= count($weeks) === 1 ? '' : 's' ?> from <?= h(fmt_date($weeks[0]['start'])) ?>)</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="cap-cap">
  <table>
    <caption class="visually-hidden" id="cap-cap">Booked and available hours per instructor for each of the next 4 weeks</caption>
    <thead><tr><th scope="col">Instructor</th>
      <?php foreach ($weeks as $wk): ?><th scope="col" class="num"><?= h($wk['label']) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($staff as $s): ?>
      <tr>
        <th scope="row"><?= h($s['name']) ?> <span class="muted">(<?= h(rtrim(rtrim(number_format((float)$s['weekly_hours'], 2), '0'), '.')) ?> <?= (float)$s['weekly_hours'] == 1.0 ? 'hour' : 'hours' ?> a week)</span></th>
        <?php foreach ($weeks as $i => $wk):
            $booked = $byWeek[$i][(int)$s['id']] ?? 0.0;
            $avail = (float)$s['weekly_hours'];
            $over = $avail > 0 && $booked > $avail;
        ?>
        <td class="num <?= $over ? 'field-error' : '' ?>">
          <?= number_format($booked, 1) ?> / <?= number_format($avail, 1) ?>
          <?= $over ? '<br>Overbooked' : '' ?>
        </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">Booked hours / available hours. Booked hours count each session once (a group session once, however many attend) and include sessions already held this week; cancelled sessions are not counted. A week with more booked than available hours is marked “Overbooked”. Set each instructor's weekly available hours on the My Account page (admin).</p>
</section>

<section class="card" aria-labelledby="unsch-h">
  <h2 id="unsch-h">Authorized hours not yet scheduled</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="unsch-cap">
  <table>
    <caption class="visually-hidden" id="unsch-cap">Remaining, scheduled and unscheduled authorized hours for each active track</caption>
    <thead><tr><th scope="col">Client / Track</th><th scope="col" class="num">Remaining on auth</th>
      <th scope="col" class="num">Upcoming scheduled</th><th scope="col" class="num">Unscheduled</th></tr></thead>
    <tbody>
    <?php if (!$tracks): ?><tr><td colspan="4" class="muted">No active tracks.</td></tr><?php endif; ?>
    <?php foreach ($tracks as $t):
        $remaining = max(0, (float)$t['authorized_hours'] - (float)$t['worked']);
        $unscheduled = max(0, $remaining - (float)$t['upcoming']);
    ?>
      <tr>
        <th scope="row"><?= can('clients') ? '<a href="client.php?id=' . (int)$t['cid'] . '">' . h($t['client_name']) . '</a>' : h($t['client_name']) ?><br><span class="muted"><?= h($t['title']) ?></span></th>
        <td class="num"><?= number_format($remaining, 1) ?></td>
        <td class="num"><?= number_format((float)$t['upcoming'], 1) ?></td>
        <td class="num"><?= $unscheduled > 0 ? '<strong>' . number_format($unscheduled, 1) . '</strong>' : number_format($unscheduled, 1) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">"Unscheduled" hours are authorized and billable but have no session on the calendar yet — schedule them before the authorization expires.</p>
</section>
<?php page_footer();
