<?php
// Device funding & procurement pipeline: a client's device need moves from
// Requested → Quoting → Awaiting authorization → Authorized → Ordered →
// Delivered → Trained (or Denied/Cancelled). Comparison quotes, the VR funding
// authorization, PO/delivery/warranty, and a printable justification letter all
// live on one record. Caseload-scoped like the rest of the case work.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('procurement');

const DR_STATUSES = ['Requested', 'Quoting', 'Awaiting Authorization', 'Authorized', 'Ordered', 'Delivered', 'Trained', 'Denied', 'Cancelled'];
// DR_CATEGORIES is shared with the app's API, so it lives in inc/access.php.
// The open lifecycle, in order. Denied/Cancelled close a request from any open step.
const DR_LIFECYCLE = ['Requested', 'Quoting', 'Awaiting Authorization', 'Authorized', 'Ordered', 'Delivered', 'Trained'];

/** SQL for a lifecycle step's status: it moves the request forward to $to and never
 *  back, so re-saving an earlier step (to fix a PO number, say) keeps the later
 *  status, and a request closed meanwhile keeps its status. Built from constants. */
function dr_forward_sql(string $to): string {
    $earlier = array_slice(DR_LIFECYCLE, 0, (int)array_search($to, DR_LIFECYCLE, true));
    return "IF(status IN ('" . implode("','", $earlier) . "'), '" . $to . "', status)";
}

function dr_badge(string $status): string {
    $map = ['Requested' => 'badge-pending', 'Quoting' => 'badge-pending', 'Awaiting Authorization' => 'badge-pending',
        'Authorized' => 'badge-active', 'Ordered' => 'badge-active', 'Delivered' => 'badge-active',
        'Trained' => 'badge-closed', 'Denied' => 'badge-hold', 'Cancelled' => 'badge-hold'];
    return '<span class="badge ' . ($map[$status] ?? 'badge-pending') . '">' . h($status) . '</span>';
}

/** A lifecycle date as posted: [date or null, error or null]. Blank gives no date (the
 *  caller keeps the one recorded). Anything else must be a whole, real date with a year
 *  from 2000 on and not after today (a warranty may run up to 25 years ahead), so an
 *  unreadable, partly typed (flagged in date_bad[] by dr_date_script()) or mistyped date
 *  is refused on its field — never dropped or replaced with today. */
function dr_date(string $field, string $label, bool $ahead = false): array {
    $flagged = $_POST['date_bad'] ?? [];
    $raw = $_POST[$field] ?? '';
    $max = (int)date('Y') + ($ahead ? 25 : 0);
    $bad = $label . ': enter the full date (month, day and year) with a year from 2000 to ' . $max . ($ahead ? ', or leave it blank.' : '.');
    if ((is_array($flagged) && in_array($field, $flagged, true)) || !is_string($raw)) return [null, $bad];
    if (trim($raw) === '') return [null, null];
    $d = valid_date($raw, 2000, $max);
    if ($d === null) return [null, $bad];
    if (!$ahead && $d > date('Y-m-d')) return [null, $label . ' cannot be in the future.'];
    return [$d, null];
}
/** Marks partly filled date fields on submit so the server can refuse them (see dr_date). */
function dr_date_script(): string {
    return '<script>document.addEventListener("submit",function(e){var f=e.target;if(!f||!f.querySelectorAll)return;'
        . 'f.querySelectorAll("input[name=\'date_bad[]\']").forEach(function(x){x.remove();});'
        . 'f.querySelectorAll("input[type=date]").forEach(function(i){if(i.validity&&i.validity.badInput){var b=document.createElement("input");b.type="hidden";b.name="date_bad[]";b.value=i.name;f.appendChild(b);}});});</script>';
}

/** A posted text field, trimmed. Anything that is not one value (an array from a
 *  crafted post such as serial[]=x) reads as blank, so it is refused or left empty on
 *  its field instead of throwing a TypeError. */
function dr_text(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : '';
}

