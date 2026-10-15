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

// Authorized hours versus hours left, by instructor, for the chosen period: the
// hours authorized on the active tracks of the clients assigned to each instructor,
// what was logged on them in the period, what is left on those authorizations, the
// sessions still ahead in the period, and the instructor's available hours (weekly
// hours × the weeks in the period), so an overload is visible before it happens.
// A caseload-scoped user sees only their own row.
$periodDays = (int)round((strtotime($period['to']) - strtotime($period['from'])) / 86400) + 1;
$periodWeeks = $periodDays / 7;
$loadStaff = $scoped ? array_values(array_filter($staff, fn($s) => (int)$s['id'] === (int)$user['id'])) : $staff;
$authQ = db()->prepare("SELECT COALESCE(SUM(t.authorized_hours),0) AS authorized,
        COALESCE(SUM(wa.h),0) AS worked_all, COALESCE(SUM(wp.h),0) AS worked_period
    FROM training_tracks t JOIN clients c ON c.id = t.client_id
    LEFT JOIN (SELECT track_id, SUM(hours) h FROM work_logs GROUP BY track_id) wa ON wa.track_id = t.id
    LEFT JOIN (SELECT track_id, SUM(hours) h FROM work_logs WHERE work_date BETWEEN ? AND ? GROUP BY track_id) wp ON wp.track_id = t.id
    WHERE c.assigned_to = ? AND t.status = 'Active' AND c.archived_at IS NULL");
// Sessions still ahead: one sitting per group session, as the weekly table counts them.
$aheadQ = db()->prepare("SELECT COALESCE(SUM(mins),0) / 60 FROM (
        SELECT MAX(TIMESTAMPDIFF(MINUTE, start_at, end_at)) AS mins FROM {$rs} scheduled_sessions
        WHERE user_id = ? AND status = 'Scheduled' AND start_at >= NOW() AND DATE(start_at) BETWEEN ? AND ?
        GROUP BY start_at, COALESCE(group_uid, CONCAT('s', id))
    ) x");
$load = [];
foreach ($loadStaff as $s) {
    $authQ->execute([$period['from'], $period['to'], (int)$s['id']]);
    $a = $authQ->fetch();
    $aheadQ->execute([(int)$s['id'], $period['from'], $period['to']]);
    $ahead = (float)$aheadQ->fetchColumn();
    $available = round((float)$s['weekly_hours'] * $periodWeeks, 1);
    $committed = (float)$a['worked_period'] + $ahead;
    $load[] = ['name' => $s['name'], 'authorized' => (float)$a['authorized'], 'logged' => (float)$a['worked_period'],
        'remaining' => max(0.0, (float)$a['authorized'] - (float)$a['worked_all']), 'ahead' => $ahead,
        'available' => $available, 'weekly' => (float)$s['weekly_hours'], 'diff' => round($available - $committed, 1)];
}

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
<section class="card" aria-labelledby="load-h">
  <h2 id="load-h">Authorized hours versus hours left, by instructor and month</h2>
  <p>For <?= h(fmt_date($period['from'])) ?> to <?= h(fmt_date($period['to'])) ?> (<?= h(rtrim(rtrim(number_format($periodWeeks, 1), '0'), '.')) ?> week<?= abs($periodWeeks - 1.0) < 0.05 ? '' : 's' ?>).<?= $scoped ? ' Only your own row is shown.' : '' ?></p>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="load-cap">
  <table>
    <caption id="load-cap">Authorized, logged, remaining, scheduled and available hours for each instructor in the chosen period</caption>
    <thead><tr><th scope="col">Instructor</th><th scope="col" class="num">Authorized on active tracks</th>
      <th scope="col" class="num">Logged in period</th><th scope="col" class="num">Remaining on authorizations</th>
      <th scope="col" class="num">Scheduled ahead in period</th><th scope="col" class="num">Available in period</th></tr></thead>
    <tbody>
    <?php if (!$load): ?><tr><td colspan="6" class="muted">No active instructors.</td></tr><?php endif; ?>
    <?php foreach ($load as $l): ?>
      <tr>
        <th scope="row"><?= h($l['name']) ?></th>
        <td class="num"><?= number_format($l['authorized'], 1) ?></td>
        <td class="num"><?= number_format($l['logged'], 1) ?></td>
        <td class="num"><?= number_format($l['remaining'], 1) ?></td>
        <td class="num"><?= number_format($l['ahead'], 1) ?></td>
        <td class="num <?= $l['diff'] < 0 ? 'field-error' : '' ?>"><?= number_format($l['available'], 1) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <ul>
  <?php foreach ($load as $l): ?>
    <li><?= h($l['name']) ?>:
      <?php if ($l['weekly'] <= 0): ?>no weekly available hours are set, so capacity cannot be compared (set them on the Administration page).
      <?php elseif ($l['diff'] < 0): ?><strong>over capacity by <?= h(fmt_hours(-$l['diff'])) ?></strong> for this period (logged plus scheduled hours exceed available hours).
      <?php else: ?><?= h(fmt_hours($l['diff'])) ?> free in this period after the hours logged and the sessions scheduled.<?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <p class="hint">Authorized, logged and remaining hours are for the active tracks of the clients assigned to each instructor; remaining hours count every hour ever logged on those tracks. Scheduled hours are that instructor's sessions still to come within the period (a group session once). Available hours are the instructor's weekly hours times the weeks in the period. The free or over-capacity figure compares available hours with the hours logged in the period plus the sessions still ahead.</p>
</section>
<?php page_footer();
