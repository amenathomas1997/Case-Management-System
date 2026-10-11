<?php
// One-click billing export (CSV) formatted for state VR invoice submission.
require __DIR__ . '/inc/bootstrap.php';
$user = require_login();
require_perm('billing');

$trackId = (int)($_GET['track'] ?? 0);
$stmt = db()->prepare('SELECT t.*, c.name AS client_name, c.assigned_to, c.counselor_name, c.counselor_agency FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
$stmt->execute([$trackId]);
$track = $stmt->fetch();
// A stale link (deleted track) returns to the app with a message, not a bare text page.
if (!$track) { flash('That training track no longer exists.', 'error'); redirect('clients.php'); }
scope_check_client($track, $user);

$logs = db()->prepare('SELECT w.*, u.name AS author, i.invoice_number FROM work_logs w LEFT JOIN users u ON u.id = w.created_by LEFT JOIN invoices i ON i.id = w.invoice_id WHERE w.track_id = ? ORDER BY w.work_date');
$logs->execute([$trackId]);
$logs = $logs->fetchAll();
// Expenses are billed on the same invoices, so the statement lists them too.
$expenses = db()->prepare('SELECT e.*, i.invoice_number FROM track_expenses e LEFT JOIN invoices i ON i.id = e.invoice_id WHERE e.track_id = ? ORDER BY e.expense_date, e.id');
$expenses->execute([$trackId]);
$expenses = $expenses->fetchAll();
$r = track_rollup($trackId);

// ?layout=table: one table with its header in row 1 (sessions and expenses in
// the same columns, a Type column telling them apart, no preamble or totals), so
// a screen reader's column titles and spreadsheet filters apply to every row. The
// default layout below stays as it is for state VR submission.
$asTable = ($_GET['layout'] ?? '') === 'table';
$filename = 'invoice_' . preg_replace('/[^A-Za-z0-9]+/', '_', $track['client_name']) . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $track['auth_number']) . '_' . date('Ymd') . ($asTable ? '_table' : '') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

if ($asTable) {
    write_csv_row($out, ['Type', 'Date', 'RSA Category', 'Service Type', 'Hours', 'Mileage', 'Description', 'Rate', 'Hours Amount', 'Mileage Amount', 'Amount', 'Billed', 'Invoice #', 'Logged By']);
    foreach ($logs as $l) {
        $amt = log_line_amounts($l, $r['hourly_rate'], $r['mileage_rate']);
        write_csv_row($out, [
            'Session',
            $l['work_date'],
            csv_cell($l['rsa_category'] ?? $track['rsa_category'] ?? ''),
            csv_cell($l['service_type'] ?? $track['service_type'] ?? ''),
            number_format((float)$l['hours'], 2),
            number_format((float)$l['mileage'], 1),
            csv_cell($l['description']),
            number_format((float)($l['hourly_rate'] ?? $r['hourly_rate']), 2),
            number_format($amt['hours'], 2),
            number_format($amt['mileage'], 2),
            number_format($amt['total'], 2),
            $l['is_billed'] ? 'Yes' : 'No',
            csv_cell($l['invoice_number'] ?? ''),
            csv_cell($l['author'] ?? ''),
        ]);
    }
    foreach ($expenses as $e) {
        write_csv_row($out, [
            'Expense',
            csv_cell($e['expense_date']),
            '', '', '', '',
            csv_cell($e['description']),
            '', '', '',
            number_format((float)$e['amount'], 2),
            $e['is_billed'] ? 'Yes' : 'No',
            csv_cell($e['invoice_number'] ?? ''),
            '',
        ]);
    }
    fclose($out);
    exit;
}

