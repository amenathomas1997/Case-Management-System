<?php
declare(strict_types=1);
// The equipment rules equipment.php and the apps share (4.37.0): a status change (in repair, back in
// service, retired with its confirmation), which moves an item may be offered, whether a row is the
// caller's to manage, the inventory's retired filter and a new item's fields. Moved from
// equipment.php, so the page and the API give the same refusals, audit, notifications and wording.

const EQUIPMENT_STATUS_MOVES = ['Available', 'Repair', 'Retired'];

/** Whether an item is in an active AT repair or reuse workflow (its status is changed there). The AT
 *  tables may predate their migration: then nothing is in a workflow. */
function equipment_in_at_workflow(int $eid): bool {
    try {
        $w = db()->prepare("SELECT id FROM at_records WHERE equipment_id = ? AND ((kind='repair' AND status NOT IN ('Resolved','Cancelled')) OR (kind='reuse' AND status NOT IN ('Redeployed','Recycled'))) LIMIT 1");
        $w->execute([$eid]);
        return (bool)$w->fetchColumn();
    } catch (PDOException $e) {
        if (!in_array((string)$e->getCode(), ['42S02', '42S22'], true)) throw $e;
        return false;
    }
}

/** Whether the caller manages this row (equipment.php's "Managed by the assigned staff"): an item on
 *  loan to a client outside a caseload-scoped user's caseload is not theirs to change. $item needs
 *  borrower_id and borrower_assigned (the open loan's client and that client's instructor). */
function equipment_manageable(array $item, int $userId): bool {
    return !(caseload_scoped() && ($item['borrower_id'] ?? null) !== null && (int)($item['borrower_assigned'] ?? 0) !== $userId);
}

/** The status moves equipment.php offers on a row: none on loan (Mark returned instead), none from
 *  Retired (Start reuse intake instead), none while an AT workflow owns the status, else the other two. */
function equipment_status_actions(array $item, bool $onLoan, bool $inWorkflow): array {
    $status = (string)($item['status'] ?? '');
    if ($onLoan || $inWorkflow || $status === 'Retired') return [];
    return array_values(array_filter(EQUIPMENT_STATUS_MOVES, fn(string $s): bool => $s !== $status));
}

/** The middle sentence of the retire confirmation: the holds it cancels, or none. */
function equipment_retire_hold_text(int $holds): string {
    if ($holds === 0) return 'No reservations or waitlist entries are affected.';
    return $holds . ' reservation' . ($holds === 1 ? ' or waitlist entry' : 's and waitlist entries') . ' for this item will be cancelled, and each client\'s instructor and the staff who made '
        . ($holds === 1 ? 'it' : 'them') . ' will be notified. Putting the item back into service later does not restore ' . ($holds === 1 ? 'it' : 'them') . '.';
}

/** The retire confirmation's facts: the item, its current status, how many reservations and waitlist
 *  entries retiring it cancels, and the sentence that says so. Null when the item is not on file. */
function equipment_retire_preview(int $eid): ?array {
    $ri = db()->prepare('SELECT id, name, status FROM equipment WHERE id = ?');
    $ri->execute([$eid]);
    $item = $ri->fetch();
    if (!$item) return null;
    $rc = db()->prepare("SELECT COUNT(*) FROM equipment_reservations WHERE equipment_id = ? AND status IN ('Reserved','Waitlist')");
    $rc->execute([$eid]);
    $holds = (int)$rc->fetchColumn();
    return ['id' => (int)$item['id'], 'name' => (string)$item['name'], 'status' => (string)$item['status'], 'holds' => $holds, 'text' => equipment_retire_hold_text($holds)];
}

/**
 * Change an item's status (Available, Repair or Retired), as equipment.php's Save does. The row is
 * locked first (the order loans and AT workflows use); the change is refused, with the reason, when
 * the item is on loan, retired (back into service only through a reuse intake) or owned by an active
 * AT workflow. Back in service promotes the longest waitlist entry; retiring cancels the item's holds
 * (their staff are told) and closes its open service-due task. Only a real change is audited.
 * Returns ['ok' => true, 'status', 'from', 'cancelled', 'promoted', 'changed', 'message'],
 * ['ok' => false, 'error', 'code'] or ['ok' => false, 'needs_confirm' => true] (Retired, unconfirmed).
 */
