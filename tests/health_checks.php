<?php
declare(strict_types=1);
// The heartbeat rule behind public/health.php (no database needed).
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
$src = file_get_contents(__DIR__ . '/../public/health.php');
check(str_contains($src, "_awaited_since") && str_contains($src, 'heartbeat_status('), 'health.php records when it first saw a heartbeat missing and applies the bounded rule');
echo "All health check rules passed.\n";
