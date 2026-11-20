<?php
declare(strict_types=1);
/*
 * Marking alerts read, and undoing it (4.36.0: moved from notifications.php, shared with the
 * apps' mark_notifications_read and mark_notifications_unread). One timestamp marks the whole
 * batch, so Undo puts back exactly the alerts that batch marked, and only for 15 minutes.
 * The website keeps the batch in its session; the apps are handed its ids and stamp, and the
 * stamp is checked here, so neither can restore anyone else's alerts or an older batch.
 */

/** How long a batch marked read can be marked unread again. */
const ALERTS_UNDO_SECONDS = 900;

/** The alert ids from "1,2,3" (or one number), each once, at most 100. */
function alerts_ids(mixed $v): array {
    $raw = is_string($v) ? $v : (is_int($v) ? (string)$v : '');
    return array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn($i) => $i > 0))), 0, 100);
}

/**
 * Mark the listed alerts read ($all: every unread one, in one update however many), with one stamp.
 * Returns ['marked' => int, 'left' => int (still unread), 'stamp' => 'Y-m-d H:i:s', 'ids' => int[]
 * (the listed ids; [] for $all, whose batch is known by its stamp)].
 */
function alerts_mark_read(int $userId, array $ids, bool $all = false): array {
    $stamp = date('Y-m-d H:i:s');
    $marked = 0;
    if ($all) {
        $mr = db()->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL');
        $mr->execute([$stamp, $userId]);
        $marked = $mr->rowCount();
        $ids = [];
    } elseif ($ids) {
        $mr = db()->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $mr->execute(array_merge([$stamp, $userId], $ids));
        $marked = $mr->rowCount();
    }
    $left = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $left->execute([$userId]);
    return ['marked' => $marked, 'left' => (int)$left->fetchColumn(), 'stamp' => $stamp, 'ids' => $ids];
}

/**
 * Mark unread again the alerts of one batch still carrying its stamp, within 15 minutes of it:
 * only the listed ids, or, with no ids (a batch marked with "all"), every alert of the caller's
 * carrying that stamp. Returns how many.
 */
function alerts_undo(int $userId, array $ids, string $stamp): int {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    $at = strtotime($stamp);
    if ($at === false || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stamp) || $at < time() - ALERTS_UNDO_SECONDS) return 0;
    $ur = db()->prepare('UPDATE notifications SET read_at = NULL WHERE user_id = ? AND read_at = ?'
        . ($ids ? ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : ''));
    $ur->execute(array_merge([$userId, $stamp], $ids));
    return $ur->rowCount();
}

/** "N alerts marked as read." plus how many more are listed now. */
function alerts_marked_message(int $marked, int $left): string {
    return $marked . ' alert' . ($marked === 1 ? '' : 's') . ' marked as read.'
        . ($left ? ' ' . $left . ' more unread alert' . ($left === 1 ? ' is' : 's are') . ' listed now.' : '');
}

/** "N alerts are unread again.", or that there was nothing to undo. */
function alerts_undo_message(int $restored): string {
    return $restored ? $restored . ' alert' . ($restored === 1 ? ' is' : 's are') . ' unread again.'
        : 'There was nothing to undo: alerts can be marked unread again for 15 minutes after marking them read.';
}
