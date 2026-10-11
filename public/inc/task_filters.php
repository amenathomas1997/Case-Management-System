<?php
declare(strict_types=1);

function task_filters(array $input): array {
    return [
        'show' => ($input['show'] ?? '') === 'all' ? 'all' : 'open',
        'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 190) : '',
        'due' => in_array($input['due'] ?? '', ['overdue', 'today', 'week', 'none'], true) ? $input['due'] : 'any',
    ];
}

/** Append bound parameters in the same order as the returned SQL predicates. */
function task_filter_sql(array $filters, array &$params): string {
    $sql = $filters['show'] === 'all' ? '' : " AND t.status = 'Open'";
    $sql .= match ($filters['due']) {
        'overdue' => " AND t.status = 'Open' AND t.due_date < CURDATE()",
        'today' => " AND t.status = 'Open' AND t.due_date = CURDATE()",
        'week' => " AND t.status = 'Open' AND t.due_date >= CURDATE() AND t.due_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
        'none' => ' AND t.due_date IS NULL',
        default => '',
    };
    if ($filters['q'] !== '') {
        // Treat percent and underscore as literal text, not SQL wildcards.
        $like = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $sql .= " AND (t.title LIKE ? ESCAPE '!' OR t.details LIKE ? ESCAPE '!')";
        array_push($params, $like, $like);
    }
    return $sql;
}
