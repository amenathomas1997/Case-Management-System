<?php
declare(strict_types=1);
// Client self-booking (4.20.0, owner request 2026-10-17). Staff set the hours they take bookings
// (staff_availability, on My availability); when an administrator turns self-booking on (the same
// page), a client sees their own instructor's open times in the portal and books one. A time is open
// only when the instructor has no session then, their Outlook calendar is free (inc/calendar_sync.php),
// it is far enough ahead, and, for a client with a training track, the track's authorization covers
// it. The booking is made in one locked step, so two clients can never take the same time.

const SELF_BOOKING_DAYS = ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'];

/** The administrator's choices, with their built-in values. */
function self_booking_settings(): array {
    $n = fn(string $k, int $d, int $min, int $max) => max($min, min($max, (int)(setting($k) ?? $d) ?: $d));
    return [
        'on' => setting('self_booking') === 'on',
        'minutes' => $n('self_booking_minutes', 60, 15, 240),
        'notice_hours' => $n('self_booking_notice_hours', 24, 1, 336),
        'days' => $n('self_booking_days', 21, 1, 90),
        'max' => $n('self_booking_max', 2, 1, 10),
    ];
}

/** A person's booking hours, by weekday then start. */
function availability_for(int $userId): array {
    try {
        $q = db()->prepare('SELECT * FROM staff_availability WHERE user_id = ? ORDER BY weekday, start_time');
        $q->execute([$userId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return []; // before migration 4.20.0
    }
}

/** Check and save one block of hours. Returns null, or [the field it is about, what is wrong]. */
function availability_add(int $userId, string $weekday, string $from, string $to, bool $remote, string $location): ?array {
    if (!isset(SELF_BOOKING_DAYS[$weekday])) return ['weekday', 'Day: choose a day of the week.'];
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from)) return ['from', 'From: enter a time, such as 09:00.'];
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to)) return ['to', 'To: enter a time, such as 12:00.'];
    if ($to <= $from) return ['to', 'To: the end time must be after the start time.'];
    $location = trim($location);
    if (!$remote && $location === '') return ['location', 'Where: say where in-person sessions in these hours happen.'];
    if (mb_strlen($location) > 190) return ['location', 'Where: keep it to 190 characters or fewer.'];
    $o = db()->prepare('SELECT COUNT(*) FROM staff_availability WHERE user_id = ? AND weekday = ? AND start_time < ? AND end_time > ?');
    $o->execute([$userId, (int)$weekday, $to . ':00', $from . ':00']);
    if ((int)$o->fetchColumn() > 0) return ['from', 'From: those hours overlap hours already set on ' . SELF_BOOKING_DAYS[$weekday] . '.'];
    db()->prepare('INSERT INTO staff_availability (user_id, weekday, start_time, end_time, is_remote, location) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, (int)$weekday, $from . ':00', $to . ':00', $remote ? 1 : 0, $remote ? null : $location]);
    return null;
}

/** "Mondays 9:00 AM to 12:00 PM, online" */
function availability_words(array $a): string {
    $t = fn(string $hms) => date('g:i A', strtotime('2000-01-01 ' . $hms));
    return SELF_BOOKING_DAYS[(string)$a['weekday']] . 's ' . $t((string)$a['start_time']) . ' to ' . $t((string)$a['end_time'])
        . ((int)$a['is_remote'] ? ', online' : ', in person at ' . (string)$a['location']);
}

/**
 * The client's active training tracks with what the authorization check needs (as workflow_authorization
 * counts them: hours delivered, plus booked sessions to the quarter hour), read once per page.
 */
