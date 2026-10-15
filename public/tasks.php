<?php
// Follow-up tasks & compliance timers. Everyone sees their own and unassigned
// tasks; admins see everything. System tasks (contact referral, schedule
// assessment) are created automatically and complete themselves when the
// tracked event happens.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();

$isAdmin = can('admin');
require_once __DIR__ . '/inc/task_filters.php';
$filters = task_filters($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$returnUrl = 'tasks.php?' . http_build_query($filters);

/* ---- Add a task ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    $title = trim($_POST['title'] ?? '');
    // A due date that cannot be read is refused, never dropped: a partly filled
    // date input posts blank, so the page's script names it in date_bad[].
    $dueRaw = $_POST['due_date'] ?? '';
    $dueBad = $_POST['date_bad'] ?? [];
    $due = valid_date($dueRaw, 2000, (int)date('Y') + 5);
    $errs = [];
    if ($title === '') $errs['title'] = 'The task needs a title: say what needs doing.';
    if ((is_array($dueBad) && in_array('due_date', $dueBad, true)) || !is_string($dueRaw)
        || (trim($dueRaw) !== '' && $due === null)) {
        $errs['due_date'] = 'Enter the full due date (month, day and year) with a year from 2000 to ' . ((int)date('Y') + 5) . ', or leave it blank.';
    }
    if ($errs) {
        // Tied to the field and listed in the form's own error summary (no flash,
        // so the error is announced once); what was typed is refilled.
        keep_input();
        keep_field_errors($errs);
        redirect($returnUrl . '#add-h');
    } else {
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        if ($clientId !== null) {
            require_perm('clients');   // linking a task to a client reveals that client
            scope_check_client_id($clientId, $user);
        }
        $assignee = (int)($_POST['assigned_to'] ?? 0) ?: null;
        if (!$isAdmin) $assignee = (int)$user['id']; // non-admins assign to themselves
        $saved = add_task($title, trim($_POST['details'] ?? '') ?: null, $clientId, null,
            $due, $assignee, (int)$user['id']);
        if ($saved) {
            audit($user['id'], 'task.add', 'task', null, $title);
            flash('Task added.');
        } else {
            keep_input();
            flash('The task could not be saved. Your input has been kept; please try again.', 'error');
        }
    }
    redirect($returnUrl);
}

/* After Done / Reopen / Delete, the confirmation is given beside the list and
   focus goes to the next task's button, so the user keeps their place in the
   table instead of starting again from the top of the page. */
function task_return_to_list(string $returnUrl, string $message): never {
    $_SESSION['task_result'] = $message;
    $next = (int)($_POST['focus_next'] ?? 0);
    redirect($returnUrl . ($next > 0 ? '&focus=' . $next : '') . '#list-h');
}

/* ---- Complete / reopen / delete ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_done'])) {
    $tid = (int)($_POST['task_id'] ?? 0);
    if (set_task_done($tid, $user, ($_POST['to'] ?? '') !== 'Open')) {
        task_return_to_list($returnUrl, ($_POST['to'] ?? '') === 'Open' ? 'Task reopened.' : 'Task completed.');
    } else {
        flash('The task was unavailable or already in that state.', 'error');
    }
    redirect($returnUrl);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_task']) && $isAdmin) {
    $tid = (int)($_POST['task_id'] ?? 0);
    $tq = db()->prepare('SELECT title FROM tasks WHERE id = ?');
    $tq->execute([$tid]);
    $title = $tq->fetchColumn();
    $del = db()->prepare('DELETE FROM tasks WHERE id = ?');
    $del->execute([$tid]);
    if ($title !== false && $del->rowCount() === 1) {
        audit($user['id'], 'task.delete', 'task', $tid, (string)$title);
        task_return_to_list($returnUrl, 'Task deleted.');
    } else {
        flash('That task no longer exists. It may already have been deleted.', 'error');
    }
    redirect($returnUrl);
}

/* ---- Listing ---- */
$show = $filters['show'];
$params = [];
$visibility = task_visibility_sql($user, $params);
$sql = "SELECT t.*, c.name AS client_name, r.client_name AS referral_name, u.name AS assigned_name,
        DATEDIFF(t.due_date, CURDATE()) AS days_left, DATEDIFF(CURDATE(), DATE(t.created_at)) AS age_days
    FROM tasks t
    LEFT JOIN clients c ON c.id = t.client_id
    LEFT JOIN referrals r ON r.id = t.referral_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE " . $visibility;
$sql .= task_filter_sql($filters, $params);
$sql .= " ORDER BY t.status = 'Done', t.due_date IS NULL, t.due_date, t.created_at LIMIT 300";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$clients = [];
if (can('clients')) {
    $cq = db()->prepare("SELECT id, name FROM clients WHERE status NOT LIKE 'Closed%'"
        . (caseload_scoped() ? ' AND assigned_to = ?' : '') . ' ORDER BY name');
    $cq->execute(caseload_scoped() ? [(int)$user['id']] : []);
    $clients = $cq->fetchAll();
}
// A Done / Reopen / Delete just made: its confirmation and the next task to focus.
$taskResult = is_string($_SESSION['task_result'] ?? null) ? $_SESSION['task_result'] : null;
unset($_SESSION['task_result']);
$focusTask = (int)($_GET['focus'] ?? 0);
// Each row's buttons carry the task to focus afterwards: the one below, or the one above for the last row.
$taskIds = array_map(fn($t) => (int)$t['id'], $tasks);
$nextTask = [];
foreach ($taskIds as $i => $tid) $nextTask[$tid] = $taskIds[$i + 1] ?? ($taskIds[$i - 1] ?? 0);
$staff = $isAdmin ? db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() : [];

page_header('Tasks', $user, 'tasks');

function due_note(?int $daysLeft): string {
    if ($daysLeft === null) return '<span class="muted">no due date</span>';
    if ($daysLeft < 0) return '<span class="field-error"><strong>' . abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' overdue</strong></span>';
    if ($daysLeft === 0) return '<strong>due today</strong>';
    return '<span class="muted">due in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . '</span>';
}
?>
<h1>Tasks</h1>
<p class="muted">Referral tasks are added for you: "make contact" when a referral arrives (due in 3 business days) and "schedule assessment" when it is converted.</p>

<div class="page-actions">
  <a class="btn <?= $show !== 'all' ? 'btn-primary' : 'btn-secondary' ?>" href="tasks.php?<?= h(http_build_query(array_replace($filters, ['show' => 'open']))) ?>" <?= $show !== 'all' ? 'aria-current="true"' : '' ?>>Open tasks</a>
  <a class="btn <?= $show === 'all' ? 'btn-primary' : 'btn-secondary' ?>" href="tasks.php?<?= h(http_build_query(array_replace($filters, ['show' => 'all']))) ?>" <?= $show === 'all' ? 'aria-current="true"' : '' ?>>Everything (including done)</a>
</div>

<?php // Collapsed so the list comes first; open after a refused or restored entry (or a #add-h link).
$addOpen = array_intersect_key(form_errors(), ['title' => 1, 'due_date' => 1]) || old_in('title') !== '' || old_in('details') !== ''; ?>
<section class="card" aria-labelledby="add-h">
  <h2 class="visually-hidden">Add a task</h2>
  <details id="add-task"<?= $addOpen ? ' open' : '' ?>>
  <summary id="add-h">Add a task</summary>
  <form method="post" action="tasks.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="show" value="<?= h($show) ?>">
    <input type="hidden" name="q" value="<?= h($filters['q']) ?>">
    <input type="hidden" name="due" value="<?= h($filters['due']) ?>">
    <?= form_error_summary(['title' => 'What needs doing?', 'due_date' => 'Due date']) ?>
    <div class="grid grid-2">
      <div class="field"><label for="title">What needs doing? *</label>
        <input type="text" id="title" name="title" required maxlength="190" value="<?= h(old_in('title')) ?>" <?= field_aria('title') ?>>
        <?= field_error('title') ?></div>
      <div class="field"><label for="due_date">Due date</label>
        <input type="date" id="due_date" name="due_date" value="<?= h(old_in('due_date')) ?>" <?= field_aria('due_date') ?>>
        <?= field_error('due_date') ?></div>
      <?php if ($clients): ?>
      <div class="field"><label for="client_id">Client <span class="hint">optional</span></label>
        <select id="client_id" name="client_id"><option value="">— None —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= old_in('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
      <div class="field"><label for="assigned_to">Assign to</label>
        <select id="assigned_to" name="assigned_to"><option value="">— Anyone —</option>
          <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= old_in('assigned_to', (string)$user['id']) === (string)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="field"><label for="details">Details</label>
        <input type="text" id="details" name="details" value="<?= h(old_in('details')) ?>"></div>
    </div>
    <button type="submit" name="add_task" value="1" class="btn btn-primary">Add task</button>
  </form>
  <script>
  // A partly filled date posts blank: name it so the server refuses it rather than saving no due date.
  document.currentScript.previousElementSibling.addEventListener('submit', function(e){
    var f = e.currentTarget;
    f.querySelectorAll("input[name='date_bad[]']").forEach(function(x){ x.remove(); });
    f.querySelectorAll('input[type=date]').forEach(function(i){
      if (i.validity && i.validity.badInput) { var b = document.createElement('input'); b.type = 'hidden'; b.name = 'date_bad[]'; b.value = i.name; f.appendChild(b); }
    });
  });
  </script>
  </details>
  <script>(function(){var d=document.getElementById('add-task');if(location.hash==='#add-h'&&d)d.open=true;})();</script>
</section>

<form method="get" action="tasks.php" class="card" role="search" aria-label="Filter tasks">
  <input type="hidden" name="show" value="<?= h($show) ?>">
  <div class="grid grid-2">
    <div class="field"><label for="task-q">Search task titles and details</label>
      <input type="search" id="task-q" name="q" maxlength="190" value="<?= h($filters['q']) ?>"></div>
    <div class="field"><label for="task-due">Due date</label>
      <select id="task-due" name="due">
        <?php foreach (['any' => 'Any due date', 'overdue' => 'Overdue', 'today' => 'Due today (open)', 'week' => 'Next 7 days (open)', 'none' => 'No due date'] as $value => $label): ?>
        <option value="<?= h($value) ?>" <?= $filters['due'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <button type="submit" class="btn btn-primary">Apply filters</button>
  <a href="tasks.php" class="btn btn-secondary">Clear filters</a>
</form>
<?php if (count($tasks) === 300): ?><p role="status">Showing the first 300 matches. Narrow your filters to find more specific tasks.</p><?php endif; ?>
<h2 id="list-h" tabindex="-1"><?= $show === 'all' ? 'All tasks' : 'Open tasks' ?> (<?= count($tasks) ?>)</h2>
<?php if ($taskResult !== null): ?>
<p id="task-result" role="status"></p>
<script>document.addEventListener('DOMContentLoaded',function(){
  // Focus first (the next task's button, else the list heading), then the
  // confirmation fills the status region, so both are heard, once each.
  var f=document.getElementById('task-act-<?= $focusTask ?>')||document.getElementById('list-h');
  if(f)f.focus();
  setTimeout(function(){var s=document.getElementById('task-result');if(s)s.textContent=<?= json_encode($taskResult, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;},400);
});</script>
<?php endif; ?>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="list-h">
<table>
  <caption class="visually-hidden">Task list</caption>
  <thead><tr><th scope="col">Task</th><th scope="col">Who / what</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$tasks): ?><tr><td colspan="5" class="muted">No tasks match this view.</td></tr><?php endif; ?>
  <?php foreach ($tasks as $t): $taskLabel = $t['title'] . ' — task #' . (int)$t['id'] . ($t['client_name'] ? ' for ' . $t['client_name'] : ''); ?>
    <tr>
      <th scope="row"><?= h($t['title']) ?><?= $t['details'] ? '<br><span class="muted">' . h($t['details']) . '</span>' : '' ?>
          <?= $t['auto_key'] ? '<br><span class="muted">(automatic — ' . (int)$t['age_days'] . ' day' . ((int)$t['age_days'] === 1 ? '' : 's') . ' since created)</span>' : '' ?></th>
      <td>
        <?php if ($t['client_id'] && can('clients')): ?><a href="client.php?id=<?= (int)$t['client_id'] ?>"><?= h($t['client_name']) ?></a><br><?php elseif ($t['client_name']): ?><?= h($t['client_name']) ?><br><?php endif; ?>
        <?php if ($t['referral_id'] && can('referrals')): ?><a href="referrals.php?view=<?= (int)$t['referral_id'] ?>">Referral: <?= h($t['referral_name'] ?? '#' . (int)$t['referral_id']) ?></a><br><?php endif; ?>
        <span class="muted"><?= h($t['assigned_name'] ?? 'Anyone') ?></span>
      </td>
      <?php // A done task without a due date says so in words, as an open one does (a lone dash is skipped or read as "dash"). ?>
      <td><?= $t['status'] === 'Open' ? due_note($t['due_date'] !== null ? (int)$t['days_left'] : null) : '<span class="muted">' . ($t['due_date'] ? h(fmt_date($t['due_date'])) : 'no due date') . '</span>' ?></td>
      <td><span class="badge <?= $t['status'] === 'Open' ? 'badge-active' : 'badge-closed' ?>"><?= h($t['status']) ?></span></td>
      <td>
        <form method="post" action="tasks.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="show" value="<?= h($show) ?>">
          <input type="hidden" name="q" value="<?= h($filters['q']) ?>">
          <input type="hidden" name="due" value="<?= h($filters['due']) ?>">
          <input type="hidden" name="focus_next" value="<?= (int)($nextTask[(int)$t['id']] ?? 0) ?>">
          <?php if ($t['status'] === 'Open'): ?>
          <button type="submit" name="set_done" value="1" class="btn btn-secondary btn-sm" id="task-act-<?= (int)$t['id'] ?>">Done<span class="visually-hidden"> — <?= h($taskLabel) ?></span></button>
          <?php else: ?>
          <input type="hidden" name="to" value="Open">
          <button type="submit" name="set_done" value="1" class="btn btn-secondary btn-sm" id="task-act-<?= (int)$t['id'] ?>">Reopen<span class="visually-hidden"> — <?= h($taskLabel) ?></span></button>
          <?php endif; ?>
        </form>
        <?php if ($isAdmin): ?>
        <form method="post" action="tasks.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="show" value="<?= h($show) ?>">
          <input type="hidden" name="q" value="<?= h($filters['q']) ?>">
          <input type="hidden" name="due" value="<?= h($filters['due']) ?>">
          <input type="hidden" name="focus_next" value="<?= (int)($nextTask[(int)$t['id']] ?? 0) ?>">
          <button type="submit" name="delete_task" value="1" class="btn btn-danger btn-sm"
            onclick="return confirm('Delete this task permanently?');">Delete<span class="visually-hidden"> — <?= h($taskLabel) ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
