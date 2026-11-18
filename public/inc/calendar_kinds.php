<?php
declare(strict_types=1);
// The three kinds of calendar a staff member's sessions can go to (4.33.0), behind one shape
// that inc/calendar_sync.php drives without knowing which kind it has:
//   - microsoft: the person's own Microsoft connection (inc/meetings.php), a work account or a
//     personal Outlook.com one; Graph calendarView, events and transactionId;
//   - caldav: a CalDAV server (iCloud, Nextcloud, cPanel's Calendars and Contacts server on port
//     2080, Fastmail) with the person's user name and an app password; PROPFIND discovery, a
//     calendar-query REPORT for busy times, PUT and DELETE of .ics files;
//   - google: Google Calendar through a service account the person shared their calendar with;
//     a signed JWT for the token, freeBusy for busy times, events on the shared calendar.
// A CalDAV password or Google key is kept encrypted with the meeting_tokens.key file, as the
// meeting tokens are (meeting_seal), never in the database in the clear nor in its backups.
// Every request goes through meeting_http(), the one HTTP helper, which tests replace.

const CALENDAR_KINDS = ['microsoft' => 'Microsoft (Outlook)', 'caldav' => 'CalDAV (iCloud, Nextcloud, cPanel, Fastmail)', 'google' => 'Google Calendar'];
const CALENDAR_GOOGLE_API = 'https://www.googleapis.com/calendar/v3';
// Our own events on a Google calendar carry this private property, so one listing finds them.
const CALENDAR_GOOGLE_MARK = 'cms_calendar';

/** A kind's name in words. */
function calendar_kind_name(?string $kind): string {
    return CALENDAR_KINDS[$kind ?? ''] ?? 'calendar';
}

