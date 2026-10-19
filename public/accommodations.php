<?php
// Job accommodation & worksite assessments: an on-site assessment of a client's
// job, the barriers found, and the recommended accommodations — tracked from
// scheduling through recommendations, implementation, and follow-up, with a
// printable employer recommendation report. Caseload-scoped case work.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

const WA_STATUSES = ['Scheduled', 'Assessed', 'Recommendations Sent', 'Implemented', 'Follow-up', 'Closed'];
// The narrative fields take what their TEXT columns hold — the same limit the app
// uses — so text entered in the app is never cut when it is saved here.
const WA_TEXT_BYTES = 65535;
const WA_NARRATIVE = ['job_tasks' => 'Essential job tasks', 'barriers' => 'Barriers identified',
    'recommendations' => 'Recommended accommodations', 'outcome' => 'Outcome / follow-up notes'];

/** A submitted field, trimmed; null when blank. Line endings are stored as the app stores them. */
function wa_in(string $k): ?string {
    $v = is_string($_POST[$k] ?? null) ? str_replace("\r\n", "\n", trim($_POST[$k])) : '';
    return $v !== '' ? $v : null;
}

/** A submitted date within the years the app accepts (2000 to five years ahead, as api.php); null otherwise. */
function wa_date(string $k): ?string {
    return valid_date(wa_in($k), 2000, (int)date('Y') + 5);
}

/** Errors for the assessment fields in this post: over-long text and unreadable
 *  dates are refused with a message on the field, never cut or cleared. */
function wa_field_errors(): array {
    $e = [];
    foreach (['employer' => [190, 'Employer'], 'job_title' => [190, 'Job title'], 'worksite_location' => [255, 'Worksite location']] as $k => [$max, $label]) {
        if (mb_strlen((string)wa_in($k)) > $max) $e[$k] = $label . ' is too long (' . $max . ' characters maximum).';
    }
    foreach (WA_NARRATIVE as $k => $label) {
        if (strlen((string)wa_in($k)) > WA_TEXT_BYTES) {
            $e[$k] = $label . ' is too long (65,535 bytes maximum, about 65,000 plain characters). Shorten it and save again.';
        }
    }
    foreach (['assessment_date' => 'assessment date', 'follow_up_date' => 'follow-up date'] as $k => $label) {
        // A partly typed date reaches the server blank, which would clear the stored
        // date; the page script marks it (<field>_bad) so it is refused instead.
        if (!empty($_POST[$k . '_bad'])) $e[$k] = 'The ' . $label . ' was not filled in completely, so nothing was saved. Enter it in full, for example 10/31/2026, or clear it to remove the date.';
        elseif (wa_in($k) !== null && valid_date(wa_in($k)) === null) $e[$k] = 'Enter the ' . $label . ' as a valid date.';
        elseif (wa_in($k) !== null && wa_date($k) === null) $e[$k] = 'Enter the ' . $label . ' with a year from 2000 to ' . ((int)date('Y') + 5) . '.';
    }
    // The follow-up comes after the assessment (both are compared only when both are valid).
    if (!isset($e['assessment_date']) && !isset($e['follow_up_date'])
        && wa_date('assessment_date') !== null && wa_date('follow_up_date') !== null
        && wa_date('follow_up_date') < wa_date('assessment_date')) {
        $e['follow_up_date'] = 'The follow-up date cannot be before the assessment date.';
    }
    return $e;
}

/** The fields the edit form saves, with the names said when someone else changed them (4.28.0). */
const WA_TRACKED = ['employer' => 'Employer', 'job_title' => 'Job title', 'worksite_location' => 'Worksite location', 'assessment_date' => 'Assessment date',
    'job_tasks' => 'Essential job tasks', 'barriers' => 'Barriers identified', 'recommendations' => 'Recommended accommodations',
    'status' => 'Status', 'follow_up_date' => 'Follow-up date', 'outcome' => 'Outcome / follow-up notes'];
