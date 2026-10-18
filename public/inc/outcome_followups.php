<?php
declare(strict_types=1);
require_once __DIR__ . '/lists.php';   // the lists and time limits set on screen (4.4.0)
// After a case closes (3.0.47): 30, 60 and 90-day follow-up checks (still employed or
// independent, still using the technology, needs more service), surveys answered by phone,
// and the outcome measures the Outcome report adds up.

const CLOSURE_FOLLOWUP_DAYS = [30, 60, 90];

/** Schedule the three follow-up checks for a closure (called by client_closure_record()). */
function closure_followups_schedule(int $clientId, int $closureId): void {
    try {
        // A correction from one Closed status to another is the same closure: its checks stand.
        $open = db()->prepare("SELECT COUNT(*) FROM closure_followups WHERE client_id = ? AND status = 'Pending'");
        $open->execute([$clientId]);
        if ((int)$open->fetchColumn() > 0) return;
        $ins = db()->prepare('INSERT INTO closure_followups (client_id, closure_id, interval_days, due_on) VALUES (?,?,?,?)');
        foreach (cms_days('closure_followup_days') as $d) $ins->execute([$clientId, $closureId, $d, date('Y-m-d', strtotime('+' . $d . ' days'))]);
    } catch (PDOException $e) {
        error_log('closure_followups_schedule: ' . $e->getCode()); // mig_3.0.47 not run yet
    }
}

