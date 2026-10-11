<?php
// Incident & safety log: record injuries, near misses, equipment failures and
// other safety events, optionally tied to a client, and track them to closure.
// Staff see incidents they reported or on their caseload; admins see all.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

const INCIDENT_TYPES = ['Injury', 'Near miss', 'Equipment failure', 'Behavioral', 'Property damage', 'Medical', 'Safety concern', 'Other'];
const INCIDENT_SEVERITY = ['Low', 'Medium', 'High', 'Critical'];
const INCIDENT_STATUS = ['Open', 'Under review', 'Closed'];

$scoped = caseload_scoped();

/** Clients this user may attach an incident to. */
function incident_clients(array $user): array {
    $scoped = caseload_scoped();
    $sql = 'SELECT id, name FROM clients WHERE archived_at IS NULL' . ($scoped ? ' AND assigned_to = ?' : '') . ' ORDER BY name';
    $q = db()->prepare($sql); $q->execute($scoped ? [(int)$user['id']] : []);
    return $q->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_incident'])) {
    $cid = (int)($_POST['client_id'] ?? 0) ?: null;
    if ($cid) scope_check_client_id($cid, $user);
    // A safety record is never quietly altered: a missing or invalid value is
    // refused with a message on its field, the same rules the app follows.
    $in = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    $incErrs = [];
    $date = valid_date($in('incident_date'), 2000, (int)date('Y'));
    if ($in('incident_date') === '') $incErrs['incident_date'] = 'Enter the date of the incident.';
    elseif ($date === null) $incErrs['incident_date'] = 'Enter the incident date as a valid date (YYYY-MM-DD) from the year 2000 onwards.';
    // An incident has already happened: a future date is a mistyped one.
    elseif ($date > date('Y-m-d')) $incErrs['incident_date'] = 'The incident date cannot be in the future. Check the year.';
    $type = $in('type');
    if (!in_array($type, INCIDENT_TYPES, true)) $incErrs['type'] = 'Choose a type from the list.';
    $sev = $in('severity');
    if (!in_array($sev, INCIDENT_SEVERITY, true)) $incErrs['severity'] = 'Choose a severity from the list.';
    $desc = $in('description');
    $location = $in('location');
    $action = $in('action_taken');
    if ($desc === '') $incErrs['description'] = 'Describe what happened.';
    elseif (mb_strlen($desc) > 5000) $incErrs['description'] = 'What happened is too long (5,000 characters maximum).';
    if (mb_strlen($location) > 190) $incErrs['location'] = 'Location is too long (190 characters maximum).';
    if (mb_strlen($action) > 5000) $incErrs['action_taken'] = 'Action taken is too long (5,000 characters maximum).';
    if ($incErrs) { keep_input(); keep_field_errors($incErrs); redirect('incidents.php'); }
    db()->prepare('INSERT INTO incidents (client_id, reported_by, incident_date, location, type, severity, description, action_taken) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$cid, (int)$user['id'], $date, $location !== '' ? $location : null, $type, $sev, $desc, $action !== '' ? $action : null]);
    $iid = (int)db()->lastInsertId();
    audit($user['id'], 'incident.add', 'incident', $iid, $type . ' / ' . $sev);
    if (in_array($sev, ['High', 'Critical'], true)) notify_admin_users($sev . ' incident reported (' . $type . ')', 'incidents.php');
    flash('Incident logged.');
    redirect('incidents.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $iid = (int)($_POST['id'] ?? 0);
    // An unrecognised status is refused — it must never quietly reopen a closed incident.
    $to = in_array($_POST['to'] ?? '', INCIDENT_STATUS, true) ? $_POST['to'] : null;
    // The view the change was made from (Show closed or not) is kept on return.
    $back = 'incidents.php' . (($_POST['all'] ?? '') === '1' ? '?all=1' : '');
    $row = db()->prepare('SELECT i.reported_by, i.client_id, i.type, i.incident_date, c.name AS client_name FROM incidents i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = ?');
    $row->execute([$iid]); $inc = $row->fetch();
    if ($to === null) {
        flash('Choose a status from the list; the incident was not changed.', 'error');
    } elseif (!$inc) {
        flash('That incident was not found.', 'error');
    } elseif (can('admin') || (int)$inc['reported_by'] === (int)$user['id']) {
        db()->prepare('UPDATE incidents SET status = ? WHERE id = ?')->execute([$to, $iid]);
        audit($user['id'], 'incident.status', 'incident', $iid, $to);
        // Says which incident changed, and where a closed one went.
        flash('Status of the ' . $inc['type'] . ' incident on ' . fmt_date($inc['incident_date'])
            . ($inc['client_name'] ? ' involving ' . $inc['client_name'] : '') . ' changed to ' . $to
            . ($to === 'Closed' && $back === 'incidents.php' ? '. Closed incidents are hidden from this list; choose Show closed to see it.' : '.'));
    } else {
        flash('Only the reporter or an administrator can change that incident.', 'error');
    }
    redirect($back);
}

$showClosed = !empty($_GET['all']);
// Visibility: admins see all; others see what they reported or on their caseload.
$where = '1=1';
$args = [];
if (!can('admin')) {
    $where = '(i.reported_by = ? OR (i.client_id IS NOT NULL AND EXISTS (SELECT 1 FROM clients cc WHERE cc.id = i.client_id AND cc.assigned_to = ?)))';
    $args = [(int)$user['id'], (int)$user['id']];
}
if (!$showClosed) $where .= " AND i.status <> 'Closed'";
$sql = "SELECT i.*, c.name AS client_name, u.name AS reporter FROM incidents i
    LEFT JOIN clients c ON c.id = i.client_id LEFT JOIN users u ON u.id = i.reported_by
    WHERE $where ORDER BY FIELD(i.severity,'Critical','High','Medium','Low'), i.incident_date DESC LIMIT 300";
$st = db()->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();
$openCount = count(array_filter($rows, fn($r) => $r['status'] !== 'Closed'));
$clients = incident_clients($user);

page_header('Incidents', $user, 'incidents');
$sevBadge = fn($s) => '<span class="badge ' . ($s === 'Critical' || $s === 'High' ? 'badge-hold' : ($s === 'Medium' ? 'badge-pending' : 'badge-closed')) . '">' . h($s) . '</span>';
?>
<h1>Incident &amp; safety log</h1>
<p class="muted">Record injuries, near misses, equipment failures and other safety events, and track them to closure. <?= $openCount ?> open.</p>
<div class="page-actions"><a class="btn btn-secondary btn-sm" href="incidents.php<?= $showClosed ? '' : '?all=1' ?>"><?= $showClosed ? 'Hide closed' : 'Show closed' ?></a></div>

<section class="card" aria-labelledby="add-h">
  <h2 id="add-h">Report an incident</h2>
  <p class="muted">Fields marked with an asterisk (*) are required. Check the details before you log the incident: afterwards only its status can be changed.</p>
  <form method="post" action="incidents.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['incident_date' => 'Date', 'type' => 'Type', 'severity' => 'Severity', 'location' => 'Location', 'description' => 'What happened', 'action_taken' => 'Action taken']) ?>
    <div class="grid grid-4">
      <div class="field"><label for="incident_date">Date <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><input type="date" id="incident_date" name="incident_date" required max="<?= date('Y-m-d') ?>" value="<?= h(old_in('incident_date', date('Y-m-d'))) ?>" <?= field_aria('incident_date') ?>><?= field_error('incident_date') ?></div>
      <div class="field"><label for="type">Type <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><select id="type" name="type" required <?= field_aria('type') ?>><option value="">Choose a type</option><?php foreach (INCIDENT_TYPES as $t): ?><option<?= old_in('type') === $t ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select><?= field_error('type') ?></div>
      <div class="field"><label for="severity">Severity <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><select id="severity" name="severity" required <?= field_aria('severity') ?>><option value="">Choose a severity</option><?php foreach (INCIDENT_SEVERITY as $s): ?><option<?= old_in('severity') === $s ? ' selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select><?= field_error('severity') ?></div>
      <div class="field"><label for="client_id">Client <span class="hint">optional</span></label>
        <select id="client_id" name="client_id"><option value="">— None —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= old_in('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="field"><label for="location">Location</label><input type="text" id="location" name="location" maxlength="190" value="<?= h(old_in('location')) ?>" <?= field_aria('location') ?>><?= field_error('location') ?></div>
    <div class="field"><label for="description">What happened? <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><textarea id="description" name="description" rows="3" required <?= field_aria('description') ?>><?= h(old_in('description')) ?></textarea><?= field_error('description') ?></div>
    <div class="field"><label for="action_taken">Action taken</label><textarea id="action_taken" name="action_taken" rows="2" <?= field_aria('action_taken') ?>><?= h(old_in('action_taken')) ?></textarea><?= field_error('action_taken') ?></div>
    <button type="submit" name="add_incident" value="1" class="btn btn-primary">Log incident</button>
  </form>
</section>

<div class="table-scroll" tabindex="0" role="region" aria-label="Incidents">
<table>
  <caption class="visually-hidden">Incident log</caption>
  <thead><tr><th scope="col">Date / reported by</th><th scope="col">Type / severity</th><th scope="col">Client</th><th scope="col">What happened</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No <?= $showClosed ? '' : 'open ' ?>incidents.</td></tr><?php endif; ?>
  <?php
  // The full report is always reachable: long text sits in an expandable
  // section (its opening words as the summary) instead of being cut off.
  $fullText = function (string $text, string $what): string {
      if (mb_strlen($text) <= 160) return '<span style="white-space:pre-wrap">' . h($text) . '</span>';
      return '<details><summary>' . h(mb_strimwidth($text, 0, 90, '…')) . '<span class="visually-hidden"> (expand to read the full ' . h($what) . ')</span></summary>'
          . '<p style="white-space:pre-wrap">' . h($text) . '</p></details>';
  };
  foreach ($rows as $r):
    // Names each row's controls, so a screen reader can tell the incidents apart.
    $incName = $r['type'] . ' incident on ' . fmt_date($r['incident_date']) . ($r['client_name'] ? ' involving ' . $r['client_name'] : ''); ?>
    <tr>
      <th scope="row"><?= fmt_date($r['incident_date']) ?><br><span class="muted">reported by <?= h($r['reporter'] ?? '—') ?></span></th>
      <td><?= h($r['type']) ?><br><?= $sevBadge($r['severity']) ?></td>
      <td><?= $r['client_name'] ? '<a href="client.php?id=' . (int)$r['client_id'] . '">' . h($r['client_name']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <td><?= $fullText((string)$r['description'], 'description') ?>
        <?= $r['location'] ? '<br><span class="muted">Location: ' . h($r['location']) . '</span>' : '' ?>
        <?= $r['action_taken'] ? '<div class="muted">Action taken: ' . $fullText((string)$r['action_taken'], 'action taken') . '</div>' : '' ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td>
        <?php if (can('admin') || (int)$r['reported_by'] === (int)$user['id']): ?>
        <form method="post" action="incidents.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php if ($showClosed): ?><input type="hidden" name="all" value="1"><?php endif; ?>
          <label class="visually-hidden" for="st-<?= (int)$r['id'] ?>">Status for the <?= h($incName) ?></label>
          <select id="st-<?= (int)$r['id'] ?>" name="to"><?php foreach (INCIDENT_STATUS as $s): ?><option<?= $r['status'] === $s ? ' selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> status for the <?= h($incName) ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
