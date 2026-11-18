<?php
declare(strict_types=1);
// Two-way calendar sync (4.20.0, owner request 2026-10-17; three kinds of calendar since 4.33.0).
// Each person who turns it on (My Account) connects one calendar: their Microsoft account (a work
// account, or a personal Outlook.com one), a CalDAV calendar (iCloud, Nextcloud, cPanel, Fastmail)
// or a Google calendar shared with a service account (inc/calendar_kinds.php). Then:
//   - their sessions are in that calendar: booked sessions are added, moved ones moved, cancelled
//     ones removed (calendar_links remembers which event is which session);
//   - the calendar's busy times are kept (calendar_busy), so the system never offers, books or moves
//     a session over them;
//   - they are told when they move or delete a session's event in the calendar, to change the
//     session to match (a session is never moved or cancelled from the calendar alone: it carries
//     hours and authorizations).
// The watchdog runs calendar_sync_all() every ten minutes; a booking, move or cancellation also
// pushes that one session at once (calendar_push_session).

const CALENDAR_DAYS_BACK = 1;
const CALENDAR_DAYS_AHEAD = 120;
const CALENDAR_BUSY_DAYS = 60;

/** Whether anyone can connect a calendar: always, since 4.33.0 (CalDAV and Google need no app of the organisation's; Microsoft needs its app). */
function calendar_available(): bool {
    return true;
}

/** Whether the organisation has set up the Microsoft app, which the Microsoft kind needs. */
function calendar_microsoft_available(): bool {
    return function_exists('meeting_app') && meeting_app('microsoft') !== null;
}

/** Why this person's sessions cannot go to their calendar right now, or null when they can. */
function calendar_problem(int $userId): ?string {
    $q = db()->prepare('SELECT calendar_sync FROM users WHERE id = ? AND is_active = 1');
    $q->execute([$userId]);
    if (!(int)$q->fetchColumn()) return 'Calendar sync is off for this person (My Account).';
    $kind = calendar_kind($userId);
    if ($kind === null) return 'A calendar is not connected for this person (My Account).';
    if ($kind === 'microsoft') {
        if (!calendar_microsoft_available()) return 'An administrator has not set up the Microsoft connection (Settings, Online meetings).';
        $acc = meeting_account($userId, 'microsoft');
        if (!$acc) return 'Microsoft is not connected for this person (My Account).';
        if (meeting_refused_at($acc) !== null) return 'The Microsoft connection has ended: connect Microsoft again on My Account.';
        // The granted scope as Microsoft names it: "Calendars.ReadWrite", or the full address for a personal account.
        if (!str_contains(strtolower((string)($acc['granted_scope'] ?? '')), 'calendars.readwrite')) return 'The Microsoft connection does not include the calendar: connect Microsoft again on My Account and allow the calendar.';
        return null;
    }
    if (calendar_connection($userId, $kind) === null) return 'The ' . calendar_kind_name($kind) . ' connection could not be used: connect the calendar again on My Account.';
    return null;
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

/** Add or update one session's event in the connected calendar. Returns null, or what went wrong. */
function calendar_put(array &$conn, int $userId, array $s, ?array $link): ?string {
    $payload = calendar_payload($s);
    $hash = sha1((string)json_encode($payload));
    $recreate = false;
    // An event the person deleted in their calendar: a changed session gets a new one.
    if ($link && $link['outlook_change'] === 'deleted') {
        if ($link['pushed_hash'] === $hash) return null; // unchanged: left deleted, as the person chose
        db()->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$link['id']]);
        $link = null; $recreate = true;
    }
    if ($link && $link['pushed_hash'] === $hash) return null;
    if ($link) {
        $err = calendar_event_update($conn, (string)$link['event_id'], $s, $payload);
        if ($err === 'gone') { db()->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$link['id']]); $link = null; }
        elseif ($err !== null) return $err;
    }
    if (!$link) {
        // transactionId (Outlook): the event is made once, even if a lost reply makes us send it again.
        // The same session with the same content always has the same id, so the ten-minute sync and an immediate push never make two.
        [$eventId, $err] = calendar_event_create($conn, $s, $payload, 'cms-' . (int)$s['id'] . '-' . substr($hash, 0, 12) . ($recreate ? '-' . time() : ''));
        if ($err !== null || $eventId === null) return $err ?? 'The event could not be added.';
        db()->prepare('INSERT INTO calendar_links (session_id, user_id, event_id, kind, pushed_hash, event_start, event_end) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), kind = VALUES(kind), pushed_hash = VALUES(pushed_hash), event_start = VALUES(event_start), event_end = VALUES(event_end), outlook_change = NULL, updated_at = NOW()')
            ->execute([(int)$s['id'], $userId, $eventId, (string)$conn['kind'], $hash, $s['start_at'], $s['end_at']]);
        return null;
    }
    db()->prepare('UPDATE calendar_links SET pushed_hash = ?, event_start = ?, event_end = ?, outlook_change = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$hash, $s['start_at'], $s['end_at'], (int)$link['id']]);
    return null;
}

