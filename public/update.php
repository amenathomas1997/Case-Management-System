<?php
// Software update (administrators, 4.18.0): download the publisher's newest signed release from
// GitHub, check it, then install it with one button (inc/updater.php). Two steps, so the
// administrator sees what will change, and that a database backup exists, before anything does.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/updater.php';
csrf_check();
$user = require_login();
require_perm('admin');

$pending = (string)(setting('update_downloaded') ?? '');
$pendingZip = $pending !== '' ? updater_dir() . '/cms-update-' . $pending . '.zip' : '';

// An install the server cut off part way: put the previous files back.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_previous']) && is_file(updater_marker())) {
    $mk = (array)json_decode((string)file_get_contents(updater_marker()), true);
    $bk = (string)($mk['backup'] ?? '');
    if ($bk !== '' && dirname($bk) === updater_dir() && is_file($bk) && updater_restore($bk)) {
        @unlink(updater_marker());
        audit($user['id'], 'update.restore', 'settings', null, 'back to ' . (string)($mk['from'] ?? '?'));
        flash('The previous files were put back. The site runs version ' . (string)($mk['from'] ?? 'it had') . ' again; its database keeps the new changes, which the previous version works with.');
    } else {
        flash('The previous files could not all be put back. Restore them from ' . basename($bk) . ' in the private updates folder, or ask for help.', 'error');
    }
    redirect('update.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && updater_enabled() && isset($_POST['download'])) {
    try {
        $rel = updater_latest();
        if ($rel === null) throw new RuntimeException('GitHub could not be reached, or there is no release yet. Try again later.');
        if (!version_compare($rel['version'], APP_VERSION, '>')) { flash('This site already runs the newest version (' . APP_VERSION . ').'); redirect('update.php'); }
        $zip = updater_download($rel);
        set_setting('update_downloaded', $rel['version']);
        audit($user['id'], 'update.download', 'settings', null, $rel['version']);
        flash('Version ' . $rel['version'] . ' was downloaded and its signature checked. Read what it will do below, then install it.');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('update.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && updater_enabled() && isset($_POST['install'])) {
    // 4.27.0: the error is tied to the tick box and listed in the error summary.
    if (empty($_POST['have_backup'])) { keep_field_errors(['have_backup' => 'Confirm that there is a backup of the database from today first.']); redirect('update.php'); }
    try {
        // Checked again here: the file could have changed since it was downloaded.
        if ($pendingZip === '' || !is_file($pendingZip) || !updater_signature_ok($pendingZip, $pendingZip . '.sig')) throw new RuntimeException('The downloaded update is missing or no longer matches its signature. Download it again.');
        $plan = updater_inspect($pendingZip);
        $report = updater_apply($pendingZip, $plan, (int)$user['id']);
        set_setting('update_downloaded', '');
        flash('Updated to version ' . $plan['version'] . '. ' . implode(' ', $report));
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('update.php');
}

$plan = null;
$planError = null;
if ($pendingZip !== '' && is_file($pendingZip) && version_compare($pending, APP_VERSION, '>')) {
    try { $plan = updater_inspect($pendingZip); } catch (RuntimeException $e) { $planError = $e->getMessage(); }
}
$latest = setting('update_latest');
$dumps = glob(dirname((string)cfg('upload_dir')) . '/backups/cms_*.sql.gz') ?: [];
rsort($dumps);
$newest = $dumps ? (int)filemtime($dumps[0]) : null;
page_header('Software update', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Software update</h1>
<p>This site runs version <?= h(APP_VERSION) ?>.<?= $latest && version_compare($latest, APP_VERSION, '>') ? ' Version ' . h($latest) . ' is available.' : '' ?></p>
<?php if (is_file(updater_marker())): $mk = (array)json_decode((string)file_get_contents(updater_marker()), true); ?>
<section class="card" aria-labelledby="cut-h">
  <h2 id="cut-h">An update did not finish</h2>
  <p>Installing version <?= h((string)($mk['to'] ?? '')) ?> was cut off part way (<?= h((string)($mk['at'] ?? '')) ?>), so the site may have a mix of old and new files. Put the previous files back now; then try the update again.</p>
  <form method="post" action="update.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="restore_previous" value="1" class="btn btn-primary">Put the previous files back</button>
  </form>
</section>
<?php endif; ?>
<?php if (!updater_enabled()): ?>
<p>One-button updates are off. To get them from the publisher's GitHub releases, set "Where to check for new versions" to <code>github</code> on <a href="settings.php">Settings</a>, Updates.</p>
<?php else: ?>
<?php if (!$plan): ?>
<section class="card" aria-labelledby="get-h">
  <h2 id="get-h">Get the newest version</h2>
  <p>Downloads the newest release from GitHub (<?= h(UPDATE_REPO) ?>) and checks that the publisher signed it. Nothing changes on the site yet.</p>
  <?php if ($planError): ?><p><strong><?= h($planError) ?></strong></p><?php endif; ?>
  <form method="post" action="update.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="download" value="1" class="btn btn-primary">Download and check the newest version</button>
  </form>
</section>
<?php else: ?>
<section class="card" aria-labelledby="plan-h">
  <h2 id="plan-h">Version <?= h($plan['version']) ?> is ready to install</h2>
  <?php if ($plan['notes'] !== ''): // from inside the signed package ?><p><?= nl2br(h($plan['notes'])) ?></p><?php endif; ?>
  <p>It replaces <?= count($plan['docroot']) ?> website files<?= $plan['cron'] ? ' and ' . count($plan['cron']) . ' scheduled-job files' : '' ?><?= $plan['remove'] ? ', removes ' . count($plan['remove']) . ' old ones' : '' ?>, and applies any database changes the new version needs. The current files are copied first, and put back if anything fails. Your settings, uploads, icons and configuration are not touched.</p>
  <?php if ($plan['problems']): ?>
  <p><strong>It cannot be installed yet:</strong></p>
  <ul><?php foreach ($plan['problems'] as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul>
  <?php if (updater_db_behind() !== null): ?><p><a href="upgrade.php">Open Database upgrade</a>, then come back here to install the update.</p><?php endif; ?>
  <p>Fix these (usually by letting the web server write its own files, in your hosting control panel), or update by hand from the release's install bundle.</p>
  <?php else: ?>
  <p>The newest database backup on the server was made <?= $newest ? h(date('F j, Y \a\t g:i a', $newest)) : 'never' ?>.<?= !$newest || $newest < time() - 86400 ? ' <strong>Make a fresh copy first (download the database from your hosting control panel).</strong>' : '' ?></p>
  <form method="post" action="update.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['have_backup' => 'Backup']) ?>
    <div class="field"><label><input type="checkbox" id="have_backup" name="have_backup" value="1" required aria-required="true" <?= field_aria('have_backup') ?>> There is a backup of the database from today <?= required_mark() ?></label><?= field_error('have_backup') ?></div>
    <?php if ($plan['kept']): ?><p>Kept as your own (you changed them): <?= h(implode(', ', $plan['kept'])) ?>.</p><?php endif; ?>
    <button type="submit" name="install" value="1" class="btn btn-primary">Install version <?= h($plan['version']) ?></button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
