<?php
// Formal AT evaluation report — prefilled from a tech assessment, editable,
// downloadable as a letterhead PDF or emailed (signed) to the VR counselor.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('reports');

$aid = (int)($_GET['assessment'] ?? 0);
$stmt = db()->prepare('SELECT a.*, c.id AS cid, c.name AS client_name, c.dob, c.counselor_name, c.counselor_email, c.counselor_agency, c.ipe_goal
    FROM tech_assessments a JOIN clients c ON c.id = a.client_id WHERE a.id = ?');
$stmt->execute([$aid]);
$a = $stmt->fetch();
if (!$a) { flash('Assessment not found.', 'error'); redirect('clients.php'); }
scope_check_client_id((int)$a['cid'], $user);

// The prefilled text of each section; what the evaluator posted replaces it.
$defaults = [
    'background' => "Client presents with " . ($a['primary_disability'] ?: '[primary disability]') . ". An assistive technology evaluation was conducted on " . fmt_date($a['assessment_date']) . " to determine appropriate technology and training needs for vocational objectives.",
    'findings' => $a['summary'] ?: '',
    'recommendations' => "Primary technology recommended: " . $a['primary_tech'] . ".\n"
        . ($a['hardware_details'] ? "Hardware: {$a['hardware_details']}\n" : '')
        . ($a['software_version'] ? "Software: {$a['software_version']}\n" : '')
        . "Estimated training: [hours] hours of individualized instruction.",
    // The IPE employment goal is named when it is on file (3.0.45).
    'justification' => "The recommended technology is necessary for the client to access print material, complete essential job functions, and achieve the vocational goal identified in their Individualized Plan for Employment"
        . (trim((string)($a['ipe_goal'] ?? '')) !== '' ? ' (' . trim((string)$a['ipe_goal']) . ')' : '') . ". Without this technology and associated training, the client cannot independently perform the tasks required.",
];
// After a sign-in (session timeout) or a stale-token redirect, what the evaluator
// typed comes back as old input on a GET, as on report.php; it replaces the defaults.
$refill = $_SERVER['REQUEST_METHOD'] !== 'POST' && array_key_exists('background', $GLOBALS['OLD_INPUT'] ?? []);
$fields = [];
foreach ($defaults as $k => $d) $fields[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : ($refill ? old_in($k, $d) : $d);

// Section labels as on the form (used in messages and the error summary).
const EVAL_SECTIONS = ['background' => 'Background', 'findings' => 'Evaluation findings',
    'recommendations' => 'Recommendations', 'justification' => 'Justification of need'];

/** "Counselor — Agency" from whichever parts are recorded. (Built from parts:
 *  trim() with a multibyte character list strips bytes of accented letters.) */
function eval_counselor(array $a): string {
    return implode(' — ', array_filter([trim((string)($a['counselor_name'] ?? '')), trim((string)($a['counselor_agency'] ?? ''))], fn($s) => $s !== ''));
}

/** The closing line: a signature only on the emailed (signed) report. */
function eval_signoff(string $preparedBy, bool $signed): string {
    return ($signed ? 'Electronically signed by ' . $preparedBy : 'Draft — not signed. Prepared by ' . $preparedBy)
        . ' on ' . date('F j, Y') . ' — ' . brand('org') . '.';
}

/** File name (without extension) for the report's PDF and HTML copies: the
 *  client's name and today's date, like the other client documents. */
function eval_file_base(array $a): string {
    return 'AT_Evaluation_' . (trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)$a['client_name']) ?? '', '_') ?: (string)(int)$a['id']) . '_' . date('Ymd');
}

/** A typed name compared the way a person reads it (as consent.php does):
 *  Unicode-normalized when the intl extension is present, spaces collapsed,
 *  case-folded for every alphabet. */
function eval_name_key(string $s): string {
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    return mb_convert_case(preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s), MB_CASE_FOLD, 'UTF-8');
}

