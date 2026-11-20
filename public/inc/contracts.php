<?php
declare(strict_types=1);
// Grants and contracts (4.38.0): moved from contracts.php, so the page and the apps' contracts,
// contract, add_contract and contract_update read money, work out the percentage, say the
// sentences and audit each change the same way.

const DELIVERABLE_STATUSES = ['Pending', 'In Progress', 'Submitted', 'Accepted'];
const CONTRACT_MONEY_HINT = 'Enter a dollar amount of 0 or more, for example 50000 or 50,000.00, with a period before the cents.';
const CONTRACT_SPEND_HINT = 'Amount: enter a dollar amount above 0, for example 1250 or 1,250.00, with a period before the cents.';

function contract_load(int $id): ?array {
    $s = db()->prepare('SELECT * FROM contracts WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function contract_spent(int $id): float {
    $s = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM contract_spend WHERE contract_id = ?');
    $s->execute([$id]);
    return (float)$s->fetchColumn();
}
/** Whole percent of budget spent, rounded DOWN (99.5% must not read as an over-budget
 *  "100%" while funds remain), worked in exact cents: float division can floor an
 *  exact 29% to 28. */
function contract_pct(float $spent, float $amount): int {
    $amountCents = (int)round($amount * 100);
    return $amountCents > 0 ? intdiv((int)round($spent * 100) * 100, $amountCents) : 0;
}
/** A dollar amount typed on a form. "$50,000", "50000" and "1250.5" are read as typed
 *  (the $ sign and spaces are dropped, and commas that separate thousands); null when
 *  blank; false when it is not a plain non-negative amount with at most 2 decimals that
 *  fits DECIMAL(12,2). A comma anywhere else ("50,00", "1,2,3") is refused, never read
 *  as a thousands separator that makes the amount 100 times too large. A JSON number from
 *  the apps is read as its digits. */
function contract_money_in(mixed $raw): float|false|null {
    if (is_int($raw) || is_float($raw)) $raw = (string)$raw;
    if (!is_string($raw)) return false;
    $v = str_replace(['$', ' '], '', trim($raw));
    if ($v === '') return null;
    if (str_contains($v, ',')) {
        if (!preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $v)) return false;
        $v = str_replace(',', '', $v);
    }
    return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v) ? (float)$v : false;
}

/** A new contract's fields read from $src (the form or the app's body): name required, the budget
 *  as contract_money_in() reads it, notes up to 2,000 characters. Returns [errors, values]; values
 *  hold name, funder, contract_number and notes trimmed and the amount (0.0 when blank). */
function contract_input(array $src): array {
    $t = fn(string $k): string => is_string($src[$k] ?? null) ? trim($src[$k]) : '';
    $errs = [];
    $name = $t('name');
    if ($name === '') $errs['name'] = 'Contract name is required.';
    $budget = contract_money_in($src['amount'] ?? '');
    if ($budget === false) $errs['amount'] = 'Budget: ' . CONTRACT_MONEY_HINT;
    $notes = $t('notes');
    if (mb_strlen($notes) > 2000) $errs['notes'] = 'Notes must be 2,000 characters or fewer.';
    return [$errs, ['name' => $name, 'funder' => $t('funder'), 'contract_number' => $t('contract_number'), 'amount' => is_float($budget) ? $budget : 0.0, 'notes' => $notes]];
}

/** Calendar days from today to an end date and whether it has passed. DateTime::diff stays correct
 *  when a daylight-saving change falls in between; dividing seconds by 86400 does not. [null, false]
 *  without an end date. */
function contract_end_days(?string $end): array {
    if (!$end) return [null, false];
    $d = (new DateTime(date('Y-m-d')))->diff(new DateTime($end));
    return [(int)$d->days, (bool)$d->invert];
}

/** The detail page's end-date phrase: "Ended 3 days ago", "Ends today", "12 days to end"; null without an end date. */
function contract_days_text(?string $end): ?string {
    [$days, $ended] = contract_end_days($end);
    if ($days === null) return null;
    if ($ended) return 'Ended ' . ($days === 1 ? '1 day ago' : $days . ' days ago');
    return $days === 0 ? 'Ends today' : ($days === 1 ? '1 day to end' : $days . ' days to end');
}

/** The list's warning after an end date within 60 days: "— ended" or "— ending soon"; null otherwise. */
function contract_ends_text(?string $end): ?string {
    if (!$end || $end > date('Y-m-d', strtotime('+60 days'))) return null;
    return $end < date('Y-m-d') ? '— ended' : '— ending soon';
}

