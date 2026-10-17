<?php
declare(strict_types=1);

/**
 * Create a follow-up task. $autoKey makes system-generated tasks idempotent
 * (the same event never creates the task twice).
 */
function add_task(string $title, ?string $details, ?int $clientId, ?int $referralId,
                  ?string $due, ?int $assignedTo, ?int $createdBy, ?string $autoKey = null): bool {
    try {
        db()->prepare('INSERT ' . ($autoKey ? 'IGNORE ' : '') . 'INTO tasks
            (title, details, client_id, referral_id, due_date, assigned_to, created_by, auto_key)
            VALUES (?,?,?,?,?,?,?,?)')
            ->execute([mb_substr($title, 0, 190), $details, $clientId, $referralId, $due, $assignedTo, $createdBy, $autoKey]);
        return true;
    } catch (Throwable $e) {
        error_log('add_task failed: ' . get_class($e) . ' code=' . (string)$e->getCode() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        return false;
    }
}

/**
 * WHERE fragment (alias `t`) for which tasks a user may see. Admins see all;
 * everyone else sees tasks assigned to them (explicit delegation: an assignee
 * always sees, and can complete, a task given to them, even about a client
 * not on their caseload), plus unassigned tasks only when their permissions
 * cover what the task is about: referral tasks need the referrals permission
 * (and, when scoped, the referral in their scope: assigned to them,
 * unassigned and not yet converted, or converted into a client on their
 * caseload, as referral_in_scope()), client tasks need
 * the clients permission (and the client on their caseload when scoped).
 * Delegation covers the work, not the record: once a caseload-scoped user's
 * task about a client is done, it shows to them only while that client is on
 * their caseload (a reassigned client's done history goes with the client).
 */
function task_visibility_sql(array $user, array &$params): string {
    if (can('admin')) return '1=1';
    $params[] = (int)$user['id'];
    $mine = 't.assigned_to = ?';
    if (caseload_scoped()) {
        $mine = "(t.assigned_to = ? AND (t.status = 'Open' OR t.client_id IS NULL"
            . " OR EXISTS (SELECT 1 FROM clients tm WHERE tm.id = t.client_id AND tm.assigned_to = ?)))";
        $params[] = (int)$user['id'];
    }
    $or = ['(t.client_id IS NULL AND t.referral_id IS NULL)'];
    if (can('referrals')) {
        if (caseload_scoped()) {
            $or[] = "(t.referral_id IS NOT NULL AND EXISTS (SELECT 1 FROM referrals tr WHERE tr.id = t.referral_id AND ((tr.status <> 'Converted' AND (tr.assigned_to = ? OR tr.assigned_to IS NULL)) OR (tr.status = 'Converted' AND EXISTS (SELECT 1 FROM clients trc WHERE trc.id = tr.client_id AND trc.assigned_to = ?)))))";
            $params[] = (int)$user['id'];
            $params[] = (int)$user['id'];
        } else {
            $or[] = 't.referral_id IS NOT NULL';
        }
    }
    if (can('clients')) {
        if (caseload_scoped()) {
            $or[] = '(t.client_id IS NOT NULL AND EXISTS (SELECT 1 FROM clients tc WHERE tc.id = t.client_id AND tc.assigned_to = ?))';
            $params[] = (int)$user['id'];
        } else {
            $or[] = 't.client_id IS NOT NULL';
        }
    }
    return '(' . $mine . ' OR (t.assigned_to IS NULL AND (' . implode(' OR ', $or) . ')))';
}

/** Enforce task visibility on the write, including unassigned-task scope. Only
 *  an Open task can be completed and only a Done one reopened, so a stale
 *  second click never rewrites who completed it, or when. */
/** Whether a task this person can see is already Done (or, with $done false, already Open). */
function task_already(int $id, array $user, bool $done): bool {
    $params = [];
    $visibility = task_visibility_sql($user, $params);
    $q = db()->prepare('SELECT 1 FROM tasks t WHERE t.id = ? AND t.status = ' . ($done ? "'Done'" : "'Open'") . ' AND ' . $visibility);
    $q->execute(array_merge([$id], $params));
    return (bool)$q->fetchColumn();
}

function set_task_done(int $id, array $user, bool $done): bool {
    $params = [];
    $visibility = task_visibility_sql($user, $params);
    $where = ' WHERE t.id = ? AND t.status = ' . ($done ? "'Open'" : "'Done'") . ' AND ' . $visibility;
    if ($done) {
        $stmt = db()->prepare("UPDATE tasks t SET status = 'Done', done_at = NOW(), done_by = ?" . $where);
        $stmt->execute(array_merge([(int)$user['id'], $id], $params));
    } else {
        $stmt = db()->prepare("UPDATE tasks t SET status = 'Open', done_at = NULL, done_by = NULL" . $where);
        $stmt->execute(array_merge([$id], $params));
    }
    $changed = $stmt->rowCount() > 0;
    // Completing or reopening is audited like adding and deleting (website and app alike).
    if ($changed && function_exists('audit')) audit((int)$user['id'], $done ? 'task.done' : 'task.reopen', 'task', $id, $done ? 'completed' : 'reopened');
    return $changed;
}