/** One saved field as the form holds it (a stored row or a post): trimmed, LF line endings. */
function wa_norm(array $src, string $f): string {
    $v = $src[$f] ?? null;
    return is_scalar($v) ? str_replace("\r\n", "\n", trim((string)$v)) : '';
}
/** The saved fields of a stored row, as the form holds them. */
function wa_snapshot(array $row): array {
    $out = [];
    foreach (array_keys(WA_TRACKED) as $f) $out[$f] = wa_norm($row, $f);
    return $out;
}

function wa_badge(string $s): string {
    $map = ['Scheduled' => 'badge-pending', 'Assessed' => 'badge-active', 'Recommendations Sent' => 'badge-active',
        'Implemented' => 'badge-active', 'Follow-up' => 'badge-pending', 'Closed' => 'badge-closed'];
    return '<span class="badge ' . ($map[$s] ?? 'badge-pending') . '">' . h($s) . '</span>';
}

function wa_load(int $id, array $user): ?array {
    $s = db()->prepare('SELECT wa.*, c.name AS client_name, c.assigned_to, u.name AS assessor
        FROM worksite_assessments wa JOIN clients c ON c.id = wa.client_id LEFT JOIN users u ON u.id = wa.assessor_id WHERE wa.id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return null;
    if (caseload_scoped() && (int)$r['assigned_to'] !== (int)$user['id']) return null;
    return $r;
}

/* ---- Create ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_assessment'])) {
    $cid = (int)($_POST['client_id'] ?? 0);
    if ($cid > 0) scope_check_client_id($cid, $user);
    $errors = ($cid <= 0 ? ['client_id' => 'Choose a client.'] : []) + wa_field_errors();
    // The form is novalidate: keep everything that was typed and flag each field.
    if ($errors) { keep_input(); keep_field_errors($errors); redirect('accommodations.php'); }
    // The same form sent twice (Enter pressed again while it loads) creates the
    // assessment once; the second answer goes to the one the first created.
    $waKey = web_idem_key($_POST);
    if (($firstId = web_once_get($waKey)) !== null) {
        flash('This worksite assessment was already created (the form was sent twice), so it was saved once.', 'info');
        redirect('accommodations.php?id=' . $firstId);
    }
    db()->prepare('INSERT INTO worksite_assessments (client_id, employer, job_title, worksite_location, assessor_id, assessment_date, status) VALUES (?,?,?,?,?,?,?)')
        ->execute([$cid, wa_in('employer'), wa_in('job_title'), wa_in('worksite_location'), $user['id'], wa_date('assessment_date'), 'Scheduled']);
    // Capture the id before audit() inserts its own row and replaces it.
    $id = (int)db()->lastInsertId();
    web_once_set($waKey, $id);
    audit($user['id'], 'worksite.add', 'worksite_assessment', $id);
    flash('Worksite assessment created.');
    redirect('accommodations.php?id=' . $id);
}

/* ---- AI: draft the recommended accommodations from what was typed (3.0.47). Nothing is
   saved: the form comes back with the draft below any recommendations already written. ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_recommend']) && ai_feature_enabled('draft')) {
    $id = (int)($_POST['id'] ?? 0);
    $r = wa_load($id, $user);
    if (!$r) { flash('Assessment not found.', 'error'); redirect('accommodations.php'); }
    $in = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $cr = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $cr->execute([(int)$r['client_id']]);
    $res = worksite_ai_recommendations(['job_title' => $in('job_title'), 'job_tasks' => $in('job_tasks'), 'barriers' => $in('barriers')], $cr->fetch() ?: [], (int)$user['id']);
    keep_input();
    $_SESSION['old_input']['save_assessment'] = '1';
    if ($res['ok']) {
        $mine = $in('recommendations');
        $_SESSION['old_input']['recommendations'] = $mine === '' ? $res['text'] : $mine . "\n" . $res['text'];
        audit($user['id'], 'ai.draft', 'client', (int)$r['client_id'], 'worksite recommendations');
        flash('Recommendations drafted. Read and edit them, then save the assessment.');
    } else {
        flash($res['error'], 'error');
    }
    redirect('accommodations.php?id=' . $id . '#recommendations');
}

/* ---- Update ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assessment'])) {
    $id = (int)($_POST['id'] ?? 0);
    $r = wa_load($id, $user);
    if (!$r) { flash('Assessment not found.', 'error'); redirect('accommodations.php'); }
    $errors = wa_field_errors();
    if ($errors) { keep_input(); keep_field_errors($errors); redirect('accommodations.php?id=' . $id); }
    $status = in_array($_POST['status'] ?? '', WA_STATUSES, true) ? $_POST['status'] : $r['status'];
    // Two editors, two tabs, or the website and the app (4.28.0): the row is locked and the
    // save refused when it changed after this form was opened, so the later save never
    // quietly replaces the other's text. A form opened before this release carries no
    // values and is saved as before.
    $pdo = db();
    $pdo->beginTransaction();
    $lk = $pdo->prepare('SELECT * FROM worksite_assessments WHERE id = ? FOR UPDATE');
    $lk->execute([$id]);
    $locked = $lk->fetch();
    $orig = json_decode(is_string($_POST['orig'] ?? null) ? $_POST['orig'] : '', true);
    $loadedAt = is_string($_POST['loaded_at'] ?? null) ? $_POST['loaded_at'] : null;
    $current = $locked ? wa_snapshot($locked) : [];
    $theirs = [];
    if ($locked && is_array($orig)) {
        foreach (WA_TRACKED as $f => $label) if (array_key_exists($f, $orig) && $current[$f] !== (string)$orig[$f]) $theirs[] = $label;
    }
    if (!$locked || $theirs || ($loadedAt !== null && !is_array($orig) && $loadedAt !== (string)$locked['updated_at'])) {
        $pdo->rollBack();
        if (!$locked) { flash('Assessment not found.', 'error'); redirect('accommodations.php'); }
        // Nothing was saved. The form comes back with this person's own edits and the
        // saved values for every field they left alone, the changed fields named; it
        // carries the current version, so saving again goes through.
        keep_input();
        foreach (array_keys(WA_TRACKED) as $f) {
            if (is_array($orig) && array_key_exists($f, $orig) && wa_norm($_POST, $f) === (string)$orig[$f]) $_SESSION['old_input'][$f] = $current[$f];
        }
        $_SESSION['old_input']['orig'] = (string)json_encode($current);
        $_SESSION['old_input']['loaded_at'] = (string)$locked['updated_at'];
        $who = $theirs ? (count($theirs) === 1 ? $theirs[0] : implode(', ', array_slice($theirs, 0, -1)) . ' and ' . end($theirs)) : '';
        keep_field_errors(['stale' => $who !== ''
            ? 'Someone else changed ' . $who . ' since you opened this assessment. Those fields now show the saved values, and your own changes are kept; review and save again.'
            : 'Someone else changed this assessment since you opened it. Your changes are below; review and save again.']);
        redirect('accommodations.php?id=' . $id);
    }
    $pdo->prepare('UPDATE worksite_assessments SET employer=?, job_title=?, worksite_location=?, assessment_date=?, job_tasks=?, barriers=?, recommendations=?, status=?, follow_up_date=?, outcome=? WHERE id=?')
        ->execute([
            wa_in('employer'),
            wa_in('job_title'),
            wa_in('worksite_location'),
            wa_date('assessment_date'),
            wa_in('job_tasks'),
            wa_in('barriers'),
            wa_in('recommendations'),
            $status,
            wa_date('follow_up_date'),
            wa_in('outcome'),
            $id]);
    $pdo->commit();
    audit($user['id'], 'worksite.update', 'worksite_assessment', $id, $status);
    flash('Assessment saved.');
    redirect('accommodations.php?id=' . $id);
}

/* ---- Recommendation report ---- */
if (isset($_GET['report'])) {
    $r = wa_load((int)$_GET['report'], $user);
    if (!$r) { flash('Assessment not found.', 'error'); redirect('accommodations.php'); }
    $doc = new AccessibleDocument('Job Accommodation Recommendations — ' . $r['client_name']);
    $doc->paragraph(brand('org'));
    $doc->properties(array_filter([
        'Client' => $r['client_name'],
        'Employer' => $r['employer'] ?? '',
        'Job title' => $r['job_title'] ?? '',
        'Worksite' => $r['worksite_location'] ?? '',
        'Assessment date' => $r['assessment_date'] ? fmt_date($r['assessment_date']) : '',
        'Assessor' => $r['assessor'] ?? $user['name'],
    ]));
    if ($r['job_tasks']) { $doc->heading('Essential job tasks'); $doc->paragraph($r['job_tasks']); }
    if ($r['barriers']) { $doc->heading('Barriers identified'); $doc->paragraph($r['barriers']); }
    $doc->heading('Recommended accommodations');
    $doc->paragraph($r['recommendations'] ?: '[Recommendations to be completed]');
    $doc->paragraph('Prepared ' . date('F j, Y') . ' by ' . ($r['assessor'] ?? $user['name']) . ' — ' . cfg('name'));
    audit($user['id'], 'worksite.report', 'worksite_assessment', (int)$r['id']);
    // Named for the client and the day, so a saved copy is found by name in a file list.
    $base = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)$r['client_name']) ?? '', '_') ?: (string)(int)$r['id'];
    $doc->download('Job_Accommodation_Recommendations_' . $base . '_' . date('Ymd') . '.html');
}

