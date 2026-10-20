<?php
// Custom reports (4.7.0): build a report from the fields of clients, tracks, hours, invoices,
// sessions or referrals; see it as a table, download it as a spreadsheet, and save it.
// Caseload-scoped like the other reports (inc/custom_reports.php).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('analytics');
$uid = (int)$user['id'];

/* Save, or delete, a report */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    $def = custom_report_clean($_POST);
    $name = trim(is_string($_POST['report_name'] ?? null) ? $_POST['report_name'] : '');
    if ($name === '' || mb_strlen($name) > 120) { keep_input(); keep_field_errors(['report_name' => 'Name the report (120 characters or fewer) to save it.']); redirect('custom_reports.php?' . http_build_query($def) . '&run=1#save-h'); }
    try {
        db()->prepare('INSERT INTO saved_reports (name, owner_id, shared, definition) VALUES (?,?,?,?)')
            ->execute([$name, $uid, !empty($_POST['shared']) ? 1 : 0, json_encode($def)]);
        $newId = (int)db()->lastInsertId();   // before audit() inserts its own row
    } catch (PDOException $e) {
        flash('The report could not be saved. If this site was just upgraded, run Database upgrade first.', 'error');
        redirect('custom_reports.php');
    }
    audit($uid, 'report.save', 'settings', null, $name);
    flash('Saved "' . $name . '". It is listed under Saved reports.');
    redirect('custom_reports.php?saved=' . $newId . '#result-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
    $rid = (int)($_POST['report_id'] ?? 0);
    $d = db()->prepare('DELETE FROM saved_reports WHERE id = ? AND (owner_id = ? OR ?)');
    $d->execute([$rid, $uid, can('admin') ? 1 : 0]);
    flash($d->rowCount() ? 'The saved report was deleted.' : 'Only the person who saved a report, or an administrator, can delete it.', $d->rowCount() ? 'success' : 'error');
    redirect('custom_reports.php#saved-h');
}

$saved = custom_reports_saved($uid);
$savedRow = null;
if (isset($_GET['saved'])) foreach ($saved as $s) if ((int)$s['id'] === (int)$_GET['saved']) $savedRow = $s;
$def = $savedRow ? custom_report_clean((array)json_decode((string)$savedRow['definition'], true)) : custom_report_clean($_GET);
$subjects = custom_report_allowed_subjects();
[$subjectLabel, , , $fields, $statusCol] = $subjects[$def['subject']];
$run = $savedRow || isset($_GET['run']) || isset($_GET['export']);
$result = $run ? custom_report_run($def, $uid, isset($_GET['export']) ? 10000 : 500) : null;
if ($result && !isset($_GET['export'])) audit($uid, 'report.view', 'client', null, 'Custom report, ' . count($result['rows']) . ' rows: ' . json_encode($def));

if ($result && isset($_GET['export'])) {
    // What was taken, and how much, is kept (client information can leave in a spreadsheet).
    audit($uid, 'report.export', 'client', null, 'Custom report CSV, ' . count($result['rows']) . ' rows: ' . json_encode($def));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report_' . $def['subject'] . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if (caseload_scoped()) write_csv_row($out, ['SCOPE', 'Your own caseload only']);
    write_csv_row($out, $result['head']);
    foreach ($result['rows'] as $r) write_csv_row($out, array_map(fn($v, $k) => csv_cell(custom_report_csv_value($v, $k)), $r, $result['kinds']));
    exit;
}

// Choices for the filters.
$statuses = [];
if ($statusCol) {
    [$tbl, $col] = explode('.', $statusCol);
    $map = ['c' => 'clients', 't' => 'training_tracks', 'i' => 'invoices', 's' => 'scheduled_sessions', 'r' => 'referrals'];
    $statuses = db()->query('SELECT DISTINCT ' . $col . ' FROM ' . $map[$tbl] . ' WHERE ' . $col . ' IS NOT NULL ORDER BY ' . $col)->fetchAll(PDO::FETCH_COLUMN);
}
$staffList = caseload_scoped() ? [$uid => (string)$user['name']] : array_column(db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll(), 'name', 'id');
$agencies = array_map(fn($a) => (string)$a['name'], array_values(agencies_all()));
$sel = fn(string $v, string $cur) => $v === $cur ? ' selected' : '';

