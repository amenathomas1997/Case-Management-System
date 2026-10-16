<?php
declare(strict_types=1);
// "My devices" in the client portal (3.0.42): the client's open equipment loans with their
// due dates, open repairs, and the 30/60/90-day device check-ins they can answer
// themselves (saved as client-reported). Loan reminders to the client are sent by
// cron/daily_alerts.php (loan_reminders()).

/** Open loans: [name, loaned_at, due_date] */
function portal_device_loans(int $clientId): array {
    $q = db()->prepare('SELECT l.id, l.loaned_at, l.due_date, e.name FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id
        WHERE l.client_id = ? AND l.returned_at IS NULL ORDER BY l.due_date IS NULL, l.due_date, l.id');
    $q->execute([$clientId]);
    return $q->fetchAll();
}

/** Open repairs (AT repair records not resolved or cancelled). */
function portal_device_repairs(int $clientId): array {
    try {
        $q = db()->prepare("SELECT title, status, updated_at FROM at_records WHERE client_id = ? AND kind = 'repair' AND status NOT IN ('Resolved','Cancelled') ORDER BY updated_at DESC");
        $q->execute([$clientId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Device check-ins the client can answer: pending, due within a week or overdue. */
function portal_device_checkins(int $clientId): array {
    $q = db()->prepare("SELECT f.id, f.interval_days, f.due_date, e.name FROM equipment_followups f JOIN equipment e ON e.id = f.equipment_id
        WHERE f.client_id = ? AND f.status = 'Pending' AND f.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY f.due_date, f.id");
    $q->execute([$clientId]);
    return $q->fetchAll();
}

/**
 * Save a check-in the client answered. Only a still-Pending check-in of this client is
 * recorded (once); a device no longer in use adds a case note and a task for the
 * instructor, as when staff record it. Returns false when there was nothing to record.
 */
function portal_device_checkin_save(int $followupId, array $client, ?int $inUse, ?int $sat, string $barriers): bool {
    $barriers = mb_substr(trim($barriers), 0, 255);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $f = $pdo->prepare("SELECT f.*, e.name AS equipment_name FROM equipment_followups f JOIN equipment e ON e.id = f.equipment_id
            WHERE f.id = ? AND f.client_id = ? AND f.status = 'Pending' FOR UPDATE");
        $f->execute([$followupId, (int)$client['id']]);
        $row = $f->fetch();
        if (!$row) { $pdo->rollBack(); return false; }
        $pdo->prepare("UPDATE equipment_followups SET status = 'Completed', in_use = ?, satisfaction = ?, barriers = ?, notes = ?, completed_at = NOW(),
                completed_by = NULL, client_reported = 1 WHERE id = ?")
            ->execute([$inUse, $sat, $barriers !== '' ? $barriers : null, 'Answered by the client in the portal.', $followupId]);
        if ($inUse === 0) {
            $pdo->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?, 'Case Note', ?, ?, NULL)")
                ->execute([(int)$client['id'], mb_substr('Device no longer in use: ' . $row['equipment_name'], 0, 255),
                    'In the portal, the client said "' . $row['equipment_name'] . '" is no longer in use (' . (int)$row['interval_days'] . '-day check-in).'
                    . ($barriers !== '' ? ' What gets in the way: ' . $barriers . '.' : '')]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit(null, 'followup.complete', 'client', (int)$client['id'], ($inUse === 0 ? 'device not in use' : ($inUse === 1 ? 'device in use' : 'use unknown')) . ' — ' . $row['equipment_name'] . ' (client, portal)');
    if ($inUse === 0) {
        add_task('Follow up on abandoned device: ' . $row['equipment_name'] . ' (' . $client['name'] . ')',
            'The client said in the portal that this item is no longer in use. Re-assess fit, training, or reassignment.',
            (int)$client['id'], null, date('Y-m-d', strtotime('+3 days')), (int)($client['active_owner'] ?? 0) ?: null, null, 'abandon-' . $followupId);
    }
    return true;
}

/**
 * Loan reminders to the client (run daily): three days before a loan is due, and the day
 * after it is overdue, each once. Only a current client with a confirmed email who does not
 * prefer phone or post, with the portal link when they have one.
 */
function loan_reminders(): int {
    $sent = 0;
    $rows = db()->query("SELECT l.id, l.due_date, e.name AS equipment, c.id AS cid, c.name, c.email, c.email_verified, c.a11y_contact, c.preferred_language,
            p.token AS portal_token, DATEDIFF(l.due_date, CURDATE()) AS days
        FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id JOIN clients c ON c.id = l.client_id
        LEFT JOIN portal_tokens p ON p.client_id = c.id AND (p.expires_at IS NULL OR p.expires_at > NOW())
        WHERE l.returned_at IS NULL AND l.due_date IS NOT NULL AND DATEDIFF(l.due_date, CURDATE()) IN (3, -1)
          AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'")->fetchAll();
    foreach ($rows as $r) {
        if ((int)$r['email_verified'] !== 1 || !filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) || in_array($r['a11y_contact'] ?? '', ['Phone', 'Postal mail'], true)) continue;
        $overdue = (int)$r['days'] < 0;
        $key = ($overdue ? 'loan-over-' : 'loan-due-') . (int)$r['id'];
        $seen = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ?');
        $seen->execute([$key]);
        if ((int)$seen->fetchColumn() > 0) continue;
        $es = client_lang($r) === 'es';
        [$subj, $body] = email_template_for_client($overdue ? 'loan_overdue_client' : 'loan_due_client', $r, [
            'name' => $r['name'], 'item' => $r['equipment'],
            'date' => $es ? when_es(strtotime((string)$r['due_date']), false) : date('l, F j', strtotime((string)$r['due_date'])),
            'portal_link' => $r['portal_token'] ? cfg('base_url') . '/portal.php?t=' . $r['portal_token'] . ($es ? '&lang=es' : '') . '#dev-h' : '',
            'contact' => trim((string)(cfg('support_email') ?: cfg('mail_from')))]);
        if (send_app_mail((string)$r['email'], $subj, $body)) {
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES ('', ?, 1)")->execute([$key]);
            $sent++;
        }
    }
    return $sent;
}
