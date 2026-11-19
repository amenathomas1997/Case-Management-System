<?php
declare(strict_types=1);
// Outcome risk flags (4.34.0): the clients assessed, the signal line sent for each and the prompt,
// shared by the website (risk.php) and the app (api.php ai_risk), so both send the provider the
// same signals for the same period.

/** The quarter's first day (the period when none is chosen). */
function risk_quarter_start(): string {
    return date('Y-m-d', strtotime(date('Y') . '-' . (floor((date('n') - 1) / 3) * 3 + 1) . '-01'));
}

/** Clients in active training (caseload-scoped when $scopeUser is set), with the count of all of
 *  them. At most 80 fit one assessment: when there are more, the ones with no session or the oldest
 *  last session (the likeliest to be at risk) are taken. The last session is the last one held, so
 *  a future-dated log never makes a disengaged client look recently seen.
 *  @return array{0: array, 1: int} */
function risk_ai_rows(?int $scopeUser, string $from, string $to): array {
    $where = " FROM clients c WHERE c.status = 'Active Training'" . ($scopeUser !== null ? ' AND c.assigned_to = ?' : '');
    $params = $scopeUser !== null ? [$scopeUser] : [];
    $ct = db()->prepare('SELECT COUNT(*)' . $where);
    $ct->execute($params);
    $total = (int)$ct->fetchColumn();
    $st = db()->prepare("SELECT c.id, c.name,
            (SELECT MAX(w.work_date) FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE t.client_id = c.id AND w.work_date <= CURDATE()) AS last_session,
            (SELECT MAX(n.created_at) FROM client_notes n WHERE n.client_id = c.id) AS last_note,
            (SELECT COUNT(*) FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE t.client_id = c.id AND w.work_date BETWEEN ? AND ?) AS period_sessions,
            (SELECT MIN(t.expiration_date) FROM training_tracks t WHERE t.client_id = c.id AND t.status = 'Active' AND t.expiration_date IS NOT NULL) AS next_exp,
            (SELECT COUNT(*) FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = c.id AND sg.status <> 'Achieved') AS open_goals,
            (SELECT ROUND(AVG(sg.progress_pct)) FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = c.id AND sg.status <> 'Achieved') AS avg_progress"
        . $where . ' ORDER BY last_session, c.name LIMIT 80');
    $st->execute(array_merge([$from, $to], $params));
    return [$st->fetchAll(), $total];
}

/** Days from today, or null. */
function risk_days_since(?string $d): ?int {
    if (!$d) return null;
    return (int)(new DateTime('today'))->diff(new DateTime(substr($d, 0, 10)))->days;
}

/** Authorization expiry as how far away it is ("in 21d" / "expired 12d ago"): the date itself is
 *  not needed for triage, so it does not leave for the provider. */
function risk_expiry_words(?string $d): string {
    if (!$d) return 'none';
    $days = (int)(new DateTime('today'))->diff(new DateTime(substr($d, 0, 10)))->format('%r%a');
    return $days >= 0 ? "in {$days}d" : 'expired ' . -$days . 'd ago';
}

/** One client's signal line, by token. */
function risk_ai_line(array $r): string {
    $ls = risk_days_since($r['last_session']); $ln = risk_days_since($r['last_note']);
    return 'C' . (int)$r['id'] . ': last session ' . ($ls === null ? 'never' : "{$ls}d ago")
        . ', last note ' . ($ln === null ? 'never' : "{$ln}d ago")
        . ", {$r['period_sessions']} sessions in the period"
        . ', nearest auth expiry ' . risk_expiry_words($r['next_exp'])
        . ", {$r['open_goals']} open goals"
        . ($r['avg_progress'] !== null ? " avg {$r['avg_progress']}% progress" : '') . "\n";
}

function risk_ai_system(): string {
    return ai_system_base() . ' You are flagging vocational-rehabilitation clients at operational risk of not completing, from the engagement '
        . 'and progress signals below. Risk indicators include: long gaps since the last session or note; an authorization expiring soon while '
        . 'open goals remain at low progress; and stalled progress. Prioritize the highest-risk clients first, refer to each by their token '
        . '(e.g. C12), give the specific reason, and suggest one concrete action. Use only the signals provided. This is operational triage, '
        . 'not a clinical or guaranteed prediction. Give a numbered list with one client per number: the token, the reason, then the action.'
        . ai_plain_text_rule();
}

function risk_ai_prompt(string $from, string $to, string $ctx): string {
    return "Today's date: " . date('Y-m-d') . "\nThe period: " . $from . ' to ' . $to . "\nActive-client signals:\n{$ctx}";
}
