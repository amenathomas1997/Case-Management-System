<?php
declare(strict_types=1);
// The invoice or statement document (4.38.0): moved from invoice_pdf.php, so the page's
// accessible HTML and PDF and the apps' invoice_document are built from one copy. The page
// still checks the caseload (scope_check_client) and the API with its own scope check.
require_once __DIR__ . '/document.php';

/**
 * Everything invoice_pdf.php prints for an invoice ($invoice, a row of invoices) or, with $invoice
 * null, a statement of every entry on the track: the track and client ('t'), the lines, totals,
 * billing header and payment details. A sentence (the page's) when the track no longer exists.
 */
function invoice_document_context(?array $invoice, int $trackId): array|string {
    $stmt = db()->prepare('SELECT t.*, c.name AS client_name, c.assigned_to, c.counselor_name, c.counselor_agency, c.counselor_email, c.counselor_verified,
        c.address AS client_address, c.email AS client_email, c.email_verified AS client_email_verified, c.vr_case_number FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
    $stmt->execute([$trackId]);
    $t = $stmt->fetch();
    if (!$t) return 'That training track no longer exists.';

    // Without ?invoice this is a full STATEMENT of every entry on the track
    // (billed or not) — labeling it "INVOICE" with a "TOTAL DUE" double-bills.
    $docTitle = $invoice ? 'INVOICE' : 'STATEMENT';

    if ($invoice) {
        $logs = db()->prepare('SELECT * FROM work_logs WHERE invoice_id = ? ORDER BY work_date');
        $logs->execute([(int)$invoice['id']]);
        $expRows = db()->prepare('SELECT * FROM track_expenses WHERE invoice_id = ? ORDER BY expense_date');
        $expRows->execute([(int)$invoice['id']]);
    } else {
        $logs = db()->prepare('SELECT * FROM work_logs WHERE track_id = ? ORDER BY work_date');
        $logs->execute([$trackId]);
        $expRows = db()->prepare('SELECT * FROM track_expenses WHERE track_id = ? ORDER BY expense_date');
        $expRows->execute([$trackId]);
    }
    $logs = $logs->fetchAll();
    $expRows = $expRows->fetchAll();
    $expTotal = round(array_sum(array_map(fn($e) => (float)$e['amount'], $expRows)), 2);
    $r = track_rollup($trackId);
    // Authorization-usage is always a whole-track concept (cumulative hours vs the
    // authorization), so keep the untouched rollup for that summary line even when we
    // overwrite $r below with the single-invoice totals — otherwise the footer reads
    // "{invoice hrs} of {auth} used ({track pct}%)", which is internally contradictory.
    $authUsage = $r;
    if ($invoice) {
        // Totals reflect the stored invoice, not the whole track
        $r['hours_worked'] = (float)$invoice['hours'];
        $r['billed_amount'] = (float)$invoice['hours_amount'];
        $r['mileage'] = (float)$invoice['mileage'];
        $r['mileage_amount'] = (float)$invoice['mileage_amount'];
        $r['total_billed'] = (float)$invoice['total_amount'];
    }

    // A stored invoice prints the billing header captured when it was generated
    // (generate_invoice()), so a later edit to the track never rewrites what was
    // billed. Invoices from before that capture fall back to the track as it is now,
    // leaving out a counselor email not yet confirmed (counselor_verified = 0).
    $snap = $invoice && ($invoice['auth_number'] ?? null) !== null;
    $authNo = $snap ? (string)$invoice['auth_number'] : (string)$t['auth_number'];
    // A private-pay invoice or statement is billed to the client (3.0.34): no agency line.
    $privateBill = $invoice ? ($invoice['bill_mode'] ?? 'vr') === 'private' : track_mode($t) === 'private';
    $billTo = array_values(array_filter(array_map(fn($v) => trim((string)$v), $snap
        ? array_merge($privateBill ? [] : [$invoice['funding_agency'] ?? ''], explode("\n", (string)($invoice['bill_to'] ?? '')))
        : ($privateBill ? [$t['client_name'], $t['client_address'], (int)$t['client_email_verified'] === 1 ? $t['client_email'] : null]
            : [$t['funding_agency'], $t['counselor_name'], $t['counselor_agency'], (int)$t['counselor_verified'] === 1 ? $t['counselor_email'] : null])), fn($v) => $v !== ''));
    // Lines without their own rate snapshot are priced at the rate captured on the
    // invoice (what they were billed at), else the track's current rate.
    $fbRate = ($invoice && ($invoice['hourly_rate'] ?? null) !== null) ? (float)$invoice['hourly_rate'] : $r['hourly_rate'];
    $fbMileageRate = ($invoice && ($invoice['mileage_rate'] ?? null) !== null) ? (float)$invoice['mileage_rate'] : $r['mileage_rate'];
    // An invoice from before the rate was captured: its lines without a snapshot are
    // priced at the rate it actually billed them at (its stored amount less the lines
    // that carry their own rate, over their hours or miles), never at the track's rate
    // as later amended, so the lines still add up to the stored totals.
    if ($invoice) {
        $bare = ['h' => 0.0, 'm' => 0.0, 'ha' => (float)$invoice['hours_amount'], 'ma' => (float)$invoice['mileage_amount']];
        foreach ($logs as $l) {
            $own = log_line_amounts($l, 0.0, 0.0);
            if ($l['hourly_rate'] === null) $bare['h'] += (float)$l['hours']; else $bare['ha'] -= $own['hours'];
            if ($l['mileage_rate'] === null) $bare['m'] += (float)$l['mileage']; else $bare['ma'] -= $own['mileage'];
        }
        if (($invoice['hourly_rate'] ?? null) === null && $bare['h'] > 0) $fbRate = round(max(0.0, $bare['ha']) / $bare['h'], 2);
        if (($invoice['mileage_rate'] ?? null) === null && $bare['m'] > 0) $fbMileageRate = round(max(0.0, $bare['ma']) / $bare['m'], 3);
    }
    // The header names the rate the lines were actually priced at; when entries
    // carry different rates it says so instead of quoting one that does not apply.
    $hRates = array_unique(array_map(fn($l) => round((float)($l['hourly_rate'] ?? $fbRate), 2), $logs));
    $mRates = array_unique(array_map(fn($l) => round((float)($l['mileage_rate'] ?? $fbMileageRate), 3),
        array_filter($logs, fn($l) => (float)$l['mileage'] > 0)));
    sort($hRates); sort($mRates);
    $rateText = (count($hRates) > 1 ? fmt_money($hRates[0]) . '–' . fmt_money(end($hRates)) . ' per hour (varies by entry)' : fmt_money($hRates[0] ?? $fbRate) . ' per hour')
        // Mileage rates carry three decimals (0.655): print them in full so rate x miles matches the lines.
        . (count($mRates) > 1 ? ' + ' . fmt_mileage_rate($mRates[0]) . '–' . fmt_mileage_rate(end($mRates)) . '/mile'
            : (($mRates[0] ?? $fbMileageRate) > 0 ? ' + ' . fmt_mileage_rate($mRates[0] ?? $fbMileageRate) . '/mile' : ''));
    if ($snap && !empty($invoice['period_start'])) {
        $periodLabel = 'Billing period';
        $periodText = fmt_date($invoice['period_start']) . ' to ' . fmt_date($invoice['period_end']);
    } else {
        $periodLabel = 'Period';
        $periodText = fmt_date($t['start_date']) . ' to ' . fmt_date($t['expiration_date']);
    }
    // An invoice is dated when it was created (as its number and the QuickBooks
    // export are); only an ad-hoc statement is dated today.
    $docDate = $invoice ? date('F j, Y', strtotime($invoice['created_at'])) : date('F j, Y');
    $docNumber = $invoice ? $invoice['invoice_number'] : $authNo . '-' . date('Ymd');
    // The organisation's billing details (Administration > Billing details, 3.0.34): who to
    // pay and how VR matches the payment, printed only when they have been entered.
    $bs = billing_settings();
    $private = $invoice ? ($invoice['bill_mode'] ?? 'vr') === 'private' : track_mode($t) === 'private';
    $fromLines = array_values(array_filter(array_merge(explode("\n", str_replace("\r", '', $bs['remit_to'])),
        [$bs['phone'] !== '' ? 'Phone ' . $bs['phone'] : '', $bs['email']]), fn($v) => trim($v) !== ''));
    $payInfo = array_filter([
        // The vendor number kept on the invoice when it was made (4.1.0); else this agency's, else
        // Billing details. The terms are the ones its due date was set with, once it is sent.
        'Vendor number' => trim((string)($invoice['vendor_number'] ?? '')) !== '' ? trim((string)$invoice['vendor_number'])
            : ($private ? $bs['vendor_number'] : agency_vendor_number((string)(($invoice['funding_agency'] ?? null) ?: $t['funding_agency']))), 'Tax ID' => $bs['tax_id'],
        'Payment terms' => $invoice ? 'Due ' . (!empty($invoice['due_date']) && !empty($invoice['submitted_at'])
            ? (int)round((strtotime((string)$invoice['due_date']) - strtotime(substr((string)$invoice['submitted_at'], 0, 10))) / 86400)
            : ($private ? $bs['terms_days'] : agency_terms_days((string)(($invoice['funding_agency'] ?? null) ?: $t['funding_agency'])))) . ' days after submission' : '',
        'Due date' => $invoice && !empty($invoice['due_date']) ? fmt_date($invoice['due_date']) : '',
        'Memo' => $invoice ? (string)($invoice['notes'] ?? '') : '',
    ], fn($v) => $v !== '');
    // What has been paid, written off and is still owed; a closed invoice says so.
    $paidSoFar = $invoice ? (float)($invoice['paid_amount'] ?? 0) : 0.0;
    $writtenOff = $invoice ? (float)($invoice['written_off'] ?? 0) : 0.0;
    $closedNote = $invoice && in_array($invoice['status'], ['Denied', 'Void'], true)
        ? 'This invoice is ' . strtoupper($invoice['status']) . ($invoice['closed_reason'] ? ': ' . $invoice['closed_reason'] : '') . '. Nothing is owed on it; its work was released to be invoiced again.' : '';
    // 4.28.0: a Denied or Void invoice is never printed as "TOTAL DUE": its total is labelled with its status.
    $totalLabel = !$invoice ? 'TOTAL (ALL WORK TO DATE, INCLUDING PREVIOUSLY INVOICED)'
        : ($closedNote !== '' ? 'INVOICE TOTAL (' . strtoupper((string)$invoice['status']) . ', NOTHING DUE)'
        : ($paidSoFar > 0 || $writtenOff > 0 ? 'INVOICE TOTAL' : 'TOTAL DUE'));

    return compact('invoice', 'trackId', 't', 'docTitle', 'logs', 'expRows', 'expTotal', 'r', 'authUsage', 'snap', 'authNo', 'privateBill', 'billTo', 'fbRate', 'fbMileageRate', 'hRates', 'mRates', 'rateText', 'periodLabel', 'periodText', 'docDate', 'docNumber', 'bs', 'private', 'fromLines', 'payInfo', 'paidSoFar', 'writtenOff', 'closedNote', 'totalLabel');
}

/** One invoice's context (invoice_document_context()), or the page's sentence when it is gone. */
function invoice_document_load(int $invoiceId): array|string {
    $iv = db()->prepare('SELECT * FROM invoices WHERE id = ?');
    $iv->execute([$invoiceId]);
    $invoice = $iv->fetch();
    if (!$invoice) return 'That invoice no longer exists.';
    return invoice_document_context($invoice, (int)$invoice['track_id']);
}

/** The accessible document for a context: the same authorized data as the PDF, as a portable,
 *  semantic HTML report (the PDF links to a kept copy of it, 4.30.0). */
function invoice_document(array $ctx, string $preparedBy): AccessibleDocument {
    extract($ctx);
    $doc = new AccessibleDocument(ucfirst(strtolower($docTitle)) . ' — ' . $t['client_name']);
    $doc->paragraph(implode("\n", array_filter(array_merge([brand('org'), brand('tagline')], $fromLines))));
    if ($closedNote !== '') $doc->paragraph($closedNote);
    if ($payInfo) { $doc->heading('Payment details'); $doc->properties($payInfo); }
    $doc->heading($private ? 'Bill to' : 'Bill to and service authorization');
    $doc->properties(array_merge(
        $invoice ? ['Invoice date' => $docDate, 'Printed' => date('F j, Y')] : ['Statement date' => $docDate], [
        $invoice ? 'Invoice number' : 'Statement number' => $docNumber,
        'Bill to' => implode("\n", $billTo),
        'Authorization' => $private ? 'Private pay' : $authNo, 'Client' => $t['client_name'],
        // The VR agency's case number (3.0.45), when it is on file.
        ] + (!$private && trim((string)($t['vr_case_number'] ?? '')) !== '' ? ['VR case number' => (string)$t['vr_case_number']] : []) + [
        'Service' => $t['title'],
        'Rehabilitation Services Administration (RSA) program' => track_program_label($t),
        $periodLabel => $periodText,
        'Rate' => $rateText,
    ]));
    $rows = [];
    foreach ($logs as $l) {
        $prefix = '';
        if (!empty($l['rsa_category']) && count(track_visit_categories($t)) > 1) $prefix = '[' . $l['rsa_category'] . '] ';
        elseif (empty($l['rsa_category']) && !empty($l['service_type']) && $l['service_type'] !== $t['service_type']) $prefix = '[' . $l['service_type'] . '] ';
        if (!empty($l['signature_path'])) $prefix = '[Client-signed] ' . $prefix;
        elseif (trim((string)($l['signed_name'] ?? '')) !== '') $prefix = '[Client-attested by typed name] ' . $prefix;
        $amount = log_line_amounts($l, $fbRate, $fbMileageRate)['total'];
        $rows[] = [fmt_date($l['work_date']), number_format((float)$l['hours'], 2), number_format((float)$l['mileage'], 1), $prefix . $l['description'], fmt_money($amount)];
    }
    foreach ($expRows as $e) $rows[] = [fmt_date($e['expense_date']), '—', '—', 'EXPENSE: ' . $e['description'], fmt_money((float)$e['amount'])];
    $doc->heading('Services and expenses');
    $doc->table('Service and expense details', ['Date', 'Hours', 'Miles', 'Activity', 'Amount'], $rows);
    $totals = ['Total hours (' . number_format($r['hours_worked'], 2) . ' hours)' => fmt_money($r['billed_amount'])];
    if ($r['mileage_amount'] > 0) $totals['Total mileage (' . number_format($r['mileage'], 1) . ' miles)'] = fmt_money($r['mileage_amount']);
    if ($expTotal > 0) $totals['Total expenses'] = fmt_money($expTotal);
    $totals[$totalLabel] = fmt_money($invoice ? (float)$invoice['total_amount'] : $r['total_billed'] + $expTotal);
    if ($paidSoFar > 0) $totals['Paid'] = fmt_money($paidSoFar);
    if ($writtenOff > 0) $totals['Written off'] = fmt_money($writtenOff);
    if ($invoice && ($paidSoFar > 0 || $writtenOff > 0)) $totals['BALANCE DUE'] = fmt_money(invoice_balance($invoice));
    $doc->heading('Totals');
    $doc->properties($totals);
    if (!$private) $doc->heading('Authorization usage');
    if (!$private) $doc->paragraph('Authorization usage: ' . fmt_hours($authUsage['hours_worked']) . ' of ' . fmt_hours($authUsage['authorized_hours']) . ' authorized hours used (' . $authUsage['pct_used'] . '%). Remaining: ' . fmt_hours($authUsage['hours_remaining']) . ' / ' . fmt_money($authUsage['funds_remaining']) . '. Prepared by ' . $preparedBy . '.');
    return $doc;
}