/** Remove a session's event (cancelled, reassigned, or the person turned sync off). */
function calendar_remove(array &$conn, array $link): bool {
    // An event made in another kind of calendar (the person switched) cannot be reached: its link is dropped.
    if ((string)($link['kind'] ?? 'microsoft') !== (string)$conn['kind'] || calendar_event_delete($conn, (string)$link['event_id'])) {
        db()->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$link['id']]);
        return true;
    }
    return false;
}

/**
 * Bring one person's calendar and the system together. Returns ['pushed' => n, 'removed' => n,
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
        // Told once when it starts failing, so new sessions missing from the calendar are not a surprise.
        if ($why !== null && ($before === null || $before === false)) notify_user($userId, 'Your sessions are not reaching your ' . (calendar_kind($userId) === 'microsoft' ? 'Outlook calendar' : 'calendar') . ': ' . $why, 'account.php#calendar-h', true);
        return $out;
    };
    if (($why = calendar_problem($userId)) !== null) return $setError($why);
    $conn = calendar_connection($userId);
    if ($conn === null) return $setError('The calendar connection has expired: connect the calendar again on My Account.');
    $kindName = $conn['kind'] === 'microsoft' ? 'Outlook' : ($conn['kind'] === 'google' ? 'Google Calendar' : 'your calendar');
    $sessions = calendar_sessions($userId);
    $lq = db()->prepare('SELECT * FROM calendar_links WHERE user_id = ?');
    $lq->execute([$userId]);
    $links = [];
    foreach ($lq->fetchAll() as $l) $links[(int)$l['session_id']] = $l;
    // What the calendar holds now, from yesterday on: our events (to notice changes made there) and the person's own (busy times).
    $read = calendar_read($conn, time() - CALENDAR_DAYS_BACK * 86400, time() + CALENDAR_DAYS_AHEAD * 86400);
    if ($read['error'] !== null) return $setError($read['error']);
    $events = $read['events'];
    $ours = array_flip(array_map(fn($l) => (string)$l['event_id'], $links));
    // 1. Changes made in the calendar to our events: told once, never applied by themselves.
    // 4.28.0: the link and the session are read again before telling anyone. A move or cancellation
    // made on the website while this run read the calendar (calendar_push_session changed the event and
    // its link) is not a change made in the calendar, so the person is not told they made it there.
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
        if (!isset($sessions[$sid]) || $l['outlook_change'] !== null || (string)($l['kind'] ?? 'microsoft') !== (string)$conn['kind']) continue;
        $s = $sessions[$sid];
        if (strtotime((string)$s['start_at']) < time()) continue; // a session under way or done is left alone
        $first = explode(' ', trim((string)$s['client_name']))[0] ?: 'a client';
        if (!isset($events[$l['event_id']]) || $events[$l['event_id']]['cancelled']) {
            if ($stillOurs($l, (int)$sid) === null) continue; // cancelled, moved to someone else, or re-made here meanwhile
            db()->prepare("UPDATE calendar_links SET outlook_change = 'deleted' WHERE id = ?")->execute([(int)$l['id']]);
            notify_user($userId, "You removed the session with $first on " . date('D, M j g:i A', strtotime((string)$s['start_at'])) . " from $kindName, but it is still booked. Cancel it on the Schedule if it is not happening.", 'schedule.php#s-' . $sid);
            $out['noticed']++;
            continue;
        }
        $es = $events[$l['event_id']]['start'];
        if ($es !== null && abs($es - strtotime((string)$l['event_start'])) >= 60) {
            $now = $stillOurs($l, (int)$sid);
            // The event is where the website last put it, or where the session now is: nothing was moved in the calendar.
            if ($now === null || abs($es - strtotime((string)$now['event_start'])) < 60 || abs($es - strtotime($now['session_start'])) < 60) continue;
            $s['start_at'] = $now['session_start'];
            db()->prepare("UPDATE calendar_links SET outlook_change = 'moved' WHERE id = ?")->execute([(int)$l['id']]);
            notify_user($userId, "You moved the session with $first to " . date('D, M j g:i A', $es) . " in $kindName. It is still " . date('D, M j g:i A', strtotime((string)$s['start_at'])) . ' here: move it on the Schedule if the new time is right.', 'schedule.php#s-' . $sid);
            $out['noticed']++;
        }
    }
    // 2. Sessions to add or update; events of sessions no longer booked (or not this person's) to remove.
    $failed = null;
    foreach ($sessions as $sid => $s) {
        if ($deadline !== null && time() > $deadline) break;
        $l = $links[$sid] ?? null;
        // An event made in a calendar of another kind (the person switched calendars) is forgotten; the session gets an event in the new one.
        if ($l && (string)($l['kind'] ?? 'microsoft') !== (string)$conn['kind']) { db()->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$l['id']]); $l = null; }
        // A session moved in the calendar is left as the person moved it until the session itself changes here.
        if ($l && $l['outlook_change'] === 'moved' && $l['pushed_hash'] === sha1((string)json_encode(calendar_payload($s)))) continue;
        $err = calendar_put($conn, $userId, $s, $l);
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
        calendar_remove($conn, $l);
        $out['removed']++;
    }
    // 3. The person's own busy times, for booking around them (refreshed whenever the calendar could be read).
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM calendar_busy WHERE user_id = ?')->execute([$userId]);
        $ins = db()->prepare('INSERT INTO calendar_busy (user_id, start_at, end_at) VALUES (?,?,?)');
        foreach ($events as $id => $ev) {
            if (isset($ours[$id]) || $ev['invite'] || $ev['cancelled'] || $ev['free']) continue;
            $a = $ev['start']; $b = $ev['end'];
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

/** Put one session in (or take it out of) its instructor's calendar at once, after a booking, move or cancellation. Never fails the page. */
function calendar_push_session(int $sessionId): void {
    try {
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
                $other = calendar_connection((int)$l['user_id'], (string)($l['kind'] ?? 'microsoft'));
                if ($other !== null) calendar_remove($other, $l);
            }
        }
        if ($s['status'] !== 'Scheduled' || calendar_problem($uid) !== null) return;
        $conn = calendar_connection($uid);
        if ($conn === null) return;
        $mine = array_values(array_filter($links, fn($l) => (int)$l['user_id'] === $uid && (string)($l['kind'] ?? 'microsoft') === (string)$conn['kind']));
        calendar_put($conn, $uid, $s, $mine[0] ?? null);
    } catch (Throwable $e) {
        error_log('calendar_push_session: ' . get_class($e));
    }
}