/** The CalDAV or Google calendar this person connected (My Account), or null; before migration 4.33.0, null. */
function calendar_account(int $userId): ?array {
    try {
        $q = db()->prepare('SELECT * FROM calendar_accounts WHERE user_id = ?');
        $q->execute([$userId]);
        return $q->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Which kind of calendar this person's sessions go to: what they chose (users.calendar_kind),
 * else the CalDAV or Google calendar they connected, else Microsoft when that is connected, else null.
 */
function calendar_kind(int $userId): ?string {
    $kind = null;
    try {
        $q = db()->prepare('SELECT calendar_kind FROM users WHERE id = ?');
        $q->execute([$userId]);
        $kind = (string)($q->fetchColumn() ?: '');
    } catch (PDOException $e) {}
    if (isset(CALENDAR_KINDS[$kind])) return $kind;
    $acc = calendar_account($userId);
    if ($acc && isset(CALENDAR_KINDS[(string)$acc['kind']])) return (string)$acc['kind'];
    return function_exists('meeting_connected') && meeting_app('microsoft') !== null && meeting_connected($userId, 'microsoft') ? 'microsoft' : null;
}

/**
 * The connection the sync drives, whatever its kind, or null when the person has none that works:
 * ['kind', 'user_id', 'label' (the account or calendar, in words), ...kind-specific values, with
 * the CalDAV password or Google key already opened]. A password or key this server cannot read
 * (the key file lost) gives null, and is logged.
 */
function calendar_connection(int $userId, ?string $kind = null): ?array {
    $kind ??= calendar_kind($userId);
    if ($kind === 'microsoft') {
        if (!function_exists('meeting_account') || !meeting_app('microsoft')) return null;
        $acc = meeting_account($userId, 'microsoft');
        if (!$acc || meeting_refused_at($acc) !== null) return null;
        return ['kind' => 'microsoft', 'user_id' => $userId, 'label' => (string)($acc['account_email'] ?? '') ?: 'the connected Microsoft account', 'granted_scope' => (string)($acc['granted_scope'] ?? '')];
    }
    if ($kind === null) return null;
    $acc = calendar_account($userId);
    if (!$acc || (string)$acc['kind'] !== $kind) return null;
    $secret = meeting_open((string)$acc['secret']);
    if ($secret === null) { error_log('calendar_connection: a stored calendar password or key could not be read (meeting_tokens.key)'); return null; }
    $out = ['kind' => $kind, 'user_id' => $userId, 'id' => (int)$acc['id'], 'label' => (string)($acc['label'] ?? ''), 'server_url' => (string)($acc['server_url'] ?? ''),
        'username' => (string)($acc['username'] ?? ''), 'calendar_url' => (string)($acc['calendar_url'] ?? ''), 'access_token' => (string)($acc['access_token'] ?? ''), 'expires_at' => (string)($acc['expires_at'] ?? '')];
    if ($kind === 'google') {
        $sa = json_decode($secret, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) return null;
        $out['service_account'] = $sa;
        $out['label'] = $out['label'] !== '' ? $out['label'] : $out['server_url'];
    } else {
        $out['password'] = $secret;
        $out['label'] = $out['label'] !== '' ? $out['label'] : $out['calendar_url'];
    }
    return $out;
}

/**
 * Keep a CalDAV or Google calendar for this person (replacing any other), and make it the kind
 * their sessions go to. $secret is the CalDAV app password, or the Google service account as JSON.
 */
function calendar_account_store(int $userId, string $kind, string $label, string $serverUrl, string $username, string $secret, string $calendarUrl): void {
    db()->prepare('INSERT INTO calendar_accounts (user_id, kind, label, server_url, username, secret, calendar_url, access_token, expires_at) VALUES (?,?,?,?,?,?,?,NULL,NULL)
        ON DUPLICATE KEY UPDATE kind = VALUES(kind), label = VALUES(label), server_url = VALUES(server_url), username = VALUES(username), secret = VALUES(secret), calendar_url = VALUES(calendar_url), access_token = NULL, expires_at = NULL')
        ->execute([$userId, $kind, mb_substr($label, 0, 190), mb_substr($serverUrl, 0, 500), mb_substr($username, 0, 190), meeting_seal($secret), mb_substr($calendarUrl, 0, 500)]);
    db()->prepare('UPDATE users SET calendar_kind = ? WHERE id = ?')->execute([$kind, $userId]);
}

/** Forget this person's CalDAV or Google calendar (the caller has taken the session events out first). */
function calendar_account_forget(int $userId): void {
    try { db()->prepare('DELETE FROM calendar_accounts WHERE user_id = ?')->execute([$userId]); } catch (PDOException $e) {}
    try { db()->prepare('UPDATE users SET calendar_kind = NULL WHERE id = ?')->execute([$userId]); } catch (PDOException $e) {}
}

/** The minute after the end of today, $days ahead, as a UTC Graph or RFC 3339 time. */
function calendar_utc(int $ts, bool $z = true): string {
    return gmdate('Y-m-d\TH:i:s', $ts) . ($z ? 'Z' : '');
}

/* ====================================================================================
   Microsoft Graph, through the person's Microsoft connection
   ==================================================================================== */

/**
 * A Graph call as this person: [status, decoded body]. The access token is the connection's cached
 * one, renewed by inc/meetings.php when it has expired. A token Microsoft refuses before it
 * expired (401: the person removed the app, or signed out everywhere) is renewed once and the
 * call repeated; a second 401 is returned as it is.
 */
function calendar_ms_request(int $userId, string $method, string $path, ?array $body = null, bool $retry = true): array {
    $token = meeting_access_token($userId, 'microsoft');
    if ($token === null) return [401, null];
    $url = str_starts_with($path, 'https://') ? $path : 'https://graph.microsoft.com/v1.0' . $path;
    // Times in UTC, and ids that stay the same when Outlook moves an event between folders.
    [$code, $data] = meeting_http($method, $url, ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Prefer: outlook.timezone="UTC"', 'Prefer: IdType="ImmutableId"'],
        $body !== null ? (string)json_encode($body) : null);
    if ($code === 401 && $retry) {
        if (meeting_access_token($userId, 'microsoft', true) === null) return [401, $data];
        return calendar_ms_request($userId, $method, $path, $body, false);
    }
    return [$code, $data];
}

/** A Graph UTC date ("2026-10-20T15:00:00.0000000") as a server-time timestamp. */
function calendar_ts(?array $g): ?int {
    $v = (string)($g['dateTime'] ?? '');
    if ($v === '') return null;
    $ts = strtotime(substr($v, 0, 19) . 'Z');
    return $ts === false ? null : $ts;
}

/** An Outlook event's start and end as timestamps; an all-day event covers the office's whole local days. */
function calendar_event_span(array $ev): array {
    if (!empty($ev['isAllDay'])) {
        $a = strtotime(substr((string)($ev['start']['dateTime'] ?? ''), 0, 10) . ' 00:00:00');
        $b = strtotime(substr((string)($ev['end']['dateTime'] ?? ''), 0, 10) . ' 00:00:00');
        return [$a ?: null, $b ?: null];
    }
    return [calendar_ts($ev['start'] ?? null), calendar_ts($ev['end'] ?? null)];
}

/** Whether a calendar event is one of this system's own email invitations (sent to instructors), by its iCalendar UID. */
function calendar_is_our_invite_uid(string $uid): bool {
    if ($uid === '') return false;
    $sample = ics_uid('x', 0); // prefix-x-0@host
    $prefix = substr($sample, 0, (int)strpos($sample, '-'));
    $host = substr($sample, (int)strrpos($sample, '@'));
    return str_starts_with($uid, $prefix . '-') && str_ends_with($uid, $host);
}

/** Whether an Outlook event is one of this system's own invitations. */
function calendar_is_our_invite(array $ev): bool {
    return calendar_is_our_invite_uid((string)($ev['iCalUId'] ?? ''));
}

/** What the person's Outlook calendar holds between two times: ['events' => [id => event], 'error' => ?string]. */
function calendar_ms_read(array $conn, int $from, int $to): array {
    $uid = (int)$conn['user_id'];
    $events = [];
    $next = '/me/calendarView?startDateTime=' . calendar_utc($from) . '&endDateTime=' . calendar_utc($to) . '&$select=id,iCalUId,start,end,showAs,isCancelled,isAllDay&$top=250';
    for ($page = 0; $next !== null && $page < 40; $page++) {
        [$code, $data] = calendar_ms_request($uid, 'GET', $next);
        if ($code === 401 || $code === 403) return ['events' => [], 'error' => 'Outlook refused calendar access: connect Microsoft again on My Account and allow the calendar.'];
        if ($code === 429) return ['events' => [], 'error' => 'Outlook asked us to slow down. It is tried again in ten minutes.'];
        if ($code < 200 || $code >= 300) return ['events' => [], 'error' => "Outlook could not be read (reply $code). It is tried again in ten minutes."];
        foreach ((array)($data['value'] ?? []) as $ev) {
            if (!is_string($ev['id'] ?? null)) continue;
            [$a, $b] = calendar_event_span($ev);
            $events[$ev['id']] = ['start' => $a, 'end' => $b, 'cancelled' => !empty($ev['isCancelled']),
                'free' => in_array((string)($ev['showAs'] ?? 'busy'), ['free', 'workingElsewhere'], true), 'invite' => calendar_is_our_invite($ev)];
        }
        $nl = (string)($data['@odata.nextLink'] ?? '');
        $next = str_starts_with($nl, 'https://graph.microsoft.com/v1.0/') ? $nl : null;
    }
    // A calendar too big to read whole is not judged: a missing page would look like deleted events.
    if ($next !== null) return ['events' => [], 'error' => 'The Outlook calendar has more events than can be read at once; it is tried again in ten minutes.'];
    return ['events' => $events, 'error' => null];
}

/* ====================================================================================
   CalDAV
   ==================================================================================== */

/** A request to the CalDAV server with the saved user name and password: [status, raw body, response headers]. */
function caldav_http(array $conn, string $method, string $url, array $headers = [], ?string $body = null): array {
    $headers[] = 'Authorization: Basic ' . base64_encode((string)$conn['username'] . ':' . (string)$conn['password']);
    [$code, , $raw, $respHeaders] = meeting_http($method, $url, $headers, $body);
    return [$code, $raw, $respHeaders];
}

/** Make an href absolute against the server address. */
function caldav_absolute(string $base, string $href): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    $p = parse_url($base);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . ltrim($href, '/');
}

/** A multistatus answer as [href => [property local name => value, or a list for resourcetype and href-valued ones]]. */
function caldav_parse_multistatus(string $xml): array {
    $out = [];
    $prev = libxml_use_internal_errors(true);
    $doc = $xml !== '' ? simplexml_load_string($xml) : false;
    libxml_use_internal_errors($prev);
    if ($doc === false) throw new RuntimeException('The calendar server sent an answer that could not be read.');
    foreach ($doc->xpath('//*[local-name()="response"]') ?: [] as $response) {
        $href = trim((string)($response->xpath('./*[local-name()="href"]')[0] ?? ''));
        $props = [];
        foreach ($response->xpath('./*[local-name()="propstat"]') ?: [] as $propstat) {
            $statusText = (string)($propstat->xpath('./*[local-name()="status"]')[0] ?? '');
            if ($statusText !== '' && !str_contains($statusText, ' 200 ')) continue;
            foreach ($propstat->xpath('./*[local-name()="prop"]/*') ?: [] as $prop) {
                $children = $prop->xpath('./*');
                if ($children) {
                    $list = [];
                    // <c:comp name="VEVENT"/> is kept as "comp:VEVENT"; hrefs as their text; other children by name.
                    foreach ($children as $child) $list[] = $child->getName() === 'href' ? trim((string)$child) : (isset($child['name']) ? $child->getName() . ':' . (string)$child['name'] : $child->getName());
                    $props[$prop->getName()] = $list;
                } else {
                    $props[$prop->getName()] = trim((string)$prop);
                }
            }
        }
        $out[$href] = $props;
    }
    return $out;
}

/** One PROPFIND, parsed. A refused user name and password is said in plain words. */
function caldav_propfind(array $conn, string $url, string $depth, string $props): array {
    $body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>' . $props . '</d:prop></d:propfind>';
    [$code, $xml] = caldav_http($conn, 'PROPFIND', $url, ['Depth: ' . $depth, 'Content-Type: application/xml; charset=utf-8'], $body);
    if ($code === 401 || $code === 403) throw new RuntimeException('The calendar server did not accept that user name and password.');
    if ($code === 0 || $code === 599) throw new RuntimeException('The calendar server did not answer.');
    if ($code < 200 || $code >= 300) throw new RuntimeException("The calendar server answered $url with status $code.");
    return caldav_parse_multistatus($xml);
}

/**
 * The calendars behind a server address, as [[url, name], ...]: the address as given, then
 * /.well-known/caldav, then cPanel's /calendars/<user>/ and /principals/<user>/ shapes, following
 * current-user-principal and calendar-home-set. Throws with the reason when none is found.
 */
function caldav_discover(array $conn): array {
    $base = rtrim((string)$conn['server_url'], '/');
    if (!preg_match('#^https?://[^/\s]+#i', $base)) throw new RuntimeException('Enter the calendar server address, starting with https://.');
    $user = (string)$conn['username'];
    $tries = [$base . '/', $base . '/.well-known/caldav', caldav_absolute($base, '/.well-known/caldav')];
    if ($user !== '') { $tries[] = caldav_absolute($base, '/calendars/' . rawurlencode($user) . '/'); $tries[] = caldav_absolute($base, '/principals/' . rawurlencode($user) . '/'); }
    $seen = [];
    $noAnswer = null;
    foreach (array_unique($tries) as $url) {
        try {
            $found = caldav_calendars_under($conn, $url, $seen);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'user name and password')) throw $e;
            $noAnswer ??= $e->getMessage();
            continue;
        }
        if ($found) return $found;
    }
    throw new RuntimeException('No calendars were found behind that address' . ($noAnswer !== null ? ' (' . $noAnswer . ')' : '') . '. For a cPanel calendar the address is usually https://your-domain:2080 and the user name is the email account; for iCloud it is https://caldav.icloud.com with an app-specific password.');
}

