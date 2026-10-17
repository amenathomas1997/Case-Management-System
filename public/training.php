<?php
// My training (4.16.0): the training this person is asked to take, read and answered here
// (inc/staff_training.php). ?id= opens one; a result shows which questions were wrong, and the
// training can be taken again at once.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_training'])) {
    $mid = (int)($_POST['module_id'] ?? 0);
    $answers = is_array($_POST['answer'] ?? null) ? $_POST['answer'] : [];
    $r = training_submit($uid, $mid, (int)($_POST['version'] ?? 0), is_string($_POST['text_hash'] ?? null) ? $_POST['text_hash'] : '', $answers, 'website');
    if ($r['error'] !== null) {
        if (!$r['changed']) $_SESSION['training_answers'] = array_map('strval', array_filter($answers, 'is_scalar'));
        keep_field_errors(['training' => $r['error']]);
        redirect('training.php?id=' . $mid . '#quiz-h');
    }
    unset($_SESSION['training_answers']);
    if ($r['passed']) {
        flash('Passed: ' . $r['score'] . ' of ' . $r['total'] . ' right. You are asked again in a year or if the training changes.');
        redirect('training.php');
    }
    $_SESSION['training_wrong'] = $r['wrong'];
    keep_field_errors(['training' => 'Not passed: ' . $r['score'] . ' of ' . $r['total'] . ' right. Read again, then answer the questions marked below.']);
    redirect('training.php?id=' . $mid . '#quiz-h');
}

$status = training_status($uid);

if (isset($_GET['id'])) {
    $m = null;
    foreach ($status as $r) if ((int)$r['id'] === (int)$_GET['id']) $m = $r;
    if (!$m) { flash('That training is no longer asked for.', 'error'); redirect('training.php'); }
    [$qs] = training_parse_questions((string)$m['questions']);
    $wrong = (array)($_SESSION['training_wrong'] ?? []);
    $kept = (array)($_SESSION['training_answers'] ?? []);
    unset($_SESSION['training_wrong'], $_SESSION['training_answers']);
    page_header($m['title'], $user, 'account', [['account.php', 'My Account'], ['training.php', 'My training']]);
    ?>
<h1><?= h($m['title']) ?></h1>
<p><?= h($m['why']) ?>. Read it, then answer the <?= count($qs) ?> questions; <?= (int)$m['pass_pct'] ?>% passes.</p>
<section class="card" aria-labelledby="read-h">
  <h2 id="read-h">Read</h2>
  <?= staff_agreement_html((string)$m['body']) ?>
</section>
<section class="card" aria-labelledby="quiz-h">
  <h2 id="quiz-h" tabindex="-1">Questions</h2>
  <?= field_error('training') ?>
  <form method="post" action="training.php" class="stacked" novalidate><?= csrf_field() ?>
    <input type="hidden" name="module_id" value="<?= (int)$m['id'] ?>">
    <input type="hidden" name="version" value="<?= (int)$m['version'] ?>">
    <input type="hidden" name="text_hash" value="<?= h(training_hash($m)) ?>">
    <?php foreach ($qs as $i => $q): ?>
    <fieldset>
      <legend><?= $i + 1 ?>. <?= h($q['q']) ?><?= in_array($i + 1, $wrong, true) ? ' <strong>(answered wrongly last time)</strong>' : '' ?></legend>
      <?php foreach ($q['options'] as $o => $opt): ?>
      <div class="field"><label><input type="radio" name="answer[<?= $i ?>]" value="<?= $o ?>" <?= ($kept[$i] ?? '') === (string)$o ? 'checked' : '' ?>> <?= h($opt) ?></label></div>
      <?php endforeach; ?>
    </fieldset>
    <?php endforeach; ?>
    <button type="submit" name="submit_training" value="1" class="btn btn-primary">Check my answers</button>
  </form>
</section>
<?php
    page_footer();
    exit;
}

$needed = array_filter($status, fn($r) => $r['state'] !== 'done');
page_header('My training', $user, 'account', [['account.php', 'My Account']]);
?>
<h1>My training</h1>
<?php if (!$status): ?>
<p>There is no training for you to take.</p>
<?php else: ?>
<p><?= $needed ? count($needed) . ' of ' . count($status) . ' to take.' : 'Every training is passed and current.' ?></p>
<ul>
<?php foreach ($status as $r): ?>
  <li><a href="training.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a>: <?= $r['state'] === 'needed' ? '<strong>to take</strong> (' . h(mb_strtolower($r['why'])) . ')' : h($r['why']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_footer();