page_header('Custom reports', $user, 'analytics', [['analytics.php', 'Business analytics']]);
?>
<h1>Custom reports</h1>
<p>Choose what to report on, the columns and the filters, then show the report. You can download it as a spreadsheet or save it to run again.<?= caseload_scoped() ? ' It covers your own caseload.' : '' ?></p>

<?php if ($saved): ?>
<section class="card" aria-labelledby="saved-h">
  <h2 id="saved-h">Saved reports (<?= count($saved) ?>)</h2>
  <ul>
  <?php foreach ($saved as $s): ?>
    <li><a href="custom_reports.php?saved=<?= (int)$s['id'] ?>#result-h"><?= h($s['name']) ?></a><?= (int)$s['owner_id'] !== $uid ? ', shared by ' . h((string)$s['owner_name']) : ((int)$s['shared'] === 1 ? ', shared' : '') ?>
      <?php if ((int)$s['owner_id'] === $uid || can('admin')): ?>
      <form method="post" action="custom_reports.php" class="inline-form" onsubmit="return confirm('Delete the saved report <?= h(addslashes((string)$s['name'])) ?>?');"><?= csrf_field() ?>
        <input type="hidden" name="report_id" value="<?= (int)$s['id'] ?>"><button type="submit" name="delete_report" value="1" class="btn btn-secondary btn-sm">Delete<span class="visually-hidden"> <?= h($s['name']) ?></span></button></form>
      <?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="build-h">
  <h2 id="build-h" tabindex="-1">Build a report</h2>
  <form method="get" action="custom_reports.php#build-h" class="stacked">
    <div class="field"><label for="subject">Report on</label>
      <select id="subject" name="subject"><?php foreach ($subjects as $k => $s): ?><option value="<?= h($k) ?>"<?= $sel($k, $def['subject']) ?>><?= h($s[0]) ?></option><?php endforeach; ?></select></div>
    <button type="submit" name="choose" value="1" class="btn btn-secondary btn-sm">Show the choices for <?= h(mb_strtolower($subjectLabel)) ?></button>
  </form>
  <form method="get" action="custom_reports.php#result-h" class="stacked">
    <input type="hidden" name="subject" value="<?= h($def['subject']) ?>">
    <fieldset><legend>Columns: <?= h(mb_strtolower($subjectLabel)) ?></legend>
      <?php foreach ($fields as $k => [$label]): ?>
      <label class="check"><input type="checkbox" name="cols[]" value="<?= h($k) ?>"<?= in_array($k, $def['cols'], true) ? ' checked' : '' ?>> <?= h($label) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <fieldset><legend>Filters</legend>
      <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= h($def['from']) ?>"></div>
      <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= h($def['to']) ?>"></div>
      <?php if ($statuses): ?><div class="field"><label for="status">Status</label>
        <select id="status" name="status"><option value="">Any</option><?php foreach ($statuses as $st): ?><option value="<?= h((string)$st) ?>"<?= $sel((string)$st, $def['status']) ?>><?= h((string)$st) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <?php if (isset($fields['staff'])): ?><div class="field"><label for="staff">Staff</label>
        <select id="staff" name="staff"><option value="0">Anyone</option><?php foreach ($staffList as $sid => $sname): ?><option value="<?= (int)$sid ?>"<?= $sel((string)$sid, (string)$def['staff']) ?>><?= h($sname) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <?php if (isset($fields['funding_agency']) && $agencies): ?><div class="field"><label for="agency">Funding agency</label>
        <select id="agency" name="agency"><option value="">Any</option><?php foreach ($agencies as $ag): ?><option value="<?= h($ag) ?>"<?= $sel($ag, $def['agency']) ?>><?= h($ag) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    </fieldset>
    <fieldset><legend>Grouping and order</legend>
      <div class="field"><label for="group">Group by (one row for each, with how many and totals)</label>
        <select id="group" name="group"><option value="">No grouping</option><?php foreach ($fields as $k => [$label, , $kind]): if ($kind === 'number' || $kind === 'money') continue; ?><option value="<?= h($k) ?>"<?= $sel($k, $def['group']) ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="sort">Sort by</label>
        <select id="sort" name="sort"><option value="">Newest first</option><?php foreach ($fields as $k => [$label]): ?><option value="<?= h($k) ?>"<?= $sel($k, $def['sort']) ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><input type="checkbox" name="desc" value="1"<?= $def['desc'] ? ' checked' : '' ?>> Reverse order</label></div>
    </fieldset>
    <button type="submit" name="run" value="1" class="btn btn-primary">Show the report</button>
  </form>