/** Calendars at a URL, following current-user-principal and calendar-home-set when the URL is not the home itself. */
function caldav_calendars_under(array $conn, string $url, array &$seen): array {
    if (isset($seen[$url]) || count($seen) > 8) return [];
    $seen[$url] = true;
    $props = caldav_propfind($conn, $url, '0', '<d:current-user-principal/><c:calendar-home-set/><d:resourcetype/>');
    $self = reset($props) ?: [];
    if (!empty($self['calendar-home-set'][0])) {
        $found = caldav_list_calendars($conn, caldav_absolute($url, (string)$self['calendar-home-set'][0]));
        if ($found) return $found;
    }
    if (!empty($self['current-user-principal'][0])) {
        $found = caldav_calendars_under($conn, caldav_absolute($url, (string)$self['current-user-principal'][0]), $seen);
        if ($found) return $found;
    }
    return caldav_list_calendars($conn, $url);
}

/** The event calendars in a calendar home: tasks-only calendars and the scheduling inbox and outbox are left out. */
function caldav_list_calendars(array $conn, string $home): array {
    $members = caldav_propfind($conn, rtrim($home, '/') . '/', '1', '<d:resourcetype/><d:displayname/><c:supported-calendar-component-set/>');
    $out = [];
    foreach ($members as $href => $props) {
        $types = (array)($props['resourcetype'] ?? []);
        if (!in_array('calendar', $types, true)) continue;
        $components = array_filter((array)($props['supported-calendar-component-set'] ?? []), fn($c) => str_starts_with((string)$c, 'comp:'));
        if ($components && !in_array('comp:VEVENT', $components, true)) continue;
        $out[] = [caldav_absolute($home, $href), (string)($props['displayname'] ?? '') ?: basename(rtrim($href, '/'))];
    }
    return $out;
}

