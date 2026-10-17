<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('equipment');

// Equipment categories, conditions and maintenance types are on Lists and time limits (inc/lists.php, 4.5.0).

/** Maintenance status from the last service date + interval. */
function maint_status(?string $last, $intervalDays): array {
    $iv = (int)$intervalDays;
    if ($iv <= 0) return ['Not scheduled', 'muted', null, 999999];
    if (!$last) return ['Service needed', 'field-error', null, -1];
    $next = date('Y-m-d', strtotime($last . ' +' . $iv . ' days'));
    // Whole calendar days via DateTime::diff (as followups.php counts them): dividing
    // seconds by 86400 loses a day when a daylight-saving change falls in between.
    $diff = (new DateTime('today'))->diff(new DateTime($next));
    $daysLeft = $diff->invert ? -(int)$diff->days : (int)$diff->days;
    if ($daysLeft < 0) return ['Overdue since ' . fmt_date($next), 'field-error', $next, $daysLeft];
    if ($daysLeft <= 14) return ['Due ' . fmt_date($next), 'badge-pending', $next, $daysLeft];
    return ['Next ' . fmt_date($next), 'muted', $next, $daysLeft];
}

/** A typed dollar amount, read as the CSV import reads one: a dollar sign, spaces and
 *  thousands commas are allowed. Returns the plain number, '' when blank, or null when
 *  it is not one amount from 0 to 9,999,999.99 with up to two decimal places. */
function equip_amount(string $raw): ?string {
    $clean = str_replace(['$', ' ', "\u{00A0}"], '', trim($raw));
    if ($clean === '') return '';
    if (!preg_match('/^(\d{1,3}(,\d{3})+|\d+)(\.\d{1,2})?$/D', $clean)) return null;
    $n = str_replace(',', '', $clean);
    return (float)$n > 9999999.99 ? null : $n;
}

/* Add item */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    // A value that is not a string (an array from name[]=x, say) reads as blank,
    // so it is refused on its field, never a 400.
    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $serial = trim((string)($_POST['serial'] ?? ''));
    $itemNotes = trim((string)($_POST['notes'] ?? ''));
    // Each problem is shown on its own field and the form comes back filled in.
    // Text longer than its column (name 190, serial 120, notes 255) is refused, not cut.
    $errors = [];
    if ($name === '') $errors['name'] = 'Item name is required.';
    elseif (mb_strlen($name) > 190) $errors['name'] = 'Item name must be 190 characters or fewer.';
    if (mb_strlen($serial) > 120) $errors['serial'] = 'Serial number must be 120 characters or fewer.';
    // Money must be a non-negative amount that fits the column (decimal 9,2). The
    // fields are text, so what was typed reaches the server and is checked here rather
    // than dropped by the browser; whole numbers are refused, not clamped, when out of range.
    $valueRaw = equip_amount((string)($_POST['value'] ?? ''));
    if ($valueRaw === null) $errors['value'] = 'Value: enter one amount in dollars from 0 to 9,999,999.99, for example 2995.00.';
    $lifeRaw = trim((string)($_POST['useful_life_months'] ?? ''));
    if ($lifeRaw !== '' && (!preg_match('/^\d{1,3}$/D', $lifeRaw) || (int)$lifeRaw < 1 || (int)$lifeRaw > 600)) $errors['useful_life_months'] = 'Useful life: enter a whole number of months from 1 to 600, or leave it blank for 36.';
    $ivRaw = trim((string)($_POST['maintenance_interval_days'] ?? ''));
    if ($ivRaw !== '' && (!preg_match('/^\d{1,4}$/D', $ivRaw) || (int)$ivRaw > 3650)) $errors['maintenance_interval_days'] = 'Service interval: enter a whole number of days from 0 to 3650, or leave it blank for no schedule.';
    if (mb_strlen($itemNotes) > 255) $errors['notes'] = 'Notes must be 255 characters or fewer.';
    // A purchase date that cannot be read, has a mistyped year or is in the future is
    // refused on its field (it sets the current value), not dropped.
    $purchaseRaw = trim((string)($_POST['purchase_date'] ?? ''));
    $purchase = valid_date($purchaseRaw, 1970, (int)date('Y'));
    if ($purchaseRaw !== '' && $purchase === null) $errors['purchase_date'] = 'Purchase date: enter the full date (month, day and year) with a year from 1970 to ' . date('Y') . ', or leave it blank.';
    elseif ($purchase !== null && $purchase > date('Y-m-d')) $errors['purchase_date'] = 'Purchase date cannot be in the future.';
    if ($errors) { keep_input(); keep_field_errors($errors); redirect('equipment.php'); }
    $iv = $ivRaw !== '' ? (int)$ivRaw : null;
    $life = $lifeRaw !== '' ? (int)$lifeRaw : 36;
    // The same form sent twice (Enter pressed again while it loads) adds the item once.
    $itemKey = web_idem_key($_POST);
    if (web_once_get($itemKey) !== null) {
        flash('This item was already added (the form was sent twice), so it was saved once.', 'info');
        redirect('equipment.php');
    }
    db()->prepare('INSERT INTO equipment (name, category, serial, item_condition, value, purchase_date, useful_life_months, notes, maintenance_interval_days) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$name,
            in_array($_POST['category'] ?? '', cms_list_items('equipment_categories'), true) ? $_POST['category'] : 'Other',
            $serial ?: null,
            in_array($_POST['item_condition'] ?? '', cms_list_items('equipment_conditions'), true) ? $_POST['item_condition'] : 'Good',
            $valueRaw !== '' ? (float)$valueRaw : null,
            $purchase,
            $life,
            $itemNotes ?: null,
            $iv ?: null]);
    // Capture the id before audit() inserts its own row and replaces it.
    $newId = (int)db()->lastInsertId();
    web_once_set($itemKey, $newId);
    audit($user['id'], 'equipment.add', 'equipment', $newId, $name);
    flash('Equipment added.');
    redirect('equipment.php');
}

/* Loan out */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_out'])) {
    $eid = (int)($_POST['equipment_id'] ?? 0);
    $cid = (int)($_POST['client_id'] ?? 0);
    $e = db()->prepare("SELECT * FROM equipment WHERE id = ? AND status = 'Available'");
    $e->execute([$eid]);
    // Each problem is shown on its own field, and the form comes back as it was filled in.
    $loanErrors = [];
    if ($eid <= 0) $loanErrors['loan_equipment_id'] = 'Item is required: choose an available item.';
    elseif (!$e->fetch()) $loanErrors['loan_equipment_id'] = 'Item: that item is no longer available; choose another.';
    if ($cid <= 0) $loanErrors['loan_client_id'] = 'Client is required: choose who receives the item.';
    $loanNotes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($loanNotes) > 255) $loanErrors['loan_notes'] = 'Notes must be 255 characters or fewer.';
    // The return date is checked as the app checks one: a date that cannot be read, a
    // mistyped year or a date already past is refused on its field, never dropped.
    $dueRaw = trim((string)($_POST['due_date'] ?? ''));
    $due = valid_date($dueRaw, 2000, (int)date('Y') + 5);
    if ($dueRaw !== '' && $due === null) $loanErrors['loan_due_date'] = 'Return due: enter the full date (month, day and year) with a year from 2000 to ' . ((int)date('Y') + 5) . ', or leave it blank for no return date.';
    elseif ($due !== null && $due < date('Y-m-d')) $loanErrors['loan_due_date'] = 'Return due cannot be before today.';
    if ($loanErrors) { keep_input(); keep_field_errors($loanErrors); redirect('equipment.php'); }
    scope_check_client_id($cid, $user);
    try {
        // loan_equipment() also settles reservations in the same transaction: this
        // client's hold is fulfilled, anyone else's hold returns to the waitlist, and
        // the staff member who made that hold is notified.
        $newLoan = loan_equipment($eid, $cid, $due, $loanNotes ?: null, (int)$user['id']);
        loan_handoff_out($newLoan, is_string($_POST['condition_out'] ?? null) ? $_POST['condition_out'] : null, is_string($_POST['accepted_name'] ?? null) ? $_POST['accepted_name'] : null);
    } catch (DomainException $e) {
        keep_input(); keep_field_errors(['loan_equipment_id' => 'Item: ' . lcfirst($e->getMessage())]); redirect('equipment.php');
    }
    audit($user['id'], 'equipment.loan', 'equipment', $eid, 'to client #' . $cid);
    flash('Item loaned out.');
    redirect('equipment.php');
}

