<?php
// Grants & contracts: track each funding contract's budget, spend-down, and
// deliverables with due dates, so nothing is over-spent or missed at renewal.
// Organization-level (not caseload); gated by the 'billing' permission.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('billing');

const DELIVERABLE_STATUSES = ['Pending', 'In Progress', 'Submitted', 'Accepted'];

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
 *  as a thousands separator that makes the amount 100 times too large. */
function contract_money_in(mixed $raw): float|false|null {
    if (!is_string($raw)) return false;
    $v = str_replace(['$', ' '], '', trim($raw));
    if ($v === '') return null;
    if (str_contains($v, ',')) {
        if (!preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $v)) return false;
        $v = str_replace(',', '', $v);
    }
    return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v) ? (float)$v : false;
}
const CONTRACT_MONEY_HINT = 'Enter a dollar amount of 0 or more, for example 50000 or 50,000.00, with a period before the cents.';
/** True when a date field was typed but cannot be read: a value that is not a real
 *  date, one with a year outside 2000 to $yearsAhead years from now (the app's range,
 *  api.php api_date: 5 years, or 25 for a contract end or deliverable due date), or one
 *  the browser flagged as only partly filled in (a partly filled date input submits as
 *  blank, so the page's script names it in date_bad[]). Such a date is refused with the
 *  field named — never dropped or replaced with today. */
function contract_date_bad(string $field, int $yearsAhead = 5): bool {
    $flagged = $_POST['date_bad'] ?? [];
    if (is_array($flagged) && in_array($field, $flagged, true)) return true;
    $raw = $_POST[$field] ?? '';
    return !is_string($raw) || (trim($raw) !== '' && valid_date($raw, 2000, (int)date('Y') + $yearsAhead) === null);
}
function contract_date_hint(int $yearsAhead = 5): string {
    return 'enter the full date (month, day and year) with a year from 2000 to ' . ((int)date('Y') + $yearsAhead) . ', or leave it blank.';
}
/** Marks partly filled date fields on submit so the server can refuse them (see contract_date_bad). */
function contract_date_script(): string {
    return '<script>document.addEventListener("submit",function(e){var f=e.target;if(!f||!f.querySelectorAll)return;'
        . 'f.querySelectorAll("input[name=\'date_bad[]\']").forEach(function(x){x.remove();});'
        . 'f.querySelectorAll("input[type=date]").forEach(function(i){if(i.validity&&i.validity.badInput){var b=document.createElement("input");b.type="hidden";b.name="date_bad[]";b.value=i.name;f.appendChild(b);}});});</script>';
}

