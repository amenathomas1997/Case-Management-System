<?php
// AI-guided assessment intake: staff answer a few functional-needs questions,
// the AI drafts a structured tech assessment (disability, recommended tech,
// hardware/software, summary) and suggests which standardized instruments to
// administer. Staff review and save a real assessment. Governed by ai_run().
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$cid = (int)($_GET['client'] ?? 0);
$cstmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$cstmt->execute([$cid]);
$client = $cstmt->fetch();
if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
scope_check_client($client, $user);

if (!ai_feature_enabled('intake')) {
    page_header('Guided intake', $user, 'clients');
    echo '<div class="card"><h1>Guided intake</h1><p class="muted">AI-guided assessment intake is turned off. An administrator can enable it under AI assistant settings.</p><p><a href="client.php?id=' . $cid . '"><span aria-hidden="true">&larr; </span>Back to ' . h($client['name']) . '</a></p></div>';
    page_footer();
    exit;
}

$fields = ['primary_disability' => '', 'primary_tech' => 'Other', 'hardware_details' => '', 'software_version' => '', 'summary' => ''];
// A fresh form starts from what the client record already says (3.3.7): the
// primary assistive technology on the record, matched to the nearest type, and
// the disability / limitations from the client's latest assessment. Posted
// input (below) replaces these.
if (($techMatch = tech_type_match((string)($client['a11y_primary_tech'] ?? ''))) !== null) $fields['primary_tech'] = $techMatch;
$lastA = db()->prepare('SELECT primary_disability FROM tech_assessments WHERE client_id = ? AND primary_disability IS NOT NULL ORDER BY assessment_date DESC, id DESC LIMIT 1');
$lastA->execute([$cid]);
$fields['primary_disability'] = (string)($lastA->fetchColumn() ?: '');
$instruments = '';
$answers = ['goals' => '', 'limitations' => '', 'tried' => '', 'context' => ''];
$assessDate = date('Y-m-d');
$generated = false;
$saveRefused = false;
// Stale-session recovery returns the same user's typed answers and reviewed draft.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (old_in('generate') !== '' || old_in('save_assessment') !== '')) {
    foreach ($answers as $key => $_) $answers[$key] = old_in($key);
    if (old_in('save_assessment') !== '') {
        foreach ($fields as $key => $default) $fields[$key] = old_in($key, $default);
        if (!in_array($fields['primary_tech'], cms_list_items('tech_types'), true)) $fields['primary_tech'] = 'Other';
        $assessDate = old_in('assessment_date', $assessDate);
        $instruments = old_in('instruments');
        $generated = true;
    }
}
// Column sizes of the short draft fields (tech_assessments), with their labels.
const INTAKE_LIMITS = ['primary_disability' => [190, 'Primary disability / functional limitations'],
    'hardware_details' => [255, 'Hardware'], 'software_version' => [190, 'Software & version']];
$tooLong = function (array $fields): array {
    $errs = [];
    foreach (INTAKE_LIMITS as $k => [$max, $label]) {
        $n = mb_strlen($fields[$k]);
        if ($n > $max) $errs[$k] = "{$label} is {$n} characters; shorten it to {$max} or fewer (more detail can go in the summary).";
    }
    return $errs;
};
// A saved assessment cannot be edited, so it must not keep a [bracketed]
// placeholder the AI left for a missing detail (or a redaction tag such as
// [DOB]); screen readers at the default punctuation level do not say the
// brackets, so each one is named. Same pattern as eval_report.php.
$placeholders = function (array $fields): array {
    $errs = [];
    foreach (['primary_disability', 'hardware_details', 'software_version', 'summary'] as $k) {
        if (preg_match('/\[(?=[^\[\]\n]*\p{L})[^\[\]\n]{1,80}\]/u', (string)$fields[$k], $m)) {
            $errs[$k] = 'Replace the placeholder ' . $m[0] . ' with the actual detail, or remove it, before saving.';
        }
    }
    return $errs;
};

