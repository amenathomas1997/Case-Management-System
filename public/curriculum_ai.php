<?php
// AI curriculum generator: draft an ordered training curriculum (skill set) for
// a specific assistive technology and skill level. Staff review/edit the skills,
// then create a real curriculum used by the competency tracker. Governed by
// ai_run(); needs the 'templates' permission.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('templates');

if (!ai_feature_enabled('curriculum')) {
    page_header('Curriculum generator', $user, 'ai_tools');
    echo '<div class="card"><h1>Curriculum generator</h1><p class="muted">This AI tool is turned off. An administrator can enable it under AI assistant settings.</p></div>';
    page_footer();
    exit;
}

// Most text accepted per request (sent to the AI provider or stored as skills).
const CURRICULUM_AI_MAX_CHARS = 20000;
$postText = fn(string $k, string $default = ''): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : $default;

/* ---- Create the reviewed curriculum ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $name = trim($postText('name'));
    $curTech = trim($postText('tech'));
    // Each skill, noting the box's line numbers of any that are too long.
    $skills = [];
    $longLines = [];
    foreach (preg_split('/\r?\n/', $postText('skills')) ?: [] as $i => $line) {
        $s = trim($line);
        if ($s === '') continue;
        if (mb_strlen($s) > 300) $longLines[] = $i + 1;
        $skills[] = $s;
    }
    // Refused, never cut (sizes are the curricula and curriculum_skills columns, as
    // curriculum.php checks): the review form is shown again as typed, each problem
    // marked on its field.
    $errs = [];
    if ($name === '') $errs['name'] = 'Give the curriculum a name.';
    elseif (($n = mb_strlen($name)) > 190) $errs['name'] = 'Keep the curriculum name to 190 characters or fewer (it has ' . number_format($n) . ').';
    if (($n = mb_strlen($curTech)) > 120) $errs['cur_tech'] = 'Keep the technology to 120 characters or fewer (it has ' . number_format($n) . '); more detail can go in the skills.';
    if (mb_strlen($postText('skills')) > CURRICULUM_AI_MAX_CHARS) $errs['skills'] = 'The skill list is too long (20,000 characters maximum). Shorten it and try again.';
    elseif (!$skills) $errs['skills'] = 'Enter at least one skill, one per line.';
    elseif ($longLines) $errs['skills'] = 'Keep each skill to 300 characters or fewer (too long on line ' . implode(', ', $longLines) . ').';
    if ($errs) { set_form_errors($errs); flash('The curriculum was not created. ' . implode(' ', $errs), 'error'); }
    else {
        // The curriculum and all its skills commit together, so a failure part-way
        // never leaves a partial curriculum behind "nothing was saved".
        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO curricula (name, tech, description) VALUES (?,?,?)')
                ->execute([$name, $curTech !== '' ? $curTech : null, 'Generated with AI, staff-reviewed']);
            $curId = (int)db()->lastInsertId();
            $ins = db()->prepare('INSERT INTO curriculum_skills (curriculum_id, skill, sort) VALUES (?,?,?)');
            foreach ($skills as $i => $s) $ins->execute([$curId, $s, $i + 1]);
            audit($user['id'], 'curriculum.ai_create', 'curriculum', $curId, count($skills) . ' skills');
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        flash('Curriculum created with ' . count($skills) . ' skills.');
        redirect('curriculum.php?manage=' . $curId);
    }
}

$tech = $postText('tech');
$level = in_array($postText('level'), ['Beginner', 'Intermediate', 'Advanced'], true) ? $postText('level') : 'Beginner';
$focus = $postText('focus');
$name = $postText('name');
$skillsText = $postText('skills');
// The full lesson plan (objectives, numbered steps, success criteria) as plain
// text: shown as sections, kept in the review form, and offered as a download.
$planText = $postText('plan');
$truncated = false;

/**
 * The plan's plain text as lessons: each "Lesson: <title>" block with its
 * Objectives, Steps to complete the task and How you know it's done lines. Text
 * that does not follow the layout gives no lessons (it is then shown as is).
 */
function curriculum_parse_plan(string $text): array {
    $lessons = []; $cur = null; $sect = '';
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $t = trim($line);
        if ($t === '') continue;
        if (preg_match('/^lesson(?:\s*\d+)?\s*:\s*(.+)$/iu', $t, $m)) {
            if ($cur) $lessons[] = $cur;
            $cur = ['title' => trim($m[1]), 'objectives' => [], 'steps' => [], 'done' => []]; $sect = '';
            continue;
        }
        if ($cur === null) continue;
        if (preg_match('/^objectives?\s*:\s*(.*)$/iu', $t, $m)) { $sect = 'objectives'; $t = trim($m[1]); }
        elseif (preg_match('/^steps(?:\s+to complete the task)?\s*:\s*(.*)$/iu', $t, $m)) { $sect = 'steps'; $t = trim($m[1]); }
        elseif (preg_match('/^how you know it\W?s done\s*:\s*(.*)$/iu', $t, $m)) { $sect = 'done'; $t = trim($m[1]); }
        if ($t === '' || $sect === '') continue;
        $t = preg_replace('/^(?:[-*•]|\d{1,3}[.)])\s+/u', '', $t);
        if ($t !== '') $cur[$sect][] = $t;
    }
    if ($cur) $lessons[] = $cur;
    return $lessons;
}

