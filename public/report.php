<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/report_builder.php';
csrf_check();
$user = require_login();
require_perm('reports');

$trackId = (int)($_GET['track'] ?? 0);
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
// The form shows what the user typed: the posted values when it re-renders in place
// (after an AI draft), or the values kept across an error redirect (keep_input()).
$refill = !$posted && old_in('send_report') !== '';
$introIn = $posted ? (is_string($_POST['intro'] ?? null) ? $_POST['intro'] : '') : old_in('intro');
// 3.0.47: the reporting period (since the last report, by default), barriers and
// recommendations, and a Final Report for closing.
$fieldIn = fn(string $k): string => $posted ? (is_string($_POST[$k] ?? null) ? $_POST[$k] : '') : old_in($k);
$tq = db()->prepare('SELECT last_report_at FROM training_tracks WHERE id = ?');
$tq->execute([$trackId]);
$lastReportAt = $tq->fetchColumn() ?: null;
$finalMode = $fieldIn('report_kind') === 'final' || (!$posted && !$refill && ($_GET['final'] ?? '') === '1');
$periodOn = $lastReportAt && !$finalMode && ($posted || $refill ? $fieldIn('period') === '1' : true);
$barriersIn = $fieldIn('barriers');
$recommendIn = $fieldIn('recommendations');
// The page's own h1 and "Preview" h2 sit above the embedded report, so its title is an h3.
$report = build_track_report($trackId, $user['name'], trim($introIn), 3,
    ['since' => $periodOn ? $lastReportAt : null, 'final' => $finalMode, 'barriers' => $barriersIn, 'recommendations' => $recommendIn]);
if (!$report) { flash('Training track not found.', 'error'); redirect('clients.php'); }
scope_check_client_id((int)$report['track']['cid'], $user);
$t = $report['track'];

/** A typed name compared the way a person reads it (as consent.php and
 *  eval_report.php do): Unicode-normalized when the intl extension is present,
 *  spaces collapsed, case-folded for every alphabet. */
function report_name_key(string $s): string {
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    return mb_convert_case(preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s), MB_CASE_FOLD, 'UTF-8');
}
/** The first unfilled [PLACEHOLDER] in a cover note (the same test as eval_report.php),
 *  or null. A screen reader at its default punctuation level does not voice the
 *  brackets, so "[CLIENT]" would sound like a finished word. */
function report_placeholder(string $s): ?string {
    return preg_match('/\[(?=[^\[\]\n]*\p{L})[^\[\]\n]{1,80}\]/u', $s, $m) ? $m[0] : null;
}

// AI: draft a short cover narrative from the report data. Fills the intro field,
// which the staff member then edits before sending.
$aiDraft = '';
if ($posted && isset($_POST['ai_draft']) && !ai_feature_enabled('draft')) {
    // Pressed on a page loaded before drafting was turned off: nothing is sent or audited.
    flash('That AI feature is turned off. An administrator can enable it under AI assistant settings.', 'error');
} elseif ($posted && isset($_POST['ai_draft'])) {
    // The prompt is the report with the client's name and DOB, the counselor's name and
    // agency, and the authorization number already replaced by placeholders; the full
    // client row lets ai_run() also mask the client's own details wherever else they
    // appear (session descriptions, the typed message).
    $cr = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $cr->execute([(int)$t['cid']]);
    $clientRow = $cr->fetch() ?: ['name' => $t['client_name'] ?? ''];
    $res = ai_run('draft',
        ai_system_base() . ' You are drafting a brief cover note (2–3 short paragraphs) that a VR instructor will put at '
            . 'the top of a ' . ($finalMode ? 'final report at the close of training, summarizing what the client achieved and the outcome' : 'training progress report') . ' to the counselor. Summarize progress and next steps warmly and professionally. '
            . 'Do not restate the tables of hours — those follow below your note. Details in square brackets, such as [CLIENT] '
            . 'or [COUNSELOR], were withheld for privacy: keep them as placeholders and do not invent names, dates or numbers.',
        "Write the cover note for this training progress report.\n\n" . $report['ai_text'],
        900, $clientRow, (int)$user['id']);
    if ($res['ok']) {
        // The details withheld from the AI are put back here, as amendment_request.php does.
        $restore = ['[CLIENT]' => (string)($t['client_name'] ?? ''), '[COUNSELOR]' => trim((string)($t['counselor_name'] ?? '')), '[AUTH]' => trim((string)($t['auth_number'] ?? '')),
            '[EMPLOYER]' => (string)(client_last_closure((int)$t['cid'])['employer'] ?? '')];
        $aiDraft = strtr($res['text'], array_filter($restore, fn($v) => $v !== ''));
        // A message the user already typed is kept: the draft goes below it, never in its place.
        $typedIntro = trim($introIn) !== '';
        $left = report_placeholder($aiDraft);
        flash(($typedIntro ? 'Draft added below your message' : 'Draft created') . ' — review and edit it below, then send.'
            . ($left !== null ? ' It still contains the placeholder ' . $left . ': replace it with the actual detail before sending.' : ''));
        // Recorded only when a draft was written (a refused or failed request drafts nothing).
        audit($user['id'], 'ai.draft', 'client', (int)$t['cid'], 'VRC progress cover note');
    }
    else flash($res['error'], 'error');
}

