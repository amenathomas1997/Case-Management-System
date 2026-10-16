<?php
// Privacy incidents (3.0.38): a misdirected report, a lost phone, access by the wrong
// person, a wrong portal link. Any staff member can report one; administrators assess it
// (the four HIPAA risk factors), list the people affected, record each notice sent and
// follow-up, and close it. The notice deadline is 60 days from discovery, and the daily job
// opens a task two weeks before it. Separate from the safety log (incidents.php).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');
$isAdmin = can('admin');

$ready = true;
try { db()->query('SELECT 1 FROM privacy_incidents LIMIT 1'); } catch (PDOException $e) { $ready = false; }
$in = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
$id = (int)($_GET['id'] ?? $_POST['incident_id'] ?? 0);
$back = fn(string $anchor = '') => redirect('privacy_incidents.php' . ($id ? '?id=' . $id : '') . $anchor);

/* ---- Report one (any staff member) ---- */
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_incident'])) {
    $errs = [];
    $kind = $in('kind');
    if (!in_array($kind, PRIVACY_INCIDENT_KINDS, true)) $errs['kind'] = 'Choose what kind of incident it is.';
    $disc = valid_date($in('discovered_on'), 2000, (int)date('Y'));
    if ($disc === null || $disc > date('Y-m-d')) $errs['discovered_on'] = 'Enter the date it was discovered (today or earlier).';
    $occ = $in('occurred_on') === '' ? null : valid_date($in('occurred_on'), 2000, (int)date('Y'));
    if ($in('occurred_on') !== '' && ($occ === null || $occ > date('Y-m-d'))) $errs['occurred_on'] = 'Enter the date it happened (today or earlier), or leave it blank.';
    $desc = $in('description');
    if ($desc === '') $errs['description'] = 'Describe what happened.';
    elseif (mb_strlen($desc) > 5000) $errs['description'] = 'What happened is too long (5,000 characters maximum).';
    $info = $in('info_involved');
    if (mb_strlen($info) > 500) $errs['info_involved'] = 'Keep the information involved to 500 characters or fewer.';
    $cid = (int)($_POST['client_id'] ?? 0);
    $client = null;
    if ($cid) {
        $c = db()->prepare('SELECT id, name, assigned_to FROM clients WHERE id = ?');
        $c->execute([$cid]);
        $client = $c->fetch() ?: null;
        if (!$client || (caseload_scoped() && (int)$client['assigned_to'] !== (int)$user['id'])) $errs['client_id'] = 'Choose a client on your caseload, or None.';
    }
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('privacy_incidents.php#report-h'); }
    $key = web_idem_key($_POST);
    if (($first = web_once_get($key)) !== null) redirect('privacy_incidents.php?id=' . $first);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO privacy_incidents (kind, occurred_on, discovered_on, notice_deadline, description, info_involved, reported_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$kind, $occ, $disc, date('Y-m-d', strtotime($disc . ' +' . PRIVACY_NOTICE_DAYS . ' days')), $desc, $info !== '' ? $info : null, $user['id']]);
        $newId = (int)$pdo->lastInsertId();
        if ($client) $pdo->prepare('INSERT INTO privacy_incident_clients (incident_id, client_id, client_label) VALUES (?,?,?)')->execute([$newId, $client['id'], $client['name']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    web_once_set($key, $newId);
    audit($user['id'], 'privacy_incident.add', 'privacy_incident', $newId, $kind);
    notify_admin_users('Privacy incident reported: ' . $kind, 'privacy_incidents.php?id=' . $newId);
    flash('Privacy incident reported. An administrator has been told. The notice deadline is ' . fmt_date(date('Y-m-d', strtotime($disc . ' +' . PRIVACY_NOTICE_DAYS . ' days'))) . '.');
    redirect($isAdmin ? 'privacy_incidents.php?id=' . $newId : 'privacy_incidents.php');
}

/* ---- Manage one (administrators) ---- */
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0 && !isset($_POST['report_incident'])) {
    if (!$isAdmin) { flash('Only an administrator can change a privacy incident.', 'error'); redirect('privacy_incidents.php'); }
    $inc = privacy_incident($id);
    if (!$inc) { flash('That privacy incident was not found.', 'error'); redirect('privacy_incidents.php'); }
    if (isset($_POST['save_risk'])) {
        $level = $in('risk_level');
        $errs = [];
        if (!in_array($level, PRIVACY_RISK_LEVELS, true)) $errs['risk_level'] = 'Choose the outcome of the assessment.';
        foreach (['risk_nature', 'risk_recipient', 'risk_acquired', 'risk_mitigation'] as $k) if (mb_strlen($in($k)) > 5000) $errs[$k] = 'Keep this to 5,000 characters or fewer.';
        if ($errs) { keep_input(); keep_field_errors($errs); $back('#risk-h'); }
        db()->prepare('UPDATE privacy_incidents SET risk_nature = ?, risk_recipient = ?, risk_acquired = ?, risk_mitigation = ?, risk_level = ? WHERE id = ?')
            ->execute([$in('risk_nature') ?: null, $in('risk_recipient') ?: null, $in('risk_acquired') ?: null, $in('risk_mitigation') ?: null, $level, $id]);
        audit($user['id'], 'privacy_incident.assess', 'privacy_incident', $id, $level);
        flash('Risk assessment saved: ' . mb_strtolower($level) . '.');
        $back('#risk-h');
    }
    if (isset($_POST['add_person'])) {
        $c = db()->prepare('SELECT id, name FROM clients WHERE id = ?');
        $c->execute([(int)($_POST['client_id'] ?? 0)]);
        $cl = $c->fetch();
        if (!$cl) { flash('Choose a client.', 'error'); $back('#people-h'); }
        db()->prepare('INSERT IGNORE INTO privacy_incident_clients (incident_id, client_id, client_label) VALUES (?,?,?)')->execute([$id, $cl['id'], $cl['name']]);
        audit($user['id'], 'privacy_incident.person', 'privacy_incident', $id, 'client #' . (int)$cl['id']);
        flash($cl['name'] . ' is listed as affected.');
        $back('#people-h');
    }
    if (isset($_POST['add_event'])) {
        $type = ($_POST['event_type'] ?? '') === 'Notice' ? 'Notice' : 'Follow-up';
        $errs = [];
        $on = valid_date($in('happened_on'), 2000, (int)date('Y'));
        if ($on === null || $on > date('Y-m-d')) $errs['happened_on'] = 'Enter the date (today or earlier).';
        $detail = $in('detail');
        if ($detail === '') $errs['detail'] = $type === 'Notice' ? 'Say what the notice said, or attach its wording.' : 'Describe the follow-up.';
        elseif (mb_strlen($detail) > 5000) $errs['detail'] = 'Keep this to 5,000 characters or fewer.';
        $recipient = $in('recipient');
        if ($type === 'Notice' && !in_array($recipient, PRIVACY_NOTICE_RECIPIENTS, true)) $errs['recipient'] = 'Choose who was notified.';
        $method = $in('method');
        if (mb_strlen($method) > 60) $errs['method'] = 'Keep how it was sent to 60 characters or fewer.';
        if ($errs) { keep_input(); keep_field_errors($errs); $back('#log-h'); }
        db()->prepare('INSERT INTO privacy_incident_events (incident_id, event_type, recipient, method, happened_on, detail, created_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $type, $type === 'Notice' ? $recipient : null, $method !== '' ? $method : null, $on, $detail, $user['id']]);
        // A notice to a person marks them notified (the client chosen with it).
        $who = (int)($_POST['notified_client'] ?? 0);
        if ($type === 'Notice' && $who > 0) db()->prepare('UPDATE privacy_incident_clients SET notified_at = ? WHERE incident_id = ? AND client_id = ? AND notified_at IS NULL')->execute([$on, $id, $who]);
        audit($user['id'], 'privacy_incident.' . ($type === 'Notice' ? 'notice' : 'followup'), 'privacy_incident', $id, $type === 'Notice' ? $recipient : 'follow-up');
        flash($type === 'Notice' ? 'Notice recorded.' : 'Follow-up recorded.');
        $back('#log-h');
    }
    if (isset($_POST['close_incident']) || isset($_POST['reopen_incident'])) {
        $close = isset($_POST['close_incident']);
        if ($close && $inc['risk_level'] === 'Under assessment') { flash('Record the risk assessment before closing the incident.', 'error'); $back('#risk-h'); }
        db()->prepare('UPDATE privacy_incidents SET status = ?, closed_at = ' . ($close ? 'NOW()' : 'NULL') . ', closed_by = ? WHERE id = ?')
            ->execute([$close ? 'Closed' : 'Open', $close ? $user['id'] : null, $id]);
        if ($close) complete_task_by_key('privinc-' . $id, (int)$user['id']);
        audit($user['id'], 'privacy_incident.' . ($close ? 'close' : 'reopen'), 'privacy_incident', $id, null);
        flash($close ? 'Privacy incident closed.' : 'Privacy incident reopened.');
        $back();
    }
    $back();
}

$inc = ($ready && $id > 0) ? privacy_incident($id) : null;
if ($inc && !$isAdmin && (int)$inc['reported_by'] !== (int)$user['id']) $inc = null;

if ($inc) {
    $people = privacy_incident_people($id);
    $events = privacy_incident_events($id);
    $left = privacy_days_left($inc);
    $allClients = db()->query('SELECT id, name FROM clients ORDER BY name, id')->fetchAll();
    $edit = fn(string $k): string => h(isset($GLOBALS['OLD_INPUT']['risk_level']) ? old_in($k) : (string)($inc[$k] ?? ''));
    page_header('Privacy incident: ' . $inc['kind'], $user, 'admin', [['privacy_incidents.php', 'Privacy incidents']]);
    ?>
<h1>Privacy incident: <?= h($inc['kind']) ?></h1>
<p><?= status_badge($inc['status']) ?> Reported by <?= h($inc['reporter'] ?? '—') ?> on <?= fmt_date($inc['created_at']) ?>. Discovered <?= fmt_date($inc['discovered_on']) ?><?= $inc['occurred_on'] ? ', happened ' . fmt_date($inc['occurred_on']) : '' ?>.</p>
<p><strong>Notice deadline: <?= fmt_date($inc['notice_deadline']) ?></strong><?= $inc['status'] === 'Open' ? ($left >= 0 ? ' (' . $left . ' day' . ($left === 1 ? '' : 's') . ' left)' : ' (' . -$left . ' day' . ($left === -1 ? '' : 's') . ' past)') : '' ?>. Risk: <?= h($inc['risk_level']) ?>.</p>
<section class="card" aria-labelledby="what-h">
  <h2 id="what-h">What happened</h2>
  <p style="white-space:pre-wrap"><?= h($inc['description']) ?></p>
  <?php if ($inc['info_involved']): ?><p>Information involved: <?= h($inc['info_involved']) ?></p><?php endif; ?>
</section>
<section class="card" aria-labelledby="people-h">
  <h2 id="people-h">People affected (<?= count($people) ?>)</h2>
  <?php if (!$people): ?><p class="muted">No one listed yet.</p><?php else: ?>
  <ul>
  <?php foreach ($people as $p): ?>
    <li><?= h($p['client_label']) ?><?= $p['client_id'] === null ? ' (record since removed)' : '' ?>: <?= $p['notified_at'] ? 'notified ' . fmt_date($p['notified_at']) : '<strong>not notified</strong>' ?>
      <?php if ($p['client_id'] !== null && $isAdmin): ?> <a href="audit.php?client=<?= (int)$p['client_id'] ?>">Access history<span class="visually-hidden"> for <?= h($p['client_label']) ?></span></a><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
  <form method="post" action="privacy_incidents.php?id=<?= $id ?>" class="inline-form"><?= csrf_field() ?>
    <label for="add_client">Add an affected client</label>
    <select id="add_client" name="client_id"><option value="">Choose a client</option><?php foreach ($allClients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select>
    <button type="submit" name="add_person" value="1" class="btn btn-secondary btn-sm">Add</button>
  </form>
  <?php endif; ?>
</section>
<?php if ($isAdmin): ?>
<section class="card" aria-labelledby="risk-h">
  <h2 id="risk-h">Risk assessment</h2>
  <p class="hint">The four factors: what information and how identifying, who received or used it, whether it was actually viewed or acquired, and how far the risk was reduced.</p>
  <form method="post" action="privacy_incidents.php?id=<?= $id ?>" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['risk_level' => 'Outcome', 'risk_nature' => 'Information', 'risk_recipient' => 'Recipient', 'risk_acquired' => 'Viewed or acquired', 'risk_mitigation' => 'Mitigation']) ?>
    <div class="field"><label for="risk_nature">What information, and how identifying</label><textarea id="risk_nature" name="risk_nature" rows="2" <?= field_aria('risk_nature') ?>><?= $edit('risk_nature') ?></textarea><?= field_error('risk_nature') ?></div>
    <div class="field"><label for="risk_recipient">Who received or used it</label><textarea id="risk_recipient" name="risk_recipient" rows="2" <?= field_aria('risk_recipient') ?>><?= $edit('risk_recipient') ?></textarea><?= field_error('risk_recipient') ?></div>
    <div class="field"><label for="risk_acquired">Was it actually viewed or acquired</label><textarea id="risk_acquired" name="risk_acquired" rows="2" <?= field_aria('risk_acquired') ?>><?= $edit('risk_acquired') ?></textarea><?= field_error('risk_acquired') ?></div>
    <div class="field"><label for="risk_mitigation">How the risk was reduced</label><textarea id="risk_mitigation" name="risk_mitigation" rows="2" <?= field_aria('risk_mitigation') ?>><?= $edit('risk_mitigation') ?></textarea><?= field_error('risk_mitigation') ?></div>
    <div class="field"><label for="risk_level">Outcome</label><select id="risk_level" name="risk_level" <?= field_aria('risk_level') ?>><?= select_options(PRIVACY_RISK_LEVELS, isset($GLOBALS['OLD_INPUT']['risk_level']) ? old_in('risk_level') : $inc['risk_level']) ?></select><?= field_error('risk_level') ?></div>
    <button type="submit" name="save_risk" value="1" class="btn btn-primary">Save assessment</button>
  </form>
</section>
<?php endif; ?>
<section class="card" aria-labelledby="log-h">
  <h2 id="log-h">Notices and follow-up (<?= count($events) ?>)</h2>
  <?php if (!$events): ?><p class="muted">Nothing recorded yet.</p><?php else: ?>
  <ul>
  <?php foreach ($events as $e): ?>
    <li><strong><?= fmt_date($e['happened_on']) ?>, <?= $e['event_type'] === 'Notice' ? 'notice to ' . h(mb_strtolower((string)$e['recipient'])) : 'follow-up' ?></strong><?= $e['method'] ? ' (' . h($e['method']) . ')' : '' ?><?= $e['by_name'] ? ', recorded by ' . h($e['by_name']) : '' ?>: <span style="white-space:pre-wrap"><?= h($e['detail']) ?></span></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
  <details<?= array_intersect_key(form_errors(), array_flip(['happened_on', 'detail', 'recipient', 'method'])) ? ' open' : '' ?>><summary>Record a notice or follow-up</summary>
  <form method="post" action="privacy_incidents.php?id=<?= $id ?>" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['happened_on' => 'Date', 'detail' => 'Details', 'recipient' => 'Notified', 'method' => 'How']) ?>
    <div class="field"><label for="event_type">Entry</label><select id="event_type" name="event_type"><?= select_options(['Notice', 'Follow-up'], old_in('event_type', 'Notice')) ?></select></div>
    <div class="field"><label for="happened_on">Date</label><input type="date" id="happened_on" name="happened_on" value="<?= h(old_in('happened_on', date('Y-m-d'))) ?>" <?= field_aria('happened_on') ?>><?= field_error('happened_on') ?></div>
    <div class="field"><label for="recipient">Notified (for a notice)</label><select id="recipient" name="recipient" <?= field_aria('recipient') ?>><option value="">Choose who</option><?= select_options(PRIVACY_NOTICE_RECIPIENTS, old_in('recipient') ?: null) ?></select><?= field_error('recipient') ?></div>
    <?php if ($people): ?>
    <div class="field"><label for="notified_client">Affected person notified</label><select id="notified_client" name="notified_client"><option value="0">None</option><?php foreach ($people as $p): if ($p['client_id'] === null) continue; ?><option value="<?= (int)$p['client_id'] ?>"><?= h($p['client_label']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="field"><label for="method">How it was sent</label><input type="text" id="method" name="method" maxlength="60" value="<?= h(old_in('method')) ?>" <?= field_aria('method') ?>><?= field_error('method') ?></div>
    <div class="field"><label for="detail">Details</label><textarea id="detail" name="detail" rows="3" <?= field_aria('detail') ?>><?= h(old_in('detail')) ?></textarea><?= field_error('detail') ?></div>
    <button type="submit" name="add_event" value="1" class="btn btn-primary">Record</button>
  </form>
  </details>
  <form method="post" action="privacy_incidents.php?id=<?= $id ?>" class="inline-form mt-1"><?= csrf_field() ?>
    <?php if ($inc['status'] === 'Open'): ?><button type="submit" name="close_incident" value="1" class="btn btn-secondary">Close incident</button>
    <?php else: ?><button type="submit" name="reopen_incident" value="1" class="btn btn-secondary">Reopen incident</button><?php endif; ?>
  </form>
  <?php endif; ?>
</section>
<?php
    page_footer();
    exit;
}

$showClosed = !empty($_GET['all']);
$rows = [];
if ($ready) {
    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM privacy_incident_clients a WHERE a.incident_id = p.id) AS affected,
            (SELECT COUNT(*) FROM privacy_incident_clients a WHERE a.incident_id = p.id AND a.notified_at IS NULL) AS unnotified
        FROM privacy_incidents p WHERE ' . ($showClosed ? '1=1' : "p.status = 'Open'") . ($isAdmin ? '' : ' AND p.reported_by = ?') . ' ORDER BY p.notice_deadline, p.id';
    $q = db()->prepare($sql);
    $q->execute($isAdmin ? [] : [(int)$user['id']]);
    $rows = $q->fetchAll();
}
$clients = [];
if ($ready) {
    $cq = db()->prepare('SELECT id, name FROM clients WHERE archived_at IS NULL' . (caseload_scoped() ? ' AND assigned_to = ?' : '') . ' ORDER BY name');
    $cq->execute(caseload_scoped() ? [(int)$user['id']] : []);
    $clients = $cq->fetchAll();
}
page_header('Privacy incidents', $user, 'admin');
?>
<h1>Privacy incidents</h1>
<p>Report a possible privacy breach here: a report or email sent to the wrong person, a lost phone or laptop, someone seeing a record they should not, or a wrong portal link. <?= $isAdmin ? 'You see every incident.' : 'An administrator assesses it; you see the ones you reported.' ?> Safety events go in <a href="incidents.php">Incident reports</a>.</p>
<?php if (!$ready): ?><p class="alert alert-info">This page needs migration 3.0.38. Run it, then reload.</p><?php else: ?>
<div class="page-actions"><a class="btn btn-secondary btn-sm" href="privacy_incidents.php<?= $showClosed ? '' : '?all=1' ?>"><?= $showClosed ? 'Hide closed' : 'Show closed' ?></a></div>
<h2 id="list-h"><?= $showClosed ? 'All' : 'Open' ?> privacy incidents (<?= count($rows) ?>)</h2>
<?php if (!$rows): ?><p class="muted">None.</p><?php else: ?>
<ul>
<?php foreach ($rows as $r): $l = privacy_days_left($r); ?>
  <li><a href="privacy_incidents.php?id=<?= (int)$r['id'] ?>"><?= h($r['kind']) ?>, discovered <?= fmt_date($r['discovered_on']) ?></a>:
    <?= h($r['status']) ?>, <?= h(mb_strtolower($r['risk_level'])) ?>, <?= (int)$r['affected'] ?> affected<?= (int)$r['unnotified'] > 0 ? ' (' . (int)$r['unnotified'] . ' not notified)' : '' ?>,
    deadline <?= fmt_date($r['notice_deadline']) ?><?= $r['status'] === 'Open' ? ($l >= 0 ? ' (' . $l . ' days left)' : ' (<strong>' . -$l . ' days past</strong>)') : '' ?>.</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<h2 class="visually-hidden">Report a privacy incident</h2>
