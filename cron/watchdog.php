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

$alerted = 0;
$alertTo = cfg('backup_email');
foreach ($problems as $key => $msg) {
    if (($state[$key] ?? 0) > time() - 6 * 3600) continue; // already alerted recently
    if (!$alertTo) { error_log("CMS watchdog (no backup_email configured): {$key} — {$msg}"); continue; }
    $ok = send_app_mail($alertTo,
        'CMS health alert: ' . $key,
        "The Case Management System watchdog found a problem:\n\n{$msg}\n\nChecked at " . date('Y-m-d H:i:s') . " server time.\nThis alert repeats at most every 6 hours while the problem persists.\n");
    if ($ok) { $state[$key] = time(); $alerted++; }
}
// Clear state for resolved issues
foreach (array_keys($state) as $key) {
    if (!isset($problems[$key])) unset($state[$key]);
}
file_put_contents($stateFile, json_encode($state), LOCK_EX);
echo $problems ? count($problems) . " problem(s), {$alerted} alert(s) sent: " . implode(' | ', $problems) . "\n" : "All healthy.\n";