// AI: draft the barriers and recommendations (3.0.47). Each fills its box only when the box
// is empty; text already typed is kept and the draft goes below it.
if ($posted && isset($_POST['ai_sections']) && ai_feature_enabled('draft')) {
    $cr = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $cr->execute([(int)$t['cid']]);
    $sec = report_ai_sections($report['ai_text'], $cr->fetch() ?: ['name' => $t['client_name'] ?? ''], (int)$user['id'], $finalMode);
    if ($sec['ok']) {
        $restore = ['[CLIENT]' => (string)($t['client_name'] ?? ''), '[COUNSELOR]' => trim((string)($t['counselor_name'] ?? '')), '[AUTH]' => trim((string)($t['auth_number'] ?? '')),
            '[EMPLOYER]' => (string)(client_last_closure((int)$t['cid'])['employer'] ?? '')];
        $fill = fn(string $mine, string $draft): string => trim($mine) === '' ? strtr($draft, array_filter($restore, fn($v) => $v !== '')) : rtrim($mine) . "\n" . strtr($draft, array_filter($restore, fn($v) => $v !== ''));
        $barriersIn = $fill($barriersIn, $sec['barriers']);
        $recommendIn = $fill($recommendIn, $sec['recommendations']);
        $report = build_track_report($trackId, $user['name'], trim($introIn), 3,
            ['since' => $periodOn ? $lastReportAt : null, 'final' => $finalMode, 'barriers' => $barriersIn, 'recommendations' => $recommendIn]);
        flash('Barriers and recommendations drafted. Read and edit them below before sending.');
        audit($user['id'], 'ai.draft', 'client', (int)$t['cid'], ($finalMode ? 'final' : 'progress') . ' report barriers and recommendations');
    } else {
        flash($sec['error'], 'error');
    }
}

