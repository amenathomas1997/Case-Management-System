<?php
declare(strict_types=1);
require_once __DIR__ . '/lists.php';   // the lists and time limits set on screen (4.4.0)

const DEVICE_FOLLOWUP_DAYS = [30, 60, 90];
/** Device request categories: the fixed list the procurement page and the app's
 *  add_device_request (api.php) both accept, so they cannot drift apart. */
const DR_CATEGORIES = ['Braille Display', 'Screen Reader', 'Magnifier / CCTV', 'AAC Device', 'Tablet / Phone', 'Computer', 'Hearing / Amplification', 'Switch / Access', 'Software License', 'Other'];

/**
 * What a browser session is bound to: the password hash and, from 4.27.0, the
 * account's session epoch (users.session_epoch), which removing a passkey, an
 * administrator's two-step reset and an administrator's change of email bump to
 * end every other session. An epoch of 0 (or a database not yet migrated, where
 * the column is missing) gives exactly the value earlier releases did, so
 * sessions open during the upgrade carry on.
 */
function credential_version(array $row): string {
    $epoch = (int)($row['session_epoch'] ?? 0);
    return $epoch > 0
        ? hash('sha256', (string)$row['password_hash'] . "\0epoch:" . $epoch)
        : hash('sha256', (string)$row['password_hash']);
}

/**
 * End every browser session of $userId (4.27.0) by bumping its session epoch.
 * When the request's own session is that account's and was valid, it is kept:
 * the person who made the change stays signed in here. Returns false when the
 * column is not there yet (before the 4.27.0 migration): nothing is ended then.
 */
function user_session_epoch_bump(int $userId): bool {
    try {
        db()->prepare('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?')->execute([$userId]);
    } catch (PDOException $e) {
        error_log('user_session_epoch_bump: session_epoch not available (' . $e->getCode() . ')');
        return false;
    }
    if (isset($_SESSION) && (int)($_SESSION['user_id'] ?? 0) === $userId && ($_SESSION['otp_verified'] ?? false) === true && isset($_SESSION['credential_version'])) {
        $s = db()->prepare('SELECT * FROM users WHERE id = ?');
        $s->execute([$userId]);
        $row = $s->fetch();
        if ($row) {
            // Kept only when it matched the account just before the bump.
            $before = array_replace($row, ['session_epoch' => max(0, (int)($row['session_epoch'] ?? 0) - 1)]);
            if (hash_equals(credential_version($before), (string)$_SESSION['credential_version'])) {
                $_SESSION['credential_version'] = credential_version($row);
            }
        }
    }
    return true;
}

function session_matches_user(array $session, array $row): bool {
    return !empty($row['is_active']) && ($session['otp_verified'] ?? false) === true
        && (int)($session['user_id'] ?? 0) === (int)$row['id']
        && isset($session['credential_version'])
        && hash_equals(credential_version($row), (string)$session['credential_version']);
}

/** Every loan mutation locks the equipment row first, including returns.
 *  $scheduleFollowups is false for a temporary loaner (an AT repair loaner): it
 *  comes back when the repair ends, so it gets no "still in use?" checks. */
