<?php
// Database upgrade (3.0.54): after new application files are copied in, this applies the
// database changes they need, in order, one step at a time, and reports each step. It runs
// only after an administrator confirms there is a backup from today. Every migration is
// safe to run again.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('admin');
csrf_check();
@set_time_limit(600);

$dumps = glob(dirname((string)cfg('upload_dir')) . '/backups/cms_*.sql.gz') ?: [];
rsort($dumps);
$newest = $dumps ? (int)filemtime($dumps[0]) : null;
$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_upgrade'])) {
    if (empty($_POST['have_backup'])) { flash('Confirm that there is a backup from today first.', 'error'); redirect('upgrade.php'); }
    $report = [];
    foreach (pending_migrations() as $v => $path) {
        [$n, $err] = run_migration_file($path);
        if ($err !== null) { $report[] = "Version $v stopped: $err Nothing after it was run. Restore from the backup, or ask for help."; break; }
        if (version_compare((string)$v, (string)(db_schema_version() ?? '0'), '>')) set_setting('schema_version', (string)$v);
        $report[] = "Version $v applied ($n change" . ($n === 1 ? '' : 's') . ').';
    }
    if (!$report) $report[] = 'The database was already up to date.';
    audit($user['id'], 'database.upgrade', 'settings', null, mb_substr(implode(' ', $report), 0, 250));
}
$have = db_schema_version();
$pending = pending_migrations();
page_header('Database upgrade', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Database upgrade</h1>
<?php if ($report): ?>
<section class="card" aria-labelledby="result-h"><h2 id="result-h">What happened</h2><ol><?php foreach ($report as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ol></section>
<?php endif; ?>
<p>The application files are version <?= h(APP_VERSION) ?> and need database version <?= h(DB_SCHEMA_VERSION) ?>. The database is <?= $have ? 'version ' . h($have) : 'from before version 3.0.54 (not recorded)' ?>.</p>
<?php if (!$pending || ($have !== null && version_compare($have, DB_SCHEMA_VERSION, '>='))): ?>
<p><strong>The database is up to date.</strong> Nothing needs doing.</p>
<?php else: ?>
<p><?= count($pending) ?> database change<?= count($pending) === 1 ? '' : 's' ?> to apply: version<?= count($pending) === 1 ? '' : 's' ?> <?= h(implode(', ', array_keys($pending))) ?>.</p>
<p>The newest backup on the server was made <?= $newest ? h(date('F j, Y \a\t g:i a', $newest)) : 'never' ?>.<?= $newest && $newest < time() - 86400 ? ' <strong>It is more than a day old: make a fresh copy first (download the database from your hosting control panel).</strong>' : '' ?></p>
<form method="post" action="upgrade.php" class="stacked"><?= csrf_field() ?>
  <div class="field"><label><input type="checkbox" name="have_backup" value="1"> There is a backup of the database from today</label></div>
  <button type="submit" name="run_upgrade" value="1" class="btn btn-primary">Apply the database changes</button>
</form>
<?php endif; ?>
<?php page_footer();