if ($posted && isset($_POST['send_report'])) {
    // Each rendering of the form carries a one-time send token, used up here before
    // anything is sent. A second press (or a resubmitted page) while the first send is
    // still going waits for the session, then finds its token used and is refused, so
    // the counselor never gets the report, its acknowledgment link or the note twice.
    $sendTok = is_string($_POST['send_token'] ?? null) ? $_POST['send_token'] : '';
    $sendOk = $sendTok !== '' && (int)($_SESSION['report_send_tokens'][$sendTok] ?? 0) === $trackId;
    if ($sendTok !== '') unset($_SESSION['report_send_tokens'][$sendTok]);
    if (!$sendOk) {
        keep_input();
        flash('The report was not sent: it was already sent from this page, or the page was out of date. Check the client\'s notes for "Training report emailed to VRC" before you send it again.', 'error');
        redirect('report.php?track=' . $trackId);
    }
    $to = strtolower(trim(is_string($_POST['recipient'] ?? null) ? $_POST['recipient'] : ''));
    $ccSelf = !empty($_POST['cc_self']);
    $signature = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
    // Errors go on their fields; the cover note, recipient and copy choice are kept
    // (keep_input() never keeps the signature, which is retyped).
    $errs = [];
    if (mb_strlen($to) > 190 || !filter_var($to, FILTER_VALIDATE_EMAIL)) $errs['recipient'] = 'Enter a valid recipient email address.';
    // As api.php send_report: a counselor email that came in on the public referral
    // form and has not been confirmed by staff is not written to until it is, unless
    // the user typed a different recipient on purpose.
    elseif ($to === strtolower(trim((string)($t['counselor_email'] ?? ''))) && $to !== '') {
        $cv = db()->prepare('SELECT counselor_verified FROM clients WHERE id = ?');
        $cv->execute([(int)$t['cid']]);
        if ((int)$cv->fetchColumn() !== 1) $errs['recipient'] = "The counselor email on this client has not been confirmed. Confirm it on the client's record first, or enter a different recipient.";
    }
    // A cover note must not go out with an unfilled [placeholder] from an AI draft.
    $introPh = report_placeholder(trim($introIn));
    if ($introPh !== null) $errs['intro'] = 'Replace the placeholder ' . $introPh . ' with the actual detail before sending.';
    if (!mb_check_encoding($signature, 'UTF-8') || report_name_key($signature) !== report_name_key((string)$user['name'])) $errs['signature'] = 'To sign the report, type your full name exactly as it appears on your account (' . $user['name'] . ').';
    if ($errs) {
        keep_input();
        keep_field_errors($errs);
        redirect('report.php?track=' . $trackId);
    }
    // At most 50 emailed reports per user per hour, counted in the same bucket as the
    // app's send_report (api.php): room for a whole caseload's month-end reports, while
    // a runaway script still cannot flood counselors.
    $rlKey = 'report-' . (int)$user['id'];
    $rl = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 3600 SECOND)');
    $rl->execute([$rlKey]);
    if ((int)$rl->fetchColumn() >= 50) {
        keep_input();
        flash('You have sent the maximum number of reports for now. Try again in an hour.', 'error');
        redirect('report.php?track=' . $trackId);
    }
    // Same send path as OTP codes (plain text via Graph) — most reliable delivery.
    $ackUrl = create_report_ack((int)$t['cid'], $trackId, $to, $report['text']);
    $body = $report['text']
        . "\n\n---\nElectronically signed by {$user['name']} on " . date('F j, Y \a\t g:i A') . " (" . cfg('name') . ")."
        . "\n\n" . email_template('report_cover', ['ack' => $ackUrl])[1];
    $sent = send_app_mail($to, $report['subject'], $body);
    // The note says the report was copied to the sender only when the copy went.
    $ccOk = $sent && $ccSelf && send_app_mail($user['email'], '[Copy] ' . $report['subject'], $report['text']);
    if ($sent) {
        // The email has gone: a failure recording it must not show an error page, or the
        // user would resend the report (as api.php send_report). The note and the track's
        // last-report date both record this send, so they are written together.
        // last_report_at means "a report was emailed that day" (the automatic reports
        // write it the same way), so compliance stops listing it as due.
        $recorded = true;
        try {
            db()->beginTransaction();
            db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?)')->execute([
                (int)$t['cid'], 'Progress Report',
                $finalMode ? 'Final report emailed to VRC' : 'Training report emailed to VRC',
                "Report for track “{$t['title']}” (Auth {$t['auth_number']}) emailed to {$to}"
                    . ($ccOk ? " (cc: {$user['email']})" : '') . ", electronically signed by {$user['name']}.",
                $user['id'],
            ]);
            db()->prepare('UPDATE training_tracks SET last_report_at = CURDATE() WHERE id = ?')->execute([$trackId]);
            db()->commit();
        } catch (Throwable $e) {
            $recorded = false;
            try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
            error_log('report.php: report note not recorded: ' . get_class($e) . ' code=' . $e->getCode());
        }
        try {
            db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)')
                ->execute([client_ip(), $rlKey]);
        } catch (Throwable $e) {
            error_log('report.php: report send not counted: ' . get_class($e) . ' code=' . $e->getCode());
        }
        audit($user['id'], 'report.send', 'client', (int)$t['cid'], 'to ' . $to);
        $copyMsg = $ccSelf && !$ccOk ? " The copy to you ({$user['email']}) could not be sent." : '';
        // A copy for the client's portal (3.0.51), when staff chose to share it.
        if (!empty($_POST['share_portal'])) {
            try {
                $shared = report_share_to_portal((int)$t['cid'], ($finalMode ? 'Final report' : 'Progress report') . ', ' . date('F j, Y'),
                    $report['text'] . "\n\nElectronically signed by {$user['name']} on " . date('F j, Y \a\t g:i A') . '.', (int)$user['id']);
                $copyMsg .= $shared ? ' A copy is in the client\'s portal.' : ' The copy for the client\'s portal could not be saved.';
            } catch (Throwable $e) {
                error_log('report.php: portal copy not saved: ' . get_class($e));
                $copyMsg .= ' The copy for the client\'s portal could not be saved.';
            }
        }
        if ($recorded) {
            flash("Report signed, emailed to {$to}, and logged as a Progress Report note." . $copyMsg);
        } else {
            flash("Report signed and emailed to {$to}, so do not send it again. It could not be logged as a Progress Report note: add that note to the client's file yourself." . $copyMsg, 'error');
        }
        redirect('client.php?id=' . (int)$t['cid'] . '&tab=notes');
    }
    // Nothing was sent, so the acknowledgment link just created must not linger in the
    // counselor's portal as a report sent (same as api.php send_report).
    $ackToken = (string)substr($ackUrl, (int)strrpos($ackUrl, '?t=') + 3);
    if ($ackToken !== '') db()->prepare('DELETE FROM report_acks WHERE token = ? AND acked_at IS NULL')->execute([$ackToken]);
    keep_input();
    flash('The report email could not be sent. Please try again or contact your administrator.', 'error');
    redirect('report.php?track=' . $trackId);
}

