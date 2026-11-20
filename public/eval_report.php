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
// The assessment, the sections, the AI draft and the signed email are the helpers the apps share
// (inc/eval_report.php, 4.37.0), so both give the same sentences, audit and note.
$a = eval_load($aid);
if (!$a) { flash('Assessment not found.', 'error'); redirect('clients.php'); }
scope_check_client_id((int)$a['cid'], $user);

// The prefilled text of each section; what the evaluator posted replaces it.
$defaults = eval_defaults($a);
// After a sign-in (session timeout) or a stale-token redirect, what the evaluator
// typed comes back as old input on a GET, as on report.php; it replaces the defaults.
$refill = $_SERVER['REQUEST_METHOD'] !== 'POST' && array_key_exists('background', $GLOBALS['OLD_INPUT'] ?? []);
$fields = [];
foreach ($defaults as $k => $d) $fields[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : ($refill ? old_in($k, $d) : $d);

// Offered only while the drafting feature itself is on (as amendment_request.php),
// not merely while AI is configured.
$aiDraftOn = ai_feature_enabled('draft');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_draft']) && $aiDraftOn) {
    // Only sections empty or still prefilled are drafted; a section the evaluator wrote in is kept.
    $res = eval_ai_draft($a, $fields, (int)$user['id']);
    if (!$res['ok']) flash($res['error'], 'error');
    else { $fields = $res['sections']; flash($res['message'], $res['is_error'] ? 'error' : 'success'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_html'])) {
    audit($user['id'], 'evalreport.download', 'client', (int)$a['cid'], 'html');
    eval_html($a, $fields, $user['name'])->download(eval_file_base($a) . '.html');
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
    $sendRes = eval_send($a, $fields, is_string($_POST['recipient'] ?? null) ? $_POST['recipient'] : '', is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '', $user);
    if (isset($sendRes['errors'])) {
        // Announced once by the focused error summary at the top of the form; the address field is "recipient" here.
        $errs = $sendRes['errors'];
        if (isset($errs['to'])) { $errs = ['recipient' => $errs['to']] + $errs; unset($errs['to']); }
        set_form_errors($errs);
    } elseif ($sendRes['ok']) {
        flash($sendRes['message'], $sendRes['warning'] ? 'error' : 'success');
        redirect('client.php?id=' . (int)$a['cid'] . '&tab=assessments');
    } else {
        flash($sendRes['error'], 'error');
    }
}

// A signature that matched is typed in again after any refusal; a wrong one is not.
$sigPosted = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
$sigKeep = $sigPosted !== '' && mb_check_encoding($sigPosted, 'UTF-8') && person_name_key($sigPosted) === person_name_key((string)$user['name']) ? $sigPosted : '';

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
    <?php // Read before the buttons, as plain text (4.28.0: no aria-describedby). ?>
    <?php if ($aiDraftOn): ?><p class="hint"><?= h(EVAL_AI_HINT) ?></p><?php endif; ?>
    <div class="page-actions">
      <?php if ($aiDraftOn): ?>
      <button type="submit" name="ai_draft" value="1" class="btn btn-secondary" formnovalidate>Draft narrative with AI</button>
      <?php endif; ?>
      <button type="submit" name="download_html" value="1" class="btn btn-secondary">Download accessible HTML</button>
      <button type="submit" name="download" value="1" class="btn btn-secondary">Download PDF</button>
      <button type="submit" name="email_report" value="1" class="btn btn-primary">Email signed report to counselor</button>
    </div>
  </div>
</form>
<?php page_footer();