/** The person's CalDAV calendar between two times: ['events' => [href => event], 'error' => ?string]. */
function caldav_read(array $conn, int $from, int $to): array {
    $url = (string)$conn['calendar_url'];
    if ($url === '') return ['events' => [], 'error' => 'No calendar was chosen: connect the calendar again on My Account.'];
    $body = '<?xml version="1.0" encoding="utf-8"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
        . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="' . gmdate('Ymd\THis\Z', $from) . '" end="' . gmdate('Ymd\THis\Z', $to) . '"/></c:comp-filter></c:comp-filter></c:filter>'
        . '</c:calendar-query>';
    [$code, $xml] = caldav_http($conn, 'REPORT', $url, ['Depth: 1', 'Content-Type: application/xml; charset=utf-8'], $body);
    if ($code === 401 || $code === 403) return ['events' => [], 'error' => 'The calendar server did not accept the saved user name and password: connect the calendar again on My Account.'];
    if ($code === 0 || $code === 599) return ['events' => [], 'error' => 'The calendar server did not answer. It is tried again in ten minutes.'];
    if ($code < 200 || $code >= 300) return ['events' => [], 'error' => "The calendar server could not be read (reply $code). It is tried again in ten minutes."];
    try { $items = caldav_parse_multistatus($xml); } catch (RuntimeException $e) { return ['events' => [], 'error' => $e->getMessage() . ' It is tried again in ten minutes.']; }
    $events = [];
    foreach ($items as $href => $props) {
        $ics = (string)($props['calendar-data'] ?? '');
        if ($ics === '') continue;
        $id = caldav_absolute($url, $href);
        $n = 0;
        foreach (ics_occurrences($ics, $from, $to) as $o) {
            // One .ics file is one event; a repeating one gives several busy times under the same file.
            $events[$n === 0 ? $id : $id . '#' . $n] = ['start' => $o['start'], 'end' => $o['end'], 'cancelled' => $o['cancelled'], 'free' => $o['free'], 'invite' => calendar_is_our_invite_uid($o['uid'])];
            $n++;
        }
    }
    return ['events' => $events, 'error' => null];
}

/** Text as an iCalendar value: backslashes, commas, semicolons and line breaks escaped. */
function ics_escape(string $s): string {
    return str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], str_replace("\r\n", "\n", $s));
}

/** Lines folded at 75 bytes with CRLF, as RFC 5545 asks. */
function ics_fold(string $line): string {
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        // Never split a multi-byte character.
        while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

/** A session's event as an .ics file for a CalDAV calendar, from the shared event description (calendar_payload). */
function caldav_ics(array $payload, string $uid): string {
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . ics_escape(trim((string)(brand('short') ?: cfg('name')))) . '//Case management//EN', 'BEGIN:VEVENT',
        'UID:' . $uid, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . gmdate('Ymd\THis\Z', strtotime(((string)$payload['start']['dateTime']) . 'Z')),
        'DTEND:' . gmdate('Ymd\THis\Z', strtotime(((string)$payload['end']['dateTime']) . 'Z')),
        'SUMMARY:' . ics_escape((string)$payload['subject']),
        'DESCRIPTION:' . ics_escape((string)($payload['body']['content'] ?? ''))];
    if (trim((string)($payload['location']['displayName'] ?? '')) !== '') $lines[] = 'LOCATION:' . ics_escape((string)$payload['location']['displayName']);
    // Private, so a shared calendar shows the time but not the client; busy; a reminder 15 minutes before.
    array_push($lines, 'CLASS:PRIVATE', 'TRANSP:OPAQUE', 'STATUS:CONFIRMED', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT15M', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR');
    return implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
}

/** Put a session's event in the CalDAV calendar: [href, error]. A new file is made only if none is there (If-None-Match: *). */
function caldav_put(array $conn, array $payload, ?string $href): array {
    $new = $href === null;
    if ($new) {
        $base = rtrim((string)$conn['calendar_url'], '/');
        if ($base === '') return [null, 'No calendar was chosen: connect the calendar again on My Account.'];
        $href = $base . '/session-' . bin2hex(random_bytes(8)) . '.ics';
    }
    $uid = basename($href, '.ics') . '@' . (parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost');
    [$code] = caldav_http($conn, 'PUT', $href, array_merge(['Content-Type: text/calendar; charset=utf-8'], $new ? ['If-None-Match: *'] : []), caldav_ics($payload, $uid));
    if ($code === 401 || $code === 403) return [null, 'The calendar server did not accept the saved user name and password: connect the calendar again on My Account.'];
    if (!$new && $code === 404) return [null, 'gone'];
    if ($code < 200 || $code >= 300) return [null, 'The calendar server refused the event (reply ' . $code . ').'];
    return [$href, null];
}

/** Remove a session's event from the CalDAV calendar: true when it is gone (404 counts: it was already gone). */
function caldav_delete(array $conn, string $href): bool {
    [$code] = caldav_http($conn, 'DELETE', $href);
    return ($code >= 200 && $code < 300) || $code === 404;
}

/* ---------- iCalendar reading, for a CalDAV calendar's busy times ---------- */

/** The VEVENTs of an .ics text as [['uid', 'start', 'end', 'cancelled', 'free', 'allday', 'rrule', 'exdates', 'recurrence_id', 'tz'], ...]. */
function ics_parse_events(string $ics): array {
    // Folded lines joined, then one property per line.
    $text = preg_replace("/\r\n[ \t]|\n[ \t]/", '', str_replace("\r\n", "\n", $ics)) ?? '';
    $events = [];
    $cur = null;
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line, "\r");
        if ($line === 'BEGIN:VEVENT') { $cur = ['uid' => '', 'start' => null, 'end' => null, 'duration' => null, 'cancelled' => false, 'free' => false, 'allday' => false, 'rrule' => '', 'exdates' => [], 'recurrence_id' => null, 'tz' => null]; continue; }
        if ($line === 'END:VEVENT') { if ($cur !== null) $events[] = $cur; $cur = null; continue; }
        if ($cur === null || !str_contains($line, ':')) continue;
        [$left, $value] = explode(':', $line, 2);
        $parts = explode(';', $left);
        $name = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $p) if (str_contains($p, '=')) { [$k, $v] = explode('=', $p, 2); $params[strtoupper($k)] = trim($v, '"'); }
        switch ($name) {
            case 'UID': $cur['uid'] = trim($value); break;
            case 'DTSTART':
                $cur['allday'] = ($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', trim($value)) === 1;
                $cur['tz'] = $params['TZID'] ?? null;
                $cur['start'] = ics_time(trim($value), $params);
                break;
            case 'DTEND': $cur['end'] = ics_time(trim($value), $params); break;
            case 'DURATION': $cur['duration'] = ics_duration(trim($value)); break;
            case 'RRULE': $cur['rrule'] = trim($value); break;
            case 'EXDATE': foreach (explode(',', $value) as $d) { $t = ics_time(trim($d), $params); if ($t !== null) $cur['exdates'][] = $t; } break;
            case 'RECURRENCE-ID': $cur['recurrence_id'] = ics_time(trim($value), $params); break;
            case 'STATUS': $cur['cancelled'] = strtoupper(trim($value)) === 'CANCELLED'; break;
            case 'TRANSP': $cur['free'] = strtoupper(trim($value)) === 'TRANSPARENT'; break;
        }
    }
    return $events;
}

