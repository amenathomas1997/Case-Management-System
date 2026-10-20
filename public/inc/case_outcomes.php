<?php
declare(strict_types=1);
require_once __DIR__ . '/lists.php';   // the lists and time limits set on screen (4.4.0)
// Referral outcomes and case closure (3.0.35). A referral that does not become a client is
// Closed with a reason, and the referrer is emailed the outcome automatically (owner
// decision, 2026-10-16), with the reference number and a general reason only. A referral for
// someone already on file is attached to their record, which is reopened when it was closed
// (the recommended default). A case closes with a reason from the agreed list and an
// optional outcome. The website, the app's API and voice all use these.

/** Referral close reasons => the general phrase the referrer's email gives (never the note). */
const REFERRAL_CLOSE_REASONS = [
    'Declined services' => 'the person decided not to go ahead with services',
    'Not eligible' => 'the request is outside the services we can provide',
    'Withdrawn' => 'the referral was withdrawn',
    'Unable to reach' => 'we were not able to reach the person after several attempts',
    'Duplicate' => 'it repeats a referral we have already received',
    'Referred elsewhere' => 'the person was referred to another provider',
    'Other' => 'we are not able to provide this service at this time',
];

/** The Spanish words for the built-in close reasons (4.30.0), for a referral sent on the Spanish form.
 *  Words an administrator changed are looked up in the Spanish dictionary, and are sent as written
 *  when there is no translation. */
const REFERRAL_CLOSE_REASONS_ES = [
    'the person decided not to go ahead with services' => 'la persona decidió no seguir adelante con los servicios',
    'the request is outside the services we can provide' => 'lo que se pide está fuera de los servicios que podemos ofrecer',
    'the referral was withdrawn' => 'el referido fue retirado',
    'we were not able to reach the person after several attempts' => 'no pudimos comunicarnos con la persona después de varios intentos',
    'it repeats a referral we have already received' => 'repite un referido que ya recibimos',
    'the person was referred to another provider' => 'la persona fue referida a otro proveedor',
    'we are not able to provide this service at this time' => 'no podemos ofrecer este servicio en este momento',
];

/** The words the referrer's email gives for $reason, in Spanish when $es. */
function referral_close_words(string $reason, bool $es): string {
    $words = (string)(cms_list('referral_close_reasons')[$reason] ?? '');
    if (!$es) return $words;
    if (isset(REFERRAL_CLOSE_REASONS_ES[$words])) return REFERRAL_CLOSE_REASONS_ES[$words];
    static $dict = null;
    if ($dict === null) { $dict = @include __DIR__ . '/lang_es.php'; if (!is_array($dict)) $dict = []; }
    return isset($dict[$words]) && is_string($dict[$words]) ? $dict[$words] : $words;
}

/** Case closure reasons (owner's list, 2026-10-16). 'Not recorded' is only written for a
 *  closure from an older app build or voice, which do not ask. */
const CLOSURE_REASONS = ['Employed', 'Goals met', 'Declined further services', 'Unable to contact', 'Moved away',
    'Health or medical', 'Not eligible', 'Transferred to another provider', 'Other'];