function self_booking_tracks(int $clientId, bool $fresh = false): array {
    static $cache = [];
    if (!$fresh && isset($cache[$clientId])) return $cache[$clientId];
    $q = db()->prepare("SELECT t.id, t.auth_number, t.start_date, t.expiration_date, t.authorized_hours,
            (SELECT COALESCE(SUM(hours), 0) FROM work_logs w WHERE w.track_id = t.id) AS used,
            (SELECT COALESCE(SUM(ROUND(TIMESTAMPDIFF(SECOND, start_at, end_at) / 900)), 0) / 4 FROM scheduled_sessions s WHERE s.track_id = t.id AND s.status = 'Scheduled') AS reserved
        FROM training_tracks t WHERE t.client_id = ? AND t.status = 'Active' ORDER BY t.expiration_date IS NULL, t.expiration_date, t.id");
    $q->execute([$clientId]);
    return $cache[$clientId] = $q->fetchAll();
}

/**
 * The training track a self-booked time counts against: an active one with an authorization number whose
 * dates and remaining hours cover it. A client with no such track books through their instructor instead
 * (self-booking never makes a session nobody can bill).
 */
function self_booking_track(int $clientId, int $start, int $end, bool $fresh = false): array {
    $hours = round(($end - $start) / 3600 * 4) / 4;
    foreach (self_booking_tracks($clientId, $fresh) as $t) {
        if (trim((string)$t['auth_number']) === '') continue;
        if ($t['start_date'] && date('Y-m-d', $start) < $t['start_date']) continue;
        if ($t['expiration_date'] && date('Y-m-d', $end - 1) > $t['expiration_date']) continue;
        if ((float)$t['used'] + (float)$t['reserved'] + $hours > (float)$t['authorized_hours'] + .001) continue;
        return ['ok' => true, 'track_id' => (int)$t['id']];
    }
    return ['ok' => false, 'track_id' => null];
}

/**
 * Why this client cannot book in the portal, or null when they can. A client whose case is closed or
 * archived, who has no instructor, or who already has as many self-booked sessions ahead as allowed.
 */
function self_booking_blocked(array $client): ?string {
    $cfg = self_booking_settings();
    if (!$cfg['on']) return 'off';
    if ($client['archived_at'] !== null || str_starts_with((string)$client['status'], 'Closed')) return 'closed';
    if (empty($client['assigned_to'])) return 'no_instructor';
    // An instructor whose account is switched off takes no bookings (their hours stay for when they return).
    $iq = db()->prepare('SELECT is_active FROM users WHERE id = ?');
    $iq->execute([(int)$client['assigned_to']]);
    if (!(int)$iq->fetchColumn()) return 'no_instructor';
    if (!self_booking_tracks((int)$client['id'])) return 'no_track';
    if (!availability_for((int)$client['assigned_to'])) return 'no_hours';
    $q = db()->prepare("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id = ? AND booked_by_client = 1 AND status = 'Scheduled' AND start_at > NOW()");
    $q->execute([(int)$client['id']]);
    if ((int)$q->fetchColumn() >= $cfg['max']) return 'max';
    return null;
}

/**
 * The open times this client can book with their instructor, soonest first (at most $limit; with
 * $only, just that start if it is open):
 * [['start' => ts, 'end' => ts, 'remote' => bool, 'location' => ?string, 'track_id' => ?int], ...].
 */
function self_booking_slots(array $client, int $limit = 40, ?int $only = null): array {
    if (self_booking_blocked($client) !== null) return [];
    $cfg = self_booking_settings();
    $uid = (int)$client['assigned_to'];
    $avail = availability_for($uid);
    $from = time() + $cfg['notice_hours'] * 3600;
    $until = strtotime('today') + ($cfg['days'] + 1) * 86400;
    $len = $cfg['minutes'] * 60;
    // The instructor's sessions and Outlook busy times in the whole range, read once.
    $busy = [];
    // The instructor's sessions, and the client's own with anyone else.
    $q = db()->prepare("SELECT start_at, end_at FROM scheduled_sessions WHERE (user_id = ? OR client_id = ?) AND status = 'Scheduled' AND end_at > ? AND start_at < ?");
    $q->execute([$uid, (int)$client['id'], date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $until)]);
    foreach ($q->fetchAll() as $r) $busy[] = [strtotime((string)$r['start_at']), strtotime((string)$r['end_at'])];
    try {
        $q = db()->prepare('SELECT start_at, end_at FROM calendar_busy WHERE user_id = ? AND end_at > ? AND start_at < ?');
        $q->execute([$uid, date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $until)]);
        foreach ($q->fetchAll() as $r) $busy[] = [strtotime((string)$r['start_at']), strtotime((string)$r['end_at'])];
    } catch (PDOException $e) {}
    $free = function (int $a, int $b) use ($busy): bool { foreach ($busy as [$x, $y]) if ($x < $b && $y > $a) return false; return true; };
    $out = [];
    for ($day = strtotime('today'); $day < $until && count($out) < $limit; $day = strtotime('+1 day', $day)) {
        $wd = date('N', $day);
        foreach ($avail as $a) {
            if ((string)$a['weekday'] !== $wd) continue;
            $winEnd = strtotime(date('Y-m-d', $day) . ' ' . $a['end_time']);
            for ($s = strtotime(date('Y-m-d', $day) . ' ' . $a['start_time']); $s + $len <= $winEnd && count($out) < $limit; $s += $len) {
                if ($s < $from || ($only !== null && $s !== $only) || !$free($s, $s + $len)) continue;
                $tr = self_booking_track((int)$client['id'], $s, $s + $len, $only !== null);
                if (!$tr['ok']) continue;
                $out[] = ['start' => $s, 'end' => $s + $len, 'remote' => (bool)(int)$a['is_remote'], 'location' => $a['location'], 'track_id' => $tr['track_id']];
            }
        }
    }
    usort($out, fn($x, $y) => $x['start'] <=> $y['start']);
    return array_slice($out, 0, $limit);
}

