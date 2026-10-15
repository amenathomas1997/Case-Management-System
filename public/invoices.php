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
    $status = in_array($get('status'), ['Draft', 'Submitted', 'Paid'], true) ? $get('status') : '';
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
    WHERE t.funding_agency <> '' AND (EXISTS (SELECT 1 FROM work_logs w WHERE w.track_id = t.id AND w.is_billed = 0)
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
        WHERE (w.id IS NOT NULL OR e.id IS NOT NULL)" . ($agency !== '' ? ' AND t.funding_agency = ?' : '') . $scopeCond);
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
        $st = db()->prepare("UPDATE invoices SET status='Submitted', submitted_at=CURDATE() WHERE id=? AND status='Draft'");
        $st->execute([$id]);
        if ($st->rowCount() === 1) { audit($user['id'], 'invoice.submit', 'invoice', $id, null); $changed++; }
        else $skipped++;
    }
    $msg = $changed . ' draft invoice' . ($changed === 1 ? ' was' : 's were') . ' marked submitted.';
    if ($skipped) $msg .= ' ' . $skipped . ' ' . ($skipped === 1 ? 'was' : 'were') . ' not changed: no longer a Draft (someone may have already updated or voided ' . ($skipped === 1 ? 'it' : 'them') . ').';
    if ($notOwn) $msg .= ' ' . $notOwn . ' ' . ($notOwn === 1 ? 'is' : 'are') . ' not on your caseload and ' . ($notOwn === 1 ? 'was' : 'were') . ' left as ' . ($notOwn === 1 ? 'it was' : 'they were') . '.';
    flash($msg, $changed ? 'success' : 'error');
    redirect($listUrl);
}

