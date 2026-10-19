<?php
// Merge two client records: everything on the duplicate (notes, tracks,
// documents, sessions, history…) moves onto the record you keep, blank
// profile fields are filled from the duplicate, and the duplicate is removed.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/client_merge.php';
csrf_check();
$user = require_login();
require_perm('admin');

$clients = db()->query('SELECT id, name, status, dob, phone, email, archived_at FROM clients ORDER BY name')->fetchAll();
$byId = [];
foreach ($clients as $c) $byId[(int)$c['id']] = $c;

// The pair comes from the form or the URL, or from the draft kept while the
// admin signed in again, so a resumed merge returns to the same two records.
$keepId = (int)($_REQUEST['keep'] ?? old_in('keep', '0'));
$mergeId = (int)($_REQUEST['merge'] ?? old_in('merge', '0'));
$keep = $byId[$keepId] ?? null;
$dupe = $byId[$mergeId] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_merge'])) {
    if (!$keep || !$dupe || $keepId === $mergeId) {
        flash('Pick two different client records.', 'error');
        redirect('client_merge.php');
    }
    // Only the fills the admin left ticked on the confirmation are applied.
    $chosenFills = array_values(array_filter(is_array($_POST['fill'] ?? null) ? $_POST['fill'] : [],
        fn($f) => is_string($f) && isset(MERGE_FILL_FIELDS[$f])));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Both client rows are locked before anything else is read, so the reads
        // below see any edit committed while the merge waited (not an older snapshot).
        $pdo->prepare('SELECT id FROM clients WHERE id IN (?, ?) ORDER BY id FOR UPDATE')->execute([$keepId, $mergeId]);
        // The duplicate's open tasks and upcoming sessions, noted before they move:
        // those its instructor holds follow the kept client's instructor below
        // (as a reassignment does).
        $dupeTasks = $pdo->prepare("SELECT id FROM tasks WHERE client_id = ? AND status = 'Open'");
        $dupeTasks->execute([$mergeId]);
        $dupeTaskIds = array_map('intval', $dupeTasks->fetchAll(PDO::FETCH_COLUMN));
        $dupeSessions = $pdo->prepare("SELECT id FROM scheduled_sessions WHERE client_id = ? AND status = 'Scheduled' AND start_at >= NOW()");
        $dupeSessions->execute([$mergeId]);
        $dupeSessionIds = array_map('intval', $dupeSessions->fetchAll(PDO::FETCH_COLUMN));
        move_client_records($pdo, $keepId, $mergeId);
        // A portal link the client is using on the duplicate moves to the kept
        // record when that has none (one link per client); otherwise the kept
        // record's own link stays and the duplicate's stops working.
        $keepPortal = $pdo->prepare('SELECT id FROM portal_tokens WHERE client_id = ? FOR UPDATE');
        $keepPortal->execute([$keepId]);
        $portalMoved = $portalDropped = 0;
        if ($keepPortal->fetchColumn() === false) {
            $pm = $pdo->prepare('UPDATE portal_tokens SET client_id = ? WHERE client_id = ?');
            $pm->execute([$keepId, $mergeId]);
            $portalMoved = $pm->rowCount();
        } else {
            $pd = $pdo->prepare('DELETE FROM portal_tokens WHERE client_id = ?');
            $pd->execute([$mergeId]);
            $portalDropped = $pd->rowCount();
        }
        // Fill the chosen blank profile fields on the kept record from the duplicate
        $src = $pdo->prepare('SELECT * FROM clients WHERE id = ? FOR UPDATE');
        $src->execute([$mergeId]);
        $s = $src->fetch();
        $tgt = $pdo->prepare('SELECT * FROM clients WHERE id = ? FOR UPDATE');
        $tgt->execute([$keepId]);
        $t = $tgt->fetch();
        $fill = array_intersect_key(merge_fillable($t, $s), array_flip($chosenFills));
        $filledLabels = array_values(array_intersect_key(MERGE_FILL_FIELDS, $fill));
        $fill = merge_fill_columns($fill, $s);
        // A legal hold on the duplicate is never lost (4.27.0): the kept record takes it
        // when it has none of its own (its own hold stays otherwise), read from the
        // locked rows, so the records that move across stay protected from purging.
        $holdCarried = merge_hold_carry($t, $s);
        $fill += $holdCarried;
        $dupeHeld = !empty($s['hold_at']);
        // A copied counselor email keeps the duplicate's confirmation state: an
        // address staff have not confirmed stays unconfirmed on the kept record.
        if (isset($fill['counselor_email']) && array_key_exists('counselor_verified', $t)) {
            $fill['counselor_verified'] = (int)($s['counselor_verified'] ?? 0) === 1 ? 1 : 0;
        }
        // The same for the client's own email (one from the public referral form
        // gets no reminders, surveys or portal link until staff confirm it).
        if (isset($fill['email'])) $fill['email_verified'] = (int)$s['email_verified'] === 1 ? 1 : 0;
        // The access-needs profile comes across too: blank fields are filled,
        // "uses a screen reader" holds if either record says so, and access
        // notes on both records are kept (the duplicate's appended).
        foreach (['a11y_format', 'a11y_primary_tech', 'a11y_contact'] as $f) {
            if ((string)($t[$f] ?? '') === '' && (string)($s[$f] ?? '') !== '') $fill[$f] = $s[$f];
        }
        if (empty($t['a11y_screen_reader']) && !empty($s['a11y_screen_reader'])) $fill['a11y_screen_reader'] = 1;
        $keepNotes = trim((string)($t['a11y_notes'] ?? ''));
        $dupeNotes = trim((string)($s['a11y_notes'] ?? ''));
        if ($dupeNotes !== '' && $dupeNotes !== $keepNotes) {
            $fill['a11y_notes'] = $keepNotes === '' ? $dupeNotes : $keepNotes . "\n\n[Merged from #{$mergeId}] " . $dupeNotes;
            if (strlen($fill['a11y_notes']) > 65535) {
                throw new DomainException("The two records' access notes together are too long to keep in one record. Shorten one of them, then merge again.");
            }
        }
        // Merging an active duplicate into an archived record re-opens it, so the
        // case that moved across is not hidden from every list (status unchanged).
        $unarchive = $t['archived_at'] !== null && $s['archived_at'] === null;
        if ($unarchive) $fill['archived_at'] = null;
        if ($fill) {
            $pdo->prepare('UPDATE clients SET ' . implode(', ', array_map(fn($f) => "`$f` = ?", array_keys($fill))) . ' WHERE id = ?')
                ->execute([...array_values($fill), $keepId]);
        }
        $finalAssignee = isset($fill['assigned_to']) ? (int)$fill['assigned_to'] : ($t['assigned_to'] !== null ? (int)$t['assigned_to'] : null);
        $dupeAssignee = $s['assigned_to'] !== null ? (int)$s['assigned_to'] : null;
        $movedTasks = 0;
        if ($dupeTaskIds && $dupeAssignee !== null && $dupeAssignee !== $finalAssignee) {
            $mv = $pdo->prepare('UPDATE tasks SET assigned_to = ? WHERE id IN (' . implode(',', array_fill(0, count($dupeTaskIds), '?')) . ") AND status = 'Open' AND assigned_to = ?");
            $mv->execute([$finalAssignee, ...$dupeTaskIds, $dupeAssignee]);
            $movedTasks = $mv->rowCount();
        }
        // So do its upcoming sessions (a session always needs an instructor).
        // reminder_sent goes back to 0, so the new instructor still gets the
        // day-before reminder when the old one had already been sent it.
        $movedSessions = 0;
        $movedSessionRows = [];
        if ($dupeSessionIds && $dupeAssignee !== null && $finalAssignee !== null && $dupeAssignee !== $finalAssignee) {
            $idList = implode(',', array_fill(0, count($dupeSessionIds), '?'));
            $sq = $pdo->prepare("SELECT id, start_at, end_at FROM scheduled_sessions WHERE id IN ($idList) AND status = 'Scheduled' AND user_id = ? ORDER BY start_at, id FOR UPDATE");
            $sq->execute([...$dupeSessionIds, $dupeAssignee]);
            $movedSessionRows = $sq->fetchAll();
            if ($movedSessionRows) {
                $ids = array_map(fn($r) => (int)$r['id'], $movedSessionRows);
                $ms = $pdo->prepare('UPDATE scheduled_sessions SET user_id = ?, reminder_sent = 0, client_reminded_at = NULL, staff_reminded_at = NULL WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $ms->execute([$finalAssignee, ...$ids]);
                $movedSessions = count($ids);
            }
        }
        // Converted referrals (the intake copies) follow the kept client's instructor.
        $mr = $pdo->prepare("UPDATE referrals SET assigned_to = ? WHERE client_id = ? AND status = 'Converted' AND NOT (assigned_to <=> ?)");
        $mr->execute([$finalAssignee, $keepId, $finalAssignee]);
        $movedReferrals = $mr->rowCount();
        // Trashed items from the duplicate must restore onto the kept client,
        // and its auto assessment task must track the kept client's key. The
        // payload is rewritten in PHP so client_id stays a JSON number (matched
        // the same way whether an older merge stored it as a number or a string).
        $tr = $pdo->query("SELECT id, payload FROM trash WHERE entity IN ('client_documents','client_notes') FOR UPDATE");
        $trUpd = $pdo->prepare('UPDATE trash SET payload = ? WHERE id = ?');
        foreach ($tr->fetchAll() as $trow) {
            $pl = json_decode((string)$trow['payload'], true);
            if (!is_array($pl) || (int)($pl['client_id'] ?? 0) !== $mergeId) continue;
            $pl['client_id'] = $keepId;
            $trUpd->execute([json_encode($pl, JSON_THROW_ON_ERROR), (int)$trow['id']]);
        }
        // Staff alerts that link to the duplicate (survey results, consent, portal
        // requests and messages) now link to the kept record, so they open it and
        // a later purge of the kept record finds them. Links are matched whole,
        // so client 5 never re-points client 57's.
        $relink = $pdo->prepare('UPDATE notifications SET link = CONCAT(?, SUBSTRING(link, CHAR_LENGTH(?) + 1))
            WHERE link = ? OR link LIKE ? OR link LIKE ?');
        foreach (['client.php?id=', 'messages.php?client=', 'portal_requests.php?client='] as $base) {
            $old = $base . $mergeId;
            $like = addcslashes($old, '\\%_');
            $relink->execute([$base . $keepId, $old, $old, $like . '&%', $like . '#%']);
        }
        // The key is unique: when the kept client already has its own assessment
        // task, or has been assessed already, the duplicate's is closed (under its
        // old key nothing would ever complete it); otherwise it takes the kept
        // client's key. Assessments moved across from the duplicate count too.
        $keepTask = $pdo->prepare('SELECT id FROM tasks WHERE auto_key = ? FOR UPDATE');
        $keepTask->execute(['assess-' . $keepId]);
        $keepAssessed = $pdo->prepare('SELECT 1 FROM tech_assessments WHERE client_id = ? LIMIT 1');
        $keepAssessed->execute([$keepId]);
        if ($keepTask->fetchColumn() || $keepAssessed->fetchColumn()) {
            $pdo->prepare("UPDATE tasks SET auto_key = NULL, status = 'Done', done_at = COALESCE(done_at, NOW()), done_by = COALESCE(done_by, ?) WHERE auto_key = ?")
                ->execute([(int)$user['id'], 'assess-' . $mergeId]);
        } else {
            $pdo->prepare('UPDATE tasks SET auto_key = ? WHERE auto_key = ?')
                ->execute(['assess-' . $keepId, 'assess-' . $mergeId]);
        }
        $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$mergeId]);
        $pdo->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?)')
            ->execute([$keepId, 'Case Note', 'Duplicate record merged',
                "The duplicate client record \"{$s['name']}\" (#{$mergeId}) was merged into this record by {$user['name']} on " . date('F j, Y') . '.'
                . ($filledLabels ? ' Blank fields filled from the duplicate: ' . implode(', ', $filledLabels) . '.' : '')
                . ($unarchive ? ' This record was archived and was re-opened by the merge.' : '')
                . ($holdCarried ? ' The duplicate was on hold (' . (string)$holdCarried['hold_reason'] . '); the hold is kept on this record.' : '')
                . ($dupeHeld && !$holdCarried ? ' The duplicate was also on hold (' . (string)$s['hold_reason'] . '); this record\'s own hold stays.' : ''),
                (int)$user['id']]);
        $pdo->commit();
        audit($user['id'], 'client.merge', 'client', $keepId, "merged #{$mergeId} ({$s['name']}) into #{$keepId}"
            . (($filledKeys = array_keys(array_diff_key($fill, ['archived_at' => 1, 'hold_at' => 1, 'hold_by' => 1, 'hold_reason' => 1]))) ? '; filled: ' . implode(', ', $filledKeys) : '')
            . ($holdCarried ? "; the duplicate's hold was kept on this record" : '')
            . ($unarchive ? '; re-opened (was archived)' : '')
            . ($portalMoved ? '; portal link moved from the duplicate' : '')
            . ($portalDropped ? "; duplicate's portal link turned off" : ''));
        if ($movedTasks > 0) {
            audit($user['id'], 'task.reassign', 'client', $keepId, $movedTasks . ' open task(s) from merged #' . $mergeId . ' moved from instructor #'
                . $dupeAssignee . ' to ' . ($finalAssignee !== null ? '#' . $finalAssignee : 'unassigned'));
        }
        if ($movedSessions > 0) {
            audit($user['id'], 'session.reassign', 'client', $keepId, $movedSessions . ' upcoming session(s) from merged #' . $mergeId . ' moved from instructor #'
                . $dupeAssignee . ' to #' . $finalAssignee);
        }
        if ($movedReferrals > 0) {
            audit($user['id'], 'referral.reassign', 'client', $keepId, $movedReferrals . ' converted referral(s) moved to '
                . ($finalAssignee !== null ? 'instructor #' . $finalAssignee : 'unassigned'));
        }
        $told = ['to' => false, 'from' => false];
        try {
            $told = send_session_move_notices($movedSessionRows, $dupeAssignee, $finalAssignee, (string)$t['name'], (int)$user['id']);
        } catch (Throwable $e) {
            // The merge is committed: a notice that fails must not report it as undone.
            error_log('Merge session notices failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        }
        flash('Records merged. Everything from "' . $s['name'] . '" is now on this client.'
            . ($movedSessions > 0 ? ' ' . $movedSessions . ' upcoming session' . ($movedSessions === 1 ? '' : 's') . ' moved to this client\'s instructor.'
                . ($told['to'] ? ' The new instructor was sent the list.' : '') . ($told['from'] ? ' The previous instructor was told they were moved.' : '') : '')
            . ($portalMoved ? ' The portal link the client had for the duplicate keeps working and now opens this record.' : '')
            . ($portalDropped ? ' The duplicate\'s portal link no longer works, because this record already had its own: if the client was using the duplicate\'s link, send them a new portal link.' : '')
            . ($unarchive ? ' The kept record was archived; the merge re-opened it.' : '')
            . ($holdCarried ? ' The duplicate was on hold, so this record is now on hold for the same reason.' : ''));
        redirect('client.php?id=' . $keepId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Only the merge's own validation messages are safe to show. Database
        // errors can quote record data, so they are logged by class and code only.
        error_log('Client merge failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        flash('Merge failed and nothing was changed' . ($e instanceof DomainException
            ? ': ' . $e->getMessage()
            : '. Please try again; if it keeps failing, contact support.'), 'error');
        // Back to the same pair, so neither record has to be found again.
        redirect('client_merge.php?keep=' . $keepId . '&merge=' . $mergeId);
    }
}

