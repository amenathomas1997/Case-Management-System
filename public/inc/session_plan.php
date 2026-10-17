<?php
declare(strict_types=1);
// Booking several sessions at once (4.9.0, the owner's "multi-session scheduler"). Besides the
// weekly repeat schedule.php always had: on several days each week (for a number of weeks or
// until a date), on a list of dates, or spread over an authorization (its days until it ends,
// stopping when its remaining hours are used). Every plan is shown first and booked only once
// the person confirms that exact list.

const PLAN_PATTERNS = ['weekly' => 'Once, or weekly on the same day', 'days' => 'On several days each week',
    'dates' => 'On a list of dates', 'fill' => 'Spread over the authorization'];
const PLAN_WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const PLAN_MAX_SESSIONS = 100;

/**
 * The sessions a plan books, as [[start, end], ...] (timestamps, the site's zone), or an error.
 * $startTs/$endTs: the first session as typed (its date, start time and length). $track: the
 * linked track row, needed for 'fill'. Returns [occurrences, error|null, error field|null].
 */
function session_plan(array $in, int $startTs, int $endTs, ?array $track): array {
    $pattern = isset(PLAN_PATTERNS[$in['plan_pattern'] ?? '']) ? (string)$in['plan_pattern'] : 'weekly';
    $len = $endTs - $startTs;
    $time = date('H:i:s', $startTs);
    $at = fn(string $day): array => [($s = strtotime($day . ' ' . $time)), $s + $len];
    if ($pattern === 'weekly') {
        $weeksIn = is_string($in['repeat_weeks'] ?? null) ? trim($in['repeat_weeks']) : '';
        $weeks = $weeksIn === '' ? 1 : (ctype_digit($weeksIn) ? (int)$weeksIn : 0);
        if ($weeks < 1 || $weeks > 26) return [[], 'Enter a number of weeks from 1 to 26 (1 is a single session).', 'repeat_weeks'];
        $out = [];
        // "+N week" keeps the local start time across daylight-saving changes.
        for ($w = 0; $w < $weeks; $w++) $out[] = [$w === 0 ? $startTs : strtotime("+{$w} week", $startTs), $w === 0 ? $endTs : strtotime("+{$w} week", $endTs)];
        return [$out, null, null];
    }
    if ($pattern === 'dates') {
        $out = []; $seen = [];
        foreach (preg_split('/\R/', (string)($in['plan_dates'] ?? '')) as $n => $line) {
            $line = trim($line);
            if ($line === '') continue;
            // "2026-11-03", or with its own start time: "2026-11-03 2:30 PM".
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+(.+))?$/', $line, $m) || valid_date($m[1], (int)date('Y'), (int)date('Y') + 2) === null) {
                return [[], 'Line ' . ($n + 1) . ' of the dates: write each as 2026-11-03, or with a time as 2026-11-03 2:30 PM.', 'plan_dates'];
            }
            $s = isset($m[2]) ? strtotime($m[1] . ' ' . $m[2]) : strtotime($m[1] . ' ' . $time);
            if ($s === false || date('Y-m-d', $s) !== $m[1]) return [[], 'Line ' . ($n + 1) . ' of the dates: that time could not be read; write it as 2:30 PM or 14:30.', 'plan_dates'];
            if ($s < time()) return [[], 'Line ' . ($n + 1) . ' of the dates is in the past.', 'plan_dates'];
            if (isset($seen[$s])) continue;
            $seen[$s] = true;
            $out[] = [$s, $s + $len];
        }
        if (count($out) < 1) return [[], 'Write at least one date, one per line.', 'plan_dates'];
        if (count($out) > PLAN_MAX_SESSIONS) return [[], 'Book at most ' . PLAN_MAX_SESSIONS . ' sessions at a time.', 'plan_dates'];
        usort($out, fn($a, $b) => $a[0] <=> $b[0]);
        return [$out, null, null];
    }
    $days = array_values(array_unique(array_filter(array_map('intval', is_array($in['plan_days'] ?? null) ? $in['plan_days'] : []), fn($d) => isset(PLAN_WEEKDAYS[$d]))));
    if (!$days) return [[], 'Choose at least one day of the week.', 'plan_days'];
    $first = date('Y-m-d', $startTs);
    if ($pattern === 'days') {
        $untilIn = is_string($in['plan_until'] ?? null) ? trim($in['plan_until']) : '';
        $until = $untilIn !== '' ? valid_date($untilIn, (int)date('Y'), (int)date('Y') + 2) : null;
        if ($untilIn !== '' && $until === null) return [[], 'Enter the last date as a real date, within two years.', 'plan_until'];
        $weeksIn = is_string($in['repeat_weeks'] ?? null) ? trim($in['repeat_weeks']) : '';
        $weeks = $weeksIn === '' ? 1 : (ctype_digit($weeksIn) ? (int)$weeksIn : 0);
        if ($until === null && ($weeks < 1 || $weeks > 52)) return [[], 'Give the last date, or a number of weeks from 1 to 52.', 'repeat_weeks'];
        $last = $until ?? date('Y-m-d', strtotime($first . ' +' . ($weeks * 7 - 1) . ' days'));
        if ($last < $first) return [[], 'The last date is before the first session.', 'plan_until'];
        $out = [];
        for ($d = $first; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (in_array((int)date('N', strtotime($d)), $days, true)) $out[] = $at($d);
            if (count($out) > PLAN_MAX_SESSIONS) return [[], 'That would book more than ' . PLAN_MAX_SESSIONS . ' sessions: choose an earlier last date or fewer days.', 'plan_until'];
        }
        if (!$out) return [[], 'No chosen day falls between the first date and the last.', 'plan_days'];
        return [$out, null, null];
    }
    // 'fill': the track's authorization, from the first date to its end, until its hours are used.
    if (!$track) return [[], 'Choose the training track whose authorization to spread the sessions over.', 'track_id'];
    if (empty($track['expiration_date'])) return [[], 'That authorization has no end date: use several days each week with a last date instead.', 'track_id'];
    if ((float)$track['authorized_hours'] <= 0) return [[], 'That authorization has no authorized hours to spread.', 'track_id'];
    $q = db()->prepare('SELECT COALESCE(SUM(hours), 0) FROM work_logs WHERE track_id = ?');
    $q->execute([(int)$track['id']]);
    $used = (float)$q->fetchColumn();
    $q = db()->prepare("SELECT COALESCE(SUM(ROUND(TIMESTAMPDIFF(SECOND, start_at, end_at) / 900)), 0) / 4 FROM scheduled_sessions WHERE track_id = ? AND status = 'Scheduled'");
    $q->execute([(int)$track['id']]);
    $left = (float)$track['authorized_hours'] - $used - (float)$q->fetchColumn();
    $each = round($len / 3600 * 4) / 4;
    if ($left < $each - 0.001) return [[], sprintf('The authorization has %.2f hours left after the work logged and sessions already booked, less than one session.', max(0, $left)), 'track_id'];
    $out = [];
    // From the authorization's own start when the typed date is earlier.
    $from = !empty($track['start_date']) && $track['start_date'] > $first ? (string)$track['start_date'] : $first;
    for ($d = $from; $d <= $track['expiration_date'] && $left >= $each - 0.001; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (!in_array((int)date('N', strtotime($d)), $days, true)) continue;
        $out[] = $at($d);
        $left -= $each;
        if (count($out) > PLAN_MAX_SESSIONS) return [[], 'That would book more than ' . PLAN_MAX_SESSIONS . ' sessions: choose fewer days a week.', 'plan_days'];
    }
    if (!$out) return [[], 'No chosen day falls between the first date and the end of the authorization.', 'plan_days'];
    return [$out, null, null];
}

/** The fingerprint of a plan, so "book these" books exactly the list that was shown. */
function session_plan_hash(array $occurrences, int $clientId, int $staffId, int $trackId = 0, string $pattern = ''): string {
    return hash('sha256', $clientId . '|' . $staffId . '|' . $trackId . '|' . $pattern . '|' . implode(',', array_map(fn($o) => $o[0] . '-' . $o[1], $occurrences)));
}

/** Hours a plan books, to the quarter hour per session (as completing one logs it). */
function session_plan_hours(array $occurrences): float {
    return array_sum(array_map(fn($o) => round(($o[1] - $o[0]) / 3600 * 4) / 4, $occurrences));
}