<details class="card mt-1"<?= array_intersect_key(form_errors(), array_flip(['kind', 'discovered_on', 'occurred_on', 'description', 'info_involved', 'client_id'])) ? ' open' : '' ?>>
  <summary accesskey="n" id="report-h">Report a privacy incident</summary>
  <form method="post" action="privacy_incidents.php" class="stacked" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['kind' => 'Kind', 'discovered_on' => 'Discovered', 'occurred_on' => 'Happened', 'description' => 'What happened', 'info_involved' => 'Information involved', 'client_id' => 'Client']) ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="field"><label for="kind">Kind <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><select id="kind" name="kind" <?= field_aria('kind') ?>><option value="">Choose a kind</option><?= select_options(PRIVACY_INCIDENT_KINDS, old_in('kind') ?: null) ?></select><?= field_error('kind') ?></div>
    <div class="field"><label for="discovered_on">Date discovered <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><input type="date" id="discovered_on" name="discovered_on" max="<?= date('Y-m-d') ?>" value="<?= h(old_in('discovered_on', date('Y-m-d'))) ?>" <?= field_aria('discovered_on') ?>><?= field_error('discovered_on') ?></div>
    <div class="field"><label for="occurred_on">Date it happened, if known</label><input type="date" id="occurred_on" name="occurred_on" max="<?= date('Y-m-d') ?>" value="<?= h(old_in('occurred_on')) ?>" <?= field_aria('occurred_on') ?>><?= field_error('occurred_on') ?></div>
    <div class="field"><label for="pi_client">Client affected, if one</label><select id="pi_client" name="client_id" <?= field_aria('client_id') ?>><option value="">None</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= old_in('client_id') === (string)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select><?= field_error('client_id') ?></div>
    <div class="field"><label for="description">What happened <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><textarea id="description" name="description" rows="3" <?= field_aria('description') ?>><?= h(old_in('description')) ?></textarea><?= field_error('description') ?></div>
    <div class="field"><label for="info_involved">Information involved</label><input type="text" id="info_involved" name="info_involved" maxlength="500" value="<?= h(old_in('info_involved')) ?>" <?= field_aria('info_involved') ?>><?= field_error('info_involved') ?></div>
    <button type="submit" name="report_incident" value="1" class="btn btn-primary">Report incident</button>
  </form>
</details>
<?php endif; ?>
<?php page_footer();
