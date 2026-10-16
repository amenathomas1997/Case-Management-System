<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

/* ---- Admin: return an archived record to the active lists ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unarchive'])) {
    require_perm('admin');
    $restoreId = (int)($_POST['client_id'] ?? 0);
    // The nightly retention run archives a closed record whose last status-history
    // entry is older than the retention period, which a restored record still is.
    // The restore is written to the history (status unchanged; the reports count
    // a closure only when a case moves into Closed from an open status), so the
    // retention period starts again from today.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT status FROM clients WHERE id = ? AND archived_at IS NOT NULL FOR UPDATE');
        $cur->execute([$restoreId]);
        $restoredStatus = $cur->fetchColumn();
        if ($restoredStatus !== false) {
            $pdo->prepare('UPDATE clients SET archived_at = NULL WHERE id = ?')->execute([$restoreId]);
            $pdo->prepare('INSERT INTO client_status_history (client_id, old_status, new_status, changed_by, note) VALUES (?,?,?,?,?)')
                ->execute([$restoreId, $restoredStatus, $restoredStatus, (int)$user['id'], 'Restored from archive (retention period restarts)']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($restoredStatus !== false) {
        audit($user['id'], 'client.unarchive', 'client', $restoreId);
        $retYears = (int)(setting('retention_years') ?? '0');
        flash('The record was restored to the active lists.'
            . ($retYears > 0 && str_starts_with((string)$restoredStatus, 'Closed')
                ? ' Its retention period starts again today, so it will be archived again automatically in ' . $retYears . ' year' . ($retYears === 1 ? '' : 's') . ' if it is still closed then.'
                : ''));
    } else {
        flash('That record is not archived.', 'error');
    }
    redirect('clients.php?archived=1');
}

// A hand-made URL can send these as arrays; only text is a filter.
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$showArchived = isset($_GET['archived']) && can('admin');
// Caseload filter: a submitted choice is remembered per user (as My Account keeps
// per-user settings), so the list opens the same way next time. With no choice
// saved, staff limited to their own caseload start on "My clients"; everyone
// else on "Everyone's clients".
// Named instructors too (3.0.50), for anyone who sees every caseload.
$instructors = caseload_scoped() ? [] : array_values(array_filter(db()->query("SELECT DISTINCT u.id, u.name FROM users u JOIN clients c ON c.assigned_to = u.id WHERE u.is_active = 1 AND c.archived_at IS NULL ORDER BY u.name")->fetchAll(), fn($i) => (int)$i['id'] !== (int)$user['id']));
$whoChoices = array_merge(['', 'me', 'unassigned'], array_map(fn($i) => 'u' . (int)$i['id'], $instructors));
$whoKey = 'clients_filter_' . (int)$user['id'];
if (is_string($_GET['who'] ?? null)) {
    $who = in_array($_GET['who'], $whoChoices, true) ? $_GET['who'] : '';
    if ((setting($whoKey) ?? '') !== $who) set_setting($whoKey, $who);
} else {
    $savedWho = setting($whoKey);
    $who = $savedWho !== null && in_array($savedWho, $whoChoices, true) ? $savedWho : (caseload_scoped() ? 'me' : '');
}

$sql = 'SELECT c.*, u.name AS instructor, (SELECT COUNT(*) FROM training_tracks t WHERE t.client_id = c.id AND t.status = "Active") AS active_tracks
    FROM clients c LEFT JOIN users u ON u.id = c.assigned_to WHERE 1=1';
$params = [];
$sql .= $showArchived ? ' AND c.archived_at IS NOT NULL' : ' AND c.archived_at IS NULL';
if ($q !== '') { $sql .= ' AND (c.name LIKE ? OR c.email LIKE ? OR c.counselor_name LIKE ?)'; $like = "%$q%"; array_push($params, $like, $like, $like); }
if (in_array($statusFilter, CLIENT_STATUSES, true)) { $sql .= ' AND c.status = ?'; $params[] = $statusFilter; }
if ($who === 'me') { $sql .= ' AND c.assigned_to = ?'; $params[] = $user['id']; }
elseif ($who === 'unassigned') { $sql .= ' AND c.assigned_to IS NULL'; }
elseif (str_starts_with($who, 'u')) { $sql .= ' AND c.assigned_to = ?'; $params[] = (int)substr($who, 1); }
// Client type, funding agency (any of the client's tracks) and school (3.0.50).
$typeFilter = in_array($_GET['type'] ?? '', CLIENT_TYPES, true) ? $_GET['type'] : '';
$agencyFilter = is_string($_GET['agency'] ?? null) ? trim($_GET['agency']) : '';
$schoolFilter = is_string($_GET['school'] ?? null) ? trim($_GET['school']) : '';
if ($typeFilter !== '') { $sql .= ' AND c.client_type = ?'; $params[] = $typeFilter; }
if ($agencyFilter !== '') { $sql .= ' AND EXISTS (SELECT 1 FROM training_tracks ft WHERE ft.client_id = c.id AND ft.funding_agency = ?)'; $params[] = $agencyFilter; }
if ($schoolFilter !== '') { $sql .= ' AND c.school LIKE ?'; $params[] = '%' . $schoolFilter . '%'; }
if (caseload_scoped()) { $sql .= ' AND c.assigned_to = ?'; $params[] = $user['id']; }
// The download has every match, past the screen's 500 rows (3.0.50).
if (isset($_GET['export'])) {
    $ex = db()->prepare($sql . ' ORDER BY c.name');
    $ex->execute($params);
    list_export_send('clients', (int)$user['id'], ['Name', 'Status', 'Client type', 'Instructor', 'VR counselor', 'Counselor agency', 'Email', 'Phone', 'Date of birth', 'School', 'Active tracks'],
        array_map(fn($c) => [$c['name'], $c['status'], $c['client_type'], $c['instructor'], $c['counselor_name'], $c['counselor_agency'], $c['email'], $c['phone'], $c['dob'], $c['school'], (int)$c['active_tracks']], $ex->fetchAll()), ' (filtered)');
}
// The list shows at most 500 rows; count every match so the page can say so.
$cnt = db()->prepare('SELECT COUNT(*) FROM (' . $sql . ') matched');
$cnt->execute($params);
$matchCount = (int)$cnt->fetchColumn();
$sql .= ' ORDER BY c.name LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();

page_header($showArchived ? 'Archived clients' : 'Clients', $user, 'clients');
?>
<h1><?= $showArchived ? 'Archived clients' : 'Clients' ?></h1>
<div class="page-actions">
  <a class="btn btn-primary" href="client_edit.php">Add client</a>
  <?php if (can('admin')): ?>
    <?php if ($showArchived): ?><a class="btn btn-secondary" href="clients.php">Back to current clients</a>
    <?php else: ?><a class="btn btn-secondary" href="clients.php?archived=1">Archived clients</a><?php endif; ?>
  <?php endif; ?>
</div>
<?php if ($showArchived): ?>
<p class="muted">Closed records past the retention period. Open a record to export its full file and, when appropriate, purge it permanently.</p>
<?php endif; ?>

<form method="get" action="clients.php" class="card" role="search" aria-label="Filter clients">
  <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
  <div class="grid grid-2">
    <div class="field">
      <label for="q">Search by client, email, or counselor</label>
      <input type="text" id="q" name="q" value="<?= h($q) ?>">
    </div>
    <div class="field">
      <label for="status">Case status</label>
      <select id="status" name="status">
        <option value="">All statuses</option>
        <?= select_options(CLIENT_STATUSES, $statusFilter) ?>
      </select>
    </div>
    <div class="field">
      <label for="who">Caseload</label>
      <span class="hint" id="who-hint">Your choice is remembered for the next time you open Client records.</span>
      <select id="who" name="who" aria-describedby="who-hint">
        <option value="" <?= $who === '' ? 'selected' : '' ?>>Everyone's clients</option>
        <option value="me" <?= $who === 'me' ? 'selected' : '' ?>>My clients</option>
        <option value="unassigned" <?= $who === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
        <?php foreach ($instructors as $in): ?><option value="u<?= (int)$in['id'] ?>" <?= $who === 'u' . (int)$in['id'] ? 'selected' : '' ?>><?= h($in['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="type">Client type</label>
      <select id="type" name="type"><option value="">All types</option><?= select_options(CLIENT_TYPES, $typeFilter) ?></select>
    </div>
    <?php $agencies = db()->query("SELECT DISTINCT funding_agency FROM training_tracks WHERE funding_agency NOT IN ('', 'Private pay', 'No charge') ORDER BY funding_agency")->fetchAll(PDO::FETCH_COLUMN); ?>
    <div class="field">
      <label for="agency">Funding agency</label>
      <select id="agency" name="agency"><option value="">All agencies</option><?= select_options(array_map('strval', $agencies), $agencyFilter) ?></select>
    </div>
    <div class="field">
      <label for="school">School</label>
      <input type="text" id="school" name="school" value="<?= h($schoolFilter) ?>">
    </div>
  </div>
  <button type="submit" class="btn btn-secondary">Apply filters</button>
  <button type="submit" name="export" value="1" class="btn btn-secondary">Download these clients (CSV)</button>
</form>

<?php if ($matchCount > count($clients)): ?>
<p class="alert alert-info">Showing the first <?= count($clients) ?> of <?= number_format($matchCount) ?> matching clients, in name order. Search by name or narrow the filters to find the rest.</p>
<?php endif; ?>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="clients-cap">
<table>
  <caption class="visually-hidden" id="clients-cap"><?= $showArchived ? 'Archived client records' : 'Client list' ?><?= $matchCount > count($clients) ? ' (first ' . count($clients) . ' of ' . number_format($matchCount) . ')' : '' ?></caption>
  <thead><tr>
    <th scope="col">Name</th><th scope="col">Status</th><th scope="col">Instructor</th><th scope="col">VR counselor</th>
    <th scope="col">Contact</th><th scope="col" class="num">Active tracks</th>
    <?php if ($showArchived): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php if (!$clients): ?>
    <tr><td colspan="<?= $showArchived ? 7 : 6 ?>" class="muted">No clients match. <a href="client_edit.php">Add your first client</a> or <a href="referrals.php">convert a referral</a>.</td></tr>
  <?php endif; ?>
  <?php $dupNames = array_count_values(array_column($clients, 'name')); // two clients with one name are told apart by record number (2.4.9) ?>
<?php foreach ($clients as $c): ?>
    <tr>
      <th scope="row"><a href="client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= ($dupNames[$c['name']] ?? 0) > 1 ? '<span class="visually-hidden"> (record #' . (int)$c['id'] . ')</span>' : '' ?></a></th>
      <td><?= status_badge($c['status']) ?></td>
      <td><?= h($c['instructor'] ?? '—') ?></td>
      <td><?= h($c['counselor_name'] ?? '—') ?><br><span class="muted"><?= h($c['counselor_agency'] ?? '') ?></span></td>
      <td><?= h($c['phone'] ?? '') ?><br><span class="muted"><?= h($c['email'] ?? '') ?></span></td>
      <td class="num"><?= (int)$c['active_tracks'] ?></td>
      <?php if ($showArchived): ?>
      <td>
        <form method="post" action="clients.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
          <button type="submit" name="unarchive" value="1" class="btn btn-secondary btn-sm">Restore to active lists<span class="visually-hidden"> — <?= h($c['name']) ?><?= ($dupNames[$c['name']] ?? 0) > 1 ? ' (record #' . (int)$c['id'] . ')' : '' ?></span></button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