// AI: draft the narrative sections from the assessment, which the evaluator then
// edits before download/email. Only sections that are empty or still hold the
// prefilled text are drafted; a section the evaluator has written in is kept.
$sameText = fn(string $x, string $y): bool => trim(str_replace("\r\n", "\n", $x)) === trim(str_replace("\r\n", "\n", $y));
$toDraft = [];
foreach (EVAL_SECTIONS as $k => $label) if (trim($fields[$k]) === '' || $sameText($fields[$k], $defaults[$k])) $toDraft[$k] = $label;
$kept = array_diff_key(EVAL_SECTIONS, $toDraft);
// Offered only while the drafting feature itself is on (as amendment_request.php),
// not merely while AI is configured.
$aiDraftOn = ai_feature_enabled('draft');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_draft']) && $aiDraftOn && !$toDraft) {
    flash('Nothing was drafted: every section holds text you have written, and the AI never replaces it. To have a section drafted, clear it, then press "Draft narrative with AI" again.', 'error');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_draft']) && $aiDraftOn) {
    // The client's full record is the redaction list (every name part, contacts,
    // guardian, counselor, DOB in any written form). The narrative does not need
    // the date of birth, so it is not part of the context at all.
    $cr = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $cr->execute([(int)$a['cid']]);
    $clientRow = $cr->fetch() ?: ['name' => $a['client_name'], 'dob' => $a['dob'], 'counselor_name' => $a['counselor_name'], 'counselor_email' => $a['counselor_email']];
    $ctx = "Client: {$a['client_name']}\n"
        . "Assessment date: " . fmt_date($a['assessment_date']) . "\n"
        . "Primary disability: " . ($a['primary_disability'] ?: '[not recorded]') . "\n"
        . "Primary technology recommended: " . ($a['primary_tech'] ?: '[not recorded]') . "\n"
        . ($a['hardware_details'] ? "Hardware: {$a['hardware_details']}\n" : '')
        . ($a['software_version'] ? "Software: {$a['software_version']}\n" : '')
        . "Assessment summary / findings notes: " . ($a['summary'] ?: '[none recorded]') . "\n"
        . "VR Counselor: " . eval_counselor($a);
    $res = ai_run('draft',
        ai_system_base() . " You are drafting a formal Assistive Technology Evaluation Report. Return exactly four "
            . "sections, each wrapped in the given tags and nothing else: "
            . "[BACKGROUND]...[/BACKGROUND][FINDINGS]...[/FINDINGS][RECOMMENDATIONS]...[/RECOMMENDATIONS][JUSTIFICATION]...[/JUSTIFICATION]. "
            . "Justification must explain why the technology is necessary for the vocational goal. "
            . "Bracketed tags such as [CLIENT] and [COUNSELOR] stand for names that were withheld: keep them exactly as written and do not invent names.",
        "Draft the evaluation report sections from this assessment:\n\n" . $ctx,
        2000, $clientRow, (int)$user['id']);
    if ($res['ok']) {
        // Only sections open for drafting that the reply actually contains are
        // replaced; the rest are named. The names withheld from the AI are put
        // back here (as amendment_request.php does), so the report never goes out
        // saying [CLIENT].
        $names = ['[CLIENT]' => (string)$a['client_name'], '[COUNSELOR]' => trim((string)$a['counselor_name']) ?: 'the VR counselor'];
        if (trim((string)($clientRow['guardian_name'] ?? '')) !== '') $names['[GUARDIAN]'] = trim((string)$clientRow['guardian_name']);
        $missing = [];
        foreach ($toDraft as $k => $label) {
            $tag = strtoupper($k);
            if (preg_match('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/s', $res['text'], $m) && trim($m[1]) !== '') $fields[$k] = str_replace(array_keys($names), array_values($names), trim($m[1]));
            else $missing[] = $label;
        }
        $keptNote = $kept ? ' Kept as you wrote them: ' . implode(', ', $kept) . '.' : '';
        if (count($missing) === count($toDraft)) flash('The AI reply could not be read, so no section was changed. Try again, or edit the sections yourself.', 'error');
        elseif ($missing) flash('Draft created for some sections only. Not drafted (left as they were): ' . implode(', ', $missing) . '.' . $keptNote . ' Review and edit every section before downloading or emailing.', 'error');
        else flash('Draft created for ' . implode(', ', $toDraft) . '.' . $keptNote . ' Review and edit each section below before downloading or emailing.');
    } else {
        flash($res['error'], 'error');
    }
    // Recorded only when the assessment was actually sent to the provider (ai_run()
    // logged the call); a refusal before sending (feature off, usage limit) is not a draft.
    // A failed provider call is still recorded (the assessment may have reached the provider), noting that nothing was drafted.
    if (array_key_exists('usage_id', $res)) audit($user['id'], 'ai.draft', 'client', (int)$a['cid'], 'AT evaluation narrative' . ($res['ok'] ? '' : ' (AI call failed; nothing drafted)'));
}

