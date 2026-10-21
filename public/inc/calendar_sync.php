<?php
declare(strict_types=1);
// Two-way Outlook calendar sync (4.20.0, owner request 2026-10-17), through each staff member's own
// Microsoft connection (inc/meetings.php, with calendar access added to its scope). Each person who
// turns it on (My Account):
//   - gets their sessions in their Outlook calendar: booked sessions are added, moved ones moved,
//     cancelled ones removed (calendar_links remembers which event is which session);
//   - has their Outlook busy times kept (calendar_busy), so the system never offers, books or moves
//     a session over them;
//   - is told when they move or delete a session's event in Outlook, to change the session to match
//     (a session is never moved or cancelled from Outlook alone: it carries hours and authorizations).
// The watchdog runs calendar_sync_all() every ten minutes; a booking, move or cancellation also
// pushes that one session at once (calendar_push_session).

const CALENDAR_DAYS_BACK = 1;
const CALENDAR_DAYS_AHEAD = 120;
const CALENDAR_BUSY_DAYS = 60;

/** Whether the organisation has set up the Microsoft connection the sync uses. */
function calendar_available(): bool {
    return function_exists('meeting_app') && meeting_app('microsoft') !== null;
}

/** Why this person's sessions cannot go to Outlook right now, or null when they can. */
function calendar_problem(int $userId): ?string {
    if (!calendar_available()) return 'An administrator has not set up the Microsoft connection (Settings, Online meetings).';
    $q = db()->prepare('SELECT calendar_sync FROM users WHERE id = ? AND is_active = 1');
    $q->execute([$userId]);
    if (!(int)$q->fetchColumn()) return 'Outlook calendar sync is off for this person (My Account).';
    $acc = meeting_account($userId, 'microsoft');
    if (!$acc) return 'Microsoft is not connected for this person (My Account).';
    if (meeting_refused_at($acc) !== null) return 'The Microsoft connection has ended: connect Microsoft again on My Account.';
    if (!str_contains(' ' . strtolower((string)($acc['granted_scope'] ?? '')) . ' ', ' calendars.readwrite ')) return 'The Microsoft connection does not include the calendar: connect Microsoft again on My Account and allow the calendar.';
    return null;
}

/** A Graph call with this person's token: [status, decoded body]. */
function calendar_graph(string $token, string $method, string $path, ?array $body = null, array $extra = []): array {
    return meeting_http($method, 'https://graph.microsoft.com/v1.0' . $path,
        // Times in UTC, and ids that stay the same when Outlook moves an event between folders.
        array_merge(['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Prefer: outlook.timezone="UTC"', 'Prefer: IdType="ImmutableId"'], $extra),
        $body !== null ? (string)json_encode($body) : null);
}

/** The event for a session: the client's first name only (or nothing, by setting), the time, the place and the link. */
function calendar_payload(array $s): array {
    $org = trim((string)(brand('short') ?: cfg('name')));
    $first = trim((string)($s['client_name'] ?? ''));
    $first = $first !== '' ? explode(' ', preg_replace('/\s+/', ' ', $first) ?? $first)[0] : '';
    $named = (($GLOBALS['CONFIG']['meet']['calendar_first_name'] ?? 'yes') ?: 'yes') !== 'no';
    $subject = $named && $first !== '' ? "Session with $first" : 'Client session';
    $link = rtrim((string)cfg('base_url'), '/') . '/schedule.php#s-' . (int)$s['id'];
    $where = (int)$s['is_remote'] ? 'Online' . (!empty($s['meeting_url']) ? ': ' . $s['meeting_url'] : '') : trim((string)($s['location'] ?? ''));
    return [
        'subject' => mb_substr("$org: $subject", 0, 250),
        'body' => ['contentType' => 'text', 'content' => "Booked in $org. Details, check-in and notes: $link"],
        'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s', strtotime((string)$s['start_at'])), 'timeZone' => 'UTC'],
        'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s', strtotime((string)$s['end_at'])), 'timeZone' => 'UTC'],
        'location' => ['displayName' => mb_substr($where, 0, 250)],
        'showAs' => 'busy',
        // Colleagues and delegates who can see the calendar see "Private", not the client's name or address.
        'sensitivity' => 'private',
        'isReminderOn' => true,
        'reminderMinutesBeforeStart' => 15,
    ];
}