/** An iCalendar date or date-time as a timestamp: Z is UTC, a TZID its zone, a floating time or a date the server's zone. */
function ics_time(string $value, array $params): ?int {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) return mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) ?: null;
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m)) return null;
    $zone = null;
    if ($m[7] === 'Z') $zone = new DateTimeZone('UTC');
    elseif (!empty($params['TZID'])) { try { $zone = new DateTimeZone((string)$params['TZID']); } catch (Throwable $e) { $zone = ics_windows_zone((string)$params['TZID']); } }
    try {
        $d = new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", $zone ?? new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable $e) {
        return null;
    }
    return $d->getTimestamp();
}

/** The common Windows time zone names some servers write in TZID, as PHP zones; null when unknown. */
function ics_windows_zone(string $name): ?DateTimeZone {
    $map = ['Eastern Standard Time' => 'America/New_York', 'Central Standard Time' => 'America/Chicago', 'Mountain Standard Time' => 'America/Denver', 'US Mountain Standard Time' => 'America/Phoenix',
        'Pacific Standard Time' => 'America/Los_Angeles', 'Alaskan Standard Time' => 'America/Anchorage', 'Hawaiian Standard Time' => 'Pacific/Honolulu', 'UTC' => 'UTC', 'GMT Standard Time' => 'Europe/London'];
    try { return isset($map[$name]) ? new DateTimeZone($map[$name]) : null; } catch (Throwable $e) { return null; }
}

/** An iCalendar duration (P1D, PT1H30M, P1W) in seconds; null when it cannot be read. */
function ics_duration(string $value): ?int {
    if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $value, $m)) return null;
    $s = (int)($m[2] ?? 0) * 604800 + (int)($m[3] ?? 0) * 86400 + (int)($m[4] ?? 0) * 3600 + (int)($m[5] ?? 0) * 60 + (int)($m[6] ?? 0);
    return ($m[1] ?? '') === '-' ? -$s : $s;
}

/**
 * The times an .ics file's events occupy between $from and $to, repeats expanded (daily, weekly
 * with BYDAY, monthly and yearly, with INTERVAL, COUNT, UNTIL and EXDATE; a changed single
 * occurrence replaces the repeat's). Each is ['uid', 'start', 'end', 'cancelled', 'free'].
 */
function ics_occurrences(string $ics, int $from, int $to): array {
    $events = ics_parse_events($ics);
    $overrides = [];
    foreach ($events as $e) if ($e['recurrence_id'] !== null) $overrides[$e['uid']][] = $e['recurrence_id'];
    $out = [];
    foreach ($events as $e) {
        if ($e['start'] === null) continue;
        $length = $e['end'] !== null ? $e['end'] - $e['start'] : ($e['duration'] ?? ($e['allday'] ? 86400 : 0));
        if ($length <= 0) $length = $e['allday'] ? 86400 : 0;
        $one = fn(int $start) => ['uid' => $e['uid'], 'start' => $start, 'end' => $start + $length, 'cancelled' => $e['cancelled'], 'free' => $e['free']];
        if ($e['rrule'] === '' || $e['recurrence_id'] !== null) {
            if ($e['start'] < $to && $e['start'] + $length > $from) $out[] = $one($e['start']);
            continue;
        }
        $skip = array_merge($e['exdates'], $overrides[$e['uid']] ?? []);
        foreach (ics_rrule_starts($e['rrule'], $e['start'], $e['tz'], $to) as $start) {
            if (in_array($start, $skip, true) || $start + $length <= $from || $start >= $to) continue;
            $out[] = $one($start);
        }
    }
    return $out;
}