function eval_html(array $a, array $fields, string $preparedBy, bool $signed = false): AccessibleDocument {
    $doc = new AccessibleDocument('Assistive Technology Evaluation Report — ' . $a['client_name']);
    $doc->paragraph(brand('org'));
    $doc->properties([
        'Client' => $a['client_name'] . ($a['dob'] ? ' (date of birth ' . fmt_date($a['dob']) . ')' : ''),
        'Evaluation date' => fmt_date($a['assessment_date']),
        'Vocational rehabilitation (VR) counselor' => eval_counselor($a),
        'Evaluator' => $preparedBy, 'Primary technology' => $a['primary_tech'],
    ]);
    foreach (['background' => 'Background', 'findings' => 'Evaluation Findings',
              'recommendations' => 'Recommendations', 'justification' => 'Justification of Need'] as $key => $heading) {
        $doc->heading($heading);
        $doc->paragraph($fields[$key] !== '' ? $fields[$key] : '—');
    }
    $doc->paragraph(eval_signoff($preparedBy, $signed));
    return $doc;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_html'])) {
    audit($user['id'], 'evalreport.download', 'client', (int)$a['cid'], 'html');
    eval_html($a, $fields, $user['name'])->download(eval_file_base($a) . '.html');
}

function eval_pdf(array $a, array $fields, string $preparedBy, bool $signed = false): string {
    require_once __DIR__ . '/inc/app_pdf.php';
    $pdf = new AppPdf('P', 'mm', 'Letter');
    $tx = fn(?string $s) => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? '') ?: '';
    $pdf->SetTitle($tx('Assistive Technology Evaluation Report — ' . $a['client_name']));
    $pdf->SetAutoPageBreak(true, 22);
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, 'Accessible equivalent: download HTML from the same form, or use the HTML email attachment.', 0, 1);
    $pdf->SetFont('Helvetica', 'B', 18);
    $pdf->SetTextColor(...brand_rgb());
    $pdf->Cell(0, 9, $tx(brand('org')), 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 5, $tx('Assistive Technology Evaluation Report'), 0, 1);
    $pdf->Ln(2);
    $pdf->SetDrawColor(...brand_rgb());
    $pdf->SetLineWidth(0.6);
    $pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetTextColor(0, 0, 0);
    foreach ([
        'Client' => $a['client_name'] . ($a['dob'] ? ' (DOB ' . fmt_date($a['dob']) . ')' : ''),
        'Evaluation date' => fmt_date($a['assessment_date']),
        'VR Counselor' => eval_counselor($a),
        'Evaluator' => $preparedBy,
        'Primary technology' => $a['primary_tech'],
    ] as $k => $v) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(45, 6, $tx($k), 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 6, $tx($v));
    }
    foreach ([
        'Background' => $fields['background'],
        'Evaluation Findings' => $fields['findings'],
        'Recommendations' => $fields['recommendations'],
        'Justification of Need' => $fields['justification'],
    ] as $heading => $text) {
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(...brand_rgb());
        $pdf->Cell(0, 7, $tx($heading), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 5.5, $tx($text !== '' ? $text : '—'));
    }
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'I', 10);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->MultiCell(0, 5.5, $tx(eval_signoff($preparedBy, $signed)));
    return $pdf->Output('S');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $bytes = eval_pdf($a, $fields, $user['name']);
    audit($user['id'], 'evalreport.download', 'client', (int)$a['cid'], 'pdf');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . eval_file_base($a) . '.pdf"');
    echo $bytes;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email_report'])) {
    $to = strtolower(trim(is_string($_POST['recipient'] ?? null) ? $_POST['recipient'] : ''));
    $errs = [];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $errs['recipient'] = 'Enter a valid email address, for example name@agency.org.';
    // As report.php and api.php send_report: a counselor email from the public referral
    // form is not written to until staff confirm it, unless a different recipient is typed.
    elseif ($to === strtolower(trim((string)($a['counselor_email'] ?? ''))) && $to !== '') {
        $cv = db()->prepare('SELECT counselor_verified FROM clients WHERE id = ?');
        $cv->execute([(int)$a['cid']]);
        if ((int)$cv->fetchColumn() !== 1) $errs['recipient'] = "The counselor email on this client has not been confirmed. Confirm it on the client's record first, or enter a different recipient.";
    }
    $signature = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
    if (!mb_check_encoding($signature, 'UTF-8') || eval_name_key($signature) !== eval_name_key((string)$user['name'])) $errs['signature'] = 'Type your full name (' . $user['name'] . ') to sign before emailing.';
    // A formal report must not go out with an unfilled [placeholder] (the default
    // text's [hours], or one the AI left for a missing detail).
    foreach (EVAL_SECTIONS as $k => $label) {
        if (preg_match('/\[(?=[^\[\]\n]*\p{L})[^\[\]\n]{1,80}\]/u', (string)$fields[$k], $m)) $errs[$k] = 'Replace the placeholder ' . $m[0] . ' with the actual detail before emailing.';
    }
    // At most 50 emailed reports per user per hour, in the same bucket as
    // report.php and the app's send_report, so a runaway script cannot flood mail.
    $rlKey = 'report-' . (int)$user['id'];
    $rl = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 3600 SECOND)');
    $rl->execute([$rlKey]);
    if ($errs) {
        // Announced once by the focused error summary at the top of the form.
        set_form_errors($errs);
    } elseif ((int)$rl->fetchColumn() >= 50) {
        flash('You have sent the maximum number of reports for now. Nothing was sent; your edits are below. Try again in an hour.', 'error');
    } else {
        $bytes = eval_pdf($a, $fields, $user['name'], true);
        $sent = send_app_mail_attach($to,
            'AT Evaluation Report — ' . fmt_date($a['assessment_date']),
            "Please find attached the Assistive Technology Evaluation Report for {$a['client_name']}, evaluated " . fmt_date($a['assessment_date']) . ". The accessible HTML attachment contains the same report as the PDF.\n\n"
            . "Electronically signed by {$user['name']}, " . brand('org') . ".\nReply to this email with any questions.\n",
            [['name' => eval_file_base($a) . '.pdf', 'mime' => 'application/pdf', 'bytes' => $bytes],
             ['name' => eval_file_base($a) . '.html', 'mime' => 'text/html', 'bytes' => eval_html($a, $fields, $user['name'], true)->html()]]);
        if ($sent) {
            try {
                db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)')
                    ->execute([(string)($_SERVER['REMOTE_ADDR'] ?? ''), $rlKey]);
            } catch (Throwable $e) {
                error_log('eval_report.php: report send not counted: ' . get_class($e) . ' code=' . $e->getCode());
            }
            // The email has gone: a failure recording it must not show an error page,
            // or the evaluator would send the report again (as report.php).
            $recorded = true;
            try {
                db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?)')
                    ->execute([(int)$a['cid'], 'Progress Report', 'AT evaluation report emailed',
                        "Evaluation report (assessment of " . fmt_date($a['assessment_date']) . ") emailed to {$to}, signed by {$user['name']}.", $user['id']]);
            } catch (Throwable $e) {
                $recorded = false;
                error_log('eval_report.php: report note not recorded: ' . get_class($e) . ' code=' . $e->getCode());
            }
            audit($user['id'], 'evalreport.send', 'client', (int)$a['cid'], 'to ' . $to);
            if ($recorded) flash("Evaluation report emailed to {$to} and logged on the client's file.");
            else flash("Evaluation report signed and emailed to {$to}, so do not send it again. It could not be logged on the client's file: add a Progress Report note saying it was sent.", 'error');
            redirect('client.php?id=' . (int)$a['cid'] . '&tab=assessments');
        }
        flash('The report email could not be sent.', 'error');
    }
}