/** A Graph UTC date ("2026-10-20T15:00:00.0000000") as a server-time timestamp. */
function calendar_ts(?array $g): ?int {
    $v = (string)($g['dateTime'] ?? '');
    if ($v === '') return null;
    $ts = strtotime(substr($v, 0, 19) . 'Z');
    return $ts === false ? null : $ts;
}

/** The sessions a person's calendar should hold: booked, from yesterday to four months ahead. */
function calendar_sessions(int $userId): array {
    $q = db()->prepare("SELECT s.*, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
        WHERE s.user_id = ? AND s.status = 'Scheduled' AND s.start_at >= DATE_SUB(NOW(), INTERVAL " . CALENDAR_DAYS_BACK . " DAY)
          AND s.start_at < DATE_ADD(NOW(), INTERVAL " . CALENDAR_DAYS_AHEAD . " DAY)");
    $q->execute([$userId]);
    $out = [];
    foreach ($q->fetchAll() as $s) $out[(int)$s['id']] = $s;
    return $out;
}

/** Add or update one session's event. Returns null, or what went wrong. */
function calendar_put(string $token, int $userId, array $s, ?array $link): ?string {
    $payload = calendar_payload($s);
    $hash = sha1((string)json_encode($payload));
    $recreate = false;
    // An event the person deleted in Outlook sits in Deleted Items: a changed session gets a new one.
    if ($link && $link['outlook_change'] === 'deleted') {
        if ($link['pushed_hash'] === $hash) return null; // unchanged: left deleted, as the person chose
        db()->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$link['id']]);
        $link = null; $recreate = true;
    }
    if ($link && $link['pushed_hash'] === $hash) return null;
    if ($link) {
        [$code] = calendar_graph($token, 'PATCH', '/me/events/' . rawurlencode((string)$link['event_id']), $payload);
        if ($code === 404) { db()->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$link['id']]); $link = null; }
        elseif ($code < 200 || $code >= 300) return "Outlook refused the change (reply $code).";
    }
    if (!$link) {
        // transactionId: Outlook makes the event once, even if a lost reply makes us send it again.
        // The same session with the same content always has the same id, so the ten-minute sync and an immediate push never make two.
        [$code, $ev] = calendar_graph($token, 'POST', '/me/events', $payload + ['transactionId' => 'cms-' . (int)$s['id'] . '-' . substr($hash, 0, 12) . ($recreate ? '-' . time() : '')]);
        if ($code < 200 || $code >= 300 || !is_string($ev['id'] ?? null)) return "Outlook did not add the event (reply $code).";
        db()->prepare('INSERT INTO calendar_links (session_id, user_id, event_id, pushed_hash, event_start, event_end) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), pushed_hash = VALUES(pushed_hash), event_start = VALUES(event_start), event_end = VALUES(event_end), outlook_change = NULL, updated_at = NOW()')
            ->execute([(int)$s['id'], $userId, mb_substr((string)$ev['id'], 0, 255), $hash, $s['start_at'], $s['end_at']]);
        return null;
    }
    db()->prepare('UPDATE calendar_links SET pushed_hash = ?, event_start = ?, event_end = ?, outlook_change = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$hash, $s['start_at'], $s['end_at'], (int)$link['id']]);
    return null;
}

/** Remove a session's event (cancelled, reassigned, or the person turned sync off). */
function calendar_remove(string $token, array $link): bool {
    [$code] = calendar_graph($token, 'DELETE', '/me/events/' . rawurlencode((string)$link['event_id']));
    if (($code >= 200 && $code < 300) || $code === 404) { db()->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$link['id']]); return true; }
    return false;
}

