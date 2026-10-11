<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

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
    $q = db()->prepare("SELECT t.id, t.title, t.auth_number, t.expiration_date, t.authorized_hours, c.id AS client_id, c.name AS client_name,
          (t.expiration_date < CURDATE()) AS is_expired,
          (SELECT COALESCE(SUM(hours),0) FROM work_logs WHERE track_id = t.id) AS worked
        FROM training_tracks t JOIN clients c ON c.id = t.client_id
        WHERE t.status = 'Active' AND t.expiration_date IS NOT NULL
          AND t.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . $scopeAnd . "
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
$tq = db()->prepare("SELECT t.*, c.name AS client_name, DATEDIFF(t.due_date, CURDATE()) AS days_left
    FROM tasks t LEFT JOIN clients c ON c.id = t.client_id
    WHERE t.status = 'Open' AND " . $tvSql . "
    ORDER BY t.due_date IS NULL, t.due_date LIMIT 6");
$tq->execute($tvParams);
$myTasks = $tq->fetchAll();
// Real totals — the list above is only the first six.
$tc = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(t.due_date IS NOT NULL AND t.due_date < CURDATE()), 0) overdue
    FROM tasks t WHERE t.status = 'Open' AND " . $tvSql);
$tc->execute($tvParams);
$taskTotals = $tc->fetch();
$taskCount = (int)$taskTotals['n'];
$overdueTasks = (int)$taskTotals['overdue'];

// Same helper as the Invoices page and the app dashboard, so all three agree:
// hours plus mileage at the snapshot rates plus unbilled expenses, caseload-scoped.
$unbilled = ['hours' => 0.0, 'total' => 0.0];
if (can('billing')) {
    $unbilled = unbilled_totals($scoped ? (int)$user['id'] : null);
}

page_header('Dashboard', $user, 'dashboard');
?>
<h1>Caseload Dashboard</h1>

<?php if ($myTasks): ?>
<section class="card" aria-labelledby="tasks-heading">
  <h2 id="tasks-heading">My tasks (<?= $taskCount ?><?= $overdueTasks ? ', ' . $overdueTasks . ' overdue' : '' ?><?= $taskCount > count($myTasks) ? ' — first ' . count($myTasks) . ' shown' : '' ?>)</h2>
  <ul>
  <?php foreach ($myTasks as $t): $dl = $t['due_date'] !== null ? (int)$t['days_left'] : null; ?>
    <li>
      <?= h($t['title']) ?><?= $t['client_name'] && can('clients') ? ' — <a href="client.php?id=' . (int)$t['client_id'] . '">' . h($t['client_name']) . '</a>' : '' ?>
      <?php if ($dl !== null && $dl < 0): ?> <span class="field-error"><strong><?= abs($dl) ?> day<?= abs($dl) === 1 ? '' : 's' ?> overdue</strong></span>
      <?php elseif ($dl === 0): ?> <strong>(due today)</strong>
      <?php elseif ($dl !== null): ?> <span class="muted">(due <?= fmt_date($t['due_date']) ?>)</span><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <p><a href="tasks.php">Open the task list <span aria-hidden="true">→</span></a></p>
</section>
<?php endif; ?>

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
