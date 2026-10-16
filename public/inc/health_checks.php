<?php
declare(strict_types=1);
// The heartbeat rule health.php applies to each cron job, kept free of the database
// so tests/health_checks.php can check it directly.
//
// A job that has run is healthy while its last run is younger than $maxAge. A job that
// has never recorded a run gets one $maxAge of grace, counted from the first time
// health.php saw it missing ($waitingSince): a fresh install or the release that
// added the heartbeat is not an outage, but a job that never starts is one, and it
// cannot send its own warning.
//
// Returns ['ok' => bool, 'state' => 'fresh'|'stale'|'waiting'|'overdue', 'deadline' => ?int].
function heartbeat_status(?int $lastRun, ?int $waitingSince, int $maxAge, int $now): array
{
    if ($lastRun !== null) {
        $fresh = $lastRun >= $now - $maxAge;
        return ['ok' => $fresh, 'state' => $fresh ? 'fresh' : 'stale', 'deadline' => null];
    }
    $deadline = ($waitingSince ?? $now) + $maxAge;
    $waiting = $now <= $deadline;
    return ['ok' => $waiting, 'state' => $waiting ? 'waiting' : 'overdue', 'deadline' => $deadline];
}

/** The scheduled jobs, in the order System health lists them: key => [name, script,
 *  the schedule the installer sets up, the longest a good run may be ago]. Each job
 *  records heartbeat_<key> after a good run (record_heartbeat()); the nightly backup
 *  is judged by its newest dump file instead. */
function cms_cron_jobs(): array {
    return [
        'watchdog' => ['Watchdog', 'cron/watchdog.php', 'every 10 minutes', 2 * 3600],
        'ingest_email' => ['Email filing', 'cron/ingest_email.php', 'every 15 minutes', 3600],
        'session_reminders' => ['Session reminders', 'cron/session_reminders.php', '7:15 a.m. and 5:15 p.m.', 15 * 3600],
        'daily_alerts' => ['Daily alerts', 'cron/daily_alerts.php', '7:00 a.m.', 36 * 3600],
        'monthly_reports' => ['Progress reports', 'cron/monthly_reports.php', '7:30 a.m.', 36 * 3600],
        'at_reminders' => ['AT service reminders', 'cron/at_reminders.php', '8:00 a.m.', 36 * 3600],
        'backup' => ['Database backup', 'cron/backup.sh', '2:15 a.m.', 48 * 3600],
        'weekly_offsite' => ['Off-site backup', 'cron/weekly_offsite.php', 'Mondays at 2:45 a.m.', 8 * 86400],
    ];
}

/** The private directory (config, backups, cron.log): the parent of upload_dir. */
function health_private_dir(): string {
    return dirname((string)cfg('upload_dir'));
}

/** The database dumps in the backups directory, newest first: [path => mtime], or null
 *  when the directory cannot be read. */
function health_backup_dumps(): ?array {
    $dir = health_private_dir() . '/backups';
    if (!is_dir($dir) || !is_readable($dir)) return null;
    $dumps = [];
    foreach (glob($dir . '/cms_*.sql.gz') ?: [] as $f) $dumps[$f] = (int)filemtime($f);
    arsort($dumps);
    return $dumps;
}

/**
 * Every scheduled job judged by heartbeat_status(), one row each:
 * ['key','name','script','schedule','ok','state','last'(?int),'deadline'(?int),'detail'].
 * A job with no recorded run yet gets its own limit of grace from the first time this
 * was asked ($key_awaited_since), as health.php has done for the first two jobs since
 * 3.0.31. State 'unreadable' (the backups directory) and 'unchecked' (no database)
 * are failures too.
 */
function health_job_rows(bool $dbOk, ?int $now = null): array {
    $now ??= time();
    $rows = [];
    $when = fn(int $ts): string => date('F j, Y g:i a', $ts);
    foreach (cms_cron_jobs() as $key => [$name, $script, $schedule, $maxAge]) {
        $row = ['key' => $key, 'name' => $name, 'script' => $script, 'schedule' => $schedule, 'ok' => false, 'state' => 'unchecked', 'last' => null, 'deadline' => null, 'detail' => ''];
        if ($key === 'backup') {
            $dumps = health_backup_dumps();
            if ($dumps === null) { $rows[] = ['state' => 'unreadable', 'detail' => 'The backups directory could not be read.'] + $row; continue; }
            $last = $dumps ? reset($dumps) : null;
            if ($last === null) { $rows[] = ['state' => 'overdue', 'detail' => "No backup has been made yet. Check that its cron job ({$script}) is scheduled."] + $row; continue; }
            $s = heartbeat_status($last, null, $maxAge, $now);
        } else {
            if (!$dbOk) { $rows[] = ['detail' => 'Not checked: the database did not answer.'] + $row; continue; }
            $raw = setting('heartbeat_' . $key);
            $last = $raw !== null && strtotime($raw) !== false ? (int)strtotime($raw) : null;
            $since = null;
            if ($last === null) {
                $sinceRaw = setting('heartbeat_' . $key . '_awaited_since');
                $since = $sinceRaw !== null && strtotime($sinceRaw) !== false ? (int)strtotime($sinceRaw) : null;
                if ($since === null) {
                    $since = $now;
                    try { set_setting('heartbeat_' . $key . '_awaited_since', date('c', $since)); } catch (Throwable $e) { /* judged from now */ }
                }
            }
            $s = heartbeat_status($last, $since, $maxAge, $now);
        }
        // Said without the job's name, which the page and the JSON show beside it.
        $detail = match ($s['state']) {
            'fresh' => 'Last ran ' . $when($last) . '.',
            'stale' => 'Has not run since ' . $when($last) . ". Check its cron job ({$script}) and cron.log.",
            'waiting' => 'No run recorded yet; the first is due by ' . $when($s['deadline']) . '.',
            'overdue' => 'No run recorded; the first was due by ' . $when($s['deadline']) . ". Check that its cron job ({$script}) is scheduled.",
        };
        $rows[] = ['ok' => $s['ok'], 'state' => $s['state'], 'last' => $last, 'deadline' => $s['deadline'], 'detail' => $detail] + $row;
    }
    return $rows;
}
