<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
csrf_check();

// What's new: the current version's release notes (inc/release_notes.php) are offered
// once per account, until Got it records that this version was seen (one setting per
// user, as the low-vision preference in account.php). A version with no notes says nothing.
$releaseNotes = require __DIR__ . '/inc/release_notes.php';
$newInVersion = $releaseNotes[APP_VERSION] ?? [];
$seenReleaseKey = 'seen_release_' . (int)$user['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seen_release'])) {
    set_setting($seenReleaseKey, APP_VERSION);
    redirect('index.php');
}
$showWhatsNew = $newInVersion && setting($seenReleaseKey) !== APP_VERSION;

// Every dashboard section respects the viewer's permissions and caseload scope.
$scoped = caseload_scoped();
$scopeAnd = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeParams = $scoped ? [(int)$user['id']] : [];

$activeClients = 0; $pendingClients = 0; $expiring = []; $recentNotes = [];
if (can('clients')) {
    $q = db()->prepare("SELECT COUNT(*) FROM clients c WHERE c.status = 'Active Training'" . $scopeAnd);
    $q->execute($scopeParams); $activeClients = (int)$q->fetchColumn();
    $q = db()->prepare("SELECT COUNT(*) FROM clients c WHERE c.status = 'Pending Assessment'" . $scopeAnd);
    $q->execute($scopeParams); $pendingClients = (int)$q->fetchColumn();
    // Active tracks whose authorization has already lapsed stay listed (first,
    // marked expired): that is when hours must stop being logged against them.
    // Open clients only, as on the Compliance page and in cron/daily_alerts.php:
    // closing a client leaves its track Active, so it would stay listed forever.
    $q = db()->prepare("SELECT t.id, t.title, t.auth_number, t.expiration_date, t.authorized_hours, c.id AS client_id, c.name AS client_name,
          (t.expiration_date < CURDATE()) AS is_expired,
          (SELECT COALESCE(SUM(hours),0) FROM work_logs WHERE track_id = t.id) AS worked
        FROM training_tracks t JOIN clients c ON c.id = t.client_id
        WHERE t.status = 'Active' AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%' AND t.expiration_date IS NOT NULL
          AND t.expiration_date <= DATE_ADD(CURDATE(), INTERVAL " . cms_number('auth_soon_days') . " DAY)" . $scopeAnd . "
        ORDER BY t.expiration_date");
    $q->execute($scopeParams); $expiring = $q->fetchAll();
    $q = db()->prepare("SELECT n.*, c.name AS client_name FROM client_notes n JOIN clients c ON c.id = n.client_id WHERE 1=1" . $scopeAnd . " ORDER BY n.created_at DESC LIMIT 5");
    $q->execute($scopeParams); $recentNotes = $q->fetchAll();
}
$newReferrals = 0; $recentReferrals = [];
if (can('referrals')) {
    // Unassigned referrals are a shared queue until converted; a converted one
    // shows only to whoever the client is assigned to now, whoever it was
    // assigned to (same rule as referral_in_scope() on the Referrals page and
    // api_referral_scope_sql() in the app).
    $refWhere = $scoped ? " AND ((referrals.status <> 'Converted' AND (referrals.assigned_to = ? OR referrals.assigned_to IS NULL))"
        . " OR (referrals.status = 'Converted' AND EXISTS (SELECT 1 FROM clients sc WHERE sc.id = referrals.client_id AND sc.assigned_to = ?)))" : '';
    $refParams = $scoped ? [(int)$user['id'], (int)$user['id']] : [];
    $q = db()->prepare("SELECT COUNT(*) FROM referrals WHERE status = 'New'" . $refWhere);
    $q->execute($refParams); $newReferrals = (int)$q->fetchColumn();
    $q = db()->prepare("SELECT * FROM referrals WHERE 1=1" . $refWhere . " ORDER BY created_at DESC LIMIT 5");
    $q->execute($refParams); $recentReferrals = $q->fetchAll();
}
$tvParams = [];
$tvSql = task_visibility_sql($user, $tvParams);
$tq = db()->prepare("SELECT t.*, c.name AS client_name, u.name AS assignee_name, DATEDIFF(t.due_date, CURDATE()) AS days_left
    FROM tasks t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'Open' AND " . $tvSql . "
    ORDER BY t.due_date IS NULL, t.due_date LIMIT 6");
$tq->execute($tvParams);
$myTasks = $tq->fetchAll();
// Administrators see the whole team's open tasks here, so the heading says so and each
// task names its assignee when it is someone else's (2.4.6).
$teamTasks = (bool)array_filter($myTasks, fn($t) => (int)($t['assigned_to'] ?? 0) !== (int)$user['id']);
// Real totals — the list above is only the first six.
$tc = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(t.due_date IS NOT NULL AND t.due_date < CURDATE()), 0) overdue
    FROM tasks t WHERE t.status = 'Open' AND " . $tvSql);
$tc->execute($tvParams);
$taskTotals = $tc->fetch();
$taskCount = (int)$taskTotals['n'];
$overdueTasks = (int)$taskTotals['overdue'];

/* ---- Today: what is due now, each a link to where it is done, only for the
   areas this user can open. Counts use the same visibility and filters as the
   pages they link to (the Task list's Due filters, the Schedule, Messages, Device
   follow-ups and Client portal requests), so the numbers agree. ---- */
require_once __DIR__ . '/inc/task_filters.php';
$todayItems = [];   // each: ['href' => ..., 'text' => ..., 'sub' => [[href, text], ...]]
$todayTaskCounts = [];
foreach (['overdue' => 'overdue', 'today' => 'due today'] as $dueKey => $dueLabel) {
    $tp = $tvParams;
    $tfSql = task_filter_sql(task_filters(['due' => $dueKey]), $tp);
    $tcq = db()->prepare('SELECT COUNT(*) FROM tasks t WHERE ' . $tvSql . $tfSql);
    $tcq->execute($tp);
    $todayTaskCounts[$dueKey] = (int)$tcq->fetchColumn();
    if ($todayTaskCounts[$dueKey] > 0) {
        $n = $todayTaskCounts[$dueKey];
        $todayItems[] = ['href' => 'tasks.php?due=' . $dueKey, 'text' => $n . ' task' . ($n === 1 ? '' : 's') . ' ' . $dueLabel];
    }
}
if (can('schedule')) {
    // Today's sessions still open for an outcome, scoped like the Schedule page; each
    // links to its row there (the Upcoming or Ended-sessions table).
    $sq = db()->prepare("SELECT s.id, s.start_at, s.end_at, c.name AS client_name, t.title AS track_title
        FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id LEFT JOIN training_tracks t ON t.id = s.track_id
        WHERE s.status = 'Scheduled' AND DATE(s.start_at) = CURDATE()" . $scopeAnd . ' ORDER BY s.start_at, s.id');
    $sq->execute($scopeParams);
    $todaySessions = $sq->fetchAll();
    if ($todaySessions) {
        $n = count($todaySessions);
        $todayItems[] = ['href' => 'schedule.php#upcoming-h', 'text' => $n . ' session' . ($n === 1 ? '' : 's') . ' today',
            'sub' => array_map(fn($s) => ['schedule.php#s-' . (int)$s['id'],
                date('g:i A', strtotime($s['start_at'])) . ' — ' . $s['client_name'] . ($s['track_title'] ? ' (' . $s['track_title'] . ')' : '')
                . (strtotime($s['end_at']) < time() ? ', ended, awaiting an outcome' : '')], $todaySessions)];
    }
}
if (can('clients')) {
    $mq = db()->prepare("SELECT COUNT(*) FROM messages m JOIN clients c ON c.id = m.client_id WHERE m.sender <> 'staff' AND m.read_at IS NULL" . $scopeAnd);
    $mq->execute($scopeParams);
    if (($n = (int)$mq->fetchColumn()) > 0) $todayItems[] = ['href' => 'messages.php', 'text' => $n . ' unread message' . ($n === 1 ? '' : 's') . ' from clients and counselors'];
    $fq = db()->prepare("SELECT COUNT(*) FROM equipment_followups f JOIN clients c ON c.id = f.client_id WHERE f.status = 'Pending' AND f.due_date <= CURDATE()" . $scopeAnd);
    $fq->execute($scopeParams);
    if (($n = (int)$fq->fetchColumn()) > 0) $todayItems[] = ['href' => 'followups.php#due-h', 'text' => $n . ' device follow-up' . ($n === 1 ? '' : 's') . ' due'];
    $pq = db()->prepare("SELECT COUNT(*) FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.status = 'New'" . $scopeAnd);
    $pq->execute($scopeParams);
    if (($n = (int)$pq->fetchColumn()) > 0) $todayItems[] = ['href' => 'portal_requests.php', 'text' => $n . ' new client portal request' . ($n === 1 ? '' : 's')];
}

// Same helper as the Invoices page and the app dashboard, so all three agree:
// hours plus mileage at the snapshot rates plus unbilled expenses, caseload-scoped.
$unbilled = ['hours' => 0.0, 'total' => 0.0];
if (can('billing')) {
    $unbilled = unbilled_totals($scoped ? (int)$user['id'] : null);
}

page_header('Dashboard', $user, 'dashboard');
?>
<h1>Caseload Dashboard</h1>
<?php $toSign = staff_agreements_needed((int)$user['id']); if ($toSign > 0): // 4.15.0 ?>
<section class="card alert-info" aria-labelledby="agreements-heading">
  <h2 id="agreements-heading"><?= $toSign === 1 ? 'An agreement to sign' : $toSign . ' agreements to sign' ?></h2>
  <p>Please read and sign <?= $toSign === 1 ? 'it' : 'them' ?>. <a href="agreements.php">Open My agreements</a></p>
</section>
<?php endif; ?>

<?php if ($showWhatsNew): // Read once in page order, right after the h1 and before Today; not a live region. ?>
<section class="card alert-info" aria-labelledby="whats-new-heading">
  <h2 id="whats-new-heading">New in <?= h(APP_VERSION) ?></h2>
  <p><?= h($newInVersion[0]) ?> <a href="help.php#whats-new">Read what's new<span class="visually-hidden"> in version <?= h(APP_VERSION) ?>, in the user guide</span></a></p>
  <form method="post" action="index.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="seen_release" value="1" class="btn btn-sm">Got it<span class="visually-hidden">: hide this notice about version <?= h(APP_VERSION) ?></span></button>
  </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="today-heading">
  <h2 id="today-heading">Today<?= $todayItems ? ' (' . count($todayItems) . ' item' . (count($todayItems) === 1 ? '' : 's') . ')' : '' ?></h2>
  <?php if (!$todayItems): ?>
    <p class="muted">Nothing due today.</p>
  <?php else: ?>
  <ul>
  <?php foreach ($todayItems as $item): ?>
    <li><a href="<?= h($item['href']) ?>"><?= h($item['text']) ?></a>
      <?php if (!empty($item['sub'])): ?>
      <ul>
      <?php foreach ($item['sub'] as [$subHref, $subText]): ?>
        <li><a href="<?= h($subHref) ?>"><?= h($subText) ?></a></li>
      <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php // Your week (3.0.46): hours logged and still booked, against the weekly target. ?>
<p id="my-week"><?= h(staff_week_text(staff_week((int)$user['id']))) ?></p>
<h2 class="visually-hidden">Caseload at a glance</h2>
<div class="grid grid-4">
  <?php if (can('clients')): ?>
  <div class="card stat">
    <span class="stat-num"><?= $activeClients ?></span>
    <span class="stat-label">Clients in active training</span>
  </div>
  <div class="card stat">
    <span class="stat-num"><?= $pendingClients ?></span>
    <span class="stat-label">Pending assessment</span>
  </div>
  <?php endif; ?>
  <?php if (can('referrals')): ?>
  <div class="card stat">
    <span class="stat-num"><?= $newReferrals ?></span>
    <span class="stat-label">New referrals</span>
  </div>
  <?php endif; ?>
  <?php if (can('billing')): ?>
  <div class="card stat">
    <span class="stat-num"><?= fmt_hours((float)$unbilled['hours']) ?></span>
    <span class="stat-label">Unbilled <?= $scoped ? 'on your caseload' : 'across all tracks' ?> (<?= fmt_money((float)$unbilled['total']) ?> with mileage and expenses)</span>
  </div>
  <?php endif; ?>
</div>

<?php if ($myTasks): ?>
<section class="card" aria-labelledby="tasks-heading">
  <h2 id="tasks-heading"><?= $teamTasks ? 'Open tasks, yours and your team\'s' : 'My tasks' ?> (<?= $taskCount ?><?= $overdueTasks ? ', ' . $overdueTasks . ' overdue' : '' ?><?= $taskCount > count($myTasks) ? ' — first ' . count($myTasks) . ' shown' : '' ?>)</h2>
  <ul>
  <?php foreach ($myTasks as $t): $dl = $t['due_date'] !== null ? (int)$t['days_left'] : null; ?>
    <li>
      <?= h($t['title']) ?><?= $t['client_name'] && can('clients') ? ' — <a href="client.php?id=' . (int)$t['client_id'] . '">' . h($t['client_name']) . '</a>' : '' ?><?= $teamTasks && (int)($t['assigned_to'] ?? 0) !== (int)$user['id'] ? ' <span class="muted">(' . ($t['assignee_name'] ? 'assigned to ' . h($t['assignee_name']) : 'unassigned') . ')</span>' : '' ?>
      <?php if ($dl !== null && $dl < 0): ?> <span class="field-error"><strong><?= abs($dl) ?> day<?= abs($dl) === 1 ? '' : 's' ?> overdue</strong></span>
      <?php elseif ($dl === 0): ?> <strong>(due today)</strong>
      <?php elseif ($dl !== null): ?> <span class="muted">(due <?= fmt_date($t['due_date']) ?>)</span><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <p><a href="tasks.php">Open the task list <span aria-hidden="true">→</span></a></p>
</section>
<?php endif; ?>

<?php if (can('clients')): ?>
<section aria-labelledby="expiring-heading" class="card">
  <h2 id="expiring-heading">Authorizations expired or expiring within 30 days</h2>
  <?php if (!$expiring): ?>
    <p class="muted">No active training track has an authorization that has expired or expires in the next 30 days.</p>
  <?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="expiring-cap">
    <table>
      <caption class="visually-hidden" id="expiring-cap">Active training tracks whose authorization has expired (listed first) or expires within 30 days: client, track, authorization number, expiry date and hours used</caption>
      <thead><tr>
        <th scope="col">Client</th><th scope="col">Training track</th><th scope="col"><abbr title="Authorization number">Auth #</abbr></th>
        <th scope="col">Expires</th><th scope="col" class="num">Hours used / authorized</th>
      </tr></thead>
      <tbody>
      <?php foreach ($expiring as $t): ?>
        <tr>
          <th scope="row"><a href="client.php?id=<?= (int)$t['client_id'] ?>"><?= h($t['client_name']) ?></a></th>
          <td><a href="track.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a></td>
          <td><?= h($t['auth_number']) ?></td>
          <td><?php if ((int)$t['is_expired']): ?><span class="badge badge-hold">Expired</span> <?php endif; ?><?= fmt_date($t['expiration_date']) ?></td>
          <td class="num"><?= fmt_hours((float)$t['worked']) ?> / <?= fmt_hours((float)$t['authorized_hours']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="grid grid-2">
  <?php if (can('referrals')): ?>
  <section aria-labelledby="ref-heading" class="card">
    <h2 id="ref-heading">Recent referrals</h2>
    <?php if (!$recentReferrals): ?>
      <p class="muted">No referrals yet. Counselors can submit at the <a href="referral.php">referral portal</a>.</p>
    <?php else: ?>
      <ul>
      <?php foreach ($recentReferrals as $r): ?>
        <li>
          <a href="referrals.php?view=<?= (int)$r['id'] ?>">Referral: <?= h($r['client_name']) ?></a> — <?= h($r['reason']) ?>
          <br><span class="muted"><?= h($r['counselor_name']) ?>, <?= h($r['counselor_agency']) ?> · <?= fmt_datetime($r['created_at']) ?></span>
          <?= status_badge($r['status']) ?>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if (can('clients')): ?>
  <section aria-labelledby="notes-heading" class="card">
    <h2 id="notes-heading">Latest client notes</h2>
    <?php if (!$recentNotes): ?>
      <p class="muted">No notes yet.</p>
    <?php else: ?>
      <ul>
      <?php foreach ($recentNotes as $n): ?>
        <li>
          <a href="client.php?id=<?= (int)$n['client_id'] ?>&amp;tab=notes">Notes for <?= h($n['client_name']) ?></a>: <?= h($n['subject']) ?>
          <br><span class="muted"><?= h($n['note_type']) ?> · <?= fmt_datetime($n['created_at']) ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php page_footer();
