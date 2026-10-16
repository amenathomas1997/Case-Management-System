<?php
// Full client file export: ZIP containing a complete summary PDF plus copies of
// every uploaded document — for records requests and VR audits.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require_once __DIR__ . '/inc/client_export.php';
require __DIR__ . '/inc/app_pdf.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('clients');

// A failed export is shown as a normal page (title, language, a way back),
// not a bare line of text.
$exportFailed = function (int $status, string $title, string $message, string $backHref, string $backText) use ($user): never {
    http_response_code($status);
    page_header($title, $user, 'clients');
    echo '<h1>' . h($title) . '</h1>' . error_notice($message)
        . '<p><a href="' . h($backHref) . '">' . h($backText) . '</a></p>';
    page_footer();
    exit;
};

$id = (int)($_GET['id'] ?? 0);
$c = db()->prepare('SELECT * FROM clients WHERE id = ?');
$c->execute([$id]);
$client = $c->fetch();
if (!$client) $exportFailed(404, 'Client not found', 'This client record could not be found. It may have been merged into another record or purged.', 'clients.php', 'Back to the client list');
scope_check_client($client, $user);

$tx = fn(?string $s) => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? '') ?: '';

$doc = new AccessibleDocument('Complete Client File — ' . $client['name']);
$doc->paragraph(brand('org') . "\nGenerated " . date('F j, Y g:i A') . ' by ' . $user['name']);

$pdf = new AppPdf('P', 'mm', 'Letter');
$pdf->SetTitle($tx('Complete Client File — ' . $client['name']));
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$h1 = function (string $t) use ($pdf, $tx, $doc) {
    $doc->heading($t);
    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(...brand_rgb());
    // MultiCell wraps: a long track title (up to 190 characters) stays on the page.
    $pdf->MultiCell(0, 8, $tx($t));
    $pdf->SetTextColor(0, 0, 0);
};
// $pp writes to the PDF only (for entries the HTML twin shows as a table).
$pp = function (string $t, int $size = 10, string $style = '') use ($pdf, $tx) {
    $pdf->SetFont('Helvetica', $style, $size);
    $pdf->MultiCell(0, 5.5, $tx($t));
};
$p = function (string $t, int $size = 10, string $style = '') use ($pp, $doc) {
    $doc->paragraph($t);
    $pp($t, $size, $style);
};
// One entry's header line (a note, an assessment): bold in the PDF, a level-3
// heading in the HTML twin so a screen reader can move from entry to entry.
$h3 = function (string $t) use ($pp, $doc) {
    $doc->subheading($t);
    $pp($t, 10, 'B');
};

$pdf->SetFont('Helvetica', 'B', 17);
$pdf->SetTextColor(...brand_rgb());
$pdf->MultiCell(0, 9, $tx(brand('org')));
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetTextColor(60, 60, 60);
$pdf->MultiCell(0, 6, $tx('Complete Client File — generated ' . date('F j, Y g:i A') . ' by ' . $user['name']));
$pdf->SetTextColor(0, 0, 0);

$h1('Client');
$p("Name: {$client['name']}\nDate of birth: " . fmt_date($client['dob']) . "\nStatus: {$client['status']}\nEmail: " . ($client['email'] ?? '—') . "\nPhone: " . ($client['phone'] ?? '—') . "\nAddress: " . ($client['address'] ?? '—')
  // Joined from the non-empty parts: a byte-wise trim(' —') could cut a
  // multibyte letter in half and blank this whole paragraph.
  . "\nVocational rehabilitation (VR) counselor: " . implode(' — ', array_filter([trim((string)($client['counselor_name'] ?? '')), trim((string)($client['counselor_agency'] ?? ''))], 'strlen')) . ' (' . ($client['counselor_email'] ?? '—') . ')');

