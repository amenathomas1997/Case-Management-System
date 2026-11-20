<?php
declare(strict_types=1);
// The formal AT evaluation report (4.37.0: moved from eval_report.php, shared with the apps'
// eval_report, ai_eval_draft and send_eval_report): the assessment it is built from, the prefilled
// sections, the AI draft that fills only untouched sections, the signed email with its PDF and HTML,
// and the accessible HTML copy. The page and the apps give the same sentences, audit and note.

// Section labels as on the form (used in messages and the error summary).
const EVAL_SECTIONS = ['background' => 'Background', 'findings' => 'Evaluation findings',
    'recommendations' => 'Recommendations', 'justification' => 'Justification of need'];

/** The hint above the AI button, the page's and the apps' sentence. */
const EVAL_AI_HINT = '“Draft narrative with AI” fills only the sections above that are empty or still hold the prefilled text; a section you have written in is kept as it is. To have it drafted, clear it first. Review and edit every section before sending.';

/** A typed name compared the way a person reads it (as consent.php, eval_report.php and report.php
 *  do): Unicode-normalized when the intl extension is present, spaces collapsed, case-folded for
 *  every alphabet. api.php's api_name_key() is this rule. */
function person_name_key(string $s): string {
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    return mb_convert_case(preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s), MB_CASE_FOLD, 'UTF-8');
}

/** One tech assessment with the client facts the report uses (cid, client_name, dob, the counselor,
 *  ipe_goal), or null. The caller checks the client's scope. */
function eval_load(int $assessmentId): ?array {
    $stmt = db()->prepare('SELECT a.*, c.id AS cid, c.name AS client_name, c.dob, c.counselor_name, c.counselor_email, c.counselor_agency, c.counselor_verified, c.ipe_goal
        FROM tech_assessments a JOIN clients c ON c.id = a.client_id WHERE a.id = ?');
    $stmt->execute([$assessmentId]);
    return $stmt->fetch() ?: null;
}

/** The prefilled text of each section. */
function eval_defaults(array $a): array {
    return [
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
}

/** The sections as sent (each a string; one not sent keeps its prefilled text). */
function eval_fields(array $a, array $posted): array {
    $fields = [];
    foreach (eval_defaults($a) as $k => $d) $fields[$k] = is_string($posted[$k] ?? null) ? $posted[$k] : $d;
    return $fields;
}

/** Whether a section still holds its prefilled text (line endings and outer spaces aside). */
function eval_same_text(string $x, string $y): bool {
    return trim(str_replace("\r\n", "\n", $x)) === trim(str_replace("\r\n", "\n", $y));
}

/** "Counselor — Agency" from whichever parts are recorded. (Built from parts: trim() with a
 *  multibyte character list strips bytes of accented letters.) */
function eval_counselor(array $a): string {
    return implode(' — ', array_filter([trim((string)($a['counselor_name'] ?? '')), trim((string)($a['counselor_agency'] ?? ''))], fn($s) => $s !== ''));
}

/** The closing line: a signature only on the emailed (signed) report. */
function eval_signoff(string $preparedBy, bool $signed): string {
    return ($signed ? 'Electronically signed by ' . $preparedBy : 'Draft — not signed. Prepared by ' . $preparedBy)
        . ' on ' . date('F j, Y') . ' — ' . brand('org') . '.';
}

/** File name (without extension) for the report's PDF and HTML copies: the client's name and
 *  today's date, like the other client documents. */
function eval_file_base(array $a): string {
    return 'AT_Evaluation_' . (trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)$a['client_name']) ?? '', '_') ?: (string)(int)$a['id']) . '_' . date('Ymd');
}