/** The start times a repeat rule gives from the first, up to $to (at most 1000). Rules this cannot follow give the first time only. */
function ics_rrule_starts(string $rrule, int $first, ?string $tzid, int $to): array {
    $r = [];
    foreach (explode(';', $rrule) as $part) if (str_contains($part, '=')) { [$k, $v] = explode('=', $part, 2); $r[strtoupper($k)] = strtoupper($v); }
    $freq = $r['FREQ'] ?? '';
    $interval = max(1, (int)($r['INTERVAL'] ?? 1));
    $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null;
    $until = isset($r['UNTIL']) ? ics_time($r['UNTIL'], str_ends_with($r['UNTIL'], 'Z') ? [] : ['TZID' => (string)$tzid]) : null;
    $zone = null;
    if ($tzid !== null) { try { $zone = new DateTimeZone($tzid); } catch (Throwable $e) { $zone = ics_windows_zone($tzid); } }
    $zone ??= new DateTimeZone(date_default_timezone_get());
    $start = (new DateTimeImmutable('@' . $first))->setTimezone($zone);
    $out = [];
    $days = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
    if ($freq === 'WEEKLY' && !empty($r['BYDAY'])) {
        $wanted = [];
        foreach (explode(',', $r['BYDAY']) as $d) if (isset($days[$d])) $wanted[] = $days[$d];
        if (!$wanted) $wanted = [(int)$start->format('w')];
        sort($wanted);
        // Weeks start on Monday unless WKST says otherwise; each wanted day of each week, from the first start.
        $wkst = $days[$r['WKST'] ?? 'MO'] ?? 1;
        $back = ((int)$start->format('w') - $wkst + 7) % 7;
        $week = $start->modify("-{$back} days");
        for ($w = 0; $w < 1000 && count($out) < 1000; $w += $interval) {
            $base = $week->modify('+' . ($w * 7) . ' days');
            foreach ($wanted as $d) {
                $offset = ($d - $wkst + 7) % 7;
                $t = $base->modify("+{$offset} days")->getTimestamp();
                if ($t < $first) continue;
                if (($until !== null && $t > $until) || $t >= $to || ($count !== null && count($out) >= $count)) return $out;
                $out[] = $t;
            }
        }
        return $out;
    }
    $step = ['DAILY' => 'days', 'WEEKLY' => 'weeks', 'MONTHLY' => 'months', 'YEARLY' => 'years'][$freq] ?? null;
    if ($step === null || (isset($r['BYDAY']) && $freq !== 'WEEKLY') || isset($r['BYMONTHDAY']) || isset($r['BYSETPOS'])) return [$first];
    for ($i = 0; $i < 1000; $i++) {
        $t = $i === 0 ? $start : $start->modify('+' . ($i * $interval) . ' ' . $step);
        // A monthly rule on the 31st skips shorter months rather than sliding into the next one.
        if (($step === 'months' || $step === 'years') && $i > 0 && $t->format('d') !== $start->format('d')) continue;
        $ts = $t->getTimestamp();
        if (($until !== null && $ts > $until) || $ts >= $to || ($count !== null && count($out) >= $count)) break;
        $out[] = $ts;
    }
    return $out;
}

/* ====================================================================================
   Google Calendar, through a service account the person shared their calendar with
   ==================================================================================== */