/* Reserve / waitlist an item for a client */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reservation'])) {
    $eid = (int)($_POST['equipment_id'] ?? 0);
    $cid = (int)($_POST['client_id'] ?? 0);
    $e = db()->prepare('SELECT status FROM equipment WHERE id = ?');
    $e->execute([$eid]);
    $estatus = $e->fetchColumn();
    if ($estatus === false || $cid <= 0) { flash('Choose an item and a client.', 'error'); redirect('equipment.php'); }
    if ($estatus === 'Retired') { flash('That item is retired and cannot be reserved.', 'error'); redirect('equipment.php'); }
    scope_check_client_id($cid, $user);
    // Dates are checked as the app checks them: a date that cannot be read, or an
    // end before the start, is refused on its field with what was typed kept.
    $fromRaw = trim((string)($_POST['needed_from'] ?? ''));
    $untilRaw = trim((string)($_POST['needed_until'] ?? ''));
    // A mistyped year (0026, 2062) is refused too, with the app's range.
    $maxYear = (int)date('Y') + 5;
    $from = valid_date($fromRaw, 2000, $maxYear);
    $until = valid_date($untilRaw, 2000, $maxYear);
    $dateErrors = [];
    if ($fromRaw !== '' && $from === null) $dateErrors['needed_from'] = 'Needed from: enter a valid date (YYYY-MM-DD) with a year from 2000 to ' . $maxYear . '.';
    if ($untilRaw !== '' && $until === null) $dateErrors['needed_until'] = 'Needed until: enter a valid date (YYYY-MM-DD) with a year from 2000 to ' . $maxYear . '.';
    elseif ($from !== null && $until !== null && $until < $from) $dateErrors['needed_until'] = '"Needed until" cannot be before "needed from".';
    $resNotes = is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : '';
    if (mb_strlen($resNotes) > 500) $dateErrors['res_notes'] = 'Notes must be 500 characters or fewer.';
    if ($dateErrors) { keep_input(); keep_field_errors($dateErrors); redirect('equipment.php'); }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the item so two reservations made at once can't both get the hold.
        $lock = $pdo->prepare('SELECT status FROM equipment WHERE id = ? FOR UPDATE');
        $lock->execute([$eid]);
        $estatus = $lock->fetchColumn();
        // Checked again under the lock: an item retired while this request waited has
        // had its holds cancelled, so no new hold or waitlist entry may join it.
        if ($estatus === false || $estatus === 'Retired') {
            $pdo->rollBack();
            flash('That item is retired and cannot be reserved.', 'error');
            redirect('equipment.php');
        }
        // One active hold per client and item: a double submit, or re-adding a client
        // who is already listed, would count them twice in the queue.
        $dup = $pdo->prepare("SELECT status FROM equipment_reservations WHERE equipment_id = ? AND client_id = ? AND status IN ('Reserved','Waitlist') LIMIT 1");
        $dup->execute([$eid, $cid]);
        if ($dupStatus = $dup->fetchColumn()) {
            $pdo->rollBack();
            keep_input();
            keep_field_errors(['res_client_id' => 'Client: this client ' . ($dupStatus === 'Reserved' ? 'already has a reservation for' : 'is already on the waitlist for') . ' that item (listed above), so nothing was added. To change its dates, cancel that entry and add it again' . ($dupStatus === 'Reserved' ? '.' : ' (it then joins the end of the waitlist).')]);
            redirect('equipment.php');
        }
        // Holds that lapsed (their "needed until" date passed) or belong to a closed or
        // archived client do not count (equipment_hold_live_sql()).
        $live = equipment_hold_live_sql('er');
        $held = $pdo->prepare("SELECT COUNT(*) FROM equipment_reservations er WHERE er.equipment_id = ? AND er.status = 'Reserved' AND $live");
        $held->execute([$eid]);
        $heldNow = (int)$held->fetchColumn() > 0;
        $waiting = $pdo->prepare("SELECT COUNT(*) FROM equipment_reservations er WHERE er.equipment_id = ? AND er.status = 'Waitlist' AND $live");
        $waiting->execute([$eid]);
        $queueAhead = (int)$waiting->fetchColumn() > 0;
        // Reserved = the one hold on an item that is available right now, when nobody
        // is already waiting for it. Anything else (item out, in repair, already held,
        // or clients waiting since earlier) joins the end of the waitlist.
        $rstatus = ($estatus === 'Available' && !$heldNow && !$queueAhead) ? 'Reserved' : 'Waitlist';
        $pdo->prepare('INSERT INTO equipment_reservations (equipment_id, client_id, reserved_by, needed_from, needed_until, status, notes) VALUES (?,?,?,?,?,?,?)')
            ->execute([$eid, $cid, $user['id'], $from, $until, $rstatus, $resNotes !== '' ? $resNotes : null]);
        // An available item with clients waiting and no hold (left so by an older
        // release, or by a hold that lapsed) gives the hold to whoever has waited
        // longest; this new entry is behind them. The promotion is audited and its
        // client's instructor told by promote_equipment_waitlist().
        $promoted = ($estatus === 'Available' && !$heldNow && $queueAhead) ? promote_equipment_waitlist($eid, (int)$user['id']) : null;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($user['id'], 'equipment.reserve', 'equipment', $eid, 'client #' . $cid . ' (' . $rstatus . ')');
    flash($rstatus === 'Reserved' ? 'Item reserved.' : 'Added to the end of the waitlist.'
        . ($promoted !== null ? ' The item is available, so it is now held for the client who has waited longest.' : ''));
    redirect('equipment.php');
}

/* Cancel a reservation */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
    $resId = (int)($_POST['reservation_id'] ?? 0);
    $r = db()->prepare('SELECT er.id, er.equipment_id, er.client_id, c.assigned_to FROM equipment_reservations er JOIN clients c ON c.id = er.client_id WHERE er.id = ?');
    $r->execute([$resId]);
    if ($row = $r->fetch()) {
        if (caseload_scoped() && (int)$row['assigned_to'] !== (int)$user['id']) { flash('Not your caseload.', 'error'); redirect('equipment.php#res-h'); }
        // The item row is locked first (the order loans use), so a cancelled hold on an
        // available item passes to the client who has waited longest exactly once.
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status FROM equipment WHERE id = ? FOR UPDATE');
            $lock->execute([(int)$row['equipment_id']]);
            $lock->fetchColumn();
            $was = $pdo->prepare('SELECT status FROM equipment_reservations WHERE id = ? FOR UPDATE');
            $was->execute([$resId]);
            $wasStatus = $was->fetchColumn();
            // Only an active hold or waitlist entry is cancelled: one already fulfilled by
            // a loan (or cancelled from another page) keeps its history.
            $cancel = $pdo->prepare("UPDATE equipment_reservations SET status = 'Cancelled' WHERE id = ? AND status IN ('Reserved','Waitlist')");
            $cancel->execute([$resId]);
            $cancelled = $cancel->rowCount() === 1;
            $promoted = ($cancelled && $wasStatus === 'Reserved') ? promote_equipment_waitlist((int)$row['equipment_id'], (int)$user['id']) : null;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($cancelled) {
            audit($user['id'], 'equipment.reservation_cancel', 'equipment', (int)$row['equipment_id'], 'client #' . (int)$row['client_id']);
            flash('Reservation cancelled.' . ($promoted !== null ? ' The item is now held for the client who has waited longest on its waitlist.' : ''));
        } else {
            flash('That reservation is no longer active (it was already fulfilled or cancelled).', 'error');
        }
    } else {
        flash('Reservation not found.', 'error');
    }
    // Row actions come back to their own section, where the result is shown and
    // focused (layout.php), not to the top of the page.
    redirect('equipment.php#res-h');
}

/* Return */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_item'])) {
    $lid = (int)($_POST['loan_id'] ?? 0);
    $l = db()->prepare('SELECT * FROM equipment_loans WHERE id = ? AND returned_at IS NULL');
    $l->execute([$lid]);
    if ($loan = $l->fetch()) {
        scope_check_client_id((int)$loan['client_id'], $user);
        // A returned item that is free again goes to the client who has waited longest
        // (return_equipment() promotes the waitlist and tells that client's instructor).
        try { return_equipment($lid, (int)$user['id']); }
        catch (DomainException $e) { flash($e->getMessage(), 'error'); redirect('equipment.php#inv-h'); }
        loan_handoff_in($lid, is_string($_POST['condition_in'] ?? null) ? $_POST['condition_in'] : null);
        audit($user['id'], 'equipment.return', 'equipment', (int)$loan['equipment_id']);
        flash('Item marked returned.');
    } else {
        // A double submit or a page left open after the return: say so, not nothing.
        flash('That loan was already returned.', 'error');
    }
    redirect('equipment.php#inv-h');
}