/** The report as an accessible HTML document. */
function eval_html(array $a, array $fields, string $preparedBy, bool $signed = false): AccessibleDocument {
    require_once __DIR__ . '/document.php';
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

/** The report as a letterhead PDF (bytes). */
function eval_pdf(array $a, array $fields, string $preparedBy, bool $signed = false): string {
    require_once __DIR__ . '/app_pdf.php';
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

/**
 * Draft the narrative with AI. Only sections empty or still holding the prefilled text are drafted;
 * a section the evaluator has written in is kept. The names withheld from the AI are put back.
 * Returns ['ok' => false, 'error'] when the AI could not be asked, else ['ok' => true, 'sections'
 * (all four, drafted ones replaced), 'drafted', 'kept', 'missing' (keys), 'message', 'is_error'
 * (the page flashes it as an error), 'truncated']. Audited ai.draft when the assessment was sent.
 */
function eval_ai_draft(array $a, array $sections, int $uid, string $channel = 'web'): array {
    $defaults = eval_defaults($a);
    $fields = [];
    foreach ($defaults as $k => $d) $fields[$k] = is_string($sections[$k] ?? null) ? $sections[$k] : $d;
    $toDraft = [];
    foreach (EVAL_SECTIONS as $k => $label) if (trim($fields[$k]) === '' || eval_same_text($fields[$k], $defaults[$k])) $toDraft[$k] = $label;
    $kept = array_diff_key(EVAL_SECTIONS, $toDraft);
    $base = ['ok' => true, 'sections' => $fields, 'drafted' => [], 'kept' => array_keys($kept), 'missing' => [], 'is_error' => true, 'truncated' => false];
    if (!$toDraft) return $base + ['message' => 'Nothing was drafted: every section holds text you have written, and the AI never replaces it. To have a section drafted, clear it, then press "Draft narrative with AI" again.'];
    // The client's full record is the redaction list (every name part, contacts, guardian,
    // counselor, DOB in any written form). The narrative does not need the date of birth.
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
        2000, $clientRow, $uid);
    // Recorded only when the assessment was actually sent to the provider (ai_run() logged the
    // call); a refusal before sending (feature off, usage limit) is not a draft.
    if (array_key_exists('usage_id', $res)) audit($uid, 'ai.draft', 'client', (int)$a['cid'], 'AT evaluation narrative' . ($res['ok'] ? '' : ' (AI call failed; nothing drafted)') . ($channel === 'mobile' ? ' (mobile)' : ''));
    if (!$res['ok']) return ['ok' => false, 'error' => (string)$res['error']];
    $names = ['[CLIENT]' => (string)$a['client_name'], '[COUNSELOR]' => trim((string)$a['counselor_name']) ?: 'the VR counselor'];
    if (trim((string)($clientRow['guardian_name'] ?? '')) !== '') $names['[GUARDIAN]'] = trim((string)$clientRow['guardian_name']);
    $missing = [];
    $drafted = [];
    foreach ($toDraft as $k => $label) {
        $tag = strtoupper($k);
        if (preg_match('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/s', (string)$res['text'], $m) && trim($m[1]) !== '') { $fields[$k] = str_replace(array_keys($names), array_values($names), trim($m[1])); $drafted[] = $k; }
        else $missing[$k] = $label;
    }
    $keptNote = $kept ? ' Kept as you wrote them: ' . implode(', ', $kept) . '.' : '';
    if (count($missing) === count($toDraft)) { $message = 'The AI reply could not be read, so no section was changed. Try again, or edit the sections yourself.'; $isError = true; }
    elseif ($missing) { $message = 'Draft created for some sections only. Not drafted (left as they were): ' . implode(', ', $missing) . '.' . $keptNote . ' Review and edit every section before downloading or emailing.'; $isError = true; }
    else { $message = 'Draft created for ' . implode(', ', $toDraft) . '.' . $keptNote . ' Review and edit each section below before downloading or emailing.'; $isError = false; }
    $truncated = !empty($res['truncated']);
    // 4.37.0: an incomplete reply says so, on the website too.
    if ($truncated) $message .= ' Incomplete: the AI stopped before the end. Review it closely.';
    return ['ok' => true, 'sections' => $fields, 'drafted' => $drafted, 'kept' => array_keys($kept), 'missing' => array_keys($missing), 'message' => $message, 'is_error' => $isError, 'truncated' => $truncated];
}

/** Whether the counselor email on the client is confirmed. */
function eval_counselor_verified(array $a): bool {
    $cv = db()->prepare('SELECT counselor_verified FROM clients WHERE id = ?');
    $cv->execute([(int)$a['cid']]);
    return (int)$cv->fetchColumn() === 1;
}

/**
 * Email the signed report (PDF and accessible HTML attached) and log it on the client's file. The
 * checks, in order: a valid recipient that is not the client's unconfirmed counselor email, the
 * evaluator's own name typed as the signature, no unfilled [placeholder] in any section, and at most
 * 50 reports an hour (shared with send_report). Returns ['ok' => true, 'message', 'warning' (true when
 * sent but not logged; the message says so)], ['ok' => false, 'errors' => [field => sentence]] with
 * the fields to, signature and the section keys, or ['ok' => false, 'error', 'code' (429|502)].
 */
function eval_send(array $a, array $sections, string $to, string $signature, array $user, string $channel = 'web'): array {
    $fields = eval_fields($a, $sections);
    $to = strtolower(trim($to));
    $errs = [];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $errs['to'] = 'Enter a valid email address, for example name@agency.org.';
    // As report.php and send_report: a counselor email from the public referral form is not written
    // to until staff confirm it, unless a different recipient is typed.
    elseif ($to === strtolower(trim((string)($a['counselor_email'] ?? ''))) && $to !== '' && !eval_counselor_verified($a)) {
        $errs['to'] = "The counselor email on this client has not been confirmed. Confirm it on the client's record first, or enter a different recipient.";
    }
    $signature = trim($signature);
    if (!mb_check_encoding($signature, 'UTF-8') || person_name_key($signature) !== person_name_key((string)$user['name'])) $errs['signature'] = 'Type your full name (' . $user['name'] . ') to sign before emailing.';
    // A formal report must not go out with an unfilled [placeholder] (the default text's [hours],
    // or one the AI left for a missing detail).
    foreach (EVAL_SECTIONS as $k => $label) {
        if (preg_match('/\[(?=[^\[\]\n]*\p{L})[^\[\]\n]{1,80}\]/u', (string)$fields[$k], $m)) $errs[$k] = 'Replace the placeholder ' . $m[0] . ' with the actual detail before emailing.';
    }
    if ($errs) return ['ok' => false, 'errors' => $errs];
    // At most 50 emailed reports per user per hour, in the same bucket as report.php and the app's
    // send_report, so a runaway script cannot flood mail.
    $rlKey = 'report-' . (int)$user['id'];
    $rl = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 3600 SECOND)');
    $rl->execute([$rlKey]);
    if ((int)$rl->fetchColumn() >= 50) return ['ok' => false, 'error' => 'You have sent the maximum number of reports for now. Nothing was sent; your edits are below. Try again in an hour.', 'code' => 429];
    $bytes = eval_pdf($a, $fields, $user['name'], true);
    $sent = send_app_mail_attach($to,
        'AT Evaluation Report — ' . fmt_date($a['assessment_date']),
        "Please find attached the Assistive Technology Evaluation Report for {$a['client_name']}, evaluated " . fmt_date($a['assessment_date']) . ". The accessible HTML attachment contains the same report as the PDF.\n\n"
        . "Electronically signed by {$user['name']}, " . brand('org') . ".\nReply to this email with any questions.\n",
        [['name' => eval_file_base($a) . '.pdf', 'mime' => 'application/pdf', 'bytes' => $bytes],
         ['name' => eval_file_base($a) . '.html', 'mime' => 'text/html', 'bytes' => eval_html($a, $fields, $user['name'], true)->html()]]);
    if (!$sent) return ['ok' => false, 'error' => 'The report email could not be sent.', 'code' => 502];
    try {
        db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)')->execute([client_ip(), $rlKey]);
    } catch (Throwable $e) {
        error_log('eval_send: report send not counted: ' . get_class($e) . ' code=' . $e->getCode());
    }
    // The email has gone: a failure recording it must not show an error, or the evaluator would
    // send the report again (as report.php).
    $recorded = true;
    try {
        db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?)')
            ->execute([(int)$a['cid'], 'Progress Report', 'AT evaluation report emailed',
                "Evaluation report (assessment of " . fmt_date($a['assessment_date']) . ") emailed to {$to}, signed by {$user['name']}.", $user['id']]);
    } catch (Throwable $e) {
        $recorded = false;
        error_log('eval_send: report note not recorded: ' . get_class($e) . ' code=' . $e->getCode());
    }
    audit((int)$user['id'], 'evalreport.send', 'client', (int)$a['cid'], 'to ' . $to . ($channel === 'mobile' ? ' (mobile)' : ''));
    return $recorded
        ? ['ok' => true, 'warning' => false, 'to' => $to, 'message' => eval_sent_message($to)]
        : ['ok' => true, 'warning' => true, 'to' => $to, 'message' => eval_sent_unlogged_message($to)];
}

/** The confirmation once the report has gone and been logged. */
function eval_sent_message(string $to): string {
    return "Evaluation report emailed to {$to} and logged on the client's file.";
}

/** The warning when the report went but could not be logged on the client's file. */
function eval_sent_unlogged_message(string $to): string {
    return "Evaluation report signed and emailed to {$to}, so do not send it again. It could not be logged on the client's file: add a Progress Report note saying it was sent.";
}