/** The service account's details from a pasted key file: ['client_email', 'private_key'], or null with $why. */
function google_service_account(string $json, ?string &$why = null): ?array {
    $sa = json_decode(trim($json), true);
    if (!is_array($sa)) { $why = 'Paste the whole contents of the service account key file (it starts with { and names "client_email" and "private_key").'; return null; }
    $email = trim((string)($sa['client_email'] ?? ''));
    $key = (string)($sa['private_key'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $key === '') { $why = 'The key file must name the service account\'s client_email and its private_key.'; return null; }
    if (openssl_pkey_get_private($key) === false) { $why = 'The private key in that file could not be read. Download the key file again from Google Cloud and paste all of it.'; return null; }
    return ['client_email' => $email, 'private_key' => $key];
}

/** A signed JWT (RS256) asking Google for a calendar token for the service account, or null when the key cannot sign. */
function google_jwt(array $sa, int $now): ?string {
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $unsigned = $b64((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . $b64((string)json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/calendar', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    $key = openssl_pkey_get_private((string)$sa['private_key']);
    if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) return null;
    return $unsigned . '.' . $b64($signature);
}

/**
 * A working access token for the service account: the cached one (calendar_accounts.access_token,
 * sealed) while it has five minutes left, else a new one from a signed JWT. $force gets a new one
 * whatever the time (Google refused the cached one). Null, with $why, when Google will not give one.
 */
function google_access_token(array &$conn, bool $force = false, ?string &$why = null): ?string {
    if (!$force && $conn['access_token'] !== '' && $conn['expires_at'] !== '' && strtotime($conn['expires_at']) > time() + 300) {
        $t = meeting_open($conn['access_token']);
        if ($t !== null && $t !== '') return $t;
    }
    $jwt = google_jwt($conn['service_account'], time());
    if ($jwt === null) { $why = 'The Google service account key could not be used to sign in. Connect the Google calendar again with the key file.'; return null; }
    [$code, $data] = meeting_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
    if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
        $msg = is_array($data) ? (string)($data['error_description'] ?? $data['error'] ?? '') : '';
        $why = 'Google would not sign the service account in' . ($msg !== '' ? ' (' . mb_substr($msg, 0, 160) . ')' : ($code === 0 || $code === 599 ? ' (no answer)' : " (reply $code)")) . '.'
            . ($code === 400 || $code === 401 ? ' The key may have been deleted in Google Cloud: connect the Google calendar again with a new key file.' : '');
        return null;
    }
    $token = (string)$data['access_token'];
    $conn['access_token'] = meeting_seal($token);
    $conn['expires_at'] = date('Y-m-d H:i:s', time() + max(60, (int)($data['expires_in'] ?? 3600)) - 60);
    try { db()->prepare('UPDATE calendar_accounts SET access_token = ?, expires_at = ? WHERE id = ?')->execute([$conn['access_token'], $conn['expires_at'], (int)$conn['id']]); } catch (Throwable $e) {}
    return $token;
}

/**
 * A Google Calendar call: [status, decoded body, error in words or null]. A token Google refuses
 * (401) is renewed once and the call repeated. A 404 says the calendar is not shared with the
 * service account, the mistake people make.
 */
function google_request(array &$conn, string $method, string $path, ?array $json = null, bool $retry = true): array {
    $why = null;
    $token = google_access_token($conn, false, $why);
    if ($token === null) return [401, null, $why];
    [$code, $data] = meeting_http($method, CALENDAR_GOOGLE_API . $path, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $json !== null ? (string)json_encode($json) : null);
    if ($code === 401 && $retry) {
        if (google_access_token($conn, true, $why) === null) return [401, $data, $why];
        return google_request($conn, $method, $path, $json, false);
    }
    return [$code, $data, $code >= 200 && $code < 300 ? null : google_reason($conn, $code, $data)];
}

/** Why Google refused, in words. */
function google_reason(array $conn, int $code, $data): string {
    $msg = is_array($data) ? trim((string)($data['error']['message'] ?? '')) : '';
    $tail = $msg !== '' ? ' Google said: "' . mb_substr($msg, 0, 160) . '".' : '';
    if ($code === 404) return 'The Google calendar ' . $conn['server_url'] . ' was not found: the calendar is not shared with the service account ' . ($conn['service_account']['client_email'] ?? '') . ', or its address is wrong. In Google Calendar, open the calendar\'s settings, Share with specific people, and add that address with "Make changes to events".' . $tail;
    if ($code === 403) return 'Google refused: share the calendar with the service account ' . ($conn['service_account']['client_email'] ?? '') . ' with "Make changes to events", and check that the Google Calendar API is turned on for the project.' . $tail;
    if ($code === 401) return 'Google refused the service account key: connect the Google calendar again on My Account.' . $tail;
    if ($code === 429) return 'Google asked us to slow down. It is tried again in ten minutes.';
    if ($code === 0 || $code === 599 || $code >= 500) return 'Google did not answer just now. It is tried again in ten minutes.';
    return "Google Calendar could not be used (reply $code)." . $tail;
}

/** The address of the person's Google calendar, as a path part. */
function google_calendar_path(array $conn): string {
    return '/calendars/' . rawurlencode((string)$conn['server_url']);
}

/**
 * The person's Google calendar between two times: this system's own events (one listing, by their
 * private mark, to notice changes made there) and the person's busy times from freeBusy, as events
 * of their own, with our events' times taken out of them.
 */
function google_read(array &$conn, int $from, int $to): array {
    $events = [];
    $ours = [];
    $next = google_calendar_path($conn) . '/events?' . http_build_query(['privateExtendedProperty' => CALENDAR_GOOGLE_MARK . '=1', 'timeMin' => calendar_utc($from), 'timeMax' => calendar_utc($to), 'singleEvents' => 'true', 'showDeleted' => 'true', 'maxResults' => 250]);
    for ($page = 0; $next !== null && $page < 40; $page++) {
        [$code, $data, $why] = google_request($conn, 'GET', $next);
        if ($why !== null) return ['events' => [], 'error' => $why];
        foreach ((array)($data['items'] ?? []) as $ev) {
            if (!is_string($ev['id'] ?? null)) continue;
            [$a, $b] = google_event_span($ev);
            $events[$ev['id']] = ['start' => $a, 'end' => $b, 'cancelled' => (string)($ev['status'] ?? '') === 'cancelled', 'free' => (string)($ev['transparency'] ?? 'opaque') === 'transparent', 'invite' => false];
            if ($a !== null && $b !== null) $ours[] = [$a, $b];
        }
        $token = (string)($data['nextPageToken'] ?? '');
        $next = $token !== '' ? google_calendar_path($conn) . '/events?' . http_build_query(['privateExtendedProperty' => CALENDAR_GOOGLE_MARK . '=1', 'timeMin' => calendar_utc($from), 'timeMax' => calendar_utc($to), 'singleEvents' => 'true', 'showDeleted' => 'true', 'maxResults' => 250, 'pageToken' => $token]) : null;
    }
    if ($next !== null) return ['events' => [], 'error' => 'The Google calendar has more events than can be read at once; it is tried again in ten minutes.'];
    [$code, $data, $why] = google_request($conn, 'POST', '/freeBusy', ['timeMin' => calendar_utc($from), 'timeMax' => calendar_utc($to), 'items' => [['id' => (string)$conn['server_url']]]]);
    if ($why !== null) return ['events' => [], 'error' => $why];
    $entry = (array)($data['calendars'][(string)$conn['server_url']] ?? []);
    if (!empty($entry['errors'])) {
        $reason = (string)($entry['errors'][0]['reason'] ?? 'the calendar could not be read');
        return ['events' => [], 'error' => $reason === 'notFound'
            ? 'Google says notFound: the calendar is not shared with the service account ' . ($conn['service_account']['client_email'] ?? '') . ', or its address ' . $conn['server_url'] . ' is wrong. Share it with that address, with "Make changes to events".'
            : 'Google could not read the calendar\'s busy times (' . $reason . ').'];
    }
    $n = 0;
    foreach ((array)($entry['busy'] ?? []) as $p) {
        $a = strtotime((string)($p['start'] ?? '')); $b = strtotime((string)($p['end'] ?? ''));
        if ($a === false || $b === false || $b <= $a) continue;
        // The sessions this system put there are already known by their ids; the rest of each busy time is the person's own.
        foreach (calendar_subtract([[$a, $b]], $ours) as [$x, $y]) $events['busy-' . (++$n)] = ['start' => $x, 'end' => $y, 'cancelled' => false, 'free' => false, 'invite' => false];
    }
    return ['events' => $events, 'error' => null];
}

/** A Google event's start and end as timestamps; an all-day event covers its whole local days. */
function google_event_span(array $ev): array {
    $a = $ev['start'] ?? []; $b = $ev['end'] ?? [];
    if (isset($a['date'])) return [strtotime((string)$a['date'] . ' 00:00:00') ?: null, strtotime((string)($b['date'] ?? $a['date']) . ' 00:00:00') ?: null];
    $x = strtotime((string)($a['dateTime'] ?? '')); $y = strtotime((string)($b['dateTime'] ?? ''));
    return [$x === false ? null : $x, $y === false ? null : $y];
}

/** The parts of each [start, end] in $spans not covered by any of $holes. */
function calendar_subtract(array $spans, array $holes): array {
    foreach ($holes as [$h0, $h1]) {
        $next = [];
        foreach ($spans as [$a, $b]) {
            if ($h1 <= $a || $h0 >= $b) { $next[] = [$a, $b]; continue; }
            if ($h0 > $a) $next[] = [$a, $h0];
            if ($h1 < $b) $next[] = [$h1, $b];
        }
        $spans = $next;
    }
    return $spans;
}

/** A session's event as Google describes one, from the shared event description (calendar_payload). */
function google_event_body(array $payload, int $sessionId): array {
    $body = [
        'summary' => (string)$payload['subject'],
        'description' => (string)($payload['body']['content'] ?? ''),
        'start' => ['dateTime' => (string)$payload['start']['dateTime'] . 'Z', 'timeZone' => 'UTC'],
        'end' => ['dateTime' => (string)$payload['end']['dateTime'] . 'Z', 'timeZone' => 'UTC'],
        'transparency' => 'opaque',
        'visibility' => 'private',
        'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 15]]],
        'extendedProperties' => ['private' => [CALENDAR_GOOGLE_MARK => '1', 'cms_session' => (string)$sessionId]],
    ];
    if (trim((string)($payload['location']['displayName'] ?? '')) !== '') $body['location'] = (string)$payload['location']['displayName'];
    return $body;
}

/** The calendar's own name, for My Account and the connection test: [name, error]. */
function google_calendar_name(array &$conn): array {
    [$code, $data, $why] = google_request($conn, 'GET', google_calendar_path($conn));
    if ($why !== null) return ['', $why];
    return [(string)($data['summary'] ?? $conn['server_url']), null];
}

/* ====================================================================================
   The calendar, whichever kind it is
   ==================================================================================== */

/** What the person's calendar holds between two times: ['events' => [id => ['start', 'end', 'cancelled', 'free', 'invite']], 'error' => ?string]. */
function calendar_read(array &$conn, int $from, int $to): array {
    switch ($conn['kind']) {
        case 'microsoft': return calendar_ms_read($conn, $from, $to);
        case 'caldav': return caldav_read($conn, $from, $to);
        case 'google': return google_read($conn, $from, $to);
    }
    return ['events' => [], 'error' => 'No calendar is connected.'];
}

/**
 * Make a session's event in the calendar: [event id, error]. $tag makes Outlook's transactionId:
 * the same session with the same content always has the same one, so a lost reply never makes two.
 */
function calendar_event_create(array &$conn, array $s, array $payload, string $tag): array {
    switch ($conn['kind']) {
        case 'microsoft':
            [$code, $ev] = calendar_ms_request((int)$conn['user_id'], 'POST', '/me/events', $payload + ['transactionId' => $tag]);
            if ($code < 200 || $code >= 300 || !is_string($ev['id'] ?? null)) return [null, "Outlook did not add the event (reply $code)."];
            return [mb_substr((string)$ev['id'], 0, 255), null];
        case 'caldav':
            return caldav_put($conn, $payload, null);
        case 'google':
            [$code, $ev, $why] = google_request($conn, 'POST', google_calendar_path($conn) . '/events', google_event_body($payload, (int)$s['id']));
            if ($why !== null || !is_string($ev['id'] ?? null)) return [null, $why ?? 'Google did not add the event.'];
            return [mb_substr((string)$ev['id'], 0, 255), null];
    }
    return [null, 'No calendar is connected.'];
}

/** Change a session's event: null when done, 'gone' when the calendar no longer has it, else the error. */
function calendar_event_update(array &$conn, string $eventId, array $s, array $payload): ?string {
    switch ($conn['kind']) {
        case 'microsoft':
            [$code] = calendar_ms_request((int)$conn['user_id'], 'PATCH', '/me/events/' . rawurlencode($eventId), $payload);
            if ($code === 404) return 'gone';
            return $code >= 200 && $code < 300 ? null : "Outlook refused the change (reply $code).";
        case 'caldav':
            [, $err] = caldav_put($conn, $payload, $eventId);
            return $err;
        case 'google':
            [$code, , $why] = google_request($conn, 'PATCH', google_calendar_path($conn) . '/events/' . rawurlencode($eventId), google_event_body($payload, (int)$s['id']));
            if ($code === 404 || $code === 410) return 'gone';
            return $why;
    }
    return 'No calendar is connected.';
}

/** Remove a session's event: true when it is gone (an event already gone counts). */
function calendar_event_delete(array &$conn, string $eventId): bool {
    switch ($conn['kind']) {
        case 'microsoft':
            [$code] = calendar_ms_request((int)$conn['user_id'], 'DELETE', '/me/events/' . rawurlencode($eventId));
            return ($code >= 200 && $code < 300) || $code === 404;
        case 'caldav':
            return caldav_delete($conn, $eventId);
        case 'google':
            [$code] = google_request($conn, 'DELETE', google_calendar_path($conn) . '/events/' . rawurlencode($eventId));
            return ($code >= 200 && $code < 300) || $code === 404 || $code === 410;
    }
    return false;
}

/**
 * Try the person's calendar connection (My Account, Test the connection): the account or calendar
 * it reaches and how many busy times it holds in the next 7 days. Throws with the reason in words.
 */
function calendar_test(int $userId): string {
    $conn = calendar_connection($userId);
    if ($conn === null) throw new RuntimeException('No calendar is connected for you yet.');
    $from = time();
    $r = calendar_read($conn, $from, $from + 7 * 86400);
    if ($r['error'] !== null) throw new RuntimeException($r['error']);
    $busy = 0;
    foreach ($r['events'] as $ev) if (!$ev['cancelled'] && !$ev['free'] && $ev['start'] !== null && $ev['end'] !== null && $ev['end'] > $ev['start']) $busy++;
    $what = match ($conn['kind']) {
        'microsoft' => 'the Microsoft calendar of ' . $conn['label'],
        'caldav' => 'the calendar "' . $conn['label'] . '" at ' . (parse_url($conn['server_url'], PHP_URL_HOST) ?: $conn['server_url']),
        default => 'the Google calendar "' . $conn['label'] . '"',
    };
    return 'Connected to ' . $what . '. It has ' . $busy . ' busy ' . ($busy === 1 ? 'time' : 'times') . ' in the next 7 days.';
}
