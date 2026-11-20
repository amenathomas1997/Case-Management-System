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

/** The status a reopened device request resumes at (4.34.0: shared by procurement.php and the app's
 *  device_request_update): the furthest step it had recorded (delivery, order, authorization,
 *  recommended quote, any quote), so its status agrees with its lifecycle and it is counted and
 *  filtered where it belongs. Nothing can change those steps while it is closed. $r needs
 *  delivered_at, ordered_at, authorized_at and selected_quote_id. */
function device_request_reopen_status(array $r, int $requestId): string {
    if (!empty($r['delivered_at'])) return 'Delivered';
    if (!empty($r['ordered_at'])) return 'Ordered';
    if (!empty($r['authorized_at'])) return 'Authorized';
    if (!empty($r['selected_quote_id'])) return 'Awaiting Authorization';
    $qc = db()->prepare('SELECT COUNT(*) FROM device_request_quotes WHERE request_id = ?');
    $qc->execute([$requestId]);
    return (int)$qc->fetchColumn() > 0 ? 'Quoting' : 'Requested';
}

/** Add a delivered device to the loaner inventory, once per request however often the delivery is
 *  saved (4.37.0: moved from procurement.php, shared with the app's device_request_update deliver).
 *  Only when asked and the caller has the equipment permission. Runs inside the caller's lock on the
 *  request. The item's note names the request, not the client: the inventory is visible to staff
 *  outside the client's caseload. Returns ['state' => 'added'|'already'|null, 'message' (the
 *  website's confirmation), 'audit' (device.deliver's detail, or null)]. */
function device_delivery_inventory(int $rid, array $r, bool $wanted, ?string $serial): array {
    $state = null;
    if ($wanted && can('equipment')) {
        $marker = 'From device request #' . $rid;
        $have = db()->prepare('SELECT id FROM equipment WHERE notes = ? OR notes LIKE ? LIMIT 1');
        $have->execute([$marker, $marker . ' for %']); // second form: rows written before 3.0.8
        if (!$have->fetchColumn()) {
            db()->prepare('INSERT INTO equipment (name, category, serial, item_condition, notes) VALUES (?,?,?,?,?)')
                ->execute([$r['device_name'], 'Other', $serial !== null && $serial !== '' ? mb_substr($serial, 0, 120) : null, 'New', $marker]);
            $state = 'added';
        } else {
            $state = 'already';
        }
    }
    $message = 'Delivery recorded' . ($state === 'added' ? ' and the device added to the loaner inventory.'
        : ($state === 'already' ? '. The device was already in the loaner inventory from this request, so it was not added again.'
        : (can('equipment') ? '. The device was not added to the loaner inventory.' : '.')));
    return ['state' => $state, 'message' => $message, 'audit' => $state === 'added' ? 'added to the loaner inventory' : null];
}

/** The note kept when a request is denied or cancelled (procurement.php's reason): what was sent,
 *  trimmed and cut at 4,000 characters; a field not sent keeps the stored note. Null for none. */
function device_close_note(mixed $raw, ?string $stored): ?string {
    // Not sent, or not one value (an array from a crafted post): the stored note is kept, as before.
    $v = is_string($raw) ? trim($raw) : (string)($stored ?? '');
    $v = mb_substr($v, 0, 4000);
    return $v !== '' ? $v : null;
}

/** A new device request's fields as procurement.php's form checks them (4.37.0: shared with the app's
 *  add_device_request): the device named (190 characters at most) and the justification no longer
 *  than 4,000 characters (each line break counted once), both refused, not cut. The client is the
 *  caller's to check. Returns [errors, values{device_name, category, justification}]. */
function device_request_input(array $in): array {
    $name = is_string($in['device_name'] ?? null) ? trim($in['device_name']) : '';
    $justification = is_string($in['justification'] ?? null) ? trim(str_replace("\r\n", "\n", $in['justification'])) : '';
    $errors = array_filter([
        'device_name' => $name === '' ? 'Name the device or technology.' : (mb_strlen($name) > 190 ? 'Device / technology: use 190 characters or fewer (you entered ' . mb_strlen($name) . ').' : null),
        'justification' => mb_strlen($justification) > 4000 ? 'Justification of need: use 4,000 characters or fewer (you entered ' . number_format(mb_strlen($justification)) . ').' : null,
    ]);
    return [$errors, ['device_name' => $name,
        'category' => in_array($in['category'] ?? '', cms_list_items('device_request_categories'), true) ? (string)$in['category'] : 'Other',
        'justification' => $justification !== '' ? $justification : null]];
}

/** The default "Justification of need" when the request has none. */
const DEVICE_JUSTIFICATION_DEFAULT = 'The recommended technology is necessary for the client to access print and electronic material, complete essential job functions, and achieve the vocational goal identified in their Individualized Plan for Employment (IPE). Without this technology and associated training, the client cannot independently perform the required tasks.';

/** The funding justification letter for one request (4.37.0: moved from procurement.php, shared with
 *  the app's device_letter). $r is the request with client_name; the caller has checked its scope.
 *  Returns ['doc' => AccessibleDocument, 'title', 'text' (the same letter as plain text), 'file']. */
function device_letter_doc(array $r, array $user): array {
    require_once __DIR__ . '/document.php';
    $sel = null;
    if (!empty($r['selected_quote_id'])) {
        $q = db()->prepare('SELECT * FROM device_request_quotes WHERE id = ? AND request_id = ?');
        $q->execute([(int)$r['selected_quote_id'], (int)$r['id']]);
        $sel = $q->fetch() ?: null;
    }
    // Titled and named with the client, like the PDF letters, so a saved copy is found by name.
    $title = 'Assistive Technology Funding Justification — ' . $r['client_name'];
    $doc = new AccessibleDocument($title);
    $org = brand('org');
    $doc->paragraph($org);
    $props = array_filter([
        'Client' => (string)$r['client_name'],
        'Device / technology' => $r['device_name'] . ($r['category'] ? ' (' . $r['category'] . ')' : ''),
        'Recommended vendor' => $sel ? (string)$sel['vendor'] : '',
        'Estimated cost' => $sel ? fmt_money((float)$sel['amount']) : '',
        'Funding source' => (string)($r['funding_source'] ?? ''),
        'Authorization #' => (string)($r['auth_number'] ?? ''),
        'Prepared' => date('F j, Y') . ' by ' . ($user['name'] ?? ''),
    ]);
    $doc->properties($props);
    $doc->heading('Justification of need');
    $just = trim((string)($r['justification'] ?? '')) !== '' ? (string)$r['justification'] : DEVICE_JUSTIFICATION_DEFAULT;
    $doc->paragraph($just);
    $text = $title . "\n\n" . $org . "\n\n" . implode("\n", array_map(fn($k, $v) => $k . ': ' . $v, array_keys($props), $props)) . "\n\nJustification of need\n\n" . $just;
    return ['doc' => $doc, 'title' => $title, 'text' => $text,
        'file' => 'justification_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string)$r['client_name']) . '_' . date('Ymd') . '.html'];
}