/** Load one request scoped to the user's caseload, or null. */
function dr_load(int $id, array $user): ?array {
    $s = db()->prepare('SELECT dr.*, c.name AS client_name, c.assigned_to, u.name AS requester
        FROM device_requests dr JOIN clients c ON c.id = dr.client_id
        LEFT JOIN users u ON u.id = dr.requested_by WHERE dr.id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return null;
    if (caseload_scoped() && (int)$r['assigned_to'] !== (int)$user['id']) return null;
    return $r;
}

/* ---- Create a request ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_request'])) {
    $cid = (int)($_POST['client_id'] ?? 0);
    $name = dr_text('device_name');
    // Browsers send each line break as CRLF; count it once, as the textarea's maxlength does.
    $justification = trim(str_replace("\r\n", "\n", (string)($_POST['justification'] ?? '')));
    // Over-long text is refused, not cut: a scripted or app post must not lose part of the justification.
    $reqErrors = array_filter([
        'client_id' => $cid <= 0 ? 'Choose a client.' : (!client_still_on_file($cid) ? 'That client is no longer on file (merged or removed). Choose the client again.' : null),
        'device_name' => $name === '' ? 'Name the device or technology.' : (mb_strlen($name) > 190 ? 'Device / technology: use 190 characters or fewer (you entered ' . mb_strlen($name) . ').' : null),
        'justification' => mb_strlen($justification) > 4000 ? 'Justification of need: use 4,000 characters or fewer (you entered ' . number_format(mb_strlen($justification)) . ').' : null,
    ]);
    if ($reqErrors) {
        // The form comes back filled in, with each problem shown on its own field.
        keep_input();
        keep_field_errors($reqErrors);
        redirect('procurement.php');
    }
    scope_check_client_id($cid, $user);
    // The same form sent twice (Enter pressed again while it loads) creates the
    // request once; the second answer goes to the request the first created.
    $reqKey = web_idem_key($_POST);
    if (($firstId = web_once_get($reqKey)) !== null) {
        flash('This device request was already created (the form was sent twice), so it was saved once.', 'info');
        redirect('procurement.php?id=' . $firstId);
    }
    db()->prepare('INSERT INTO device_requests (client_id, requested_by, device_name, category, justification, status) VALUES (?,?,?,?,?,?)')
        ->execute([$cid, $user['id'], $name,
            in_array($_POST['category'] ?? '', cms_list_items('device_request_categories'), true) ? $_POST['category'] : 'Other',
            $justification !== '' ? $justification : null, 'Requested']);
    $rid = (int)db()->lastInsertId();
    web_once_set($reqKey, $rid);
    audit($user['id'], 'device.request', 'device_request', $rid, $name);
    flash('Device request created.');
    redirect('procurement.php?id=' . $rid);
}

/* ---- Actions on one request ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rid'])) {
    $rid = (int)$_POST['rid'];
    $r = dr_load($rid, $user);
    if (!$r) { flash('Request not found.', 'error'); redirect('procurement.php'); }
    $back = 'procurement.php?id=' . $rid;

    // The page offers the lifecycle forms only while a request is open, and
    // "Reopen" only on a denied or cancelled one. Enforce the same rules here so
    // a direct or replayed POST cannot revive or rewrite a closed request.
    $closed = in_array($r['status'], ['Trained', 'Denied', 'Cancelled'], true);
    // A reopen is only ever the reopen itself: a POST that also carries another
    // action is not treated as one, so it cannot slip that action past the closed check.
    $isReopen = isset($_POST['set_state']) && ($_POST['to'] ?? '') === 'Requested'
        && !array_intersect_key($_POST, array_flip(['add_quote', 'select_quote', 'authorize', 'record_order', 'record_delivery', 'record_training']));
    if ($isReopen && !in_array($r['status'], ['Denied', 'Cancelled'], true)) {
        flash('Only a denied or cancelled request can be reopened.', 'error');
        redirect($back);
    }
    if (!$isReopen && $closed) {
        flash('This request is closed (' . $r['status'] . ')' . ($r['status'] === 'Trained' ? ' and can no longer be changed.' : '. Reopen it before making changes.'), 'error');
        redirect($back);
    }

    if (isset($_POST['add_quote'])) {
        $vendor = dr_text('vendor');
        // A quote needs its price: a blank amount would be stored as $0.00 and then
        // read as the cheapest quote and as the letter's estimated cost. The amount
        // must also fit the column (decimal 10,2).
        $amountRaw = str_replace([',', '$'], '', trim(is_string($_POST['amount'] ?? null) ? $_POST['amount'] : ''));
        $quoteErrors = [];
        if ($vendor === '') $quoteErrors['vendor'] = 'Vendor is required for a quote.';
        if ($amountRaw === '') $quoteErrors['amount'] = 'Amount is required: enter the quoted price, for example 2995.00.';
        elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $amountRaw)) $quoteErrors['amount'] = 'Amount: enter the quoted price in dollars, 0 or more with up to two decimal places, for example 2995.00.';
        if ($quoteErrors) { keep_input(); keep_field_errors($quoteErrors); redirect($back); }
        // The same quote sent twice is added once.
        $quoteKey = web_idem_key($_POST);
        if (web_once_get($quoteKey) !== null) {
            flash('This quote was already added (the form was sent twice), so it was saved once.', 'info');
            redirect($back);
        }
        db()->prepare('INSERT INTO device_request_quotes (request_id, vendor, amount, quote_ref, notes, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$rid, mb_substr($vendor, 0, 190), (float)$amountRaw,
                mb_substr(dr_text('quote_ref'), 0, 120) ?: null,
                mb_substr(dr_text('quote_notes'), 0, 500) ?: null, $user['id']]);
        web_once_set($quoteKey, (int)db()->lastInsertId());
        if (in_array($r['status'], ['Requested'], true)) db()->prepare("UPDATE device_requests SET status = 'Quoting' WHERE id = ?")->execute([$rid]);
        audit($user['id'], 'device.quote', 'device_request', $rid, $vendor);
        flash('Quote added.');
    } elseif (isset($_POST['select_quote'])) {
        $qid = (int)($_POST['quote_id'] ?? 0);
        $q = db()->prepare('SELECT id FROM device_request_quotes WHERE id = ? AND request_id = ?');
        $q->execute([$qid, $rid]);
        if ($q->fetchColumn()) {
            db()->prepare("UPDATE device_requests SET selected_quote_id = ?, status = " . dr_forward_sql('Awaiting Authorization') . " WHERE id = ?")
                ->execute([$qid, $rid]);
            audit($user['id'], 'device.select_quote', 'device_request', $rid, 'Quote #' . $qid);
            flash('Quote selected as the recommended option.');
        }
    } elseif (isset($_POST['authorize'])) {
        // Blank means no amount; anything else must be a plain amount that fits the
        // column (decimal 10,2), refused on its field rather than read as $0.00.
        // A text box (4.29.0): a number box sent "3,000" as blank, which erased the stored amount.
        // Commas and a dollar sign are allowed and dropped.
        $authAmountRaw = str_replace([',', '$'], '', trim(is_string($_POST['authorized_amount'] ?? null) ? $_POST['authorized_amount'] : ''));
        $authErrors = [];
        if ($authAmountRaw !== '' && !preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $authAmountRaw)) {
            $authErrors['authorized_amount'] = 'Authorized amount: enter the amount in dollars, 0 to 99,999,999.99 with up to two decimal places, for example 2995.00, or leave it blank.';
        }
        [$authDate, $dateError] = dr_date('authorized_at', 'Authorized on');
        if ($dateError !== null) $authErrors['authorized_at'] = $dateError;
        if ($authErrors) { keep_input(); keep_field_errors($authErrors); redirect($back); }
        // Each step only moves the status forward, and a date left blank keeps the
        // one already recorded (today's date only when there is none yet).
        db()->prepare("UPDATE device_requests SET funding_source = ?, auth_number = ?, authorized_amount = ?, authorized_at = ?, status = " . dr_forward_sql('Authorized') . " WHERE id = ?")
            ->execute([mb_substr(dr_text('funding_source'), 0, 120) ?: null,
                mb_substr(dr_text('auth_number'), 0, 80) ?: null,
                $authAmountRaw !== '' ? (float)$authAmountRaw : null,
                $authDate ?: ($r['authorized_at'] ?: date('Y-m-d')), $rid]);
        audit($user['id'], 'device.authorize', 'device_request', $rid, $r['device_name']);
        flash('Funding authorization recorded.');
    } elseif (isset($_POST['record_order'])) {
        [$orderDate, $dateError] = dr_date('ordered_at', 'Ordered on');
        $ordered = $orderDate ?: ($r['ordered_at'] ?: date('Y-m-d'));
        if ($dateError === null && $r['delivered_at'] && $ordered > $r['delivered_at']) $dateError = 'Ordered on cannot be after the delivery date (' . fmt_date($r['delivered_at']) . ').';
        if ($dateError !== null) { keep_input(); keep_field_errors(['ordered_at' => $dateError]); redirect($back); }
        db()->prepare("UPDATE device_requests SET po_number = ?, ordered_at = ?, status = " . dr_forward_sql('Ordered') . " WHERE id = ?")
            ->execute([mb_substr(dr_text('po_number'), 0, 80) ?: null, $ordered, $rid]);
        audit($user['id'], 'device.order', 'device_request', $rid);
        flash('Order recorded.');
    } elseif (isset($_POST['record_delivery'])) {
        // Checked before anything is written; a delivery cannot come before the order.
        $delErrors = [];
        [$delDate, $dateError] = dr_date('delivered_at', 'Delivered on');
        $delivered = $delDate ?: ($r['delivered_at'] ?: date('Y-m-d'));
        if ($dateError !== null) $delErrors['delivered_at'] = $dateError;
        elseif ($r['ordered_at'] && $delivered < $r['ordered_at']) $delErrors['delivered_at'] = 'Delivered on cannot be before the order date (' . fmt_date($r['ordered_at']) . ').';
        [$warranty, $dateError] = dr_date('warranty_until', 'Warranty until', true);
        if ($dateError !== null) $delErrors['warranty_until'] = $dateError;
        if ($delErrors) { keep_input(); keep_field_errors($delErrors); redirect($back); }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the request row so a double submit is processed one at a time.
            $lock = $pdo->prepare('SELECT id FROM device_requests WHERE id = ? FOR UPDATE');
            $lock->execute([$rid]);
            $lock->fetchColumn();
            $pdo->prepare("UPDATE device_requests SET delivered_at = ?, serial = ?, warranty_until = ?, status = " . dr_forward_sql('Delivered') . " WHERE id = ?")
                ->execute([$delivered, mb_substr(dr_text('serial'), 0, 120) ?: null, $warranty, $rid]);
            // Optionally add the delivered device to the loaner/equipment inventory —
            // once per request, however many times the form is submitted. The note
            // names the request, not the client: the inventory is visible to staff
            // outside the client's caseload. The result is said in the confirmation.
            $inventory = null;
            if (!empty($_POST['add_to_inventory']) && can('equipment')) {
                $marker = 'From device request #' . $rid;
                $have = $pdo->prepare('SELECT id FROM equipment WHERE notes = ? OR notes LIKE ? LIMIT 1');
                $have->execute([$marker, $marker . ' for %']); // second form: rows written before this change
                if (!$have->fetchColumn()) {
                    $pdo->prepare('INSERT INTO equipment (name, category, serial, item_condition, notes) VALUES (?,?,?,?,?)')
                        ->execute([$r['device_name'], 'Other', mb_substr(dr_text('serial'), 0, 120) ?: null, 'New', $marker]);
                    $inventory = 'added';
                } else {
                    $inventory = 'already';
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        audit($user['id'], 'device.deliver', 'device_request', $rid, $inventory === 'added' ? 'added to the loaner inventory' : null);
        flash('Delivery recorded' . ($inventory === 'added' ? ' and the device added to the loaner inventory.'
            : ($inventory === 'already' ? '. The device was already in the loaner inventory from this request, so it was not added again.'
            : (can('equipment') ? '. The device was not added to the loaner inventory.' : '.'))));
    } elseif (isset($_POST['record_training'])) {
        // Trained closes the request for good (it cannot be reopened), so it is
        // recorded only after the delivery and with an explicit confirmation, on a
        // readable date no earlier than the delivery; what was typed is kept if not.
        // (The page offers this form only once a delivery is recorded.)
        if (!$r['delivered_at']) { flash('Record the delivery first. A request is closed as Trained only after its delivery is recorded; nothing was changed.', 'error'); redirect($back); }
        $trainErrors = [];
        [$trainDate, $dateError] = dr_date('trained_at', 'Training completed on');
        $trained = $trainDate ?: date('Y-m-d');
        if ($dateError !== null) $trainErrors['trained_at'] = $dateError;
        elseif ($trained < $r['delivered_at']) $trainErrors['trained_at'] = 'Training completed on cannot be before the delivery date (' . fmt_date($r['delivered_at']) . ').';
        if (($_POST['confirm_trained'] ?? '') !== '1') $trainErrors['confirm_trained'] = 'Confirm that training is complete: tick the box to close this request as Trained.';
        if ($trainErrors) { keep_input(); keep_field_errors($trainErrors); redirect($back); }
        db()->prepare("UPDATE device_requests SET trained_at = ?, status = " . dr_forward_sql('Trained') . " WHERE id = ?")
            ->execute([$trained, $rid]);
        audit($user['id'], 'device.trained', 'device_request', $rid);
        flash('Training completed — request closed out.');
    } elseif (isset($_POST['set_state'])) {
        $to = in_array($_POST['to'] ?? '', ['Denied', 'Cancelled', 'Requested'], true) ? $_POST['to'] : null;
        if ($to) {
            // A reopened request resumes at the furthest step it had recorded (delivery,
            // order, authorization, recommended quote, any quote), so its status agrees
            // with its lifecycle and it is counted and filtered where it belongs. Nothing
            // can change those steps while it is closed.
            if ($to === 'Requested') {
                $qc = db()->prepare('SELECT COUNT(*) FROM device_request_quotes WHERE request_id = ?');
                $qc->execute([$rid]);
                $to = $r['delivered_at'] ? 'Delivered' : ($r['ordered_at'] ? 'Ordered' : ($r['authorized_at'] ? 'Authorized'
                    : ($r['selected_quote_id'] ? 'Awaiting Authorization' : ((int)$qc->fetchColumn() > 0 ? 'Quoting' : 'Requested'))));
            }
            db()->prepare('UPDATE device_requests SET status = ?, notes = ? WHERE id = ?')
                ->execute([$to, mb_substr(dr_text('notes', (string)($r['notes'] ?? '')), 0, 4000) ?: null, $rid]);
            audit($user['id'], 'device.status', 'device_request', $rid, ($isReopen ? 'reopened as ' : '') . $to);
            flash(($isReopen ? 'Request reopened. ' : '') . 'Status updated to ' . $to . '.');
        } else {
            flash('Choose whether the request was denied or cancelled. Nothing was changed.', 'error');
        }
    }
    redirect($back);
}

/* ---- Justification letter (printable / emailable) ---- */
if (isset($_GET['letter'])) {
    $r = dr_load((int)$_GET['letter'], $user);
    if (!$r) { flash('Request not found.', 'error'); redirect('procurement.php'); }
    $sel = null;
    if ($r['selected_quote_id']) {
        $q = db()->prepare('SELECT * FROM device_request_quotes WHERE id = ? AND request_id = ?');
        $q->execute([(int)$r['selected_quote_id'], (int)$r['id']]);
        $sel = $q->fetch();
    }
    // Titled and named with the client, like the PDF letters, so a saved copy is found by name.
    $doc = new AccessibleDocument('Assistive Technology Funding Justification — ' . $r['client_name']);
    $doc->paragraph(brand('org'));
    $doc->properties(array_filter([
        'Client' => $r['client_name'],
        'Device / technology' => $r['device_name'] . ($r['category'] ? ' (' . $r['category'] . ')' : ''),
        'Recommended vendor' => $sel ? $sel['vendor'] : '',
        'Estimated cost' => $sel ? fmt_money((float)$sel['amount']) : '',
        'Funding source' => $r['funding_source'] ?? '',
        'Authorization #' => $r['auth_number'] ?? '',
        'Prepared' => date('F j, Y') . ' by ' . $user['name'],
    ]));
    $doc->heading('Justification of need');
    $doc->paragraph($r['justification'] ?: 'The recommended technology is necessary for the client to access print and electronic material, complete essential job functions, and achieve the vocational goal identified in their Individualized Plan for Employment (IPE). Without this technology and associated training, the client cannot independently perform the required tasks.');
    audit($user['id'], 'device.letter', 'device_request', (int)$r['id']);
    $doc->download('justification_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string)$r['client_name']) . '_' . date('Ymd') . '.html');
}

/* ================= Detail view ================= */
if (isset($_GET['id'])) {
    $r = dr_load((int)$_GET['id'], $user);
    if (!$r) { flash('Request not found.', 'error'); redirect('procurement.php'); }
    $rid = (int)$r['id'];
    $quotes = db()->prepare('SELECT q.*, u.name AS by_name FROM device_request_quotes q LEFT JOIN users u ON u.id = q.created_by WHERE q.request_id = ? ORDER BY q.amount');
    $quotes->execute([$rid]);
    $quotes = $quotes->fetchAll();
    $done = in_array($r['status'], ['Trained', 'Denied', 'Cancelled'], true);
    page_header('Device purchase: ' . $r['device_name'], $user, 'procurement', [['procurement.php', 'Device purchases']]);
    ?>
    <p><a href="procurement.php"><span aria-hidden="true">&larr; </span>All device requests</a></p>
    <h1>Device purchase: <?= h($r['device_name']) ?> <?= dr_badge($r['status']) ?></h1>
    <p class="muted">For <a href="client.php?id=<?= (int)$r['client_id'] ?>"><?= h($r['client_name']) ?></a>
       <?= $r['category'] ? ' · ' . h($r['category']) : '' ?> · requested by <?= h($r['requester'] ?? 'unknown') ?> <?= fmt_date($r['created_at']) ?></p>
    <div class="page-actions">
      <a class="btn btn-secondary btn-sm" href="at_services.php?kind=setup&amp;new=1&amp;client_id=<?= (int)$r['client_id'] ?>&amp;request_id=<?= $rid ?>">Create device setup and outcome checks</a>
      <a class="btn btn-secondary btn-sm" href="procurement.php?letter=<?= $rid ?>">Download justification letter (HTML file)</a>
    </div>

    <?php if ($r['justification']): ?>
    <section class="card"><h2>Justification of need</h2><p style="white-space:pre-wrap"><?= h($r['justification']) ?></p></section>
    <?php endif; ?>

    <section class="card" aria-labelledby="q-h">
      <h2 id="q-h">Comparison quotes (<?= count($quotes) ?>)</h2>
      <p class="muted">VR funding usually requires comparison quotes. Mark the one you recommend.</p>
      <?php if ($quotes): ?>
      <div class="table-scroll" tabindex="0" role="region" aria-labelledby="q-cap">
      <table>
        <caption class="visually-hidden" id="q-cap">Comparison quotes by vendor</caption>
        <thead><tr><th scope="col">Vendor</th><th scope="col" class="num">Amount</th><th scope="col">Reference</th><th scope="col">Recommended</th></tr></thead>
        <tbody>
        <?php foreach ($quotes as $q): $isSel = (int)$r['selected_quote_id'] === (int)$q['id']; ?>
          <tr>
            <th scope="row"><?= h($q['vendor']) ?><?= $q['notes'] ? '<br><span class="muted">' . h($q['notes']) . '</span>' : '' ?></th>
            <td class="num"><?= fmt_money((float)$q['amount']) ?></td>
            <td><?= h($q['quote_ref'] ?? '—') ?></td>
            <td><?php if ($isSel): ?><span class="badge badge-closed">Recommended</span><?php elseif (!$done): ?>
              <form method="post" action="procurement.php" class="inline-form"><?= csrf_field() ?>
                <input type="hidden" name="rid" value="<?= $rid ?>"><input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>">
                <button type="submit" name="select_quote" value="1" class="btn btn-secondary btn-sm">Mark recommended<span class="visually-hidden"> — <?= h($q['vendor']) ?></span></button>
              </form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?><p class="muted">No quotes yet.</p><?php endif; ?>
      <?php if (!$done): ?>
      <form method="post" action="procurement.php" class="stacked" style="margin-top:1rem"><?= csrf_field() ?><?= web_idem_field() ?>
        <input type="hidden" name="rid" value="<?= $rid ?>">
        <?php // After a rejected submission the form comes back filled in, with the error on its field.
        $oldQuote = fn(string $k): string => old_in('add_quote') !== '' ? h(old_in($k)) : ''; ?>
        <?= form_error_summary(['vendor' => '', 'amount' => '']) ?>
        <p class="hint">Fields marked * are required.</p>
        <h3>Add a quote</h3>
        <div class="grid grid-4">
          <div class="field"><label for="vendor">Vendor <?= required_mark() ?></label><input type="text" id="vendor" name="vendor" required maxlength="190" value="<?= $oldQuote('vendor') ?>" <?= field_aria('vendor') ?>><?= field_error('vendor') ?></div>
          <div class="field"><label for="amount">Amount in US dollars <?= required_mark() ?></label><input type="text" inputmode="decimal" id="amount" name="amount" maxlength="16" required value="<?= $oldQuote('amount') ?>" <?= field_aria('amount') ?>><?= field_error('amount') ?></div>
          <div class="field"><label for="quote_ref">Quote #</label><input type="text" id="quote_ref" name="quote_ref" maxlength="120" value="<?= $oldQuote('quote_ref') ?>"></div>
          <div class="field"><label for="quote_notes">Notes</label><input type="text" id="quote_notes" name="quote_notes" maxlength="500" value="<?= $oldQuote('quote_notes') ?>"></div>
        </div>
        <button type="submit" name="add_quote" value="1" class="btn btn-secondary">Add quote</button>
      </form>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="life-h">
      <h2 id="life-h">Lifecycle</h2>
      <dl class="props">
        <dt>Funding source</dt><dd><?= h($r['funding_source'] ?? '—') ?></dd>
        <dt>Authorization #</dt><dd><?= h($r['auth_number'] ?? '—') ?><?= $r['authorized_amount'] !== null ? ' · ' . fmt_money((float)$r['authorized_amount']) : '' ?><?= $r['authorized_at'] ? ' · ' . fmt_date($r['authorized_at']) : '' ?></dd>
        <dt>Purchase order</dt><dd><?= h($r['po_number'] ?? '—') ?><?= $r['ordered_at'] ? ' · ordered ' . fmt_date($r['ordered_at']) : '' ?></dd>
        <dt>Delivered</dt><dd><?= $r['delivered_at'] ? fmt_date($r['delivered_at']) : '—' ?><?= $r['serial'] ? ' · serial number ' . h($r['serial']) : '' ?><?= $r['warranty_until'] ? ' · warranty to ' . fmt_date($r['warranty_until']) : '' ?></dd>
        <dt>Training complete</dt><dd><?= $r['trained_at'] ? fmt_date($r['trained_at']) : '—' ?></dd>
      </dl>

      <?php if (!$done):
      // Every step stays available (each only moves the status forward), but only the
      // next one for the current status is shown up front; the rest, and closing the
      // request, are under "More actions", which opens itself after a refused save there.
      $nextStep = ['Authorized' => 'order', 'Ordered' => 'delivery', 'Delivered' => 'training'][$r['status']] ?? 'authorize';
      $stepFields = ['authorize' => ['authorized_amount', 'authorized_at'], 'order' => ['ordered_at'], 'delivery' => ['delivered_at', 'warranty_until'], 'training' => ['trained_at', 'confirm_trained']];
      $lifeForms = [];
      ob_start(); ?>
        <form method="post" action="procurement.php" class="stacked card"><?= csrf_field() ?>
          <input type="hidden" name="rid" value="<?= $rid ?>">
          <h3>Record funding authorization</h3>
          <?php // After a rejected amount the form comes back as it was typed, with the error on its field.
          $oldAuth = fn(string $k, string $default): string => old_in('authorize') !== '' ? old_in($k) : $default; ?>
          <?= form_error_summary(['authorized_amount' => '', 'authorized_at' => '']) ?>
          <div class="field"><label for="funding_source">Funding source</label><input type="text" id="funding_source" name="funding_source" maxlength="120" value="<?= h($oldAuth('funding_source', (string)($r['funding_source'] ?? ''))) ?>" placeholder="e.g. State VR"></div>
          <div class="field"><label for="auth_number">Authorization #</label><input type="text" id="auth_number" name="auth_number" maxlength="80" value="<?= h($oldAuth('auth_number', (string)($r['auth_number'] ?? ''))) ?>"></div>
          <div class="field"><label for="authorized_amount">Authorized amount</label><input type="text" inputmode="decimal" id="authorized_amount" name="authorized_amount" maxlength="16" value="<?= h($oldAuth('authorized_amount', $r['authorized_amount'] !== null ? (string)$r['authorized_amount'] : '')) ?>" <?= field_aria('authorized_amount') ?>><?= field_error('authorized_amount') ?></div>
          <div class="field"><label for="authorized_at">Authorized on</label><input type="date" id="authorized_at" name="authorized_at" max="<?= date('Y-m-d') ?>" value="<?= h($oldAuth('authorized_at', (string)($r['authorized_at'] ?: date('Y-m-d')))) ?>" <?= field_aria('authorized_at') ?>><?= field_error('authorized_at') ?></div>
          <button type="submit" name="authorize" value="1" class="btn btn-primary">Save authorization</button>
        </form>
      <?php $lifeForms['authorize'] = ob_get_clean(); ob_start(); ?>
        <form method="post" action="procurement.php" class="stacked card"><?= csrf_field() ?>
          <input type="hidden" name="rid" value="<?= $rid ?>">
          <h3>Record order</h3>
          <?php $oldOrder = fn(string $k, string $default): string => old_in('record_order') !== '' ? old_in($k) : $default; ?>
          <?= form_error_summary(['ordered_at' => '']) ?>
          <div class="field"><label for="po_number">Purchase order (PO) number</label><input type="text" id="po_number" name="po_number" maxlength="80" value="<?= h($oldOrder('po_number', (string)($r['po_number'] ?? ''))) ?>"></div>
          <div class="field"><label for="ordered_at">Ordered on</label><input type="date" id="ordered_at" name="ordered_at" max="<?= date('Y-m-d') ?>" value="<?= h($oldOrder('ordered_at', (string)($r['ordered_at'] ?: date('Y-m-d')))) ?>" <?= field_aria('ordered_at') ?>><?= field_error('ordered_at') ?></div>
          <button type="submit" name="record_order" value="1" class="btn btn-primary">Save order</button>
        </form>
      <?php $lifeForms['order'] = ob_get_clean(); ob_start(); ?>
        <form method="post" action="procurement.php" class="stacked card"><?= csrf_field() ?>
          <input type="hidden" name="rid" value="<?= $rid ?>">
          <h3>Record delivery</h3>
          <?php $oldDel = fn(string $k, string $default): string => old_in('record_delivery') !== '' ? old_in($k) : $default; ?>
          <?= form_error_summary(['delivered_at' => '', 'warranty_until' => '']) ?>
          <div class="field"><label for="delivered_at">Delivered on</label><input type="date" id="delivered_at" name="delivered_at" max="<?= date('Y-m-d') ?>" value="<?= h($oldDel('delivered_at', (string)($r['delivered_at'] ?: date('Y-m-d')))) ?>" <?= field_aria('delivered_at') ?>><?= field_error('delivered_at') ?></div>
          <div class="field"><label for="serial">Serial number</label><input type="text" id="serial" name="serial" maxlength="120" value="<?= h($oldDel('serial', (string)($r['serial'] ?? ''))) ?>"></div>
          <div class="field"><label for="warranty_until">Warranty until</label><input type="date" id="warranty_until" name="warranty_until" value="<?= h($oldDel('warranty_until', (string)($r['warranty_until'] ?? ''))) ?>" <?= field_aria('warranty_until') ?>><?= field_error('warranty_until') ?></div>
          <?php // After a refused save the box comes back as it was ticked, like the fields above. ?>
          <?php if (can('equipment')): ?><div class="field"><label><input type="checkbox" name="add_to_inventory" value="1"<?= old_in('record_delivery') !== '' && old_in('add_to_inventory') === '1' ? ' checked' : '' ?>> Also add to the loaner inventory</label></div><?php endif; ?>
          <button type="submit" name="record_delivery" value="1" class="btn btn-primary">Save delivery</button>
        </form>
      <?php $lifeForms['delivery'] = ob_get_clean(); ob_start(); ?>
        <form method="post" action="procurement.php" class="stacked card"><?= csrf_field() ?>
          <input type="hidden" name="rid" value="<?= $rid ?>">
          <h3>Complete training</h3>
          <?php // Closing as Trained is final, so it needs the confirmation box ticked; a
          // request not yet recorded as delivered says so next to the box.
          $oldTrain = fn(string $k, string $default): string => old_in('record_training') !== '' ? old_in($k, $default) : $default; ?>
          <?php if (!$r['delivered_at']): // Training closes a request only after its delivery is recorded. ?>
          <p>Record the delivery first. A request is closed as Trained only after its delivery is recorded (status now: <?= h($r['status']) ?>).</p>
          <?php else: ?>
          <?= form_error_summary(['trained_at' => '', 'confirm_trained' => ''], ['confirm_trained' => 'confirm_trained']) ?>
          <div class="field"><label for="trained_at">Training completed on</label><input type="date" id="trained_at" name="trained_at" min="<?= h($r['delivered_at']) ?>" max="<?= date('Y-m-d') ?>" value="<?= h($oldTrain('trained_at', date('Y-m-d'))) ?>" <?= field_aria('trained_at') ?>><?= field_error('trained_at') ?></div>
          <div class="field"><label><input type="checkbox" id="confirm_trained" name="confirm_trained" value="1" required <?= field_aria('confirm_trained') ?>> Training is complete; close this request <span class="hint">This closes the request as Trained. A trained request cannot be changed or reopened.</span></label><?= field_error('confirm_trained') ?></div>
          <button type="submit" name="record_training" value="1" class="btn btn-primary">Mark trained &amp; close</button>
          <?php endif; ?>
        </form>
      <?php $lifeForms['training'] = ob_get_clean();
      $moreFailed = false;
      foreach ($stepFields as $stepKey => $fields) {
          if ($stepKey !== $nextStep && array_intersect_key(form_errors(), array_flip($fields)) !== []) $moreFailed = true;
      } ?>
      <?= $lifeForms[$nextStep] ?>
      <details class="mt-1"<?= $moreFailed ? ' open' : '' ?>>
      <summary>More actions<span class="visually-hidden"> for this request: other lifecycle steps, deny or cancel</span></summary>
      <div class="grid grid-2">
        <?php foreach ($lifeForms as $stepKey => $formHtml) if ($stepKey !== $nextStep) echo $formHtml; ?>
      </div>

      <?php // The outcome is an explicit choice, so pressing Enter in the reason field
      // can never pick one by default, and the form works without scripting. ?>
      <form method="post" action="procurement.php" class="stacked" style="margin-top:1rem"><?= csrf_field() ?>
        <input type="hidden" name="rid" value="<?= $rid ?>"><input type="hidden" name="set_state" value="1">
        <h3>Deny or cancel</h3>
        <fieldset>
          <legend>Close this request as</legend>
          <label><input type="radio" name="to" value="Denied" required> Denied (funding was refused)</label>
          <label><input type="radio" name="to" value="Cancelled"> Cancelled (no longer needed)</label>
        </fieldset>
        <div class="field"><label for="notes">Reason (optional)</label><input type="text" id="notes" name="notes" maxlength="500" value="<?= h($r['notes'] ?? '') ?>"></div>
        <button type="submit" class="btn btn-danger">Close request</button>
      </form>
      </details>
      <?= dr_date_script() ?>
      <?php else: ?>
      <p class="muted">This request is closed (<?= h($r['status']) ?>).</p>
      <?php if ($r['status'] !== 'Trained'): ?>
      <form method="post" action="procurement.php"><?= csrf_field() ?>
        <input type="hidden" name="rid" value="<?= $rid ?>"><input type="hidden" name="to" value="Requested">
        <button type="submit" name="set_state" value="1" class="btn-link">Reopen this request</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($r['notes']): ?><p class="muted">Note: <?= h($r['notes']) ?></p><?php endif; ?>
    </section>
    <?php
    page_footer();
    exit;
}

/* ================= List view ================= */
$scoped = caseload_scoped();
$statusFilter = in_array($_GET['status'] ?? '', DR_STATUSES, true) ? $_GET['status'] : '';
$where = [];
$args = [];
if ($scoped) { $where[] = 'c.assigned_to = ?'; $args[] = (int)$user['id']; }
if ($statusFilter !== '') { $where[] = 'dr.status = ?'; $args[] = $statusFilter; }
$sql = "SELECT dr.id, dr.device_name, dr.category, dr.status, dr.authorized_amount, dr.created_at, c.name AS client_name, c.id AS cid,
        (SELECT sq.amount FROM device_request_quotes sq WHERE sq.id = dr.selected_quote_id AND sq.request_id = dr.id) AS selected_quote,
        (SELECT MIN(amount) FROM device_request_quotes q WHERE q.request_id = dr.id) AS low_quote
    FROM device_requests dr JOIN clients c ON c.id = dr.client_id";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY dr.updated_at DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

// The tiles count every request on the caseload, whatever the status filter or the
// 300-row display limit; only "Shown" describes this view.
$tq = db()->prepare('SELECT dr.status, COUNT(*) FROM device_requests dr JOIN clients c ON c.id = dr.client_id'
    . ($scoped ? ' WHERE c.assigned_to = ?' : '') . ' GROUP BY dr.status');
$tq->execute($scoped ? [(int)$user['id']] : []);
$open = 0; $awaiting = 0; $authorized = 0;
foreach ($tq->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $n) {
    if (!in_array($status, ['Trained', 'Denied', 'Cancelled'], true)) $open += (int)$n;
    if ($status === 'Awaiting Authorization') $awaiting += (int)$n;
    if (in_array($status, ['Authorized', 'Ordered', 'Delivered'], true)) $authorized += (int)$n;
}

$cq = db()->prepare("SELECT id, name FROM clients WHERE status NOT LIKE 'Closed%'" . ($scoped ? ' AND assigned_to = ?' : '') . ' ORDER BY name');
$cq->execute($scoped ? [(int)$user['id']] : []);
$clients = $cq->fetchAll();

page_header('Device purchases', $user, 'procurement');
?>
<h1>Device purchases</h1>
<p class="muted">Track each client's device from request through quotes, funding authorization, ordering, delivery, and training<?= $scoped ? ' (your caseload)' : '' ?>.</p>

<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= $open ?></span><span class="stat-label">Open requests</span></div>
  <div class="card stat"><span class="stat-num"><?= $awaiting ?></span><span class="stat-label">Awaiting authorization</span></div>
  <div class="card stat"><span class="stat-num"><?= $authorized ?></span><span class="stat-label">Authorized / in progress</span></div>
  <div class="card stat"><span class="stat-num"><?= count($rows) ?></span><span class="stat-label">Shown</span></div>
</div>

<form method="get" action="procurement.php" class="page-actions" role="search" aria-label="Filter requests">
  <label for="status">Status</label>
  <select id="status" name="status">
    <option value="">All</option>
    <?php foreach (DR_STATUSES as $s): ?><option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
</form>

<div class="table-scroll" tabindex="0" role="region" aria-label="Device requests">
<table>
  <caption class="visually-hidden">Device requests</caption>
  <thead><tr><th scope="col">Device</th><th scope="col">Client</th><th scope="col">Status</th><th scope="col" class="num">Estimated cost</th><th scope="col">Requested</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No device requests<?= $statusFilter ? ' with that status' : ' yet' ?>.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
    <tr>
      <th scope="row"><a href="procurement.php?id=<?= (int)$x['id'] ?>"><?= h($x['device_name']) ?><span class="visually-hidden"> for <?= h($x['client_name']) ?></span></a><?= $x['category'] ? '<br><span class="muted">' . h($x['category']) . '</span>' : '' ?></th>
      <td><?= h($x['client_name']) ?></td>
      <td><?= dr_badge($x['status']) ?></td>
      <?php // The authorized amount, else the recommended quote (the letter's figure), else the lowest quote, each labelled. ?>
      <td class="num"><?php
        if ($x['authorized_amount'] !== null) echo fmt_money((float)$x['authorized_amount']) . ' <span class="muted">authorized</span>';
        elseif ($x['selected_quote'] !== null) echo fmt_money((float)$x['selected_quote']) . ' <span class="muted">recommended quote</span>';
        elseif ($x['low_quote'] !== null) echo fmt_money((float)$x['low_quote']) . ' <span class="muted">lowest quote</span>';
        else echo '—'; ?></td>
      <td><?= fmt_date($x['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php // The list comes first; the new-request form opens itself after a refused submit. ?>
<h2 class="visually-hidden">New device request</h2>
<details class="card mt-1"<?= array_intersect_key(form_errors(), array_flip(['client_id', 'device_name', 'justification'])) !== [] ? ' open' : '' ?>>
  <summary accesskey="n" id="new-h">New device request</summary>
  <?php // After a rejected submission the form comes back filled in, with each error on its field.
  $oldReq = fn(string $k, string $default = ''): string => old_in('add_request') !== '' ? old_in($k, $default) : $default; ?>
  <form method="post" action="procurement.php" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['client_id' => '', 'device_name' => '', 'justification' => '']) ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="grid grid-4">
      <div class="field"><label for="client_id">Client <?= required_mark() ?></label>
        <select id="client_id" name="client_id" required <?= field_aria('client_id') ?>>
          <option value="">— Choose —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $oldReq('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select><?= field_error('client_id') ?></div>
      <div class="field"><label for="device_name">Device / technology <?= required_mark() ?></label><input type="text" id="device_name" name="device_name" required maxlength="190" value="<?= h($oldReq('device_name')) ?>" <?= field_aria('device_name') ?>><?= field_error('device_name') ?></div>
      <div class="field"><label for="category">Category <span class="hint">CCTV: closed-circuit television (video) magnifier. AAC: augmentative and alternative communication.</span></label>
        <?php // The values are the stored categories; only the shown names spell out the abbreviations (as in recommend.php). ?>
        <select id="category" name="category"><?php $catSel = $oldReq('category', 'Other');
          foreach (cms_list_items('device_request_categories') as $cv): $cl = ['Magnifier / CCTV' => 'Video magnifier (CCTV, closed-circuit television)', 'AAC Device' => 'Communication device (AAC, augmentative and alternative communication)'][$cv] ?? $cv; ?>
          <option value="<?= h($cv) ?>"<?= $cv === $catSel ? ' selected' : '' ?>><?= h($cl) ?></option>
        <?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="justification">Justification of need <span class="hint">Why this device is needed for the vocational goal</span></label>
      <textarea id="justification" name="justification" rows="3" maxlength="4000" <?= field_aria('justification') ?>><?= h($oldReq('justification')) ?></textarea><?= field_error('justification') ?></div>
    <button type="submit" name="add_request" value="1" class="btn btn-primary">Create request</button>
  </form>
</details>
<?php page_footer();
