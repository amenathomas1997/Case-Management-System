<?php
declare(strict_types=1);
// Health endpoint for an external monitor (UptimeRobot or similar, polling every
// 5 minutes and alerting on anything but HTTP 200). JSON only, no sign-in, no
// session: the database must answer and every scheduled job must have run within
// its limit (cms_cron_jobs() in inc/health_checks.php: the watchdog 2 hours, email
// filing 1 hour, the daily jobs 15 to 36 hours, the nightly backup 48 hours, the
// off-site backup 8 days; a job with no run yet gets that long from when it was first
// seen missing). System health (system_health.php) shows the same checks in full.
// Nothing here names a client, a path or a credential: the version, the check
// names, and the times of the last runs are all it says.
// Lightly rate-limited through login_attempts (the shared limiter ledger: a key
// without "@", success = 1, pruned after 30 days by daily_alerts.php).
require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/health_checks.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$checks = [];
$iso = fn(int $ts): string => date('c', $ts);

/* Database */
$dbOk = false;
try {
    db()->query('SELECT 1')->fetchColumn();
    $dbOk = true;
} catch (Throwable $e) {
    error_log('health.php: database check failed (' . get_class($e) . ' ' . $e->getCode() . ')');
}
$checks[] = ['name' => 'database', 'ok' => $dbOk, 'detail' => $dbOk ? 'The database answered.' : 'The database did not answer.'];

/* Rate limit (4.27.0: per address, not across all callers, so nobody else can
   make the monitor see 429s and raise a false alert): 60 requests a minute from
   one address is far more than a monitor needs; beyond it that address gets 429
   without touching anything else. */
if ($dbOk) {
    try {
        $ip = client_ip();
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = 'health' AND ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $rl->execute([$ip]);
        if ((int)$rl->fetchColumn() >= 60) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'Too many requests; poll this address at most a few times a minute.']);
            exit;
        }
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,'health',1)")->execute([$ip]);
    } catch (Throwable $e) { /* the limiter never decides the health answer */ }
}

/* Every scheduled job (inc/health_checks.php, shared with System health): a job's
   heartbeat, written at the end of a good run, or the newest backup file. */
$jobRows = health_job_rows($dbOk);
foreach ($jobRows as $j) $checks[] = ['name' => mb_strtolower($j['name']), 'ok' => $j['ok'], 'detail' => $j['detail']];
$jobLast = array_column($jobRows, 'last', 'key');
$lastDaily = $jobLast['daily_alerts'] !== null ? $iso($jobLast['daily_alerts']) : null;
$lastWatchdog = $jobLast['watchdog'] !== null ? $iso($jobLast['watchdog']) : null;
$lastBackup = $jobLast['backup'] !== null ? $iso($jobLast['backup']) : null;

$ok = !in_array(false, array_column($checks, 'ok'), true);
http_response_code($ok ? 200 : 503);
echo json_encode([
    'ok' => $ok,
    'version' => APP_VERSION,
    'db' => $dbOk ? 'ok' : 'error',
    'last_daily_alerts' => $lastDaily,
    'last_watchdog' => $lastWatchdog,
    'last_backup' => $lastBackup,
    'checks' => $checks,
    'checked_at' => $iso(time()),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
