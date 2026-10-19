<?php
// System health (administrators): everything that keeps the system running, on one
// page. Every scheduled job and when it last ran, the backups, email, storage, the
// database, sign-in activity, the AI assistant, recent problems in the cron log and
// work that is waiting. The job checks are health.php's (inc/health_checks.php), so
// this page and the external monitor never disagree. It can be reloaded at will ("Check
// again"). Its few actions (3.0.53): check the backup now, send a test email, and record
// when the Microsoft Graph secret expires.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/health_checks.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('admin');
csrf_check();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_backup'])) {
        $bc = backup_check(false);
        audit($user['id'], 'backup.check', 'settings', null, $bc['ok'] ? 'ok' : 'problem');
        flash(($bc['ok'] ? 'The backup check passed. ' : 'The backup check found a problem. ') . $bc['detail'], $bc['ok'] ? 'success' : 'error');
        redirect('system_health.php#backups-h');
    }
    if (isset($_POST['test_email'])) {
        $sent = send_app_mail((string)$user['email'], 'Test email from ' . brand('org'), "This is a test email from the Case Management System's System health page. If you can read it, email is working.\n");
        flash($sent ? 'A test email was sent to ' . $user['email'] . '. Check that it arrived (and not in junk).' : 'The test email could not be sent. See Email below.', $sent ? 'success' : 'error');
        redirect('system_health.php#email-h');
    }
    if (isset($_POST['forget_keys'])) {
        // 4.29.0 (P14): the way out when the key file is lost or replaced and the old one cannot
        // be copied back. Typed confirmation, since it turns off authenticator apps and connections.
        $typed = strtoupper(trim(is_string($_POST['forget_confirm'] ?? null) ? $_POST['forget_confirm'] : ''));
        if ($typed !== 'FORGET') {
            keep_field_errors(['forget_confirm' => $typed === '' ? 'Type the word FORGET to confirm. Nothing was changed.' : 'That is not the word FORGET. Nothing was changed.']);
            redirect('system_health.php#forget-keys-h');
        }
        $fr = meeting_forget_stored_keys((int)$user['id']);
        if (!$fr['ok'] && $fr['seeds'] + $fr['meetings'] + $fr['qbo'] === 0) { flash($fr['error'], 'error'); redirect('system_health.php#security-h'); }
        $done = $fr['seeds'] . ' authenticator app' . ($fr['seeds'] === 1 ? '' : 's') . ' turned off (those people were emailed and sign in with an emailed code until they set it up again), '
            . $fr['meetings'] . ' meeting account' . ($fr['meetings'] === 1 ? '' : 's') . ' and ' . ($fr['qbo'] ? 'the QuickBooks connection' : 'no QuickBooks connection') . ' forgotten.';
        flash($fr['ok'] ? 'Done: ' . $done . ' A new key file was made. Connect QuickBooks again on the QuickBooks Online page, and ask staff to connect Zoom or Teams again on My Account.'
            : $done . ' ' . $fr['error'], $fr['ok'] ? 'success' : 'error');
        redirect('system_health.php#security-h');
    }
    if (isset($_POST['save_graph_expiry'])) {
        $d = trim((string)($_POST['graph_expires'] ?? ''));
        if ($d !== '' && !valid_date($d, (int)date('Y'), (int)date('Y') + 3)) { flash('Enter the expiry date as a date within the next three years, or leave it empty.', 'error'); redirect('system_health.php#email-h'); }
        set_setting('graph_credential_expires', $d);
        audit($user['id'], 'settings.graph_expiry', 'settings', null, $d ?: 'cleared');
        flash($d === '' ? 'The secret expiry date was cleared.' : 'Saved. Administrators are reminded 30, 14 and 7 days before ' . fmt_date($d) . '.');
        redirect('system_health.php#email-h');
    }
}

