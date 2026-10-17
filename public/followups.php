<?php
// Device follow-ups: post-provision retention checks on loaned/provisioned AT.
// Abandonment of assistive technology is a well-documented outcome risk, so each
// loan auto-schedules 30/60/90-day checks. Staff record whether the device is
// still in use, a satisfaction rating, and any barriers — feeding an abandonment
// / retention rate. Caseload-scoped by the client the device is with.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$scoped = caseload_scoped();

/** Load one follow-up with its client's assignment, for scope checks. */
function fu_load(int $id): ?array {
    $q = db()->prepare('SELECT f.*, c.assigned_to, c.name AS client_name, e.name AS equipment_name
        FROM equipment_followups f JOIN clients c ON c.id = f.client_id JOIN equipment e ON e.id = f.equipment_id
        WHERE f.id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $row = fu_load($id);
    if (!$row) { flash('That follow-up no longer exists.', 'error'); redirect('followups.php'); }
    if ($scoped && (int)$row['assigned_to'] !== (int)$user['id']) { flash('That follow-up is not on your caseload.', 'error'); redirect('followups.php'); }

    if (isset($_POST['complete'])) {
        $inUse = ($_POST['in_use'] ?? '') === 'yes' ? 1 : (($_POST['in_use'] ?? '') === 'no' ? 0 : null);
        $sat = (int)($_POST['satisfaction'] ?? 0);
        $sat = ($sat >= 1 && $sat <= 5) ? $sat : null;
        $barriers = mb_substr(trim((string)($_POST['barriers'] ?? '')), 0, 255) ?: null;
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 4000) ?: null;
        // Atomic claim: only a still-Pending follow-up is completed, so a
        // double-submit can't duplicate the abandonment note or overwrite a
        // prior result. The claim and the "no longer in use" case note share one
        // transaction, so a failure can't leave it Completed without its note.
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare("UPDATE equipment_followups SET status = 'Completed', in_use = ?, satisfaction = ?, barriers = ?, notes = ?, completed_at = NOW(), completed_by = ? WHERE id = ? AND status = 'Pending'");
            $claim->execute([$inUse, $sat, $barriers, $notes, $user['id'], $id]);
            if ($claim->rowCount() !== 1) { $pdo->rollBack(); flash('That follow-up was already recorded.', 'error'); redirect('followups.php'); }
            // Flag abandonment for action: a case note + task so it isn't lost.
            if ($inUse === 0) {
                $pdo->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?, 'Case Note', ?, ?, ?)")
                    ->execute([(int)$row['client_id'], mb_substr('Device no longer in use: ' . $row['equipment_name'], 0, 255),
                        'A ' . (int)$row['interval_days'] . '-day device follow-up found "' . $row['equipment_name'] . '" is no longer in use.'
                        . ($barriers ? ' Barriers: ' . $barriers . '.' : '')
                        . ($notes ? ' Notes: ' . $notes . '.' : ''), $user['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // "Unknown" (NULL) is its own outcome — never reported as "in use".
        $useLabel = $inUse === 0 ? 'device not in use' : ($inUse === 1 ? 'device in use' : 'use unknown');
        audit($user['id'], 'followup.complete', 'client', (int)$row['client_id'], $useLabel . ' — ' . $row['equipment_name']);
        if ($inUse === 0) {
            // The task is created after the commit; say so plainly if it could not be.
            $taskMade = add_task('Follow up on abandoned device: ' . $row['equipment_name'] . ' (' . $row['client_name'] . ')',
                'A device follow-up found this item is no longer in use. Re-assess fit, training, or reassignment.',
                (int)$row['client_id'], null, date('Y-m-d', strtotime('+3 days')), (int)$row['assigned_to'] ?: (int)$user['id'], (int)$user['id'], 'abandon-' . $id);
            if ($taskMade) flash('Recorded. Because the device is no longer in use, a case note and a follow-up task were created.');
            else flash('Recorded, and a case note was added because the device is no longer in use, but the follow-up task could not be created. Add a task for this client from the Tasks page.', 'error');
        } else {
            flash('Follow-up recorded.');
        }
        redirect('followups.php');
    }
    // Skip and snooze change only a still-Pending follow-up; when someone else has
    // already recorded it, nothing changed, so nothing is audited or reported as done.
    if (isset($_POST['skip'])) {
        $skip = db()->prepare("UPDATE equipment_followups SET status = 'Skipped', completed_at = NOW(), completed_by = ? WHERE id = ? AND status = 'Pending'");
        $skip->execute([$user['id'], $id]);
        if ($skip->rowCount() !== 1) { flash('That follow-up was already recorded.', 'error'); redirect('followups.php'); }
        audit($user['id'], 'followup.skip', 'client', (int)$row['client_id']);
        flash('Follow-up skipped.');
        redirect('followups.php');
    }
    if (isset($_POST['snooze'])) {
        $snooze = db()->prepare("UPDATE equipment_followups SET due_date = DATE_ADD(GREATEST(due_date, CURDATE()), INTERVAL 14 DAY) WHERE id = ? AND status = 'Pending'");
        $snooze->execute([$id]);
        if ($snooze->rowCount() !== 1) { flash('That follow-up was already recorded.', 'error'); redirect('followups.php'); }
        audit($user['id'], 'followup.snooze', 'client', (int)$row['client_id'], '14 days');
        flash('Follow-up moved out two weeks.');
        redirect('followups.php');
    }
}

// Retention / abandonment stat over completed follow-ups (caseload-scoped).
$statSql = "SELECT COUNT(*) AS done, SUM(f.in_use = 1) AS in_use, SUM(f.in_use = 0) AS not_use
    FROM equipment_followups f JOIN clients c ON c.id = f.client_id
    WHERE f.status = 'Completed' AND f.in_use IS NOT NULL" . ($scoped ? ' AND c.assigned_to = ?' : '');
$ss = db()->prepare($statSql); $ss->execute($scoped ? [(int)$user['id']] : []);
$stat = $ss->fetch() ?: ['done' => 0, 'in_use' => 0, 'not_use' => 0];
$retention = ((int)$stat['done'] > 0) ? round(100 * (int)$stat['in_use'] / (int)$stat['done']) : null;

// Queues: due now (pending, due today or earlier), then upcoming, then recent.
$base = "SELECT f.*, c.name AS client_name, c.id AS cid, e.name AS equipment_name, e.serial AS asset_tag
    FROM equipment_followups f JOIN clients c ON c.id = f.client_id JOIN equipment e ON e.id = f.equipment_id";
$scopeSql = $scoped ? ' AND c.assigned_to = ?' : '';
$args = $scoped ? [(int)$user['id']] : [];

$dueQ = db()->prepare("$base WHERE f.status = 'Pending' AND f.due_date <= CURDATE()$scopeSql ORDER BY f.due_date LIMIT 300");
$dueQ->execute($args); $due = $dueQ->fetchAll();
$upQ = db()->prepare("$base WHERE f.status = 'Pending' AND f.due_date > CURDATE()$scopeSql ORDER BY f.due_date LIMIT 100");
$upQ->execute($args); $upcoming = $upQ->fetchAll();
// The tiles count every pending check, not just the rows each list shows; a list
// that reaches its limit says so.
$cntQ = db()->prepare("SELECT COALESCE(SUM(f.due_date <= CURDATE()),0) AS due_n, COALESCE(SUM(f.due_date > CURDATE()),0) AS up_n
    FROM equipment_followups f JOIN clients c ON c.id = f.client_id JOIN equipment e ON e.id = f.equipment_id
    WHERE f.status = 'Pending'$scopeSql");
$cntQ->execute($args); $cnt = $cntQ->fetch() ?: ['due_n' => 0, 'up_n' => 0];
$dueTotal = max((int)$cnt['due_n'], count($due));
$upTotal = max((int)$cnt['up_n'], count($upcoming));
$recentQ = db()->prepare("$base WHERE f.status IN ('Completed','Skipped')$scopeSql ORDER BY f.completed_at DESC LIMIT 25");
$recentQ->execute($args); $recent = $recentQ->fetchAll();

page_header('Device follow-ups', $user, 'followups');

/** The interval label + overdue styling for a pending follow-up. */
function fu_when(array $r): string {
    $d = (int)$r['interval_days'];
    // Whole calendar days via DateTime::diff: dividing seconds by 86400 loses a
    // day when a daylight-saving change falls between today and the due date.
    $diff = (new DateTime('today'))->diff(new DateTime((string)$r['due_date']));
    $days = $diff->invert ? -(int)$diff->days : (int)$diff->days;
    $cls = $days < 0 ? 'field-error' : 'muted';
    $when = $days < 0 ? 'overdue ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') : ($days === 0 ? 'due today' : 'due ' . fmt_date($r['due_date']));
    return $d . '-day check · <span class="' . $cls . '">' . $when . '</span>';
}
?>
<h1>Device follow-ups</h1>
<p class="muted">Post-provision checks that loaned assistive technology is still being used<?= $scoped ? ' (your caseload)' : '' ?>. Checks are scheduled automatically at <?= implode(', ', array_map(fn($d) => $d . ' days', cms_days('device_followup_days'))) ?> after a device goes out.</p>

<div class="stat-row">
  <div class="card stat"><span class="stat-num"><?= $dueTotal ?></span><span class="stat-label">Due now</span></div>
  <div class="card stat"><span class="stat-num"><?= $upTotal ?></span><span class="stat-label">Upcoming</span></div>
  <div class="card stat"><span class="stat-num"><?= $retention === null ? '—' : $retention . '%' ?></span><span class="stat-label">Still in use<?= (int)$stat['done'] ? ' (of ' . (int)$stat['done'] . ' check' . ((int)$stat['done'] === 1 ? '' : 's') . ' answered yes or no)' : '' ?></span></div>
  <div class="card stat"><span class="stat-num"><?= (int)$stat['not_use'] ?></span><span class="stat-label">Flagged abandoned</span></div>
</div>

<section class="card" aria-labelledby="due-h">
  <h2 id="due-h">Due now</h2>
  <?php if (!$due): ?><p class="muted">Nothing due. Nice — every provisioned device has been followed up.</p><?php endif; ?>
  <?php if ($dueTotal > count($due)): ?><p>Showing the first <?= count($due) ?> of <?= $dueTotal ?> checks due, oldest first.</p><?php endif; ?>
  <?php foreach ($due as $r):
    // Every item repeats the same controls, so each one carries the client and
    // device it acts on (and the item is a heading, for heading navigation).
    $fuName = $r['client_name'] . ', ' . $r['equipment_name']; ?>
    <form method="post" class="fu-item">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <h3 class="fu-head">
        <a href="client.php?id=<?= (int)$r['cid'] ?>"><?= h($r['client_name']) ?></a> —
        <?= h($r['equipment_name']) ?><?= $r['asset_tag'] ? ' <span class="muted">(' . h($r['asset_tag']) . ')</span>' : '' ?>
      </h3>
      <p class="fu-when muted"><?= fu_when($r) ?></p>
      <fieldset class="fu-fields">
        <legend class="visually-hidden">Record the <?= (int)$r['interval_days'] ?>-day follow-up for <?= h($fuName) ?></legend>
        <fieldset class="fu-use"><legend>Still in use? <span class="visually-hidden">(required)</span></legend>
          <label><input type="radio" name="in_use" value="yes" required> Yes</label>
          <label><input type="radio" name="in_use" value="no"> No</label>
          <label><input type="radio" name="in_use" value="unknown"> Unknown</label>
        </fieldset>
        <label class="fu-sat">Satisfaction
          <select name="satisfaction">
            <option value="">—</option>
            <?php for ($n = 5; $n >= 1; $n--): ?><option value="<?= $n ?>"><?= $n ?> / 5</option><?php endfor; ?>
          </select>
        </label>
        <label class="fu-bar">Barriers <input type="text" name="barriers" maxlength="255" placeholder="e.g. needs more training, discomfort, broke"></label>
        <label class="fu-notes">Notes <input type="text" name="notes" maxlength="4000" placeholder="Anything to record"></label>
      </fieldset>
      <div class="fu-actions">
        <?php // A recorded follow-up cannot be changed, and "No" also adds a case note and a
        // task, so that answer is confirmed first (Enter in a text box presses this button too). ?>
        <button type="submit" name="complete" value="1" class="btn btn-primary btn-sm" onclick="var n=this.form.querySelector('input[name=in_use][value=no]');return !(n&amp;&amp;n.checked)||confirm(<?= h((string)json_encode('Record that ' . $r['equipment_name'] . ' is no longer in use by ' . $r['client_name'] . '? This adds a case note and a follow-up task, and a recorded follow-up cannot be changed.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>);">Save follow-up<span class="visually-hidden"> for <?= h($fuName) ?></span></button>
        <button type="submit" name="snooze" value="1" formnovalidate class="btn btn-secondary btn-sm">Snooze 2 weeks<span class="visually-hidden"> — <?= h($fuName) ?></span></button>
        <button type="submit" name="skip" value="1" formnovalidate class="btn-link" onclick="return confirm(<?= h((string)json_encode('Skip the ' . (int)$r['interval_days'] . '-day follow-up for ' . $fuName . '?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>);">skip<span class="visually-hidden"> the follow-up for <?= h($fuName) ?></span></button>
      </div>
    </form>
  <?php endforeach; ?>
</section>

<?php if ($upcoming): ?>
<section class="card" aria-labelledby="up-h">
  <h2 id="up-h">Upcoming</h2>
  <?php if ($upTotal > count($upcoming)): ?><p>Showing the first <?= count($upcoming) ?> of <?= $upTotal ?> upcoming checks, soonest first.</p><?php endif; ?>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Upcoming follow-ups">
  <table>
    <caption class="visually-hidden">Upcoming device follow-ups</caption>
    <thead><tr><th scope="col">Client</th><th scope="col">Device</th><th scope="col">Check</th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $r): ?>
      <tr><td><a href="client.php?id=<?= (int)$r['cid'] ?>"><?= h($r['client_name']) ?></a></td>
        <td><?= h($r['equipment_name']) ?></td>
        <td><?= (int)$r['interval_days'] ?>-day · <?= fmt_date($r['due_date']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if ($recent): ?>
<section class="card" aria-labelledby="rec-h">
  <h2 id="rec-h">Recently recorded</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Recent follow-ups">
  <table>
    <caption class="visually-hidden">Recently recorded device follow-ups</caption>
    <thead><tr><th scope="col">Client</th><th scope="col">Device</th><th scope="col">Check</th><th scope="col">Outcome</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><a href="client.php?id=<?= (int)$r['cid'] ?>"><?= h($r['client_name']) ?></a></td>
        <td><?= h($r['equipment_name']) ?></td>
        <td><?= (int)$r['interval_days'] ?>-day</td>
        <td><?php
          if ($r['status'] === 'Skipped') echo '<span class="muted">' . ($r['notes'] === 'Device returned' ? 'skipped — device returned' : 'skipped') . '</span>';
          elseif ($r['in_use'] === null) echo '<span class="muted">Use unknown</span>' . ($r['satisfaction'] ? ' · ' . (int)$r['satisfaction'] . '/5' : '');
          elseif ((int)$r['in_use'] === 1) echo 'In use' . ($r['satisfaction'] ? ' · ' . (int)$r['satisfaction'] . '/5' : '');
          else echo '<span class="field-error">Not in use</span>' . ($r['barriers'] ? ' · ' . h($r['barriers']) : '');
          // Answered by the client in the portal (3.0.42).
          if (!empty($r['client_reported'])) echo ' · reported by the client';
        ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<style>
.fu-item{border:1px solid var(--line);border-radius:var(--radius);padding:.6rem .7rem;margin:.5rem 0}
.fu-head{font-size:1rem;margin:0 0 .15rem}
.fu-when{margin:0 0 .4rem;font-size:.9rem}
.fu-fields{border:0;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:flex-end}
.fu-fields label{display:flex;flex-direction:column;font-size:.85rem;gap:.15rem}
.fu-use{font-size:.85rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;border:0;margin:0;padding:0}.fu-use legend{float:left;padding:0;margin-right:.5rem}
.fu-use label{flex-direction:row;align-items:center;gap:.2rem}
.fu-bar input,.fu-notes input{min-width:14rem}
.fu-actions{margin-top:.5rem;display:flex;gap:.6rem;align-items:center}
.stat-row{display:flex;flex-wrap:wrap;gap:.6rem;margin:.6rem 0}
</style>
<?php
page_footer();
