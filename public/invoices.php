<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('billing');

// Caseload-scoped billing users see and act on only their own clients' invoices.
$scoped = caseload_scoped();
$uid = (int)$user['id'];
$scopeCond = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeArg = $scoped ? [$uid] : [];

const INVOICE_STATUSES = ['Draft', 'Submitted', 'Paid', 'Denied', 'Void'];

/** Cents in a typed amount ("1250.00", "$1,250", "125.5"), or null when it is not
 *  dollars and cents. A leading $ and thousands commas are accepted as typed. */
function invoice_amount_cents(string $raw): ?int {
    $amt = preg_replace('/^\$\s*/', '', trim($raw));
    if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/D', $amt)) $amt = str_replace(',', '', $amt);
    return preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $amt) ? (int)round((float)$amt * 100) : null;
}

/** The list filters — status, agency, month (YYYY-MM of the invoice date) and text
 *  (invoice number or client name) — read from the page's GET, or from the list_*
 *  hidden fields a row's form carries ($prefix 'list_'), so a refused entry returns
 *  to the list as it was filtered. Anything unreadable is blank (no filter). */
function invoice_list_filters(array $src, string $prefix = ''): array {
    $get = fn(string $k): string => is_string($src[$prefix . $k] ?? null) ? trim($src[$prefix . $k]) : '';
    $status = in_array($get('status'), INVOICE_STATUSES, true) ? $get('status') : '';
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $get('month')) ? $get('month') : '';
    return ['status' => $status, 'agency' => mb_substr($get('agency'), 0, 190), 'month' => $month, 'q' => mb_substr($get('q'), 0, 120)];
}
/** The SQL for a filter set, against the list's aliases (i, t, c); binds into $params. */
function invoice_filter_sql(array $f, array &$params): string {
    $sql = '';
    if ($f['status'] !== '') { $sql .= ' AND i.status = ?'; $params[] = $f['status']; }
    if ($f['agency'] !== '') { $sql .= ' AND COALESCE(i.funding_agency, t.funding_agency) = ?'; $params[] = $f['agency']; }
    if ($f['month'] !== '') { $sql .= ' AND i.created_at >= ? AND i.created_at < ?'; $params[] = $f['month'] . '-01'; $params[] = date('Y-m-01', strtotime($f['month'] . '-01 +1 month')); }
    if ($f['q'] !== '') { $sql .= ' AND (i.invoice_number LIKE ? OR c.name LIKE ?)'; $like = '%' . addcslashes($f['q'], '%_\\') . '%'; $params[] = $like; $params[] = $like; }
    return $sql;
}
/** invoices.php?… for a filter set and page, as the pagination and the forms link back. */
function invoice_list_url(array $f, int $page = 1, string $anchor = 'inv-list-h'): string {
    $qs = http_build_query(array_filter($f + ['page' => $page > 1 ? (string)$page : ''], fn($v) => $v !== ''));
    return 'invoices.php' . ($qs !== '' ? '?' . $qs : '') . '#' . $anchor;
}
/** The hidden fields that carry the filters through a row's form. */
function invoice_list_hidden(array $f): string {
    $out = '';
    foreach ($f as $k => $v) if ($v !== '') $out .= '<input type="hidden" name="list_' . $k . '" value="' . h($v) . '">';
    return $out;
}



