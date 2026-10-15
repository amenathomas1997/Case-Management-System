<?php
declare(strict_types=1);
// Health endpoint for an external monitor (UptimeRobot or similar, polling every
// 5 minutes and alerting on anything but HTTP 200). JSON only, no sign-in, no
// session: the database must answer, the watchdog (cron/watchdog.php, every 10
// minutes) must have run within 2 hours, the daily alerts (cron/daily_alerts.php)
// within 36 hours, and the newest database backup must be under 48 hours old.
// Nothing here names a client, a path or a credential: the version, the check
// names, and the times of the last runs are all it says.
// Lightly rate-limited through login_attempts (the shared limiter ledger: a key
// without "@", success = 1, pruned after 30 days by daily_alerts.php).
require __DIR__ . '/inc/core.php';

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

/* Rate limit: 60 requests a minute across all callers is far more than a monitor
   needs; beyond it the endpoint answers 429 without touching anything else. */
if ($dbOk) {
    try {
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = 'health' AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $rl->execute();
        if ((int)$rl->fetchColumn() >= 60) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'Too many requests; poll this address at most a few times a minute.']);
            exit;
        }
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,'health',1)")->execute([(string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    } catch (Throwable $e) { /* the limiter never decides the health answer */ }
}

/* Heartbeats written by the cron scripts (set_setting at the end of a good run) */
$heartbeat = function (string $key, int $maxAgeSeconds, string $what, string $cron) use ($dbOk, $iso, &$checks): ?string {
    $raw = $dbOk ? setting($key) : null;
    $ts = $raw !== null ? strtotime($raw) : false;
    if ($ts === false) {
        $checks[] = ['name' => $what, 'ok' => false, 'detail' => $dbOk ? "The {$what} ({$cron}) has not recorded a run yet." : 'Not checked: the database did not answer.'];
        return null;
    }
    $fresh = $ts >= time() - $maxAgeSeconds;
    $checks[] = ['name' => $what, 'ok' => $fresh, 'detail' => ($fresh ? "The {$what} last ran " : "The {$what} ({$cron}) has not run since ") . date('F j, Y g:i a', $ts) . '.'];
    return $iso($ts);
};
$lastDaily = $heartbeat('heartbeat_daily_alerts', 36 * 3600, 'daily alerts', 'cron/daily_alerts.php');
$lastWatchdog = $heartbeat('heartbeat_watchdog', 2 * 3600, 'watchdog', 'cron/watchdog.php');

/* Newest database backup in the private backups directory (beside the uploads) */
$lastBackup = null;
$backupDir = dirname((string)cfg('upload_dir')) . '/backups';
if (!is_dir($backupDir) || !is_readable($backupDir)) {
    $checks[] = ['name' => 'backup', 'ok' => false, 'detail' => 'The backups directory could not be read.'];
} else {
    $dumps = glob($backupDir . '/cms_*.sql.gz') ?: [];
    $newest = 0;
    foreach ($dumps as $f) $newest = max($newest, (int)filemtime($f));
    if ($newest === 0) {
        $checks[] = ['name' => 'backup', 'ok' => false, 'detail' => 'No database backup has been made yet (cron/backup.sh).'];
    } else {
        $lastBackup = $iso($newest);
        $fresh = $newest >= time() - 48 * 3600;
        $checks[] = ['name' => 'backup', 'ok' => $fresh, 'detail' => ($fresh ? 'The newest backup is from ' : 'The nightly backup has not run since ') . date('F j, Y g:i a', $newest) . '.'];
    }
}

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
