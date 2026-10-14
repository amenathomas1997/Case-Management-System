<?php
// Public outcome survey — token-authorized, 3 questions.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
enable_public_translation();
// Copy used only on this page (its Spanish lives here, beside the English).
register_translations([
    'Feedback received' => 'Opinión recibida',
    'Thank you. Your feedback was sent.' => 'Gracias. Su opinión fue enviada.',
    'Questions 1 and 2 need an answer. Question 3 is optional.' => 'Las preguntas 1 y 2 necesitan una respuesta. La pregunta 3 es opcional.',
    '1. Overall, how satisfied are you with your training?' => '1. En general, ¿qué tan satisfecho está con su capacitación?',
    '2. How well did the training meet your goals?' => '2. ¿Qué tan bien cumplió la capacitación con sus metas?',
    '(required)' => '(obligatoria)',
    '(optional)' => '(opcional)',
    '(not satisfied)' => '(nada satisfecho/a)',
    '(extremely satisfied)' => '(sumamente satisfecho/a)',
    '(not at all)' => '(para nada)',
    '(completely)' => '(por completo)',
    'This page was open for a long time, so your answers were not sent yet. Everything you chose and typed is still here. Please check it and choose Submit feedback again.' => 'Esta página estuvo abierta por mucho tiempo, así que sus respuestas todavía no se enviaron. Todo lo que eligió y escribió sigue aquí. Revíselo y elija Enviar opinión otra vez.',
]);
// A public visitor has no account to sign back into. When the form's token no
// longer matches (the session lapsed while the page sat open), nothing is saved
// and the form is shown again at once with the answers in place (this form
// refills from the post itself, which a redirect through csrf_check() would lose).
$csrfStale = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['user_id'])
    && !(is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']));
if (!$csrfStale) csrf_check();

$rawToken = $_GET['t'] ?? '';   // token rides the query string on GET and POST; never sourced from a cookie
$token = is_string($rawToken) ? preg_replace('/[^a-f0-9]/', '', $rawToken) : '';
$survey = null;
if (strlen($token) === 48) {
    // An archived client's link is not recognized, as on the portal: nothing is
    // greeted by name or written into an archived record.
    $stmt = db()->prepare('SELECT s.*, c.name AS client_name FROM surveys s JOIN clients c ON c.id = s.client_id WHERE s.token = ? AND c.archived_at IS NULL');
    $stmt->execute([$token]);
    $survey = $stmt->fetch();
}

if (!$survey) {
    page_header('Survey');
    // The language switch is offered here too, as on the portal.
    echo lang_toggle();
    echo '<div class="card auth-card"><h1>' . t('Link not recognized') . '</h1><p>' . t('This survey link is invalid or has been removed.') . '</p></div>';
    page_footer();
    exit;
}

$error = false;
// The form re-renders from what was posted, so the answers stay in place.
$staleNotice = $csrfStale && !$survey['completed_at'];
if ($staleNotice) $error = true;
if (!$csrfStale && $_SERVER['REQUEST_METHOD'] === 'POST' && !$survey['completed_at']) {
    $sat = (int)($_POST['satisfaction'] ?? 0);
    $goals = (int)($_POST['goals_met'] ?? 0);
    $comments = trim(is_string($_POST['comments'] ?? '') ? $_POST['comments'] : '');
    // Each problem is named on its own question; the page re-renders (no
    // redirect), so the answers already given stay filled in.
    $errors = [];
    if ($sat < 1 || $sat > 10) $errors['satisfaction'] = 'Choose a rating from 1 to 10.';
    if ($goals < 1 || $goals > 10) $errors['goals_met'] = 'Choose a rating from 1 to 10.';
    if (mb_strlen($comments) > 8000) $errors['comments'] = 'Your comments are too long (8,000 characters maximum).';
    if ($errors) {
        set_form_errors($errors);
        $error = true;
    } else {
        db()->beginTransaction();
        try {
            // The submitted answers and case note must commit together.
            $save = db()->prepare('UPDATE surveys SET completed_at = NOW(), satisfaction = ?, goals_met = ?, comments = ? WHERE id = ? AND completed_at IS NULL');
            $save->execute([$sat, $goals, $comments ?: null, $survey['id']]);
            if ($save->rowCount() === 1) {
                db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)')
                    ->execute([(int)$survey['client_id'], 'Case Note', 'Outcome survey completed',
                        "Satisfaction: {$sat}/10 · Goals met: {$goals}/10" . ($comments !== '' ? "\n\nComments: " . $comments : '')]);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        if ($save->rowCount() === 1) {
            notify_admin_users("Outcome survey completed: {$survey['client_name']} rated {$sat}/10", 'client.php?id=' . (int)$survey['client_id'] . '&tab=notes');
            // Announced once on the next page (focused, with an earcon), as on the portal.
            flash_ui('Thank you. Your feedback was sent.');
        }
        redirect('survey.php?t=' . $token);
    }
}

// The title names the result: a receipt, a form with an error, or the form.
page_header($survey['completed_at'] ? 'Feedback received' : (($error ? 'Error — ' : '') . 'How did we do?'));
echo lang_toggle();
?>
<div class="card" style="max-width: 36rem; margin: 2rem auto;">
<?php if ($survey['completed_at']): ?>
  <h1><?= t('Thank you!') ?></h1>
  <p><?= t('Your feedback has been recorded. We\'re grateful you took the time — and we wish you every success.') ?></p>
<?php else: ?>
  <h1><?= t('How did we do,') ?> <?= h(explode(' ', $survey['client_name'])[0]) ?>?</h1>
  <p><?= t('You recently completed assistive technology training with') ?> <?= h(brand('short')) ?><?= t('. Three quick questions — it takes under a minute.') ?></p>
  <p><?= t('Questions 1 and 2 need an answer. Question 3 is optional.') ?></p>
  <?php if ($staleNotice): // Announced once, by moving focus to it (no alert role). ?>
  <div class="alert alert-error" id="form-errors" tabindex="-1"><span class="visually-hidden"><?= t('Error:') ?> </span><?= t('This page was open for a long time, so your answers were not sent yet. Everything you chose and typed is still here. Please check it and choose Submit feedback again.') ?></div>
  <script>document.addEventListener("DOMContentLoaded",function(){var e=document.getElementById("form-errors");if(e)e.focus();});</script>
  <?php endif; ?>
  <?php // The page language rides the form, so the reply keeps it even if the session lapsed. ?>
  <form method="post" action="survey.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?php // One focused summary names each unanswered question and links to it (announced once, by the focus move). ?>
    <?= form_error_summary(['satisfaction' => '1. Overall, how satisfied are you with your training?', 'goals_met' => '2. How well did the training meet your goals?',
        'comments' => "3. Anything else you'd like us to know?"], ['satisfaction' => 'sat-1', 'goals_met' => 'goals-1']) ?>
    <fieldset aria-describedby="sat-hint<?= has_field_error('satisfaction') ? ' err-satisfaction' : '' ?>">
      <legend><?= t('1. Overall, how satisfied are you with your training?') ?> <span class="hint"><?= t('(required)') ?></span></legend>
      <p class="hint" id="sat-hint"><?= t('1 = not satisfied · 10 = extremely satisfied') ?></p>
      <?= field_error('satisfaction') ?>
      <div class="field">
        <?php for ($i = 1; $i <= 10; $i++): ?>
        <label style="display:inline-block; margin-right:.6rem;"><input type="radio" id="sat-<?= $i ?>" name="satisfaction" value="<?= $i ?>" <?= (int)($_POST['satisfaction'] ?? 0) === $i ? 'checked' : '' ?> required> <?= $i ?><?= $i === 1 ? ' <span class="visually-hidden">' . t('(not satisfied)') . '</span>' : ($i === 10 ? ' <span class="visually-hidden">' . t('(extremely satisfied)') . '</span>' : '') ?></label>
        <?php endfor; ?>
      </div>
    </fieldset>
    <fieldset aria-describedby="goals-hint<?= has_field_error('goals_met') ? ' err-goals_met' : '' ?>">
      <legend><?= t('2. How well did the training meet your goals?') ?> <span class="hint"><?= t('(required)') ?></span></legend>
      <p class="hint" id="goals-hint"><?= t('1 = not at all · 10 = completely') ?></p>
      <?= field_error('goals_met') ?>
      <div class="field">
        <?php for ($i = 1; $i <= 10; $i++): ?>
        <label style="display:inline-block; margin-right:.6rem;"><input type="radio" id="goals-<?= $i ?>" name="goals_met" value="<?= $i ?>" <?= (int)($_POST['goals_met'] ?? 0) === $i ? 'checked' : '' ?> required> <?= $i ?><?= $i === 1 ? ' <span class="visually-hidden">' . t('(not at all)') . '</span>' : ($i === 10 ? ' <span class="visually-hidden">' . t('(completely)') . '</span>' : '') ?></label>
        <?php endfor; ?>
      </div>
    </fieldset>
    <div class="field">
      <label for="comments"><?= t('3. Anything else you\'d like us to know?') ?> <span class="hint"><?= t('(optional)') ?></span></label>
      <textarea id="comments" name="comments" rows="4" maxlength="8000" <?= field_aria('comments') ?>><?= h(is_string($_POST['comments'] ?? '') ? ($_POST['comments'] ?? '') : '') ?></textarea><?= field_error('comments') ?>
    </div>
    <button type="submit" class="btn btn-primary"><?= t('Submit feedback') ?></button>
  </form>
<?php endif; ?>
</div>
<?php page_footer();
