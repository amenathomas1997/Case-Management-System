<?php
// Personal iCalendar subscription feed: sessions for one staff member,
// authorized by a private per-user token. Subscribe once in Outlook/Apple/
// Google Calendar and sessions appear and update automatically.
// Client names are shortened to first name + last initial, and an in-person
// session's location (often the client's home address) is left out, since
// calendar providers store the feed contents.
require __DIR__ . '/inc/core.php';

// This feed loads core.php, not bootstrap.php, so it installs its own last-resort
// handler: never print an error (a failed connection's trace can quote the
// database credentials), and log only the exception class, code and location.
ini_set('display_errors', '0');
set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf('Uncaught %s (code %s) at %s:%d', get_class($e), (string)$e->getCode(), $e->getFile(), $e->getLine()));
    if (headers_sent()) return; // part of the calendar already left: add nothing to it
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(503);
    header_remove('Content-Disposition');
    header('Cache-Control: no-store');
    header('Retry-After: 300');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The calendar feed is unavailable right now. Your calendar will try again later.';
});

/** A titled page for a dead feed address: a calendar app or a browser shows it, so it says what it is (2.4.2). */
function calfeed_not_found(): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Calendar feed not found</title></head>'
        . '<body><nav aria-label="Page navigation"><a href="index.php">Dashboard</a> · <a href="index.php#main-nav">Main navigation</a> · <a href="account.php">Back to My Account</a></nav><main aria-label="Main content"><h1>Calendar feed not found</h1><p>This calendar address is no longer valid. Sign in to the case management system and create a new calendar subscription from My Account.</p></main></body></html>';
}
$raw = $_GET['u'] ?? '';
$token = is_string($raw) ? preg_replace('/[^a-f0-9]/', '', $raw) : '';
$u = null;
if (strlen($token) === 48) {
    $s = db()->prepare('SELECT * FROM users WHERE cal_token = ? AND is_active = 1');
    $s->execute([$token]);
    $u = $s->fetch();
}
if (!$u) { http_response_code(404); exit(calfeed_not_found()); }
$access = resolve_perms($u);
if (!in_array('schedule', $access['perms'], true)) { http_response_code(404); exit(calfeed_not_found()); }
$scope = $access['scoped'] ? ' AND c.assigned_to = '.(int)$u['id'] : '';
header('Cache-Control: no-store');

$rows = db()->prepare("SELECT s.*, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
    WHERE s.user_id = ? AND s.start_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND s.status <> 'Cancelled'".$scope."
    ORDER BY s.start_at LIMIT 500");
$rows->execute([(int)$u['id']]);

$esc = fn(string $s) => addcslashes(preg_replace('/[\r\n]+/', ' ', $s), ",;\\");
$fmt = fn(string $dt) => gmdate('Ymd\THis\Z', (int)strtotime($dt));
$shortName = function (string $n): string {
    $parts = preg_split('/\s+/', trim($n)) ?: [];
    if (count($parts) < 2) return $n;
    return $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.';
};

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="sessions.ics"');
echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CaseManagement//CMS//EN\r\n"
    . "X-WR-CALNAME:" . $esc(brand('short') . ' sessions — ' . $u['name']) . "\r\n"
    . "X-PUBLISHED-TTL:PT1H\r\n";
foreach ($rows->fetchAll() as $s) {
    $remote = !empty($s['is_remote']);
    // Completion and a No Show are said in words: a check mark alone is dropped or
    // garbled by some braille tables and screen-reader punctuation settings. A No Show
    // stays in the feed (it was the instructor's time) but never reads as a confirmed
    // appointment: it is titled so, and marked cancelled.
    $noShow = $s['status'] === 'No Show';
    $title = ($s['status'] === 'Completed' ? 'Completed — ' : ($noShow ? 'No show — ' : '')) . ($remote ? 'Remote training: ' : 'Training: ') . $shortName($s['client_name']);
    // An in-person location is often the client's home address, so it stays in the
    // app: the event says where to find it instead.
    $loc = $remote ? ((string)($s['meeting_url'] ?? '') ?: 'Remote / telepractice')
        : (trim((string)($s['location'] ?? '')) !== '' ? 'In person (location on the schedule in the app)' : '');
    echo "BEGIN:VEVENT\r\n"
        . "UID:cms-cal-" . (int)$s['id'] . "@" . (parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost') . "\r\n"
        . "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
        . "DTSTART:" . $fmt($s['start_at']) . "\r\nDTEND:" . $fmt($s['end_at']) . "\r\n"
        . "SUMMARY:" . $esc($title) . "\r\n"
        // URL is a URI value, not TEXT: it must not be backslash-escaped (as in build_ics()).
        . ($remote && !empty($s['meeting_url']) ? "URL:" . preg_replace('/[\r\n]+/', '', (string)$s['meeting_url']) . "\r\n" : '')
        . ($loc !== '' ? "LOCATION:" . $esc($loc) . "\r\n" : '')
        // Session notes are intentionally omitted: calendar providers store the
        // feed, so clinical detail must not leave the app (matches the name
        // shortening above). Staff read notes in the app, not the calendar.
        . "STATUS:" . ($noShow ? 'CANCELLED' : 'CONFIRMED') . "\r\nEND:VEVENT\r\n";
}
echo "END:VCALENDAR\r\n";