$recipientVal = $posted ? (is_string($_POST['recipient'] ?? null) ? $_POST['recipient'] : '')
    : ($refill ? old_in('recipient') : (string)($t['counselor_email'] ?? ''));
$ccChecked = $posted ? !empty($_POST['cc_self']) : (!$refill || old_in('cc_self') !== '');
// An AI draft never replaces what the user typed: it is added below it.
$introShown = $aiDraft === '' ? $introIn : (trim($introIn) !== '' ? rtrim($introIn) . "\n\n" . $aiDraft : $aiDraft);
$noCounselorEmail = empty($t['counselor_email']);
// A new one-time send token for this rendering (see send_report). The last 20 are kept,
// so report pages open in other tabs can each still send once.
$sendToken = bin2hex(random_bytes(16));
$sendTokens = is_array($_SESSION['report_send_tokens'] ?? null) ? $_SESSION['report_send_tokens'] : [];
$sendTokens[$sendToken] = $trackId;
$_SESSION['report_send_tokens'] = array_slice($sendTokens, -20, null, true);

page_header('Training report', $user, 'clients');
?>
<p class="page-actions"><a href="track.php?id=<?= $trackId ?>"><span aria-hidden="true">&larr; </span>Back to <?= h($t['title']) ?></a></p>
<h1>Training progress report</h1>