/** A referral's reference number, as the referrer is told it. */
function referral_ref(int $id): string {
    return 'REF-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/**
 * Close an open referral (New or Reviewed) with a reason, and email the referrer the
 * outcome. Returns ['error' => ?string, 'emailed' => bool]. The contact task is completed
 * and a waitlist entry is closed with it.
 */
function referral_close(int $id, string $reason, string $note, int $actor): array {
    if (!isset(cms_list('referral_close_reasons')[$reason])) return ['error' => 'Choose a reason from the list.', 'emailed' => false];
    $note = trim($note);
    if ($reason === 'Other' && $note === '') return ['error' => 'Give a short note for Other.', 'emailed' => false];
    if (mb_strlen($note) > 255) return ['error' => 'Keep the note to 255 characters or fewer.', 'emailed' => false];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM referrals WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $ref = $q->fetch();
        if (!$ref || !in_array($ref['status'], ['New', 'Reviewed'], true)) {
            $pdo->rollBack();
            return ['error' => 'Only an open referral can be closed' . ($ref ? '; this one is ' . $ref['status'] . '.' : '.'), 'emailed' => false];
        }
        $pdo->prepare("UPDATE referrals SET status = 'Closed', closed_reason = ?, closed_note = ?, closed_at = NOW(), closed_by = ? WHERE id = ?")
            ->execute([$reason, $note !== '' ? $note : null, $actor, $id]);
        $pdo->prepare("UPDATE referral_waitlist SET status = 'Closed' WHERE referral_id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    complete_task_by_key('ref-contact-' . $id, $actor);
    // The outcome goes to whoever sent the referral, automatically: no client details.
    $emailed = false;
    $to = trim((string)$ref['counselor_email']);
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        // 4.30.0: a referral sent on the Spanish form is answered in Spanish, with a Spanish date and reason.
        $es = ($ref['preferred_language'] ?? 'en') === 'es';
        [$subj, $body] = email_template($es ? 'referral_closed_es' : 'referral_closed', ['ref' => referral_ref($id),
            'date' => $es ? when_es($createdTs = (int)strtotime((string)$ref['created_at']), false) . ' de ' . date('Y', $createdTs) : fmt_date($ref['created_at']),
            'reason' => referral_close_words($reason, $es)]);
        if (send_app_mail($to, $subj, $body)) {
            $emailed = true;
            db()->prepare('UPDATE referrals SET outcome_emailed_at = NOW() WHERE id = ?')->execute([$id]);
        }
    }
    audit($actor, 'referral.close', 'referral', $id, $reason . ($note !== '' ? ': ' . mb_substr($note, 0, 150) : '') . ($emailed ? '; outcome emailed to the referrer' : '; no outcome email sent'));
    return ['error' => null, 'emailed' => $emailed];
}

/** Reopen a Closed referral (closed by mistake): back to New. */
function referral_reopen(int $id, int $actor): ?string {
    // What closing settled is undone with it (4.29.0): the "Contact new referral" task is open
    // again, due within the agency's time to contact from today, and a waitlist entry is Waiting,
    // so the referral is back in the action center and is not reported as contacted.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM referrals WHERE id = ? FOR UPDATE");
        $q->execute([$id]);
        $ref = $q->fetch();
        if (!$ref || $ref['status'] !== 'Closed') { $pdo->rollBack(); return 'Only a closed referral can be reopened.'; }
        $pdo->prepare("UPDATE referrals SET status = 'New', closed_reason = NULL, closed_note = NULL, closed_at = NULL, closed_by = NULL WHERE id = ?")->execute([$id]);
        $days = function_exists('agency_contact_days') ? agency_contact_days(function_exists('referral_agency') ? referral_agency($ref) : null) : 3;
        $pdo->prepare("UPDATE tasks SET status = 'Open', done_at = NULL, done_by = NULL, due_date = ? WHERE auto_key = ? AND status <> 'Open'")
            ->execute([business_days_from($days), 'ref-contact-' . $id]);
        try {
            $pdo->prepare("UPDATE referral_waitlist SET status = 'Waiting', version = version + 1 WHERE referral_id = ? AND status = 'Closed'")->execute([$id]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S02') throw $e; // the waitlist table from a later migration
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($actor, 'referral.reopen', 'referral', $id, null);
    return null;
}

/**
 * Attach an open referral to a client already on file, instead of creating a new record.
 * A closed or archived client is reopened to Pending Assessment, with a history note citing
 * the referral. The intake note goes on their record, the contact task is completed and a
 * follow-up task is opened. Returns ['error' => ?string, 'reopened' => bool].
 */
function referral_attach(int $id, int $clientId, int $actor): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM referrals WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $ref = $q->fetch();
        if (!$ref || !in_array($ref['status'], ['New', 'Reviewed'], true)) {
            $pdo->rollBack();
            return ['error' => 'Only an open referral can be attached' . ($ref ? '; this one is ' . $ref['status'] . '.' : '.'), 'reopened' => false];
        }
        $c = $pdo->prepare('SELECT * FROM clients WHERE id = ? FOR UPDATE');
        $c->execute([$clientId]);
        $client = $c->fetch();
        if (!$client) { $pdo->rollBack(); return ['error' => 'That client record no longer exists.', 'reopened' => false]; }
        $reopen = $client['archived_at'] !== null || str_starts_with((string)$client['status'], 'Closed');
        if ($reopen) {
            record_status_change($clientId, (string)$client['status'], 'Pending Assessment', $actor, 'Reopened: referred again (' . referral_ref($id) . ')');
            $pdo->prepare("UPDATE clients SET status = 'Pending Assessment', archived_at = NULL WHERE id = ?")->execute([$clientId]);
        }
        $intake = referral_intake_note($ref);
        $pdo->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?)')
            ->execute([$clientId, 'Intake', $intake['subject'], $intake['body'], $actor]);
        // The referral follows the client's instructor, as a converted one does (4.28.0), so it is
        // never shown to its former assignee once it belongs to someone else's caseload.
        $pdo->prepare("UPDATE referrals SET status = 'Converted', client_id = ?, assigned_to = ? WHERE id = ?")
            ->execute([$clientId, $client['assigned_to'], $id]);
        // Its answers to the organisation's own questions (4.24.0) fill in what the record has not answered.
        if (function_exists('custom_copy_referral_to_client')) custom_copy_referral_to_client($id, $clientId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    complete_task_by_key('ref-contact-' . $id, $actor);
    add_task('Follow up the new referral for ' . $client['name'], 'Referral ' . referral_ref($id) . ' was attached to this record' . ($reopen ? ', which was reopened' : '') . '. Contact the person and plan the next step.',
        $clientId, null, date('Y-m-d', strtotime('+3 days')), $client['assigned_to'] !== null ? (int)$client['assigned_to'] : $actor, $actor, 'reref-' . $id);
    audit($actor, 'referral.attach', 'client', $clientId, 'referral #' . $id . ($reopen ? '; record reopened to Pending Assessment' : ''));
    return ['error' => null, 'reopened' => $reopen];
}

/**
 * The closure details typed with a Closed status: [data, errors]. $src keys are
 * closure_reason, closure_note, closure_employed ('yes', 'no' or ''), closure_employer,
 * closure_job_title, closure_job_start, closure_hours, closure_wage, closure_independence.
 */
function closure_input(array $src): array {
    $s = fn(string $k): string => trim(is_string($src[$k] ?? null) ? $src[$k] : '');
    $e = [];
    $reason = $s('closure_reason'); $note = $s('closure_note');
    if (!in_array($reason, cms_list_items('closure_reasons'), true)) $e['closure_reason'] = 'Choose why the case is closing.';
    if ($reason === 'Other' && $note === '') $e['closure_note'] = 'Give a short note for Other.';
    if (mb_strlen($note) > 255) $e['closure_note'] = 'Keep the note to 255 characters or fewer.';
    $emp = $s('closure_employed');
    $employed = $emp === 'yes' ? 1 : ($emp === 'no' ? 0 : null);
    $start = $s('closure_job_start');
    $startOk = $start === '' ? null : valid_date($start, 2000, (int)date('Y') + 1);
    if ($start !== '' && $startOk === null) $e['closure_job_start'] = 'Enter the job start date as a real date, or leave it blank.';
    $num = function (string $k, float $max, string $what) use ($s, &$e): ?float {
        $v = str_replace([',', '$'], '', $s($k));
        if ($v === '') return null;
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > $max) { $e[$k] = "Enter the $what as a number from 0 to " . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . ', or leave it blank.'; return null; }
        return round((float)$v, 2);
    };
    $hours = $num('closure_hours', 80, 'hours a week');
    $wage = $num('closure_wage', 999.99, 'hourly wage');
    // Assistive technology outcomes (4.24.0): devices recommended and acquired, and whether they are in use.
    $count = function (string $k, string $what) use ($s, &$e): ?int {
        if ($s($k) === '') return null;
        if (!preg_match('/^\d{1,2}$/', $s($k))) { $e[$k] = "Enter the number of $what as a whole number from 0 to 99, or leave it blank."; return null; }
        return (int)$s($k);
    };
    $atRec = $count('closure_at_recommended', 'devices recommended');
    $atAcq = $count('closure_at_acquired', 'devices acquired');
    $inUse = $s('closure_at_in_use');
    foreach (['closure_employer' => 190, 'closure_job_title' => 120, 'closure_independence' => 500, 'closure_at_note' => 255] as $k => $max) {
        if (mb_strlen($s($k)) > $max) $e[$k] = "Keep it to $max characters or fewer.";
    }
    return [[
        'reason' => $reason, 'note' => $note !== '' ? $note : null, 'employed' => $employed,
        'employer' => $s('closure_employer') ?: null, 'job_title' => $s('closure_job_title') ?: null, 'job_start' => $startOk,
        'hours_per_week' => $hours, 'hourly_wage' => $wage, 'independence_outcome' => $s('closure_independence') ?: null,
        'at_recommended' => $atRec, 'at_acquired' => $atAcq, 'at_in_use' => $inUse === 'yes' ? 1 : ($inUse === 'no' ? 0 : null), 'at_note' => $s('closure_at_note') ?: null,
    ], $e];
}

/** Record a case closure (call it in the status change's transaction). */
function client_closure_record(int $clientId, string $status, array $d, ?int $actor): void {
    db()->prepare('INSERT INTO client_closures (client_id, status, reason, note, employed, employer, job_title, job_start, hours_per_week, hourly_wage, independence_outcome, closed_by,
            at_recommended, at_acquired, at_in_use, at_note)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$clientId, $status, $d['reason'], $d['note'], $d['employed'], $d['employer'], $d['job_title'],
        $d['job_start'], $d['hours_per_week'], $d['hourly_wage'], $d['independence_outcome'], $actor,
        $d['at_recommended'] ?? null, $d['at_acquired'] ?? null, $d['at_in_use'] ?? null, $d['at_note'] ?? null]);
    // 30, 60 and 90-day follow-up checks after every closure (3.0.47).
    closure_followups_schedule($clientId, (int)db()->lastInsertId());
}

