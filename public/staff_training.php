<?php
// Staff training (administrators, 4.16.0): write the reading and quiz staff take themselves,
// turn it on, and see who has passed. Changing the reading or quiz makes a new version that
// everyone takes again. Also: whether client records wait until agreements and training are done.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

$ready = true;
try { db()->query('SELECT 1 FROM training_modules LIMIT 1'); } catch (PDOException $e) { $ready = false; }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hold'])) {
    $mode = in_array($_POST['access_hold'] ?? '', ['off', 'new', 'all'], true) ? $_POST['access_hold'] : 'new';
    $days = is_string($_POST['access_hold_days'] ?? null) ? trim($_POST['access_hold_days']) : '';
    if (!ctype_digit($days) || (int)$days > 90 || ($mode === 'all' && (int)$days < 1)) { keep_input(); keep_field_errors(['access_hold_days' => 'Enter a number of days from 1 to 90 (new staff are held at once either way).']); redirect('staff_training.php#hold-h'); }
    // Who this would take client records from right now: said, and confirmed, before it happens.
    if ($mode !== 'off' && empty($_POST['confirm_hold'])) {
        $before = [setting('access_hold'), setting('access_hold_days')];
        set_setting('access_hold', $mode); set_setting('access_hold_days', (string)(int)$days);
        $wouldHold = [];
        foreach (db()->query('SELECT * FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() as $u) if (access_hold_reasons($u, true)) $wouldHold[] = $u['name'];
        set_setting('access_hold', (string)($before[0] ?? '')); set_setting('access_hold_days', (string)($before[1] ?? ''));
        if ($wouldHold) {
            keep_input();
            $_SESSION['hold_preview'] = $wouldHold;
            keep_field_errors(['confirm_hold' => count($wouldHold) . ' staff would lose client records now: ' . implode(', ', $wouldHold) . '. Tick the box to confirm, then save again.']);
            redirect('staff_training.php#hold-h');
        }
    }
    set_setting('access_hold', $mode);
    set_setting('access_hold_days', (string)(int)$days);
    audit($user['id'], 'settings.access_hold', 'settings', null, $mode . ', ' . (int)$days . ' days');
    flash(['off' => 'Client records no longer wait for agreements and training.', 'new' => 'New staff open client records once their agreements and training are done.',
        'all' => 'Everyone opens client records only while their agreements and training are current (after ' . (int)$days . ' days to catch up).'][$mode]);
    redirect('staff_training.php#hold-h');
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_module'])) {
    $mid = (int)($_POST['module_id'] ?? 0);
    $str = fn(string $k): string => is_string($_POST[$k] ?? null) ? str_replace("\r", '', trim($_POST[$k])) : '';
    $title = $str('title'); $body = $str('body'); $questions = $str('questions');
    $pass = ctype_digit($str('pass_pct')) ? (int)$str('pass_pct') : -1;
    $active = !empty($_POST['active']) ? 1 : 0;
    $required = !empty($_POST['required']) ? 1 : 0;
    $privacy = !empty($_POST['counts_as_privacy']) ? 1 : 0;
    $sfx = $mid > 0 ? '_' . $mid : '';
    $errs = [];
    if ($title === '' || mb_strlen($title) > 190) $errs['title' . $sfx] = 'Give the training a title (190 characters or fewer).';
    if ($body === '' || strlen($body) > 200000) $errs['body' . $sfx] = 'Write what staff read (up to about 200,000 characters).';
    [, $qErr] = training_parse_questions($questions);
    if ($qErr !== null) $errs['questions' . $sfx] = $qErr;
    if ($pass < 50 || $pass > 100) $errs['pass_pct' . $sfx] = 'Enter the pass mark as a percent from 50 to 100.';
    if ($active && str_contains($body, 'Edit this text before turning the training on.')) $errs['body' . $sfx] = 'Edit the starting text, and remove its first line, before turning the training on.';
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('staff_training.php' . ($mid > 0 ? '?edit=' . $mid . '#module-' . $mid : '?new=1#new-h')); }
    db()->beginTransaction();
    try {
        if ($privacy) db()->prepare('UPDATE training_modules SET counts_as_privacy = 0 WHERE id <> ?')->execute([$mid]);
        if ($mid > 0) {
            $q = db()->prepare('SELECT * FROM training_modules WHERE id = ? FOR UPDATE');
            $q->execute([$mid]);
            $old = $q->fetch();
            if (!$old) { db()->rollBack(); flash('That training no longer exists.', 'error'); redirect('staff_training.php'); }
            $changed = $old['title'] !== $title || $old['body'] !== $body || $old['questions'] !== $questions || (int)$old['pass_pct'] !== $pass;
            // A small correction to the reading only (same title, quiz and pass mark, a typo-sized change) keeps the version.
            $small = !empty($_POST['small_correction']) && $old['title'] === $title && $old['questions'] === $questions && (int)$old['pass_pct'] === $pass
                && staff_agreement_small_change((string)$old['body'], $body);
            if (!empty($_POST['small_correction']) && $changed && !$small) {
                db()->rollBack();
                keep_input();
                keep_field_errors(['body' . $sfx => 'This is more than a small correction (the title, quiz or pass mark changed, or the reading changed by more than 40 characters or a line), so everyone takes it again. Untick "small correction" to save it as a new version.']);
                redirect('staff_training.php?edit=' . $mid . '#module-' . $mid);
            }
            $changed = $changed && !$small;
            $version = (int)$old['version'] + ($changed ? 1 : 0);
            $turnedOn = $active && !(int)$old['active'];
            db()->prepare('UPDATE training_modules SET title = ?, body = ?, questions = ?, pass_pct = ?, version = ?, active = ?, required = ?, counts_as_privacy = ?, updated_by = ?, updated_at = NOW()'
                    . ($turnedOn ? ', activated_at = NOW()' : '') . ($changed ? ', version_at = NOW()' : '') . ' WHERE id = ?')
                ->execute([$title, $body, $questions, $pass, $version, $active, $required, $privacy, $user['id'], $mid]);
            // A new version of the privacy training that staff are taking: the date the reminders
            // follow no longer stands. Editing a training that is off leaves everyone's dates alone.
            if ($changed && $privacy && (int)$old['active'] === 1 && $active) db()->exec('UPDATE users SET privacy_training_on = NULL');
            $what = $changed ? 'changed: version ' . $version . ', everyone takes it again' : 'settings saved';
        } else {
            db()->prepare('INSERT INTO training_modules (title, body, questions, pass_pct, active, required, counts_as_privacy, updated_by, activated_at) VALUES (?,?,?,?,?,?,?,?,' . ($active ? 'NOW()' : 'NULL') . ')')
                ->execute([$title, $body, $questions, $pass, $active, $required, $privacy, $user['id']]);
            $mid = (int)db()->lastInsertId();
            $what = 'added';
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($user['id'], 'training.save', 'settings', null, $title . ': ' . $what . ($active ? ', on' : ', off'));
    flash($title . ': ' . $what . '. ' . ($active ? 'Staff are asked to take it.' : 'It is off, so nobody is asked to take it yet.'));
    redirect('staff_training.php#module-' . $mid);
}

$modules = $ready ? db()->query('SELECT m.*, u.name AS updated_by_name FROM training_modules m LEFT JOIN users u ON u.id = m.updated_by ORDER BY m.active DESC, m.title')->fetchAll() : [];
$staff = $ready ? db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() : [];
$latest = $ready ? training_latest() : [];
$editing = (int)($_GET['edit'] ?? 0);

/** The module form (new or edit). */
$form = function (array $m, string $sfx) {
    $mine = old_in('module_id') === (string)($m['id'] ?? 0);
    $v = fn(string $k, string $d) => $mine ? old_in($k) : $d;
    ob_start(); ?>
  <form method="post" action="staff_training.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['title' . $sfx => 'Title', 'body' . $sfx => 'Reading', 'questions' . $sfx => 'Quiz', 'pass_pct' . $sfx => 'Pass mark'], ['title' . $sfx => 'tt' . $sfx, 'body' . $sfx => 'tb' . $sfx, 'questions' . $sfx => 'tq' . $sfx, 'pass_pct' . $sfx => 'tp' . $sfx]) ?>
    <input type="hidden" name="module_id" value="<?= (int)($m['id'] ?? 0) ?>">
    <div class="field"><label for="tt<?= $sfx ?>">Title</label>
      <input type="text" id="tt<?= $sfx ?>" name="title" maxlength="190" value="<?= h($v('title', (string)($m['title'] ?? ''))) ?>" <?= field_aria('title' . $sfx) ?>><?= field_error('title' . $sfx) ?></div>
    <div class="field"><label for="tb<?= $sfx ?>">What staff read <span class="hint">a line starting with # is a heading, a line starting with - is a list item</span></label>
      <textarea id="tb<?= $sfx ?>" name="body" rows="14" <?= field_aria('body' . $sfx) ?>><?= h($v('body', (string)($m['body'] ?? ''))) ?></textarea><?= field_error('body' . $sfx) ?></div>
    <div class="field"><label for="tq<?= $sfx ?>">The quiz <span class="hint">start each question with ?, the right answer with *, and each wrong answer with -</span></label>
      <textarea id="tq<?= $sfx ?>" name="questions" rows="12" <?= field_aria('questions' . $sfx) ?>><?= h($v('questions', (string)($m['questions'] ?? ''))) ?></textarea><?= field_error('questions' . $sfx) ?></div>
    <div class="field"><label for="tp<?= $sfx ?>">Pass mark, percent</label>
      <input type="number" id="tp<?= $sfx ?>" name="pass_pct" min="50" max="100" value="<?= h($v('pass_pct', (string)($m['pass_pct'] ?? 80))) ?>" <?= field_aria('pass_pct' . $sfx) ?>><?= field_error('pass_pct' . $sfx) ?></div>
    <?php if (!empty($m['id'])): ?><div class="field"><label><input type="checkbox" name="small_correction" value="1"> This change is only a small correction to the reading, such as a typo, so nobody takes it again <span class="hint">the same quiz and pass mark, and at most 40 characters different</span></label></div><?php endif; ?>
    <div class="field"><label><input type="checkbox" name="required" value="1" <?= ($m['required'] ?? 1) ? 'checked' : '' ?>> Required: client records can wait until it is passed (see below)</label></div>
    <div class="field"><label><input type="checkbox" name="counts_as_privacy" value="1" <?= !empty($m['counts_as_privacy']) ? 'checked' : '' ?>> This is the privacy training (passing it records the date Compliance follows)</label></div>
    <div class="field"><label><input type="checkbox" name="active" value="1" <?= !empty($m['active']) ? 'checked' : '' ?>> On: ask staff to take it</label></div>
    <button type="submit" name="save_module" value="1" class="btn btn-primary">Save<span class="visually-hidden"> <?= h((string)($m['title'] ?? 'the training')) ?></span></button>
  </form>
<?php return (string)ob_get_clean();
};

page_header('Staff training', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Staff training</h1>
<p>Training staff take themselves, on the website or the phone apps: a reading and a short quiz. Each attempt is kept. Staff take it again a year after passing, or when you change it. The one marked as the privacy training also records the date Compliance follows.</p>
<?php if (!$ready): ?>
<p class="alert alert-info">This page needs migration 4.16.0. Run Database upgrade, then reload.</p>
<?php else: ?>
<p><a class="btn btn-secondary" href="staff_training.php?new=1#new-h">Write a new training</a></p>
<?php foreach ($modules as $m): $mid = (int)$m['id']; $passed = 0;
    foreach ($staff as $s) { $c = $latest[(int)$s['id']][$mid] ?? null; if ($c && (int)$c['version'] === (int)$m['version'] && strtotime(substr((string)$c['completed_at'], 0, 10) . ' +' . STAFF_RENEWAL_DAYS . ' days') >= strtotime(date('Y-m-d'))) $passed++; } ?>
<section class="card" aria-labelledby="module-<?= $mid ?>">
  <h2 id="module-<?= $mid ?>" tabindex="-1"><?= h($m['title']) ?>: <?= $m['active'] ? 'on' : 'off' ?>, version <?= (int)$m['version'] ?></h2>
  <p><?= $m['active'] ? $passed . ' of ' . count($staff) . ' active staff have passed the current version.' : 'Off: nobody is asked to take it.' ?> Pass mark <?= (int)$m['pass_pct'] ?>%.<?= $m['required'] ? ' Required.' : '' ?><?= $m['counts_as_privacy'] ? ' This is the privacy training.' : '' ?></p>
  <?php if ($m['active']): ?>
  <h3>Who has passed</h3>
  <ul>
  <?php foreach ($staff as $s): $c = $latest[(int)$s['id']][$mid] ?? null; ?>
    <li><?= h($s['name']) ?>: <?= $c ? ((int)$c['version'] === (int)$m['version'] ? 'passed ' . fmt_date($c['completed_at']) . ' (' . (int)$c['score'] . ' of ' . (int)$c['total'] . ')' : '<strong>needs to take it again</strong> (last passed ' . fmt_date($c['completed_at']) . ')') : '<strong>not passed</strong>' ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($editing === $mid): ?><h3>Edit</h3><?= $form($m, '_' . $mid) ?>
  <?php else: ?><p><a href="staff_training.php?edit=<?= $mid ?>#module-<?= $mid ?>">Edit <?= h($m['title']) ?></a></p><?php endif; ?>
</section>
<?php endforeach; ?>
<?php if (isset($_GET['new'])): ?>
<section class="card" aria-labelledby="new-h"><h2 id="new-h" tabindex="-1">Write a new training</h2><?= $form([], '') ?></section>
<?php endif; ?>

<section class="card" aria-labelledby="hold-h">
  <h2 id="hold-h" tabindex="-1">Before client records open</h2>
  <p>Whether staff must sign the agreements that are on (Staff agreements) and pass the required training before they can open client records. While something is outstanding, they keep their account, My agreements and My training; client records, referrals, the schedule and the other client areas open once it is done.</p>
  <form method="post" action="staff_training.php" class="stacked" novalidate><?= csrf_field() ?>
    <?php $mode = old_in('access_hold') !== '' ? old_in('access_hold') : access_hold_mode(); ?>
    <fieldset><legend>Hold client records</legend>
      <div class="field"><label><input type="radio" name="access_hold" value="new" <?= $mode === 'new' ? 'checked' : '' ?>> For new staff, until they have signed and passed</label></div>
      <div class="field"><label><input type="radio" name="access_hold" value="all" <?= $mode === 'all' ? 'checked' : '' ?>> For everyone, when an agreement or training is outstanding past the days below</label></div>
      <div class="field"><label><input type="radio" name="access_hold" value="off" <?= $mode === 'off' ? 'checked' : '' ?>> Never: only remind</label></div>
    </fieldset>
    <div class="field"><label for="hold_days">Days staff have to catch up, for everyone <span class="hint">0 to 90; new staff are held at once</span></label>
      <input type="number" id="hold_days" name="access_hold_days" min="0" max="90" value="<?= h(old_in('access_hold_days', (string)access_hold_days())) ?>" <?= field_aria('access_hold_days') ?>><?= field_error('access_hold_days') ?></div>
    <?php if (has_field_error('confirm_hold')): ?>
    <div class="field"><label><input type="checkbox" id="confirm_hold" name="confirm_hold" value="1" <?= field_aria('confirm_hold') ?>> I understand these staff lose client records until they finish</label><?= field_error('confirm_hold') ?></div>
    <?php endif; ?>
    <button type="submit" name="save_hold" value="1" class="btn btn-secondary">Save</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
