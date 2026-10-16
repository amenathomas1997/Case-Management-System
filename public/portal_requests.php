<?php
// Staff queue of self-service requests clients send from their portal
// (appointments, reschedule/cancel, device help, other). Caseload-scoped.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

const PREQ_STATUSES = ['New', 'In Progress', 'Done', 'Declined'];
const PREQ_TYPES = ['appointment' => 'Appointment', 'reschedule' => 'Reschedule', 'cancel' => 'Cancellation', 'device' => 'Device help', 'other' => 'Request'] + RECORDS_REQUEST_TYPES;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $id = (int)($_POST['id'] ?? 0);
    $r = db()->prepare('SELECT pr.id, pr.client_id, pr.type, pr.status, c.name AS client_name, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ?');
    $r->execute([$id]);
    if ($row = $r->fetch()) {
        if (caseload_scoped() && (int)$row['assigned_to'] !== (int)$user['id']) { flash('That request is not on your caseload.', 'error'); redirect('portal_requests.php'); }
        $to = in_array($_POST['to'] ?? '', PREQ_STATUSES, true) ? $_POST['to'] : null;
        if ($to === null) { keep_field_errors(['to_' . $id => 'Choose a status for the request.']); redirect('portal_requests.php'); }
        // A records request closes with what was done: how the copy was delivered, what was
        // corrected, or why it was refused (3.0.38). The client sees it in the portal.
        $outcome = trim(is_string($_POST['outcome'] ?? null) ? $_POST['outcome'] : '');
        if (isset(RECORDS_REQUEST_TYPES[$row['type']]) && in_array($to, ['Done', 'Declined'], true) && $outcome === '') {
            keep_field_errors(['to_' . $id => $to === 'Declined' ? 'Say why the request is refused; the client sees it.' : 'Say how the copy was delivered or what was corrected; the client sees it.']);
            redirect('portal_requests.php');
        }
        if (mb_strlen($outcome) > 500) { keep_field_errors(['to_' . $id => 'Keep the outcome to 500 characters or fewer.']); redirect('portal_requests.php'); }
        // Saving the status it already has changes nothing, so nothing is audited.
        if ($to === $row['status']) { flash('Request from ' . $row['client_name'] . ' (' . (PREQ_TYPES[$row['type']] ?? 'Request') . ') is already ' . $to . '; nothing was changed.'); redirect('portal_requests.php'); }
        $closed = ['Done', 'Declined'];
        db()->beginTransaction();
        try {
            db()->prepare('UPDATE portal_requests SET status = ?, handled_by = ?, outcome = COALESCE(?, outcome) WHERE id = ?')->execute([$to, $user['id'], $outcome !== '' ? $outcome : null, $id]);
            // A handled request closes the follow-up task the portal opened for it, so it
            // does not sit Open and go overdue (requests sent before the key existed have none).
            if (in_array($to, $closed, true)) {
                complete_task_by_key('preq-' . $id, (int)$user['id']);
            } elseif (in_array($row['status'], $closed, true)) {
                // Opened again: so is its follow-up task (only this request's own task),
                // with its original due date. It goes to the client's instructor as of now
                // (an active account; unassigned otherwise, as when the portal opened it),
                // so a former instructor of a reassigned client never gets the request back.
                $owner = db()->prepare('SELECT u.id FROM clients c JOIN users u ON u.id = c.assigned_to AND u.is_active = 1 WHERE c.id = ?');
                $owner->execute([(int)$row['client_id']]);
                $ownerId = $owner->fetchColumn();
                db()->prepare("UPDATE tasks SET status = 'Open', done_at = NULL, done_by = NULL, assigned_to = ? WHERE auto_key = ? AND status = 'Done'")
                    ->execute([$ownerId !== false ? (int)$ownerId : null, 'preq-' . $id]);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        // Tied to the client, and naming the request, like the other client audit entries.
        audit($user['id'], 'portal_request.status', 'client', (int)$row['client_id'], 'request #' . $id . ': ' . $row['status'] . ' → ' . $to);
        // The client is emailed that it was answered (3.0.40); the answer stays in the portal.
        if (in_array($to, $closed, true) && !in_array($row['status'], $closed, true)) request_closed_notice($id);
        flash('Request from ' . $row['client_name'] . ' (' . (PREQ_TYPES[$row['type']] ?? 'Request') . ') set to ' . $to . '.');
    }
    redirect('portal_requests.php');
}

/* A copy of the record, published to the client's portal for a records request (3.0.38). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_copy'])) {
    $id = (int)($_POST['id'] ?? 0);
    $r = db()->prepare("SELECT pr.client_id, c.name AS client_name, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ? AND pr.type = 'records'");
    $r->execute([$id]);
    $row = $r->fetch();
    if (!$row || (caseload_scoped() && (int)$row['assigned_to'] !== (int)$user['id'])) { flash('That records request was not found on your caseload.', 'error'); redirect('portal_requests.php'); }
    $docId = client_records_copy_publish((int)$row['client_id'], (int)$user['id']);
    flash($docId ? 'A copy of ' . $row['client_name'] . '\'s records is now under Resources in their portal. Set the request to Done with how it was delivered.' : 'The copy could not be saved. Try again.', $docId ? 'success' : 'error');
    redirect('portal_requests.php');
}

$scoped = caseload_scoped();
$showDone = !empty($_GET['all']);
$scopeSql = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeArg = $scoped ? [(int)$user['id']] : [];
// 300 rows are listed; one more is read only to know whether any were left out, so
// the page never claims to show "all" requests when it does not.
$sql = "SELECT pr.*, c.name AS client_name, c.id AS cid, s.start_at
    FROM portal_requests pr JOIN clients c ON c.id = pr.client_id
    LEFT JOIN scheduled_sessions s ON s.id = pr.session_id
    WHERE 1=1" . ($showDone ? '' : " AND pr.status IN ('New','In Progress')") . $scopeSql . '
    ORDER BY pr.status = \'New\' DESC, pr.created_at DESC LIMIT 301';
$st = db()->prepare($sql);
$st->execute($scopeArg);
$rows = $st->fetchAll();
$truncated = count($rows) > 300;
$rows = array_slice($rows, 0, 300);
// Counted over every request in scope, not only the rows listed.
$nc = db()->prepare("SELECT COUNT(*) FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.status = 'New'" . $scopeSql);
$nc->execute($scopeArg);
$newCount = (int)$nc->fetchColumn();

page_header('Client requests', $user, 'portal_requests');
?>
<h1>Client self-service requests</h1>
<p class="muted">What clients have asked for from their portal<?= $scoped ? ' (your caseload)' : '' ?>. <?= $newCount ?> new.
  <?= $showDone ? ($truncated ? 'Showing the 300 most recent requests (new ones first), including done and declined ones; older requests are not listed.' : 'Showing all requests, including done and declined ones.')
      : ($truncated ? 'Showing 300 open requests (new and in progress, new ones first); more open requests are not listed. Done and declined requests are hidden.' : 'Showing open requests (new and in progress); done and declined requests are hidden.') ?></p>
<?php // The link text says what the list will show, read alone in a links list. ?>
<div class="page-actions"><a class="btn btn-secondary btn-sm" href="portal_requests.php<?= $showDone ? '' : '?all=1' ?>"><?= $showDone ? 'Show open requests only' : 'Show all requests, including done and declined' ?></a></div>

<div class="table-scroll" tabindex="0" role="region" aria-label="Client requests">
<table>
  <caption class="visually-hidden"><?= $showDone ? ($truncated ? 'Client self-service requests: the 300 most recent' : 'All client self-service requests')
      : 'Open client self-service requests (new and in progress)' . ($truncated ? ': the first 300' : '') ?></caption>
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
      <?php // Only staff who can open the schedule get a link to it; others see the date. ?>
      <td><?= h(PREQ_TYPES[$r['type']] ?? 'Request') ?><?= !empty($r['due_on']) ? '<br><strong>Due ' . fmt_date($r['due_on']) . '</strong>' : '' ?><?= $r['start_at'] ? (can('schedule')
          ? '<br><a class="muted" href="schedule.php#s-' . (int)$r['session_id'] . '">session ' . fmt_datetime($r['start_at']) . '<span class="visually-hidden"> on the schedule</span></a>'
          : '<br><span class="muted">session ' . fmt_datetime($r['start_at']) . '</span>') : '' ?></td>
      <?php // Line breaks the client typed are kept (a list of times stays a list). ?>
      <td><?= $r['details'] ? nl2br(h($r['details'])) : '<span class="muted">—</span>' ?><?= !empty($r['outcome']) ? '<br><span class="muted">Outcome: ' . h($r['outcome']) . '</span>' : '' ?></td>
      <td><span class="badge <?= $r['status'] === 'Done' ? 'badge-closed' : ($r['status'] === 'Declined' ? 'badge-hold' : 'badge-pending') ?>"><?= h($r['status']) ?></span></td>
      <td>
        <form method="post" action="portal_requests.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php $toKey = 'to_' . (int)$r['id']; ?>
          <?= form_error_summary([$toKey => 'Status for ' . $which], [$toKey => 's-' . (int)$r['id']]) ?>
          <label class="visually-hidden" for="s-<?= (int)$r['id'] ?>">Set status for <?= $which ?></label>
          <select id="s-<?= (int)$r['id'] ?>" name="to" <?= field_aria($toKey) ?>><?= select_options(PREQ_STATUSES, $r['status']) ?></select><?= field_error($toKey) ?>
          <?php if (isset(RECORDS_REQUEST_TYPES[$r['type']])): ?>
          <label for="o-<?= (int)$r['id'] ?>">Outcome the client sees<span class="visually-hidden"> for <?= $which ?></span></label>
          <input type="text" id="o-<?= (int)$r['id'] ?>" name="outcome" maxlength="500" value="<?= h((string)($r['outcome'] ?? '')) ?>">
          <?php endif; ?>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> status for <?= $which ?></span></button>
        </form>
        <?php if ($r['type'] === 'records' && !in_array($r['status'], ['Done', 'Declined'], true)): ?>
        <form method="post" action="portal_requests.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button type="submit" name="publish_copy" value="1" class="btn btn-secondary btn-sm">Publish a copy to the portal<span class="visually-hidden"> for <?= $which ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