write_csv_row($out, ['BILLING SUMMARY — ' . cfg('name')]);
write_csv_row($out, ['Generated', date('Y-m-d H:i'), 'By', csv_cell($user['name'])]);
write_csv_row($out, []);
write_csv_row($out, ['Client', csv_cell($track['client_name'])]);
write_csv_row($out, ['VR Counselor', csv_cell($track['counselor_name'] ?? ''), 'Agency', csv_cell($track['counselor_agency'] ?? '')]);
write_csv_row($out, ['Training Track', csv_cell($track['title'])]);
write_csv_row($out, ['Authorization #', csv_cell($track['auth_number']), 'Funding Agency', csv_cell($track['funding_agency'])]);
write_csv_row($out, ['RSA Program', csv_cell(track_program_label($track))]);
write_csv_row($out, ['Auth Period', fmt_date($track['start_date']) . ' to ' . fmt_date($track['expiration_date'])]);
write_csv_row($out, ['Authorized Hours', number_format($r['authorized_hours'], 2), 'Hourly Rate', number_format($r['hourly_rate'], 2), 'Mileage Rate', number_format($r['mileage_rate'], 3)]);
write_csv_row($out, []);
write_csv_row($out, ['Date', 'RSA Category', 'Service Type', 'Hours', 'Mileage', 'Activity Description', 'Rate', 'Hours Amount', 'Mileage Amount', 'Line Total', 'Billed', 'Logged By']);
foreach ($logs as $l) {
    // Priced at the rate in force when the log was entered, each part rounded to
    // the cent exactly as the totals below are summed.
    $rate = (float)($l['hourly_rate'] ?? $r['hourly_rate']);
    $amt = log_line_amounts($l, $r['hourly_rate'], $r['mileage_rate']);
    write_csv_row($out, [
        $l['work_date'],
        csv_cell($l['rsa_category'] ?? $track['rsa_category'] ?? ''),
        csv_cell($l['service_type'] ?? $track['service_type'] ?? ''),
        number_format((float)$l['hours'], 2),
        number_format((float)$l['mileage'], 1),
        csv_cell($l['description']),
        number_format($rate, 2),
        number_format($amt['hours'], 2),
        number_format($amt['mileage'], 2),
        number_format($amt['total'], 2),
        $l['is_billed'] ? 'Yes' : 'No',
        csv_cell($l['author'] ?? ''),
    ]);
}
write_csv_row($out, []);
write_csv_row($out, ['Expense Date', 'Expense Description', 'Amount', 'Billed', 'Invoice #']);
if (!$expenses) write_csv_row($out, ['No expenses logged']);
foreach ($expenses as $e) {
    write_csv_row($out, [
        csv_cell($e['expense_date']),
        csv_cell($e['description']),
        csv_cell(number_format((float)$e['amount'], 2)),
        csv_cell($e['is_billed'] ? 'Yes' : 'No'),
        csv_cell($e['invoice_number'] ?? ''),
    ]);
}
write_csv_row($out, []);
write_csv_row($out, ['TOTAL HOURS WORKED', number_format($r['hours_worked'], 2)]);
write_csv_row($out, ['TOTAL HOURS AMOUNT', number_format($r['billed_amount'], 2)]);
write_csv_row($out, ['TOTAL MILEAGE', number_format($r['mileage'], 1)]);
write_csv_row($out, ['TOTAL MILEAGE AMOUNT', number_format($r['mileage_amount'], 2)]);
write_csv_row($out, ['TOTAL EXPENSES', number_format($r['expenses_amount'], 2)]);
// Same basis as the PDF statement: all logged work and expenses, invoiced or not.
write_csv_row($out, ['TOTAL (ALL WORK AND EXPENSES TO DATE, INCLUDING PREVIOUSLY INVOICED)', number_format($r['total_billed'] + $r['expenses_amount'], 2)]);
// Drafts count as on an invoice (their work is no longer "not yet invoiced"); the
// part already submitted to the funder or paid is given on its own line, as the
// AT funding record reports it.
write_csv_row($out, ['ON INVOICES TO DATE (INCLUDING DRAFTS)', number_format($r['invoiced_amount'], 2)]);
write_csv_row($out, ['SUBMITTED TO FUNDER OR PAID TO DATE', number_format($r['submitted_amount'], 2)]);
write_csv_row($out, ['NOT YET INVOICED', number_format($r['unbilled_amount'] + $r['unbilled_expenses'], 2)]);
write_csv_row($out, ['HOURS REMAINING ON AUTH', number_format($r['hours_remaining'], 2)]);
write_csv_row($out, ['FUNDS REMAINING ON AUTH', number_format($r['funds_remaining'], 2)]);
write_csv_row($out, ['UNBILLED HOURS', number_format($r['unbilled_hours'], 2)]);
fclose($out);
