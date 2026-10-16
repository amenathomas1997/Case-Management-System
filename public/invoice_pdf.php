<?php
// Letterhead-style PDF invoice for a training track, formatted for state VR submission.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require __DIR__ . '/inc/app_pdf.php';
$user = require_login();
require_perm('billing');

$invoice = null;
if (!empty($_GET['invoice'])) {
    $iv = db()->prepare('SELECT * FROM invoices WHERE id = ?');
    $iv->execute([(int)$_GET['invoice']]);
    $invoice = $iv->fetch();
    // A stale link returns to the app with a message, not a bare text page.
    if (!$invoice) { flash('That invoice no longer exists.', 'error'); redirect('invoices.php'); }
    $trackId = (int)$invoice['track_id'];
} else {
    $trackId = (int)($_GET['track'] ?? 0);
}
$stmt = db()->prepare('SELECT t.*, c.name AS client_name, c.assigned_to, c.counselor_name, c.counselor_agency, c.counselor_email, c.counselor_verified FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
$stmt->execute([$trackId]);
$t = $stmt->fetch();
if (!$t) { flash('That training track no longer exists.', 'error'); redirect('clients.php'); }
scope_check_client($t, $user);

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
$billTo = array_values(array_filter(array_map(fn($v) => trim((string)$v), $snap
    ? array_merge([$invoice['funding_agency'] ?? ''], explode("\n", (string)($invoice['bill_to'] ?? '')))
    : [$t['funding_agency'], $t['counselor_name'], $t['counselor_agency'], (int)$t['counselor_verified'] === 1 ? $t['counselor_email'] : null]), fn($v) => $v !== ''));
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
    'Vendor number' => $bs['vendor_number'], 'Tax ID' => $bs['tax_id'],
    'Payment terms' => $invoice ? 'Due ' . $bs['terms_days'] . ' days after submission' : '',
    'Due date' => $invoice && !empty($invoice['due_date']) ? fmt_date($invoice['due_date']) : '',
    'Memo' => $invoice ? (string)($invoice['notes'] ?? '') : '',
], fn($v) => $v !== '');
// What has been paid, written off and is still owed; a closed invoice says so.
$paidSoFar = $invoice ? (float)($invoice['paid_amount'] ?? 0) : 0.0;
$writtenOff = $invoice ? (float)($invoice['written_off'] ?? 0) : 0.0;
$closedNote = $invoice && in_array($invoice['status'], ['Denied', 'Void'], true)
    ? 'This invoice is ' . strtoupper($invoice['status']) . ($invoice['closed_reason'] ? ': ' . $invoice['closed_reason'] : '') . '. Nothing is owed on it; its work was released to be invoiced again.' : '';