/* ---- Create contract ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_contract'])) {
    $name = trim($_POST['name'] ?? '');
    $startDate = valid_date($_POST['start_date'] ?? '');
    $endDate = valid_date($_POST['end_date'] ?? '');
    // A budget the page cannot read is refused with the field named, never stored as $0.00.
    $budget = contract_money_in($_POST['amount'] ?? '');
    $errs = [];
    if ($name === '') $errs['name'] = 'Contract name is required.';
    if ($budget === false) $errs['amount'] = 'Budget: ' . CONTRACT_MONEY_HINT;
    if (contract_date_bad('start_date')) $errs['start_date'] = 'Start date: ' . contract_date_hint();
    if (contract_date_bad('end_date', 25)) $errs['end_date'] = 'End date: ' . contract_date_hint(25);
    // Same rule as the app (api.php add_contract), once both dates are in range.
    if (!isset($errs['start_date']) && !isset($errs['end_date']) && $startDate !== null && $endDate !== null && $endDate < $startDate) $errs['end_date'] = 'The end date cannot be before the start date.';
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('contracts.php'); }
    db()->prepare('INSERT INTO contracts (name, funder, contract_number, amount, start_date, end_date, notes) VALUES (?,?,?,?,?,?,?)')
        ->execute([mb_substr($name, 0, 190), mb_substr(trim($_POST['funder'] ?? ''), 0, 190) ?: null,
            mb_substr(trim($_POST['contract_number'] ?? ''), 0, 120) ?: null, $budget ?? 0.0,
            $startDate, $endDate,
            mb_substr(trim($_POST['notes'] ?? ''), 0, 2000) ?: null]);
    $id = (int)db()->lastInsertId();
    audit($user['id'], 'contract.add', 'contract', $id, $name);
    flash('Contract created.');
    redirect('contracts.php?id=' . $id);
}

/* ---- Contract-scoped actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contract_id'])) {
    $cid = (int)$_POST['contract_id'];
    $c = contract_load($cid);
    if (!$c) { flash('Contract not found.', 'error'); redirect('contracts.php'); }
    $back = 'contracts.php?id=' . $cid;

    if (isset($_POST['add_deliverable'])) {
        $back .= '#del-h';
        $title = trim($_POST['title'] ?? '');
        // A blank (or spaces-only) title passes the browser's "required" check, so say so here.
        $dErrs = [];
        if ($title === '') $dErrs['title'] = 'Enter a deliverable title.';
        if (contract_date_bad('due_date', 25)) $dErrs['due_date'] = 'Due date: ' . contract_date_hint(25);
        if ($dErrs) { keep_input(); keep_field_errors($dErrs); redirect($back); }
        $title = mb_substr($title, 0, 255);
        db()->prepare('INSERT INTO contract_deliverables (contract_id, title, due_date, notes) VALUES (?,?,?,?)')
            ->execute([$cid, $title, valid_date($_POST['due_date'] ?? ''), mb_substr(trim($_POST['d_notes'] ?? ''), 0, 500) ?: null]);
        audit($user['id'], 'contract.add_deliverable', 'contract', $cid, $title);
        flash('Deliverable added.');
    } elseif (isset($_POST['set_deliverable'])) {
        $back .= '#del-h';
        $did = (int)($_POST['deliverable_id'] ?? 0);
        // An unrecognised status is refused (as in the app) — never quietly reset to Pending.
        $to = $_POST['status'] ?? '';
        if (!is_string($to) || !in_array($to, DELIVERABLE_STATUSES, true)) { flash('Choose a status from the list.', 'error'); redirect($back); }
        $dq = db()->prepare('SELECT title, status FROM contract_deliverables WHERE id = ? AND contract_id = ?');
        $dq->execute([$did, $cid]);
        $d = $dq->fetch();
        if (!$d) { flash('Deliverable not found.', 'error'); redirect($back); }
        if ($d['status'] !== $to) {
            db()->prepare('UPDATE contract_deliverables SET status = ? WHERE id = ? AND contract_id = ?')->execute([$to, $did, $cid]);
            audit($user['id'], 'contract.set_deliverable', 'contract', $cid, $d['title'] . ' → ' . $to);
        }
        flash('Deliverable updated.');
    } elseif (isset($_POST['del_deliverable'])) {
        $delivId = (int)($_POST['deliverable_id'] ?? 0);
        $delivRow = db()->prepare('SELECT title FROM contract_deliverables WHERE id = ? AND contract_id = ?');
        $delivRow->execute([$delivId, $cid]);
        $delivTitle = $delivRow->fetchColumn();
        $delivDel = db()->prepare('DELETE FROM contract_deliverables WHERE id = ? AND contract_id = ?');
        $delivDel->execute([$delivId, $cid]);
        if ($delivDel->rowCount() === 1) audit($user['id'], 'contract.deliverable_delete', 'contract', $cid, is_string($delivTitle) ? $delivTitle : null);
        flash('Deliverable removed.');
    } elseif (isset($_POST['set_budget'])) {
        $budget = contract_money_in($_POST['budget'] ?? '');
        if ($budget === null || $budget === false) { keep_input(); keep_field_errors(['budget' => 'Budget: ' . CONTRACT_MONEY_HINT]); redirect($back); }
        $was = (float)$c['amount'];
        if (round($was, 2) === round($budget, 2)) {
            flash('The budget is already ' . fmt_money($budget) . '.');
        } else {
            db()->prepare('UPDATE contracts SET amount = ? WHERE id = ?')->execute([$budget, $cid]);
            audit($user['id'], 'contract.budget', 'contract', $cid, fmt_money($was) . ' → ' . fmt_money($budget));
            flash('Budget changed to ' . fmt_money($budget) . '.');
        }
    } elseif (isset($_POST['add_spend'])) {
        // As in the app, a spend entry must be a positive amount; it is never stored as $0.00.
        $spendAmount = contract_money_in($_POST['amount'] ?? '');
        $sErrs = [];
        if (!is_float($spendAmount) || $spendAmount <= 0) $sErrs['spend_amount'] = 'Amount: enter a dollar amount above 0, for example 1250 or 1,250.00, with a period before the cents.';
        if (contract_date_bad('spent_on')) $sErrs['spent_on'] = 'Date: ' . contract_date_hint();
        if ($sErrs) { keep_input(); keep_field_errors($sErrs); redirect($back); }
        // A date left blank on purpose is today's; the confirmation names the date used.
        $spentOn = valid_date($_POST['spent_on'] ?? '') ?? date('Y-m-d');
        db()->prepare('INSERT INTO contract_spend (contract_id, spent_on, amount, memo, created_by) VALUES (?,?,?,?,?)')
            ->execute([$cid, $spentOn, $spendAmount,
                mb_substr(trim($_POST['memo'] ?? ''), 0, 255) ?: null, $user['id']]);
        audit($user['id'], 'contract.spend', 'contract', $cid, fmt_money($spendAmount));
        flash('Spend of ' . fmt_money($spendAmount) . ' recorded for ' . fmt_date($spentOn) . '.');
    } elseif (isset($_POST['del_spend'])) {
        $spendId = (int)($_POST['spend_id'] ?? 0);
        $spendRow = db()->prepare('SELECT amount, spent_on, memo FROM contract_spend WHERE id = ? AND contract_id = ?');
        $spendRow->execute([$spendId, $cid]);
        $spendWas = $spendRow->fetch();
        $spendDel = db()->prepare('DELETE FROM contract_spend WHERE id = ? AND contract_id = ?');
        $spendDel->execute([$spendId, $cid]);
        if ($spendDel->rowCount() === 1) {
            audit($user['id'], 'contract.spend_delete', 'contract', $cid, $spendWas
                ? fmt_money((float)$spendWas['amount']) . ' on ' . $spendWas['spent_on'] . ($spendWas['memo'] ? ' — ' . $spendWas['memo'] : '')
                : null);
        }
        flash('Spend entry removed.');
    } elseif (isset($_POST['toggle_contract'])) {
        // The form posts the state its button offers, so a repeated or stale submit
        // cannot flip the contract back to the state the user just left.
        $target = $_POST['to_status'] ?? '';
        if (!is_string($target) || !in_array($target, ['Active', 'Closed'], true)) { flash('The contract was not changed. Reload the page and try again.', 'error'); redirect($back); }
        $upd = db()->prepare('UPDATE contracts SET status = ? WHERE id = ? AND status <> ?');
        $upd->execute([$target, $cid, $target]);
        if ($upd->rowCount() === 1) {
            audit($user['id'], $target === 'Closed' ? 'contract.close' : 'contract.reopen', 'contract', $cid, $c['name']);
            flash($target === 'Closed' ? 'Contract closed.' : 'Contract reopened.');
        } else {
            flash($target === 'Closed' ? 'That contract is already closed.' : 'That contract is already active.');
        }
        redirect('contracts.php');
    }
    redirect($back);
}

/* ================= Detail ================= */
if (isset($_GET['id'])) {
    $c = contract_load((int)$_GET['id']);
    if (!$c) { flash('Contract not found.', 'error'); redirect('contracts.php'); }
    $cid = (int)$c['id'];
    $spent = contract_spent($cid);
    $remaining = (float)$c['amount'] - $spent;
    $pct = contract_pct($spent, (float)$c['amount']);
    $deliverables = db()->prepare('SELECT * FROM contract_deliverables WHERE contract_id = ? ORDER BY due_date IS NULL, due_date, id');
    $deliverables->execute([$cid]);
    $deliverables = $deliverables->fetchAll();
    // Every entry is listed (they are small rows): the Spent and Remaining figures sum
    // them all, and an entry can only be removed from this list.
    $spend = db()->prepare('SELECT s.*, u.name AS by_name FROM contract_spend s LEFT JOIN users u ON u.id = s.created_by WHERE s.contract_id = ? ORDER BY s.spent_on DESC, s.id DESC');
    $spend->execute([$cid]);
    $spend = $spend->fetchAll();
    $today = date('Y-m-d');
    // Calendar days to the end date. DateTime::diff stays correct when a
    // daylight-saving change falls in between; dividing seconds by 86400 does not.
    // A past end date is said as "Ended N days ago" (the list says "— ended"), never
    // as 0 days to end.
    $daysToEnd = null;
    $ended = false;
    if ($c['end_date']) {
        $endDiff = (new DateTime($today))->diff(new DateTime((string)$c['end_date']));
        $ended = (bool)$endDiff->invert;
        $daysToEnd = (int)$endDiff->days;
    }
    page_header('Grant or contract: ' . $c['name'], $user, 'contracts', [['contracts.php', 'Grants & contracts']]);
    ?>
    <p><a href="contracts.php"><span aria-hidden="true">&larr; </span>All contracts</a></p>
    <h1>Grant or contract: <?= h($c['name']) ?> <?= $c['status'] === 'Active' ? '' : '<span class="badge badge-hold">Closed</span>' ?></h1>
    <p class="muted"><?= $c['funder'] ? h($c['funder']) . ' · ' : '' ?><?= $c['contract_number'] ? '#' . h($c['contract_number']) . ' · ' : '' ?>
      <?= $c['start_date'] ? fmt_date($c['start_date']) : '' ?><?= $c['end_date'] ? ' – ' . fmt_date($c['end_date']) : '' ?></p>
    <div class="page-actions">
      <form method="post" action="contracts.php" class="inline-form"><?= csrf_field() ?>
        <input type="hidden" name="contract_id" value="<?= $cid ?>">
        <input type="hidden" name="to_status" value="<?= $c['status'] === 'Active' ? 'Closed' : 'Active' ?>">
        <button type="submit" name="toggle_contract" value="1" class="btn btn-secondary btn-sm"><?= $c['status'] === 'Active' ? 'Close contract' : 'Reopen' ?></button>
      </form>
    </div>

    <div class="grid grid-4">
      <div class="card stat"><span class="stat-num"><?= fmt_money((float)$c['amount']) ?></span><span class="stat-label">Budget</span></div>
      <?php $noBudget = (float)$c['amount'] <= 0; // No budget: a percentage of $0 would read as 0% spent (as on the list). ?>
      <div class="card stat"><span class="stat-num"><?= fmt_money($spent) ?></span><span class="stat-label">Spent (<?= $noBudget ? 'no budget set' : $pct . '%' ?>)</span></div>
      <div class="card stat"><span class="stat-num<?= $remaining < 0 ? ' field-error' : '' ?>"><?= fmt_money($remaining) ?></span><span class="stat-label">Remaining<?= $remaining < 0 ? ' (over budget)' : '' ?></span></div>
      <?php if ($ended): ?>
      <div class="card stat"><span class="stat-num">Ended</span><span class="stat-label"><?= $daysToEnd === 1 ? '1 day ago' : $daysToEnd . ' days ago' ?></span></div>
      <?php else: ?>
      <div class="card stat"><span class="stat-num"><?= $daysToEnd === null ? '<span aria-hidden="true">—</span><span class="visually-hidden">No end date set</span>' : $daysToEnd ?></span><span class="stat-label">Days to end<?= $daysToEnd === 0 ? ' (ends today)' : '' ?></span></div>
      <?php endif; ?>
    </div>
    <div class="progress-wrap card">
      <?php if ($noBudget): ?>
      <p class="progress-caption"><strong>Spend-down:</strong> <?= fmt_money($spent) ?> spent (no budget set)</p>
      <?php else: ?>
      <label for="spend-bar" class="progress-caption"><strong>Spend-down:</strong> <?= fmt_money($spent) ?> of <?= fmt_money((float)$c['amount']) ?> (<?= $pct ?>%)</label>
      <progress id="spend-bar" class="hours" max="100" value="<?= min(100, $pct) ?>" aria-valuetext="<?= $pct ?>% of budget spent"><?= $pct ?>%</progress>
      <?php endif; ?>
    </div>
    <?php // The budget can be corrected here; every change is audited with the old and new amounts. ?>
    <section class="card" aria-labelledby="budget-h">
    <h2 id="budget-h">Budget</h2>
    <form method="post" action="contracts.php" class="stacked" novalidate><?= csrf_field() ?>
      <?= form_error_summary(['budget' => '']) ?>
      <input type="hidden" name="contract_id" value="<?= $cid ?>">
      <div class="field"><label for="budget">Budget (USD)</label><input type="text" inputmode="decimal" id="budget" name="budget" maxlength="20" required
        value="<?= old_in('set_budget') !== '' ? h(old_in('budget')) : number_format((float)$c['amount'], 2, '.', '') ?>" <?= field_aria('budget') ?>><?= field_error('budget') ?></div>
      <button type="submit" name="set_budget" value="1" class="btn btn-secondary">Save budget</button>
    </form>
    </section>

    <section class="card" aria-labelledby="del-h">
      <h2 id="del-h">Deliverables (<?= count($deliverables) ?>)</h2>
      <?php if ($deliverables): ?>
      <div class="table-scroll" tabindex="0" role="region" aria-labelledby="del-cap">
      <table>
        <caption class="visually-hidden" id="del-cap">Contract deliverables with due date and status</caption>
        <thead><tr><th scope="col">Deliverable</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($deliverables as $d): $overdue = $d['due_date'] && $d['due_date'] < $today && $d['status'] !== 'Accepted'; ?>
          <tr>
            <th scope="row"><?= h($d['title']) ?><?= $d['notes'] ? '<br><span class="muted">' . h($d['notes']) . '</span>' : '' ?></th>
            <td><?= $d['due_date'] ? ($overdue ? '<strong class="field-error">' . fmt_date($d['due_date']) . ' — overdue</strong>' : fmt_date($d['due_date'])) : no_date_mark(true) ?></td>
            <td>
              <form method="post" action="contracts.php" class="inline-form"><?= csrf_field() ?>
                <input type="hidden" name="contract_id" value="<?= $cid ?>"><input type="hidden" name="deliverable_id" value="<?= (int)$d['id'] ?>">
                <label class="visually-hidden" for="ds-<?= (int)$d['id'] ?>">Status of <?= h($d['title']) ?></label>
                <select id="ds-<?= (int)$d['id'] ?>" name="status"><?= select_options(DELIVERABLE_STATUSES, $d['status']) ?></select>
                <button type="submit" name="set_deliverable" value="1" class="btn btn-secondary btn-sm">Save status<span class="visually-hidden"> for <?= h($d['title']) ?></span></button>
              </form>
            </td>
            <td><form method="post" action="contracts.php" class="inline-form"><?= csrf_field() ?>
              <input type="hidden" name="contract_id" value="<?= $cid ?>"><input type="hidden" name="deliverable_id" value="<?= (int)$d['id'] ?>">
              <button type="submit" name="del_deliverable" value="1" class="btn-link" onclick="return confirm('Remove this deliverable?');">remove<span class="visually-hidden"> <?= h($d['title']) ?></span></button>
            </form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?><p class="muted">No deliverables yet.</p><?php endif; ?>
      <?php // After a rejected submission the form comes back filled in, with the error on its field.
      $oldD = fn(string $k): string => old_in('add_deliverable') !== '' ? h(old_in($k)) : ''; ?>
      <form method="post" action="contracts.php" class="stacked" style="margin-top:1rem" novalidate><?= csrf_field() ?>
        <?= form_error_summary(['title' => '', 'due_date' => '']) ?>
        <input type="hidden" name="contract_id" value="<?= $cid ?>">
        <div class="grid grid-4">
          <div class="field"><label for="title">Deliverable *</label><input type="text" id="title" name="title" required maxlength="255" value="<?= $oldD('title') ?>" <?= field_aria('title') ?>><?= field_error('title') ?></div>
          <div class="field"><label for="due_date">Due date</label><input type="date" id="due_date" name="due_date" value="<?= $oldD('due_date') ?>" <?= field_aria('due_date') ?>><?= field_error('due_date') ?></div>
          <div class="field"><label for="d_notes">Notes</label><input type="text" id="d_notes" name="d_notes" maxlength="500" value="<?= $oldD('d_notes') ?>"></div>
        </div>
        <button type="submit" name="add_deliverable" value="1" class="btn btn-secondary">Add deliverable</button>
      </form>
    </section>

    <section class="card" aria-labelledby="spend-h">
      <h2 id="spend-h">Spend entries (<?= count($spend) ?>)</h2>
      <?php if ($spend): ?>
      <ul class="timeline">
        <?php foreach ($spend as $s): ?>
        <li><strong><?= fmt_money((float)$s['amount']) ?></strong> · <?= fmt_date($s['spent_on']) ?>
          <?= $s['memo'] ? ' · ' . h($s['memo']) : '' ?> <span class="muted"><?= $s['by_name'] ? 'by ' . h($s['by_name']) : '' ?></span>
          <form method="post" action="contracts.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="contract_id" value="<?= $cid ?>"><input type="hidden" name="spend_id" value="<?= (int)$s['id'] ?>">
            <button type="submit" name="del_spend" value="1" class="btn-link" onclick="return confirm('Remove this spend entry?');">remove<span class="visually-hidden"> the <?= fmt_money((float)$s['amount']) ?> entry of <?= fmt_date($s['spent_on']) ?></span></button>
          </form></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><p class="muted">No spend recorded yet.</p><?php endif; ?>
      <?php $oldS = fn(string $k, string $default = ''): string => old_in('add_spend') !== '' ? h(old_in($k)) : $default; ?>
      <form method="post" action="contracts.php" class="stacked" style="margin-top:.5rem" novalidate><?= csrf_field() ?>
        <?= form_error_summary(['spend_amount' => '', 'spent_on' => ''], ['spend_amount' => 'amount_s']) ?>
        <input type="hidden" name="contract_id" value="<?= $cid ?>">
        <div class="grid grid-4">
          <div class="field"><label for="amount_s">Amount *</label><input type="text" inputmode="decimal" id="amount_s" name="amount" maxlength="20" required value="<?= $oldS('amount') ?>" <?= field_aria('spend_amount') ?>><?= field_error('spend_amount') ?></div>
          <div class="field"><label for="spent_on">Date</label><input type="date" id="spent_on" name="spent_on" value="<?= $oldS('spent_on', date('Y-m-d')) ?>" <?= field_aria('spent_on') ?>><?= field_error('spent_on') ?></div>
          <div class="field"><label for="memo">Memo</label><input type="text" id="memo" name="memo" maxlength="255" value="<?= $oldS('memo') ?>"></div>
        </div>
        <button type="submit" name="add_spend" value="1" class="btn btn-secondary">Record spend</button>
      </form>
    </section>
    <?php if ($c['notes']): ?><section class="card"><h2>Notes</h2><p style="white-space:pre-wrap"><?= h($c['notes']) ?></p></section><?php endif; ?>
    <?= contract_date_script() ?>
    <?php page_footer(); exit;
}

