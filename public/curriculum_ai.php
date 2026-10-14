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
$truncated = false;
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
            ai_system_base() . ' You design training curricula for assistive technology instruction. Produce an ordered list of concrete, '
                . 'teachable skills (one per line, no numbering, no preamble, no commentary) for the given technology and learner level. '
                . 'Each line is a single skill a learner can demonstrate.',
            "Technology: {$tech}\nLevel: {$level}\nFocus / notes: " . (trim((string)$focus) ?: '[none]'), 1200);
        // Outcome, provider time and cut-off are logged as ai_run() logs them.
        ai_log_usage('curriculum', mb_strlen((string)$tech) + mb_strlen((string)$focus), $res['ok'] ? mb_strlen($res['text']) : 0, false, (int)$user['id'],
            (bool)$res['ok'], (int)round((microtime(true) - $started) * 1000), !empty($res['truncated']));
        if ($res['ok']) {
            $skillsText = trim($res['text']);
            if ($name === '') $name = trim($tech . ' — ' . $level);
            // Stopped at the token limit: the last line may be a half-written skill.
            $truncated = !empty($res['truncated']);
            if ($truncated) flash('The skill list below is incomplete: the AI stopped before finishing it, so the last skill may be cut off. Review it, or draft again with shorter focus notes.', 'error');
            else flash('Skills drafted — edit, name it, and create.');
        } else flash($res['error'], 'error');
    }
}

page_header('Curriculum generator', $user, 'ai_tools');
?>
<p><a href="ai_tools.php"><span aria-hidden="true">&larr; </span>AI tools</a></p>
<h1>AI curriculum generator</h1>
<p class="muted">Draft an ordered skill set for a technology, review it, then create a curriculum the competency tracker can use.</p>

<form method="post" action="curriculum_ai.php" class="stacked" novalidate><?= csrf_field() ?>
  <p class="hint">Fields marked * are required.</p>
  <div class="grid grid-4">
    <div class="field"><label for="tech">Technology <span aria-hidden="true">*</span></label><input type="text" id="tech" name="tech" value="<?= h($tech) ?>" required placeholder="e.g. JAWS, ZoomText, an AAC device" <?= field_aria('tech') ?>><?= field_error('tech') ?></div>
    <div class="field"><label for="level">Learner level</label><select id="level" name="level"><?= select_options(['Beginner', 'Intermediate', 'Advanced'], $level) ?></select></div>
    <div class="field"><label for="focus">Focus / notes</label><input type="text" id="focus" name="focus" value="<?= h($focus) ?>" placeholder="e.g. employment tasks" <?= field_aria('focus') ?>><?= field_error('focus') ?></div>
  </div>
  <button type="submit" name="generate" value="1" class="btn btn-primary">Draft skills with AI</button>
</form>

<?php // Also after a refused create, so the fields its errors name are on the page. ?>
<?php if (trim((string)$skillsText) !== '' || isset($_POST['create'])): ?>
<section class="card" aria-labelledby="rev-h" style="margin-top:1rem">
  <h2 id="rev-h">Review &amp; create<?= $truncated ? ' (incomplete)' : '' ?></h2>
  <?php if ($truncated): ?><p class="field-error"><strong>Incomplete:</strong> the AI stopped before finishing the list; check the last skill before you create the curriculum.</p><?php endif; ?>
  <form method="post" action="curriculum_ai.php" class="stacked" novalidate><?= csrf_field() ?>
    <?php // The draft settings ride along so a refused create shows them again. ?>
    <input type="hidden" name="level" value="<?= h($level) ?>">
    <input type="hidden" name="focus" value="<?= h($focus) ?>">
    <div class="field"><label for="name">Curriculum name <span aria-hidden="true">*</span></label><span class="hint" id="name-hint">Up to 190 characters.</span><input type="text" id="name" name="name" value="<?= h($name) ?>" maxlength="190" required <?= field_aria('name', 'name-hint') ?>><?= field_error('name') ?></div>
    <?php // Saved with the curriculum (120 characters at most), so a longer description typed for the draft can be shortened here. ?>
    <div class="field"><label for="cur_tech">Technology saved with the curriculum</label><span class="hint" id="cur_tech-hint">Up to 120 characters; starts as the technology you drafted for.</span><input type="text" id="cur_tech" name="tech" value="<?= h($tech) ?>" maxlength="120" <?= field_aria('cur_tech', 'cur_tech-hint') ?>><?= field_error('cur_tech') ?></div>
    <div class="field"><label for="skills">Skills <span aria-hidden="true">*</span></label><span class="hint" id="skills-hint">One per line, up to 300 characters each — edit freely.</span><textarea id="skills" name="skills" rows="12" required <?= field_aria('skills', 'skills-hint') ?>><?= h($skillsText) ?></textarea><?= field_error('skills') ?></div>
    <button type="submit" name="create" value="1" class="btn btn-primary">Create curriculum</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
