<?php
declare(strict_types=1);
// Correcting logged work afterwards, and each person's hours this week (3.0.46).
// Only work not yet on an invoice can change. A correction keeps the entry (its note,
// signature and goal) and records old and new values with the reason in the audit log;
// more hours or miles are checked against the authorization like new work.

/**
 * Correct an unbilled work log's hours and miles. Hours may be 0 when there are miles
 * (a mileage-only entry); both 0 is refused (delete the entry instead). Returns an
 * error, or null when saved.
 */
function work_log_correct(int $logId, int $trackId, float $hours, float $mileage, string $reason, int $actor): ?string {
    $hours = round($hours, 2); $mileage = round($mileage, 1); $reason = trim($reason);
    if ($hours < 0 || $hours > 24) return 'Enter hours from 0 to 24.';
    if ($mileage < 0 || $mileage > 1000) return 'Enter miles from 0 to 1000.';
    if ($hours == 0.0 && $mileage == 0.0) return 'An entry needs hours or miles. To remove it, delete it instead.';
    if ($reason === '') return 'Say why the entry is being corrected.';
    if (mb_strlen($reason) > 255) return 'Keep the reason to 255 characters or fewer.';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // The track first, then the entry: the lock order invoice generation uses.
        $t = $pdo->prepare('SELECT * FROM training_tracks WHERE id = ? FOR UPDATE');
        $t->execute([$trackId]);
        $track = $t->fetch();
        $l = $pdo->prepare('SELECT * FROM work_logs WHERE id = ? AND track_id = ? FOR UPDATE');
        $l->execute([$logId, $trackId]);
        $log = $l->fetch();
        if (!$track || !$log) { $pdo->rollBack(); return 'That entry no longer exists.'; }
        if ((int)$log['is_billed'] === 1) { $pdo->rollBack(); return 'That entry is already on an invoice, so it cannot change (the invoice totals would no longer match).'; }
        if ($hours == (float)$log['hours'] && $mileage == (float)$log['mileage']) { $pdo->rollBack(); return 'Those are the entry\'s current hours and miles; nothing was changed.'; }
        $rate = (float)($log['hourly_rate'] ?? $track['hourly_rate']);
        $mRate = (float)($log['mileage_rate'] ?? $track['mileage_rate']);
        $addHours = round($hours - (float)$log['hours'], 2);
        $addAmount = round($addHours * $rate, 2) + round(($mileage - (float)$log['mileage']) * $mRate, 2);
        if ($addHours > 0 || $addAmount > 0) {
            $block = work_authorization_block($track, (string)$log['work_date'], max(0.0, $addHours), max(0.0, $addAmount));
            if ($block !== null) { $pdo->rollBack(); return $block; }
        }
        $pdo->prepare('UPDATE work_logs SET hours = ?, mileage = ? WHERE id = ?')->execute([$hours, $mileage, $logId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($actor, 'hours.correct', 'track', $trackId, 'log #' . $logId . ' (' . $log['work_date'] . '): ' . fmt_hours((float)$log['hours']) . ', ' . (float)$log['mileage'] . ' mi → '
        . fmt_hours($hours) . ', ' . $mileage . ' mi. ' . $reason);
    return null;
}

/** One person's week (Monday to Sunday): hours logged, hours still booked, and their weekly target. */
function staff_week(int $userId): array {
    $monday = date('Y-m-d', strtotime('monday this week'));
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $q = db()->prepare('SELECT COALESCE(SUM(hours), 0) FROM work_logs WHERE created_by = ? AND work_date BETWEEN ? AND ?');
    $q->execute([$userId, $monday, $sunday]);
    $logged = (float)$q->fetchColumn();
    // Booked time still ahead this week, one count per sitting (a group is one sitting).
    $b = db()->prepare("SELECT COALESCE(SUM(mins), 0) / 60 FROM (SELECT MAX(TIMESTAMPDIFF(MINUTE, start_at, end_at)) AS mins FROM scheduled_sessions
        WHERE user_id = ? AND status = 'Scheduled' AND start_at >= NOW() AND start_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY start_at, COALESCE(group_uid, CONCAT('s', id))) x");
    $b->execute([$userId, $sunday]);
    $booked = round((float)$b->fetchColumn(), 2);
    $w = db()->prepare('SELECT weekly_hours FROM users WHERE id = ?');
    $w->execute([$userId]);
    return ['from' => $monday, 'to' => $sunday, 'logged' => round($logged, 2), 'booked' => $booked, 'target' => (float)$w->fetchColumn()];
}

/** The week in one sentence. */
function staff_week_text(array $w): string {
    return 'This week (' . fmt_date($w['from']) . ' to ' . fmt_date($w['to']) . '): ' . fmt_hours($w['logged']) . ' logged'
        . ($w['booked'] > 0 ? ', ' . fmt_hours($w['booked']) . ' still booked' : '')
        . ($w['target'] > 0 ? ', against your weekly target of ' . fmt_hours($w['target']) . '.' : '.');
}

/**
 * The goal worked on and the service delivered on a visit (4.35.0, shared by the app's log_hours
 * and complete_session): null when both can be used, otherwise [field, message]. A goal id of 0
 * and a blank category mean none and the track's own; anything else must be this track's.
 */
function visit_choice_problem(array $track, int $goalId, mixed $category): ?array {
    if ($category !== '' && $category !== null) {
        $offered = track_visit_categories($track);
        if (!is_string($category) || !in_array($category, $offered, true)) {
            return ['rsa_category', 'RSA category this visit: ' . (is_string($category) ? '"' . mb_strimwidth($category, 0, 120, '…') . '" ' : 'that category ')
                . 'is not offered on this track. Choose one of: ' . implode(', ', array_filter($offered, 'is_string')) . '.'];
        }
    }
    if ($goalId > 0) {
        $gv = db()->prepare('SELECT id FROM smart_goals WHERE id = ? AND track_id = ?');
        $gv->execute([$goalId, (int)$track['id']]);
        if (!$gv->fetch()) return ['goal_id', 'Goal this session advanced: that goal is not on this track. It may have been removed. Choose another goal, or none.'];
    }
    return null;
}

/** Put the goal worked on and the service delivered (3.0.46) on a completed session's work log:
 *  only this track's own (anything else is left as it is). Moved from schedule.php (4.35.0). */
function visit_choices_apply(int $logId, array $track, int $goalId, string $category): void {
    if ($goalId > 0) {
        $gq = db()->prepare('SELECT id FROM smart_goals WHERE id = ? AND track_id = ?');
        $gq->execute([$goalId, (int)$track['id']]);
        if ($gq->fetchColumn()) db()->prepare('UPDATE work_logs SET goal_id = ? WHERE id = ?')->execute([$goalId, $logId]);
    }
    if ($category !== '' && in_array($category, track_visit_categories($track), true)) {
        db()->prepare('UPDATE work_logs SET rsa_category = ? WHERE id = ?')->execute([$category, $logId]);
    }
}