/* ================= List ================= */
$showClosed = !empty($_GET['closed']);
$contracts = db()->query("SELECT c.*,
        (SELECT COALESCE(SUM(amount),0) FROM contract_spend s WHERE s.contract_id = c.id) AS spent,
        (SELECT COUNT(*) FROM contract_deliverables d WHERE d.contract_id = c.id AND d.status <> 'Accepted') AS open_deliv
    FROM contracts c WHERE c.status = '" . ($showClosed ? 'Closed' : 'Active') . "' ORDER BY c.end_date IS NULL, c.end_date, c.name")->fetchAll();
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+60 days'));

page_header($showClosed ? 'Closed contracts' : 'Contracts', $user, 'contracts');
?>
<h1>Grants &amp; contracts<?= $showClosed ? ' — closed' : '' ?></h1>
<p class="muted">Track each funding contract's budget, spend-down, and deliverables so nothing is over-spent or missed at renewal.</p>

<h2 id="list-h"><?= $showClosed ? 'Closed contracts' : 'Active contracts' ?></h2>
<div class="page-actions">
  <a class="btn btn-secondary btn-sm" href="contracts.php<?= $showClosed ? '' : '?closed=1' ?>"><?= $showClosed ? 'Show active' : 'Show closed' ?></a>
</div>

<div class="table-scroll" tabindex="0" role="region" aria-labelledby="list-h">
<table>
  <caption class="visually-hidden"><?= $showClosed ? 'Closed grants and contracts' : 'Active grants and contracts' ?></caption>
  <thead><tr><th scope="col">Contract</th><th scope="col">Funder</th><th scope="col" class="num">Budget</th><th scope="col" class="num">Spent</th><th scope="col">Ends</th><th scope="col" class="num">Open deliverables</th></tr></thead>
  <tbody>
  <?php if (!$contracts): ?><tr><td colspan="6" class="muted">No <?= $showClosed ? 'closed' : 'active' ?> contracts.</td></tr><?php endif; ?>
  <?php foreach ($contracts as $c): $pct = contract_pct((float)$c['spent'], (float)$c['amount']); $endingSoon = $c['end_date'] && $c['end_date'] <= $soon; ?>
    <tr>
      <th scope="row"><a href="contracts.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></th>
      <td><?= ($c['funder'] ?? '') !== '' ? h($c['funder']) : no_date_mark(true) ?></td>
      <td class="num"><?= fmt_money((float)$c['amount']) ?></td>
      <?php // What the red emphasis means is also said in words. ?>
      <?php if ((float)$c['amount'] <= 0): // No budget: a percentage of $0 would read as 0% spent. ?>
      <td class="num"><?= fmt_money((float)$c['spent']) ?> <span class="muted">(no budget set)</span></td>
      <?php else: ?>
      <td class="num"><?= fmt_money((float)$c['spent']) ?> <strong<?= $pct >= 100 ? ' class="field-error"' : '' ?>>(<?= $pct ?>%)<?= $pct >= 100 ? ((float)$c['spent'] > (float)$c['amount'] ? ' over budget' : ' fully spent') : '' ?></strong></td>
      <?php endif; ?>
      <td><?= $c['end_date'] ? ($endingSoon ? '<strong class="field-error">' . fmt_date($c['end_date']) . ($c['end_date'] < $today ? ' — ended' : ' — ending soon') . '</strong>' : fmt_date($c['end_date'])) : no_date_mark(true) ?></td>
      <td class="num"><?= (int)$c['open_deliv'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php // The list comes first; the new-contract form opens itself after a refused submit. ?>
<h2 class="visually-hidden">New contract or grant</h2>
<details class="card mt-1"<?= array_intersect_key(form_errors(), array_flip(['name', 'amount', 'start_date', 'end_date'])) !== [] ? ' open' : '' ?>>
  <summary accesskey="n" id="new-h">New contract or grant</summary>
  <?php // After a rejected submission the form comes back filled in, with the error on its field.
  $old = fn(string $k): string => old_in('add_contract') !== '' ? h(old_in($k)) : ''; ?>
  <form method="post" action="contracts.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['name' => '', 'amount' => '', 'start_date' => '', 'end_date' => '']) ?>
    <div class="grid grid-4">
      <div class="field"><label for="name">Name *</label><input type="text" id="name" name="name" required maxlength="190" value="<?= $old('name') ?>" <?= field_aria('name') ?>><?= field_error('name') ?></div>
      <div class="field"><label for="funder">Funder</label><input type="text" id="funder" name="funder" maxlength="190" value="<?= $old('funder') ?>"></div>
      <div class="field"><label for="contract_number">Contract #</label><input type="text" id="contract_number" name="contract_number" maxlength="120" value="<?= $old('contract_number') ?>"></div>
      <div class="field"><label for="amount">Budget (USD)</label><input type="text" inputmode="decimal" id="amount" name="amount" maxlength="20" value="<?= $old('amount') ?>" <?= field_aria('amount') ?>><?= field_error('amount') ?></div>
    </div>
    <div class="grid grid-4">
      <div class="field"><label for="start_date">Start</label><input type="date" id="start_date" name="start_date" value="<?= $old('start_date') ?>" <?= field_aria('start_date') ?>><?= field_error('start_date') ?></div>
      <div class="field"><label for="end_date">End</label><input type="date" id="end_date" name="end_date" value="<?= $old('end_date') ?>" <?= field_aria('end_date') ?>><?= field_error('end_date') ?></div>
    </div>
    <button type="submit" name="add_contract" value="1" class="btn btn-primary">Create contract</button>
  </form>
</details>
<?= contract_date_script() ?>
<?php page_footer();
