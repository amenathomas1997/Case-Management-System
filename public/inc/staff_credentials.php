<?php
declare(strict_types=1);
// Staff credentials (4.16.0): certifications and checks staff must keep current (CPR, an AT
// professional certification, a background check), with expiry dates. Compliance lists those
// expired or expiring within 60 days, and the daily job opens a task 60 days before.

const CREDENTIAL_TYPES = ['CPR / First Aid', 'Assistive Technology Professional (ATP)', 'Certified Rehabilitation Counselor (CRC)', 'Certified Vision Rehabilitation Therapist (CVRT)', "Driver's license", 'Vehicle insurance', 'Background check', 'Other'];
const CREDENTIAL_WARN_DAYS = 60;

function credentials_for(int $userId): array {
    try {
        $q = db()->prepare('SELECT * FROM staff_credentials WHERE user_id = ? ORDER BY expires_on IS NULL, expires_on, name');
        $q->execute([$userId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return []; // before migration 4.16.0
    }
}

/** A credential's standing in words: expired, expiring soon, current, or no expiry. */
function credential_state(array $c): array {
    if (empty($c['expires_on'])) return ['current', 'no expiry date'];
    $today = date('Y-m-d');
    if ($c['expires_on'] < $today) return ['expired', 'expired ' . fmt_date((string)$c['expires_on'])];
    if ($c['expires_on'] <= date('Y-m-d', strtotime('+' . CREDENTIAL_WARN_DAYS . ' days'))) return ['soon', 'expires ' . fmt_date((string)$c['expires_on'])];
    return ['current', 'current until ' . fmt_date((string)$c['expires_on'])];
}

/** Every active person's credentials expired or expiring soon, in one query: [user id => [rows]]. */
function credentials_due(): array {
    $out = [];
    try {
        foreach (db()->query('SELECT c.*, u.name AS user_name FROM staff_credentials c JOIN users u ON u.id = c.user_id WHERE u.is_active = 1 AND c.expires_on IS NOT NULL
            AND c.expires_on <= DATE_ADD(CURDATE(), INTERVAL ' . CREDENTIAL_WARN_DAYS . ' DAY) ORDER BY c.expires_on')->fetchAll() as $r) $out[(int)$r['user_id']][] = $r;
    } catch (PDOException $e) {
    }
    return $out;
}

/** For Compliance: one person's credentials that need renewing, in words. */
function credential_notes(array $due): array {
    return array_map(fn($c) => $c['name'] . ': ' . credential_state($c)[1], $due);
}

/** A renewed, corrected or removed credential's open reminder tasks are closed (a later date opens its own). */
function credential_close_tasks(int $credentialId): void {
    try {
        db()->prepare("UPDATE tasks SET status = 'Done', done_at = NOW() WHERE status = 'Open' AND auto_key LIKE ?")->execute(['credential-' . $credentialId . '-%']);
    } catch (PDOException $e) {
    }
}

/** This person's own credentials expired or expiring soon (for their Home page). */
function credentials_due_for(int $userId): array {
    return array_values(array_filter(credentials_for($userId), fn($c) => credential_state($c)[0] !== 'current'));
}