/** A date the browser cannot read (a partly typed one) is sent blank: this marks it
 *  as <field>_bad on submit so wa_field_errors() refuses it instead of clearing the date. */
function wa_bad_date_script(): string {
    return '<script>document.addEventListener("submit",function(e){var f=e.target;'
        . 'f.querySelectorAll("input[data-bad-marker]").forEach(function(m){m.remove();});'
        . 'f.querySelectorAll("input[type=date]").forEach(function(i){if(i.name&&i.validity&&i.validity.badInput){'
        . 'var m=document.createElement("input");m.type="hidden";m.name=i.name+"_bad";m.value="1";m.setAttribute("data-bad-marker","");f.appendChild(m);}});},true);</script>';
}

/* ================= Detail / edit ================= */
if (isset($_GET['id'])) {
    $r = wa_load((int)$_GET['id'], $user);
    if (!$r) { flash('Assessment not found.', 'error'); redirect('accommodations.php'); }
    $id = (int)$r['id'];
    page_header('Worksite assessment', $user, 'accommodations');
    // After a refused save, show what was typed rather than the stored values.
    $old = old_in('save_assessment') !== '' && old_in('id') === (string)$id;
    $v = fn(string $k): string => $old ? old_in($k) : (string)($r[$k] ?? '');
    ?>
    <p><a href="accommodations.php"><span aria-hidden="true">&larr; </span>All assessments</a></p>
    <h1>Worksite assessment <?= wa_badge($r['status']) ?></h1>
    <p class="muted">For <a href="client.php?id=<?= (int)$r['client_id'] ?>"><?= h($r['client_name']) ?></a>
      <?= $r['employer'] ? ' · ' . h($r['employer']) : '' ?><?= $r['job_title'] ? ' · ' . h($r['job_title']) : '' ?></p>
    <div class="page-actions"><a class="btn btn-secondary btn-sm" href="accommodations.php?report=<?= $id ?>">Download recommendation report</a></div>
    <?php // The report is for the employer: said here when no current release covers one (3.0.36). ?>
    <?php if ($relWarn = release_warning((int)$r['client_id'], 'Employer')): ?><p class="alert alert-info"><?= h($relWarn) ?></p><?php endif; ?>

    <form method="post" action="accommodations.php" class="stacked" novalidate><?= csrf_field() ?>
      <?= form_error_summary(['employer' => 'Employer', 'job_title' => 'Job title', 'worksite_location' => 'Worksite location',
          'assessment_date' => 'Assessment date', 'job_tasks' => 'Essential job tasks', 'barriers' => 'Barriers identified',
          'recommendations' => 'Recommended accommodations', 'follow_up_date' => 'Follow-up date', 'outcome' => 'Outcome / follow-up notes', 'stale' => ''], ['stale' => 'employer']) ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <?php // What this form was opened with (4.28.0), so a save over someone else's change is refused. ?>
      <input type="hidden" name="orig" value="<?= h($old && old_in('orig') !== '' ? old_in('orig') : (string)json_encode(wa_snapshot($r))) ?>">
      <input type="hidden" name="loaded_at" value="<?= h($old && old_in('loaded_at') !== '' ? old_in('loaded_at') : (string)$r['updated_at']) ?>">
      <fieldset>
        <legend>Worksite</legend>
        <div class="grid grid-4">
          <div class="field"><label for="employer">Employer</label><input type="text" id="employer" name="employer" maxlength="190" value="<?= h($v('employer')) ?>" <?= field_aria('employer') ?>><?= field_error('employer') ?></div>
          <div class="field"><label for="job_title">Job title</label><input type="text" id="job_title" name="job_title" maxlength="190" value="<?= h($v('job_title')) ?>" <?= field_aria('job_title') ?>><?= field_error('job_title') ?></div>
          <div class="field"><label for="worksite_location">Worksite location</label><input type="text" id="worksite_location" name="worksite_location" maxlength="255" value="<?= h($v('worksite_location')) ?>" <?= field_aria('worksite_location') ?>><?= field_error('worksite_location') ?></div>
          <div class="field"><label for="assessment_date">Assessment date</label><input type="date" id="assessment_date" name="assessment_date" value="<?= h($v('assessment_date')) ?>" <?= field_aria('assessment_date') ?>><?= field_error('assessment_date') ?></div>
        </div>
      </fieldset>
      <div class="field"><label for="job_tasks">Essential job tasks</label><textarea id="job_tasks" name="job_tasks" rows="3" <?= field_aria('job_tasks') ?>><?= h($v('job_tasks')) ?></textarea><?= field_error('job_tasks') ?></div>
      <div class="field"><label for="barriers">Barriers identified</label><textarea id="barriers" name="barriers" rows="3" <?= field_aria('barriers') ?>><?= h($v('barriers')) ?></textarea><?= field_error('barriers') ?></div>
      <div class="field"><label for="recommendations">Recommended accommodations</label><textarea id="recommendations" name="recommendations" rows="4" <?= field_aria('recommendations') ?>><?= h($v('recommendations')) ?></textarea><?= field_error('recommendations') ?>
        <?php if (ai_feature_enabled('draft')): ?><button type="submit" name="ai_recommend" value="1" class="btn btn-secondary btn-sm" formnovalidate>Draft recommendations with AI</button><?php endif; ?></div>
      <fieldset>
        <legend>Status &amp; follow-up</legend>
        <div class="grid grid-4">
          <div class="field"><label for="status">Status</label><select id="status" name="status"><?= select_options(WA_STATUSES, $v('status')) ?></select></div>
          <div class="field"><label for="follow_up_date">Follow-up date</label><input type="date" id="follow_up_date" name="follow_up_date" value="<?= h($v('follow_up_date')) ?>" <?= field_aria('follow_up_date') ?>><?= field_error('follow_up_date') ?></div>
        </div>
        <div class="field"><label for="outcome">Outcome / follow-up notes</label><textarea id="outcome" name="outcome" rows="3" <?= field_aria('outcome') ?>><?= h($v('outcome')) ?></textarea><?= field_error('outcome') ?></div>
      </fieldset>
      <button type="submit" name="save_assessment" value="1" class="btn btn-primary">Save assessment</button>
    </form>
    <?= wa_bad_date_script() ?>
    <?php page_footer(); exit;
}