/** Whether a person's calendar shows them busy for any of [$start, $end). */
function calendar_busy_overlaps(int $userId, int $start, int $end): bool {
    try {
        $q = db()->prepare('SELECT 1 FROM calendar_busy WHERE user_id = ? AND start_at < ? AND end_at > ? LIMIT 1');
        $q->execute([$userId, date('Y-m-d H:i:s', $end), date('Y-m-d H:i:s', $start)]);
        return (bool)$q->fetchColumn();
    } catch (PDOException $e) {
        return false; // before migration 4.20.0
    }
}

/**
 * Turn sync off: the events it added are taken out of the calendar (as far as it can be reached).
 * $kind names the calendar to take them out of when the person is switching away from it.
 */
function calendar_stop(int $userId, ?int &$left = null, ?string $kind = null): int {
    $removed = 0;
    $lq = db()->prepare('SELECT * FROM calendar_links WHERE user_id = ?');
    $lq->execute([$userId]);
    $links = $lq->fetchAll();
    $conn = $links ? calendar_connection($userId, $kind) : null;
    // Only events the calendar confirmed removed are counted (4.32.0); $left is how many stay there.
    $deadline = time() + 60;
    foreach ($links as $l) {
        if ($conn !== null && time() < $deadline && calendar_remove($conn, $l)) $removed++;
    }
    $left = count($links) - $removed;
    db()->prepare('DELETE FROM calendar_links WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM calendar_busy WHERE user_id = ?')->execute([$userId]);
    db()->prepare('UPDATE users SET calendar_sync = 0, calendar_error = NULL WHERE id = ?')->execute([$userId]);
    return $removed;
}