$h1('Status history');
$hist = db()->prepare('SELECT h.*, u.name AS who FROM client_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.client_id = ? ORDER BY h.changed_at');
$hist->execute([$id]);
$histRows = [];
foreach ($hist->fetchAll() as $row) {
    $from = $row['old_status'] ?: '(new record)';
    $pp(fmt_datetime($row['changed_at']) . ': changed from ' . $from . ' to ' . $row['new_status'] . ' (' . ($row['who'] ?? 'System') . ')' . ($row['note'] ? ' — ' . $row['note'] : ''));
    $histRows[] = [fmt_datetime($row['changed_at']), $from, $row['new_status'], $row['who'] ?? 'System', (string)($row['note'] ?? '')];
}
if ($histRows) $doc->table('Status history', ['Date', 'Changed from', 'Changed to', 'Changed by', 'Note'], $histRows);
else $p('None recorded.');

$h1('Tech assessments');
$assess = db()->prepare('SELECT * FROM tech_assessments WHERE client_id = ? ORDER BY assessment_date');
$assess->execute([$id]);
foreach ($assess->fetchAll() as $arow) {
    $h3(fmt_date($arow['assessment_date']) . ' — ' . $arow['primary_tech']
        . ($arow['primary_disability'] ? ' | ' . $arow['primary_disability'] : '')
        . ($arow['hardware_details'] ? ' | Hardware: ' . $arow['hardware_details'] : '')
        . ($arow['software_version'] ? ' | Software: ' . $arow['software_version'] : ''));
    if ($arow['summary']) $p($arow['summary']);
}

$tracks = db()->prepare('SELECT * FROM training_tracks WHERE client_id = ? ORDER BY created_at');
$tracks->execute([$id]);
foreach ($tracks->fetchAll() as $t) {
    $r = track_rollup((int)$t['id']);
    $h1('Training track: ' . $t['title'] . ' (' . $t['status'] . ')');
    $p('Authorization ' . $t['auth_number'] . ' — ' . $t['funding_agency'] . ' | ' . fmt_hours($r['authorized_hours']) . ' at ' . fmt_money($r['hourly_rate']) . ' per hour | ' . fmt_date($t['start_date']) . ' to ' . fmt_date($t['expiration_date']));
    // total_billed is the value of all logged work, whether invoiced or not.
    $p('Used: ' . fmt_hours($r['hours_worked']) . ' | Logged work value: ' . fmt_money($r['total_billed']) . ' | Invoiced: ' . fmt_money($r['invoiced_amount']) . ' | Remaining: ' . fmt_hours($r['hours_remaining']));
    $goals = db()->prepare('SELECT * FROM smart_goals WHERE track_id = ?');
    $goals->execute([(int)$t['id']]);
    foreach ($goals->fetchAll() as $g) {
        $p('[' . $g['status'] . '] ' . $g['specific_goal'] . ' — Measure: ' . $g['measurable_metric'] . ($g['target_date'] ? ' (target ' . fmt_date($g['target_date']) . ')' : ''));
    }
    $logs = db()->prepare('SELECT * FROM work_logs WHERE track_id = ? ORDER BY work_date');
    $logs->execute([(int)$t['id']]);
    $logRows = [];
    foreach ($logs->fetchAll() as $l) {
        $pp(fmt_date($l['work_date']) . ' — ' . number_format((float)$l['hours'], 2) . ' hours — ' . $l['description']);
        $logRows[] = [fmt_date($l['work_date']), number_format((float)$l['hours'], 2), (string)$l['description']];
    }
    if ($logRows) $doc->table('Work logs: ' . $t['title'], ['Date', 'Hours', 'Description'], $logRows);
    $amds = db()->prepare('SELECT * FROM auth_amendments WHERE track_id = ? ORDER BY changed_at');
    $amds->execute([(int)$t['id']]);
    foreach ($amds->fetchAll() as $am) {
        $p('Amendment ' . fmt_datetime($am['changed_at']) . ': '
            . ($am['new_hours'] !== null ? "hours changed from {$am['old_hours']} to {$am['new_hours']}; " : '')
            . ($am['new_rate'] !== null ? "rate changed from {$am['old_rate']} to {$am['new_rate']}; " : '')
            . ($am['new_expiration'] !== null || $am['old_expiration'] !== null ? 'expiration changed from ' . fmt_date($am['old_expiration']) . ' to ' . fmt_date($am['new_expiration']) . '; ' : '')
            // Columns from mig_3.0.8 (absent on an older database), worded as track.php shows them.
            . (($am['new_auth_number'] ?? null) !== null ? 'authorization number changed from ' . ($am['old_auth_number'] ?? '—') . ' to ' . $am['new_auth_number'] . '; ' : '')
            . (($am['new_mileage_rate'] ?? null) !== null ? 'mileage rate changed from $' . number_format((float)($am['old_mileage_rate'] ?? 0), 3) . ' to $' . number_format((float)$am['new_mileage_rate'], 3) . ' per mile; ' : '')
            . ($am['reason'] ? '— ' . $am['reason'] : ''), 10, 'I');
    }
}