/* ================= List ================= */
$scoped = caseload_scoped();
$statusFilter = in_array($_GET['status'] ?? '', WA_STATUSES, true) ? $_GET['status'] : '';
$where = [];
$args = [];
if ($scoped) { $where[] = 'c.assigned_to = ?'; $args[] = (int)$user['id']; }
if ($statusFilter !== '') { $where[] = 'wa.status = ?'; $args[] = $statusFilter; }
// A client filter keeps every assessment reachable once the list passes its limit.
$clientFilter = is_string($_GET['client'] ?? null) && ctype_digit($_GET['client']) ? (int)$_GET['client'] : 0;
if ($clientFilter > 0) { $where[] = 'wa.client_id = ?'; $args[] = $clientFilter; }
$sql = 'SELECT wa.id, wa.employer, wa.job_title, wa.status, wa.assessment_date, wa.follow_up_date, c.name AS client_name, c.id AS cid
    FROM worksite_assessments wa JOIN clients c ON c.id = wa.client_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY wa.updated_at DESC LIMIT 301';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$truncated = count($rows) > 300;
$rows = array_slice($rows, 0, 300);
// The clients who have an assessment in scope, for the client filter.
$fq = db()->prepare('SELECT DISTINCT c.id, c.name FROM worksite_assessments wa JOIN clients c ON c.id = wa.client_id'
    . ($scoped ? ' WHERE c.assigned_to = ?' : '') . ' ORDER BY c.name');
