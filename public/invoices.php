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

/* Batch: generate invoices for every active track with unbilled work */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_generate'])) {
    // Tracks with unbilled work logs OR unbilled expenses (expense-only tracks
    // were previously never picked up by the batch).
    $bt = db()->prepare("SELECT DISTINCT t.id FROM training_tracks t
        JOIN clients c ON c.id = t.client_id
        LEFT JOIN work_logs w ON w.track_id = t.id AND w.is_billed = 0
        LEFT JOIN track_expenses e ON e.track_id = t.id AND e.is_billed = 0
        WHERE (w.id IS NOT NULL OR e.id IS NOT NULL)" . $scopeCond);
    $bt->execute($scopeArg);
    $tracks = $bt->fetchAll();
    $made = 0;
    foreach ($tracks as $t) {
        $invId = generate_invoice((int)$t['id'], $user['id']);
        if ($invId !== null) {
            $made++;
            audit($user['id'], 'invoice.create', 'invoice', $invId, 'batch from track #' . (int)$t['id']);
        }
    }
    flash($made ? "Generated {$made} invoice(s) from all unbilled work. They are listed below as Drafts."
                : 'No unbilled work found — nothing to invoice.');
    redirect('invoices.php');
}

/* Status transitions and voiding a draft share the caseload check */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['set_status']) || isset($_POST['void_draft']))) {
    $id = (int)($_POST['id'] ?? 0);
    // Caseload scope the WRITE (the list/export are scoped, this was not): the
    // invoice's client must be on the caseload or a scoped user could transition
    // another staffer's invoice by posting its id.
    if ($scoped) {
        $own = db()->prepare('SELECT c.assigned_to FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id WHERE i.id = ?');
        $own->execute([$id]);
        $assignedTo = $own->fetchColumn();
        if ($assignedTo === false || (int)$assignedTo !== $uid) { flash('That invoice is not on your caseload.', 'error'); redirect('invoices.php'); }
    }
    $cur = db()->prepare('SELECT invoice_number, track_id, total_amount FROM invoices WHERE id = ?');
    $cur->execute([$id]);
    $inv = $cur->fetch();
    if (!$inv) { flash('That invoice no longer exists.', 'error'); redirect('invoices.php'); }
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
            redirect('invoices.php');
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
    redirect('invoices.php');
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
            $back = $_POST['list_status'] ?? '';
            redirect('invoices.php' . (in_array($back, ['Draft','Submitted','Paid'], true) ? '?status=' . $back : '') . '#inv-' . $id);
        }
        $st = db()->prepare("UPDATE invoices SET status='Paid', paid_at=CURDATE(), payment_ref=?, paid_amount=COALESCE(?, total_amount) WHERE id=? AND status='Submitted'");
        $st->execute([$ref !== '' ? $ref : null, $amtCents === null ? null : number_format($amtCents / 100, 2, '.', ''), $id]);
        $action = 'invoice.paid'; $from = 'Submitted';
        $detail = ($ref !== '' ? "ref {$ref}; " : '') . 'paid ' . fmt_money(($amtCents ?? $totalCents) / 100);
    } else {
        flash('Choose a valid invoice status.', 'error');
        redirect('invoices.php');
    }
    // Only a row that actually changed is audited and reported as done; a stale
    // page (someone else already moved it) gets told what happened instead.
    if ($st->rowCount() === 1) {
        audit($user['id'], $action, 'invoice', $id, $detail);
        flash('Invoice ' . $num . ' marked ' . strtolower($to) . '.');
    } else {
        flash('Invoice ' . $num . ' was not changed: it is no longer ' . $from . ', so someone may have already updated it. The list below shows its current status.', 'error');
    }
    redirect('invoices.php');
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

$statusFilter = $_GET['status'] ?? '';
$listSql = "SELECT i.*, t.title AS track_title, COALESCE(i.auth_number, t.auth_number) AS auth_number,
        COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, c.name AS client_name, c.id AS client_id,
        DATEDIFF(CURDATE(), i.submitted_at) AS days_outstanding
    FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
    WHERE 1=1" . $scopeCond;