function loan_equipment(int $equipmentId, int $clientId, ?string $due, ?string $notes, int $actor, bool $scheduleFollowups = true): int {
    $db = db();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $item = $db->prepare('SELECT status FROM equipment WHERE id = ? FOR UPDATE');
        $item->execute([$equipmentId]);
        if ($item->fetchColumn() !== 'Available') throw new DomainException('That item is no longer available.');
        try {
            $blocked = $db->prepare("SELECT id FROM at_records WHERE equipment_id=? AND ((kind='repair' AND status NOT IN ('Resolved','Cancelled')) OR (kind='reuse' AND status IN ('Intake','Refurbishing'))) LIMIT 1 FOR UPDATE");
            $blocked->execute([$equipmentId]);
            if ($blocked->fetchColumn()) throw new DomainException('Complete the equipment repair or reuse checks before lending this item.');
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S02') throw $e;
        }
        $open = $db->prepare('SELECT id FROM equipment_loans WHERE equipment_id = ? AND returned_at IS NULL LIMIT 1 FOR UPDATE');
        $open->execute([$equipmentId]);
        if ($open->fetchColumn()) throw new DomainException('That item already has an open loan.');
        $db->prepare('INSERT INTO equipment_loans (equipment_id, client_id, loaned_at, due_date, notes, created_by) VALUES (?,?,CURDATE(),?,?,?)')
            ->execute([$equipmentId, $clientId, $due, $notes, $actor]);
        // Capture the insert ID before another statement resets it.
        $loanId = (int)$db->lastInsertId();
        $db->prepare("UPDATE equipment SET status = 'On Loan' WHERE id = ?")->execute([$equipmentId]);
        // Auto-schedule device-retention follow-ups so provisioned AT doesn't
        // quietly go unused (abandonment). Staff can complete or skip each.
        if ($scheduleFollowups) {
            $fu = $db->prepare('INSERT INTO equipment_followups (loan_id, client_id, equipment_id, interval_days, due_date) VALUES (?,?,?,?, DATE_ADD(CURDATE(), INTERVAL ? DAY))');
            foreach (cms_days('device_followup_days') as $d) {
                $fu->execute([$loanId, $clientId, $equipmentId, $d, $d]);
            }
        }
        // Reservation bookkeeping for every loan path (web, app, voice): the
        // borrower's own hold or waitlist entry is fulfilled, and any other
        // client's hold drops back to the waitlist — the item is out now, so a
        // 'Reserved' row must not keep claiming it. That client's instructor (and
        // whoever made the hold) is told, so the client is not left expecting the device.
        try {
            $db->prepare("UPDATE equipment_reservations SET status = 'Fulfilled' WHERE equipment_id = ? AND client_id = ? AND status IN ('Reserved','Waitlist')")
                ->execute([$equipmentId, $clientId]);
            $held = $db->prepare("SELECT client_id, reserved_by FROM equipment_reservations WHERE equipment_id = ? AND client_id <> ? AND status = 'Reserved' FOR UPDATE");
            $held->execute([$equipmentId, $clientId]);
            $holds = $held->fetchAll();
            $db->prepare("UPDATE equipment_reservations SET status = 'Waitlist' WHERE equipment_id = ? AND client_id <> ? AND status = 'Reserved'")
                ->execute([$equipmentId, $clientId]);
            notify_hold_change($equipmentId, $holds, $actor, 'reservation hold', 'was moved to the waitlist because the item was loaned to another client');
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S02') throw $e; // Older schema without reservations.
        }
        if ($ownsTransaction) $db->commit();
        return $loanId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Returns the equipment ID. Scope must be checked before entering this helper.
 *  When the item is free again, the client who has waited longest gets the hold
 *  (promote_equipment_waitlist(); $actor, who made the return, is not notified). */
function return_equipment(int $loanId, int $actor = 0): int {
    $db = db();
    $lookup = $db->prepare('SELECT equipment_id FROM equipment_loans WHERE id = ?');
    $lookup->execute([$loanId]);
    $equipmentId = (int)$lookup->fetchColumn();
    if (!$equipmentId) throw new DomainException('Loan not found.');
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT status FROM equipment WHERE id = ? FOR UPDATE');
        $lock->execute([$equipmentId]);
        $currentStatus = $lock->fetchColumn();
        $claim = $db->prepare('UPDATE equipment_loans SET returned_at = CURDATE() WHERE id = ? AND returned_at IS NULL');
        $claim->execute([$loanId]);
        if ($claim->rowCount() !== 1) throw new DomainException('That loan has already been returned.');
        // The device is back, so its remaining "still in use?" checks no longer
        // apply; close them rather than ask about a device the client no longer has.
        $db->prepare("UPDATE equipment_followups SET status = 'Skipped', completed_at = NOW(), notes = 'Device returned' WHERE loan_id = ? AND status = 'Pending'")
            ->execute([$loanId]);
        // Preserve On Loan if older data contains duplicate open loans.
        $open = $db->prepare('SELECT id FROM equipment_loans WHERE equipment_id = ? AND returned_at IS NULL LIMIT 1 FOR UPDATE');
        $open->execute([$equipmentId]);
        if (!$open->fetchColumn()) {
            $status = in_array($currentStatus, ['Repair','Retired'], true) ? $currentStatus : 'Available';
            try {
                $pending = $db->prepare("SELECT id FROM at_records WHERE equipment_id=? AND ((kind='repair' AND status NOT IN ('Resolved','Cancelled')) OR (kind='reuse' AND status IN ('Intake','Refurbishing'))) LIMIT 1 FOR UPDATE");
                $pending->execute([$equipmentId]);
                if ($pending->fetchColumn()) $status = 'Repair';
            } catch (PDOException $e) {
                if ((string)$e->getCode() !== '42S02') throw $e; // Older schema before the AT migration.
            }
            $db->prepare('UPDATE equipment SET status = ? WHERE id = ?')->execute([$status, $equipmentId]);
            if ($status === 'Available') promote_equipment_waitlist($equipmentId, $actor);
        }
        if ($ownsTransaction) $db->commit();
        return $equipmentId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** SQL condition, on the equipment_reservations alias $alias, for a hold that still
 *  counts: its "needed until" date has not passed and its client is neither closed nor
 *  archived. A lapsed hold, or one for a client no longer served, stays listed (so
 *  staff can cancel it) but never keeps the item from the next client: it is left out
 *  of the "held by a reservation" checks, the queue a new request joins and waitlist
 *  promotion. $alias is a literal table alias, never user input. */
function equipment_hold_live_sql(string $alias = 'er'): string {
    return "({$alias}.needed_until IS NULL OR {$alias}.needed_until >= CURDATE())"
        . " AND EXISTS (SELECT 1 FROM clients hc WHERE hc.id = {$alias}.client_id AND hc.archived_at IS NULL AND hc.status NOT LIKE 'Closed%')";
}

/** The item has just become free to lend (returned, back from repair or reuse
 *  refurbishment, set back to Available, or its hold was cancelled): the client who
 *  has waited longest gets the hold. Turns the oldest live 'Waitlist' row (by
 *  created_at) into 'Reserved' and returns its id, or null when the item is not
 *  Available, a live hold already holds it, or nobody is waiting.
 *  The caller should already hold the equipment row lock (SELECT ... FOR UPDATE)
 *  inside its transaction; the row is locked again here, and a transaction is opened
 *  only when none is running. The promotion is audited, and the promoted client's
 *  instructor and whoever placed the entry are told through notify_hold_change()
 *  (never $actor), so callers neither audit nor notify it again. */
function promote_equipment_waitlist(int $equipmentId, int $actor = 0): ?int {
    $db = db();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $promoted = null;
        $item = $db->prepare('SELECT status FROM equipment WHERE id = ? FOR UPDATE');
        $item->execute([$equipmentId]);
        if ($item->fetchColumn() === 'Available') {
            try {
                $live = equipment_hold_live_sql('er');
                $held = $db->prepare("SELECT er.id FROM equipment_reservations er WHERE er.equipment_id = ? AND er.status = 'Reserved' AND $live LIMIT 1");
                $held->execute([$equipmentId]);
                if (!$held->fetchColumn()) {
                    $queue = $db->prepare("SELECT er.id, er.client_id, er.reserved_by FROM equipment_reservations er WHERE er.equipment_id = ? AND er.status = 'Waitlist' AND $live ORDER BY er.created_at, er.id");
                    $queue->execute([$equipmentId]);
                    $promote = $db->prepare("UPDATE equipment_reservations SET status = 'Reserved' WHERE id = ? AND status = 'Waitlist'");
                    foreach ($queue->fetchAll() as $next) {
                        $promote->execute([(int)$next['id']]);
                        if ($promote->rowCount() !== 1) continue; // Settled by another request meanwhile.
                        $promoted = (int)$next['id'];
                        audit($actor ?: null, 'equipment.waitlist_promote', 'equipment', $equipmentId, 'client #' . (int)$next['client_id'] . ' moved up from the waitlist (reservation #' . $promoted . ')');
                        notify_hold_change($equipmentId, [$next], $actor, 'waitlist entry', 'was moved up to a reservation hold because the item is available again');
                        break;
                    }
                }
            } catch (PDOException $e) {
                if ((string)$e->getCode() !== '42S02') throw $e; // Older schema without reservations, as in loan_equipment().
            }
        }
        if ($ownsTransaction) $db->commit();
        return $promoted;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** A retired item never returns to service, so its holds and waitlist entries could
 *  never be met: they are cancelled with the retirement. Call inside the transaction
 *  that already holds the equipment row lock; after the commit, tell the returned
 *  holders with notify_retired_holders(). Returns ['count' => int, 'holders' => the
 *  cancelled holds, each ['client_id' => …, 'reserved_by' => …]]. */
function cancel_retired_holds(PDO $db, int $equipmentId): array {
    $hq = $db->prepare("SELECT client_id, reserved_by FROM equipment_reservations WHERE equipment_id = ? AND status IN ('Reserved','Waitlist') FOR UPDATE");
    $hq->execute([$equipmentId]);
    $holders = $hq->fetchAll();
    $cx = $db->prepare("UPDATE equipment_reservations SET status = 'Cancelled' WHERE equipment_id = ? AND status IN ('Reserved','Waitlist')");
    $cx->execute([$equipmentId]);
    return ['count' => $cx->rowCount(), 'holders' => $holders];
}

/** Tells the people behind each cancelled hold (see notify_hold_change()). */
function notify_retired_holders(int $equipmentId, array $holders, int $actor, string $reason = 'retired'): void {
    notify_hold_change($equipmentId, $holders, $actor, 'reservation or waitlist entry', 'was cancelled because the item was ' . $reason);
}

/** A client's hold on an item changed. The client's assigned instructor is told, by
 *  client name, while their account is active (otherwise the administrators, as the
 *  portal tells a case's owner): they run the case, and the hold may have been placed
 *  by someone else, or before the client was reassigned. Whoever placed the hold is
 *  also told, without the client's name (they may no longer have that client), when
 *  that is another active account. The person making the change is never told.
 *  $holds: rows with client_id and reserved_by. */
function notify_hold_change(int $equipmentId, array $holds, int $actor, string $what, string $outcome): void {
    if (!$holds) return;
    $nm = db()->prepare('SELECT name FROM equipment WHERE id = ?');
    $nm->execute([$equipmentId]);
    $itemName = (string)$nm->fetchColumn();
    $owner = db()->prepare('SELECT c.name, au.id AS active_owner FROM clients c LEFT JOIN users au ON au.id = c.assigned_to AND au.is_active = 1 WHERE c.id = ?');
    $active = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $told = [];
    foreach ($holds as $hold) {
        $cid = (int)$hold['client_id'];
        $owner->execute([$cid]);
        $client = $owner->fetch();
        if (!$client) continue;
        $ownerId = (int)($client['active_owner'] ?? 0);
        $named = 'The ' . $what . ' for ' . $client['name'] . ' on "' . $itemName . '" ' . $outcome . '.';
        $recipients = $ownerId ? [$ownerId] : effective_admin_user_ids();
        foreach ($recipients as $uid) {
            if ((int)$uid === $actor || isset($told[$uid . ':' . $cid])) continue;
            $told[$uid . ':' . $cid] = true;
            notify_user((int)$uid, $named, 'client.php?id=' . $cid);
        }
        $placer = (int)($hold['reserved_by'] ?? 0);
        if ($placer && $placer !== $actor && !isset($told[$placer . ':' . $cid]) && !isset($told['placer:' . $placer])) {
            $active->execute([$placer]);
            if ($active->fetchColumn()) {
                $told['placer:' . $placer] = true;
                notify_user($placer, 'Your ' . $what . ' on "' . $itemName . '" ' . $outcome . '.', 'equipment.php');
            }
        }
    }
}

/** Lock all user rows in ID order to serialize access changes and last-admin checks.
 * The callback writes only after the proposed effective permissions are validated.
 */
function change_user_access(int $id, int $actor, array $changes, callable $write): void {
    $db = db();
    $db->beginTransaction();
    try {
        $users = $db->query('SELECT * FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $groups = $db->query('SELECT id, perms, own_caseload_only FROM `groups` ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $isAdmin = static function (array $u) use ($groups): bool {
            if (empty($u['is_active'])) return false;
            return !empty($u['group_id']) && isset($groups[$u['group_id']])
                ? in_array('admin', array_map('trim', explode(';', (string)$groups[$u['group_id']]['perms'])), true)
                : $u['role'] === 'admin';
        };
        // An administrator who sees every caseload, by effective_admin_user_ids()'s rule:
        // a group limited to its own caseload never counts. Org-wide notices and holds
        // on unowned clients reach only these accounts, so the last one is kept.
        $isFullAdmin = static function (array $u) use ($groups, $isAdmin): bool {
            return $isAdmin($u) && !(!empty($u['group_id']) && isset($groups[$u['group_id']]) && (int)$groups[$u['group_id']]['own_caseload_only'] === 1);
        };
        $byId = array_column($users, null, 'id');
        if (!isset($byId[$id])) throw new DomainException('User not found.');
        if (!isset($byId[$actor]) || !$isAdmin($byId[$actor])) throw new DomainException('Administrator access is required.');
        $after = array_replace($byId[$id], $changes);
        if (!empty($after['group_id']) && !isset($groups[$after['group_id']])) throw new DomainException('Choose an existing permission group.');
        if ($actor === $id && !$isAdmin($after)) throw new DomainException('You cannot deactivate or demote your own account. Ask another administrator.');
        $hadFullAdmin = (bool)array_filter($byId, $isFullAdmin);
        $byId[$id] = $after;
        if (!array_filter($byId, $isAdmin)) throw new DomainException('That change would leave no active administrator.');
        if ($hadFullAdmin && !array_filter($byId, $isFullAdmin)) throw new DomainException('That change would leave no active administrator with access to every caseload. First give another account an administrator group that is not limited to its own caseload.');
        $write($db);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Scoped read source for aggregate reports. Returns the bare table for unrestricted
 * users, or a caseload-filtered derived table for caseload_scoped() users.
 * IMPORTANT: the scoped form is a `(SELECT ...)` subquery, so the CALLER MUST follow
 * it with a SQL alias (e.g. `FROM {$rt} t`) — a missing alias is a syntax error on the
 * scoped path even though the admin path (bare table name) would still parse.
 */
function report_table(string $table, int $actor): string {
    $columns = ['clients'=>'id', 'training_tracks'=>'client_id', 'client_status_history'=>'client_id',
        'surveys'=>'client_id', 'equipment_followups'=>'client_id', 'client_scored_assessments'=>'client_id',
        'smart_goals'=>'track_id', 'invoices'=>'track_id', 'scheduled_sessions'=>'client_id'];
    if (!isset($columns[$table])) throw new InvalidArgumentException('Unsupported report table.');
    if (!caseload_scoped()) return $table;
    $ids = 'SELECT id FROM clients WHERE assigned_to='.(int)$actor;
    if ($columns[$table] === 'track_id') $ids = 'SELECT id FROM training_tracks WHERE client_id IN ('.$ids.')';
    return '(SELECT * FROM '.$table.' WHERE '.$columns[$table].' IN ('.$ids.'))';
}

/* ---------- The equipment hand-off record (4.0.0) ---------- */
// Loan conditions are the equipment conditions on Lists and time limits (4.5.0).

/** Record how an item left (its condition, and the client's typed name accepting the loan agreement). */
function loan_handoff_out(int $loanId, ?string $condition, ?string $acceptedName): void {
    $condition = in_array($condition, cms_list_items('equipment_conditions'), true) ? $condition : null;
    $acceptedName = $acceptedName !== null && trim($acceptedName) !== '' ? mb_substr(trim($acceptedName), 0, 120) : null;
    if ($condition === null && $acceptedName === null) return;
    try {
        db()->prepare('UPDATE equipment_loans SET condition_out = COALESCE(?, condition_out), accepted_name = COALESCE(?, accepted_name), accepted_at = IF(? IS NULL, accepted_at, NOW()) WHERE id = ?')
            ->execute([$condition, $acceptedName, $acceptedName, $loanId]);
    } catch (PDOException $e) { if ((string)$e->getCode() !== '42S22') throw $e; } // mig_4.0.0 not run yet
}

/** Record how an item came back; damage found updates the item's own condition. */
function loan_handoff_in(int $loanId, ?string $condition): void {
    if (!in_array($condition, cms_list_items('equipment_conditions'), true)) return;
    try {
        db()->prepare('UPDATE equipment_loans SET condition_in = ? WHERE id = ?')->execute([$condition, $loanId]);
        db()->prepare('UPDATE equipment e JOIN equipment_loans l ON l.equipment_id = e.id SET e.item_condition = ? WHERE l.id = ?')->execute([$condition, $loanId]);
    } catch (PDOException $e) { if ((string)$e->getCode() !== '42S22') throw $e; }
}

/**
 * Unreadable entries (4.29.0). A browser sends a date or number box it cannot read (a partly typed
 * date, "3,000" in a number box in some browsers) as blank, which the server would take for a
 * deliberate blank and clear the stored value. On submit this marks each such field as
 * <name>_bad, so input_was_unreadable() refuses it on its field instead. Simple names only.
 */
function bad_input_script(): string {
    return '<script>document.addEventListener("submit",function(e){var f=e.target;if(!f||!f.querySelectorAll)return;'
        . 'f.querySelectorAll("input[data-bad-input-marker]").forEach(function(m){m.remove();});'
        . 'f.querySelectorAll("input[type=date],input[type=number],input[type=time]").forEach(function(i){if(i.name&&/^[A-Za-z0-9_]+$/.test(i.name)&&i.validity&&i.validity.badInput){'
        . 'var m=document.createElement("input");m.type="hidden";m.name=i.name+"_bad";m.value="1";m.setAttribute("data-bad-input-marker","");f.appendChild(m);}});},true);</script>';
}
/** Whether bad_input_script() flagged $field as typed but unreadable. */
function input_was_unreadable(string $field, ?array $post = null): bool {
    $post ??= $_POST;
    return !empty($post[$field . '_bad']);
}
/** Whether a client chosen on a form is still on file (4.29.0): one merged away or purged while the
 *  form was open is refused on its field instead of failing the insert with a server error. */
function client_still_on_file(int $clientId): bool {
    if ($clientId <= 0) return false;
    $q = db()->prepare('SELECT 1 FROM clients WHERE id = ?');
    $q->execute([$clientId]);
    return (bool)$q->fetchColumn();
}