page_header('Merge clients', $user, 'clients');
?>
<h1>Merge duplicate client records</h1>
<p class="muted">Everything on the duplicate — notes, training tracks, assessments, documents, sessions, history, referrals — moves onto the record you keep. You choose which blank profile fields on the kept record are filled from the duplicate; blank access-needs fields are filled, and both records' access notes are kept. The duplicate record is then removed, and a note documents the merge.</p>

<?php $confirming = $keep && $dupe && $keepId !== $mergeId; ?>
<?php if ($keepId > 0 && $keepId === $mergeId): ?>
<?= error_notice('The same record is chosen as both the record to keep and the duplicate. Choose two different client records.') ?>
<?php endif; ?>
<form method="<?= $confirming ? 'post' : 'get' ?>" action="client_merge.php" class="card stacked">
  <?php if ($confirming): ?>
    <?= csrf_field() ?>
    <!-- The confirmed pair is locked in as hidden fields, so the merge can
         only apply to exactly the two records shown in the warning below. -->
    <input type="hidden" name="keep" value="<?= $keepId ?>">
    <input type="hidden" name="merge" value="<?= $mergeId ?>">
    <p>Keeping <strong><?= h($keep['name']) ?></strong> (#<?= $keepId ?>) and merging in <strong><?= h($dupe['name']) ?></strong> (#<?= $mergeId ?>).
       <a href="client_merge.php">Choose a different pair</a></p>
  <?php else: ?>
  <p class="hint">Fields marked * are required.</p>
  <div class="grid grid-2">
    <div class="field">
      <label for="keep">Record to KEEP <?= required_mark() ?></label>
      <select id="keep" name="keep" required>
        <option value="">— Choose —</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $keepId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (#<?= (int)$c['id'] ?>, <?= h($c['status']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="merge">Duplicate to merge IN (will be removed) <?= required_mark() ?></label>
      <select id="merge" name="merge" required>
        <option value="">— Choose —</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $mergeId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (#<?= (int)$c['id'] ?>, <?= h($c['status']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($confirming): ?>
  <div class="alert alert-error" role="alert">
    <p><strong>Check carefully — merging cannot be undone.</strong></p>
    <ul>
      <?php // A missing date of birth is written out like the missing email and phone (a lone dash is skipped or misread by screen readers). ?>
      <li>Keeping: <strong><?= h($keep['name']) ?></strong> (#<?= $keepId ?>) — <?= h($keep['status']) ?><?= $keep['archived_at'] !== null ? ' (archived)' : '' ?>, <?= $keep['dob'] ? 'date of birth ' . fmt_date($keep['dob']) : 'no date of birth' ?>, <?= h($keep['email'] ?: 'no email') ?>, <?= h($keep['phone'] ?: 'no phone') ?></li>
      <li>Merging in &amp; removing: <strong><?= h($dupe['name']) ?></strong> (#<?= $mergeId ?>) — <?= h($dupe['status']) ?><?= $dupe['archived_at'] !== null ? ' (archived)' : '' ?>, <?= $dupe['dob'] ? 'date of birth ' . fmt_date($dupe['dob']) : 'no date of birth' ?>, <?= h($dupe['email'] ?: 'no email') ?>, <?= h($dupe['phone'] ?: 'no phone') ?></li>
      <?php $pq = db()->prepare('SELECT client_id FROM portal_tokens WHERE client_id IN (?, ?)');
      $pq->execute([$keepId, $mergeId]);
      $withPortal = array_map('intval', $pq->fetchAll(PDO::FETCH_COLUMN));
      if (in_array($mergeId, $withPortal, true)): ?>
      <li><?= in_array($keepId, $withPortal, true)
          ? 'Both records have a client portal link. The kept record\'s link stays; the duplicate\'s stops working, so if the client uses that one, send them a new portal link after the merge.'
          : 'The duplicate has a client portal link. It keeps working after the merge and opens the kept record.' ?></li>
      <?php endif; ?>
      <?php // A legal hold on either record (4.27.0): said here, and never lost by the merge.
      $hq = db()->prepare('SELECT id, hold_at, hold_reason FROM clients WHERE id IN (?, ?) AND hold_at IS NOT NULL');
      $hq->execute([$keepId, $mergeId]);
      $holds = [];
      foreach ($hq->fetchAll() as $hr) $holds[(int)$hr['id']] = $hr;
      if (isset($holds[$mergeId])): ?>
      <li><?= isset($holds[$keepId])
          ? 'Both records are on hold. The kept record\'s hold (' . h($holds[$keepId]['hold_reason']) . ') stays; the duplicate\'s reason (' . h($holds[$mergeId]['hold_reason']) . ') is written in the merge note.'
          : 'The duplicate is on hold since ' . h(fmt_date($holds[$mergeId]['hold_at'])) . ' (' . h($holds[$mergeId]['hold_reason']) . '). The hold will be kept: the record you keep is placed on hold for the same reason, so nothing moved across can be purged.' ?></li>
      <?php elseif (isset($holds[$keepId])): ?>
      <li>The record you keep is on hold (<?= h($holds[$keepId]['hold_reason']) ?>). The hold stays after the merge.</li>
      <?php endif; ?>
      <?php if ($keep['archived_at'] !== null && $dupe['archived_at'] === null): ?>
      <li>The record you keep is archived. The merge re-opens it, so the duplicate's current work stays in the client lists; its status is not changed.</li>
      <?php elseif ($keep['archived_at'] !== null): ?>
      <li>Both records are archived, so the merged record stays archived and hidden from day-to-day lists.</li>
      <?php endif; ?>
    </ul>
  </div>
  <?php // Every blank field the duplicate would fill is shown with its value and
  // can be left out; the email addresses are copied only when ticked.
  $fr = db()->prepare('SELECT * FROM clients WHERE id = ?');
  $fr->execute([$keepId]); $keepRow = $fr->fetch() ?: [];
  $fr->execute([$mergeId]); $dupeRow = $fr->fetch() ?: [];
  $fillable = merge_fillable($keepRow, $dupeRow); ?>
  <?php if ($fillable): ?>
  <fieldset aria-describedby="fill-hint">
    <legend>Blank fields on <?= h($keep['name']) ?> (#<?= $keepId ?>) to fill from the duplicate</legend>
    <p class="hint" id="fill-hint">Untick any value that should not be copied; an unticked value is removed with the duplicate. The client, guardian and counselor email addresses start unticked and are copied only when you tick them, because session reminders, portal links, progress reports and messages are then sent there — check they are genuine first.<?= isset($fillable['counselor_email']) || isset($fillable['email']) ? ' An email marked not yet confirmed stays unconfirmed after the merge.' : '' ?></p>
    <?php foreach ($fillable as $f => $val):
      if ($f === 'dob') $shown = fmt_date($val);
      elseif ($f === 'assigned_to') {
          $un = db()->prepare('SELECT name FROM users WHERE id = ?');
          $un->execute([(int)$val]);
          $shown = (string)($un->fetchColumn() ?: 'user #' . (int)$val);
      } elseif ($f === 'office_id') $shown = (string)(office_get((int)$val)['name'] ?? 'office #' . (int)$val);
      elseif (in_array($f, ['ipe_date', 'ipe_amended_on'], true)) $shown = fmt_date((string)$val);
      elseif ($f === 'privacy_notice_at') $shown = fmt_date((string)$val) . (!empty($dupeRow['privacy_notice_how']) ? ', ' . strtolower((string)$dupeRow['privacy_notice_how']) : '');
      elseif ($f === 'preferred_language') $shown = $val === 'es' ? 'Spanish' : (string)$val;
      elseif ($f === 'time_zone') $shown = (string)(US_TIME_ZONES[$val] ?? $val);
      elseif ($f === 'vision_onset_age') $shown = (int)$val . ' years';
      else $shown = (string)$val;
      // An unconfirmed counselor or client email is copied as unconfirmed, and says so here.
      $fieldLabel = MERGE_FILL_FIELDS[$f] . (($f === 'counselor_email' && array_key_exists('counselor_verified', $dupeRow) && (int)$dupeRow['counselor_verified'] !== 1)
          || ($f === 'email' && (int)$dupeRow['email_verified'] !== 1) ? ' (not yet confirmed)' : ''); ?>
    <div class="field"><label><input type="checkbox" name="fill[]" value="<?= h($f) ?>"<?= in_array($f, MERGE_FILL_OPT_IN, true) ? '' : ' checked' ?>> <?= h($fieldLabel) ?>: <?= h($shown) ?></label></div>
    <?php endforeach; ?>
  </fieldset>
  <?php else: ?>
  <p>No blank profile fields on the kept record will be filled from the duplicate.</p>
  <?php endif; ?>
  <button type="submit" name="do_merge" value="1" class="btn btn-danger"
    onclick="return confirm('Merge and remove the duplicate record? This cannot be undone.');">Merge these records</button>
  <?php else: ?>
  <button type="submit" class="btn btn-primary">Review merge</button>
  <?php endif; ?>
</form>
<?php page_footer();