// The agencies on tracks with unbilled work, for the batch's optional restriction.
$ua = db()->prepare("SELECT DISTINCT t.funding_agency FROM training_tracks t JOIN clients c ON c.id = t.client_id
    WHERE t.funding_agency <> '' AND t.billing_mode <> 'none' AND (EXISTS (SELECT 1 FROM work_logs w WHERE w.track_id = t.id AND w.is_billed = 0)
        OR EXISTS (SELECT 1 FROM track_expenses e WHERE e.track_id = t.id AND e.is_billed = 0))" . $scopeCond . ' ORDER BY t.funding_agency');
$ua->execute($scopeArg);
$unbilledAgencies = $ua->fetchAll(PDO::FETCH_COLUMN);

/* Batch: generate invoices for every active track with unbilled work — optionally only
   work dated up to a date, and only one agency's tracks. With both blank, exactly as
   before: generate_invoice() for every track with unbilled work. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_generate'])) {
    $untilRaw = is_string($_POST['batch_until'] ?? null) ? trim($_POST['batch_until']) : '';
    $agency = is_string($_POST['batch_agency'] ?? null) ? trim($_POST['batch_agency']) : '';
    $until = $untilRaw === '' ? null : valid_date($untilRaw, 2000, (int)date('Y') + 5);
    $bErrs = [];
    if ($untilRaw !== '' && $until === null) $bErrs['batch_until'] = '"' . mb_substr($untilRaw, 0, 30) . '" is not a date. Enter the last work date to invoice as YYYY-MM-DD, or leave it blank to invoice all unbilled work.';
    if ($agency !== '' && !in_array($agency, $unbilledAgencies, true)) $bErrs['batch_agency'] = 'Choose a funding agency from the list (only agencies with unbilled work are listed), or leave it blank for all agencies.';
    if ($bErrs) {
        keep_input();
        keep_field_errors($bErrs);
        redirect('invoices.php#batch-h');
    }
    // Tracks with unbilled work logs OR unbilled expenses (expense-only tracks
    // were previously never picked up by the batch), within the limits given.
    $bt = db()->prepare("SELECT DISTINCT t.id FROM training_tracks t
        JOIN clients c ON c.id = t.client_id
        LEFT JOIN work_logs w ON w.track_id = t.id AND w.is_billed = 0" . ($until !== null ? ' AND w.work_date <= ?' : '') . "
        LEFT JOIN track_expenses e ON e.track_id = t.id AND e.is_billed = 0" . ($until !== null ? ' AND e.expense_date <= ?' : '') . "
        WHERE (w.id IS NOT NULL OR e.id IS NOT NULL) AND t.billing_mode <> 'none'" . ($agency !== '' ? ' AND t.funding_agency = ?' : '') . $scopeCond);
    $bt->execute(array_merge($until !== null ? [$until, $until] : [], $agency !== '' ? [$agency] : [], $scopeArg));
    $tracks = $bt->fetchAll();
    $made = 0;
    $limits = ($until !== null ? ' (work to ' . $until . ')' : '') . ($agency !== '' ? ' (agency ' . $agency . ')' : '');
    foreach ($tracks as $t) {
        $invId = generate_invoice((int)$t['id'], $user['id'], $until);
        if ($invId !== null) {
            $made++;
            audit($user['id'], 'invoice.create', 'invoice', $invId, 'batch from track #' . (int)$t['id'] . $limits);
        }
    }
    $what = ($agency !== '' ? $agency . "'s" : 'all') . ' unbilled work' . ($until !== null ? ' dated up to ' . fmt_date($until) : '');
    flash($made ? "Generated {$made} invoice(s) from {$what}. They are listed below as Drafts."
                : 'No unbilled work found for ' . $what . ' — nothing to invoice.');
    redirect('invoices.php#inv-list-h');
}

/* Mark every Draft invoice listed on the page submitted: the same transition and audit
   entry as a row's Mark submitted, invoice by invoice, for the ids the page listed
   (and, for a caseload-scoped user, only those on the caseload). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_shown'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', is_string($_POST['draft_ids'] ?? null) ? $_POST['draft_ids'] : '')), fn($i) => $i > 0)));
    $listUrl = invoice_list_url(invoice_list_filters($_POST, 'list_'));
    if (!$ids) { flash('No draft invoices were listed to mark submitted.', 'error'); redirect($listUrl); }
    $changed = 0; $skipped = 0; $notOwn = 0;
    $own = db()->prepare('SELECT i.invoice_number, c.assigned_to FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id WHERE i.id = ?');
    foreach (array_slice($ids, 0, 300) as $id) {
        $own->execute([$id]);
        $row = $own->fetch();
        if (!$row) { $skipped++; continue; }
        if ($scoped && (int)$row['assigned_to'] !== $uid) { $notOwn++; continue; }
        if (invoice_submit($id, date('Y-m-d'))) { audit($user['id'], 'invoice.submit', 'invoice', $id, 'submitted ' . date('Y-m-d')); $changed++; }
        else $skipped++;
    }
    $msg = $changed . ' draft invoice' . ($changed === 1 ? ' was' : 's were') . ' marked submitted.';
    if ($skipped) $msg .= ' ' . $skipped . ' ' . ($skipped === 1 ? 'was' : 'were') . ' not changed: no longer a Draft (someone may have already updated or voided ' . ($skipped === 1 ? 'it' : 'them') . ').';
    if ($notOwn) $msg .= ' ' . $notOwn . ' ' . ($notOwn === 1 ? 'is' : 'are') . ' not on your caseload and ' . ($notOwn === 1 ? 'was' : 'were') . ' left as ' . ($notOwn === 1 ? 'it was' : 'they were') . '.';
    flash($msg, $changed ? 'success' : 'error');
    redirect($listUrl);
}

/* Every action on one invoice shares the caseload check */
/* Several Draft invoices marked submitted at once (4.0.0), each with the same date. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_submit'])) {
    $raw = is_string($_POST['bulk_submitted_on'] ?? null) ? trim($_POST['bulk_submitted_on']) : '';
    $n = 0; $refused = [];
    foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($_POST['bulk_ids'] ?? []))))), 0, 200) as $bid) {
        $bq = db()->prepare("SELECT i.id, i.invoice_number, i.created_at FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id WHERE i.id = ? AND i.status = 'Draft'" . $scopeCond);
        $bq->execute(array_merge([$bid], $scopeArg));
        $bi = $bq->fetch();
        if (!$bi) continue;
        $on = $raw === '' ? date('Y-m-d') : billing_date_in($raw, (string)$bi['created_at']);
        if ($on === null) { $refused[] = $bi['invoice_number']; continue; }
        if (invoice_submit((int)$bi['id'], $on)) { audit($user['id'], 'invoice.submit', 'invoice', (int)$bi['id'], 'submitted ' . $on . ' (several at once)'); $n++; }
    }
    flash(($n ? $n . ' invoice' . ($n === 1 ? '' : 's') . ' marked submitted.' : 'No invoice was marked submitted.') . ($refused ? ' Not changed, because the date is before they were created: ' . implode(', ', $refused) . '.' : ''), $n ? 'success' : 'error');
    redirect('invoices.php#inv-list-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['set_status']) || isset($_POST['void_draft']) || isset($_POST['apply_payment'])
        || isset($_POST['remove_payment']) || isset($_POST['close_invoice']) || isset($_POST['save_memo']))) {
    $id = (int)($_POST['id'] ?? 0);
    // Caseload scope the WRITE (the list/export are scoped, this was not): the
    // invoice's client must be on the caseload or a scoped user could transition
    // another staffer's invoice by posting its id.
    if ($scoped) {
        $own = db()->prepare('SELECT c.assigned_to FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id WHERE i.id = ?');
        $own->execute([$id]);
        $assignedTo = $own->fetchColumn();
        if ($assignedTo === false || (int)$assignedTo !== $uid) { flash('That invoice is not on your caseload.', 'error'); redirect('invoices.php#inv-list-h'); }
    }
    $cur = db()->prepare('SELECT invoice_number, track_id, total_amount, created_at, submitted_at, status FROM invoices WHERE id = ?');
    $cur->execute([$id]);
    $inv = $cur->fetch();
    if (!$inv) { flash('That invoice no longer exists.', 'error'); redirect('invoices.php#inv-list-h'); }
    $num = $inv['invoice_number'];
}

/* Void a DRAFT invoice: its work and expenses become unbilled again, so a
   mistake found after generating can be corrected and re-invoiced. Submitted
   and paid invoices have gone to the funder and are never voided here. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_draft'])) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Same lock order as generate_invoice(): the track first, then its rows.
        $pdo->prepare('SELECT id FROM training_tracks WHERE id = ? FOR UPDATE')->execute([(int)$inv['track_id']]);
        $lk = $pdo->prepare('SELECT status, total_amount FROM invoices WHERE id = ? FOR UPDATE');
        $lk->execute([$id]);
        $locked = $lk->fetch();
        if (!$locked || $locked['status'] !== 'Draft') {
            $pdo->rollBack();
            flash('Invoice ' . $num . ' was not voided: only a Draft invoice can be voided, and this one ' . ($locked ? 'is ' . $locked['status'] . '.' : 'no longer exists.'), 'error');
            redirect('invoices.php#inv-list-h');
        }
        // The released work goes back at the track's CURRENT rates (the track row is
        // locked above), as every other unbilled log is priced: a rate corrected
        // while the work sat on this draft is then billed on the next invoice.
        $rp = $pdo->prepare('UPDATE work_logs w JOIN training_tracks t ON t.id = w.track_id
            SET w.hourly_rate = t.hourly_rate, w.mileage_rate = t.mileage_rate WHERE w.invoice_id = ?');
        $rp->execute([$id]);
        $repriced = $rp->rowCount();
        $wl = $pdo->prepare('UPDATE work_logs SET is_billed = 0, invoice_id = NULL WHERE invoice_id = ?');
        $wl->execute([$id]);
        $ex = $pdo->prepare('UPDATE track_expenses SET is_billed = 0, invoice_id = NULL WHERE invoice_id = ?');
        $ex->execute([$id]);
        $pdo->prepare("DELETE FROM invoices WHERE id = ? AND status = 'Draft'")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($user['id'], 'invoice.void_draft', 'invoice', $id, $num . ' ' . fmt_money((float)$locked['total_amount'])
        . ' (' . $wl->rowCount() . ' log(s), ' . $ex->rowCount() . ' expense(s) unbilled' . ($repriced ? ', ' . $repriced . ' log(s) re-priced to the track\'s current rates' : '') . ')');
    flash('Draft invoice ' . $num . ' (' . fmt_money((float)$locked['total_amount']) . ') was voided. Its '
        . $wl->rowCount() . ' work log entr' . ($wl->rowCount() === 1 ? 'y' : 'ies') . ' and ' . $ex->rowCount() . ' expense' . ($ex->rowCount() === 1 ? '' : 's')
        . ' are unbilled again, so they can be corrected on the track and invoiced again.'
        . ($repriced ? ' ' . $repriced . ' of those work log entr' . ($repriced === 1 ? 'y was' : 'ies were') . ' re-priced to the track\'s current rates.' : ''));
    redirect('invoices.php#inv-list-h');
}

/* Back to the list as it was filtered, beside this invoice. */
$rowUrl = fn(int $invId): string => invoice_list_url(invoice_list_filters($_POST, 'list_'), 1, 'inv-' . $invId);

/* Record a payment or a write-off (3.0.34): the amount, the date it was received
   and its reference. Any number of them, until the balance is zero (then Paid).
   Anyone with the billing permission may write off, with a reason (owner decision). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_payment'])) {
    $kind = ($_POST['kind'] ?? '') === 'writeoff' ? 'writeoff' : 'payment';
    $str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $amtRaw = $str('amount'); $ref = $str('reference'); $note = $str('note');
    $on = billing_date_in($str('received_on'), (string)($inv['submitted_at'] ?? $inv['created_at']));
    $amtCents = invoice_amount_cents($amtRaw);
    $errs = [];
    if ($amtRaw === '') $errs['pay_amount_' . $id] = 'Enter the amount, in dollars and cents.';
    elseif ($amtCents === null) $errs['pay_amount_' . $id] = '"' . mb_substr($amtRaw, 0, 30) . '" is not an amount. Enter it in dollars and cents, such as 250.00.';
    if ($on === null) $errs['pay_date_' . $id] = 'Enter the date it was ' . ($kind === 'payment' ? 'received' : 'written off') . ', from the day the invoice was submitted to today.';
    if (mb_strlen($ref) > 80) $errs['pay_ref_' . $id] = 'Keep the check or EFT reference to 80 characters or fewer.';
    if (mb_strlen($note) > 255) $errs['pay_note_' . $id] = 'Keep the note to 255 characters or fewer.';
    elseif ($kind === 'writeoff' && $note === '') $errs['pay_note_' . $id] = 'Give the reason for the write-off (for example, the agency paid the authorized rate only).';
    if (!$errs) {
        $why = invoice_apply($id, $kind, $amtCents / 100, $on, $ref, $note, (int)$user['id']);
        if ($why !== null) $errs['pay_amount_' . $id] = $why;
    }
    if ($errs) { keep_input(); keep_field_errors($errs); redirect($rowUrl($id)); }
    $after = db()->prepare('SELECT * FROM invoices WHERE id = ?'); $after->execute([$id]); $after = $after->fetch();
    $left = invoice_balance($after);
    flash(($kind === 'payment' ? 'Payment of ' : 'Write-off of ') . fmt_money($amtCents / 100) . ' recorded on invoice ' . $num . '. '
        . ($left > 0 ? fmt_money($left) . ' is still owed.' : 'It is settled and marked Paid.'));
    redirect($rowUrl($id));
}

/* Remove a payment or write-off entered by mistake (the reason is audited). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_payment'])) {
    $pid = (int)($_POST['payment_id'] ?? 0);
    $reason = is_string($_POST['rm_reason'] ?? null) ? trim($_POST['rm_reason']) : '';
    $belongs = db()->prepare('SELECT 1 FROM invoice_payments WHERE id = ? AND invoice_id = ?');
    $belongs->execute([$pid, $id]);
    $why = !$belongs->fetchColumn() ? 'That entry is not on invoice ' . $num . ' any more.' : (mb_strlen($reason) > 200 ? 'Keep the reason to 200 characters or fewer.' : invoice_payment_remove($pid, $reason, (int)$user['id']));
    if ($why !== null) { keep_input(); keep_field_errors(['rm_reason_' . $pid => $why]); redirect($rowUrl($id)); }
    flash('The entry was removed from invoice ' . $num . ', and its balance recalculated.');
    redirect($rowUrl($id));
}

/* A submitted invoice the funder denied, or one sent in error: it stays on record,
   and its work is released to be corrected and invoiced again. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_invoice'])) {
    $to = ($_POST['to'] ?? '') === 'Void' ? 'Void' : 'Denied';
    $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
    $why = mb_strlen($reason) > 255 ? 'Keep the reason to 255 characters or fewer.' : invoice_close($id, $to, $reason, (int)$user['id']);
    if ($why !== null) { keep_input(); keep_field_errors(['close_reason_' . $id => $why]); redirect($rowUrl($id)); }
    flash('Invoice ' . $num . ' is marked ' . $to . '. Its work is unbilled again: correct it on the training track, then generate a new invoice.');
    redirect($rowUrl($id));
}

/* A memo or purchase-order number on a Draft (printed on the invoice). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_memo'])) {
    $memo = is_string($_POST['memo'] ?? null) ? trim($_POST['memo']) : '';
    if (mb_strlen($memo) > 255) { keep_input(); keep_field_errors(['memo_' . $id => 'Keep the memo to 255 characters or fewer.']); redirect($rowUrl($id)); }
    $st = db()->prepare("UPDATE invoices SET notes = ? WHERE id = ? AND status = 'Draft'");
    $st->execute([$memo !== '' ? $memo : null, $id]);
    if ($st->rowCount() === 1) { audit($user['id'], 'invoice.memo', 'invoice', $id, $memo !== '' ? $memo : '(cleared)'); flash('Memo saved on invoice ' . $num . '.'); }
    else flash('Invoice ' . $num . ' was not changed: only a Draft takes a memo, or the memo was already the same.', 'info');
    redirect($rowUrl($id));
}

/* Mark a Draft submitted, on the date it was sent (its due date follows the terms). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (($_POST['to'] ?? '') !== 'Submitted') { flash('Choose a valid invoice status.', 'error'); redirect('invoices.php#inv-list-h'); }
    $raw = is_string($_POST['submitted_on'] ?? null) ? trim($_POST['submitted_on']) : '';
    $on = $raw === '' ? date('Y-m-d') : billing_date_in($raw, (string)$inv['created_at']);
    if ($on === null) { keep_input(); keep_field_errors(['submit_date_' . $id => 'Enter the date the invoice was sent, from the day it was created to today.']); redirect($rowUrl($id)); }
    if (invoice_submit($id, $on)) {
        audit($user['id'], 'invoice.submit', 'invoice', $id, 'submitted ' . $on);
        flash('Invoice ' . $num . ' marked submitted on ' . fmt_date($on) . '; it is due ' . fmt_date((string)db()->query('SELECT due_date FROM invoices WHERE id = ' . (int)$id)->fetchColumn()) . '.');
    } else {
        flash('Invoice ' . $num . ' was not changed: it is no longer a Draft, so someone may have already updated it. The list below shows its current status.', 'error');
    }
    redirect($rowUrl($id));
}

/* QuickBooks-friendly CSV export */
if (isset($_GET['export'])) {
    // Authorization and agency as captured on the invoice (older invoices: the track's).
    $ex = db()->prepare("SELECT i.*, t.title, COALESCE(i.auth_number, t.auth_number) AS auth_number,
            COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, c.name AS client_name
        FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
        WHERE 1=1" . $scopeCond . " ORDER BY i.created_at");
    $ex->execute($scopeArg);
    $rows = $ex->fetchAll();
    // A download of every client's billing is listed in the audit trail.
    audit($user['id'], 'invoice.export', 'invoice', null, 'QuickBooks CSV (' . count($rows) . ' invoices)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="invoices_quickbooks_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['InvoiceNo', 'Customer', 'InvoiceDate', 'DueDate', 'Item', 'Description', 'Amount', 'Status', 'PaidDate', 'PaidAmount', 'PaymentRef', 'WrittenOff', 'Balance', 'Memo']);
    foreach ($rows as $r) {
        write_csv_row($out, [
            csv_cell($r['invoice_number']),
            csv_cell($r['funding_agency']),
            date('m/d/Y', strtotime($r['created_at'])),
            $r['due_date'] ? date('m/d/Y', strtotime($r['due_date'])) : '',
            $r['bill_mode'] === 'private' ? 'AT Training Services (private pay)' : 'AT Training Services',
            csv_cell($r['client_name'] . ' — ' . $r['title'] . ' (Auth ' . $r['auth_number'] . ') — ' . number_format((float)$r['hours'], 2) . ' hrs'),
            number_format((float)$r['total_amount'], 2, '.', ''),
            $r['status'],
            $r['paid_at'] ? date('m/d/Y', strtotime($r['paid_at'])) : '',
            $r['paid_amount'] !== null ? number_format((float)$r['paid_amount'], 2, '.', '') : '',
            csv_cell($r['payment_ref'] ?? ''),
            number_format((float)$r['written_off'], 2, '.', ''),
            number_format(invoice_balance($r), 2, '.', ''),
            csv_cell((string)($r['notes'] ?? '')),
        ]);
    }
    fclose($out);
    exit;
}

$filters = invoice_list_filters($_GET);
$listFrom = " FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
    WHERE 1=1" . $scopeCond;
$listSql = "SELECT i.*, t.title AS track_title, COALESCE(i.auth_number, t.auth_number) AS auth_number,
        COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, c.name AS client_name, c.id AS client_id,
        DATEDIFF(CURDATE(), i.submitted_at) AS days_outstanding, DATEDIFF(CURDATE(), i.due_date) AS days_overdue" . $listFrom;
$filter = '';
$params = $scopeArg;
// ?id=N (search results link here) lists just that invoice, whatever its status
// or age, under the same billing permission and caseload scope as the list.
$onlyId = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
if ($onlyId > 0) { $filter = ' AND i.id = ?'; $params[] = $onlyId; $filters = invoice_list_filters([]); }
else $filter = invoice_filter_sql($filters, $params);
$statusFilter = $filters['status'];
$filtered = $filters !== invoice_list_filters([]);
// The agencies on the invoices this user can see, for the agency filter.
$fa = db()->prepare('SELECT DISTINCT COALESCE(i.funding_agency, t.funding_agency) AS agency' . $listFrom . ' ORDER BY agency');
$fa->execute($scopeArg);
$invoiceAgencies = array_values(array_filter($fa->fetchAll(PDO::FETCH_COLUMN), fn($a) => $a !== null && $a !== ''));
// 300 invoices to a page, newest first; the page says how many there are in all
// and links to the older ones, so none is silently left off.
$perPage = 300;
$cq = db()->prepare('SELECT COUNT(*)' . $listFrom . $filter);
$cq->execute($params);
$invTotal = (int)$cq->fetchColumn();
$pages = max(1, (int)ceil($invTotal / $perPage));
$page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? min(max(1, (int)$_GET['page']), $pages) : 1;
$stmt = db()->prepare($listSql . $filter . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$stmt->execute($params);
$invoices = $stmt->fetchAll();
$firstShown = $invoices ? ($page - 1) * $perPage + 1 : 0;
$lastShown = ($page - 1) * $perPage + count($invoices);
$pageUrl = fn(int $p): string => invoice_list_url($filters, $p);
// A refused payment entry (Mark paid, another payment or a corrected amount) on an
// invoice outside this page (or the filter) is listed first, so its error summary,
// field error and refill show.
$refusedId = (int)old_in('id');
$refusedKeys = array_map(fn($k) => $k . $refusedId, ['pay_amount_', 'pay_date_', 'pay_ref_', 'pay_note_', 'close_reason_', 'memo_', 'submit_date_']);
// A refused Remove names its payment entry: the invoice it belongs to is listed too.
$rmPid = (int)old_in('payment_id');
if ($rmPid > 0 && has_field_error('rm_reason_' . $rmPid)) $refusedKeys[] = 'rm_reason_' . $rmPid;
if ($refusedId > 0 && array_filter($refusedKeys, 'has_field_error')
    && !in_array($refusedId, array_map('intval', array_column($invoices, 'id')), true)) {
    $rs = db()->prepare($listSql . " AND i.id = ?");
    $rs->execute(array_merge($scopeArg, [$refusedId]));
    if ($row = $rs->fetch()) array_unshift($invoices, $row);
}

// Outstanding: submitted invoices awaiting payment, and invoices marked paid with
// less than their total (short-paid), at the balance still owed, so a short
// payment never drops out of the receivables.
$owedSql = 'i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off';
$ag = db()->prepare("SELECT COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, COUNT(*) n,
        SUM($owedSql) amt, SUM(COALESCE(i.paid_amount, 0) > 0) part_paid, MAX(DATEDIFF(CURDATE(), i.submitted_at)) oldest,
        SUM(CASE WHEN i.due_date < CURDATE() THEN $owedSql ELSE 0 END) overdue
    FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
    WHERE " . INVOICE_OPEN_SQL . $scopeCond . "
    GROUP BY COALESCE(i.funding_agency, t.funding_agency) ORDER BY amt DESC");
$ag->execute($scopeArg);
$aging = $ag->fetchAll();

// Same helper as the dashboard and the app, so all three show the same figure.
$unbilled = unbilled_totals($scoped ? $uid : null)['total'];

page_header('Invoices', $user, 'invoices');
?>
<h1>Invoices</h1>
<p><a href="vr_portal.php">VR portal: authorizations to accept and payment requests to enter in the agencies' portals</a></p>

<?php
// A refused batch limit names its field; the limits stay open to show it.
$batchErr = has_field_error('batch_until') || has_field_error('batch_agency');
if ($batchErr) echo form_error_summary(['batch_until' => 'Work up to and including', 'batch_agency' => 'Only this funding agency'], ['batch_until' => 'batch-until', 'batch_agency' => 'batch-agency']);
?>
<section class="card" aria-labelledby="batch-h">
  <h2 id="batch-h">Generate invoices</h2>
  <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?>
    <details<?= $batchErr || old_in('batch_until') !== '' || old_in('batch_agency') !== '' ? ' open' : '' ?>>
      <summary>Limit which unbilled work is invoiced (optional)</summary>
      <label for="batch-until">Work up to and including</label>
      <span class="hint" id="batch-until-hint">Only work logs and expenses dated on or before this date are invoiced, as YYYY-MM-DD. Leave blank for all unbilled work dated up to today.</span>
      <input type="date" id="batch-until" name="batch_until" value="<?= h(old_in('batch_until')) ?>" <?= field_aria('batch_until', 'batch-until-hint') ?>><?= field_error('batch_until') ?>
      <label for="batch-agency">Only this funding agency</label>
      <select id="batch-agency" name="batch_agency" <?= field_aria('batch_agency') ?>>
        <option value="">All agencies</option>
        <?php foreach ($unbilledAgencies as $a): ?><option value="<?= h($a) ?>"<?= old_in('batch_agency') === $a ? ' selected' : '' ?>><?= h($a) ?></option><?php endforeach; ?>
      </select><?= field_error('batch_agency') ?>
    </details>
    <div class="page-actions">
      <button type="submit" name="batch_generate" value="1" class="btn btn-primary"
        onclick="return confirm('Generate draft invoices for every track with unbilled work within the limits chosen (none chosen: all unbilled work)? This marks those hours as billed.');">
        Generate invoices for all unbilled work (<?= fmt_money((float)$unbilled) ?>)</button>
      <a class="btn btn-secondary" href="invoices.php?export=1">Export for QuickBooks (<abbr title="comma-separated values">CSV</abbr>)</a>
      <a class="btn btn-secondary" href="agency_report.php">Agency billing summary</a>
    </div>
  </form>
</section>

<?php if ($aging): ?>
<section class="card" aria-labelledby="aging-h">
  <h2 id="aging-h">Outstanding (submitted and not yet settled)</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="aging-cap">
  <table>
    <caption class="visually-hidden" id="aging-cap">Amounts still owed by funding agency, after payments and write-offs, and how much of it is past due</caption>
    <thead><tr><th scope="col">Funding agency</th><th scope="col" class="num">Invoices</th><th scope="col" class="num">Amount owed</th><th scope="col" class="num">Past due</th><th scope="col" class="num">Oldest (days)</th></tr></thead>
    <tbody>
    <?php foreach ($aging as $a): ?>
      <tr><td><?= h($a['funding_agency']) ?></td><td class="num"><?= (int)$a['n'] ?><?= (int)$a['part_paid'] > 0 ? ' (' . (int)$a['part_paid'] . ' part-paid)' : '' ?></td>
          <td class="num"><?= fmt_money((float)$a['amt']) ?></td><td class="num"><?= fmt_money((float)$a['overdue']) ?></td><td class="num"><?= (int)$a['oldest'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<form method="get" action="invoices.php" class="page-actions" role="search" aria-label="Filter invoices">
  <label for="status">Show</label>
  <select id="status" name="status">
    <option value="">All statuses</option>
    <?php foreach (INVOICE_STATUSES as $s): ?>
    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
    <?php endforeach; ?>
  </select>
  <label for="agency">Agency</label>
  <select id="agency" name="agency">
    <option value="">All agencies</option>
    <?php foreach ($invoiceAgencies as $a): ?><option value="<?= h($a) ?>"<?= $filters['agency'] === $a ? ' selected' : '' ?>><?= h($a) ?></option><?php endforeach; ?>
    <?php if ($filters['agency'] !== '' && !in_array($filters['agency'], $invoiceAgencies, true)): ?><option value="<?= h($filters['agency']) ?>" selected><?= h($filters['agency']) ?> (no invoices)</option><?php endif; ?>
  </select>
  <label for="month">Invoice month</label>
  <input type="month" id="month" name="month" value="<?= h($filters['month']) ?>" aria-describedby="month-hint">
  <span class="hint" id="month-hint">Year and month the invoice was created, as YYYY-MM.</span>
  <label for="q">Invoice number or client</label>
  <input type="search" id="q" name="q" value="<?= h($filters['q']) ?>" size="16" maxlength="120">
  <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
  <?php if ($filtered): ?><a href="invoices.php#inv-list-h">Clear filters</a><?php endif; ?>
</form>
<?php if ($onlyId > 0): ?>
<p><?= $invoices ? 'Showing one invoice.' : 'That invoice was not found, or it is not on your caseload.' ?> <a href="invoices.php">Show all invoices</a></p>
<?php endif; ?>

<p class="muted">Found a mistake after generating? Void the Draft: its work and expenses become unbilled again, so you can correct them on the track and invoice again. A submitted invoice the funder denied, or one sent in error, is marked Denied or Void instead: it stays on record, and its work is released to be invoiced again.</p>
<?php
// A refused entry names its field; the summary links to that row's field.
$payLabels = []; $payAnchors = [];
foreach ($invoices as $i) {
    $iid = (int)$i['id'];
    foreach (['pay_amount_' => ['Amount', 'pamt-'], 'pay_date_' => ['Date', 'pdate-'], 'pay_ref_' => ['Check or EFT reference', 'pref-'], 'pay_note_' => ['Note or reason', 'pnote-'],
              'close_reason_' => ['Reason', 'creason-'], 'memo_' => ['Memo', 'memo-'], 'submit_date_' => ['Date submitted', 'sdate-']] as $k => [$label, $anchor]) {
        $payLabels[$k . $iid] = $label; $payAnchors[$k . $iid] = $anchor . $iid;
    }
}
if ($rmPid > 0) { $payLabels['rm_reason_' . $rmPid] = 'Why remove it'; $payAnchors['rm_reason_' . $rmPid] = 'rm-' . $rmPid; }
if (array_filter(array_keys($payLabels), 'has_field_error')) echo form_error_summary($payLabels, $payAnchors);
$payDraftId = old_in('id');
$listHidden = invoice_list_hidden($filters);
// What the list shows, in words, for its heading and caption.
$listWhat = [];
if ($filters['status'] !== '') $listWhat[] = $filters['status'];
$listTitle = ($listWhat ? implode(' ', $listWhat) . ' invoices' : 'All invoices')
    . ($filters['agency'] !== '' ? ' for ' . $filters['agency'] : '')
    . ($filters['month'] !== '' ? ' created in ' . date('F Y', strtotime($filters['month'] . '-01')) : '')
    . ($filters['q'] !== '' ? ' matching "' . $filters['q'] . '"' : '');
// The drafts listed (the list is already caseload-scoped), for Mark all shown drafts submitted.
$draftIds = array_map(fn($i) => (int)$i['id'], array_filter($invoices, fn($i) => $i['status'] === 'Draft'));
// More invoices than one page: say which are shown, and link to the others.
$pageNote = $invTotal > $perPage && $onlyId === 0
    ? 'Showing invoices ' . $firstShown . ' to ' . $lastShown . ' of ' . $invTotal . ', newest first.' : '';
?>
<?php if ($pageNote !== ''): ?>
<nav aria-label="Invoice pages" class="page-actions">
  <p><?= h($pageNote) ?></p>
  <?php if ($page > 1): ?><a href="<?= h($pageUrl($page - 1)) ?>">Newer invoices<span class="visually-hidden"> (page <?= $page - 1 ?> of <?= $pages ?>)</span></a><?php endif; ?>
  <?php if ($page < $pages): ?><a href="<?= h($pageUrl($page + 1)) ?>">Older invoices<span class="visually-hidden"> (page <?= $page + 1 ?> of <?= $pages ?>)</span></a><?php endif; ?>
</nav>
<?php endif; ?>
<h2 id="inv-list-h"><?= $onlyId > 0 ? 'Requested invoice' : h($listTitle) ?></h2>
<?php if ($draftIds && $onlyId === 0): // The same transition as each row's Mark submitted, for the drafts listed below. ?>
<form method="post" action="invoices.php" class="page-actions"><?= csrf_field() ?><?= $listHidden ?>
  <input type="hidden" name="draft_ids" value="<?= implode(',', $draftIds) ?>">
  <button type="submit" name="submit_shown" value="1" class="btn btn-secondary"
    onclick="return confirm('Mark all <?= count($draftIds) ?> draft invoice<?= count($draftIds) === 1 ? '' : 's' ?> shown below as submitted to the agency? This starts the aging clock on each and cannot be undone.');">
    Mark all <?= count($draftIds) ?> shown draft<?= count($draftIds) === 1 ? '' : 's' ?> submitted</button>
</form>
<?php endif; ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Invoices list">
<table>
  <caption class="visually-hidden"><?= $onlyId > 0 ? 'The requested invoice' : h($listTitle) . ($pageNote !== '' ? ', ' . $firstShown . ' to ' . $lastShown . ' of ' . $invTotal : '') . ', newest first' ?></caption>
  <thead><tr>
    <th scope="col">Invoice #</th><th scope="col">Client / Track</th><th scope="col">Agency</th>
    <th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col">Dates</th>
    <th scope="col"><span class="visually-hidden">Actions</span></th>
  </tr></thead>
  <tbody>
  <?php if (!$invoices): ?><tr><td colspan="7" class="muted"><?= $onlyId > 0 ? 'No invoice to show.' : 'No invoices yet. Use the button above, or generate one from a training track page.' ?></td></tr><?php endif; ?>
  <?php foreach ($invoices as $i): $refill = $payDraftId === (string)$i['id']; // refill only the row that was refused ?>
    <tr id="inv-<?= (int)$i['id'] ?>">
      <td><?= h($i['invoice_number']) ?></td>
      <?php // A billing-only group cannot open client records, so the name is not a link for it. ?>
      <td><?= can('clients') ? '<a href="client.php?id=' . (int)$i['client_id'] . '">' . h($i['client_name']) . '</a>' : h($i['client_name']) ?><br>
          <span class="muted"><?= h($i['track_title']) ?> · Authorization <?= h($i['auth_number']) ?></span></td>
      <td><?= h($i['funding_agency']) ?></td>
      <td class="num"><?= fmt_money((float)$i['total_amount']) ?><br><span class="muted"><?= fmt_hours((float)$i['hours']) ?></span></td>
      <td><?php $bm = ['Draft' => 'badge-pending', 'Submitted' => 'badge-active', 'Paid' => 'badge-closed', 'Denied' => 'badge-pending', 'Void' => 'badge-closed'];
          $iid = (int)$i['id']; $bal = invoice_balance($i); $invName = '<span class="visually-hidden"> — invoice ' . h($i['invoice_number']) . '</span>'; ?>
          <span class="badge <?= $bm[$i['status']] ?? 'badge-pending' ?>"><?= h($i['status']) ?></span>
          <?php if (in_array($i['status'], ['Submitted', 'Paid'], true) && $bal > 0): ?>
            <br><span class="muted"><?= fmt_money($bal) ?> owed<?= (float)($i['paid_amount'] ?? 0) > 0 ? ' (part-paid)' : '' ?><?= $i['days_overdue'] !== null && (int)$i['days_overdue'] > 0 ? ', ' . (int)$i['days_overdue'] . ' days past due' : '' ?></span>
          <?php elseif (in_array($i['status'], ['Denied', 'Void'], true) && $i['closed_reason']): ?>
            <br><span class="muted"><?= h($i['closed_reason']) ?></span>
          <?php endif; ?></td>
      <td class="muted">Created <?= fmt_date($i['created_at']) ?>
          <?= $i['submitted_at'] ? '<br>Submitted ' . fmt_date($i['submitted_at']) : '' ?>
          <?= $i['due_date'] && in_array($i['status'], ['Submitted', 'Paid'], true) ? '<br>Due ' . fmt_date($i['due_date']) : '' ?>
          <?= $i['paid_at'] ? '<br>Last payment ' . fmt_date($i['paid_at']) : '' ?></td>
      <td>
        <a class="btn btn-secondary btn-sm" href="invoice_pdf.php?invoice=<?= $iid ?>&amp;format=html">Accessible HTML<?= $invName ?></a>
        <a class="btn btn-secondary btn-sm" href="invoice_pdf.php?invoice=<?= $iid ?>">PDF<?= $invName ?></a>
        <?php if ($i['status'] === 'Draft'): ?>
        <form method="post" action="invoices.php" class="inline-form" novalidate><?= csrf_field() ?><?= $listHidden ?>
          <input type="hidden" name="id" value="<?= $iid ?>"><input type="hidden" name="to" value="Submitted">
          <label for="sdate-<?= $iid ?>">Date submitted<?= $invName ?></label>
          <input type="date" id="sdate-<?= $iid ?>" name="submitted_on" max="<?= date('Y-m-d') ?>" value="<?= h($refill && old_in('submitted_on') !== '' ? old_in('submitted_on') : date('Y-m-d')) ?>" <?= field_aria('submit_date_' . $iid) ?>><?= field_error('submit_date_' . $iid) ?>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm('Mark invoice <?= h($i['invoice_number']) ?> as submitted to the funder? Its due date is set from the payment terms.');">Mark submitted<?= $invName ?></button>
        </form>
        <form method="post" action="invoices.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $iid ?>">
          <button type="submit" name="void_draft" value="1" class="btn btn-danger btn-sm"
            onclick="return confirm('Void draft invoice <?= h($i['invoice_number']) ?>? It is removed, and its work and expenses become unbilled again.');">Void draft<?= $invName ?></button>
        </form>
        <details<?= $refill && has_field_error('memo_' . $iid) ? ' open' : '' ?>>
          <summary>Memo or PO number<?= $invName ?></summary>
          <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?><?= $listHidden ?>
            <input type="hidden" name="id" value="<?= $iid ?>">
            <label for="memo-<?= $iid ?>">Memo or purchase-order number, printed on the invoice<?= $invName ?></label>
            <input type="text" id="memo-<?= $iid ?>" name="memo" maxlength="255" value="<?= h($refill && old_in('save_memo') !== '' ? old_in('memo') : (string)($i['notes'] ?? '')) ?>" <?= field_aria('memo_' . $iid) ?>><?= field_error('memo_' . $iid) ?>
            <button type="submit" name="save_memo" value="1" class="btn btn-secondary btn-sm">Save memo<?= $invName ?></button>
          </form>
        </details>
        <?php elseif (in_array($i['status'], ['Submitted', 'Paid'], true)):
          $pays = db()->prepare('SELECT p.*, u.name AS who FROM invoice_payments p LEFT JOIN users u ON u.id = p.recorded_by WHERE p.invoice_id = ? ORDER BY p.received_on, p.id');
          $pays->execute([$iid]); $pays = $pays->fetchAll();
          $payOpen = $refill && array_filter(['pay_amount_', 'pay_date_', 'pay_ref_', 'pay_note_'], fn($k) => has_field_error($k . $iid));
          $rmOpen = $refill && old_in('remove_payment') !== '';
          $closeOpen = $refill && has_field_error('close_reason_' . $iid); ?>
          <?php if ($bal > 0): ?>
          <details<?= $payOpen ? ' open' : '' ?>>
          <summary>Record a payment or write-off<?= $invName ?></summary>
          <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?><?= $listHidden ?>
            <input type="hidden" name="id" value="<?= $iid ?>">
            <fieldset><legend>Record<?= $invName ?></legend>
              <?php $k = $refill && old_in('apply_payment') !== '' ? old_in('kind') : 'payment'; ?>
              <label><input type="radio" name="kind" value="payment"<?= $k !== 'writeoff' ? ' checked' : '' ?>> A payment received</label>
              <label><input type="radio" name="kind" value="writeoff"<?= $k === 'writeoff' ? ' checked' : '' ?>> A write-off (the rest will not be paid)</label>
            </fieldset>
            <label for="pamt-<?= $iid ?>">Amount in dollars<?= $invName ?></label>
            <input type="text" inputmode="decimal" autocomplete="off" id="pamt-<?= $iid ?>" name="amount" size="10" maxlength="20" value="<?= h($refill && old_in('apply_payment') !== '' ? old_in('amount') : number_format($bal, 2, '.', '')) ?>" <?= field_aria('pay_amount_' . $iid) ?>><?= field_error('pay_amount_' . $iid) ?>
            <label for="pdate-<?= $iid ?>">Date received or written off<?= $invName ?></label>
            <input type="date" id="pdate-<?= $iid ?>" name="received_on" max="<?= date('Y-m-d') ?>" value="<?= h($refill && old_in('apply_payment') !== '' ? old_in('received_on') : date('Y-m-d')) ?>" <?= field_aria('pay_date_' . $iid) ?>><?= field_error('pay_date_' . $iid) ?>
            <label for="pref-<?= $iid ?>">Check or <abbr title="electronic funds transfer">EFT</abbr> reference<?= $invName ?></label>
            <input type="text" id="pref-<?= $iid ?>" name="reference" size="12" maxlength="80" value="<?= $refill ? h(old_in('reference')) : '' ?>" <?= field_aria('pay_ref_' . $iid) ?>><?= field_error('pay_ref_' . $iid) ?>
            <label for="pnote-<?= $iid ?>">Note (the reason, for a write-off)<?= $invName ?></label>
            <input type="text" id="pnote-<?= $iid ?>" name="note" maxlength="255" value="<?= $refill ? h(old_in('note')) : '' ?>" <?= field_aria('pay_note_' . $iid) ?>><?= field_error('pay_note_' . $iid) ?>
            <button type="submit" name="apply_payment" value="1" class="btn btn-secondary btn-sm">Record<?= $invName ?></button>
          </form>
          </details>
          <?php endif; ?>
          <?php if ($pays): ?>
          <details<?= $rmOpen ? ' open' : '' ?>>
          <summary>Payments and write-offs (<?= count($pays) ?>)<?= $invName ?></summary>
          <ul>
            <?php foreach ($pays as $p): $pid = (int)$p['id']; ?>
            <li><?= $p['kind'] === 'payment' ? 'Payment' : 'Write-off' ?> of <?= fmt_money((float)$p['amount']) ?> on <?= fmt_date($p['received_on']) ?><?= $p['reference'] ? ', ref ' . h($p['reference']) : '' ?><?= $p['note'] ? ': ' . h($p['note']) : '' ?><?= $p['who'] ? ' (recorded by ' . h($p['who']) . ')' : '' ?>
              <?php if (($p['qbo_id'] ?? null) !== null && (string)$p['qbo_id'] !== '' && invoice_qbo_checked($i)): /* 4.27.0: only while the sync checks it */ ?>. It is in QuickBooks: to remove it, void it there, and it is removed here at the next sync.</li>
              <?php continue; endif; ?>
              <form method="post" action="invoices.php" class="inline-form" novalidate><?= csrf_field() ?><?= $listHidden ?>
                <input type="hidden" name="id" value="<?= $iid ?>"><input type="hidden" name="payment_id" value="<?= $pid ?>">
                <label for="rm-<?= $pid ?>">Why remove it<span class="visually-hidden">, the <?= $p['kind'] === 'payment' ? 'payment' : 'write-off' ?> of <?= fmt_money((float)$p['amount']) ?></span></label>
                <input type="text" id="rm-<?= $pid ?>" name="rm_reason" maxlength="200" value="<?= $refill && (int)old_in('payment_id') === $pid ? h(old_in('rm_reason')) : '' ?>" <?= field_aria('rm_reason_' . $pid) ?>><?= field_error('rm_reason_' . $pid) ?>
                <button type="submit" name="remove_payment" value="1" class="btn btn-danger btn-sm">Remove<span class="visually-hidden"> the <?= $p['kind'] === 'payment' ? 'payment' : 'write-off' ?> of <?= fmt_money((float)$p['amount']) ?></span></button>
              </form></li>
            <?php endforeach; ?>
          </ul>
          </details>
          <?php endif; ?>
          <?php if ($i['status'] === 'Submitted' && !$pays): ?>
          <details<?= $closeOpen ? ' open' : '' ?>>
          <summary>Denied or sent in error<?= $invName ?></summary>
          <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?><?= $listHidden ?>
            <input type="hidden" name="id" value="<?= $iid ?>">
            <fieldset><legend>Mark it<?= $invName ?></legend>
              <label><input type="radio" name="to" value="Denied" checked> Denied by the funder</label>
              <label><input type="radio" name="to" value="Void"> Void (sent in error)</label>
            </fieldset>
            <label for="creason-<?= $iid ?>">Reason<?= $invName ?></label>
            <input type="text" id="creason-<?= $iid ?>" name="reason" maxlength="255" value="<?= $refill && old_in('close_invoice') !== '' ? h(old_in('reason')) : '' ?>" <?= field_aria('close_reason_' . $iid) ?>><?= field_error('close_reason_' . $iid) ?>
            <button type="submit" name="close_invoice" value="1" class="btn btn-danger btn-sm"
              onclick="return confirm('Close invoice <?= h($i['invoice_number']) ?>? It stays on record, and its work becomes unbilled again so it can be corrected and invoiced again.');">Close the invoice<?= $invName ?></button>
          </form>
          </details>
          <?php endif; ?>
        <?php else: ?>
          <span class="muted">Closed; its work was released to be invoiced again.</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php $bq = db()->prepare("SELECT i.id, i.invoice_number, i.total_amount, c.name AS client_name FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id WHERE i.status = 'Draft'" . $scopeCond . ' ORDER BY i.created_at LIMIT 200');
$bq->execute($scopeArg); $drafts = $bq->fetchAll(); if (count($drafts) > 1): ?>
<details><summary>Mark several drafts submitted</summary>
  <form method="post" action="invoices.php" class="stacked"><?= csrf_field() ?>
    <fieldset><legend>Draft invoices</legend>
    <?php foreach ($drafts as $d): ?><label><input type="checkbox" name="bulk_ids[]" value="<?= (int)$d['id'] ?>"> <?= h($d['invoice_number']) ?>, <?= h($d['client_name']) ?>, <?= h(fmt_money((float)$d['total_amount'])) ?></label><?php endforeach; ?>
    </fieldset>
    <div class="field"><label for="bulk_submitted_on">Date they were sent</label><input type="date" id="bulk_submitted_on" name="bulk_submitted_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
    <button type="submit" name="bulk_submit" value="1" class="btn btn-secondary">Mark the chosen invoices submitted</button>
  </form>
</details>
<?php endif; ?>
<?php page_footer();
