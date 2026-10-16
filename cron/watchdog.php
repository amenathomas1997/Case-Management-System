<?php
declare(strict_types=1);
// Health watchdog: site up, database reachable, backups fresh. Alerts at most
// once per 6 hours per issue. Cron: */10 * * * *
if (PHP_SAPI !== 'cli') exit('CLI only.');

// Where the application's inc/ directory is: an optional cron_config.php beside
// this script (it returns the path) wins, then the production path, then the
// repository layout. Nothing needs editing here for a different install.
foreach ([
    is_file(__DIR__ . '/cron_config.php') ? (string)(require __DIR__ . '/cron_config.php') : '',
    '/home/cmsuser/public_html/casemanagement/inc',
    dirname(__DIR__) . '/public/inc',
] as $inc) {
    $inc = rtrim($inc, '/');
    if ($inc !== '' && is_file($inc . '/core.php')) { require $inc . '/core.php'; break; }
}
if (!function_exists('db')) {
    fwrite(STDERR, "CMS cron: the application's inc/core.php was not found. Create cron_config.php beside this script containing: <?php return '/full/path/to/your/web/docroot/inc';\n");
    exit(1);
}

// Private directory derived from config (upload_dir lives inside it), so
// white-label installs don't need this path edited.
$privateDir = dirname((string)cfg('upload_dir'));
$stateFile = $privateDir . '/watchdog_state.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$problems = [];

/* 1. Site responds */
$ch = curl_init(cfg('base_url') . '/login.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($code !== 200 || !is_string($body) || !str_contains($body, 'Sign in')) {
    $problems['site'] = "Site check failed: HTTP {$code} from " . cfg('base_url') . '/login.php';
}

/* 2. Database reachable (db() throws on failure) */
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    $problems['db'] = 'Database check failed: ' . $e->getMessage();
}

/* 3. Backup freshness (< 48h old) */
$dumps = glob($privateDir . '/backups/cms_*.sql.gz') ?: [];
rsort($dumps);
if (!$dumps || filemtime($dumps[0]) < time() - 48 * 3600) {
    $problems['backup'] = 'No database backup newer than 48 hours — check the nightly backup cron.';
}
// The uploaded documents are archived on the server every Sunday (3.0.53).
$archives = glob($privateDir . '/backups/uploads_*.tar.gz') ?: [];
rsort($archives);
if ($archives && filemtime($archives[0]) < time() - 9 * 86400) {
    $problems['uploads_archive'] = 'No archive of the uploaded documents newer than 9 days — check the Sunday part of the nightly backup.';
}

/* 4. Every other scheduled job (System health's checks): one that has fallen behind
   is alerted like the checks above, at most every 6 hours while it lasts. The
   watchdog itself and the backup are judged above; the daily alerts tell about a
   stalled watchdog. */
$healthFile = dirname((string)(new ReflectionFunction('db'))->getFileName()) . '/health_checks.php';
if (!isset($problems['db']) && is_file($healthFile)) {
    require_once $healthFile;
    try {
        foreach (health_job_rows(true) as $j) {
            if ($j['ok'] || in_array($j['key'], ['watchdog', 'backup'], true)) continue;
            $problems['job_' . $j['key']] = $j['name'] . ': ' . $j['detail'];
        }
    } catch (Throwable $e) { error_log('watchdog: the job checks failed (' . get_class($e) . ' ' . $e->getCode() . ')'); }
}

$alerted = 0;
$alertTo = cfg('backup_email');
foreach ($problems as $key => $msg) {
    if (($state[$key] ?? 0) > time() - 6 * 3600) continue; // already alerted recently
    if (!$alertTo) { error_log("CMS watchdog (no backup_email configured): {$key} — {$msg}"); continue; }
    $ok = send_app_mail($alertTo,
        'CMS health alert: ' . (str_starts_with($key, 'job_') ? 'scheduled job' : $key),
        "The Case Management System watchdog found a problem:\n\n{$msg}\n\nChecked at " . date('Y-m-d H:i:s') . " server time.\nThis alert repeats at most every 6 hours while the problem persists.\n");
    if ($ok) { $state[$key] = time(); $alerted++; }
}
// Clear state for resolved issues
foreach (array_keys($state) as $key) {
    if (!isset($problems[$key])) unset($state[$key]);
}
file_put_contents($stateFile, json_encode($state), LOCK_EX);
// Heartbeat for health.php and the daily alerts: written only when this run
// completed and the database answered (an unreachable database leaves it stale,
// which is the point). Never a reason for the watchdog itself to fail.
try { if (!isset($problems['db'])) set_setting('heartbeat_watchdog', date('c')); } catch (Throwable $e) {}
echo $problems ? count($problems) . " problem(s), {$alerted} alert(s) sent: " . implode(' | ', $problems) . "\n" : "All healthy.\n";