$now = time();
$when = fn(?int $ts): string => $ts ? date('F j, Y g:i a', $ts) : 'never';
$ago = function (?int $ts) use ($now): string {
    if (!$ts) return '';
    $m = intdiv(max(0, $now - $ts), 60);
    if ($m < 60) return $m <= 1 ? 'a minute ago' : "$m minutes ago";
    $hrs = intdiv($m, 60);
    if ($hrs < 48) return $hrs === 1 ? 'an hour ago' : "$hrs hours ago";
    return intdiv($hrs, 24) . ' days ago';
};
$ts = fn(?string $v): ?int => $v !== null && $v !== '' && strtotime($v) !== false ? (int)strtotime($v) : null;
$bytes = function (float $b): string {
    foreach (['bytes', 'KB', 'MB', 'GB', 'TB'] as $i => $u) if ($b < 1024 ** ($i + 1) || $u === 'TB') return ($i ? number_format($b / 1024 ** $i, 1) : number_format($b)) . ' ' . $u;
    return '';
};
$q = function (string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$problems = []; // [anchor id, text]

/* ---- Database ---- */
$dbOk = true; $dbVersion = ''; $dbSize = 0.0; $dbTables = 0;
try {
    $dbVersion = (string)db()->query('SELECT VERSION()')->fetchColumn();
    $sz = db()->query('SELECT COUNT(*), COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetch(PDO::FETCH_NUM);
    [$dbTables, $dbSize] = [(int)$sz[0], (float)$sz[1]];
} catch (Throwable $e) { $dbOk = false; $problems[] = ['db-h', 'The database did not answer.']; }

/* ---- Scheduled jobs ---- */
$jobs = health_job_rows($dbOk, $now);
foreach ($jobs as $j) if (!$j['ok']) $problems[] = ['jobs-h', $j['name'] . ': ' . $j['detail']];

/* ---- Backups ---- */
$priv = health_private_dir();
$dumps = health_backup_dumps() ?? [];
$dumpTotal = 0.0; foreach (array_keys($dumps) as $f) $dumpTotal += (float)@filesize($f);
$newestDump = $dumps ? array_key_first($dumps) : null;
$uploadsArchives = glob($priv . '/backups/uploads_*.tar.gz') ?: [];
usort($uploadsArchives, fn($a, $b) => filemtime($b) <=> filemtime($a));
$offsite = $dbOk ? $ts(setting('heartbeat_weekly_offsite')) : null;
if ($newestDump !== null && (float)@filesize($newestDump) < 1000) $problems[] = ['backups-h', 'The newest database backup is smaller than 1 KB, so it is probably empty.'];

/* ---- Storage ---- */
$diskFree = @disk_free_space($priv); $diskTotal = @disk_total_space($priv);
$diskPct = ($diskFree !== false && $diskTotal) ? round($diskFree / $diskTotal * 100) : null;
if ($diskPct !== null && $diskPct < 10) $problems[] = ['storage-h', 'Less than 10% of the server disk is free (' . $bytes((float)$diskFree) . ').'];
$uploadCount = 0; $uploadBytes = 0.0;
$updir = (string)cfg('upload_dir');
if (is_dir($updir)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($updir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) { $uploadCount++; $uploadBytes += $f->getSize(); }
        if ($uploadCount > 50000) break; // a bound, so a huge folder cannot stall the page
    }
}

/* ---- Email ---- */
$graphOn = function_exists('graph_cfg') && graph_cfg() !== null;
$mailDisabled = !empty($GLOBALS['CONFIG']['mail_disabled']);
$mailSent = $dbOk ? $ts(setting('mail_last_sent')) : null;
$mailFailed = $dbOk ? $ts(setting('mail_last_failed')) : null;
if (!$graphOn && !$mailDisabled) $problems[] = ['email-h', 'Microsoft Graph email is not configured, so sign-in codes and reminders use the server\'s own mail, which is often blocked as spam.'];
$graphDays = graph_secret_days_left();
if ($graphOn && $graphDays !== null && $graphDays <= 30) $problems[] = ['email-h', $graphDays > 0 ? "The Microsoft Graph email secret expires in $graphDays days." : 'The Microsoft Graph email secret has expired.'];
$bcResult = (string)(setting('backup_check_result') ?? '');
if (str_starts_with($bcResult, 'PROBLEM')) $problems[] = ['backups-h', 'The last backup check found a problem.'];
$mailRecent = mail_log_recent(30);
$schemaHave = db_schema_version();
if ($dbOk && ($schemaHave === null || version_compare($schemaHave, DB_SCHEMA_VERSION, '<'))) $problems[] = ['db-h', 'The database is ' . ($schemaHave ? 'version ' . $schemaHave : 'from before 3.0.54') . ' but the files need ' . DB_SCHEMA_VERSION . ': open Database upgrade.'];
if ($mailFailed && $mailFailed > ($mailSent ?? 0)) $problems[] = ['email-h', 'The most recent email attempt failed (' . $when($mailFailed) . '), and none has been sent since.'];

/* ---- Sign-in and security ---- */
$failed24 = $failedIps = $locked = $staff = $withTotp = $appSessions = 0;
if ($dbOk) {
    $failed24 = (int)$q("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND email LIKE '%@%' AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $failedIps = (int)$q("SELECT COUNT(DISTINCT ip) FROM login_attempts WHERE success = 0 AND email LIKE '%@%' AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $staff = (int)$q('SELECT COUNT(*) FROM users WHERE is_active = 1');
    $withTotp = (int)$q('SELECT COUNT(*) FROM users WHERE is_active = 1 AND totp_enabled = 1');
    $appSessions = (int)$q('SELECT COUNT(DISTINCT user_id) FROM api_tokens WHERE expires_at > NOW() AND wipe_requested_at IS NULL');
}
// 4.28.0: the key file that encrypted authenticator seeds and connection tokens need.
// 4.29.0 (P14): what is wrong, and when it is lost or replaced, the action that forgets what cannot be read.
$keyProblem = $dbOk && function_exists('meeting_key_problem') ? meeting_key_problem() : null;
$keyCounts = in_array($keyProblem, ['missing', 'damaged', 'mismatch'], true) ? meeting_unreadable_counts() : null;
$forgetWay = ' If the old key file cannot be found, use "Forget stored connections and keys" below.';
if ($keyProblem === 'missing') $problems[] = ['security-h', 'The key file meeting_tokens.key is missing from the private directory, but authenticator apps, meeting accounts or QuickBooks need it: those people cannot sign in with their app. Copy the old key file back (readable only by its owner), or reset two-step verification for them.' . $forgetWay];
elseif ($keyProblem === 'mismatch') $problems[] = ['security-h', 'The key file meeting_tokens.key does not match the stored authenticator apps or connections (it was replaced, perhaps after a restore), so those cannot be used. Copy the original key file back, or reset two-step verification for them.' . $forgetWay];
elseif ($keyProblem === 'damaged' && meeting_key_dependents() > 0) $problems[] = ['security-h', 'The key file meeting_tokens.key in the private directory cannot be read (damaged), so authenticator apps, meeting accounts and QuickBooks cannot be used.' . $forgetWay];
elseif ($keyProblem === 'access') $problems[] = ['security-h', 'The website cannot open the key file meeting_tokens.key in the private directory (it belongs to another user, perhaps copied back as root, or its mode does not let the site read it), so authenticator apps, meeting accounts and QuickBooks cannot be used. It may still be the right key: make it owned by the user the website runs as, readable only by that user (chmod 600). Do not forget anything until that is done.'];
elseif ($keyProblem === 'permissions') $problems[] = ['security-h', 'The key file meeting_tokens.key in the private directory can be read by others: make it readable only by its owner (chmod 600). Until then authenticator apps, meeting accounts and QuickBooks cannot be used.'];
// 4.29.0: Microsoft sent no auth_time after a hand-over, so Microsoft sign-in cannot work on that browser.
if ($dbOk && entra_cfg() && (string)(setting('sso_auth_time_missing') ?? '') !== '') $problems[] = ['security-h', 'Microsoft sign-in did not send the optional claim auth_time (last on ' . fmt_date((string)setting('sso_auth_time_missing')) . '), so after a device is handed over for signing nobody can sign in with Microsoft on it. Add auth_time under Token configuration in the app registration (Federation explains).'];
if ($failed24 >= 50) $problems[] = ['security-h', "$failed24 failed sign-ins in the last 24 hours, from $failedIps address" . ($failedIps === 1 ? '' : 'es') . '.'];

/* ---- AI assistant ---- */
$aiOn = function_exists('ai_enabled') && ai_enabled();
$aiCalls = $aiFails = 0; $aiLastFail = null;
if ($dbOk) {
    $aiCalls = (int)$q('SELECT COUNT(*) FROM ai_usage WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $aiFails = (int)$q('SELECT COUNT(*) FROM ai_usage WHERE succeeded = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $aiLastFail = $ts((string)$q('SELECT MAX(created_at) FROM ai_usage WHERE succeeded = 0') ?: null);
}
if ($aiOn && $aiCalls >= 10 && $aiFails * 2 > $aiCalls) $problems[] = ['ai-h', "More than half of this week's AI requests failed ($aiFails of $aiCalls)."];

/* ---- Recent problems in the cron log, and the watchdog's open alerts ---- */
$logLines = [];
$logFile = $priv . '/cron.log';
$logSize = is_file($logFile) ? (int)filesize($logFile) : 0;
$logTime = $logSize ? (int)filemtime($logFile) : null;
if ($logSize && is_readable($logFile) && ($fh = fopen($logFile, 'rb'))) {
    fseek($fh, max(0, $logSize - 256 * 1024));
    $tail = (string)stream_get_contents($fh); fclose($fh);
    foreach (array_reverse(preg_split('/\R/', $tail) ?: []) as $line) {
        if (preg_match('/fail|error|fatal|could not|not found|refused|STOP|problem\(s\)|warning/i', $line) && !preg_match('/^All healthy\.?$/', $line)) {
            $logLines[] = mb_strimwidth(trim($line), 0, 300, '…');
            if (count($logLines) >= 10) break;
        }
    }
}
$watch = is_file($priv . '/watchdog_state.json') ? (json_decode((string)@file_get_contents($priv . '/watchdog_state.json'), true) ?: []) : [];

/* ---- Work waiting ---- */
$waiting = [];
if ($dbOk) {
    $waiting = [
        ['schedule.php', 'Ended sessions with no outcome recorded', (int)$q("SELECT COUNT(*) FROM scheduled_sessions WHERE status = 'Scheduled' AND end_at < NOW()")],
        ['referrals.php', 'New referrals waiting more than 3 days', (int)$q("SELECT COUNT(*) FROM referrals WHERE status = 'New' AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)")],
        ['portal_requests.php', 'Client portal requests not done', (int)$q("SELECT COUNT(*) FROM portal_requests WHERE status <> 'Done'")],
        ['compliance.php', 'Guardian consent requests unsigned after 14 days', (int)$q('SELECT COUNT(*) FROM consent_forms WHERE signed_at IS NULL AND withdrawn_at IS NULL AND sent_at < DATE_SUB(NOW(), INTERVAL 14 DAY)')],
    ];
}

$latest = $dbOk ? (string)(setting('update_latest') ?? '') : '';
// A check from before this version was installed says nothing about now (4.19.1).
if ($latest !== '' && version_compare($latest, APP_VERSION, '<')) $latest = '';
$checked = $dbOk ? update_checked_words() : '';
$stateWord = ['fresh' => 'Working', 'waiting' => 'Waiting for its first run', 'stale' => 'Overdue', 'overdue' => 'Never ran', 'unreadable' => 'Cannot be checked', 'unchecked' => 'Not checked'];

page_header((form_errors() ? 'Error — ' : '') . 'System health', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>System health</h1>
<p>Checked <?= h($when($now)) ?>. <a href="system_health.php">Check again</a></p>

<section class="card" aria-labelledby="summary-h">
  <h2 id="summary-h">Summary</h2>
  <?php if (!$problems): ?>
  <p><strong>Everything is working.</strong> All <?= count($jobs) ?> scheduled jobs ran on time, and backups, email and storage are fine.</p>
  <?php else: ?>
  <p><strong><?= count($problems) === 1 ? '1 problem needs attention' : count($problems) . ' problems need attention' ?>:</strong></p>
  <ul>
    <?php foreach ($problems as [$anchor, $text]): ?><li><a href="#<?= h($anchor) ?>"><?= h($text) ?></a></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="jobs-h">
  <h2 id="jobs-h">Scheduled jobs</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="jobs-cap">
  <table>
    <caption id="jobs-cap">Scheduled jobs and their last good run</caption>
    <thead><tr><th scope="col">Job</th><th scope="col">Runs</th><th scope="col">Status</th><th scope="col">Last good run</th><th scope="col">Details</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <th scope="row"><?= h($j['name']) ?></th>
        <td><?= h($j['schedule']) ?></td>
        <td><?= $j['ok'] ? '' : '<strong>' ?><?= h($stateWord[$j['state']] ?? $j['state']) ?><?= $j['ok'] ? '' : '</strong>' ?></td>
        <td><?= $j['last'] ? h($when($j['last'])) . ' (' . h($ago($j['last'])) . ')' : 'none recorded' ?></td>
        <td><?= h($j['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">The schedule shown is the one the installer sets up; the server's crontab is the source of truth. A job counts as working when its last good run is within its limit (for example two hours for the watchdog, eight days for the off-site backup). The watchdog emails the backup address when a job falls behind.</p>
</section>

<section class="card" aria-labelledby="backups-h">
  <h2 id="backups-h">Backups</h2>
  <dl class="props">
    <dt>Newest database backup</dt><dd><?= $newestDump ? h($when($dumps[$newestDump])) . ', ' . h($bytes((float)filesize($newestDump))) : 'none found' ?></dd>
    <dt>Database backups kept</dt><dd><?= count($dumps) ?><?= $dumps ? ', ' . h($bytes($dumpTotal)) . ' in all; oldest ' . h($when(end($dumps))) : '' ?></dd>
    <dt>Newest uploaded-documents archive</dt><dd><?= $uploadsArchives ? h($when((int)filemtime($uploadsArchives[0]))) . ', ' . h($bytes((float)filesize($uploadsArchives[0]))) : 'none found' ?></dd>
    <dt>Last off-site backup emailed</dt><dd><?= $offsite ? h($when($offsite)) . ' (' . h($ago($offsite)) . ')' : 'none recorded since this check was added' ?></dd>
    <dt>Last backup check</dt><dd><?= $bcResult !== '' ? h($when($ts(setting('backup_check_at')))) . ': ' . h($bcResult) : 'not run yet (it runs with each Monday backup)' ?></dd>
  </dl>
  <form method="post" action="system_health.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="check_backup" value="1" class="btn btn-secondary btn-sm">Check the backup now</button>
  </form>
  <p>How to restore from a backup: <a href="help.php#restore">Restoring from a backup</a>.</p>
</section>

<section class="card" aria-labelledby="email-h">
  <h2 id="email-h">Email</h2>
  <dl class="props">
    <dt>Sending through</dt><dd><?= $mailDisabled ? 'Nothing: email is turned off on this copy (staging); messages are kept in mail_sink' : ($graphOn ? 'Microsoft Graph' : 'The server\'s own mail (Microsoft Graph is not configured)') ?></dd>
    <dt>Last email sent</dt><dd><?= $mailSent ? h($when($mailSent)) . ' (' . h($ago($mailSent)) . ')' : 'none recorded since this check was added' ?></dd>
    <dt>Last email that failed</dt><dd><?= $mailFailed ? h($when($mailFailed)) . ' (' . h($ago($mailFailed)) . ')' : 'none recorded' ?></dd>
    <dt>Microsoft Graph secret expires</dt><dd><?= $graphDays === null ? 'not recorded' : h(fmt_date((string)setting('graph_credential_expires'))) . ($graphDays > 0 ? " (in $graphDays days)" : ' (expired)') ?></dd>
  </dl>
  <form method="post" action="system_health.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="test_email" value="1" class="btn btn-secondary btn-sm">Send a test email to me</button>
  </form>
  <form method="post" action="system_health.php" class="inline-form"><?= csrf_field() ?>
    <label for="graph_expires">Graph secret expiry date</label>
    <input type="date" id="graph_expires" name="graph_expires" value="<?= h((string)(setting('graph_credential_expires') ?? '')) ?>">
    <button type="submit" name="save_graph_expiry" value="1" class="btn btn-secondary btn-sm">Save expiry date</button>
  </form>
  <?php if ($mailRecent): ?>
  <h3 id="maillog-h">Recent emails</h3>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="maillog-cap">
  <table><caption class="visually-hidden" id="maillog-cap">The last <?= count($mailRecent) ?> emails sent, without addresses or content</caption>
    <thead><tr><th scope="col">When</th><th scope="col">To</th><th scope="col">Sent through</th><th scope="col">Result</th></tr></thead>
    <tbody><?php foreach ($mailRecent as $ml): ?><tr><th scope="row"><?= h($when($ts($ml['sent_at']))) ?></th><td><?= h($ml['recipient_type']) ?><?= (int)$ml['with_attachment'] ? ', with attachment' : '' ?></td>
      <td><?= h(['graph' => 'Microsoft Graph', 'mail' => 'server mail', 'sink' => 'not sent (staging)'][$ml['via']] ?? $ml['via']) ?></td><td><?= (int)$ml['ok'] ? 'Sent' : '<strong>Failed</strong>' ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="storage-h">
  <h2 id="storage-h">Storage</h2>
  <dl class="props">
    <dt>Free disk space</dt><dd><?= $diskFree !== false && $diskTotal ? h($bytes((float)$diskFree)) . ' of ' . h($bytes((float)$diskTotal)) . " ($diskPct% free)" : 'could not be read' ?></dd>
    <dt>Uploaded documents</dt><dd><?= number_format($uploadCount) ?> file<?= $uploadCount === 1 ? '' : 's' ?>, <?= h($bytes($uploadBytes)) ?></dd>
    <dt>Cron log</dt><dd><?= $logSize ? h($bytes((float)$logSize)) . ', last written ' . h($when($logTime)) : 'not found' ?></dd>
  </dl>
</section>

<section class="card" aria-labelledby="db-h">
  <h2 id="db-h">Database and versions</h2>
  <dl class="props">
    <dt>Database</dt><dd><?= $dbOk ? 'Answering; ' . $dbTables . ' tables, ' . h($bytes($dbSize)) : '<strong>Not answering</strong>' ?></dd>
    <dt>Time zone</dt><dd><?= h((US_TIME_ZONES[date_default_timezone_get()] ?? date_default_timezone_get()) . ' (' . date('T') . ')') ?>: every time on this site is shown in it. Change it on <a href="settings.php">Settings</a>, Time zone.</dd>
    <dt>Case Management System</dt><dd>Version <?= h(APP_VERSION) ?><?= $latest !== '' ? (version_compare($latest, APP_VERSION, '>') ? '; version ' . h($latest) . ' is available' : '; up to date') . ($checked !== '' ? ' (checked ' . h($checked) . ')' : '') : '' ?></dd>
    <dt>PHP</dt><dd><?= h(PHP_VERSION) ?></dd>
    <dt>Database server</dt><dd><?= h($dbVersion ?: 'unknown') ?></dd>
  </dl>
</section>

<section class="card" aria-labelledby="security-h">
  <h2 id="security-h">Sign-in and security</h2>
  <dl class="props">
    <dt>Failed sign-ins in the last 24 hours</dt><dd><?= $failed24 ?><?= $failed24 ? ", from $failedIps address" . ($failedIps === 1 ? '' : 'es') : '' ?></dd>
    <dt>Active staff accounts</dt><dd><?= $staff ?>; <?= $withTotp ?> use an authenticator app (the rest get emailed sign-in codes)</dd>
    <dt>Staff signed in to the phone apps</dt><dd><?= $appSessions ?></dd>
    <?php [$vsOk, $vsWords] = virus_scan_health(); // 4.25.0 ?>
    <dt>Virus scanning of uploads</dt><dd><?= $vsOk ? '' : '<strong>' ?><?= h($vsWords) ?><?= $vsOk ? '' : '</strong>' ?></dd>
  </dl>
  <?php if ($keyCounts !== null): ?>
  <h3 id="forget-keys-h" tabindex="-1">Forget stored connections and keys</h3>
  <p>First try to copy the old meeting_tokens.key back into the private directory (readable only by its owner): then nothing is lost.
     If it is gone for good, this forgets what the server can no longer read:
     <?= (int)$keyCounts['seeds'] ?> authenticator app<?= $keyCounts['seeds'] === 1 ? '' : 's' ?> (turned off; each person is emailed and signs in with an emailed code until they set it up again),
     <?= (int)$keyCounts['meetings'] ?> meeting account<?= $keyCounts['meetings'] === 1 ? '' : 's' ?> and <?= $keyCounts['qbo'] ? 'the QuickBooks connection' : 'no QuickBooks connection' ?>.
     A new key file is then made. Passkeys, passwords and everything else are kept.</p>
  <form method="post" action="system_health.php#forget-keys-h" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['forget_confirm' => 'Type FORGET to confirm']) ?>
    <div class="field"><label for="forget_confirm">Type FORGET to confirm <?= required_mark() ?></label>
      <input type="text" id="forget_confirm" name="forget_confirm" autocomplete="off" required <?= field_aria('forget_confirm') ?>><?= field_error('forget_confirm') ?></div>
    <button type="submit" name="forget_keys" value="1" class="btn btn-danger">Forget stored connections and keys</button>
  </form>
  <?php endif; ?>
  <p><a href="audit.php">Audit log</a></p>
</section>

<section class="card" aria-labelledby="ai-h">
  <h2 id="ai-h">AI assistant</h2>
  <dl class="props">
    <dt>Status</dt><dd><?= $aiOn ? 'On' : 'Off' ?></dd>
    <dt>Requests in the last 7 days</dt><dd><?= $aiCalls ?><?= $aiFails ? ", $aiFails failed" : '' ?></dd>
    <dt>Last failed request</dt><dd><?= $aiLastFail ? h($when($aiLastFail)) : 'none' ?></dd>
  </dl>
</section>

<?php $pageErrors = function_exists('app_errors_since') ? array_reverse(app_errors_since(time() - 7 * 86400)) : []; // 4.19.0 ?>
<section class="card" aria-labelledby="errors-h">
  <h2 id="errors-h">Page and job errors in the last 7 days (<?= count($pageErrors) ?>)</h2>
  <?php if (!$pageErrors): ?><p>None.</p><?php else: ?>
  <p>Each is a page or scheduled job that failed. The watchdog emails the backup address within the hour. The kind of failure and where in the code are kept here; the detail is in the server's PHP error log.</p>
  <ol><?php foreach (array_slice(app_errors_summary($pageErrors), 0, 30) as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ol>
  <p>The newest was <?= h(date('F j \a\t g:i a', strtotime((string)$pageErrors[0]['at']))) ?>.</p>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="log-h">
  <h2 id="log-h">Recent problems in the cron log</h2>
  <?php if ($watch): ?>
  <p>The watchdog has open alerts: <?= h(implode(', ', array_map(fn($k, $t) => $k . ' (last emailed ' . $when((int)$t) . ')', array_keys($watch), $watch))) ?>.</p>
  <?php endif; ?>
  <?php if ($logLines): ?>
  <p>The newest first. The log has no times, so a line may be old; it repeats while a problem lasts.</p>
  <ol><?php foreach ($logLines as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ol>
  <?php else: ?>
  <p>None in the most recent part of the log.</p>
  <?php endif; ?>
</section>

<?php if ($waiting): ?>
<section class="card" aria-labelledby="work-h">
  <h2 id="work-h">Work waiting</h2>
  <ul>
    <?php foreach ($waiting as [$href, $label, $n]): ?><li><a href="<?= h($href) ?>"><?= h($label) ?></a>: <?= $n ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php page_footer();