$sql = $listSql;
$params = $scopeArg;
// ?id=N (search results link here) lists just that invoice, whatever its status
// or age, under the same billing permission and caseload scope as the list.
$onlyId = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
if ($onlyId > 0) { $sql .= ' AND i.id = ?'; $params[] = $onlyId; $statusFilter = ''; }
elseif (in_array($statusFilter, ['Draft','Submitted','Paid'], true)) { $sql .= ' AND i.status = ?'; $params[] = $statusFilter; }
$sql .= " ORDER BY i.created_at DESC LIMIT 300";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();
// A refused "Mark paid" on an invoice outside the 300 newest (or outside the
// filter) is listed first, so its error summary, field error and refill show.
$refusedId = (int)old_in('id');
if ($refusedId > 0 && (has_field_error('paid_amount_' . $refusedId) || has_field_error('payment_ref_' . $refusedId))
    && !in_array($refusedId, array_map('intval', array_column($invoices, 'id')), true)) {
    $rs = db()->prepare($listSql . " AND i.id = ? AND i.status = 'Submitted'");
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

<div class="page-actions">
  <form method="post" action="invoices.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="batch_generate" value="1" class="btn btn-primary"
      onclick="return confirm('Generate draft invoices for ALL tracks with unbilled work? This marks those hours as billed.');">
      Generate invoices for all unbilled work (<?= fmt_money((float)$unbilled) ?>)</button>
  </form>
  <a class="btn btn-secondary" href="invoices.php?export=1">Export for QuickBooks (<abbr title="comma-separated values">CSV</abbr>)</a>
  <a class="btn btn-secondary" href="agency_report.php">Agency billing summary</a>
</div>

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
  <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
</form>
<?php if ($onlyId > 0): ?>
<p><?= $invoices ? 'Showing one invoice.' : 'That invoice was not found, or it is not on your caseload.' ?> <a href="invoices.php">Show all invoices</a></p>
<?php endif; ?>

<p class="muted">Found a mistake after generating? Void the Draft invoice: its work and expenses become unbilled again, so you can correct them on the track and invoice again. Only a Draft can be voided — once an invoice is marked submitted or paid it has gone to the funder and cannot be voided here.</p>
<?php
// A refused "Mark paid" names its field; the summary links to that row's field.
$payLabels = []; $payAnchors = [];
foreach ($invoices as $i) {
    if ($i['status'] !== 'Submitted') continue;
    $payLabels['payment_ref_' . $i['id']] = 'Check or EFT reference';
    $payLabels['paid_amount_' . $i['id']] = 'Amount paid';
    $payAnchors['payment_ref_' . $i['id']] = 'ref-' . $i['id'];
    $payAnchors['paid_amount_' . $i['id']] = 'amt-' . $i['id'];
}
if ($payLabels) echo form_error_summary($payLabels, $payAnchors);
$payDraftId = old_in('id');
?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Invoices list">
<table>
  <caption class="visually-hidden"><?= $onlyId > 0 ? 'The requested invoice' : 'Invoices, newest first' ?></caption>
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
      <td><a href="client.php?id=<?= (int)$i['client_id'] ?>"><?= h($i['client_name']) ?></a><br>
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
        <?php // Visible labels and hint (a placeholder vanishes once typing starts); the
              // checkbox sits inside its label so the label's 44px target applies. ?>
        <form method="post" action="invoices.php" class="stacked" novalidate><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="to" value="Paid"><input type="hidden" name="list_status" value="<?= in_array($statusFilter, ['Draft','Submitted','Paid'], true) ? $statusFilter : '' ?>">
          <label for="ref-<?= (int)$i['id'] ?>">Check or electronic funds transfer (<abbr title="electronic funds transfer">EFT</abbr>) reference<span class="visually-hidden"> for invoice <?= h($i['invoice_number']) ?></span></label>
          <input type="text" id="ref-<?= (int)$i['id'] ?>" name="payment_ref" size="10" maxlength="80" value="<?= $refill ? h(old_in('payment_ref')) : '' ?>" <?= field_aria('payment_ref_' . (int)$i['id']) ?>><?= field_error('payment_ref_' . (int)$i['id']) ?>
          <label for="amt-<?= (int)$i['id'] ?>">Amount paid (<span aria-hidden="true">$</span><span class="visually-hidden">in dollars</span>)<span class="visually-hidden"> for invoice <?= h($i['invoice_number']) ?></span></label>
          <span class="hint" id="amt-hint-<?= (int)$i['id'] ?>">Dollars and cents, e.g., 1250.00. Leave blank only when Paid in full is ticked.</span>
          <input type="text" inputmode="decimal" autocomplete="off" id="amt-<?= (int)$i['id'] ?>" name="paid_amount" size="10" maxlength="20" value="<?= $refill ? h(old_in('paid_amount')) : '' ?>" <?= field_aria('paid_amount_' . (int)$i['id'], 'amt-hint-' . (int)$i['id']) ?>><?= field_error('paid_amount_' . (int)$i['id']) ?>
          <label><input type="checkbox" id="full-<?= (int)$i['id'] ?>" name="paid_full" value="1"<?= $refill && old_in('paid_full') === '1' ? ' checked' : '' ?>>
            Paid in full<span class="visually-hidden"> — invoice <?= h($i['invoice_number']) ?>, <?= fmt_money((float)$i['total_amount']) ?></span></label>
          <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm('Mark invoice <?= h($i['invoice_number']) ?> as paid? This cannot be undone.');">Mark paid<span class="visually-hidden"> — invoice <?= h($i['invoice_number']) ?></span></button>
        </form>
        <?php elseif ($i['status'] === 'Paid'): ?>
          <span class="muted"><?= h($i['payment_ref'] ? 'Ref ' . $i['payment_ref'] : '') ?>
          <?= $i['paid_amount'] !== null && (float)$i['paid_amount'] !== (float)$i['total_amount'] ? ' · paid ' . fmt_money((float)$i['paid_amount']) : '' ?></span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