/** A client's devices as the device requests record them (4.24.0): [recommended, acquired], to start the closure counts. */
function client_device_counts(int $clientId): array {
    try {
        $q = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(delivered_at IS NOT NULL), 0) AS got FROM device_requests WHERE client_id = ? AND status NOT IN ('Cancelled', 'Denied')");
        $q->execute([$clientId]);
        $r = $q->fetch();
        return [(int)$r['n'], (int)$r['got']];
    } catch (PDOException $e) {
        return [0, 0];
    }
}

/** The closure recorded with a closed status change at $when (the one nearest before it, within a day), or null. */
function client_closure_at(int $clientId, string $when): ?array {
    $q = db()->prepare('SELECT * FROM client_closures WHERE client_id = ? AND closed_at <= DATE_ADD(?, INTERVAL 1 MINUTE) AND closed_at >= DATE_SUB(?, INTERVAL 1 DAY) ORDER BY closed_at DESC, id DESC LIMIT 1');
    $q->execute([$clientId, $when, $when]);
    return $q->fetch() ?: null;
}

/** The latest closure recorded for a client, or null. */
function client_last_closure(int $clientId): ?array {
    $q = db()->prepare('SELECT * FROM client_closures WHERE client_id = ? ORDER BY closed_at DESC, id DESC LIMIT 1');
    $q->execute([$clientId]);
    return $q->fetch() ?: null;
}