$h1('Invoices');
// Invoices (numbers, payment references) are billing records: like every other
// invoice view, they are only shown to staff with billing access.
$invList = [];
if (can('billing')) {
    $invs = db()->prepare('SELECT i.* FROM invoices i JOIN training_tracks t ON t.id = i.track_id WHERE t.client_id = ? ORDER BY i.created_at');
    $invs->execute([$id]);
    $invList = $invs->fetchAll();
}
$invRows = [];
foreach ($invList as $iv) {
    $pp($iv['invoice_number'] . ' — ' . fmt_money((float)$iv['total_amount']) . ' — ' . $iv['status']
        . ($iv['submitted_at'] ? ' (submitted ' . fmt_date($iv['submitted_at']) . ')' : '')
        . ($iv['paid_at'] ? ' (paid ' . fmt_date($iv['paid_at']) . ($iv['payment_ref'] ? ', payment reference ' . $iv['payment_ref'] : '') . ')' : ''));
    $invRows[] = [$iv['invoice_number'], fmt_money((float)$iv['total_amount']), $iv['status'],
        $iv['submitted_at'] ? fmt_date($iv['submitted_at']) : '—', $iv['paid_at'] ? fmt_date($iv['paid_at']) : '—', (string)($iv['payment_ref'] ?? '')];
}
if ($invRows) $doc->table('Invoices', ['Invoice', 'Amount', 'Status', 'Submitted', 'Paid', 'Payment reference'], $invRows);
elseif (!can('billing')) $p('Not included: invoices need billing access, which this account does not have.');
else $p('None recorded.');

$h1('Client notes');
$notes = db()->prepare('SELECT n.*, u.name AS who FROM client_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.client_id = ? ORDER BY n.created_at');
$notes->execute([$id]);
foreach ($notes->fetchAll() as $n) {
    $h3(fmt_datetime($n['created_at']) . ' — ' . $n['note_type'] . ' — ' . $n['subject'] . ' (' . ($n['who'] ?? 'System') . ')');
    $p($n['body']);
}

$docs = db()->prepare('SELECT * FROM client_documents WHERE client_id = ?');
$docs->execute([$id]);
$docs = $docs->fetchAll();
$attachRows = db()->prepare('SELECT id, attachment_path, attachment_name FROM tech_assessments WHERE client_id = ? AND attachment_path IS NOT NULL');
$attachRows->execute([$id]);
$attachRows = $attachRows->fetchAll();

// Which stored files are actually on the server, worked out before the summary
// is written, so the summary never lists a missing file as included.
$docOnDisk = $attachOnDisk = [];
foreach ($docs as $d) $docOnDisk[(int)$d['id']] = is_file(cfg('upload_dir') . '/' . basename($d['path']));
foreach ($attachRows as $ar) $attachOnDisk[(int)$ar['id']] = is_file(cfg('upload_dir') . '/' . basename($ar['attachment_path']));
$missingCount = count(array_filter($docOnDisk, fn($x) => !$x)) + count(array_filter($attachOnDisk, fn($x) => !$x));
$notIncluded = ' — FILE NOT FOUND ON THE SERVER, NOT INCLUDED';