/**
 * Book a time the client chose (its start, as shown). Checked again, under a lock on the instructor,
 * so two clients choosing the same time get it once. Returns ['error' => ?string, 'session_id' => ?int].
 */
function self_booking_book(array $client, int $start): array {
    $pdo = db();
    $uid = (int)$client['assigned_to'];
    $pdo->beginTransaction();
    try {
        // One booking at a time per instructor: the lock is on their user row, taken first by every path
        // that books or moves a session (session_lock_instructor), so they never cross.
        session_lock_instructor($uid);
        $slot = null;
        foreach (self_booking_slots($client, 1, $start) as $s) if ($s['start'] === $start) { $slot = $s; break; }
        if ($slot === null) { $pdo->rollBack(); return ['error' => 'That time is no longer open. Choose another.', 'session_id' => null]; }
        $pdo->prepare("INSERT INTO scheduled_sessions (client_id, track_id, user_id, start_at, end_at, location, is_remote, status, booked_by_client) VALUES (?,?,?,?,?,?,?, 'Scheduled', 1)")
            ->execute([(int)$client['id'], $slot['track_id'], $uid, date('Y-m-d H:i:s', $slot['start']), date('Y-m-d H:i:s', $slot['end']), $slot['remote'] ? null : $slot['location'], $slot['remote'] ? 1 : 0]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // An online session gets its link from the instructor's own meeting account, when they connected one.
    if ($slot['remote']) {
        foreach (['microsoft', 'zoom'] as $prov) {
            if (meeting_app($prov) && meeting_account($uid, $prov)) {
                $why = null;
                $url = meeting_create($uid, $prov, (brand('short') ?: cfg('name')) . ' session', $slot['start'], $slot['end'], false, $why);
                if ($url !== null) db()->prepare('UPDATE scheduled_sessions SET meeting_url = ? WHERE id = ?')->execute([$url, $sid]);
                break;
            }
        }
    }
    client_session_notice([$sid], 'booked');
    $first = explode(' ', trim((string)$client['name']))[0];
    notify_user($uid, "$first booked a session in the portal: " . date('D, M j g:i A', $slot['start']), 'schedule.php#s-' . $sid);
    audit(null, 'session.self_book', 'client', (int)$client['id'], 'session #' . $sid . ' ' . date('Y-m-d H:i', $slot['start']) . ' (booked by the client in the portal)');
    return ['error' => null, 'session_id' => $sid];
}

/** Lock an instructor's row for the rest of the transaction: every path that books or moves a session takes it first, so two bookings at once for one instructor wait in turn. */
function session_lock_instructor(int $userId): void {
    if ($userId > 0) db()->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
}
