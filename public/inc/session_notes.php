<?php
declare(strict_types=1);
/*
 * Case notes for completed sessions (4.31.0). Confirming a session (the Schedule page, a
 * group's attendance, the app's complete_session, voice) marks it note_pending; a session
 * still without its note an hour later gets a task (sessnote_add_due_tasks, the watchdog job); the website then opens the full case-note editor for it
 * (client.php ...&session=<id>), and saving a note linked to the session (client_notes.
 * session_id, also from the app's add_note) clears the mark and completes the task.
 * Sessions completed before 4.31.0 are never marked (no backfill).
 *
 * Everything here tolerates a database not yet migrated (client_notes.session_id,
 * scheduled_sessions.note_pending and note_pending_since missing): nothing is marked, stored or listed, and
 * the editor still opens prefilled for the session.
 */

/** Whether the 4.31.0 columns exist (checked once per request; $recheck after a migration). */
function sessnote_ready(bool $recheck = false): bool {
    static $ready = null;
    if ($ready !== null && !$recheck) return $ready;
    try {
        $q = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND ((TABLE_NAME = 'client_notes' AND COLUMN_NAME = 'session_id') OR (TABLE_NAME = 'scheduled_sessions' AND COLUMN_NAME IN ('note_pending', 'note_pending_since')))");
        $ready = (int)$q->fetchColumn() === 3;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** The subject a session's case note starts with: "Session on Oct 20, 2026". */
function sessnote_subject(string $startAt): string {
    return 'Session on ' . fmt_date($startAt);
}

/** The stable key of a session's "write the case note" task, so it is never added twice. */
function sessnote_task_key(int $sid): string {
    return 'snote-' . $sid;
}

/**
 * A session just completed: its case note is to be written. Marks it note_pending (unless a
 * note is already linked to it), with when, so the scheduled job can add the reminder task
 * once it has waited an hour (sessnote_add_due_tasks). Called after the completion has
 * committed; a failure here never undoes the completion.
 */
function sessnote_mark_pending(int $sid, int $actor): void {
    if (!sessnote_ready()) return;
    try {
        db()->prepare("UPDATE scheduled_sessions SET note_pending = 1, note_pending_since = NOW() WHERE id = ? AND status = 'Completed'
            AND NOT EXISTS (SELECT 1 FROM client_notes n WHERE n.session_id = ?)")->execute([$sid, $sid]);
    } catch (Throwable $e) {
        error_log('sessnote_mark_pending failed: ' . get_class($e) . ' code=' . (string)$e->getCode() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/** The first business day (Mon-Fri) after a date. */
function sessnote_next_business_day(string $date): string {
    $ts = strtotime(date('Y-m-d', strtotime($date)) . ' 12:00:00');
    do { $ts += 86400; } while ((int)date('N', $ts) >= 6);
    return date('Y-m-d', $ts);
}

/** Whether a staff member can open this client's record (active, the clients permission,
 *  and the client on their caseload when they are limited to it). */
function sessnote_user_can_open(?int $userId, int $clientId): bool {
    if (!$userId) return false;
    $u = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $u->execute([$userId]);
    $row = $u->fetch();
    if (!$row) return false;
    $p = resolve_perms($row);
    if (!in_array('clients', $p['perms'], true)) return false;
    if (!$p['scoped']) return true;
    $c = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?');
    $c->execute([$clientId]);
    return (int)$c->fetchColumn() === $userId;
}

/**
 * The reminder for sessions STILL without their case note (the scheduled job, every ten
 * minutes): for each completed session marked note_pending more than an hour ago, the task
 * "Write the case note for <client>'s session on <date>", due the first business day after
 * the session, for someone who can open the client: the session's instructor, else the
 * client's assigned instructor, else nobody in particular (an unassigned client task, which
 * administrators see). Keyed snote-<id>, so it is added once; it is completed when the note
 * is saved. No email. Returns how many were added.
 */
function sessnote_add_due_tasks(int $limit = 500): int {
    if (!sessnote_ready()) return 0;
    $q = db()->prepare("SELECT s.id, s.client_id, s.user_id, s.start_at, c.name AS client_name, c.assigned_to FROM scheduled_sessions s
        JOIN clients c ON c.id = s.client_id
        WHERE s.status = 'Completed' AND s.note_pending = 1 AND s.note_pending_since IS NOT NULL AND s.note_pending_since <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
          AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.auto_key = CONCAT('snote-', s.id))
        ORDER BY s.id LIMIT " . max(1, $limit));
    $q->execute();
    $added = 0;
    foreach ($q->fetchAll() as $s) {
        $cid = (int)$s['client_id'];
        $assignee = sessnote_user_can_open((int)$s['user_id'], $cid) ? (int)$s['user_id']
            : (sessnote_user_can_open($s['assigned_to'] !== null ? (int)$s['assigned_to'] : null, $cid) ? (int)$s['assigned_to'] : null);
        if (add_task('Write the case note for ' . $s['client_name'] . "'s session on " . fmt_date((string)$s['start_at']),
            'The session was completed without its case note. Write it from Notes to write on the dashboard, or from the session on the Schedule. This task is completed when the note is saved.',
            $cid, null, sessnote_next_business_day((string)$s['start_at']), $assignee, null, sessnote_task_key((int)$s['id']))) $added++;
    }
    return $added;
}

/** A note linked to the session was saved: it is no longer waiting, and its task is done. */
function sessnote_written(int $sid, ?int $actor): void {
    if (!sessnote_ready()) return;
    db()->prepare('UPDATE scheduled_sessions SET note_pending = 0 WHERE id = ?')->execute([$sid]);
    complete_task_by_key(sessnote_task_key($sid), $actor);
}

/** A note linked to the session was removed: the session waits for a note again, unless
 *  another note is still linked to it. */
function sessnote_note_removed(int $sid): void {
    if (!sessnote_ready()) return;
    db()->prepare("UPDATE scheduled_sessions SET note_pending = 1, note_pending_since = NOW() WHERE id = ? AND status = 'Completed'
        AND NOT EXISTS (SELECT 1 FROM client_notes n WHERE n.session_id = ?)")->execute([$sid, $sid]);
}

/** One of this client's completed sessions, with what its note editor shows; null otherwise. */
function sessnote_session(int $sid, int $clientId): ?array {
    if ($sid <= 0) return null;
    $q = db()->prepare("SELECT s.*, c.name AS client_name, t.title AS track_title FROM scheduled_sessions s
        JOIN clients c ON c.id = s.client_id LEFT JOIN training_tracks t ON t.id = s.track_id
        WHERE s.id = ? AND s.client_id = ? AND s.status = 'Completed'");
    $q->execute([$sid, $clientId]);
    return $q->fetch() ?: null;
}

/** Where a session's case note is written on the website: the client's full note editor in
 *  session mode. $back is where saving returns to ('schedule' or 'dashboard'). */
function sessnote_editor_url(int $clientId, int $sid, string $back = 'schedule'): string {
    return 'client.php?id=' . $clientId . '&tab=notes&session=' . $sid . ($back === 'dashboard' ? '&back=dashboard' : '') . '#addnote-h';
}

/**
 * The completed sessions of a group that the editor goes through, one note per client who
 * attended, in the order the group card lists them (client name). Caseload-scoped staff get
 * only their own clients, as the group card shows them. Each row: id, client_id,
 * client_name, note_pending.
 */
function sessnote_group_members(string $groupUid, int $userId, bool $scoped): array {
    if ($groupUid === '') return [];
    $q = db()->prepare("SELECT s.id, s.client_id, c.name AS client_name, " . (sessnote_ready() ? 's.note_pending' : '0 AS note_pending') . "
        FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
        WHERE s.group_uid = ? AND s.status = 'Completed'" . ($scoped ? ' AND c.assigned_to = ?' : '') . ' ORDER BY c.name, s.id');
    $q->execute($scoped ? [$groupUid, $userId] : [$groupUid]);
    return $q->fetchAll();
}

/** [position, total] of a session among its group's members (1-based), or null. */
function sessnote_group_position(array $members, int $sid): ?array {
    foreach (array_values($members) as $i => $m) if ((int)$m['id'] === $sid) return [$i + 1, count($members)];
    return null;
}

/** The next member after $sid still waiting for its note: [member row, position, total], or null. */
function sessnote_group_next(array $members, int $sid): ?array {
    $members = array_values($members);
    $after = false;
    foreach ($members as $i => $m) {
        if ($after && (int)$m['note_pending'] === 1) return [$m, $i + 1, count($members)];
        if ((int)$m['id'] === $sid) $after = true;
    }
    return null;
}

/**
 * Completed sessions whose case note is still to be written, newest first: the user's own
 * sessions (they taught them) and their caseload's; caseload-scoped staff get only their
 * caseload's, as the Schedule shows them. Returns [rows, total]; each row is what the app's
 * notes_to_write sends.
 */
function sessnote_list(int $userId, bool $scoped, int $limit = 100): array {
    if (!sessnote_ready()) return [[], 0];
    $where = "s.status = 'Completed' AND s.note_pending = 1 AND " . ($scoped ? 'c.assigned_to = ?' : '(s.user_id = ? OR c.assigned_to = ?)');
    $params = $scoped ? [$userId] : [$userId, $userId];
    $cq = db()->prepare("SELECT COUNT(*) FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id WHERE $where");
    $cq->execute($params);
    $total = (int)$cq->fetchColumn();
    $q = db()->prepare("SELECT s.id, s.client_id, c.name AS client_name, s.track_id, s.start_at, s.group_uid FROM scheduled_sessions s
        JOIN clients c ON c.id = s.client_id WHERE $where ORDER BY s.start_at DESC, s.id DESC LIMIT " . max(1, min(500, $limit)));
    $q->execute($params);
    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $rows[] = ['session_id' => (int)$r['id'], 'client_id' => (int)$r['client_id'], 'client_name' => (string)$r['client_name'],
            'track_id' => $r['track_id'] !== null ? (int)$r['track_id'] : null, 'session_date' => date('Y-m-d', strtotime((string)$r['start_at'])),
            'start_at' => (string)$r['start_at'], 'note_subject' => sessnote_subject((string)$r['start_at'])];
    }
    return [$rows, $total];
}
