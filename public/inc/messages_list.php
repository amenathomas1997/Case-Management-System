<?php
declare(strict_types=1);
/*
 * The Messages conversation list (4.36.0: moved from messages.php, shared with the apps'
 * message_threads), and the warning shown before a reply to a counselor whose email is not yet
 * confirmed (messages.php and message_thread). Unread conversations first, then the newest;
 * each row carries its last message cut to 60 characters and who sent it.
 */

/**
 * The conversations in scope ($scopeUser: a caseload-scoped user's id, else null), at most $limit.
 * $byChannel: one row per client and channel (the website), else one per client (the apps, with
 * per-channel unread counts). Returns ['rows', 'total' (every conversation in scope), 'unread'].
 */
function message_conversations(?int $scopeUser, int $viewerId, bool $byChannel, int $limit = 200): array {
    $scopeAnd = $scopeUser !== null ? ' AND c.assigned_to = ?' : '';
    $scopeArgs = $scopeUser !== null ? [$scopeUser] : [];
    $last = "MAX(CONCAT(m.created_at, 0x1f, m.sender, 0x1f, COALESCE(m.staff_id, 0), 0x1f, m.channel, 0x1f, m.body))";
    $sql = "SELECT m.client_id, " . ($byChannel ? 'm.channel, ' : '') . "c.name AS client_name,
            COUNT(m.id) AS total,
            MAX(m.created_at) AS last_at,
            SUM(m.sender <> 'staff' AND m.read_at IS NULL) AS unread,
            SUM(m.channel = 'client' AND m.sender <> 'staff' AND m.read_at IS NULL) AS unread_client,
            SUM(m.channel = 'counselor' AND m.sender <> 'staff' AND m.read_at IS NULL) AS unread_counselor,
            SUBSTRING_INDEX($last, 0x1f, -1) AS last_body,
            SUBSTRING_INDEX(SUBSTRING_INDEX($last, 0x1f, 2), 0x1f, -1) AS last_sender,
            SUBSTRING_INDEX(SUBSTRING_INDEX($last, 0x1f, 3), 0x1f, -1) AS last_staff_id,
            SUBSTRING_INDEX(SUBSTRING_INDEX($last, 0x1f, 4), 0x1f, -1) AS last_channel
        FROM messages m JOIN clients c ON c.id = m.client_id
        WHERE 1=1$scopeAnd
        GROUP BY m.client_id" . ($byChannel ? ', m.channel' : '') . ", c.name
        ORDER BY unread DESC, last_at DESC LIMIT " . max(1, $limit);
    $st = db()->prepare($sql);
    $st->execute($scopeArgs);
    $rows = $st->fetchAll();
    // The counts cover every conversation in scope, not only the ones listed.
    $cnt = db()->prepare('SELECT COUNT(DISTINCT m.client_id' . ($byChannel ? ', m.channel' : '') . ") AS threads, COALESCE(SUM(m.sender <> 'staff' AND m.read_at IS NULL), 0) AS unread
        FROM messages m JOIN clients c ON c.id = m.client_id WHERE 1=1$scopeAnd");
    $cnt->execute($scopeArgs);
    $counts = $cnt->fetch();
    // "You" only for the viewer's own messages; a colleague's reply shows their name.
    $staffNames = db()->query('SELECT id, name FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rows as &$r) {
        $r['last_sender_label'] = message_sender_label($r, $staffNames, $viewerId);
        $r['last_body'] = mb_strimwidth((string)$r['last_body'], 0, 60, '…');
    }
    unset($r);
    return ['rows' => $rows, 'total' => (int)$counts['threads'], 'unread' => (int)$counts['unread']];
}

/** "You: ", "<colleague>: ", or "" for a message from the client or counselor. */
function message_sender_label(array $row, array $staffNames, int $viewerId): string {
    if ($row['last_sender'] !== 'staff') return '';
    $sid = (int)$row['last_staff_id'];
    return ($sid === $viewerId ? 'You' : ($staffNames[$sid] ?? 'Staff')) . ': ';
}

/** Said before a reply on the counselor channel while the counselor email waits to be confirmed.
 *  $web: the website's sentence, whose last words link to the client's edit page (the caller adds the link). */
function message_counselor_unconfirmed_text(string $clientName, bool $web): string {
    return 'Not yet visible to the counselor: ' . $clientName . '\'s counselor email has not been confirmed, so the counselor portal does not show this client. Replies are saved here and appear there once the counselor is confirmed on '
        . ($web ? 'the client\'s edit page.' : 'the client\'s record.');
}
