<?php
// Staff queue of self-service requests clients send from their portal
// (appointments, reschedule/cancel, device help, other). Caseload-scoped.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

const PREQ_STATUSES = ['New', 'In Progress', 'Done', 'Declined'];
const PREQ_TYPES = ['appointment' => 'Appointment', 'reschedule' => 'Reschedule', 'cancel' => 'Cancellation', 'device' => 'Device help', 'other' => 'Request'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $id = (int)($_POST['id'] ?? 0);
    $r = db()->prepare('SELECT pr.id, pr.client_id, pr.type, pr.status, c.name AS client_name, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ?');
    $r->execute([$id]);
    if ($row = $r->fetch()) {
        if (caseload_scoped() && (int)$row['assigned_to'] !== (int)$user['id']) { flash('That request is not on your caseload.', 'error'); redirect('portal_requests.php'); }
        $to = in_array($_POST['to'] ?? '', PREQ_STATUSES, true) ? $_POST['to'] : null;
        if ($to === null) { flash('Choose a status for the request.', 'error'); redirect('portal_requests.php'); }
        // Saving the status it already has changes nothing, so nothing is audited.
        if ($to === $row['status']) { flash('Request from ' . $row['client_name'] . ' (' . (PREQ_TYPES[$row['type']] ?? 'Request') . ') is already ' . $to . '; nothing was changed.'); redirect('portal_requests.php'); }
        $closed = ['Done', 'Declined'];
        db()->beginTransaction();
        try {
            db()->prepare('UPDATE portal_requests SET status = ?, handled_by = ? WHERE id = ?')->execute([$to, $user['id'], $id]);
            // A handled request closes the follow-up task the portal opened for it, so it
            // does not sit Open and go overdue (requests sent before the key existed have none).
            if (in_array($to, $closed, true)) {
                complete_task_by_key('preq-' . $id, (int)$user['id']);
            } elseif (in_array($row['status'], $closed, true)) {
                // Opened again: so is its follow-up task (only this request's own task),
                // so it is back on the assignee's list with its original due date.
                db()->prepare("UPDATE tasks SET status = 'Open', done_at = NULL, done_by = NULL WHERE auto_key = ? AND status = 'Done'")
                    ->execute(['preq-' . $id]);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        // Tied to the client, and naming the request, like the other client audit entries.
        audit($user['id'], 'portal_request.status', 'client', (int)$row['client_id'], 'request #' . $id . ': ' . $row['status'] . ' → ' . $to);
        flash('Request from ' . $row['client_name'] . ' (' . (PREQ_TYPES[$row['type']] ?? 'Request') . ') set to ' . $to . '.');
    }
    redirect('portal_requests.php');
}

$scoped = caseload_scoped();
$showDone = !empty($_GET['all']);
$sql = "SELECT pr.*, c.name AS client_name, c.id AS cid, s.start_at
    FROM portal_requests pr JOIN clients c ON c.id = pr.client_id
    LEFT JOIN scheduled_sessions s ON s.id = pr.session_id
    WHERE 1=1" . ($showDone ? '' : " AND pr.status IN ('New','In Progress')") . ($scoped ? ' AND c.assigned_to = ?' : '') . '
    ORDER BY pr.status = \'New\' DESC, pr.created_at DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute($scoped ? [(int)$user['id']] : []);
$rows = $st->fetchAll();
$newCount = count(array_filter($rows, fn($r) => $r['status'] === 'New'));

page_header('Client requests', $user, 'portal_requests');
?>
<h1>Client self-service requests</h1>
<p class="muted">What clients have asked for from their portal<?= $scoped ? ' (your caseload)' : '' ?>. <?= $newCount ?> new.
  <?= $showDone ? 'Showing all requests, including done and declined ones.' : 'Showing open requests (new and in progress); done and declined requests are hidden.' ?></p>
<?php // The link text says what the list will show, read alone in a links list. ?>
<div class="page-actions"><a class="btn btn-secondary btn-sm" href="portal_requests.php<?= $showDone ? '' : '?all=1' ?>"><?= $showDone ? 'Show open requests only' : 'Show all requests, including done and declined' ?></a></div>

<div class="table-scroll" tabindex="0" role="region" aria-label="Client requests">
<table>
  <caption class="visually-hidden"><?= $showDone ? 'All client self-service requests' : 'Open client self-service requests (new and in progress)' ?></caption>
  <thead><tr><th scope="col">Client</th><th scope="col">Type</th><th scope="col">Details</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No <?= $showDone ? '' : 'open ' ?>requests.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
    // Hidden text for the row's controls: client, request type and date, since a
    // client can have several requests open.
    $which = h($r['client_name']) . ', ' . h(PREQ_TYPES[$r['type']] ?? 'Request') . ', ' . fmt_date($r['created_at']); ?>
    <tr>
      <th scope="row"><a href="client.php?id=<?= (int)$r['cid'] ?>"><?= h($r['client_name']) ?></a><br><span class="muted"><?= fmt_date($r['created_at']) ?></span></th>
      <?php // The session link names its date and time and opens the schedule at that session's row. ?>
      <td><?= h(PREQ_TYPES[$r['type']] ?? 'Request') ?><?= $r['start_at'] ? '<br><a class="muted" href="schedule.php#s-' . (int)$r['session_id'] . '">session ' . fmt_datetime($r['start_at']) . '<span class="visually-hidden"> on the schedule</span></a>' : '' ?></td>
      <?php // Line breaks the client typed are kept (a list of times stays a list). ?>
      <td><?= $r['details'] ? nl2br(h($r['details'])) : '<span class="muted">—</span>' ?></td>
      <td><span class="badge <?= $r['status'] === 'Done' ? 'badge-closed' : ($r['status'] === 'Declined' ? 'badge-hold' : 'badge-pending') ?>"><?= h($r['status']) ?></span></td>
      <td>
        <form method="post" action="portal_requests.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <label class="visually-hidden" for="s-<?= (int)$r['id'] ?>">Set status for <?= $which ?></label>
          <select id="s-<?= (int)$r['id'] ?>" name="to"><?= select_options(PREQ_STATUSES, $r['status']) ?></select>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> status for <?= $which ?></span></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