/** A deliverable's status set: nothing changes (and nothing is audited) when it already has it. */
function contract_set_deliverable(int $contractId, int $deliverableId, string $to, int $actor, string $auditTail = ''): array {
    if (!in_array($to, DELIVERABLE_STATUSES, true)) return ['ok' => false, 'changed' => false, 'status' => 422, 'message' => 'Choose a status from the list.'];
    $dq = db()->prepare('SELECT title, status FROM contract_deliverables WHERE id = ? AND contract_id = ?');
    $dq->execute([$deliverableId, $contractId]);
    $d = $dq->fetch();
    if (!$d) return ['ok' => false, 'changed' => false, 'status' => 404, 'message' => 'Deliverable not found.'];
    $changed = $d['status'] !== $to;
    if ($changed) {
        db()->prepare('UPDATE contract_deliverables SET status = ? WHERE id = ? AND contract_id = ?')->execute([$to, $deliverableId, $contractId]);
        audit($actor, 'contract.set_deliverable', 'contract', $contractId, $d['title'] . ' → ' . $to . $auditTail);
    }
    return ['ok' => true, 'changed' => $changed, 'message' => 'Deliverable updated.'];
}

/** A contract's budget changed, audited with the old and new amounts. errors on amount when unreadable or blank. */
function contract_set_budget(int $contractId, mixed $raw, int $actor, string $auditTail = ''): array {
    $c = contract_load($contractId);
    if (!$c) return ['ok' => false, 'changed' => false, 'status' => 404, 'message' => 'Contract not found.'];
    $budget = contract_money_in($raw);
    if ($budget === null || $budget === false) return ['ok' => false, 'changed' => false, 'status' => 422, 'message' => 'Budget: ' . CONTRACT_MONEY_HINT, 'errors' => ['amount' => 'Budget: ' . CONTRACT_MONEY_HINT]];
    $was = (float)$c['amount'];
    if (round($was, 2) === round($budget, 2)) return ['ok' => true, 'changed' => false, 'message' => 'The budget is already ' . fmt_money($budget) . '.'];
    db()->prepare('UPDATE contracts SET amount = ? WHERE id = ?')->execute([$budget, $contractId]);
    audit($actor, 'contract.budget', 'contract', $contractId, fmt_money($was) . ' → ' . fmt_money($budget) . $auditTail);
    return ['ok' => true, 'changed' => true, 'message' => 'Budget changed to ' . fmt_money($budget) . '.'];
}

/** A contract closed ($active false) or reopened. Asking for the state it already has changes nothing. */
function contract_set_active(int $contractId, bool $active, int $actor, string $auditTail = ''): array {
    $c = contract_load($contractId);
    if (!$c) return ['ok' => false, 'changed' => false, 'status' => 404, 'message' => 'Contract not found.'];
    $target = $active ? 'Active' : 'Closed';
    $upd = db()->prepare('UPDATE contracts SET status = ? WHERE id = ? AND status <> ?');
    $upd->execute([$target, $contractId, $target]);
    if ($upd->rowCount() === 1) {
        audit($actor, $active ? 'contract.reopen' : 'contract.close', 'contract', $contractId, $c['name'] . $auditTail);
        return ['ok' => true, 'changed' => true, 'message' => $active ? 'Contract reopened.' : 'Contract closed.'];
    }
    return ['ok' => true, 'changed' => false, 'message' => $active ? 'That contract is already active.' : 'That contract is already closed.'];
}

/** One of this contract's deliverables removed, audited with its title. */
function contract_delete_deliverable(int $contractId, int $deliverableId, int $actor, string $auditTail = ''): array {
    $row = db()->prepare('SELECT title FROM contract_deliverables WHERE id = ? AND contract_id = ?');
    $row->execute([$deliverableId, $contractId]);
    $title = $row->fetchColumn();
    $del = db()->prepare('DELETE FROM contract_deliverables WHERE id = ? AND contract_id = ?');
    $del->execute([$deliverableId, $contractId]);
    if ($del->rowCount() !== 1) return ['ok' => false, 'changed' => false, 'status' => 404, 'message' => 'Deliverable not found.'];
    audit($actor, 'contract.deliverable_delete', 'contract', $contractId, (is_string($title) ? $title : '') . $auditTail);
    return ['ok' => true, 'changed' => true, 'message' => 'Deliverable removed.'];
}

/** One of this contract's spend entries removed, audited with its amount, date and memo. */
function contract_delete_spend(int $contractId, int $spendId, int $actor, string $auditTail = ''): array {
    $row = db()->prepare('SELECT amount, spent_on, memo FROM contract_spend WHERE id = ? AND contract_id = ?');
    $row->execute([$spendId, $contractId]);
    $was = $row->fetch();
    $del = db()->prepare('DELETE FROM contract_spend WHERE id = ? AND contract_id = ?');
    $del->execute([$spendId, $contractId]);
    if ($del->rowCount() !== 1) return ['ok' => false, 'changed' => false, 'status' => 404, 'message' => 'Spend entry not found.'];
    audit($actor, 'contract.spend_delete', 'contract', $contractId, ($was ? fmt_money((float)$was['amount']) . ' on ' . $was['spent_on'] . ($was['memo'] ? ' — ' . $was['memo'] : '') : '') . $auditTail);
    return ['ok' => true, 'changed' => true, 'message' => 'Spend entry removed.'];
}
