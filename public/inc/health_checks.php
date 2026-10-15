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