/** A client's closure follow-ups, oldest first. */
function closure_followups(int $clientId): array {
    try {
        $q = db()->prepare('SELECT f.*, u.name AS by_name FROM closure_followups f LEFT JOIN users u ON u.id = f.completed_by WHERE f.client_id = ? ORDER BY f.due_on, f.id');
        $q->execute([$clientId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Record a follow-up check. Each answer is 1 (yes), 0 (no) or null (not asked). A client who
 * needs more service gets a re-referral task. Returns an error or null.
 */
function closure_followup_record(int $id, int $clientId, ?int $employed, ?int $usingTech, ?int $independent, ?int $needsService, string $notes, int $actor): ?string {
    $notes = mb_substr(trim($notes), 0, 1000);
    $u = db()->prepare("UPDATE closure_followups SET status = 'Completed', still_employed = ?, still_using_tech = ?, independent = ?, needs_service = ?, notes = ?,
        completed_at = NOW(), completed_by = ? WHERE id = ? AND client_id = ? AND status = 'Pending'");
    $u->execute([$employed, $usingTech, $independent, $needsService, $notes !== '' ? $notes : null, $actor, $id, $clientId]);
    if ($u->rowCount() !== 1) return 'That follow-up was already recorded.';
    complete_task_by_key('closefu-' . $id, $actor);
    $c = db()->prepare('SELECT name, assigned_to FROM clients WHERE id = ?');
    $c->execute([$clientId]);
    $cl = $c->fetch();
    if ($needsService === 1 && $cl) {
        add_task('Re-referral: ' . $cl['name'] . ' needs more service', 'A closure follow-up found this former client needs more service. '
            . ($notes !== '' ? 'Notes: ' . $notes . ' ' : '') . 'Ask the VR counselor for a new referral, or reopen the case.',
            $clientId, null, business_days_from(3), $cl['assigned_to'] ? (int)$cl['assigned_to'] : $actor, $actor, 'closefu-need-' . $id);
    }
    audit($actor, 'followup.closure', 'client', $clientId, 'closure follow-up #' . $id . ($needsService === 1 ? ' (needs more service)' : ''));
    return null;
}

/** Whether a client prefers phone or post, so the outcome survey is asked by phone, not emailed. */
function client_survey_by_phone(int $clientId): bool {
    $q = db()->prepare('SELECT a11y_contact FROM clients WHERE id = ?');
    $q->execute([$clientId]);
    return in_array((string)$q->fetchColumn(), ['Phone', 'Postal mail'], true);
}

/** Record survey answers taken by phone. Returns the field that is out of range, or null. */
function survey_record_phone(int $clientId, int $satisfaction, int $goalsMet, string $comments, int $actor): ?string {
    if ($satisfaction < 1 || $satisfaction > 10) return 'satisfaction';
    if ($goalsMet < 1 || $goalsMet > 10) return 'goals_met';
    $comments = mb_substr(trim($comments), 0, 2000);
    db()->prepare("INSERT INTO surveys (client_id, token, sent_at, completed_at, satisfaction, goals_met, comments, channel, recorded_by) VALUES (?,?,NOW(),NOW(),?,?,?,'Phone',?)")
        ->execute([$clientId, bin2hex(random_bytes(24)), $satisfaction, $goalsMet, $comments !== '' ? $comments : null, $actor]);
    audit($actor, 'survey.phone', 'client', $clientId, 'outcome survey answered by phone');
    return null;
}

/**
 * Outcome measures for a period (3.0.47), optionally for one instructor's caseload: devices
 * still in use (device follow-ups), skill gains (competency ratings), goals achieved (from the
 * dates progress was recorded), and the closure follow-up results.
 */
function outcome_measures(string $from, string $to, ?int $instructor = null): array {
    $scope = $instructor ? ' AND c.assigned_to = ' . (int)$instructor : '';
    $one = function (string $sql, array $p) { $q = db()->prepare($sql); $q->execute($p); return $q->fetch(); };
    $dev = $one("SELECT COUNT(*) AS n, COALESCE(SUM(f.in_use = 1), 0) AS yes FROM equipment_followups f JOIN clients c ON c.id = f.client_id
        WHERE f.status = 'Completed' AND f.in_use IS NOT NULL AND DATE(f.completed_at) BETWEEN ? AND ?" . $scope, [$from, $to]);
    // Skill gains: each client and skill with two or more ratings in the period, first to last.
    $q = db()->prepare("SELECT cc.client_id, cc.skill_id, cc.level FROM client_competency cc JOIN clients c ON c.id = cc.client_id
        WHERE DATE(cc.assessed_at) BETWEEN ? AND ?" . $scope . " ORDER BY cc.client_id, cc.skill_id, cc.assessed_at, cc.id");
    $q->execute([$from, $to]);
    $pairs = [];
    foreach ($q->fetchAll() as $r) { $k = $r['client_id'] . '-' . $r['skill_id']; $pairs[$k]['first'] ??= (int)$r['level']; $pairs[$k]['last'] = (int)$r['level']; $pairs[$k]['n'] = ($pairs[$k]['n'] ?? 0) + 1; }
    $gains = array_filter($pairs, fn($p) => $p['n'] >= 2);
    $improved = count(array_filter($gains, fn($p) => $p['last'] > $p['first']));
    $avgGain = $gains ? round(array_sum(array_map(fn($p) => $p['last'] - $p['first'], $gains)) / count($gains), 2) : null;
    $goals = $one("SELECT COUNT(DISTINCT gp.goal_id) AS n FROM goal_progress gp JOIN smart_goals g ON g.id = gp.goal_id JOIN training_tracks t ON t.id = g.track_id
        JOIN clients c ON c.id = t.client_id WHERE gp.status = 'Achieved' AND DATE(gp.created_at) BETWEEN ? AND ?" . $scope, [$from, $to]);
    try {
        $fu = $one("SELECT COUNT(*) AS n, COALESCE(SUM(f.still_employed = 1), 0) AS employed, COALESCE(SUM(f.still_employed IS NOT NULL), 0) AS employed_asked,
                COALESCE(SUM(f.still_using_tech = 1), 0) AS tech, COALESCE(SUM(f.still_using_tech IS NOT NULL), 0) AS tech_asked,
                COALESCE(SUM(f.needs_service = 1), 0) AS needs FROM closure_followups f JOIN clients c ON c.id = f.client_id
            WHERE f.status = 'Completed' AND DATE(f.completed_at) BETWEEN ? AND ?" . $scope, [$from, $to]);
    } catch (PDOException $e) { $fu = ['n' => 0, 'employed' => 0, 'employed_asked' => 0, 'tech' => 0, 'tech_asked' => 0, 'needs' => 0]; }
    $pct = fn($a, $b) => (int)$b > 0 ? (int)round(100 * (int)$a / (int)$b) : null;
    // At closure (4.24.0): devices recommended and acquired, in use, and employment with its wage.
    try {
        $cl = $one("SELECT COUNT(*) AS n, COALESCE(SUM(k.at_recommended), 0) AS rec, COALESCE(SUM(k.at_acquired), 0) AS got,
                COALESCE(SUM(k.at_in_use = 1), 0) AS in_use, COALESCE(SUM(k.at_in_use IS NOT NULL), 0) AS in_use_asked,
                COALESCE(SUM(k.employed = 1), 0) AS employed, COALESCE(SUM(k.employed IS NOT NULL), 0) AS employed_asked, AVG(CASE WHEN k.employed = 1 THEN k.hourly_wage END) AS wage
            FROM client_closures k JOIN clients c ON c.id = k.client_id WHERE DATE(k.closed_at) BETWEEN ? AND ?" . $scope, [$from, $to]);
    } catch (PDOException $e) { $cl = ['n' => 0, 'rec' => 0, 'got' => 0, 'in_use' => 0, 'in_use_asked' => 0, 'employed' => 0, 'employed_asked' => 0, 'wage' => null]; }
    return [
        'closures' => (int)$cl['n'], 'at_recommended' => (int)$cl['rec'], 'at_acquired' => (int)$cl['got'], 'at_acquired_pct' => $pct($cl['got'], $cl['rec']),
        'at_in_use_pct' => $pct($cl['in_use'], $cl['in_use_asked']), 'employed_at_closure_pct' => $pct($cl['employed'], $cl['employed_asked']),
        'average_wage_at_closure' => $cl['wage'] !== null ? round((float)$cl['wage'], 2) : null,
        'devices_checked' => (int)$dev['n'], 'devices_in_use_pct' => $pct($dev['yes'], $dev['n']),
        'skills_rated_twice' => count($gains), 'skills_improved' => $improved, 'average_skill_gain' => $avgGain,
        'goals_achieved' => (int)$goals['n'],
        'closure_followups' => (int)$fu['n'], 'still_employed_pct' => $pct($fu['employed'], $fu['employed_asked']),
        'still_using_tech_pct' => $pct($fu['tech'], $fu['tech_asked']), 'need_more_service' => (int)$fu['needs'],
    ];
}

/** Vision status choices (3.0.47), and the age bands the Outcome report counts by. */
const VISION_STATUSES = ['Totally blind', 'Legally blind', 'Low vision', 'Other visual impairment', 'No visual impairment'];
function age_band(?string $dob, string $asOf): string {
    if (!$dob) return 'Not recorded';
    $age = (int)(new DateTime($dob))->diff(new DateTime($asOf))->y;
    return $age < 14 ? 'Under 14' : ($age <= 21 ? '14 to 21' : ($age <= 54 ? '22 to 54' : ($age <= 64 ? '55 to 64' : '65 and over')));
}

/**
 * Clients served (a logged session) or closed in a period, counted by instructor, age band and
 * vision status, with each group's hours, closures and rehabilitations (3.0.47). Closures use
 * the same rule as outcome_metrics(), so each column adds up to the report's totals. $rt and
 * $rh are the report's caseload-scoped tables, as outcome_metrics() takes them.
 */
function outcome_breakdowns(string $from, string $to, string $rt = 'training_tracks', string $rh = 'client_status_history'): array {
    $q = db()->prepare("SELECT t.client_id, COALESCE(SUM(w.hours), 0) AS hrs FROM work_logs w JOIN {$rt} t ON t.id = w.track_id
        WHERE w.work_date BETWEEN ? AND ? GROUP BY t.client_id");
    $q->execute([$from, $to]);
    $hours = [];
    foreach ($q->fetchAll() as $r) $hours[(int)$r['client_id']] = (float)$r['hrs'];
    // Each client's closure in the period: their last status change, into a Closed status, and
    // only when the case moved from open to Closed in the period (as outcome_metrics()).
    $cl = db()->prepare("SELECT h.client_id, h.new_status FROM {$rh} h WHERE h.new_status LIKE 'Closed%' AND DATE(h.changed_at) BETWEEN ? AND ?
        AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')
        AND NOT EXISTS (SELECT 1 FROM client_status_history h2 WHERE h2.client_id = h.client_id AND DATE(h2.changed_at) BETWEEN ? AND ?
            AND (h2.note IS NULL OR h2.note NOT LIKE '[merged] %') AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id)))
        AND EXISTS (SELECT 1 FROM client_status_history h3 WHERE h3.client_id = h.client_id AND DATE(h3.changed_at) BETWEEN ? AND ?
            AND (h3.note IS NULL OR h3.note NOT LIKE '[merged] %') AND h3.new_status LIKE 'Closed%' AND (h3.old_status IS NULL OR h3.old_status NOT LIKE 'Closed%'))");
    $cl->execute([$from, $to, $from, $to, $from, $to]);
    $closed = [];
    foreach ($cl->fetchAll() as $r) $closed[(int)$r['client_id']] = $r['new_status'];
    $ids = array_keys($hours + $closed);
    $rows = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $cq = db()->prepare("SELECT c.id, c.dob, c.vision_status, COALESCE(u.name, 'Unassigned') AS instructor FROM clients c LEFT JOIN users u ON u.id = c.assigned_to
            WHERE c.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $cq->execute($chunk);
        foreach ($cq->fetchAll() as $r) { $r['hrs'] = $hours[(int)$r['id']] ?? 0.0; $rows[] = $r; }
    }
    $out = ['instructor' => [], 'age' => [], 'vision' => []];
    foreach ($rows as $r) {
        $keys = ['instructor' => $r['instructor'], 'age' => age_band($r['dob'], $to), 'vision' => $r['vision_status'] ?: 'Not recorded'];
        foreach ($keys as $dim => $k) {
            $g = &$out[$dim][$k];
            $g ??= ['clients' => 0, 'hours' => 0.0, 'closed' => 0, 'rehab' => 0];
            if (isset($hours[(int)$r['id']])) $g['clients']++;
            $g['hours'] += (float)$r['hrs'];
            if (isset($closed[(int)$r['id']])) { $g['closed']++; if ($closed[(int)$r['id']] === 'Closed - Rehabilitated') $g['rehab']++; }
            unset($g);
        }
    }
    ksort($out['instructor'], SORT_NATURAL | SORT_FLAG_CASE);
    ksort($out['vision'], SORT_NATURAL | SORT_FLAG_CASE);
    $ageOrder = array_flip(['Under 14', '14 to 21', '22 to 54', '55 to 64', '65 and over', 'Not recorded']);
    uksort($out['age'], fn($a, $b) => $ageOrder[$a] <=> $ageOrder[$b]);
    return $out;
}