$fq->execute($scoped ? [(int)$user['id']] : []);
$filterClients = $fq->fetchAll();
$today = date('Y-m-d');
// The summary tiles count every assessment in scope, whatever the status filter or list limit.
$tiles = db()->prepare("SELECT COALESCE(SUM(wa.status <> 'Closed'), 0) AS open_n,
        COALESCE(SUM(wa.status <> 'Closed' AND wa.follow_up_date IS NOT NULL AND wa.follow_up_date <= ?), 0) AS due_n
    FROM worksite_assessments wa JOIN clients c ON c.id = wa.client_id" . ($scoped ? ' WHERE c.assigned_to = ?' : ''));
$tiles->execute($scoped ? [$today, (int)$user['id']] : [$today]);
$tile = $tiles->fetch();
$open = (int)$tile['open_n'];
$dueFollow = (int)$tile['due_n'];

$cq = db()->prepare("SELECT id, name FROM clients WHERE status NOT LIKE 'Closed%'" . ($scoped ? ' AND assigned_to = ?' : '') . ' ORDER BY name');
$cq->execute($scoped ? [(int)$user['id']] : []);
$clients = $cq->fetchAll();

page_header('Accommodations', $user, 'accommodations');
?>
<h1>Job accommodation &amp; worksite assessments</h1>
<p class="muted">On-site job assessments, the barriers found, and recommended accommodations — from scheduling through follow-up<?= $scoped ? ' (your caseload)' : '' ?>.</p>

<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= $open ?></span><span class="stat-label">Open assessments</span></div>
  <div class="card stat"><span class="stat-num"><?= $dueFollow ?></span><span class="stat-label">Follow-up due</span></div>
  <div class="card stat"><span class="stat-num"><?= count($rows) ?></span><span class="stat-label">Shown<?= $statusFilter !== '' ? ' (' . h($statusFilter) . ')' : '' ?><?= $truncated ? ' (most recent 300)' : '' ?></span></div>
</div>

<section class="card" aria-labelledby="new-h">
  <h2 id="new-h">New worksite assessment</h2>
  <form method="post" action="accommodations.php" class="stacked" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['client_id' => 'Client', 'employer' => 'Employer', 'job_title' => 'Job title', 'assessment_date' => 'Assessment date'],
        ['employer' => 'employer_new', 'job_title' => 'job_title_new', 'assessment_date' => 'assessment_date_new']) ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="grid grid-4">
      <div class="field"><label for="client_id">Client <?= required_mark() ?></label>
        <select id="client_id" name="client_id" required <?= field_aria('client_id') ?>>
          <option value="">— Choose —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= old_in('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select><?= field_error('client_id') ?></div>
      <div class="field"><label for="employer_new">Employer</label><input type="text" id="employer_new" name="employer" maxlength="190" value="<?= h(old_in('employer')) ?>" <?= field_aria('employer') ?>><?= field_error('employer') ?></div>
      <div class="field"><label for="job_title_new">Job title</label><input type="text" id="job_title_new" name="job_title" maxlength="190" value="<?= h(old_in('job_title')) ?>" <?= field_aria('job_title') ?>><?= field_error('job_title') ?></div>
      <div class="field"><label for="assessment_date_new">Assessment date</label><input type="date" id="assessment_date_new" name="assessment_date" value="<?= h(old_in('assessment_date')) ?>" <?= field_aria('assessment_date') ?>><?= field_error('assessment_date') ?></div>
    </div>
    <button type="submit" name="add_assessment" value="1" class="btn btn-primary">Create assessment</button>
  </form>
  <?= wa_bad_date_script() ?>
</section>

<form method="get" action="accommodations.php" class="page-actions" role="search" aria-label="Filter">
  <label for="status">Status</label>
  <select id="status" name="status">
    <option value="">All</option>
    <?php foreach (WA_STATUSES as $s): ?><option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?>
  </select>
  <label for="client_filter">Client</label>
  <select id="client_filter" name="client">
    <option value="">All clients</option>
    <?php foreach ($filterClients as $fc): ?><option value="<?= (int)$fc['id'] ?>" <?= $clientFilter === (int)$fc['id'] ? 'selected' : '' ?>><?= h($fc['name']) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
</form>
<?php if ($truncated): ?><p class="muted">Only the 300 most recently updated assessments are listed here. Choose a client or a status above to find an older one.</p><?php endif; ?>

<h2 id="list-h">Worksite assessments</h2>
<div class="table-scroll" tabindex="0" role="region" aria-label="Worksite assessments">
<table>
  <caption class="visually-hidden">Worksite assessments</caption>
  <thead><tr><th scope="col">Client</th><th scope="col">Employer / role</th><th scope="col">Status</th><th scope="col">Assessed</th><th scope="col">Follow-up</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No worksite assessments<?= ($statusFilter || $clientFilter) ? ' match that filter' : ' yet' ?>.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x):
    // A client can have several assessments: the link also names the employer,
    // role and date (hidden text), so a list of links tells them apart.
    $which = array_filter([(string)($x['employer'] ?? ''), (string)($x['job_title'] ?? ''),
        $x['assessment_date'] ? 'assessed ' . fmt_date($x['assessment_date']) : 'assessment #' . (int)$x['id']], fn($v) => $v !== ''); ?>
    <tr>
      <td><a href="accommodations.php?id=<?= (int)$x['id'] ?>"><?= h($x['client_name']) ?><span class="visually-hidden"> — <?= h(implode(', ', $which)) ?></span></a></td>
      <td><?= h($x['employer'] ?? '—') ?><?= $x['job_title'] ? '<br><span class="muted">' . h($x['job_title']) . '</span>' : '' ?></td>
      <td><?= wa_badge($x['status']) ?></td>
      <td><?= $x['assessment_date'] ? fmt_date($x['assessment_date']) : '—' ?></td>
      <td><?= $x['follow_up_date'] ? ($x['follow_up_date'] <= $today && $x['status'] !== 'Closed' ? '<strong class="field-error">' . fmt_date($x['follow_up_date']) . ($x['follow_up_date'] < $today ? ' — overdue' : ' — due today') . '</strong>' : fmt_date($x['follow_up_date'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