</section>

<?php if ($result): $q = http_build_query($def + ['run' => 1]); ?>
<section class="card" aria-labelledby="result-h">
  <h2 id="result-h" tabindex="-1"><?= $savedRow ? h($savedRow['name']) : h($subjectLabel) ?>: <?= $result['grouped'] ? count($result['rows']) . ' group' . (count($result['rows']) === 1 ? '' : 's') : $result['total'] . ' row' . ($result['total'] === 1 ? '' : 's') ?></h2>
  <?php if (count($result['rows']) >= 500 && ($result['grouped'] || $result['total'] > count($result['rows']))): ?><p>The first <?= count($result['rows']) ?> are shown; the download has up to 10,000.</p><?php endif; ?>
  <p><a href="custom_reports.php?<?= h($q) ?>&amp;export=1">Download as a spreadsheet (CSV)</a></p>
  <?php if (!$result['rows']): ?><p>Nothing matches these filters.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="result-cap"><table class="table">
    <caption class="visually-hidden" id="result-cap"><?= h($subjectLabel) ?> report</caption>
    <thead><tr><?php foreach ($result['head'] as $i => $hd): ?><th scope="col"<?= in_array($result['kinds'][$i], ['number', 'money'], true) ? ' class="num"' : '' ?>><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $r): ?><tr><?php foreach ($r as $i => $v): ?><?= $i === 0 ? '<th scope="row">' : '<td' . (in_array($result['kinds'][$i], ['number', 'money'], true) ? ' class="num"' : '') . '>' ?><?= h(($cellText = custom_report_cell($v, $result['kinds'][$i])) === '' && $i === 0 ? '(blank)' : $cellText) ?><?= $i === 0 ? '</th>' : '</td>' ?><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if (!$savedRow): ?>
  <h3 id="save-h">Save this report</h3>
  <form method="post" action="custom_reports.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['report_name' => 'Name']) ?>
    <?php foreach ($def as $k => $v): if ($k === 'cols') { foreach ($v as $c): ?><input type="hidden" name="cols[]" value="<?= h($c) ?>"><?php endforeach; continue; } ?>
    <input type="hidden" name="<?= h($k) ?>" value="<?= h(is_bool($v) ? ($v ? '1' : '') : (string)$v) ?>">
    <?php endforeach; ?>
    <div class="field"><label for="report_name">Name <?= required_mark() ?></label><input type="text" id="report_name" name="report_name" maxlength="120" required aria-required="true" value="<?= h(old_in('report_name')) ?>" <?= field_aria('report_name') ?>><?= field_error('report_name') ?></div>
    <div class="field"><label><input type="checkbox" name="shared" value="1"> Share with everyone who can see reports</label></div>
    <button type="submit" name="save_report" value="1" class="btn btn-secondary">Save report</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_footer();
