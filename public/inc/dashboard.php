<?php
declare(strict_types=1);
/*
 * The dashboard's "Today" panel and "My tasks" (4.36.0: moved from index.php, shared with the
 * apps' dashboard action). Only the areas the user can open are counted, with the visibility,
 * caseload scope and filters of the pages each item links to, so the numbers agree.
 * $user needs id (and whatever task_visibility_sql reads).
 */
require_once __DIR__ . '/task_filters.php';

/**
 * Today's items, in the website's order: tasks overdue, tasks due today, sessions today (with one
 * sub-line per session), unread messages, device follow-ups due, new client portal requests.
 * Each: ['kind', 'count', 'text', 'href', 'sub' => [['session_id', 'href', 'text'], …]].
 */
function dashboard_today(array $user): array {
    $scoped = caseload_scoped();
    $scopeAnd = $scoped ? ' AND c.assigned_to = ?' : '';
    $scopeParams = $scoped ? [(int)$user['id']] : [];
    $tvParams = [];
    $tvSql = task_visibility_sql($user, $tvParams);
    $items = [];
    foreach (['overdue' => 'overdue', 'today' => 'due today'] as $dueKey => $dueLabel) {
        $tp = $tvParams;
        $tfSql = task_filter_sql(task_filters(['due' => $dueKey]), $tp);
        $tcq = db()->prepare('SELECT COUNT(*) FROM tasks t WHERE ' . $tvSql . $tfSql);
        $tcq->execute($tp);
        $n = (int)$tcq->fetchColumn();
        if ($n > 0) $items[] = ['kind' => 'tasks_' . $dueKey, 'count' => $n, 'href' => 'tasks.php?due=' . $dueKey, 'text' => $n . ' task' . ($n === 1 ? '' : 's') . ' ' . $dueLabel, 'sub' => []];
    }
    if (can('schedule')) {
        // Today's sessions still open for an outcome, scoped like the Schedule page; each
        // links to its row there (the Upcoming or Ended-sessions table).
        $sq = db()->prepare("SELECT s.id, s.start_at, s.end_at, c.name AS client_name, t.title AS track_title
            FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id LEFT JOIN training_tracks t ON t.id = s.track_id
            WHERE s.status = 'Scheduled' AND DATE(s.start_at) = CURDATE()" . $scopeAnd . ' ORDER BY s.start_at, s.id');
        $sq->execute($scopeParams);
        $sessions = $sq->fetchAll();
        if ($sessions) {
            $n = count($sessions);
            $items[] = ['kind' => 'sessions', 'count' => $n, 'href' => 'schedule.php#upcoming-h', 'text' => $n . ' session' . ($n === 1 ? '' : 's') . ' today',
                'sub' => array_map(fn($s) => ['session_id' => (int)$s['id'], 'href' => 'schedule.php#s-' . (int)$s['id'],
                    'text' => date('g:i A', strtotime($s['start_at'])) . ' — ' . $s['client_name'] . ($s['track_title'] ? ' (' . $s['track_title'] . ')' : '')
                    . (strtotime($s['end_at']) < time() ? ', ended, awaiting an outcome' : '')], $sessions)];
        }
    }
    if (can('clients')) {
        $mq = db()->prepare("SELECT COUNT(*) FROM messages m JOIN clients c ON c.id = m.client_id WHERE m.sender <> 'staff' AND m.read_at IS NULL" . $scopeAnd);
        $mq->execute($scopeParams);
        if (($n = (int)$mq->fetchColumn()) > 0) $items[] = ['kind' => 'messages', 'count' => $n, 'href' => 'messages.php', 'text' => $n . ' unread message' . ($n === 1 ? '' : 's') . ' from clients and counselors', 'sub' => []];
        $fq = db()->prepare("SELECT COUNT(*) FROM equipment_followups f JOIN clients c ON c.id = f.client_id WHERE f.status = 'Pending' AND f.due_date <= CURDATE()" . $scopeAnd);
        $fq->execute($scopeParams);
        if (($n = (int)$fq->fetchColumn()) > 0) $items[] = ['kind' => 'followups', 'count' => $n, 'href' => 'followups.php#due-h', 'text' => $n . ' device follow-up' . ($n === 1 ? '' : 's') . ' due', 'sub' => []];
        $pq = db()->prepare("SELECT COUNT(*) FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.status = 'New'" . $scopeAnd);
        $pq->execute($scopeParams);
        if (($n = (int)$pq->fetchColumn()) > 0) $items[] = ['kind' => 'portal_requests', 'count' => $n, 'href' => 'portal_requests.php', 'text' => $n . ' new client portal request' . ($n === 1 ? '' : 's'), 'sub' => []];
    }
    return $items;
}

/** "Today (N items)", or "Today" when nothing is due. */
function dashboard_today_heading(array $items): string {
    return 'Today' . ($items ? ' (' . count($items) . ' item' . (count($items) === 1 ? '' : 's') . ')' : '');
}

/**
 * The first $limit open tasks the user can see (soonest due first, undated last), the real totals,
 * and whether any is someone else's (administrators see the whole team's open tasks, 2.4.6).
 */
function dashboard_my_tasks(array $user, int $limit = 6): array {
    $tvParams = [];
    $tvSql = task_visibility_sql($user, $tvParams);
    $tq = db()->prepare("SELECT t.*, c.name AS client_name, u.name AS assignee_name, DATEDIFF(t.due_date, CURDATE()) AS days_left
        FROM tasks t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.status = 'Open' AND " . $tvSql . "
        ORDER BY t.due_date IS NULL, t.due_date LIMIT " . max(1, $limit));
    $tq->execute($tvParams);
    $rows = $tq->fetchAll();
    $team = (bool)array_filter($rows, fn($t) => (int)($t['assigned_to'] ?? 0) !== (int)$user['id']);
    // Real totals — the list is only the first few.
    $tc = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(t.due_date IS NOT NULL AND t.due_date < CURDATE()), 0) overdue
        FROM tasks t WHERE t.status = 'Open' AND " . $tvSql);
    $tc->execute($tvParams);
    $totals = $tc->fetch();
    return ['rows' => $rows, 'total' => (int)$totals['n'], 'overdue' => (int)$totals['overdue'], 'team' => $team];
}

/** "My tasks (N, M overdue — first 6 shown)" or "Open tasks, yours and your team's (…)". */
function dashboard_tasks_heading(array $t): string {
    return ($t['team'] ? 'Open tasks, yours and your team\'s' : 'My tasks') . ' (' . $t['total'] . ($t['overdue'] ? ', ' . $t['overdue'] . ' overdue' : '')
        . ($t['total'] > count($t['rows']) ? ' — first ' . count($t['rows']) . ' shown' : '') . ')';
}

/** One task as the dashboard reads it, without links: "<title> — <client> (assigned to X) 3 days overdue". */
function dashboard_task_line(array $row, array $user, bool $team): string {
    $dl = $row['due_date'] !== null ? (int)$row['days_left'] : null;
    return $row['title'] . ($row['client_name'] && can('clients') ? ' — ' . $row['client_name'] : '')
        . ($team && (int)($row['assigned_to'] ?? 0) !== (int)$user['id'] ? ' (' . ($row['assignee_name'] ? 'assigned to ' . $row['assignee_name'] : 'unassigned') . ')' : '')
        . match (true) {
            $dl !== null && $dl < 0 => ' ' . abs($dl) . ' day' . (abs($dl) === 1 ? '' : 's') . ' overdue',
            $dl === 0 => ' (due today)',
            $dl !== null => ' (due ' . fmt_date($row['due_date']) . ')',
            default => '',
        };
}