/* Set status (Repair / Retired / Available) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $eid = (int)($_POST['equipment_id'] ?? 0);
    $to = in_array($_POST['to'] ?? '', ['Available','Repair','Retired'], true) ? $_POST['to'] : null;
    if (!$to) { flash('Choose Available, Repair or Retired.', 'error'); redirect('equipment.php#inv-h'); }
    // Retiring cancels the item's reservations and waitlist entries, which setting it
    // back to Available does not restore, so it is confirmed first on its own page.
    if ($to === 'Retired' && ($_POST['confirm_retire'] ?? '') !== '1') {
        $was = db()->prepare('SELECT status FROM equipment WHERE id = ?');
        $was->execute([$eid]);
        $wasStatus = $was->fetchColumn();
        if ($wasStatus !== false && $wasStatus !== 'Retired') redirect('equipment.php?retire=' . $eid);
    }
    // The item row is locked first (the order loans and AT workflows use), then the
    // change is refused, with the reason, when the item is out on loan or its status
    // is owned by an active AT repair/reuse record. Only a real change is audited.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT status FROM equipment WHERE id = ? FOR UPDATE');
        $cur->execute([$eid]);
        $from = $cur->fetchColumn();
        $refusal = null;
        if ($from === false) $refusal = 'Item not found.';
        else {
            $open = $pdo->prepare('SELECT id FROM equipment_loans WHERE equipment_id = ? AND returned_at IS NULL LIMIT 1');
            $open->execute([$eid]);
            // An open loan decides (an 'On Loan' status left without one can be corrected here).
            if ($open->fetchColumn()) $refusal = 'This item is on loan, so its status was not changed. Mark it returned first.';
            // A retired item (one recycled through a reuse record included) goes back into
            // service only through a reuse intake, which records the inspection,
            // sanitizing and data wipe before the device can be Available again.
            elseif ($from === 'Retired' && $to !== 'Retired') $refusal = 'This item is retired, so it was not put back into service here. Start a Device reuse intake for it in AT services: the inspection, sanitizing and data-wipe checks are recorded there before it becomes Available.';
        }
        if ($refusal === null) {
            // try/catch: AT tables may predate the migration.
            try {
                $w = $pdo->prepare("SELECT id FROM at_records WHERE equipment_id = ? AND ((kind='repair' AND status NOT IN ('Resolved','Cancelled')) OR (kind='reuse' AND status NOT IN ('Redeployed','Recycled'))) LIMIT 1");
                $w->execute([$eid]);
                if ($w->fetchColumn()) $refusal = 'This item is in an active AT repair or reuse workflow — update its status there.';
            } catch (PDOException $e) {
                if (!in_array((string)$e->getCode(), ['42S02', '42S22'], true)) throw $e;
            }
        }
        $cancelledHolds = 0;
        $holders = [];
        $promoted = null;
        if ($refusal === null && $from !== $to) {
            $pdo->prepare('UPDATE equipment SET status = ? WHERE id = ?')->execute([$to, $eid]);
            // Back in service (from Repair, say): the client who has waited longest for
            // the item gets the hold, under the lock taken above.
            if ($to === 'Available') $promoted = promote_equipment_waitlist($eid, (int)$user['id']);
            // A retired item never returns to service, so its holds and waitlist entries
            // could never be met: they are cancelled with the retirement, and whoever made
            // each one is told, so no client is left expecting the device.
            if ($to === 'Retired') {
                $cancelled = cancel_retired_holds($pdo, $eid);
                $cancelledHolds = $cancelled['count'];
                $holders = $cancelled['holders'];
                // A retired item is no longer serviced: its open "service due" task is
                // closed now rather than on the next daily run.
                $pdo->prepare("UPDATE tasks SET status = 'Done', done_at = NOW(), done_by = ? WHERE auto_key LIKE ? AND status = 'Open'")
                    ->execute([$user['id'], 'equip-maint-' . $eid . '-%']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($refusal !== null) flash($refusal, 'error');
    elseif ($from === $to) flash('Status is already ' . $to . '.');
    else {
        audit($user['id'], 'equipment.status', 'equipment', $eid, $from . ' to ' . $to
            . ($cancelledHolds ? '; ' . $cancelledHolds . ' reservation' . ($cancelledHolds === 1 ? '' : 's') . ' cancelled' : ''));
        notify_retired_holders($eid, $holders, (int)$user['id']);
        flash('Status updated.' . ($cancelledHolds ? ' ' . $cancelledHolds . ' reservation' . ($cancelledHolds === 1 ? ' was' : 's were') . ' cancelled because the item is retired.' : '')
            . ($promoted !== null ? ' The item is now held for the client who has waited longest on its waitlist.' : ''));
    }
    redirect('equipment.php#inv-h');
}

/* Log a maintenance / calibration service event */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_service'])) {
    $eid = (int)($_POST['equipment_id'] ?? 0);
    $ex = db()->prepare('SELECT id FROM equipment WHERE id = ?'); $ex->execute([$eid]);
    if (!$ex->fetchColumn()) { flash('Item not found.', 'error'); redirect('equipment.php'); }
    // "Last serviced" only ever moves forward, so a date that cannot be read is refused
    // rather than read as today, and a future date (a mistyped year) is refused rather
    // than stored where it would hide the item's next service for years. A year before
    // 2000 (0026 for 2026, say) is refused as the app refuses it. The error is
    // shown on this item's date field with what was typed kept.
    $dateRaw = trim((string)($_POST['service_date'] ?? ''));
    $date = valid_date($dateRaw, 2000, (int)date('Y'));
    $svcErrors = [];
    if ($dateRaw === '') $svcErrors['service_date_' . $eid] = 'Service date is required.';
    elseif ($date === null) $svcErrors['service_date_' . $eid] = 'Service date: enter the full date (month, day and year) with a year from 2000 to ' . date('Y') . '.';
    elseif ($date > date('Y-m-d')) $svcErrors['service_date_' . $eid] = 'Service date cannot be in the future.';
    $type = in_array($_POST['service_type'] ?? '', cms_list_items('maintenance_types'), true) ? $_POST['service_type'] : 'Maintenance';
    $notes = trim((string)($_POST['service_notes'] ?? ''));
    if (mb_strlen($notes) > 500) $svcErrors['service_notes_' . $eid] = 'Service notes must be 500 characters or fewer.';
    $costRaw = equip_amount((string)($_POST['service_cost'] ?? ''));
    if ($costRaw === null) $svcErrors['service_cost_' . $eid] = 'Cost: enter one amount in dollars from 0 to 9,999,999.99, for example 125.00, or leave it blank.';
    // Every problem is shown on this item's own fields, with what was typed kept.
    if ($svcErrors) {
        keep_input();
        keep_field_errors($svcErrors);
        redirect(isset($_POST['from_scan']) ? 'equipment.php?scan=' . $eid : 'equipment.php');
    }
    $cost = $costRaw !== '' ? (float)$costRaw : null;
    // The same service sent twice is logged once.
    $serviceKey = web_idem_key($_POST);
    if (web_once_get($serviceKey) !== null) {
        flash('This service was already logged (the form was sent twice), so it was saved once.', 'info');
        redirect('equipment.php#inv-h');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO equipment_maintenance (equipment_id, service_date, service_type, notes, cost, performed_by) VALUES (?,?,?,?,?,?)')
            ->execute([$eid, $date, $type, $notes ?: null, $cost, $user['id']]);
        $svcId = (int)$pdo->lastInsertId();
        // Back-filling an older service record must never move "last serviced"
        // backwards (the same rule as the app's equipment_service).
        $pdo->prepare('UPDATE equipment SET last_service_date = ? WHERE id = ? AND (last_service_date IS NULL OR last_service_date < ?)')
            ->execute([$date, $eid, $date]);
        // The service answers the item's open "service due" task, so it is closed now
        // (the daily run closes it on the same rule, a service logged after it opened).
        $pdo->prepare("UPDATE tasks SET status = 'Done', done_at = NOW(), done_by = ? WHERE auto_key LIKE ? AND status = 'Open'")
            ->execute([$user['id'], 'equip-maint-' . $eid . '-%']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    web_once_set($serviceKey, $svcId);
    audit($user['id'], 'equipment.service', 'equipment', $eid, $type . ' on ' . $date);
    flash('Service logged.');
    redirect('equipment.php#inv-h');
}

/* Correct "last serviced" (administrators). Logging a service never moves the date
   backwards, so a wrong date already stored (such as a mistyped future year) is put
   right here: the date is set as entered, and a dated correction entry with the
   reason is added to the item's service history and the audit log. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['correct_service'])) {
    $eid = (int)($_POST['equipment_id'] ?? 0);
    if (!can('admin')) { flash('Only an administrator can correct the last service date.', 'error'); redirect('equipment.php'); }
    $ex = db()->prepare('SELECT id FROM equipment WHERE id = ?'); $ex->execute([$eid]);
    if (!$ex->fetchColumn()) { flash('Item not found.', 'error'); redirect('equipment.php'); }
    $dateRaw = trim((string)($_POST['corrected_date'] ?? ''));
    $date = valid_date($dateRaw, 2000, (int)date('Y'));
    $reason = trim((string)($_POST['correction_reason'] ?? ''));
    $errors = [];
    // Blank means the item has no service on record. A mistyped year is refused, as
    // when a service is logged.
    if ($dateRaw !== '' && $date === null) $errors['corrected_date_' . $eid] = 'Corrected last service date: enter the full date (month, day and year) with a year from 2000 to ' . date('Y') . ', or leave it blank for no service on record.';
    elseif ($date !== null && $date > date('Y-m-d')) $errors['corrected_date_' . $eid] = 'Corrected last service date cannot be in the future.';
    if ($reason === '') $errors['correction_reason_' . $eid] = 'Reason for the correction is required.';
    elseif (mb_strlen($reason) > 300) $errors['correction_reason_' . $eid] = 'Reason for the correction must be 300 characters or fewer.';
    if ($errors) { keep_input(); keep_field_errors($errors); redirect('equipment.php'); }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT last_service_date FROM equipment WHERE id = ? FOR UPDATE');
        $cur->execute([$eid]);
        $was = $cur->fetchColumn() ?: null;
        if ($was !== $date) {
            $pdo->prepare('UPDATE equipment SET last_service_date = ? WHERE id = ?')->execute([$date, $eid]);
            $pdo->prepare("INSERT INTO equipment_maintenance (equipment_id, service_date, service_type, notes, cost, performed_by) VALUES (?, ?, 'Other', ?, NULL, ?)")
                ->execute([$eid, date('Y-m-d'), 'Correction (not a service): last service date changed from ' . ($was ?? 'none') . ' to ' . ($date ?? 'none') . '. Reason: ' . $reason, $user['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($was === $date) { flash('The last service date is already ' . ($date ? fmt_date($date) : 'blank') . '; nothing was changed.'); redirect('equipment.php#inv-h'); }
    audit($user['id'], 'equipment.service_correct', 'equipment', $eid, 'last service ' . ($was ?? 'none') . ' to ' . ($date ?? 'none') . ': ' . $reason);
    flash('Last service date corrected.');
    redirect('equipment.php#inv-h');
}

/* Set the maintenance interval (days between services) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_interval'])) {
    $eid = (int)($_POST['equipment_id'] ?? 0);
    $ex = db()->prepare('SELECT id FROM equipment WHERE id = ?'); $ex->execute([$eid]);
    if (!$ex->fetchColumn()) { flash('Item not found.', 'error'); redirect('equipment.php'); }
    $iv = ($_POST['interval'] ?? '') !== '' ? max(0, min(3650, (int)$_POST['interval'])) : null;
    db()->prepare('UPDATE equipment SET maintenance_interval_days = ? WHERE id = ?')->execute([$iv ?: null, $eid]);
    audit($user['id'], 'equipment.interval', 'equipment', $eid, $iv ? $iv . ' days' : 'cleared');
    flash('Maintenance schedule updated.');
    redirect('equipment.php#inv-h');
}

/* Confirm retiring an item: says how many reservations and waitlist entries will be
   cancelled (and their staff notified) before anything changes. */
if (isset($_GET['retire'])) {
    $rid = (int)$_GET['retire'];
    $ri = db()->prepare('SELECT id, name, status FROM equipment WHERE id = ?');
    $ri->execute([$rid]);
    $ritem = $ri->fetch();
    if (!$ritem) { flash('Item not found.', 'error'); redirect('equipment.php'); }
    if ($ritem['status'] === 'Retired') { flash('Status is already Retired.'); redirect('equipment.php'); }
    $rc = db()->prepare("SELECT COUNT(*) FROM equipment_reservations WHERE equipment_id = ? AND status IN ('Reserved','Waitlist')");
    $rc->execute([$rid]);
    $holdCount = (int)$rc->fetchColumn();
    page_header('Retire ' . $ritem['name'] . '? — Equipment', $user, 'equipment');
    ?>
    <p class="page-actions"><a href="equipment.php"><span aria-hidden="true">&larr; </span>Back to equipment</a></p>
    <section class="card" style="max-width:40rem">
      <h1>Retire <?= h($ritem['name']) ?>?</h1>
      <p>A retired item can no longer be loaned out or reserved. It returns to service only through a Device reuse intake, which records its inspection, sanitizing and data wipe.</p>
      <?php if ($holdCount): ?>
      <p><strong><?= $holdCount ?> reservation<?= $holdCount === 1 ? ' or waitlist entry' : 's and waitlist entries' ?> for this item will be cancelled</strong>, and each client's instructor and the staff who made <?= $holdCount === 1 ? 'it' : 'them' ?> will be notified. Putting the item back into service later does not restore <?= $holdCount === 1 ? 'it' : 'them' ?>.</p>
      <?php else: ?>
      <p>No reservations or waitlist entries are affected.</p>
      <?php endif; ?>
      <form method="post" action="equipment.php" class="page-actions"><?= csrf_field() ?>
        <input type="hidden" name="equipment_id" value="<?= (int)$ritem['id'] ?>">
        <input type="hidden" name="to" value="Retired"><input type="hidden" name="confirm_retire" value="1">
        <button type="submit" name="set_status" value="1" class="btn btn-danger">Retire <?= h($ritem['name']) ?></button>
        <a class="btn btn-secondary" href="equipment.php">Keep <?= h($ritem['name']) ?> in service</a>
      </form>
    </section>
    <?php
    page_footer();
    exit;
}

/* QR quick-action view for one item (what an equipment QR label points to) */
if (isset($_GET['scan'])) {
    $sid = (int)$_GET['scan'];
    $s = db()->prepare("SELECT e.*, l.id AS loan_id, l.loaned_at, l.due_date, c.name AS borrower, c.id AS borrower_id, c.assigned_to AS borrower_assigned
        FROM equipment e
        LEFT JOIN equipment_loans l ON l.equipment_id = e.id AND l.returned_at IS NULL
        LEFT JOIN clients c ON c.id = l.client_id WHERE e.id = ?");
    $s->execute([$sid]);
    $it = $s->fetch();
    page_header($it ? 'Equipment: ' . $it['name'] : 'Equipment', $user, 'equipment');
    if (!$it) { echo '<div class="card"><h1>Item not found</h1><p class="page-actions"><a href="equipment.php"><span aria-hidden="true">&larr; </span>Back to equipment</a></p></div>'; page_footer(); exit; }
    // Mask the borrower (and the return action) when the loan is outside a
    // caseload-scoped user's caseload — the same masking the inventory list applies.
    $scanOutside = caseload_scoped() && $it['borrower_id'] !== null && (int)$it['borrower_assigned'] !== (int)$user['id'];
    // A retired item is no longer serviced, so it is never shown as due or overdue.
    [$mLabel, $mClass] = $it['status'] === 'Retired' ? ['Not scheduled (retired)', 'muted'] : maint_status($it['last_service_date'], $it['maintenance_interval_days']);
    // A hold is shown without naming the client, since it may be another caseload's.
    // A lapsed hold, or one for a closed or archived client, does not count.
    $hq = db()->prepare("SELECT COUNT(*) FROM equipment_reservations er WHERE er.equipment_id = ? AND er.status = 'Reserved' AND " . equipment_hold_live_sql('er'));
    $hq->execute([$sid]);
    $scanHeld = (int)$hq->fetchColumn() > 0;
    ?>
    <p class="page-actions"><a href="equipment.php"><span aria-hidden="true">&larr; </span>Back to equipment</a></p>
    <section class="card" style="max-width:34rem">
      <h1><?= h($it['name']) ?></h1>
      <p class="muted"><?= h($it['category']) ?><?= $it['serial'] ? ' · Serial number ' . h($it['serial']) : '' ?></p>
      <p>Status: <span class="badge"><?= h($it['status']) ?></span> · Condition: <?= h($it['item_condition']) ?></p>
      <p>Maintenance: <span class="<?= $mClass ?>"><?= h($mLabel) ?></span></p>
      <?php // The return action follows the open loan, whatever the status: an item on
      // loan can also be in an AT repair. ?>
      <?php if ($it['loan_id'] && $scanOutside): ?>
        <p>On loan (managed by the assigned staff — not your caseload).</p>
      <?php elseif ($it['loan_id']): ?>
        <p>On loan to <?= h($it['borrower'] ?? 'a client') ?><?= $it['due_date'] ? ', due ' . fmt_date($it['due_date']) : '' ?>.</p>
        <form method="post" action="equipment.php"><?= csrf_field() ?>
          <input type="hidden" name="loan_id" value="<?= (int)$it['loan_id'] ?>">
          <div class="field"><label for="ret_cond">Condition as it comes back</label>
            <select id="ret_cond" name="condition_in"><option value="">Unchanged</option><?= select_options(cms_list_items('equipment_conditions'), null) ?></select></div>
          <button type="submit" name="return_item" value="1" class="btn btn-primary" onclick="return confirm(<?= h((string)json_encode('Mark ' . $it['name'] . ' returned from ' . ($it['borrower'] ?? 'the client') . '?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>);">Mark returned</button>
        </form>
      <?php elseif ($it['status'] === 'Available'): ?>
        <?php if ($scanHeld): ?><p>This item is held by a reservation. Lending it to that client fulfils the hold; lending it to anyone else moves the hold to the waitlist and notifies that client's instructor and the staff member who made it.</p><?php endif; ?>
        <form method="post" action="equipment.php" class="stacked"><?= csrf_field() ?>
          <input type="hidden" name="equipment_id" value="<?= (int)$it['id'] ?>">
          <div class="field"><label for="sc_client">Loan to client</label>
            <select id="sc_client" name="client_id" required>
              <option value="">— Choose —</option>
              <?php
              // Scope the roster to the user's caseload, matching the main list's loan form.
              $scq = db()->prepare("SELECT id, name FROM clients WHERE status NOT LIKE 'Closed%'"
                  . (caseload_scoped() ? ' AND assigned_to = ?' : '') . ' ORDER BY name');
              $scq->execute(caseload_scoped() ? [(int)$user['id']] : []);
              foreach ($scq->fetchAll() as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="sc_due">Return due</label>
            <input type="date" id="sc_due" name="due_date" value="<?= date('Y-m-d', strtotime('+' . cms_number('loan_due_days') . ' days')) ?>"></div>
          <button type="submit" name="loan_out" value="1" class="btn btn-primary">Loan out</button>
        </form>
      <?php endif; ?>
      <?php $scKey = 'service_date_' . (int)$it['id']; $scNotesKey = 'service_notes_' . (int)$it['id'];
      $oldSc = fn(string $k, string $d = ''): string => (old_in('log_service') !== '' && old_in('equipment_id') === (string)(int)$it['id']) ? old_in($k, $d) : $d; ?>
      <form method="post" action="equipment.php" class="stacked" style="margin-top:1rem" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
        <input type="hidden" name="equipment_id" value="<?= (int)$it['id'] ?>">
        <input type="hidden" name="from_scan" value="1">
        <h2>Log a service</h2>
        <?= form_error_summary([$scKey => '', $scNotesKey => ''], [$scKey => 'sc_date', $scNotesKey => 'sc_notes']) ?>
        <div class="field"><label for="sc_type">Type</label>
          <select id="sc_type" name="service_type"><?= select_options(cms_list_items('maintenance_types'), $oldSc('service_type', 'Maintenance')) ?></select></div>
        <div class="field"><label for="sc_date">Date</label>
          <input type="date" id="sc_date" name="service_date" max="<?= date('Y-m-d') ?>" value="<?= h($oldSc('service_date', date('Y-m-d'))) ?>" <?= field_aria($scKey) ?>><?= field_error($scKey) ?></div>
        <div class="field"><label for="sc_notes">Service notes</label><input type="text" id="sc_notes" name="service_notes" maxlength="500" value="<?= h($oldSc('service_notes')) ?>" <?= field_aria($scNotesKey) ?>><?= field_error($scNotesKey) ?></div>
        <button type="submit" name="log_service" value="1" class="btn btn-secondary">Log service</button>
      </form>
    </section>
    <?php
    page_footer();
    exit;
}

$items = db()->query("SELECT e.*, l.id AS loan_id, l.loaned_at, l.due_date, c.name AS borrower, c.id AS borrower_id, c.assigned_to AS borrower_assigned,
        (l.due_date IS NOT NULL AND l.due_date < CURDATE()) AS overdue
    FROM equipment e
    LEFT JOIN equipment_loans l ON l.equipment_id = e.id AND l.returned_at IS NULL
    LEFT JOIN clients c ON c.id = l.client_id
    ORDER BY e.status = 'Retired', e.name")->fetchAll();
foreach ($items as &$item) {
    $item['outside_caseload'] = caseload_scoped() && $item['borrower_id'] !== null
        && (int)$item['borrower_assigned'] !== (int)$user['id'];
    if ($item['outside_caseload']) {
        $item['borrower'] = null;
        $item['borrower_id'] = null;
        $item['loan_id'] = null;
    }
}
unset($item);
$cq = db()->prepare("SELECT id, name FROM clients WHERE status NOT LIKE 'Closed%'"
    . (caseload_scoped() ? ' AND assigned_to = ?' : '') . " ORDER BY name");
$cq->execute(caseload_scoped() ? [(int)$user['id']] : []);
$clients = $cq->fetchAll();
$onLoan = count(array_filter($items, fn($i) => $i['status'] === 'On Loan'));
$overdue = count(array_filter($items, fn($i) => $i['overdue']));
// Asset value: current straight-line book value across non-retired items.
$bookTotal = 0.0;
foreach ($items as &$it) {
    $it['book_value'] = $it['status'] === 'Retired' ? 0.0 : equip_book_value($it['value'] !== null ? (float)$it['value'] : null, $it['purchase_date'] ?? null, (int)($it['useful_life_months'] ?? 36));
    if ($it['book_value'] !== null) $bookTotal += (float)$it['book_value'];
}
unset($it);

// Maintenance history per item + count of items due/overdue for service.
$maintHist = [];
$eids = array_map(fn($i) => (int)$i['id'], $items);
if ($eids) {
    $mh = db()->prepare('SELECT m.*, u.name AS by_name FROM equipment_maintenance m LEFT JOIN users u ON u.id = m.performed_by
        WHERE m.equipment_id IN (' . implode(',', array_fill(0, count($eids), '?')) . ') ORDER BY m.service_date DESC, m.id DESC');
    $mh->execute($eids);
    foreach ($mh->fetchAll() as $row) $maintHist[(int)$row['equipment_id']][] = $row;
}
$maintDue = 0;
foreach ($items as &$it) {
    // Due within 14 days or overdue, as the "Service due" card and filter count it.
    $it['service_due'] = false;
    if ($it['status'] === 'Retired') continue;
    [, , , $daysLeft] = maint_status($it['last_service_date'], $it['maintenance_interval_days']);
    if ($daysLeft <= 14) { $it['service_due'] = true; $maintDue++; }
}
unset($it);

// Inventory search and status filter (GET, so a filtered view can be bookmarked and the
// stat cards can link to it). The counts above and the loan and reservation pickers
// always use every item; only the inventory table is narrowed.
$invFilters = [
    '' => ['All', fn(array $i): bool => true],
    'available' => ['Available', fn(array $i): bool => $i['status'] === 'Available'],
    'on_loan' => ['On loan', fn(array $i): bool => $i['status'] === 'On Loan'],
    'overdue' => ['Overdue returns', fn(array $i): bool => (bool)$i['overdue']],
    'service_due' => ['Service due', fn(array $i): bool => $i['service_due']],
    'repair' => ['In repair', fn(array $i): bool => $i['status'] === 'Repair'],
    'retired' => ['Retired', fn(array $i): bool => $i['status'] === 'Retired'],
];
$invStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!isset($invFilters[$invStatus])) $invStatus = '';
$invQ = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 120);
$invUrl = fn(string $status, string $q): string => 'equipment.php' . (($qs = http_build_query(array_filter(['q' => $q, 'status' => $status], fn($v) => $v !== ''))) !== '' ? '?' . $qs : '') . '#inv-h';
$shown = array_values(array_filter($items, function (array $i) use ($invFilters, $invStatus, $invQ): bool {
    if (!$invFilters[$invStatus][1]($i)) return false;
    if ($invQ === '') return true;
    // Name, serial number (the asset tag) and the borrower; a borrower outside a
    // scoped user's caseload is already blanked above, so it is never matched.
    foreach (preg_split('/\s+/u', $invQ, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $hay = $i['name'] . ' ' . ($i['serial'] ?? '') . ' ' . ($i['borrower'] ?? '');
        if (mb_stripos($hay, $word) === false) return false;
    }
    return true;
}));
$invFiltered = $invStatus !== '' || $invQ !== '';
$loanFailed = array_intersect_key(form_errors(), array_flip(['loan_equipment_id', 'loan_client_id', 'loan_due_date', 'loan_notes'])) !== [];
$addFailed = array_intersect_key(form_errors(), array_flip(['name', 'serial', 'value', 'purchase_date', 'useful_life_months', 'maintenance_interval_days', 'notes'])) !== [];

// Active reservations / waitlist (caseload-scoped): holds first, then the waitlist in
// the order it was joined (the order a freed item passes to the next client).
// "live" is 0 for a hold that no longer counts (equipment_hold_live_sql()); it stays
// listed so staff can cancel it.
$resSql = "SELECT er.*, e.name AS item_name, e.status AS item_status, c.name AS client_name, c.id AS cid, u.name AS by_name,
        c.status AS client_status, c.archived_at AS client_archived, (" . equipment_hold_live_sql('er') . ") AS live
    FROM equipment_reservations er
    JOIN equipment e ON e.id = er.equipment_id
    JOIN clients c ON c.id = er.client_id
    LEFT JOIN users u ON u.id = er.reserved_by
    WHERE er.status IN ('Reserved','Waitlist')" . (caseload_scoped() ? ' AND c.assigned_to = ?' : '') . "
    ORDER BY er.status, er.created_at, er.id";
$resStmt = db()->prepare($resSql);
$resStmt->execute(caseload_scoped() ? [(int)$user['id']] : []);
$reservations = $resStmt->fetchAll();
$resCount = [];
foreach ($reservations as $rr) $resCount[(int)$rr['equipment_id']] = ($resCount[(int)$rr['equipment_id']] ?? 0) + 1;
// Items with a 'Reserved' hold that still counts, for any caseload: the loan picker
// flags them (without the client) so a hold is never taken over unknowingly.
$heldIds = array_flip(array_map('intval', db()->query("SELECT DISTINCT er.equipment_id FROM equipment_reservations er WHERE er.status = 'Reserved' AND " . equipment_hold_live_sql('er'))->fetchAll(PDO::FETCH_COLUMN)));

page_header('Equipment & loans', $user, 'equipment');
?>
<h1>Equipment &amp; loans</h1>
<div class="page-actions"><a class="btn btn-secondary btn-sm" href="equipment_labels.php">Print QR labels</a>
  <a class="btn btn-secondary btn-sm" href="equipment_import.php">Import from CSV</a></div>
<div class="grid grid-4">
  <?php // Each count links to the inventory filtered to those items. ?>
  <div class="card stat"><a href="<?= h($invUrl('', '')) ?>"><span class="stat-num"><?= count($items) ?></span><span class="stat-label">Items tracked</span></a></div>
  <div class="card stat"><a href="<?= h($invUrl('on_loan', '')) ?>"><span class="stat-num"><?= $onLoan ?></span><span class="stat-label">On loan</span></a></div>
  <div class="card stat"><a href="<?= h($invUrl('overdue', '')) ?>"><span class="stat-num"><?= $overdue ?></span><span class="stat-label">Overdue returns</span></a></div>
  <div class="card stat"><a href="<?= h($invUrl('service_due', '')) ?>"><span class="stat-num"><?= $maintDue ?></span><span class="stat-label">Service due / overdue</span></a></div>
  <div class="card stat"><span class="stat-num"><?= fmt_money($bookTotal) ?></span><span class="stat-label">Current value of equipment</span></div>
</div>
<p class="muted">Current value (book value) is what an item cost, reduced by an equal amount each month over its useful life, counted from its purchase date.</p>
<?php $requiredNote = '<p class="muted">Fields marked with an asterisk (<span aria-hidden="true">*</span><span class="visually-hidden">asterisk</span>) are required.</p>'; ?>

<h2 class="visually-hidden">Loan an item out</h2>
<details class="card" id="loan"<?= $loanFailed ? ' open' : '' ?>>
  <summary accesskey="n" id="loan-h">Loan an item out</summary>
  <p class="muted" id="loan-hold-hint">Items marked “held by a reservation” are reserved for a client. Lending one to someone else moves that hold to the waitlist and notifies that client's instructor and the staff member who made it.</p>
  <?php // After a refused loan the form comes back as it was filled in, with each error on its field.
  $oldLoan = fn(string $k, string $default = ''): string => old_in('loan_out') !== '' ? old_in($k, $default) : $default; ?>
  <form method="post" action="equipment.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['loan_equipment_id' => '', 'loan_client_id' => '', 'loan_due_date' => '', 'loan_notes' => ''], ['loan_equipment_id' => 'equipment_id', 'loan_client_id' => 'lo_client', 'loan_due_date' => 'due_date', 'loan_notes' => 'lo_notes']) ?>
    <?= $requiredNote ?>
    <div class="grid grid-4">
      <div class="field">
        <label for="equipment_id">Loan: item <?= required_mark() ?></label>
        <select id="equipment_id" name="equipment_id" required <?= field_aria('loan_equipment_id', 'loan-hold-hint') ?>>
          <option value="">— Available items —</option>
          <?php foreach ($items as $i): if ($i['status'] !== 'Available') continue; ?>
          <option value="<?= (int)$i['id'] ?>" <?= $oldLoan('equipment_id') === (string)$i['id'] ? 'selected' : '' ?>><?= h($i['name'] . ($i['serial'] ? ' (serial number ' . $i['serial'] . ')' : '') . (isset($heldIds[(int)$i['id']]) ? ' — held by a reservation' : '')) ?></option>
          <?php endforeach; ?>
        </select><?= field_error('loan_equipment_id') ?>
      </div>
      <div class="field">
        <label for="lo_client">Loan: client <?= required_mark() ?></label>
        <select id="lo_client" name="client_id" required <?= field_aria('loan_client_id') ?>>
          <option value="">— Choose —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $oldLoan('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select><?= field_error('loan_client_id') ?>
      </div>
      <div class="field"><label for="due_date">Return due</label>
        <input type="date" id="due_date" name="due_date" value="<?= h($oldLoan('due_date', date('Y-m-d', strtotime('+' . cms_number('loan_due_days') . ' days')))) ?>" <?= field_aria('loan_due_date') ?>><?= field_error('loan_due_date') ?></div>
      <div class="field"><label for="lo_notes">Loan notes</label>
        <input type="text" id="lo_notes" name="notes" maxlength="255" value="<?= h($oldLoan('notes')) ?>" <?= field_aria('loan_notes') ?>><?= field_error('loan_notes') ?></div>
      <div class="field"><label for="lo_cond">Condition as it goes out</label>
        <select id="lo_cond" name="condition_out"><option value="">Not recorded</option><?= select_options(cms_list_items('equipment_conditions'), $oldLoan('condition_out') ?: null) ?></select></div>
      <div class="field"><label for="lo_accept">Client's name, typed by the client, accepting the loan agreement</label>
        <input type="text" id="lo_accept" name="accepted_name" maxlength="120" autocomplete="off" value="<?= h($oldLoan('accepted_name')) ?>"></div>
    </div>
    <button type="submit" name="loan_out" value="1" class="btn btn-primary">Loan out</button>
  </form>
</details>

<h2 class="visually-hidden">Add equipment</h2>
<details class="card" id="add"<?= $addFailed ? ' open' : '' ?>>
  <summary id="add-h">Add equipment</summary>
  <?php // After a rejected submission the form comes back filled in, with each error on its field.
  $oldItem = fn(string $k, string $default = ''): string => old_in('add_item') !== '' ? old_in($k, $default) : $default; ?>
  <form method="post" action="equipment.php" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['name' => '', 'serial' => '', 'value' => '', 'purchase_date' => '', 'useful_life_months' => '', 'maintenance_interval_days' => '', 'notes' => ''], ['maintenance_interval_days' => 'maint_interval']) ?>
    <?= $requiredNote ?>
    <div class="grid grid-4">
      <div class="field"><label for="name">Item name <?= required_mark() ?></label>
        <input type="text" id="name" name="name" required maxlength="190" value="<?= h($oldItem('name')) ?>" <?= field_aria('name') ?>><?= field_error('name') ?></div>
      <div class="field"><label for="category">Category</label>
        <select id="category" name="category"><?= select_options(cms_list_items('equipment_categories'), $oldItem('category', 'Other')) ?></select></div>
      <div class="field"><label for="serial">Serial number</label>
        <input type="text" id="serial" name="serial" maxlength="120" value="<?= h($oldItem('serial')) ?>" <?= field_aria('serial') ?>><?= field_error('serial') ?></div>
      <div class="field"><label for="item_condition">Condition</label>
        <select id="item_condition" name="item_condition"><?= select_options(cms_list_items('equipment_conditions'), $oldItem('item_condition', 'Good')) ?></select></div>
      <div class="field"><label for="value">Value (USD)</label>
        <span class="hint" id="value-hint">Purchase or acquisition cost in dollars, for example 2995.00</span>
        <input type="text" inputmode="decimal" id="value" name="value" value="<?= h($oldItem('value')) ?>" <?= field_aria('value', 'value-hint') ?>><?= field_error('value') ?></div>
      <div class="field"><label for="purchase_date">Purchase date</label>
        <input type="date" id="purchase_date" name="purchase_date" max="<?= date('Y-m-d') ?>" value="<?= h($oldItem('purchase_date')) ?>" <?= field_aria('purchase_date') ?>><?= field_error('purchase_date') ?></div>
      <div class="field"><label for="useful_life_months">Useful life (months)</label>
        <span class="hint" id="life-hint">Whole months, 1 to 600, used to work out current value; default 36</span>
        <input type="text" inputmode="numeric" id="useful_life_months" name="useful_life_months" value="<?= h($oldItem('useful_life_months', '36')) ?>" <?= field_aria('useful_life_months', 'life-hint') ?>><?= field_error('useful_life_months') ?></div>
      <div class="field"><label for="maint_interval">Service interval</label>
        <span class="hint" id="interval-hint">Whole days between maintenance or calibration, 0 to 3650; blank = none</span>
        <input type="text" inputmode="numeric" id="maint_interval" name="maintenance_interval_days" value="<?= h($oldItem('maintenance_interval_days')) ?>" <?= field_aria('maintenance_interval_days', 'interval-hint') ?>><?= field_error('maintenance_interval_days') ?></div>
      <div class="field"><label for="notes">Item notes</label>
        <input type="text" id="notes" name="notes" maxlength="255" value="<?= h($oldItem('notes')) ?>" <?= field_aria('notes') ?>><?= field_error('notes') ?></div>
    </div>
    <button type="submit" name="add_item" value="1" class="btn btn-primary">Add item</button>
  </form>
</details>

<h2 id="inv-h">Inventory (<?= count($items) ?>)</h2>
<form method="get" action="equipment.php#inv-h" class="inline-form" role="search" aria-label="Search equipment">
  <?php if ($invStatus !== ''): ?><input type="hidden" name="status" value="<?= h($invStatus) ?>"><?php endif; ?>
  <label for="inv-q">Search by item name, serial number or borrower</label>
  <input type="search" id="inv-q" name="q" value="<?= h($invQ) ?>" maxlength="120" autocomplete="off">
  <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  <?php if ($invFiltered): ?><a class="btn btn-secondary btn-sm" href="equipment.php#inv-h">Clear search and filter</a><?php endif; ?>
</form>
<nav aria-label="Filter inventory by status">
  <ul class="tab-nav">
    <?php foreach ($invFilters as $fKey => [$fLabel]): ?>
    <li><a href="<?= h($invUrl($fKey, $invQ)) ?>" <?= $invStatus === $fKey ? 'aria-current="page"' : '' ?>><?= h($fLabel) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<p class="hint" id="inv-count"><?= $invFiltered ? 'Showing ' . count($shown) . ' of ' . count($items) . ' items' . ($invStatus !== '' ? ' — ' . h($invFilters[$invStatus][0]) : '') . ($invQ !== '' ? ' matching “' . h($invQ) . '”' : '') . '.' : 'Showing all ' . count($items) . ' items.' ?></p>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="inv-h">
<table>
  <caption class="visually-hidden">Equipment inventory: condition, status, borrower and maintenance for each item</caption>
  <thead><tr>
    <th scope="col">Item</th><th scope="col">Category</th><th scope="col">Condition</th>
    <th scope="col">Status</th><th scope="col">With client</th><th scope="col">Maintenance</th><th scope="col"><span class="visually-hidden">Actions</span></th>
  </tr></thead>
  <tbody>
  <?php if (!$items): ?><tr><td colspan="7" class="muted">No equipment yet — use “Add equipment” above to add your first item.</td></tr>
  <?php elseif (!$shown): ?><tr><td colspan="7" class="muted">No items match — try different words or another filter.</td></tr><?php endif; ?>
  <?php foreach ($shown as $i): ?>
    <tr>
      <th scope="row"><?= h($i['name']) ?><?= $i['serial'] ? '<br><span class="muted">Serial number ' . h($i['serial']) . '</span>' : '' ?></th>
      <td><?= h($i['category']) ?></td>
      <td><?= h($i['item_condition']) ?>
        <?php if ($i['value'] !== null): ?><br><span class="muted"><?= $i['purchase_date'] ? 'Current value ' . fmt_money((float)($i['book_value'] ?? 0)) . ' (bought for ' . fmt_money((float)$i['value']) . ')' : 'Value ' . fmt_money((float)$i['value']) . ' (no purchase date, so not depreciated)' ?></span><?php endif; ?></td>
      <td><?php $bm = ['Available' => 'badge-closed', 'On Loan' => 'badge-active', 'Repair' => 'badge-pending', 'Retired' => 'badge-hold']; ?>
          <span class="badge <?= $bm[$i['status']] ?>"><?= h($i['status']) ?></span>
          <?= $i['overdue'] ? '<br><strong class="field-error">Overdue since ' . fmt_date($i['due_date']) . '</strong>' : '' ?>
          <?= ($resCount[(int)$i['id']] ?? 0) ? '<br><span class="muted">' . (int)$resCount[(int)$i['id']] . ' waiting</span>' : '' ?></td>
      <td><?= $i['outside_caseload'] ? 'On loan (not your caseload)' : ($i['borrower'] ? '<a href="client.php?id=' . (int)$i['borrower_id'] . '">' . h($i['borrower']) . '</a><br><span class="muted">out ' . fmt_date($i['loaned_at']) . ($i['due_date'] ? ', due ' . fmt_date($i['due_date']) : '') . '</span>' : '<span aria-hidden="true">—</span><span class="visually-hidden">not on loan</span>') ?></td>
      <td>
        <?php // A retired item is no longer serviced, so it is never shown as due or overdue.
        [$mLabel, $mClass] = $i['status'] === 'Retired' ? ['Not scheduled (retired)', 'muted'] : maint_status($i['last_service_date'], $i['maintenance_interval_days']); $hist = $maintHist[(int)$i['id']] ?? [];
        // A refused service date (or correction) comes back on this item's own fields,
        // with what was typed kept and its Service section open.
        $iid = (int)$i['id'];
        $svcKey = 'service_date_' . $iid; $snKey = 'service_notes_' . $iid; $scostKey = 'service_cost_' . $iid; $cdKey = 'corrected_date_' . $iid; $crKey = 'correction_reason_' . $iid;
        $mine = old_in('equipment_id') === (string)$iid;
        $oldSvc = fn(string $k, string $d = ''): string => ($mine && old_in('log_service') !== '') ? old_in($k, $d) : $d;
        $oldCor = fn(string $k, string $d = ''): string => ($mine && old_in('correct_service') !== '') ? old_in($k, $d) : $d;
        $rowErr = has_field_error($svcKey) || has_field_error($snKey) || has_field_error($scostKey) || has_field_error($cdKey) || has_field_error($crKey); ?>
        <span class="<?= $mClass ?>"><?= h($mLabel) ?></span>
        <details class="goal-history"<?= $rowErr ? ' open' : '' ?>>
          <summary>Service<?= $hist ? ' (' . count($hist) . ')' : '' ?><span class="visually-hidden"> — <?= h($i['name']) ?></span></summary>
          <form method="post" action="equipment.php" class="stacked" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
            <input type="hidden" name="equipment_id" value="<?= $iid ?>">
            <?= form_error_summary([$svcKey => '', $snKey => '', $scostKey => ''], [$svcKey => 'md-' . $iid, $snKey => 'mn-' . $iid, $scostKey => 'mc-' . $iid]) ?>
            <label for="mt-<?= $iid ?>">Type</label>
            <select id="mt-<?= $iid ?>" name="service_type"><?= select_options(cms_list_items('maintenance_types'), $oldSvc('service_type', 'Maintenance')) ?></select>
            <label for="md-<?= $iid ?>">Date</label>
            <input type="date" id="md-<?= $iid ?>" name="service_date" max="<?= date('Y-m-d') ?>" value="<?= h($oldSvc('service_date', date('Y-m-d'))) ?>" <?= field_aria($svcKey) ?>><?= field_error($svcKey) ?>
            <label for="mn-<?= $iid ?>">Service notes</label>
            <input type="text" id="mn-<?= $iid ?>" name="service_notes" maxlength="500" value="<?= h($oldSvc('service_notes')) ?>" <?= field_aria($snKey) ?>><?= field_error($snKey) ?>
            <label for="mc-<?= $iid ?>">Cost</label>
            <span class="hint" id="mc-hint-<?= $iid ?>">In dollars, for example 125.00; blank if none</span>
            <input type="text" inputmode="decimal" id="mc-<?= $iid ?>" name="service_cost" value="<?= h($oldSvc('service_cost')) ?>" <?= field_aria($scostKey, 'mc-hint-' . $iid) ?>><?= field_error($scostKey) ?>
            <button type="submit" name="log_service" value="1" class="btn btn-secondary btn-sm">Log service<span class="visually-hidden"> — <?= h($i['name']) ?></span></button>
          </form>
          <?php if (can('admin')): ?>
          <form method="post" action="equipment.php" class="stacked" style="margin-top:.4rem" novalidate><?= csrf_field() ?>
            <input type="hidden" name="equipment_id" value="<?= $iid ?>">
            <?= form_error_summary([$cdKey => '', $crKey => ''], [$cdKey => 'cd-' . $iid, $crKey => 'cr-' . $iid]) ?>
            <label for="cd-<?= $iid ?>">Corrected last service date<span class="visually-hidden"> — <?= h($i['name']) ?></span></label>
            <span class="hint" id="cd-hint-<?= $iid ?>">Administrators: set the date when the stored one is wrong (logging a service never moves it back). Blank = no service on record.</span>
            <input type="date" id="cd-<?= $iid ?>" name="corrected_date" max="<?= date('Y-m-d') ?>" value="<?= h($oldCor('corrected_date', (string)($i['last_service_date'] ?? ''))) ?>" <?= field_aria($cdKey, 'cd-hint-' . $iid) ?>><?= field_error($cdKey) ?>
            <label for="cr-<?= $iid ?>">Reason for the correction<span class="visually-hidden"> — <?= h($i['name']) ?></span></label>
            <input type="text" id="cr-<?= $iid ?>" name="correction_reason" maxlength="300" value="<?= h($oldCor('correction_reason')) ?>" <?= field_aria($crKey) ?>><?= field_error($crKey) ?>
            <button type="submit" name="correct_service" value="1" class="btn btn-secondary btn-sm">Correct last service date<span class="visually-hidden"> — <?= h($i['name']) ?></span></button>
          </form>
          <?php endif; ?>
          <form method="post" action="equipment.php" class="inline-form" style="margin-top:.4rem"><?= csrf_field() ?>
            <input type="hidden" name="equipment_id" value="<?= (int)$i['id'] ?>">
            <label for="mi-<?= (int)$i['id'] ?>">Service every<span class="visually-hidden"> (days)</span><span class="visually-hidden"> — <?= h($i['name']) ?></span></label>
            <input type="number" id="mi-<?= (int)$i['id'] ?>" name="interval" min="0" max="3650" step="1" value="<?= $i['maintenance_interval_days'] !== null ? (int)$i['maintenance_interval_days'] : '' ?>" style="max-width:5rem"> <span aria-hidden="true">days</span>
            <button type="submit" name="set_interval" value="1" class="btn btn-secondary btn-sm">Set<span class="visually-hidden"> service interval — <?= h($i['name']) ?></span></button>
          </form>
          <?php if ($hist): ?>
          <ul class="timeline">
            <?php foreach (array_slice($hist, 0, 6) as $hr): ?>
            <li><strong><?= h($hr['service_type']) ?></strong> · <?= fmt_date($hr['service_date']) ?>
              <?= $hr['cost'] !== null ? ' · ' . fmt_money((float)$hr['cost']) : '' ?>
              <span class="muted"><?= $hr['by_name'] ? 'by ' . h($hr['by_name']) : '' ?></span>
              <?= $hr['notes'] ? '<br>' . h($hr['notes']) : '' ?></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </details>
      </td>
      <td>
        <?php if ($i['outside_caseload']): ?>
        <span class="muted">Managed by the assigned staff</span>
        <?php elseif ($i['loan_id']): // The open loan decides, not the status: an item on loan can also be in an AT repair. ?>
        <form method="post" action="equipment.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="loan_id" value="<?= (int)$i['loan_id'] ?>">
          <button type="submit" name="return_item" value="1" class="btn btn-secondary btn-sm" onclick="return confirm(<?= h((string)json_encode('Mark ' . $i['name'] . ' returned from ' . ($i['borrower'] ?? 'the client') . '?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>);">Mark returned<span class="visually-hidden"> — <?= h($i['name']) ?></span></button>
        </form>
        <?php elseif ($i['status'] === 'Retired'): // Back into service only through a reuse intake (its checks are recorded there). ?>
        <a href="at_services.php?kind=reuse&amp;new=1&amp;equipment_id=<?= (int)$i['id'] ?>">Start reuse intake<span class="visually-hidden"> — <?= h($i['name']) ?></span></a>
        <?php else: ?>
        <form method="post" action="equipment.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="equipment_id" value="<?= (int)$i['id'] ?>">
          <label class="visually-hidden" for="es-<?= (int)$i['id'] ?>">Change status of <?= h($i['name']) ?></label>
          <?php // Choosing Retired opens a confirmation page before anything changes. ?>
          <select id="es-<?= (int)$i['id'] ?>" name="to">
            <?php foreach (['Available','Repair','Retired'] as $st): ?>
            <option value="<?= $st ?>" <?= $i['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> status — <?= h($i['name']) ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<section class="card" aria-labelledby="res-h">
  <h2 id="res-h">Reservations &amp; waitlist (<?= count($reservations) ?>)</h2>
  <p class="muted">Hold an available item for a client, or add them to the waitlist for one that's on loan. When an item is loaned to that client, their reservation is fulfilled automatically.</p>
  <?php if ($reservations): ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="res-cap">
  <table>
    <caption class="visually-hidden" id="res-cap">Active holds, then the waitlist in the order it was joined: item, client, hold type and dates needed</caption>
    <thead><tr><th scope="col">Item</th><th scope="col">Client</th><th scope="col">Hold</th><th scope="col">Needed</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($reservations as $rr):
      // Why a listed hold no longer keeps the item (equipment_hold_live_sql()), in words.
      $notCounted = (int)$rr['live'] === 1 ? '' : ($rr['client_archived'] !== null ? 'the client is archived' : (str_starts_with((string)$rr['client_status'], 'Closed') ? 'the client\'s case is closed' : 'the "needed until" date has passed')); ?>
      <tr>
        <th scope="row"><?= h($rr['item_name']) ?><br><span class="muted"><?= h($rr['item_status']) ?></span></th>
        <td><a href="client.php?id=<?= (int)$rr['cid'] ?>"><?= h($rr['client_name']) ?></a></td>
        <td><?= $rr['status'] === 'Reserved' ? '<span class="badge badge-active">Reserved</span>' : '<span class="badge badge-pending">Waitlist</span>' ?>
          <br><span class="muted">added <?= fmt_date($rr['created_at'], true) ?></span>
          <?= $notCounted !== '' ? '<br><strong>' . ($rr['status'] === 'Reserved' ? 'No longer holds the item' : 'Passed over when the item is free') . ': ' . h($notCounted) . '. Cancel it if it is no longer needed.</strong>' : '' ?></td>
        <td><?= $rr['needed_from'] ? fmt_date($rr['needed_from']) . ($rr['needed_until'] ? ' – ' . fmt_date($rr['needed_until']) : '') : ($rr['needed_until'] ? 'Until ' . fmt_date($rr['needed_until']) : no_date_mark(true)) ?>
          <?= $rr['notes'] ? '<br><span class="muted">' . h($rr['notes']) . '</span>' : '' ?></td>
        <td><form method="post" action="equipment.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="reservation_id" value="<?= (int)$rr['id'] ?>">
          <?php // The dialog names the hold and says what each of its buttons does, since its own "Cancel" keeps the reservation. ?>
          <button type="submit" name="cancel_reservation" value="1" class="btn-link" onclick="return confirm(<?= h((string)json_encode('Cancel the ' . ($rr['status'] === 'Reserved' ? 'reservation' : 'waitlist entry') . ' of ' . $rr['item_name'] . ' for ' . $rr['client_name'] . '? Choose OK to cancel it, or Cancel to keep it.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>);">cancel<span class="visually-hidden"> — <?= h($rr['item_name']) ?> for <?= h($rr['client_name']) ?></span></button>
        </form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?><p class="muted">No active reservations.</p><?php endif; ?>
  <?php // After a rejected date the form comes back filled in, with the error on its field.
  $oldRes = fn(string $k): string => old_in('add_reservation') !== '' ? old_in($k) : ''; ?>
  <form method="post" action="equipment.php" class="stacked" style="margin-top:1rem"><?= csrf_field() ?>
    <h3>Reserve or waitlist an item</h3>
    <?= form_error_summary(['res_client_id' => '', 'needed_from' => '', 'needed_until' => '', 'res_notes' => ''], ['res_client_id' => 'res_client', 'needed_from' => 'res_from', 'needed_until' => 'res_until', 'res_notes' => 'res_notes']) ?>
    <?= $requiredNote ?>
    <div class="grid grid-4">
      <div class="field"><label for="res_item">Reserve: item <?= required_mark() ?></label>
        <select id="res_item" name="equipment_id" required>
          <option value="">— Choose —</option>
          <?php foreach ($items as $i): if ($i['status'] === 'Retired') continue; ?>
          <option value="<?= (int)$i['id'] ?>" <?= $oldRes('equipment_id') === (string)$i['id'] ? 'selected' : '' ?>><?= h($i['name']) ?> (<?= h($i['status']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="res_client">Reserve: client <?= required_mark() ?></label>
        <select id="res_client" name="client_id" required <?= field_aria('res_client_id') ?>>
          <option value="">— Choose —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $oldRes('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select><?= field_error('res_client_id') ?></div>
      <div class="field"><label for="res_from">Needed from</label><input type="date" id="res_from" name="needed_from" value="<?= h($oldRes('needed_from')) ?>" <?= field_aria('needed_from') ?>><?= field_error('needed_from') ?></div>
      <div class="field"><label for="res_until">Needed until</label><input type="date" id="res_until" name="needed_until" value="<?= h($oldRes('needed_until')) ?>" <?= field_aria('needed_until') ?>><?= field_error('needed_until') ?></div>
    </div>
    <div class="field"><label for="res_notes">Reservation notes</label><input type="text" id="res_notes" name="notes" maxlength="500" value="<?= h($oldRes('notes')) ?>" <?= field_aria('res_notes') ?>><?= field_error('res_notes') ?></div>
    <button type="submit" name="add_reservation" value="1" class="btn btn-secondary">Add reservation</button>
  </form>
</section>
<?php page_footer();