// A signature that matched is typed in again after any refusal; a wrong one is not.
$sigPosted = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
$sigKeep = $sigPosted !== '' && mb_check_encoding($sigPosted, 'UTF-8') && eval_name_key($sigPosted) === eval_name_key((string)$user['name']) ? $sigPosted : '';

page_header('Evaluation report', $user, 'clients');
?>
<p><a href="client.php?id=<?= (int)$a['cid'] ?>&amp;tab=assessments"><span aria-hidden="true">&larr; </span>Back to <?= h($a['client_name']) ?></a></p>
<h1>AT evaluation report — <?= h($a['client_name']) ?></h1>
<p class="muted">Prefilled from the <?= fmt_date($a['assessment_date']) ?> assessment. Edit each section, then download the PDF or email it signed to the counselor.</p>

<?php if ($relWarn = release_warning((int)$a['cid'], 'VR counselor and agency', (string)($a['counselor_email'] ?? ''))): ?>
<p class="alert alert-info"><?= h($relWarn) ?></p>
<?php endif; ?>
<form method="post" action="eval_report.php?assessment=<?= $aid ?>" class="stacked" novalidate>
  <?= csrf_field() ?>
  <?= form_error_summary(['recipient' => 'Email to', 'signature' => 'Electronic signature'] + EVAL_SECTIONS) ?>
  <?php /* Enter in a text box presses the form's first submit button. This hidden
           copy of "Email signed report" (out of the tab order and hidden from
           screen readers; the visible one is below) makes emailing that default,
           so Enter never sends the assessment to the AI or replaces the edits. */ ?>
  <button type="submit" name="email_report" value="1" class="visually-hidden" tabindex="-1" aria-hidden="true">Email signed report to counselor</button>
  <?php foreach (['background' => 4, 'findings' => 6, 'recommendations' => 6, 'justification' => 5] as $k => $rows): ?>
  <div class="field"><label for="<?= $k ?>"><?= h(EVAL_SECTIONS[$k]) ?></label>
    <textarea id="<?= $k ?>" name="<?= $k ?>" rows="<?= $rows ?>" <?= field_aria($k) ?>><?= h($fields[$k]) ?></textarea><?= field_error($k) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <div class="field">
      <label for="recipient">Email to</label>
      <input type="email" id="recipient" name="recipient" value="<?= h(is_string($_POST['recipient'] ?? null) ? $_POST['recipient'] : ($refill ? old_in('recipient') : ($a['counselor_email'] ?? ''))) ?>" <?= field_aria('recipient') ?>><?= field_error('recipient') ?>
    </div>
    <div class="field">
      <label for="signature">Electronic signature <span class="hint">Type your full name (<?= h($user['name']) ?>) — required for emailing</span></label>
      <input type="text" id="signature" name="signature" autocomplete="name" value="<?= h($sigKeep) ?>" <?= field_aria('signature') ?>><?= field_error('signature') ?>
    </div>
    <div class="page-actions">
      <?php if ($aiDraftOn): ?>
      <button type="submit" name="ai_draft" value="1" class="btn btn-secondary" formnovalidate aria-describedby="ai-draft-hint">Draft narrative with AI</button>
      <?php endif; ?>
      <button type="submit" name="download_html" value="1" class="btn btn-secondary">Download accessible HTML</button>
      <button type="submit" name="download" value="1" class="btn btn-secondary">Download PDF</button>
      <button type="submit" name="email_report" value="1" class="btn btn-primary">Email signed report to counselor</button>
    </div>
    <?php if ($aiDraftOn): ?><p class="hint" id="ai-draft-hint">“Draft narrative with AI” fills only the sections above that are empty or still hold the prefilled text; a section you have written in is kept as it is. To have it drafted, clear it first. Review and edit every section before sending.</p><?php endif; ?>
  </div>
</form>
<?php page_footer();