function equipment_set_status(int $eid, string $to, int $actor, bool $confirmRetire, string $channel = 'web'): array {
    if (!in_array($to, EQUIPMENT_STATUS_MOVES, true)) return ['ok' => false, 'error' => 'Choose Available, Repair or Retired.', 'code' => 422];
    // Retiring cancels the item's reservations and waitlist entries, which setting it back to
    // Available does not restore, so it is confirmed first.
    if ($to === 'Retired' && !$confirmRetire) {
        $was = db()->prepare('SELECT status FROM equipment WHERE id = ?');
        $was->execute([$eid]);
        $wasStatus = $was->fetchColumn();
        if ($wasStatus !== false && $wasStatus !== 'Retired') return ['ok' => false, 'needs_confirm' => true];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT status FROM equipment WHERE id = ? FOR UPDATE');
        $cur->execute([$eid]);
        $from = $cur->fetchColumn();
        $refusal = null; $code = 409;
        if ($from === false) { $refusal = 'Item not found.'; $code = 404; }
        else {
            $open = $pdo->prepare('SELECT id FROM equipment_loans WHERE equipment_id = ? AND returned_at IS NULL LIMIT 1');
            $open->execute([$eid]);
            // An open loan decides (an 'On Loan' status left without one can be corrected here).
            if ($open->fetchColumn()) $refusal = 'This item is on loan, so its status was not changed. Mark it returned first.';
            // A retired item goes back into service only through a reuse intake, which records the
            // inspection, sanitizing and data wipe before the device can be Available again.
            elseif ($from === 'Retired' && $to !== 'Retired') $refusal = 'This item is retired, so it was not put back into service here. Start a Device reuse intake for it in AT services: the inspection, sanitizing and data-wipe checks are recorded there before it becomes Available.';
        }
        if ($refusal === null && equipment_in_at_workflow($eid)) $refusal = 'This item is in an active AT repair or reuse workflow — update its status there.';
        $cancelledHolds = 0;
        $holders = [];
        $promoted = null;
        if ($refusal === null && $from !== $to) {
            $pdo->prepare('UPDATE equipment SET status = ? WHERE id = ?')->execute([$to, $eid]);
            // Back in service: the client who has waited longest gets the hold, under the lock.
            if ($to === 'Available') $promoted = promote_equipment_waitlist($eid, $actor);
            // A retired item never returns to service, so its holds could never be met: they are
            // cancelled with the retirement, and whoever made each one is told.
            if ($to === 'Retired') {
                $cancelled = cancel_retired_holds($pdo, $eid);
                $cancelledHolds = $cancelled['count'];
                $holders = $cancelled['holders'];
                // A retired item is no longer serviced: its open "service due" task is closed now.
                $pdo->prepare("UPDATE tasks SET status = 'Done', done_at = NOW(), done_by = ? WHERE auto_key LIKE ? AND status = 'Open'")
                    ->execute([$actor, 'equip-maint-' . $eid . '-%']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($refusal !== null) return ['ok' => false, 'error' => $refusal, 'code' => $code];
    if ($from === $to) return ['ok' => true, 'status' => $to, 'from' => $from, 'cancelled' => 0, 'promoted' => false, 'changed' => false, 'message' => 'Status is already ' . $to . '.'];
    audit($actor, 'equipment.status', 'equipment', $eid, $from . ' to ' . $to
        . ($cancelledHolds ? '; ' . $cancelledHolds . ' reservation' . ($cancelledHolds === 1 ? '' : 's') . ' cancelled' : '') . ($channel === 'mobile' ? ' (mobile)' : ''));
    notify_retired_holders($eid, $holders, $actor);
    $message = 'Status updated.' . ($cancelledHolds ? ' ' . $cancelledHolds . ' reservation' . ($cancelledHolds === 1 ? ' was' : 's were') . ' cancelled because the item is retired.' : '')
        . ($promoted !== null ? ' The item is now held for the client who has waited longest on its waitlist.' : '');
    return ['ok' => true, 'status' => $to, 'from' => (string)$from, 'cancelled' => $cancelledHolds, 'promoted' => $promoted !== null, 'changed' => true, 'message' => $message];
}

/** The inventory's filter on retired items: equipment.php lists them (last); the apps' list leaves
 *  them out unless asked (4.37.0, include_retired). A condition on the alias e. */
function equipment_list_status_sql(bool $includeRetired): string {
    return $includeRetired ? '1=1' : "e.status <> 'Retired'";
}

/**
 * A new item's fields as equipment.php's Add item form checks them, from $in (the form, or the app's
 * body): each problem on its own field, text longer than its column refused (never cut), the money
 * and dates checked, life 36 months by default, an unknown category, condition or AT Act type read as
 * Other, Good and "guessed from the category" (NULL). $unreadable lists fields the browser could not
 * read (a partly typed date). Returns [errors, values]; values holds the INSERT's columns.
 */
function equipment_input(array $in, array $unreadable = []): array {
    $str = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : (is_int($in[$k] ?? null) || is_float($in[$k] ?? null) ? trim((string)$in[$k]) : '');
    $name = $str('name');
    $serial = $str('serial');
    $itemNotes = $str('notes');
    $errors = [];
    if ($name === '') $errors['name'] = 'Item name is required.';
    elseif (mb_strlen($name) > 190) $errors['name'] = 'Item name must be 190 characters or fewer.';
    if (mb_strlen($serial) > 120) $errors['serial'] = 'Serial number must be 120 characters or fewer.';
    $valueRaw = equip_amount($str('value'));
    if ($valueRaw === null) $errors['value'] = 'Value: enter one amount in dollars from 0 to 9,999,999.99, for example 2995.00.';
    $lifeRaw = $str('useful_life_months');
    if ($lifeRaw !== '' && (!preg_match('/^\d{1,3}$/D', $lifeRaw) || (int)$lifeRaw < 1 || (int)$lifeRaw > 600)) $errors['useful_life_months'] = 'Useful life: enter a whole number of months from 1 to 600, or leave it blank for 36.';
    $ivRaw = $str('maintenance_interval_days');
    if ($ivRaw !== '' && (!preg_match('/^\d{1,4}$/D', $ivRaw) || (int)$ivRaw > 3650)) $errors['maintenance_interval_days'] = 'Service interval: enter a whole number of days from 0 to 3650, or leave it blank for no schedule.';
    if (mb_strlen($itemNotes) > 255) $errors['notes'] = 'Notes must be 255 characters or fewer.';
    // A purchase date that cannot be read, has a mistyped year or is in the future is refused on
    // its field (it sets the current value), not dropped.
    $purchaseRaw = $str('purchase_date');
    $purchase = valid_date($purchaseRaw, 1970, (int)date('Y'));
    if (in_array('purchase_date', $unreadable, true) || ($purchaseRaw !== '' && $purchase === null)) $errors['purchase_date'] = 'Purchase date: enter the full date (month, day and year) with a year from 1970 to ' . date('Y') . ', or leave it blank.';
    elseif ($purchase !== null && $purchase > date('Y-m-d')) $errors['purchase_date'] = 'Purchase date cannot be in the future.';
    $iv = $ivRaw !== '' ? (int)$ivRaw : null;
    $values = [
        'name' => $name,
        'category' => in_array($in['category'] ?? '', cms_list_items('equipment_categories'), true) ? (string)$in['category'] : 'Other',
        'serial' => $serial !== '' ? $serial : null,
        'item_condition' => in_array($in['item_condition'] ?? '', cms_list_items('equipment_conditions'), true) ? (string)$in['item_condition'] : 'Good',
        'value' => $valueRaw !== null && $valueRaw !== '' ? (float)$valueRaw : null,
        'purchase_date' => $purchase,
        'useful_life_months' => $lifeRaw !== '' && !isset($errors['useful_life_months']) ? (int)$lifeRaw : 36,
        'notes' => $itemNotes !== '' ? $itemNotes : null,
        'maintenance_interval_days' => $iv ?: null,
        'at_type' => is_string($in['at_type'] ?? null) && in_array($in['at_type'], AT_ACT_TYPES, true) ? $in['at_type'] : null,
    ];
    return [$errors, $values];
}

/** A typed dollar amount, read as the CSV import reads one: a dollar sign, spaces and thousands
 *  commas are allowed. Returns the plain number, '' when blank, or null when it is not one amount
 *  from 0 to 9,999,999.99 with up to two decimal places. */
function equip_amount(string $raw): ?string {
    $clean = str_replace(['$', ' ', "\u{00A0}"], '', trim($raw));
    if ($clean === '') return '';
    if (!preg_match('/^(\d{1,3}(,\d{3})+|\d+)(\.\d{1,2})?$/D', $clean)) return null;
    $n = str_replace(',', '', $clean);
    return (float)$n > 9999999.99 ? null : $n;
}

/** What the apps' equipment_detail and set_equipment_status add for the status buttons (4.37.0): an open loan, an AT workflow, the holds
 *  retiring would cancel, the moves the website's row offers, the confirmation's sentence, and whether
 *  the item is the caller's to manage ("Managed by the assigned staff"). */
function equipment_status_facts(int $eid, array $item, int $userId): array {
    $lq = db()->prepare('SELECT l.client_id AS borrower_id, c.assigned_to AS borrower_assigned FROM equipment_loans l JOIN clients c ON c.id = l.client_id WHERE l.equipment_id = ? AND l.returned_at IS NULL LIMIT 1');
    $lq->execute([$eid]);
    $loan = $lq->fetch() ?: [];
    $onLoan = $loan !== [];
    $inWorkflow = equipment_in_at_workflow($eid);
    $preview = equipment_retire_preview($eid);
    $manageable = equipment_manageable($loan, $userId);
    return ['on_loan' => $onLoan, 'in_workflow' => $inWorkflow, 'holds' => (int)($preview['holds'] ?? 0),
        'status_actions' => $manageable ? equipment_status_actions($item, $onLoan, $inWorkflow) : [],
        'retire_text' => (string)($preview['text'] ?? ''), 'manageable' => $manageable];
}