/** Whether an Outlook event is one of this system's own invitations (the email invites sent to instructors), by its iCalendar UID. */
function calendar_is_our_invite(array $ev): bool {
    $uid = (string)($ev['iCalUId'] ?? '');
    if ($uid === '') return false;
    $sample = ics_uid('x', 0); // prefix-x-0@host
    $prefix = substr($sample, 0, (int)strpos($sample, '-'));
    $host = substr($sample, (int)strrpos($sample, '@'));
    return str_starts_with($uid, $prefix . '-') && str_ends_with($uid, $host);
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

/**
 * Bring one person's Outlook calendar and the system together. Returns ['pushed' => n, 'removed' => n,
 * 'noticed' => n, 'busy' => n, 'error' => ?string]. With $deadline, stops pushing when it passes (the
 * next run carries on). A failed event does not stop the others; the first problem is reported.
 */
function calendar_sync_user(int $userId, ?int $deadline = null): array {
    $out = ['pushed' => 0, 'removed' => 0, 'noticed' => 0, 'busy' => 0, 'error' => null];
    $setError = function (?string $why) use ($userId, &$out): array {
        $out['error'] = $why;
        $was = db()->prepare('SELECT calendar_error FROM users WHERE id = ?');
        $was->execute([$userId]);
        $before = $was->fetchColumn();
        db()->prepare('UPDATE users SET calendar_error = ?, calendar_synced_at = IF(? IS NULL, NOW(), calendar_synced_at) WHERE id = ?')->execute([$why !== null ? mb_substr($why, 0, 255) : null, $why, $userId]);
        // Told once when it starts failing, so new sessions missing from Outlook are not a surprise.
        if ($why !== null && ($before === null || $before === false)) notify_user($userId, 'Your sessions are not reaching your Outlook calendar: ' . $why, 'account.php#calendar-h', true);
        return $out;
    };
    if (($why = calendar_problem($userId)) !== null) return $setError($why);
    $token = meeting_access_token($userId, 'microsoft');
    if ($token === null) return $setError('The Microsoft connection has expired: connect Microsoft again on My Account.');
    $sessions = calendar_sessions($userId);
    $lq = db()->prepare('SELECT * FROM calendar_links WHERE user_id = ?');
    $lq->execute([$userId]);
    $links = [];
    foreach ($lq->fetchAll() as $l) $links[(int)$l['session_id']] = $l;
    // What Outlook holds now, from yesterday on: our events (to notice changes made there) and the person's own (busy times).
    $from = gmdate('Y-m-d\TH:i:s\Z', time() - CALENDAR_DAYS_BACK * 86400);
    $to = gmdate('Y-m-d\TH:i:s\Z', time() + CALENDAR_DAYS_AHEAD * 86400);
    $events = []; $next = '/me/calendarView?startDateTime=' . $from . '&endDateTime=' . $to . '&$select=id,iCalUId,start,end,showAs,isCancelled,isAllDay&$top=250';
    for ($page = 0; $next !== null && $page < 40; $page++) {
        [$code, $data] = calendar_graph($token, 'GET', $next);
        if ($code === 401 || $code === 403) return $setError('Outlook refused calendar access: connect Microsoft again on My Account and allow the calendar.');
        if ($code === 429) return $setError('Outlook asked us to slow down. It is tried again in ten minutes.');
        if ($code < 200 || $code >= 300) return $setError("Outlook could not be read (reply $code). It is tried again in ten minutes.");
        foreach ((array)($data['value'] ?? []) as $ev) if (is_string($ev['id'] ?? null)) $events[$ev['id']] = $ev;
        $nl = (string)($data['@odata.nextLink'] ?? '');
        $next = str_starts_with($nl, 'https://graph.microsoft.com/v1.0/') ? substr($nl, strlen('https://graph.microsoft.com/v1.0')) : null;
    }
    // A calendar too big to read whole is not judged: a missing page would look like deleted events.
    if ($next !== null) return $setError('The Outlook calendar has more events than can be read at once; it is tried again in ten minutes.');
    $ours = array_flip(array_map(fn($l) => (string)$l['event_id'], $links));
    // 1. Changes made in Outlook to our events: told once, never applied by themselves.
    // 4.28.0: the link and the session are read again before telling anyone. A move or cancellation
    // made on the website while this run read Outlook (calendar_push_session changed the event and its
    // link) is not a change made in Outlook, so the person is not told they made it there.
    $freshLink = db()->prepare('SELECT * FROM calendar_links WHERE id = ?');
    $freshSession = db()->prepare('SELECT status, user_id, start_at FROM scheduled_sessions WHERE id = ?');
    $stillOurs = function (array $l, int $sid) use ($freshLink, $freshSession, $userId): ?array {
        $freshLink->execute([(int)$l['id']]);
        $now = $freshLink->fetch();
        if (!$now || (string)$now['event_id'] !== (string)$l['event_id'] || $now['outlook_change'] !== null) return null;
        $freshSession->execute([$sid]);
        $row = $freshSession->fetch();
        if (!$row || $row['status'] !== 'Scheduled' || (int)$row['user_id'] !== $userId) return null;
        return $now + ['session_start' => (string)$row['start_at']];
    };
    foreach ($links as $sid => $l) {
        if (!isset($sessions[$sid]) || $l['outlook_change'] !== null) continue;
        $s = $sessions[$sid];
        if (strtotime((string)$s['start_at']) < time()) continue; // a session under way or done is left alone
        $first = explode(' ', trim((string)$s['client_name']))[0] ?: 'a client';
        if (!isset($events[$l['event_id']]) || !empty($events[$l['event_id']]['isCancelled'])) {
            if ($stillOurs($l, (int)$sid) === null) continue; // cancelled, moved to someone else, or re-made here meanwhile
            db()->prepare("UPDATE calendar_links SET outlook_change = 'deleted' WHERE id = ?")->execute([(int)$l['id']]);
            notify_user($userId, "You removed the session with $first on " . date('D, M j g:i A', strtotime((string)$s['start_at'])) . ' from Outlook, but it is still booked. Cancel it on the Schedule if it is not happening.', 'schedule.php#s-' . $sid);
            $out['noticed']++;
            continue;
        }
        $es = calendar_ts($events[$l['event_id']]['start'] ?? null);
        if ($es !== null && abs($es - strtotime((string)$l['event_start'])) >= 60) {
            $now = $stillOurs($l, (int)$sid);
            // The event is where the website last put it, or where the session now is: nothing was moved in Outlook.
            if ($now === null || abs($es - strtotime((string)$now['event_start'])) < 60 || abs($es - strtotime($now['session_start'])) < 60) continue;
            $s['start_at'] = $now['session_start'];
            db()->prepare("UPDATE calendar_links SET outlook_change = 'moved' WHERE id = ?")->execute([(int)$l['id']]);
            notify_user($userId, "You moved the session with $first to " . date('D, M j g:i A', $es) . ' in Outlook. It is still ' . date('D, M j g:i A', strtotime((string)$s['start_at'])) . ' here: move it on the Schedule if the new time is right.', 'schedule.php#s-' . $sid);
            $out['noticed']++;
        }
    }
    // 2. Sessions to add or update; events of sessions no longer booked (or not this person's) to remove.
    $failed = null;
    foreach ($sessions as $sid => $s) {
        if ($deadline !== null && time() > $deadline) break;
        $l = $links[$sid] ?? null;
        // A session moved in Outlook is left as the person moved it until the session itself changes here.
        if ($l && $l['outlook_change'] === 'moved' && $l['pushed_hash'] === sha1((string)json_encode(calendar_payload($s)))) continue;
        $err = calendar_put($token, $userId, $s, $l);
        if ($err !== null) { $failed ??= $err; continue; }
        $out['pushed']++;
    }
    foreach ($links as $sid => $l) {
        if (isset($sessions[$sid]) || ($deadline !== null && time() > $deadline)) continue;
        $st = db()->prepare('SELECT status, user_id, start_at FROM scheduled_sessions WHERE id = ?');
        $st->execute([$sid]);
        $row = $st->fetch();
        // A completed or past session keeps its event (it happened); a cancelled, missed or reassigned one loses it.
        if ($row && $row['status'] === 'Completed') continue;
        if ($row && $row['status'] === 'Scheduled' && (int)$row['user_id'] === $userId) continue; // outside the window
        calendar_remove($token, $l);
        $out['removed']++;
    }
    // 3. The person's own busy times, for booking around them (refreshed whenever Outlook could be read).
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM calendar_busy WHERE user_id = ?')->execute([$userId]);
        $ins = db()->prepare('INSERT INTO calendar_busy (user_id, start_at, end_at) VALUES (?,?,?)');
        foreach ($events as $id => $ev) {
            if (isset($ours[$id]) || calendar_is_our_invite($ev) || !empty($ev['isCancelled']) || in_array((string)($ev['showAs'] ?? 'busy'), ['free', 'workingElsewhere'], true)) continue;
            [$a, $b] = calendar_event_span($ev);
            if ($a === null || $b === null || $b <= $a || $b < time() || $a > time() + CALENDAR_BUSY_DAYS * 86400) continue;
            $ins->execute([$userId, date('Y-m-d H:i:s', $a), date('Y-m-d H:i:s', $b)]);
            $out['busy']++;
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return $setError($failed);
}

/**
 * Everyone who turned sync on (the watchdog, every ten minutes), within $budget seconds; one run at a
 * time. Returns how many were synced without a problem.
 */
function calendar_sync_all(int $budget = 240): int {
    if (!calendar_available()) return 0;
    $dir = (string)($GLOBALS['CMS_PRIVATE_DIR'] ?? dirname((string)cfg('upload_dir')));
    $lock = @fopen(rtrim($dir, '/') . '/calendar_sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0; // the last run is still going
    $deadline = time() + $budget;
    $n = 0;
    try {
        // The person waiting longest first, so a short budget still reaches everyone in turn.
        foreach (db()->query('SELECT id FROM users WHERE calendar_sync = 1 AND is_active = 1 ORDER BY calendar_synced_at IS NOT NULL, calendar_synced_at')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if (time() > $deadline) break;
            try { if (calendar_sync_user((int)$uid, $deadline)['error'] === null) $n++; }
            catch (Throwable $e) { error_log('calendar_sync_user: ' . get_class($e)); }
        }
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
    return $n;
}

/** Put one session in (or take it out of) its instructor's Outlook at once, after a booking, move or cancellation. Never fails the page. */
function calendar_push_session(int $sessionId): void {
    try {
        if (!calendar_available()) return;
        $q = db()->prepare('SELECT s.*, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id WHERE s.id = ?');
        $q->execute([$sessionId]);
        $s = $q->fetch();
        if (!$s) return;
        $lq = db()->prepare('SELECT * FROM calendar_links WHERE session_id = ?');
        $lq->execute([$sessionId]);
        $links = $lq->fetchAll();
        $uid = (int)$s['user_id'];
        // The session's former instructor (it was reassigned) loses the event.
        foreach ($links as $l) {
            if ((int)$l['user_id'] !== $uid || $s['status'] === 'Cancelled' || $s['status'] === 'No Show') {
                if (($tok = meeting_access_token((int)$l['user_id'], 'microsoft')) !== null) calendar_remove($tok, $l);
            }
        }
        if ($s['status'] !== 'Scheduled' || calendar_problem($uid) !== null) return;
        $mine = array_values(array_filter($links, fn($l) => (int)$l['user_id'] === $uid));
        if (($tok = meeting_access_token($uid, 'microsoft')) !== null) calendar_put($tok, $uid, $s, $mine[0] ?? null);
    } catch (Throwable $e) {
        error_log('calendar_push_session: ' . get_class($e));
    }
}

/** Whether a person's Outlook shows them busy for any of [$start, $end). */
function calendar_busy_overlaps(int $userId, int $start, int $end): bool {
    try {
        $q = db()->prepare('SELECT 1 FROM calendar_busy WHERE user_id = ? AND start_at < ? AND end_at > ? LIMIT 1');
        $q->execute([$userId, date('Y-m-d H:i:s', $end), date('Y-m-d H:i:s', $start)]);
        return (bool)$q->fetchColumn();
    } catch (PDOException $e) {
        return false; // before migration 4.20.0
    }
}

/** Turn sync off: the events it added are taken out of Outlook (as far as Outlook can be reached). */
function calendar_stop(int $userId, ?int &$left = null): int {
    $removed = 0;
    $lq = db()->prepare('SELECT * FROM calendar_links WHERE user_id = ?');
    $lq->execute([$userId]);
    $links = $lq->fetchAll();
    $tok = $links ? meeting_access_token($userId, 'microsoft') : null;
    // Only events Outlook confirmed removed are counted (4.32.0); $left is how many stay there.
    $deadline = time() + 60;
    foreach ($links as $l) {
        if ($tok !== null && time() < $deadline && calendar_remove($tok, $l)) $removed++;
    }
    $left = count($links) - $removed;
    db()->prepare('DELETE FROM calendar_links WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM calendar_busy WHERE user_id = ?')->execute([$userId]);
    db()->prepare('UPDATE users SET calendar_sync = 0, calendar_error = NULL WHERE id = ?')->execute([$userId]);
    return $removed;
}