// The same authorized data is available as a portable, semantic HTML report.
if (($_GET['format'] ?? '') === 'html') {
    $doc = new AccessibleDocument(ucfirst(strtolower($docTitle)) . ' — ' . $t['client_name']);
    $doc->paragraph(implode("\n", array_filter(array_merge([brand('org'), brand('tagline')], $fromLines))));
    if ($closedNote !== '') $doc->paragraph($closedNote);
    if ($payInfo) { $doc->heading('Payment details'); $doc->properties($payInfo); }
    $doc->heading($private ? 'Bill to' : 'Bill to and service authorization');
    $doc->properties(array_merge(
        $invoice ? ['Invoice date' => $docDate, 'Printed' => date('F j, Y')] : ['Statement date' => $docDate], [
        $invoice ? 'Invoice number' : 'Statement number' => $docNumber,
        'Bill to' => implode("\n", $billTo),
        'Authorization' => $private ? 'Private pay' : $authNo, 'Client' => $t['client_name'], 'Service' => $t['title'],
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
    $totals[$invoice ? ($paidSoFar > 0 || $writtenOff > 0 ? 'INVOICE TOTAL' : 'TOTAL DUE') : 'TOTAL (ALL WORK TO DATE, INCLUDING PREVIOUSLY INVOICED)'] = fmt_money($invoice ? (float)$invoice['total_amount'] : $r['total_billed'] + $expTotal);
    if ($paidSoFar > 0) $totals['Paid'] = fmt_money($paidSoFar);
    if ($writtenOff > 0) $totals['Written off'] = fmt_money($writtenOff);
    if ($invoice && ($paidSoFar > 0 || $writtenOff > 0)) $totals['BALANCE DUE'] = fmt_money(invoice_balance($invoice));
    $doc->heading('Totals');
    $doc->properties($totals);
    if (!$private) $doc->heading('Authorization usage');
    if (!$private) $doc->paragraph('Authorization usage: ' . fmt_hours($authUsage['hours_worked']) . ' of ' . fmt_hours($authUsage['authorized_hours']) . ' authorized hours used (' . $authUsage['pct_used'] . '%). Remaining: ' . fmt_hours($authUsage['hours_remaining']) . ' / ' . fmt_money($authUsage['funds_remaining']) . '. Prepared by ' . $user['name'] . '.');
    // A download of the client's billing record is listed in their audit history.
    audit((int)$user['id'], 'invoice.download', $invoice ? 'invoice' : 'track', $invoice ? (int)$invoice['id'] : $trackId, ($invoice ? 'invoice ' . $invoice['invoice_number'] : 'statement') . ' (accessible HTML)');
    $doc->download('invoice_' . ($invoice['id'] ?? 'track_' . $trackId) . '.html');
}

/** Core PDF fonts are Latin-1; transliterate UTF-8 punctuation. */
function pdf_txt(?string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? '') ?: '';
}

class InvoicePdf extends AppPdf {
    public string $footerText = '';
    function Footer(): void {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(60, 60, 60);   // #3C3C3C: 11:1 on white for 8pt text
        $this->Cell(0, 5, pdf_txt($this->footerText . ' — Page ' . $this->PageNo()), 0, 0, 'C');
    }
    /** The lines MultiCell will print for $txt in a cell $w wide (its own wrapping rules). */
    function WrapLines(float $w, string $txt): array {
        $cw = $this->CurrentFont['cw'];
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") $nb--;
        $lines = []; $sep = -1; $i = 0; $j = 0; $l = 0;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") { $lines[] = substr($s, $j, $i - $j); $i++; $sep = -1; $j = $i; $l = 0; continue; }
            if ($c === ' ') $sep = $i;
            $l += $cw[$c] ?? 500;
            if ($l > $wmax) {
                if ($sep === -1) { if ($i === $j) $i++; $lines[] = substr($s, $j, $i - $j); }
                else { $lines[] = substr($s, $j, $sep - $j); $i = $sep + 1; }
                $sep = -1; $j = $i; $l = 0;
            } else $i++;
        }
        $lines[] = substr($s, $j, $i - $j);
        return $lines;
    }
    /**
     * One bordered line-item row. A row that fits on a page moves to the next page
     * whole; a description taller than a page (a long dictated session note) is
     * split into page-sized parts, the first carrying the date, hours, miles and
     * amount and each later part marked "(continued)", so no cell is left behind
     * on another page.
     */
    function LineItem(array $w, string $date, string $hours, string $miles, string $desc, string $amount): void {
        $lineH = 5;
        $bottom = $this->GetPageHeight() - 25;
        $lines = $this->WrapLines($w[3], $desc);
        if ($this->GetY() + count($lines) * $lineH > $bottom) $this->AddPage();
        $first = true;
        while ($lines) {
            $room = (int)floor(($bottom - $this->GetY()) / $lineH);
            if ($room < 1) { $this->AddPage(); continue; }
            $part = array_splice($lines, 0, $room);
            $h = count($part) * $lineH;
            $x = $this->GetX(); $y = $this->GetY();
            $this->Cell($w[0], $h, $first ? $date : '(continued)', 1, 0, 'L');
            $this->Cell($w[1], $h, $first ? $hours : '', 1, 0, 'R');
            $this->Cell($w[2], $h, $first ? $miles : '', 1, 0, 'R');
            $this->MultiCell($w[3], $lineH, implode("\n", $part), 1, 'L');
            $this->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3], $y);
            $this->Cell($w[4], $h, $first ? $amount : '', 1, 1, 'R');
            $first = false;
            if ($lines) $this->AddPage();
        }
    }
}

$pdf = new InvoicePdf('P', 'mm', 'Letter');
$pdf->footerText = cfg('name') . ' - ' . ucfirst(strtolower($docTitle)) . ' ' . $authNo;
$pdf->SetTitle(pdf_txt(ucfirst(strtolower($docTitle)) . ' — ' . $t['client_name'] . ' — ' . $authNo));
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage();
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 6, 'Accessible HTML version (sign-in required)', 0, 1, 'L', false,
    cfg('base_url') . '/invoice_pdf.php?' . ($invoice ? 'invoice=' . (int)$invoice['id'] : 'track=' . $trackId) . '&format=html');