<section class="card" aria-labelledby="send-h">
  <h2 id="send-h">Email this report</h2>
  <?php if ($relWarn = release_warning((int)$report['track']['cid'], 'VR counselor and agency', (string)($t['counselor_email'] ?? ''))): ?>
  <p class="alert alert-info"><?= h($relWarn) ?></p>
  <?php endif; ?>
  <form method="post" action="report.php?track=<?= $trackId ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="send_token" value="<?= h($sendToken) ?>">
    <?= form_error_summary(['recipient' => 'Send to', 'intro' => 'Personal message', 'signature' => 'Electronic signature']) ?>
    <p class="hint">Fields marked * are required.</p>
    <?php // Pressing Enter in a field submits with the form's FIRST submit button. This
          // hidden copy of the send button comes first, so Enter sends (and checks) the
          // report — it never runs the AI draft, which would change the cover note. ?>
    <button type="submit" name="send_report" value="1" class="visually-hidden" tabindex="-1" aria-hidden="true">Email report to counselor</button>
    <div class="field">
      <label for="recipient">Send to <?= required_mark() ?> <span class="hint"><?= $noCounselorEmail ? 'No counselor email on file; enter one' : 'Defaults to the VR counselor on ' . h($t['client_name']) . '\'s file' ?></span></label>
      <input type="email" id="recipient" name="recipient" required value="<?= h($recipientVal) ?>" <?= $noCounselorEmail ? field_aria('recipient', 'recipient-missing') : field_aria('recipient') ?>>
      <?= field_error('recipient') ?>
      <?php if ($noCounselorEmail): ?>
        <p class="field-error" id="recipient-missing">No counselor email is on this client's file — enter one here, or <a href="client_edit.php?id=<?= (int)$t['cid'] ?>">add it to the client profile</a>.</p>
      <?php endif; ?>
    </div>
    <div class="field">
      <label for="intro">Personal message (optional) <span class="hint">Appears at the top of the report</span></label>
      <textarea id="intro" name="intro" rows="<?= $aiDraft ? 8 : 3 ?>" <?= field_aria('intro') ?>><?= h($introShown) ?></textarea>
      <?= field_error('intro') ?>
      <?php if (ai_feature_enabled('draft')): ?>
      <button type="submit" name="ai_draft" value="1" class="btn btn-secondary btn-sm" formnovalidate>Draft with AI</button>
      <span class="hint">Generates a cover note from this track's progress and adds it below anything you have already typed — you can edit it before sending.</span>
      <?php endif; ?>
    </div>
    <fieldset class="field"><legend>Kind of report</legend>
      <label><input type="radio" name="report_kind" value="progress"<?= $finalMode ? '' : ' checked' ?>> Progress report</label>
      <label><input type="radio" name="report_kind" value="final"<?= $finalMode ? ' checked' : '' ?>> Final report (closing: adds skill levels, devices and the closure)</label>
    </fieldset>
    <?php if ($lastReportAt): ?>
    <div class="field"><label><input type="checkbox" name="period" value="1"<?= $periodOn ? ' checked' : '' ?>> Cover only the period since the last report (<?= fmt_date($lastReportAt) ?>); totals stay for the whole authorization</label></div>
    <?php endif; ?>
    <div class="field"><label for="barriers">Barriers (optional)</label>
      <textarea id="barriers" name="barriers" rows="2"><?= h($barriersIn) ?></textarea></div>
    <div class="field"><label for="recommendations">Recommendations (optional)</label>
      <textarea id="recommendations" name="recommendations" rows="2"><?= h($recommendIn) ?></textarea></div>
    <button type="submit" name="refresh_preview" value="1" class="btn btn-secondary btn-sm" formnovalidate>Update the preview</button>
    <?php if (ai_feature_enabled('draft')): ?>
    <button type="submit" name="ai_sections" value="1" class="btn btn-secondary btn-sm" formnovalidate>Draft barriers and recommendations with AI</button>
    <?php endif; ?>
    <div class="field">
      <label><input type="checkbox" name="cc_self" value="1"<?= $ccChecked ? ' checked' : '' ?>> Send a copy to me (<?= h($user['email']) ?>)</label>
    </div>
    <div class="field">
      <label><input type="checkbox" name="share_portal" value="1"<?= ($posted || $refill) && (!empty($_POST['share_portal']) || old_in('share_portal') !== '') ? ' checked' : '' ?>> Also put a copy in the client's portal</label>
    </div>
    <div class="field">
      <label for="signature">Electronic signature <?= required_mark() ?> <span class="hint">Type your full name (<?= h($user['name']) ?>) to sign — the signature and timestamp are included on the report</span></label>
      <input type="text" id="signature" name="signature" required autocomplete="name" <?= field_aria('signature') ?>>
      <?= field_error('signature') ?>
    </div>
    <button type="submit" name="send_report" value="1" class="btn btn-primary">Email report to counselor</button>
  </form>
</section>

<h2>Preview</h2>
<?php // The report is a light document with its own colours, readable in the dark theme;
      // a chosen vision palette (black, white, yellow, cream) still applies inside it. ?>
<style>
:root:is([data-vision-palette="black"], [data-vision-palette="white"], [data-vision-palette="yellow"], [data-vision-palette="cream"]) .report-preview * {
  background-color: var(--vision-bg) !important; color: var(--vision-ink) !important; border-color: var(--vision-ink) !important;
}
</style>
<div class="card report-preview">
  <?= $report['html'] /* built server-side from trusted templates; all data fields are escaped in the builder */ ?>
</div>
<?php page_footer();
