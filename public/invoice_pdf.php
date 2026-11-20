<?php
// Letterhead-style PDF invoice for a training track, formatted for state VR submission.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require __DIR__ . '/inc/app_pdf.php';
$user = require_login();
require_perm('billing');

$invoice = null;
if (!empty($_GET['invoice'])) {
    // 4.38.0: the invoice, its track and every printed figure from inc/invoice_document.php (shared with the apps).
    $ctx = invoice_document_load((int)$_GET['invoice']);
    // A stale link returns to the app with a message, not a bare text page.
    if (is_string($ctx)) { flash($ctx, 'error'); redirect($ctx === 'That invoice no longer exists.' ? 'invoices.php' : 'clients.php'); }
} else {
    $ctx = invoice_document_context(null, (int)($_GET['track'] ?? 0));
    if (is_string($ctx)) { flash($ctx, 'error'); redirect('clients.php'); }
}
scope_check_client($ctx['t'], $user);
extract($ctx);
$doc = invoice_document($ctx, (string)$user['name']);
if (($_GET['format'] ?? '') === 'html') {
    // A download of the client's billing record is listed in their audit history.
    audit((int)$user['id'], 'invoice.download', $invoice ? 'invoice' : 'track', $invoice ? (int)$invoice['id'] : $trackId, ($invoice ? 'invoice ' . $invoice['invoice_number'] : 'statement') . ' (accessible HTML)');
    $doc->download('invoice_' . ($invoice['id'] ?? 'track_' . $trackId) . '.html');
}

/** Core PDF fonts are Latin-1; transliterate UTF-8 punctuation. */
function pdf_txt(?string $s): string {
    return doc_pdf_text($s);
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
$pdf->describe(ucfirst(strtolower($docTitle)) . ' — ' . $t['client_name'] . ' — ' . $authNo,
    ($invoice ? 'Invoice ' . $invoice['invoice_number'] : 'Statement of all work to date') . ' from ' . brand('org') . ' for ' . $t['title'] . ($private ? ', private pay' : ', authorization ' . $authNo));
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage();
// The accessible copy of exactly what this PDF holds, for readers without a staff sign-in (4.30.0).
$pdf->accessibleCopyLine(doc_link_create($doc->html(false), $invoice ? 'invoice' : 'track', $invoice ? (int)$invoice['id'] : $trackId,
        ($invoice ? 'invoice ' . $invoice['invoice_number'] : 'statement for track #' . $trackId), (int)$t['client_id']),
    cfg('base_url') . '/invoice_pdf.php?' . ($invoice ? 'invoice=' . (int)$invoice['id'] : 'track=' . $trackId) . '&format=html');

// Letterhead, one column at a time (4.30.0), so it is read in order: the organisation and where to
// send payment, then the document, its date, number, due date and vendor number.
$xh = $pdf->GetX(); $y0 = $pdf->GetY();
$pdf->SetFont('Helvetica', 'B', 18);
$pdf->SetTextColor(...brand_rgb());
$pdf->Cell(120, 9, pdf_txt(brand('org')), 0, 2);
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(60, 60, 60);
foreach (array_merge([brand('tagline')], $fromLines) as $line) $pdf->Cell(120, 5, pdf_txt($line), 0, 2);
$yLeft = $pdf->GetY();
$rightHead = ['Date: ' . $docDate, ($invoice ? 'Invoice #: ' : 'Statement #: ') . $docNumber];
if ($invoice) $rightHead[] = 'Printed: ' . date('F j, Y');
foreach ($payInfo as $k => $v) if ($k !== 'Memo') $rightHead[] = $k . ': ' . $v;
$pdf->SetXY($xh + 120, $y0);
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->Cell(0, 9, $docTitle, 0, 2, 'R');
$pdf->SetFont('Helvetica', '', 10);
foreach ($rightHead as $line) $pdf->Cell(0, 5, pdf_txt($line), 0, 2, 'R');
$pdf->SetY(max($yLeft, $pdf->GetY()));
if (isset($payInfo['Memo'])) $pdf->Cell(0, 5, pdf_txt('Memo: ' . $payInfo['Memo']), 0, 1, 'L');
if ($closedNote !== '') { $pdf->SetFont('Helvetica', 'B', 10); $pdf->MultiCell(0, 5, pdf_txt($closedNote), 0, 'L'); $pdf->SetFont('Helvetica', '', 10); }
$pdf->Ln(4);
$pdf->SetDrawColor(...brand_rgb());
$pdf->SetLineWidth(0.6);
$pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(5);

// Bill-to / case info: each column is read whole, its heading first (4.30.0).
$pdf->SetTextColor(0, 0, 0);
$half = 92;
$leftLines = $billTo;
$rightLines = [
    $private ? 'Private pay' : 'Authorization number: ' . $authNo,
    'Client: ' . $t['client_name'],
    ...(!$private && trim((string)($t['vr_case_number'] ?? '')) !== '' ? ['VR case number: ' . $t['vr_case_number']] : []),
    'Service: ' . $t['title'],
    'RSA program: ' . track_program_label($t),
    $periodLabel . ': ' . $periodText,
    'Rate: ' . $rateText,
];
// Each block wraps within its own column (a Cell neither wraps nor clips, so a long
// agency name ran into the right column and a long title off the page); the next
// part starts below the taller of the two.
$x0 = $pdf->GetX(); $y0 = $pdf->GetY();
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell($half, 5, 'BILL TO', 0, 2);
$pdf->SetFont('Helvetica', '', 10);
$pdf->MultiCell($half, 5, pdf_txt(implode("\n", $leftLines)), 0, 'L');
$yLeft = $pdf->GetY();
$pdf->SetXY($x0 + $half, $y0);
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(0, 5, $private ? 'SERVICE' : 'SERVICE AUTHORIZATION', 0, 2);
$pdf->SetFont('Helvetica', '', 10);
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
$rowsT[] = [$totalLabel, fmt_money($grand), !$settled];
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
