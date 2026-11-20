<?php
declare(strict_types=1);
/*
 * Device follow-ups (4.36.0: moved from followups.php, shared with the apps' followups action):
 * the totals the tiles count, and a recorded check's outcome in the website's words.
 */

/** Every pending check in scope ($scopeUser: a caseload-scoped user's id, else null): due now and upcoming. */
function followup_totals(?int $scopeUser): array {
    $cntQ = db()->prepare("SELECT COALESCE(SUM(f.due_date <= CURDATE()),0) AS due_n, COALESCE(SUM(f.due_date > CURDATE()),0) AS up_n
        FROM equipment_followups f JOIN clients c ON c.id = f.client_id JOIN equipment e ON e.id = f.equipment_id
        WHERE f.status = 'Pending'" . ($scopeUser !== null ? ' AND c.assigned_to = ?' : ''));
    $cntQ->execute($scopeUser !== null ? [$scopeUser] : []);
    $cnt = $cntQ->fetch() ?: ['due_n' => 0, 'up_n' => 0];
    return ['due' => (int)$cnt['due_n'], 'upcoming' => (int)$cnt['up_n']];
}

/** A recorded check's outcome as parts: [text, the page's class ('muted', 'field-error') or null]. */
function followup_outcome_parts(array $r): array {
    $parts = [];
    if ($r['status'] === 'Skipped') $parts[] = [($r['notes'] ?? null) === 'Device returned' ? 'skipped — device returned' : 'skipped', 'muted'];
    elseif ($r['in_use'] === null) { $parts[] = ['Use unknown', 'muted']; if ($r['satisfaction']) $parts[] = [' · ' . (int)$r['satisfaction'] . '/5', null]; }
    elseif ((int)$r['in_use'] === 1) $parts[] = ['In use' . ($r['satisfaction'] ? ' · ' . (int)$r['satisfaction'] . '/5' : ''), null];
    else { $parts[] = ['Not in use', 'field-error']; if ($r['barriers']) $parts[] = [' · ' . (string)$r['barriers'], null]; }
    // Answered by the client in the portal (3.0.42).
    if (!empty($r['client_reported'])) $parts[] = [' · reported by the client', null];
    return $parts;
}

/** A recorded check's outcome in plain words: "In use · 4/5 · reported by the client". */
function followup_outcome_text(array $r): string {
    return implode('', array_map(fn($p) => $p[0], followup_outcome_parts($r)));
}

/** The outcome as followups.php shows it (escaped, with its classes). */
function followup_outcome_html(array $r): string {
    return implode('', array_map(fn($p) => $p[1] !== null ? '<span class="' . $p[1] . '">' . h($p[0]) . '</span>' : h($p[0]), followup_outcome_parts($r)));
}