// Letterhead
$pdf->SetFont('Helvetica', 'B', 18);
$pdf->SetTextColor(30, 58, 138);
$pdf->Cell(120, 9, pdf_txt(brand('org')), 0, 0);
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 9, $docTitle, 0, 1, 'R');
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(120, 5, pdf_txt(brand('tagline')), 0, 0);
$pdf->Cell(0, 5, pdf_txt('Date: ' . $docDate), 0, 1, 'R');
// The remit-to lines run down the left, beside the number, due date and vendor number.
$rightHead = [($invoice ? 'Invoice #: ' : 'Statement #: ') . $docNumber];
if ($invoice) $rightHead[] = 'Printed: ' . date('F j, Y');
foreach ($payInfo as $k => $v) if ($k !== 'Memo') $rightHead[] = $k . ': ' . $v;
for ($n = 0; $n < max(count($fromLines), count($rightHead)); $n++) {
    $pdf->Cell(120, 5, pdf_txt($fromLines[$n] ?? ''), 0, 0);
    $pdf->Cell(0, 5, pdf_txt($rightHead[$n] ?? ''), 0, 1, 'R');
}
if (isset($payInfo['Memo'])) $pdf->Cell(0, 5, pdf_txt('Memo: ' . $payInfo['Memo']), 0, 1, 'L');
if ($closedNote !== '') { $pdf->SetFont('Helvetica', 'B', 10); $pdf->MultiCell(0, 5, pdf_txt($closedNote), 0, 'L'); $pdf->SetFont('Helvetica', '', 10); }
$pdf->Ln(4);
$pdf->SetDrawColor(30, 58, 138);
$pdf->SetLineWidth(0.6);
$pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(5);

// Bill-to / case info
$pdf->SetTextColor(0, 0, 0);
$half = 92;
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell($half, 5, 'BILL TO', 0, 0);
$pdf->Cell(0, 5, $private ? 'SERVICE' : 'SERVICE AUTHORIZATION', 0, 1);
$pdf->SetFont('Helvetica', '', 10);
$leftLines = $billTo;
$rightLines = [
    $private ? 'Private pay' : 'Authorization number: ' . $authNo,
    'Client: ' . $t['client_name'],
    'Service: ' . $t['title'],
    'RSA program: ' . track_program_label($t),
    $periodLabel . ': ' . $periodText,
    'Rate: ' . $rateText,
];
// Each block wraps within its own column (a Cell neither wraps nor clips, so a long
// agency name ran into the right column and a long title off the page); the next
// part starts below the taller of the two.
$x0 = $pdf->GetX(); $y0 = $pdf->GetY();
$pdf->MultiCell($half, 5, pdf_txt(implode("\n", $leftLines)), 0, 'L');
$yLeft = $pdf->GetY();
$pdf->SetXY($x0 + $half, $y0);
$pdf->MultiCell(0, 5, pdf_txt(implode("\n", $rightLines)), 0, 'L');
$pdf->SetY(max($yLeft, $pdf->GetY()));
// A standalone file has no glossary: expand RSA once, under the column that uses it.
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell($half, 5, '', 0, 0);
$pdf->Cell(0, 5, 'RSA: Rehabilitation Services Administration', 0, 1);
$pdf->Ln(5);

// Line-item table
$w = [24, 16, 18, 92, 25];   // date, hours, miles, description, amount
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(238, 241, 246);
$pdf->Cell($w[0], 7, 'Date', 1, 0, 'L', true);
$pdf->Cell($w[1], 7, 'Hours', 1, 0, 'R', true);
$pdf->Cell($w[2], 7, 'Miles', 1, 0, 'R', true);
$pdf->Cell($w[3], 7, 'Activity', 1, 0, 'L', true);
$pdf->Cell($w[4], 7, 'Amount', 1, 1, 'R', true);
$pdf->SetFont('Helvetica', '', 9);