/* Status transitions, voiding a draft and payments on a Paid invoice share the caseload check */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['set_status']) || isset($_POST['void_draft'])
        || isset($_POST['add_payment']) || isset($_POST['correct_payment']))) {
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
    $cur = db()->prepare('SELECT invoice_number, track_id, total_amount FROM invoices WHERE id = ?');
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

/* A Paid invoice: record a further payment toward a short-paid balance, or correct
   the amount paid (a mistyped figure). Never more than the invoice total. The
   latest payment's reference (and, for a further payment, its date) is stored, and
   every change is audited with the amounts. A write-off is not offered here. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_payment']) || isset($_POST['correct_payment']))) {
    $adding = isset($_POST['add_payment']);
    $pfx = $adding ? 'add_' : 'fix_';
    $ref = is_string($_POST['payment_ref'] ?? null) ? trim($_POST['payment_ref']) : '';
    $amtRaw = is_string($_POST['paid_amount'] ?? null) ? trim($_POST['paid_amount']) : '';
    // Back to the list as it was filtered, beside this row.
    $listUrl = invoice_list_url(invoice_list_filters($_POST, 'list_'), 1, 'inv-' . $id);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lk = $pdo->prepare('SELECT status, total_amount, paid_amount, payment_ref FROM invoices WHERE id = ? FOR UPDATE');
        $lk->execute([$id]);
        $locked = $lk->fetch();
        if (!$locked || $locked['status'] !== 'Paid') {
            $pdo->rollBack();
            flash('Invoice ' . $num . ' was not changed: ' . ($locked ? 'it is ' . $locked['status'] . ', and payments are added or corrected here only once it is marked paid.' : 'it no longer exists.'), 'error');
            redirect('invoices.php#inv-list-h');
        }
        $totalCents = (int)round((float)$locked['total_amount'] * 100);
        // An older Paid invoice with no amount recorded was paid in full.
        $paidCents = $locked['paid_amount'] !== null ? (int)round((float)$locked['paid_amount'] * 100) : $totalCents;
        $owedCents = max(0, $totalCents - $paidCents);
        $amtCents = $amtRaw === '' ? null : invoice_amount_cents($amtRaw);
        $errs = [];
        $amtKey = $pfx . 'paid_amount_' . $id;
        if (mb_strlen($ref) > 80) $errs[$pfx . 'payment_ref_' . $id] = 'Keep the check or EFT reference to 80 characters or fewer.';
        if ($amtRaw === '') {
            $errs[$amtKey] = $adding ? 'Enter the amount of this payment, up to the ' . fmt_money($owedCents / 100) . ' still owed.'
                : 'Enter the total amount paid on this invoice so far, up to its total of ' . fmt_money($totalCents / 100) . '.';
        } elseif ($amtCents === null) {
            $errs[$amtKey] = '"' . mb_substr($amtRaw, 0, 30) . '" is not an amount. Enter it in dollars and cents, such as 250.00.';
        } elseif ($amtCents <= 0) {
            $errs[$amtKey] = 'The amount must be more than $0.00.';
        } elseif ($adding && $amtCents > $owedCents) {
            $errs[$amtKey] = 'This payment (' . fmt_money($amtCents / 100) . ') is more than the ' . fmt_money($owedCents / 100) . ' still owed on this invoice. Check the amount, or correct the amount paid instead.';
        } elseif (!$adding && $amtCents > $totalCents) {
            $errs[$amtKey] = 'The amount paid (' . fmt_money($amtCents / 100) . ') is more than the invoice total of ' . fmt_money($totalCents / 100) . '. Check the amount.';
        }
        if ($errs) {
            $pdo->rollBack();
            keep_input();
            keep_field_errors($errs);
            redirect($listUrl);
        }
        $newCents = $adding ? $paidCents + $amtCents : $amtCents;
        $newRef = $ref !== '' ? $ref : $locked['payment_ref'];
        if (!$adding && $newCents === $paidCents && $newRef === $locked['payment_ref']) {
            $pdo->rollBack();
            flash('Invoice ' . $num . ' already shows ' . fmt_money($paidCents / 100) . ' paid, so nothing was changed.', 'info');
            redirect($listUrl);
        }
        $newPaid = number_format($newCents / 100, 2, '.', '');
        $pdo->prepare('UPDATE invoices SET paid_amount = ?, payment_ref = ?' . ($adding ? ', paid_at = CURDATE()' : '') . " WHERE id = ? AND status = 'Paid'")
            ->execute([$newPaid, $newRef, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $ofTotal = ' of ' . fmt_money($totalCents / 100);
    audit($user['id'], $adding ? 'invoice.payment_add' : 'invoice.payment_correct', 'invoice', $id,
        ($adding ? '+' . fmt_money($amtCents / 100) . ', now ' . fmt_money($newCents / 100) : 'paid ' . fmt_money($paidCents / 100) . ' -> ' . fmt_money($newCents / 100))
        . $ofTotal . ($ref !== '' ? '; ref ' . $ref : ''));
    $stillOwed = $totalCents - $newCents;
    flash(($adding ? 'Payment of ' . fmt_money($amtCents / 100) . ' recorded on invoice ' . $num . ': '
            : 'Invoice ' . $num . ' corrected: ')
        . fmt_money($newCents / 100) . $ofTotal . ' paid. '
        . ($stillOwed > 0 ? fmt_money($stillOwed / 100) . ' is still owed.' : 'It is paid in full.'));
    redirect($listUrl);
}

/* Status transitions */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $to = $_POST['to'] ?? '';
    if ($to === 'Submitted') {
        $st = db()->prepare("UPDATE invoices SET status='Submitted', submitted_at=CURDATE() WHERE id=? AND status='Draft'");
        $st->execute([$id]);
        $action = 'invoice.submit'; $detail = null; $from = 'Draft';
    } elseif ($to === 'Paid') {
        $ref = is_string($_POST['payment_ref'] ?? null) ? trim($_POST['payment_ref']) : '';
        $amtRaw = is_string($_POST['paid_amount'] ?? null) ? trim($_POST['paid_amount']) : '';
        $paidFull = ($_POST['paid_full'] ?? '') === '1';
        // Refuse a bad amount or reference (naming the field) rather than storing $0.00,
        // the full total by default, or cutting it short. A leading $ and thousands
        // commas are accepted as typed; a blank amount means the full total ONLY
        // when "Paid in full" is ticked.
        $pErrs = [];
        if (mb_strlen($ref) > 80) $pErrs['payment_ref_' . $id] = 'Keep the check or EFT reference to 80 characters or fewer.';
        $amt = preg_replace('/^\$\s*/', '', $amtRaw);
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/D', $amt)) $amt = str_replace(',', '', $amt);
        $totalCents = (int)round((float)$inv['total_amount'] * 100);
        $amtCents = null;
        if ($amtRaw === '') {
            if (!$paidFull) $pErrs['paid_amount_' . $id] = 'Enter the amount paid, or tick Paid in full to record the full invoice total of ' . fmt_money($totalCents / 100) . '.';
        } elseif (!preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $amt)) {
            $pErrs['paid_amount_' . $id] = '"' . mb_substr($amtRaw, 0, 30) . '" is not an amount. Enter the amount paid in dollars and cents, such as 1250.00.';
        } else {
            $amtCents = (int)round((float)$amt * 100);
            if ($amtCents <= 0) {
                $pErrs['paid_amount_' . $id] = 'The amount paid must be more than $0.00.';
            } elseif ($amtCents > $totalCents) {
                $pErrs['paid_amount_' . $id] = 'The amount paid (' . fmt_money($amtCents / 100) . ') is more than the invoice total of ' . fmt_money($totalCents / 100) . '. Check the amount.';
            } elseif ($paidFull && $amtCents !== $totalCents) {
                $pErrs['paid_amount_' . $id] = 'Paid in full is ticked, but the amount paid (' . fmt_money($amtCents / 100) . ') is less than the invoice total of ' . fmt_money($totalCents / 100) . '. Untick Paid in full or correct the amount.';
            }
        }
        if ($pErrs) {
            keep_input();
            keep_field_errors($pErrs);
            // Back to the list as it was filtered; the refused row is always listed (below).
            redirect(invoice_list_url(invoice_list_filters($_POST, 'list_'), 1, 'inv-' . $id));
        }
        $st = db()->prepare("UPDATE invoices SET status='Paid', paid_at=CURDATE(), payment_ref=?, paid_amount=COALESCE(?, total_amount) WHERE id=? AND status='Submitted'");
        $st->execute([$ref !== '' ? $ref : null, $amtCents === null ? null : number_format($amtCents / 100, 2, '.', ''), $id]);
        $action = 'invoice.paid'; $from = 'Submitted';
        $detail = ($ref !== '' ? "ref {$ref}; " : '') . 'paid ' . fmt_money(($amtCents ?? $totalCents) / 100);
    } else {
        flash('Choose a valid invoice status.', 'error');
        redirect('invoices.php#inv-list-h');
    }
    // Only a row that actually changed is audited and reported as done; a stale
    // page (someone else already moved it) gets told what happened instead.
    if ($st->rowCount() === 1) {
        audit($user['id'], $action, 'invoice', $id, $detail);
        flash('Invoice ' . $num . ' marked ' . strtolower($to) . '.');
    } else {
        flash('Invoice ' . $num . ' was not changed: it is no longer ' . $from . ', so someone may have already updated it. The list below shows its current status.', 'error');
    }
    redirect('invoices.php#inv-list-h');
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
    write_csv_row($out, ['InvoiceNo', 'Customer', 'InvoiceDate', 'DueDate', 'Item', 'Description', 'Amount', 'Status', 'PaidDate', 'PaidAmount', 'PaymentRef']);
    foreach ($rows as $r) {
        write_csv_row($out, [
            csv_cell($r['invoice_number']),
            csv_cell($r['funding_agency']),
            date('m/d/Y', strtotime($r['created_at'])),
            $r['submitted_at'] ? date('m/d/Y', strtotime($r['submitted_at'] . ' +30 days')) : '',
            'AT Training Services',
            csv_cell($r['client_name'] . ' — ' . $r['title'] . ' (Auth ' . $r['auth_number'] . ') — ' . number_format((float)$r['hours'], 2) . ' hrs'),
            number_format((float)$r['total_amount'], 2, '.', ''),
            $r['status'],
            $r['paid_at'] ? date('m/d/Y', strtotime($r['paid_at'])) : '',
            $r['paid_amount'] !== null ? number_format((float)$r['paid_amount'], 2, '.', '') : '',
            csv_cell($r['payment_ref'] ?? ''),
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
        DATEDIFF(CURDATE(), i.submitted_at) AS days_outstanding" . $listFrom;
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
$refusedKeys = array_map(fn($k) => $k . $refusedId, ['paid_amount_', 'payment_ref_', 'add_paid_amount_', 'add_payment_ref_', 'fix_paid_amount_', 'fix_payment_ref_']);
if ($refusedId > 0 && array_filter($refusedKeys, 'has_field_error')
    && !in_array($refusedId, array_map('intval', array_column($invoices, 'id')), true)) {
    $rs = db()->prepare($listSql . " AND i.id = ? AND i.status IN ('Submitted','Paid')");
    $rs->execute(array_merge($scopeArg, [$refusedId]));
    if ($row = $rs->fetch()) array_unshift($invoices, $row);
}

// Outstanding: submitted invoices awaiting payment, and invoices marked paid with
// less than their total (short-paid), at the balance still owed, so a short
// payment never drops out of the receivables.
$ag = db()->prepare("SELECT COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, COUNT(*) n,
        SUM(i.total_amount - COALESCE(i.paid_amount, 0)) amt, SUM(i.status = 'Paid') short_paid, MAX(DATEDIFF(CURDATE(), i.submitted_at)) oldest
    FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
    WHERE (i.status = 'Submitted' OR (i.status = 'Paid' AND i.paid_amount IS NOT NULL AND i.paid_amount < i.total_amount))" . $scopeCond . "
    GROUP BY COALESCE(i.funding_agency, t.funding_agency) ORDER BY amt DESC");
$ag->execute($scopeArg);
$aging = $ag->fetchAll();

// Same helper as the dashboard and the app, so all three show the same figure.
$unbilled = unbilled_totals($scoped ? $uid : null)['total'];

page_header('Invoices', $user, 'invoices');
?>
<h1>Invoices</h1>

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
  <h2 id="aging-h">Outstanding (submitted and awaiting payment, or short-paid)</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="aging-cap">
  <table>
    <caption class="visually-hidden" id="aging-cap">Amounts still owed by funding agency: submitted invoices awaiting payment, and the unpaid balance of short-paid invoices</caption>
    <thead><tr><th scope="col">Funding agency</th><th scope="col" class="num">Invoices</th><th scope="col" class="num">Amount owed</th><th scope="col" class="num">Oldest (days)</th></tr></thead>
    <tbody>
    <?php foreach ($aging as $a): ?>
      <tr><td><?= h($a['funding_agency']) ?></td><td class="num"><?= (int)$a['n'] ?><?= (int)$a['short_paid'] > 0 ? ' (' . (int)$a['short_paid'] . ' short-paid)' : '' ?></td>
          <td class="num"><?= fmt_money((float)$a['amt']) ?></td><td class="num"><?= (int)$a['oldest'] ?></td></tr>
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
    <?php foreach (['Draft','Submitted','Paid'] as $s): ?>
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

<p class="muted">Found a mistake after generating? Void the Draft invoice: its work and expenses become unbilled again, so you can correct them on the track and invoice again. Only a Draft can be voided — once an invoice is marked submitted or paid it has gone to the funder and cannot be voided here.</p>
<?php
// A refused payment entry names its field; the summary links to that row's field.
$payLabels = []; $payAnchors = [];
foreach ($invoices as $i) {
    if ($i['status'] === 'Submitted') {
        $payLabels['payment_ref_' . $i['id']] = 'Check or EFT reference';
        $payLabels['paid_amount_' . $i['id']] = 'Amount paid';
        $payAnchors['payment_ref_' . $i['id']] = 'ref-' . $i['id'];
        $payAnchors['paid_amount_' . $i['id']] = 'amt-' . $i['id'];
    } elseif ($i['status'] === 'Paid') {
        $payLabels['add_payment_ref_' . $i['id']] = 'Check or EFT reference for this payment';
        $payLabels['add_paid_amount_' . $i['id']] = 'Amount of this payment';
        $payLabels['fix_paid_amount_' . $i['id']] = 'Total amount paid';
        $payLabels['fix_payment_ref_' . $i['id']] = 'Check or EFT reference';
        $payAnchors['add_payment_ref_' . $i['id']] = 'addref-' . $i['id'];
        $payAnchors['add_paid_amount_' . $i['id']] = 'addamt-' . $i['id'];
        $payAnchors['fix_paid_amount_' . $i['id']] = 'fixamt-' . $i['id'];
        $payAnchors['fix_payment_ref_' . $i['id']] = 'fixref-' . $i['id'];
    }
}
if ($payLabels) echo form_error_summary($payLabels, $payAnchors);
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
      <td><?php $bm = ['Draft' => 'badge-pending', 'Submitted' => 'badge-active', 'Paid' => 'badge-closed']; ?>
          <span class="badge <?= $bm[$i['status']] ?>"><?= h($i['status']) ?></span>
          <?php if ($i['status'] === 'Submitted' && $i['days_outstanding'] !== null): ?>
            <br><span class="muted"><?= (int)$i['days_outstanding'] ?> days outstanding</span>
          <?php elseif ($i['status'] === 'Paid' && $i['paid_amount'] !== null && (float)$i['paid_amount'] < (float)$i['total_amount']): ?>
            <br><span class="muted">Short-paid: <?= fmt_money(round((float)$i['total_amount'] - (float)$i['paid_amount'], 2)) ?> still owed</span>
          <?php endif; ?></td>
      <td class="muted">Created <?= fmt_date($i['created_at']) ?>
          <?= $i['submitted_at'] ? '<br>Submitted ' . fmt_date($i['submitted_at']) : '' ?>
          <?= $i['paid_at'] ? '<br>Paid ' . fmt_date($i['paid_at']) : '' ?></td>
      <td>
        <a class="btn btn-secondary btn-sm" href="invoice_pdf.php?invoice=<?= (int)$i['id'] ?>&amp;format=html">Accessible HTML<span class="visually-hidden"> for invoice <?= h($i['invoice_number']) ?></span></a>
        <a class="btn btn-secondary btn-sm" href="invoice_pdf.php?invoice=<?= (int)$i['id'] ?>">PDF<span class="visually-hidden"> for invoice <?= h($i['invoice_number']) ?></span></a>
        <?php if ($i['status'] === 'Draft'): ?>
        <form method="post" action="invoices.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="to" value="Submitted">
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm('Mark invoice <?= h($i['invoice_number']) ?> as submitted to the agency? This starts the aging clock and cannot be undone.');">Mark submitted<span class="visually-hidden"> — invoice <?= h($i['invoice_number']) ?></span></button>
        </form>
        <form method="post" action="invoices.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
          <button type="submit" name="void_draft" value="1" class="btn btn-danger btn-sm"
            onclick="return confirm('Void draft invoice <?= h($i['invoice_number']) ?>? It is removed, and its work and expenses become unbilled again.');">Void draft<span class="visually-hidden"> — invoice <?= h($i['invoice_number']) ?></span></button>
        </form>
        <?php elseif ($i['status'] === 'Submitted'): ?>
        <?php // The payment fields sit behind a disclosure, so the list stays short; it is open
              // when this row's entry was refused. Visible labels and hint (a placeholder vanishes
              // once typing starts); the checkbox sits inside its label so the label's 44px target applies.
              $payOpen = $refill || has_field_error('paid_amount_' . (int)$i['id']) || has_field_error('payment_ref_' . (int)$i['id']); ?>
        <details<?= $payOpen ? ' open' : '' ?>>
        <summary>Record payment<span class="visually-hidden"> — invoice <?= h($i['invoice_number']) ?></span></summary>
        <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?><?= $listHidden ?>
          <input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="to" value="Paid">
          <label for="ref-<?= (int)$i['id'] ?>">Check or electronic funds transfer (<abbr title="electronic funds transfer">EFT</abbr>) reference<span class="visually-hidden"> for invoice <?= h($i['invoice_number']) ?></span></label>
          <input type="text" id="ref-<?= (int)$i['id'] ?>" name="payment_ref" size="10" maxlength="80" value="<?= $refill ? h(old_in('payment_ref')) : '' ?>" <?= field_aria('payment_ref_' . (int)$i['id']) ?>><?= field_error('payment_ref_' . (int)$i['id']) ?>
          <label for="amt-<?= (int)$i['id'] ?>">Amount paid (<span aria-hidden="true">$</span><span class="visually-hidden">in dollars</span>)<span class="visually-hidden"> for invoice <?= h($i['invoice_number']) ?></span></label>
          <span class="hint" id="amt-hint-<?= (int)$i['id'] ?>">Dollars and cents, e.g., 1250.00. Leave blank only when Paid in full is ticked.</span>
          <input type="text" inputmode="decimal" autocomplete="off" id="amt-<?= (int)$i['id'] ?>" name="paid_amount" size="10" maxlength="20" value="<?= $refill ? h(old_in('paid_amount')) : '' ?>" <?= field_aria('paid_amount_' . (int)$i['id'], 'amt-hint-' . (int)$i['id']) ?>><?= field_error('paid_amount_' . (int)$i['id']) ?>
          <label><input type="checkbox" id="full-<?= (int)$i['id'] ?>" name="paid_full" value="1"<?= $refill && old_in('paid_full') === '1' ? ' checked' : '' ?>>
            Paid in full<span class="visually-hidden"> — invoice <?= h($i['invoice_number']) ?>, <?= fmt_money((float)$i['total_amount']) ?></span></label>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm('Mark invoice <?= h($i['invoice_number']) ?> as paid? It cannot be returned to Submitted; the amount paid can be corrected afterwards.');">Mark paid<span class="visually-hidden"> — invoice <?= h($i['invoice_number']) ?></span></button>
        </form>
        </details>
        <?php elseif ($i['status'] === 'Paid'):
          // An older Paid invoice with no amount recorded was paid in full.
          $paidAmt = $i['paid_amount'] !== null ? (float)$i['paid_amount'] : (float)$i['total_amount'];
          $owed = round((float)$i['total_amount'] - $paidAmt, 2);
          $iid = (int)$i['id']; $invName = '<span class="visually-hidden"> — invoice ' . h($i['invoice_number']) . '</span>';
          $refillAdd = $refill && old_in('add_payment') === '1';
          $refillFix = $refill && old_in('correct_payment') === '1';
          $fixOpen = $refillFix || has_field_error('fix_paid_amount_' . $iid) || has_field_error('fix_payment_ref_' . $iid);
          $addOpen = $refillAdd || has_field_error('add_paid_amount_' . $iid) || has_field_error('add_payment_ref_' . $iid); ?>
          <span class="muted"><?= h($i['payment_ref'] ? 'Ref ' . $i['payment_ref'] : '') ?>
          <?= $i['paid_amount'] !== null && (float)$i['paid_amount'] !== (float)$i['total_amount'] ? ' · paid ' . fmt_money((float)$i['paid_amount']) : '' ?></span>
          <?php if ($owed > 0): ?>
          <details<?= $addOpen ? ' open' : '' ?>>
          <summary>Record payment<?= $invName ?></summary>
          <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?><?= $listHidden ?>
            <input type="hidden" name="id" value="<?= $iid ?>">
            <label for="addref-<?= $iid ?>">Check or <abbr title="electronic funds transfer">EFT</abbr> reference for this payment<?= $invName ?></label>
            <input type="text" id="addref-<?= $iid ?>" name="payment_ref" size="10" maxlength="80" value="<?= $refillAdd ? h(old_in('payment_ref')) : '' ?>" <?= field_aria('add_payment_ref_' . $iid) ?>><?= field_error('add_payment_ref_' . $iid) ?>
            <label for="addamt-<?= $iid ?>">Amount of this payment (<span aria-hidden="true">$</span><span class="visually-hidden">in dollars</span>)<?= $invName ?></label>
            <span class="hint" id="addamt-hint-<?= $iid ?>">Up to the <?= fmt_money($owed) ?> still owed, e.g., <?= number_format($owed, 2, '.', '') ?>.</span>
            <input type="text" inputmode="decimal" autocomplete="off" id="addamt-<?= $iid ?>" name="paid_amount" size="10" maxlength="20" value="<?= $refillAdd ? h(old_in('paid_amount')) : '' ?>" <?= field_aria('add_paid_amount_' . $iid, 'addamt-hint-' . $iid) ?>><?= field_error('add_paid_amount_' . $iid) ?>
            <button type="submit" name="add_payment" value="1" class="btn btn-secondary btn-sm">Record another payment<?= $invName ?></button>
          </form>
          </details>
          <?php endif; ?>
          <details<?= $fixOpen ? ' open' : '' ?>>
            <summary>Correct the amount paid<?= $invName ?></summary>
            <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?><?= $listHidden ?>
              <input type="hidden" name="id" value="<?= $iid ?>">
              <label for="fixamt-<?= $iid ?>">Total amount paid so far (<span aria-hidden="true">$</span><span class="visually-hidden">in dollars</span>)<?= $invName ?></label>
              <span class="hint" id="fixamt-hint-<?= $iid ?>">Now <?= fmt_money($paidAmt) ?> of <?= fmt_money((float)$i['total_amount']) ?>. Enter the correct total paid, up to the invoice total.</span>
              <input type="text" inputmode="decimal" autocomplete="off" id="fixamt-<?= $iid ?>" name="paid_amount" size="10" maxlength="20" value="<?= $refillFix ? h(old_in('paid_amount')) : '' ?>" <?= field_aria('fix_paid_amount_' . $iid, 'fixamt-hint-' . $iid) ?>><?= field_error('fix_paid_amount_' . $iid) ?>
              <label for="fixref-<?= $iid ?>">Check or <abbr title="electronic funds transfer">EFT</abbr> reference<?= $invName ?></label>
              <span class="hint" id="fixref-hint-<?= $iid ?>">Leave blank to keep the reference already recorded.</span>
              <input type="text" id="fixref-<?= $iid ?>" name="payment_ref" size="10" maxlength="80" value="<?= $refillFix ? h(old_in('payment_ref')) : '' ?>" <?= field_aria('fix_payment_ref_' . $iid, 'fixref-hint-' . $iid) ?>><?= field_error('fix_payment_ref_' . $iid) ?>
              <button type="submit" name="correct_payment" value="1" class="btn btn-secondary btn-sm">Save corrected amount<?= $invName ?></button>
            </form>
          </details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