/* ---- Save the reviewed assessment ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assessment'])) {
    $in = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    foreach (array_keys($fields) as $k) $fields[$k] = $in($k);
    if (!in_array($fields['primary_tech'], cms_list_items('tech_types'), true)) $fields['primary_tech'] = 'Other';
    $assessDate = $in('assessment_date');
    $errs = $tooLong($fields) + $placeholders($fields);
    // tech_assessments.summary is a TEXT column (65,535 bytes), as client.php checks.
    if (!mb_check_encoding($fields['summary'], 'UTF-8')) $errs['summary'] = 'Summary contains characters that could not be read; paste or type it again.';
    elseif (strlen($fields['summary']) > 65535) $errs['summary'] = 'Summary is too long to save (65,535 bytes maximum; accented letters and symbols count as 2 to 4 bytes each). Shorten it, then save.';
    // Same rule as client.php: a real date from 2000 onwards, never after today.
    $date = valid_date($assessDate, 2000, (int)date('Y'));
    if ($assessDate === '') $errs['assessment_date'] = 'Assessment date is required.';
    elseif ($date === null) $errs['assessment_date'] = 'Enter the assessment date as a valid date (YYYY-MM-DD) from the year 2000 onwards.';
    elseif ($date > date('Y-m-d')) $errs['assessment_date'] = 'The assessment date cannot be in the future. Check the year.';
    if (!$errs) {
        // The same draft saved twice (Enter pressed again while it loads) is saved once.
        $assessKey = web_idem_key($_POST);
        if (web_once_get($assessKey) !== null) {
            flash('This assessment was already saved (the form was sent twice), so it was saved once.', 'info');
            redirect('client.php?id=' . $cid . '&tab=assessments');
        }
        db()->prepare('INSERT INTO tech_assessments (client_id, assessment_date, primary_disability, primary_tech, hardware_details, software_version, summary, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$cid, $date, $fields['primary_disability'] ?: null, $fields['primary_tech'],
                $fields['hardware_details'] ?: null, $fields['software_version'] ?: null,
                $fields['summary'] ?: null, $user['id']]);
        web_once_set($assessKey, (int)db()->lastInsertId());
        complete_task_by_key('assess-' . $cid, $user['id']);
        audit($user['id'], 'assessment.add', 'client', $cid, 'AI-guided intake');
        flash('Assessment saved.');
        redirect('client.php?id=' . $cid . '&tab=assessments');
    }
    // Nothing saved: show the reviewed draft again, as typed, with each problem marked.
    foreach (array_keys($answers) as $k) $answers[$k] = $in($k);
    $instruments = $in('instruments');
    $generated = true;
    $saveRefused = true;
    // Announced once by the focused error summary at the top of the draft form.
    set_form_errors($errs);
}

/* ---- Generate a draft from the intake answers ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    foreach ($answers as $k => $_) $answers[$k] = trim((string)($_POST[$k] ?? ''));
    $prompt = "Intake answers:\n"
        . "What the client wants to accomplish (goals): " . ($answers['goals'] ?: '[none]') . "\n"
        . "Functional limitations (vision/hearing/motor/cognitive): " . ($answers['limitations'] ?: '[none]') . "\n"
        . "Technology tried / currently uses: " . ($answers['tried'] ?: '[none]') . "\n"
        . "Work / education context: " . ($answers['context'] ?: '[none]');
    $res = ai_run('intake',
        ai_system_base() . ' You help a VR assistive-technology evaluator turn intake answers into a structured assessment draft. '
            . 'Return exactly these tagged sections and nothing else: '
            . '[DISABILITY]primary disability and functional limitations[/DISABILITY]'
            . '[TECH]choose one of: Screen Reader, Magnification, Braille Display, Speech-to-Text, Independent Living Skills, Braille Literacy, Low Vision Devices, Rehabilitation Technology, Other[/TECH]'
            . '[HARDWARE]recommended hardware, if any[/HARDWARE][SOFTWARE]recommended software/versions, if any[/SOFTWARE]'
            . '[SUMMARY]a concise professional assessment summary[/SUMMARY]'
            . '[INSTRUMENTS]comma-separated standardized instruments worth administering[/INSTRUMENTS]. '
            . 'Use only the information provided; mark gaps with [bracketed] placeholders. '
            . 'Bracketed tags such as [CLIENT], [GUARDIAN] and [COUNSELOR] stand for names that were withheld: keep them exactly as written and do not invent names.',
        $prompt, 1500, $client, (int)$user['id']);
    audit($user['id'], 'ai.draft', 'client', $cid, 'AI-guided intake');
    if ($res['ok']) {
        $txt = $res['text'];
        $missing = [];
        // The names withheld from the AI are put back (as eval_report.php does), so
        // the draft never says [CLIENT]; any other tag is caught as a placeholder.
        $names = ['[CLIENT]' => (string)$client['name'], '[COUNSELOR]' => trim((string)($client['counselor_name'] ?? '')) ?: 'the VR counselor'];
        if (trim((string)($client['guardian_name'] ?? '')) !== '') $names['[GUARDIAN]'] = trim((string)$client['guardian_name']);
        foreach (['primary_disability' => ['DISABILITY', 'Primary disability'], 'primary_tech' => ['TECH', 'Primary technology'], 'hardware_details' => ['HARDWARE', 'Hardware'], 'software_version' => ['SOFTWARE', 'Software'], 'summary' => ['SUMMARY', 'Summary']] as $k => [$tag, $label]) {
            if (preg_match('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/s', $txt, $m)) $fields[$k] = strtr(trim($m[1]), $names);
            else $missing[] = $label;
        }
        if (preg_match('/\[INSTRUMENTS\](.*?)\[\/INSTRUMENTS\]/s', $txt, $m)) $instruments = trim($m[1]);
        // Snap the tech to the closest allowed value.
        if (!in_array($fields['primary_tech'], cms_list_items('tech_types'), true)) $fields['primary_tech'] = tech_type_match((string)$fields['primary_tech']) ?? 'Other';
        // The draft form appears whenever any section was read; an unreadable reply
        // (no tags, or cut off before the first closing tag) is reported, not hidden.
        $generated = count($missing) < 5;
        $long = $tooLong($fields);
        $gaps = array_diff_key($placeholders($fields), $long);
        if ($long || $gaps) set_form_errors($long + $gaps);
        if (!$generated) flash('The AI reply could not be read, so no draft was made. Press "Draft assessment with AI" to try again.', 'error');
        elseif ($missing || $long || $gaps) {
            $longNames = array_map(fn($k) => INTAKE_LIMITS[$k][1], array_keys($long));
            $gapLabels = ['primary_disability' => 'Primary disability', 'hardware_details' => 'Hardware', 'software_version' => 'Software', 'summary' => 'Summary'];
            $gapNames = array_map(fn($k) => $gapLabels[$k], array_keys($gaps));
            flash('Draft ready below, under "Assessment draft".'
                . ($missing ? ' These fields were not drafted: ' . implode(', ', $missing) . '. Fill them in before saving.' : '')
                . ($long ? ' These fields are too long to save: ' . implode(', ', $longNames) . '. Shorten them, then save.' : '')
                . ($gaps ? ' These fields have a bracketed placeholder for a missing detail: ' . implode(', ', $gapNames) . '. Replace it, then save.' : ''), 'error');
        }
        else flash('Draft ready below, under "Assessment draft" — review each field and save.');
    } else { flash($res['error'], 'error'); }
}

page_header('Guided intake', $user, 'clients');
?>
<p><a href="client.php?id=<?= $cid ?>"><span aria-hidden="true">&larr; </span>Back to <?= h($client['name']) ?></a></p>
<h1>AI-guided assessment intake</h1>
<p class="muted">Answer the prompts; the AI drafts a structured assessment you review and save. Nothing is saved until you press Save.</p>

<form method="post" action="intake.php?client=<?= $cid ?>" class="stacked" id="intake-form"><?= csrf_field() ?>
  <fieldset>
    <legend>Intake</legend>
    <div class="field"><label for="goals">What does the client want to accomplish?</label><textarea id="goals" name="goals" rows="2"><?= h($answers['goals']) ?></textarea></div>
    <div class="field"><label for="limitations">Functional limitations (vision / hearing / motor / cognitive)</label><textarea id="limitations" name="limitations" rows="2"><?= h($answers['limitations']) ?></textarea></div>
    <div class="field"><label for="tried">Technology tried or currently used</label><textarea id="tried" name="tried" rows="2"><?= h($answers['tried']) ?></textarea></div>
    <div class="field"><label for="context">Work / education context</label><textarea id="context" name="context" rows="2"><?= h($answers['context']) ?></textarea></div>
  </fieldset>
  <?php // The note is part of the button's name (4.30.0), not a line of its own; the confirm covers an edited draft. ?>
  <button type="submit" name="generate" value="1" class="btn btn-primary" id="intake-generate">Draft assessment with AI<?php if ($generated): ?><span class="visually-hidden">: replaces the draft below, including any changes you made to it</span><?php endif; ?></button>
  <?php /* Empty at load, so the drafting message written into it is announced. */ ?>
  <p id="intake-status" role="status" class="hint"></p>