/** Loose ends to settle before (or after) closing a case: one sentence each, with a link. */
function closure_checklist(int $clientId): array {
    $one = function (string $sql) use ($clientId) { $q = db()->prepare($sql); $q->execute([$clientId]); return $q->fetchColumn(); };
    $items = [];
    $n = (int)$one("SELECT COUNT(*) FROM training_tracks WHERE client_id = ? AND status = 'Active'");
    if ($n) $items[] = [$n . ' training track' . ($n === 1 ? ' is' : 's are') . ' still Active: mark them Completed or Cancelled.', 'client.php?id=' . $clientId . '#tracks'];
    $amt = (float)$one("SELECT COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)), 0)
        FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE t.client_id = ? AND w.is_billed = 0 AND t.billing_mode <> 'none'");
    if ($amt > 0) $items[] = [fmt_money($amt) . ' of work is not yet invoiced.', 'invoices.php'];
    $n = (int)$one('SELECT COUNT(*) FROM equipment_loans WHERE client_id = ? AND returned_at IS NULL');
    if ($n) $items[] = [$n . ' loaned item' . ($n === 1 ? ' is' : 's are') . ' still out.', 'equipment.php'];
    $n = (int)$one("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id = ? AND status = 'Scheduled' AND start_at > NOW()");
    if ($n) $items[] = [$n . ' future session' . ($n === 1 ? ' is' : 's are') . ' still scheduled: cancel ' . ($n === 1 ? 'it' : 'them') . ' on the Schedule.', 'schedule.php'];
    $n = (int)$one("SELECT COUNT(*) FROM invoices i JOIN training_tracks t ON t.id = i.track_id WHERE t.client_id = ? AND " . INVOICE_OPEN_SQL);
    if ($n) $items[] = [$n . ' invoice' . ($n === 1 ? ' is' : 's are') . ' still awaiting payment.', 'invoices.php'];
    return $items;
}

/* ---------- On Hold with a reason and a return date (3.0.44) ----------
   Owner decision 2026-10-16: no separate "Awaiting authorization" status; On Hold, with a
   reason, covers it. Moving a client to On Hold needs a reason; a return date is optional.
   The daily job opens check-in tasks for On Hold clients, as for the Waitlist. */

/** Check the On Hold fields from a form. Returns [error or null, return date or null]. */
function on_hold_input(string $newStatus, string $oldStatus, string $reason, string $until): array {
    if ($newStatus !== 'On Hold') return [null, null];
    // Already On Hold: only a new return date can change.
    if ($oldStatus !== 'On Hold' && trim($reason) === '') return ['Say why the client is going On Hold (for example, waiting for the authorization).', null];
    if (trim($until) === '') return [null, null];
    $d = valid_date(trim($until), 2000, (int)date('Y') + 5);
    if ($d === null || $d < date('Y-m-d')) return ['Enter the expected return date as today or later, or leave it blank.', null];
    return [null, $d];
}

/** Save (or clear) the On Hold reason and return date after a status change. */
function on_hold_save(int $clientId, string $newStatus, string $oldStatus, string $reason, ?string $until): void {
    try {
        if ($newStatus === 'On Hold' && $oldStatus !== 'On Hold') {
            db()->prepare('UPDATE clients SET on_hold_reason = ?, on_hold_until = ? WHERE id = ?')->execute([mb_substr(trim($reason), 0, 255), $until, $clientId]);
        } elseif ($newStatus === 'On Hold' && $until !== null) {
            db()->prepare('UPDATE clients SET on_hold_until = ? WHERE id = ?')->execute([$until, $clientId]);
        } elseif ($newStatus !== 'On Hold' && $oldStatus === 'On Hold') {
            db()->prepare('UPDATE clients SET on_hold_reason = NULL, on_hold_until = NULL WHERE id = ?')->execute([$clientId]);
        }
    } catch (PDOException $e) {
        error_log('on_hold_save: ' . $e->getCode()); // mig_3.0.44 not run yet
    }
}

/** A warning (never a refusal) when a client is set to Active Training without an active authorized track. */
function active_without_authorization(int $clientId): ?string {
    $q = db()->prepare("SELECT COUNT(*) FROM training_tracks WHERE client_id = ? AND status = 'Active' AND (billing_mode <> 'vr' OR (auth_number IS NOT NULL AND auth_number <> ''))");
    $q->execute([$clientId]);
    return (int)$q->fetchColumn() > 0 ? null : 'Note: this client has no active training track with an authorization yet. Add one before logging billable hours.';
}