/* ---- Download the plan as plain text (the steps included) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download']) && trim($planText) !== '') {
    $fname = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name !== '' ? $name : ($tech . '-' . $level))) ?: 'lesson-plan';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . trim($fname, '-') . '-lesson-plan.txt"');
    header('X-Content-Type-Options: nosniff');
    echo ($name !== '' ? $name : trim($tech . ' — ' . $level)) . "\n\n" . str_replace(["\r\n", "\r"], "\n", $planText) . "\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if (trim((string)$tech) === '') { set_form_errors(['tech' => 'Name the technology first.']); flash('Name the technology first.', 'error'); }
    elseif (mb_strlen($tech) + mb_strlen($focus) > CURRICULUM_AI_MAX_CHARS) {
        set_form_errors(['focus' => 'The technology and focus notes together can be up to 20,000 characters. Shorten them.']);
        flash('That is too much text to send (20,000 characters maximum). Shorten the technology and focus notes.', 'error');
    }
    elseif (!ai_rate_limit((int)$user['id'])) { flash('You have reached the AI usage limit for now. Please wait a few minutes.', 'error'); }
    else {
        $started = microtime(true);
        $res = ai_generate(
            ai_system_base() . ' You design training curricula for assistive technology instruction. Produce an ordered lesson plan for the given technology and learner level: '
                . 'a sequence of lessons, each teaching concrete skills a learner can demonstrate. Write it in exactly this plain-text layout, with no preamble or commentary, and a blank line between lessons:'
                . "\nLesson: <short lesson title>"
                . "\nObjectives:\n- <one objective per line, each a single skill the learner can demonstrate, at most 300 characters>"
                . "\nSteps to complete the task:\n1. <numbered, learner-facing steps in order: what the learner does, naming the keystrokes, gestures, settings or app features where relevant (for example the JAWS or VoiceOver command, the braille display key, or the menu in the app)>"
                . "\nHow you know it's done:\n- <one or two success criteria>"
                . ' Keep every objective on one line, and use only those four labels.',
            "Technology: {$tech}\nLevel: {$level}\nFocus / notes: " . (trim((string)$focus) ?: '[none]'), 4000);
        // Outcome, provider time and cut-off are logged as ai_run() logs them.
        ai_log_usage('curriculum', mb_strlen((string)$tech) + mb_strlen((string)$focus), $res['ok'] ? mb_strlen($res['text']) : 0, false, (int)$user['id'],
            (bool)$res['ok'], (int)round((microtime(true) - $started) * 1000), !empty($res['truncated']));
        if ($res['ok']) {
            $planText = trim($res['text']);
            // The curriculum's skills are the objectives, one per line (each a
            // curriculum_skills row); a reply not in the lesson layout is used line by line.
            $lessons = curriculum_parse_plan($planText);
            $lines = [];
            foreach ($lessons as $ls) foreach ($ls['objectives'] ?: [$ls['title']] as $o) $lines[] = $o;
            $skillsText = $lessons ? implode("\n", $lines) : $planText;
            if ($name === '') $name = trim($tech . ' — ' . $level);
            // Stopped at the token limit: the last lesson may be half-written.
            $truncated = !empty($res['truncated']);
            if ($truncated) flash('The lesson plan below is incomplete: the AI stopped before finishing it, so the last lesson may be cut off. Review it, or draft again with shorter focus notes.', 'error');
            else flash('Lesson plan drafted — review the lessons and their steps, edit the skills, name the curriculum, and create it.');
        } else flash($res['error'], 'error');
    }
}
$lessons = trim($planText) !== '' ? curriculum_parse_plan($planText) : [];

page_header('Curriculum generator', $user, 'ai_tools');
?>
<p><a href="ai_tools.php"><span aria-hidden="true">&larr; </span>AI tools</a></p>
<h1>AI curriculum generator</h1>
<p class="muted">Draft an ordered skill set for a technology, review it, then create a curriculum the competency tracker can use.</p>

<form method="post" action="curriculum_ai.php" class="stacked" novalidate><?= csrf_field() ?>
  <p class="hint">Fields marked * are required.</p>
  <div class="grid grid-4">
    <div class="field"><label for="tech">Technology <?= required_mark() ?></label><input type="text" id="tech" name="tech" value="<?= h($tech) ?>" required placeholder="e.g. JAWS, ZoomText, an AAC device" <?= field_aria('tech') ?>><?= field_error('tech') ?></div>
    <div class="field"><label for="level">Learner level</label><select id="level" name="level"><?= select_options(['Beginner', 'Intermediate', 'Advanced'], $level) ?></select></div>
    <div class="field"><label for="focus">Focus / notes</label><input type="text" id="focus" name="focus" value="<?= h($focus) ?>" placeholder="e.g. employment tasks" <?= field_aria('focus') ?>><?= field_error('focus') ?></div>
  </div>
  <button type="submit" name="generate" value="1" class="btn btn-primary">Draft skills with AI</button>
</form>

<?php // Also after a refused create, so the fields its errors name are on the page. ?>
<?php if (trim((string)$skillsText) !== '' || trim((string)$planText) !== '' || isset($_POST['create'])): ?>
<?php if (trim((string)$planText) !== ''): ?>
<section class="card" aria-labelledby="plan-h" style="margin-top:1rem">
  <h2 id="plan-h">Lesson plan<?= $truncated ? ' (incomplete)' : '' ?></h2>
  <p class="muted">A draft to review. Each lesson lists its objectives, the steps the learner takes, and how you know it is done. The steps are kept in the plan text below and in the download; the curriculum itself stores the objectives as its skills.</p>
  <?php if ($lessons): foreach ($lessons as $i => $ls): ?>
  <h3 id="lesson-<?= $i + 1 ?>"><?= h($ls['title']) ?></h3>
  <h4>Objectives</h4>
  <?php if ($ls['objectives']): ?><ul><?php foreach ($ls['objectives'] as $o): ?><li><?= h($o) ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted">No objectives were given for this lesson.</p><?php endif; ?>
  <h4>Steps to complete the task</h4>
  <?php if ($ls['steps']): ?><ol><?php foreach ($ls['steps'] as $st): ?><li><?= h($st) ?></li><?php endforeach; ?></ol><?php else: ?><p class="muted">No steps were given for this lesson.</p><?php endif; ?>
  <h4>How you know it's done</h4>
  <?php if ($ls['done']): ?><ul><?php foreach ($ls['done'] as $d): ?><li><?= h($d) ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted">No success criteria were given for this lesson.</p><?php endif; ?>
  <?php endforeach; else: ?>
  <p class="muted">The plan is not laid out as lessons, so it is shown as written.</p>
  <?= plain_text_html($planText) ?>
  <?php endif; ?>
  <form method="post" action="curriculum_ai.php" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="tech" value="<?= h($tech) ?>"><input type="hidden" name="level" value="<?= h($level) ?>"><input type="hidden" name="name" value="<?= h($name) ?>">
    <input type="hidden" name="plan" value="<?= h($planText) ?>">
    <button type="submit" name="download" value="1" class="btn btn-secondary">Download the plan as a text file<span class="visually-hidden"> (objectives, steps and success criteria)</span></button>
  </form>
</section>
<?php endif; ?>
<section class="card" aria-labelledby="rev-h" style="margin-top:1rem">
  <h2 id="rev-h">Review &amp; create<?= $truncated ? ' (incomplete)' : '' ?></h2>
  <?php if ($truncated): ?><p class="field-error"><strong>Incomplete:</strong> the AI stopped before finishing the plan; check the last skill before you create the curriculum.</p><?php endif; ?>
  <form method="post" action="curriculum_ai.php" class="stacked" novalidate><?= csrf_field() ?>
    <?php // The draft settings ride along so a refused create shows them again. ?>
    <input type="hidden" name="level" value="<?= h($level) ?>">
    <input type="hidden" name="focus" value="<?= h($focus) ?>">
    <div class="field"><label for="name">Curriculum name <?= required_mark() ?> <span class="hint">Up to 190 characters.</span></label><input type="text" id="name" name="name" value="<?= h($name) ?>" maxlength="190" required <?= field_aria('name') ?>><?= field_error('name') ?></div>
    <?php // Saved with the curriculum (120 characters at most), so a longer description typed for the draft can be shortened here. ?>
    <div class="field"><label for="cur_tech">Technology saved with the curriculum <span class="hint">Up to 120 characters; starts as the technology you drafted for.</span></label><input type="text" id="cur_tech" name="tech" value="<?= h($tech) ?>" maxlength="120" <?= field_aria('cur_tech') ?>><?= field_error('cur_tech') ?></div>
    <div class="field"><label for="skills">Skills <?= required_mark() ?> <span class="hint">One per line, up to 300 characters each — edit freely. These are the lesson objectives; each becomes a skill the competency tracker follows.</span></label><textarea id="skills" name="skills" rows="12" required <?= field_aria('skills') ?>><?= h($skillsText) ?></textarea><?= field_error('skills') ?></div>
    <?php if (trim((string)$planText) !== ''): ?>
    <div class="field"><label for="plan">Lesson plan text <span class="hint">The full plan as plain text: each lesson's objectives, its numbered steps, and how you know it is done. Edit it here before downloading; it is not stored with the curriculum.</span></label><textarea id="plan" name="plan" rows="12"><?= h($planText) ?></textarea></div>
    <?php endif; ?>
    <button type="submit" name="create" value="1" class="btn btn-primary">Create curriculum</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