</form>

<?php if ($generated): ?>
<section class="card" aria-labelledby="draft-h" style="margin-top:1rem">
  <h2 id="draft-h">Assessment draft — review &amp; save</h2>
  <?php if ($instruments !== ''): ?><p class="muted">Suggested instruments to administer: <strong><?= h($instruments) ?></strong></p><?php endif; ?>
  <form method="post" action="intake.php?client=<?= $cid ?>" class="stacked" id="draft-form" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
    <?= $saveRefused ? form_error_summary(['assessment_date' => '', 'primary_disability' => '', 'hardware_details' => '', 'software_version' => '', 'summary' => '']) : '' ?>
    <?php // The intake answers and suggestions ride along so a refused save can show them again. ?>
    <?php foreach ($answers as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
    <input type="hidden" name="instruments" value="<?= h($instruments) ?>">
    <div class="field"><label for="assessment_date">Assessment date <?= required_mark() ?></label><input type="date" id="assessment_date" name="assessment_date" value="<?= h($assessDate) ?>" required max="<?= date('Y-m-d') ?>" <?= field_aria('assessment_date') ?>><?= field_error('assessment_date') ?></div>
    <div class="field"><label for="primary_disability">Primary disability / functional limitations <span class="hint">190 characters maximum</span></label><input type="text" id="primary_disability" name="primary_disability" maxlength="190" value="<?= h($fields['primary_disability']) ?>" <?= field_aria('primary_disability') ?>><?= field_error('primary_disability') ?></div>
    <div class="field"><label for="primary_tech">Primary technology recommended</label><select id="primary_tech" name="primary_tech"><?= select_options(cms_list_items('tech_types'), $fields['primary_tech']) ?></select></div>
    <div class="field"><label for="hardware_details">Hardware <span class="hint">255 characters maximum</span></label><input type="text" id="hardware_details" name="hardware_details" maxlength="255" value="<?= h($fields['hardware_details']) ?>" <?= field_aria('hardware_details') ?>><?= field_error('hardware_details') ?></div>
    <div class="field"><label for="software_version">Software &amp; version <span class="hint">190 characters maximum</span></label><input type="text" id="software_version" name="software_version" maxlength="190" value="<?= h($fields['software_version']) ?>" <?= field_aria('software_version') ?>><?= field_error('software_version') ?></div>
    <div class="field"><label for="summary">Summary</label><textarea id="summary" name="summary" rows="5" <?= field_aria('summary') ?>><?= h($fields['summary']) ?></textarea><?= field_error('summary') ?></div>
    <button type="submit" name="save_assessment" value="1" class="btn btn-primary">Save assessment</button>
  </form>
</section>
<?php endif; ?>
<script>
// The AI takes a while to answer: once session-forms.js has let the draft request
// go, say so in the status line and ignore further presses (the button keeps
// focus, so it is marked aria-disabled rather than disabled). Before that, an
// edited draft is replaced only after the user confirms.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('intake-form'), btn = document.getElementById('intake-generate'),
      status = document.getElementById('intake-status'), draft = document.getElementById('draft-form'), busy = false, asked = false;
  if (!form || !btn || !status) return;
  var edited = function () {
    if (!draft) return false;
    return Array.prototype.some.call(draft.elements, function (el) {
      if (el.tagName === 'SELECT') return Array.prototype.some.call(el.options, function (o) { return o.selected !== o.defaultSelected; });
      return (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && el.type !== 'hidden')) && el.value !== el.defaultValue;
    });
  };
  // Runs before session-forms.js (a listener on the form fires before one on the document).
  form.addEventListener('submit', function (e) {
    if (busy) { e.preventDefault(); return; }
    if (!asked && edited()) {
      if (!window.confirm('Drafting again replaces the assessment draft below, including your changes. Draft again?')) { e.preventDefault(); return; }
      asked = true;
    }
  });
  // Added after session-forms.js, so it sees whether the submission is really going ahead.
  document.addEventListener('submit', function (e) {
    if (e.target !== form || e.defaultPrevented) return;
    busy = true; asked = false;
    btn.setAttribute('aria-disabled', 'true');
    status.textContent = 'Drafting the assessment. This can take up to a minute; please wait.';
  });
  // Coming back to this page from the browser history: ready again.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) { busy = false; btn.removeAttribute('aria-disabled'); status.textContent = ''; }
  });
});
</script>
<?php page_footer();