foreach ($logs as $l) {
    // Tag each line with the RSA category delivered, whenever the program covers more than one.
    $visitCat = $l['rsa_category'] ?? null;
    $multiCategory = count(track_visit_categories($t)) > 1;
    $prefix = '';
    if ($visitCat && $multiCategory) {
        $prefix = "[{$visitCat}] ";
    } elseif (!$visitCat && ($l['service_type'] ?? null) && $l['service_type'] !== ($t['service_type'] ?? '')) {
        $prefix = "[{$l['service_type']}] ";
    }
    if (!empty($l['signature_path'])) $prefix = '[Client-signed] ' . $prefix;
    elseif (trim((string)($l['signed_name'] ?? '')) !== '') $prefix = '[Client-attested by typed name] ' . $prefix;
    // Each log is priced at the rate in force when it was entered, rounded to the
    // cent per line exactly as the totals below were summed.
    $amount = log_line_amounts($l, $fbRate, $fbMileageRate)['total'];
    $pdf->LineItem($w, pdf_txt(fmt_date($l['work_date'])), number_format((float)$l['hours'], 2),
        (float)$l['mileage'] > 0 ? number_format((float)$l['mileage'], 1) : '-', pdf_txt($prefix . $l['description']), pdf_txt(fmt_money($amount)));
}

// Expense line items
foreach ($expRows as $e) {
    $pdf->LineItem($w, pdf_txt(fmt_date($e['expense_date'])), '-', '-', pdf_txt('EXPENSE: ' . $e['description']), pdf_txt(fmt_money((float)$e['amount'])));
}

// Totals
$pdf->Ln(3);
$labelW = $w[0] + $w[1] + $w[2] + $w[3];
$rowsT = [
    ['Total hours (' . number_format($r['hours_worked'], 2) . ' hours)', fmt_money($r['billed_amount']), false],
];
if ($r['mileage_amount'] > 0) {
    $rowsT[] = ['Total mileage (' . number_format($r['mileage'], 1) . ' miles)', fmt_money($r['mileage_amount']), false];
}
if ($expTotal > 0) {
    $rowsT[] = ['Total expenses', fmt_money($expTotal), false];
}
$grand = $invoice ? (float)$invoice['total_amount'] : $r['total_billed'] + $expTotal;
$settled = $invoice && ($paidSoFar > 0 || $writtenOff > 0);
$rowsT[] = [$invoice ? ($settled ? 'INVOICE TOTAL' : 'TOTAL DUE') : 'TOTAL (ALL WORK TO DATE, INCLUDING PREVIOUSLY INVOICED)', fmt_money($grand), !$settled];
if ($paidSoFar > 0) $rowsT[] = ['Paid', fmt_money($paidSoFar), false];
if ($writtenOff > 0) $rowsT[] = ['Written off', fmt_money($writtenOff), false];
if ($settled) $rowsT[] = ['BALANCE DUE', fmt_money(invoice_balance($invoice)), true];
foreach ($rowsT as [$label, $val, $bold]) {
    $pdf->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 11 : 10);
    $pdf->Cell($labelW, 7, pdf_txt($label), 0, 0, 'R');
    $pdf->Cell($w[4], 7, pdf_txt($val), $bold ? 'T' : 0, 1, 'R');
}
$pdf->Ln(4);
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(60, 60, 60);   // #3C3C3C: 11:1 on white (small text needs 7:1)
if (!$private) $pdf->MultiCell(0, 5, pdf_txt('Authorization usage: ' . fmt_hours($authUsage['hours_worked']) . ' of ' . fmt_hours($authUsage['authorized_hours']) . ' authorized hours used (' . $authUsage['pct_used'] . '%). Remaining: ' . fmt_hours($authUsage['hours_remaining']) . ' / ' . fmt_money($authUsage['funds_remaining']) . '. Prepared by ' . $user['name'] . '.'));

$filename = 'invoice_' . preg_replace('/[^A-Za-z0-9]+/', '_', $t['client_name']) . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $authNo) . '_' . date('Ymd') . '.pdf';
// Built as a string and sent with this page's own headers: FPDF's download output
// replaces the app's Cache-Control: no-store with a cacheable one.
$bytes = $pdf->Output('S');
audit((int)$user['id'], 'invoice.download', $invoice ? 'invoice' : 'track', $invoice ? (int)$invoice['id'] : $trackId, ($invoice ? 'invoice ' . $invoice['invoice_number'] : 'statement') . ' (PDF)');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $bytes;
exit;
