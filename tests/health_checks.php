<?php
declare(strict_types=1);
// The heartbeat rules behind public/health.php and System health (no database needed:
// settings and the private directory are stand-ins here).
$SETTINGS = [];
$PRIV = sys_get_temp_dir() . '/cms-health-test-' . getmypid();
function setting(string $k): ?string { return $GLOBALS['SETTINGS'][$k] ?? null; }
function set_setting(string $k, string $v): void { $GLOBALS['SETTINGS'][$k] = $v; }
function cfg(string $k) { return $k === 'upload_dir' ? $GLOBALS['PRIV'] . '/uploads' : null; }
require __DIR__ . '/../public/inc/health_checks.php';
function check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS: $name\n"; }
$now = 1_800_000_000; $day = 36 * 3600;
check(heartbeat_status($now - 3600, null, $day, $now)['state'] === 'fresh', 'A job that ran an hour ago is healthy');
$s = heartbeat_status($now - $day - 1, null, $day, $now);
check(!$s['ok'] && $s['state'] === 'stale', 'A job whose last run is older than its limit fails');
$s = heartbeat_status(null, null, $day, $now);
check($s['ok'] && $s['state'] === 'waiting' && $s['deadline'] === $now + $day, 'A job first seen missing now has one limit of grace');
check(heartbeat_status(null, $now - $day + 60, $day, $now)['ok'], 'A missing job is still healthy inside its grace period');
$s = heartbeat_status(null, $now - $day - 60, $day, $now);
check(!$s['ok'] && $s['state'] === 'overdue', 'A job that never records a run fails once its grace period ends');

// Every job, as health.php and System health judge them.
check(array_keys(cms_cron_jobs()) === ['watchdog', 'ingest_email', 'session_reminders', 'daily_alerts', 'monthly_reports', 'at_reminders', 'backup', 'weekly_offsite'],
    'Eight scheduled jobs are checked, the nightly backup among them');
foreach (cms_cron_jobs() as $key => [$name, $script]) {
    check(is_file(__DIR__ . '/../' . $script), "$name: $script exists");
    if ($key !== 'backup') check(str_contains((string)file_get_contents(__DIR__ . '/../' . $script), $key === 'watchdog' || $key === 'daily_alerts' ? "'heartbeat_$key'" : "record_heartbeat('$key')"), "$name records its heartbeat");
}
mkdir($PRIV . '/backups', 0700, true);
$byKey = fn(array $rows): array => array_column($rows, null, 'key');
// No database: every heartbeat job is unchecked, and fails.
$r = $byKey(health_job_rows(false, $now));
check(!$r['watchdog']['ok'] && $r['watchdog']['state'] === 'unchecked', 'Without the database the heartbeat jobs are not checked, and fail');
check($r['backup']['state'] === 'overdue' && !$r['backup']['ok'], 'An empty backups directory fails');
// Heartbeats: one fresh, one stale, the rest waiting from now.
$SETTINGS = ['heartbeat_watchdog' => date('c', $now - 600), 'heartbeat_daily_alerts' => date('c', $now - 40 * 3600)];
touch($PRIV . '/backups/cms_20260101_021500.sql.gz', $now - 3600);
$r = $byKey(health_job_rows(true, $now));
check($r['watchdog']['ok'] && $r['watchdog']['state'] === 'fresh' && str_starts_with($r['watchdog']['detail'], 'Last ran '), 'A recent heartbeat is working');
check(!$r['daily_alerts']['ok'] && $r['daily_alerts']['state'] === 'stale' && str_contains($r['daily_alerts']['detail'], 'cron/daily_alerts.php'), 'An old heartbeat is overdue and names the script');
check($r['ingest_email']['ok'] && $r['ingest_email']['state'] === 'waiting' && isset($SETTINGS['heartbeat_ingest_email_awaited_since']), 'A job with no run yet waits, and the wait is recorded');
check($r['backup']['ok'] && $r['backup']['last'] === $now - 3600, 'A backup an hour old is working');
// An hour and a minute later, email filing (limit one hour) has used up its grace.
$r = $byKey(health_job_rows(true, $now + 3660));
check(!$r['ingest_email']['ok'] && $r['ingest_email']['state'] === 'overdue', 'A job that never runs is overdue once its grace ends');
check($r['weekly_offsite']['ok'], 'The off-site backup (limit eight days) is still waiting');
array_map('unlink', glob($PRIV . '/backups/*')); rmdir($PRIV . '/backups');
$r = $byKey(health_job_rows(true, $now));
check($r['backup']['state'] === 'unreadable' && !$r['backup']['ok'], 'A backups directory that cannot be read fails');
@rmdir($PRIV);

$src = (string)file_get_contents(__DIR__ . '/../public/health.php');
check(str_contains($src, 'health_job_rows('), 'health.php applies the shared job checks');
$page = (string)file_get_contents(__DIR__ . '/../public/system_health.php');
check(str_contains($page, 'health_job_rows(') && str_contains($page, "require_perm('admin')"), 'System health uses the same checks and is for administrators only');
$wd = (string)file_get_contents(__DIR__ . '/../cron/watchdog.php');
check(str_contains($wd, 'health_job_rows(true)'), 'The watchdog alerts on a scheduled job that falls behind');
echo "All health check rules passed.\n";
