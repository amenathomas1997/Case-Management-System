<?php
declare(strict_types=1);

/**
 * Mixed caseload groups require unrestricted access, including historical attendance.
 * A scoped coordinator follows the same rule; membership counts current members only
 * (left_at IS NULL), since removed members' history is covered by the attendance check.
 * IMPORTANT: the returned SQL references the cohort table via the alias `co`, so every
 * caller must alias the cohorts table as `co` (e.g. `FROM cohorts co`). A different alias
 * will raise a SQL error rather than silently widening access.
 */
function cohort_access_sql(int $userId): string {
    if (!caseload_scoped()) return '';
    // A scoped coordinator is NOT exempt: a mixed-caseload group would expose other
    // instructors' clients. Only current members (left_at IS NULL) count.
    return ' AND ((NOT EXISTS (SELECT 1 FROM cohort_members scope_member
        JOIN clients scope_client ON scope_client.id = scope_member.client_id
        WHERE scope_member.cohort_id = co.id AND scope_member.left_at IS NULL AND (scope_client.assigned_to IS NULL OR scope_client.assigned_to <> ' . $userId . '))
        AND NOT EXISTS (SELECT 1 FROM cohort_sessions scope_session
        JOIN cohort_attendance scope_attendance ON scope_attendance.session_id = scope_session.id
        JOIN clients scope_client ON scope_client.id = scope_attendance.client_id
        WHERE scope_session.cohort_id = co.id AND (scope_client.assigned_to IS NULL OR scope_client.assigned_to <> ' . $userId . '))))';
}

function cohort_accessible(int $cohortId, int $userId): bool {
    $q = db()->prepare('SELECT co.id FROM cohorts co WHERE co.id = ?' . cohort_access_sql($userId));
    $q->execute([$cohortId]);
    return $q->fetchColumn() !== false;
}
