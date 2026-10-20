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
        // 4.29.0: an outcome error is on the Outcome box (o-<id>), and the status chosen and the
        // outcome typed are kept, so the form comes back as it was sent.
        if (isset(RECORDS_REQUEST_TYPES[$row['type']]) && in_array($to, ['Done', 'Declined'], true) && $outcome === '') {
            keep_input();
            keep_field_errors(['outcome_' . $id => $to === 'Declined' ? 'Say why the request is refused; the client sees it.' : 'Say how the copy was delivered or what was corrected; the client sees it.']);
            redirect('portal_requests.php');
        }
        if (mb_strlen($outcome) > 500) { keep_input(); keep_field_errors(['outcome_' . $id => 'Keep the outcome to 500 characters or fewer.']); redirect('portal_requests.php'); }
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
                // Times still offered on it are withdrawn: the client can no longer pick one.
                db()->prepare("UPDATE portal_request_offers SET status = 'Withdrawn' WHERE request_id = ? AND status = 'Offered'")->execute([$id]);
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

/* Several requests closed at once (4.0.0), not records requests (each needs its own outcome). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_close'])) {
    $to = ($_POST['bulk_to'] ?? '') === 'Declined' ? 'Declined' : 'Done';
    $n = 0;
    $bulkIds = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($_POST['bulk_ids'] ?? []))))), 0, 200);
    // 4.30.0: nothing ticked is tied to the boxes (not a flash), and the choice of Done or Declined is kept.
    if (!$bulkIds) { keep_input(); keep_field_errors(['bulk_ids' => 'Tick at least one open request.']); redirect('portal_requests.php#bulk-h'); }
    foreach ($bulkIds as $bid) {
        $bq = db()->prepare("SELECT pr.id, pr.client_id, pr.type, pr.status, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ? AND pr.status IN ('New','In Progress')");
        $bq->execute([$bid]);
        $br = $bq->fetch();
        if (!$br || isset(RECORDS_REQUEST_TYPES[$br['type']]) || (caseload_scoped() && (int)$br['assigned_to'] !== (int)$user['id'])) continue;
        $u = db()->prepare("UPDATE portal_requests SET status = ?, handled_by = ? WHERE id = ? AND status IN ('New','In Progress')");
        $u->execute([$to, $user['id'], $bid]);
        if ($u->rowCount() !== 1) continue;
        complete_task_by_key('preq-' . $bid, (int)$user['id']);
        db()->prepare("UPDATE portal_request_offers SET status = 'Withdrawn' WHERE request_id = ? AND status = 'Offered'")->execute([$bid]);
        audit($user['id'], 'portal_request.status', 'client', (int)$br['client_id'], 'request #' . $bid . ': ' . $br['status'] . ' → ' . $to . ' (several at once)');
        request_closed_notice($bid);
        $n++;
    }
    flash($n ? $n . ' request' . ($n === 1 ? '' : 's') . ' set to ' . $to . '.' : 'Nothing was changed: the chosen requests are no longer open.', $n ? 'success' : 'error');
    redirect('portal_requests.php');
}
/* Times offered for an appointment or reschedule request (3.0.52): the client confirms one in the portal. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_times'])) {
    $id = (int)($_POST['id'] ?? 0);
    $r = db()->prepare('SELECT pr.client_id, c.name AS client_name, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ?');
    $r->execute([$id]);
    $row = $r->fetch();
    if (!$row || (caseload_scoped() && (int)$row['assigned_to'] !== (int)$user['id'])) { flash('That request is not on your caseload.', 'error'); redirect('portal_requests.php'); }
    $starts = [];
    // 4.29.0: each unreadable time is marked on its own box, and the offer form opens again with them.
    $timeErrs = [];
    foreach ([1, 2, 3] as $n) {
        $v = trim(is_string($_POST['time' . $n] ?? null) ? $_POST['time' . $n] : '');
        if ($v === '') continue;
        $ts = strtotime($v);
        if ($ts === false) { $timeErrs['offer_t' . $n . '-' . $id] = 'This time could not be read. Enter a date and a time.'; continue; }
        $starts[] = $ts;
    }
    $_POST['offer_req'] = (string)$id; // refills only this request's form
    if ($timeErrs) { keep_input(); keep_field_errors($timeErrs); redirect('portal_requests.php#offer-' . $id); }
    $staff = (int)($_POST['staff'] ?? 0);
    $su = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1');
    $su->execute([$staff]);
    if (!(int)$su->fetchColumn()) { keep_input(); keep_field_errors(['offer_staff-' . $id => 'Choose who will run the session.']); redirect('portal_requests.php#offer-' . $id); }
    // 4.28.0: never someone limited to their own caseload who is not assigned this client (they
    // could not open the session's client); the error is on the With field.
    if (($why = offer_staff_problem($staff, (int)$row['client_id'])) !== null) { keep_input(); keep_field_errors(['offer_staff-' . $id => $why]); redirect('portal_requests.php#offer-' . $id); }
    $res = offer_times_save($id, $starts, (int)($_POST['minutes'] ?? 60), $staff, (int)($_POST['track_id'] ?? 0) ?: null, !empty($_POST['remote']),
        (string)($_POST['location'] ?? ''), (int)$user['id']);
    if ($res['error'] !== null) {
        keep_input();
        // Each error on the box it is about; one about the request itself (closed, no portal link) is a message.
        $errField = match (true) {
            ($res['field'] ?? '') === 'staff' => 'offer_staff-' . $id,
            str_starts_with($res['error'], 'Choose a length') => 'offer_len-' . $id,
            str_starts_with($res['error'], 'Choose one of this client') => 'offer_track-' . $id,
            str_starts_with($res['error'], 'Enter at least one'), str_starts_with($res['error'], 'Offer at most'), str_starts_with($res['error'], 'None of the times') => 'offer_t1-' . $id,
            default => null,
        };
        if ($errField !== null) keep_field_errors([$errField => $res['error']]); else flash($res['error'], 'error');
        redirect('portal_requests.php#offer-' . $id);
    }
    flash($res['offered'] . ' time' . ($res['offered'] === 1 ? '' : 's') . ' offered to ' . $row['client_name'] . '. They choose one in their portal.'
        . (empty($res['emailed']) ? ' They were not emailed (they prefer phone or post, or have no confirmed email): tell them the times are waiting.' : '')
        . ($res['refused'] ? ' Not offered: ' . implode(' ', $res['refused']) : ''));
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
          <?php $outKey = 'outcome_' . (int)$r['id']; $stMine = old_in('set_status') !== '' && old_in('id') === (string)(int)$r['id']; ?>
          <?= form_error_summary([$toKey => 'Status for ' . $which, $outKey => 'Outcome for ' . $which], [$toKey => 's-' . (int)$r['id'], $outKey => 'o-' . (int)$r['id']]) ?>
          <label class="visually-hidden" for="s-<?= (int)$r['id'] ?>">Set status for <?= $which ?></label>
          <select id="s-<?= (int)$r['id'] ?>" name="to" <?= field_aria($toKey) ?>><?= select_options(PREQ_STATUSES, $stMine && in_array(old_in('to'), PREQ_STATUSES, true) ? old_in('to') : $r['status']) ?></select><?= field_error($toKey) ?>
          <?php if (isset(RECORDS_REQUEST_TYPES[$r['type']])): ?>
          <label for="o-<?= (int)$r['id'] ?>">Outcome the client sees<span class="visually-hidden"> for <?= $which ?></span></label>
          <input type="text" id="o-<?= (int)$r['id'] ?>" name="outcome" maxlength="500" value="<?= h($stMine ? old_in('outcome') : (string)($r['outcome'] ?? '')) ?>" <?= field_aria($outKey) ?>><?= field_error($outKey) ?>
          <?php endif; ?>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> status for <?= $which ?></span></button>
        </form>
        <?php if (in_array($r['type'], OFFER_REQUEST_TYPES, true) && !in_array($r['status'], ['Done', 'Declined'], true)):
            $open = request_offers((int)$r['id']);
            $staffList ??= db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
            $trq = db()->prepare("SELECT id, title FROM training_tracks WHERE client_id = ? AND status = 'Active' ORDER BY id DESC");
            $trq->execute([(int)$r['cid']]);
            $trackList = $trq->fetchAll();
            $rid = (int)$r['id'];
            $assignedQ = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?'); $assignedQ->execute([(int)$r['cid']]); $defStaff = (int)$assignedQ->fetchColumn() ?: (int)$user['id'];
            // A reschedule starts from the session's own instructor and place (4.28.0): what is
            // offered here is what the session becomes when the client chooses a time.
            $defRemote = false; $defLoc = '';
            if ($r['type'] === 'reschedule' && !empty($r['session_id'])) {
                $msq = db()->prepare("SELECT user_id, is_remote, location FROM scheduled_sessions WHERE id = ?"); $msq->execute([(int)$r['session_id']]);
                if ($ms = $msq->fetch()) { $defStaff = (int)$ms['user_id'] ?: $defStaff; $defRemote = (int)$ms['is_remote'] === 1; $defLoc = (string)($ms['location'] ?? ''); }
            } ?>
        <?php if ($open): ?><p>Offered: <?= h(implode('; ', array_map(fn($o) => date('D, M j g:i A', strtotime($o['start_at'])), $open))) ?></p><?php endif; ?>
        <?php // 4.29.0: every field of the offer form can carry its error; the form opens again after any refused offer.
            $offLabels = ['offer_t1-' . $rid => 'First time', 'offer_t2-' . $rid => 'Second time', 'offer_t3-' . $rid => 'Third time',
                'offer_len-' . $rid => 'Length', 'offer_staff-' . $rid => 'With', 'offer_track-' . $rid => 'Training track'];
            $offAnchors = ['offer_t1-' . $rid => 't1-' . $rid, 'offer_t2-' . $rid => 't2-' . $rid, 'offer_t3-' . $rid => 't3-' . $rid,
                'offer_len-' . $rid => 'len-' . $rid, 'offer_staff-' . $rid => 'st-' . $rid, 'offer_track-' . $rid => 'tr-' . $rid];
            $offMine = old_in('offer_req') === (string)$rid;
            $offOpen = $offMine || array_intersect_key(form_errors(), $offLabels); ?>
        <details id="offer-<?= $rid ?>"<?= $offOpen ? ' open' : '' ?>><summary><?= $open ? 'Offer different times' : 'Offer times' ?><span class="visually-hidden"> for <?= $which ?></span></summary>
          <form method="post" action="portal_requests.php" class="stacked"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $rid ?>">
            <?= form_error_summary($offLabels, $offAnchors) ?>
            <?php foreach ([1 => 'First time', 2 => 'Second time', 3 => 'Third time'] as $n => $lbl): ?>
            <div class="field"><label for="t<?= $n ?>-<?= $rid ?>"><?= $lbl ?></label><input type="datetime-local" id="t<?= $n ?>-<?= $rid ?>" name="time<?= $n ?>" value="<?= $offMine ? h(old_in('time' . $n)) : '' ?>" <?= field_aria('offer_t' . $n . '-' . $rid) ?>><?= field_error('offer_t' . $n . '-' . $rid) ?></div>
            <?php endforeach; ?>
            <?php if ($r['type'] === 'appointment'): ?><div class="field"><label for="len-<?= $rid ?>">Length</label><select id="len-<?= $rid ?>" name="minutes" <?= field_aria('offer_len-' . $rid) ?>><?php $lenSel = $offMine && ctype_digit(old_in('minutes')) ? (int)old_in('minutes') : 60; foreach ([30, 45, 60, 90, 120, 180] as $mn): ?><option value="<?= $mn ?>"<?= $mn === $lenSel ? ' selected' : '' ?>><?= $mn ?> minutes</option><?php endforeach; ?></select><?= field_error('offer_len-' . $rid) ?></div><?php endif; ?>
            <div class="field"><label for="st-<?= $rid ?>">With</label><select id="st-<?= $rid ?>" name="staff" <?= field_aria('offer_staff-' . $rid) ?>><?php foreach ($staffList as $sl): ?><option value="<?= (int)$sl['id'] ?>"<?= (int)$sl['id'] === (old_in('offer_req') === (string)$rid && ctype_digit(old_in('staff')) ? (int)old_in('staff') : $defStaff) ? ' selected' : '' ?>><?= h($sl['name']) ?></option><?php endforeach; ?></select><?= field_error('offer_staff-' . $rid) ?></div>
            <?php if ($r['type'] === 'reschedule'): ?><p>The session keeps its length.</p><?php endif; ?>
            <?php if ($r['type'] === 'appointment' && $trackList): ?>
            <div class="field"><label for="tr-<?= $rid ?>">Training track</label><select id="tr-<?= $rid ?>" name="track_id" <?= field_aria('offer_track-' . $rid) ?>><?php foreach ($trackList as $tl): ?><option value="<?= (int)$tl['id'] ?>"<?= $offMine && old_in('track_id') === (string)(int)$tl['id'] ? ' selected' : '' ?>><?= h($tl['title']) ?></option><?php endforeach; ?></select><?= field_error('offer_track-' . $rid) ?></div>
            <?php endif; ?>
            <div class="field"><label><input type="checkbox" name="remote" value="1"<?= ($offMine ? old_in('remote') === '1' : $defRemote) ? ' checked' : '' ?>> Remote</label></div>
            <div class="field"><label for="loc-<?= $rid ?>">Location</label><input type="text" id="loc-<?= $rid ?>" name="location" maxlength="190" value="<?= h($offMine ? old_in('location') : $defLoc) ?>"></div>
            <button type="submit" name="offer_times" value="1" class="btn btn-primary btn-sm">Offer these times<span class="visually-hidden"> for <?= $which ?></span></button>
          </form>
        </details>
        <?php endif; ?>
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
<?php $bulkReq = array_values(array_filter($rows, fn($r) => in_array($r['status'], ['New', 'In Progress'], true) && !isset(RECORDS_REQUEST_TYPES[$r['type']]))); if (count($bulkReq) > 1): ?>
<?php $bulkKept = old_in_list('bulk_ids'); ?>
<details<?= has_field_error('bulk_ids') ? ' open' : '' ?>><summary id="bulk-h">Close several requests at once</summary>
  <form method="post" action="portal_requests.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['bulk_ids' => 'Open requests'], ['bulk_ids' => 'bulk-' . (int)$bulkReq[0]['id']]) ?>
    <fieldset <?= field_aria('bulk_ids') ?>><legend>Open requests</legend>
    <?= field_error('bulk_ids') ?>
    <?php foreach ($bulkReq as $r): ?><label><input type="checkbox" id="bulk-<?= (int)$r['id'] ?>" name="bulk_ids[]" value="<?= (int)$r['id'] ?>"<?= in_array((string)(int)$r['id'], $bulkKept, true) ? ' checked' : '' ?>> <?= h($r['client_name']) ?>, <?= h(PREQ_TYPES[$r['type']] ?? 'Request') ?>, <?= h(fmt_date($r['created_at'])) ?></label><?php endforeach; ?>
    </fieldset>
    <fieldset><legend>Set them to</legend>
      <label><input type="radio" name="bulk_to" value="Done"<?= old_in('bulk_to') !== 'Declined' ? ' checked' : '' ?>> Done</label>
      <label><input type="radio" name="bulk_to" value="Declined"<?= old_in('bulk_to') === 'Declined' ? ' checked' : '' ?>> Declined</label>
    </fieldset>
    <button type="submit" name="bulk_close" value="1" class="btn btn-secondary">Close the chosen requests</button>
  </form>
</details>
<?php endif; ?>
<?php page_footer();