$h1('Documents included in this export');
if (!$docs && !$attachRows) $p('None on file.');
if ($missingCount) $p('Missing files: ' . $missingCount . ' stored file(s) listed below could not be found on the server and are not in this export. Their records are listed in client_record.json under missing_attachments.', 10, 'B');
foreach ($docs as $d) $p(($d['category'] === 'Client Upload' ? 'Shared by client' : $d['category']) . ' — ' . $d['title'] . ' (' . $d['orig_name'] . ', uploaded ' . fmt_date($d['created_at']) . ')' . ($docOnDisk[(int)$d['id']] ? '' : $notIncluded));
foreach ($attachRows as $ar) $p('Assessment attachment — ' . $ar['attachment_name'] . ($attachOnDisk[(int)$ar['id']] ? '' : $notIncluded));

$pdf->Ln(3);
$p('An accessible HTML equivalent of this summary and a complete structured case record (JSON) are included in the export ZIP.');
$pdfBytes = $pdf->Output('S');

// Assemble the ZIP
$safe = preg_replace('/[^A-Za-z0-9]+/', '_', $client['name']);
// The unencrypted ZIP is built in the private directory beside the uploads
// (never a shared temp folder), the place the backups use; the system temp
// folder only if neither private folder can be written to.
$tmpDir = sys_get_temp_dir();
foreach ([dirname((string)cfg('upload_dir')), (string)cfg('upload_dir')] as $privDir) {
    if ((string)cfg('upload_dir') !== '' && is_dir($privDir) && is_writable($privDir)) { $tmpDir = $privDir; break; }
}
$tmp = tempnam($tmpDir, '.cxp_');
if ($tmp !== false) @chmod($tmp, 0600);
if ($tmp === false) $exportFailed(500, 'Export not ready', 'Could not prepare the export. Please try again.', 'client.php?id=' . $id, 'Back to ' . $client['name']);
// The ZIP holds the whole client file: remove it even when the download is cut
// off (a dropped connection or a time limit ends the script without `finally`).
ignore_user_abort(true);
register_shutdown_function(static function () use ($tmp): void { if (is_file($tmp)) @unlink($tmp); });
$zip = new ZipArchive();
try {
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the export archive.');
    if (!$zip->addFromString("client_file_{$safe}.pdf", $pdfBytes)
        || !$zip->addFromString("client_file_{$safe}.html", $doc->html())) throw new RuntimeException('Could not add the export summaries.');
    $records = client_export_records(db(), $id);
    $missing = [];
    foreach ($docs as $d) {
        $path = cfg('upload_dir') . '/' . basename($d['path']);
        $entry = 'documents/' . (int)$d['id'] . '_' . basename(str_replace('\\', '/', $d['orig_name']));
        if (!is_file($path)) { $missing[] = $entry; continue; }
        if (!$zip->addFile($path, $entry)) throw new RuntimeException('Could not include a document.');
    }
    foreach ($attachRows as $ar) {
        $path = cfg('upload_dir') . '/' . basename($ar['attachment_path']);
        $entry = 'documents/assessment_' . (int)$ar['id'] . '_' . basename(str_replace('\\', '/', $ar['attachment_name']));
        if (!is_file($path)) { $missing[] = $entry; continue; }
        if (!$zip->addFile($path, $entry)) throw new RuntimeException('Could not include an assessment attachment.');
    }
    $records['missing_attachments'] = $missing;
    if (!$zip->addFromString('client_record.json', json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) throw new RuntimeException('Could not include the case data.');
    if (!$zip->close()) throw new RuntimeException('Could not finish the export archive.');
    audit($user['id'], 'client.export', 'client', $id);
    if ($missing) {
        // Shown on the next page: the download itself cannot carry a message.
        flash('The export of ' . $client['name'] . ' is missing ' . count($missing) . ' stored file(s) that could not be found on the server. The summary in the ZIP lists them; do not rely on this export as a complete copy.', 'error');
    }
    // A long download must not hold the session lock for the user's other tabs.
    session_write_close();
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="client_file_' . $safe . '_' . date('Ymd') . '.zip"');
    readfile($tmp);
} finally {
    @unlink($tmp);
}
