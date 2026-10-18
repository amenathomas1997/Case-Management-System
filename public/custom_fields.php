<?php
// Custom fields (administrators, 4.24.0): build the intake form. Fields of your own on client records,
// optionally asked on the public referral form (inc/custom_fields.php).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');
$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim(str_replace("\r", '', $_POST[$k])) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_field'])) {
    $id = (int)($_POST['field_id'] ?? 0);
    $sfx = $id ? '_' . $id : '';
    $errs = [];
    $label = $str('label');
    if ($label === '' || mb_strlen($label) > 120) $errs['label' . $sfx] = 'Label: name the field (120 characters or fewer).';
    if (mb_strlen($str('label_es')) > 120) $errs['label_es' . $sfx] = 'Spanish label: 120 characters or fewer.';
    if (mb_strlen($str('hint')) > 255) $errs['hint' . $sfx] = 'Hint: 255 characters or fewer.';
    $type = $str('type');
    if (!isset(CUSTOM_FIELD_TYPES[$type])) $errs['type' . $sfx] = 'Type: choose one from the list.';
    $options = implode("\n", custom_field_options(['options' => $str('options')]));
    if ($type === 'choice' && count(custom_field_options(['options' => $options])) < 2) $errs['options' . $sfx] = 'Choices: give at least two, one per line.';
    if (mb_strlen($options) > 4000) $errs['options' . $sfx] = 'Choices: 4,000 characters or fewer in all.';
    $order = preg_match('/^-?\d{1,4}$/', $str('sort_order')) ? (int)$str('sort_order') : 0;
    if ($errs) { keep_input(); keep_field_errors($errs); flash('Not saved: ' . implode(' ', $errs), 'error'); redirect('custom_fields.php' . ($id ? '#f-' . $id : '#add-h')); }
    $vals = [$label, $str('label_es') ?: null, $str('hint') ?: null, $type, $type === 'choice' ? $options : null, !empty($_POST['required']) ? 1 : 0, !empty($_POST['on_referral']) ? 1 : 0, $order, $id ? (!empty($_POST['is_active']) ? 1 : 0) : 1];
    if ($id) db()->prepare('UPDATE custom_fields SET label = ?, label_es = ?, hint = ?, type = ?, options = ?, required = ?, on_referral = ?, sort_order = ?, is_active = ? WHERE id = ?')->execute([...$vals, $id]);
    else db()->prepare('INSERT INTO custom_fields (label, label_es, hint, type, options, required, on_referral, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)')->execute($vals);
    audit((int)$user['id'], $id ? 'custom_field.save' : 'custom_field.add', 'settings', null, $label);
    flash('Saved the field ' . $label . '.');
    redirect('custom_fields.php');
}

$fields = custom_fields_all(false, true);
$form = function (?array $f): string {
    $id = (int)($f['id'] ?? 0);
    $sfx = $id ? '_' . $id : '';
    $mine = old_in('field_id') === (string)$id && old_in('save_field') !== '';
    $v = fn(string $k): string => $mine ? old_in($k) : (string)($f[$k] ?? '');
    $box = fn(string $k, bool $d): string => ($mine ? old_in($k) !== '' : (isset($f[$k]) ? (int)$f[$k] === 1 : $d)) ? ' checked' : '';
    ob_start(); ?>
  <form method="post" action="custom_fields.php" class="stacked" novalidate><?= csrf_field() ?><input type="hidden" name="field_id" value="<?= $id ?>"><input type="hidden" name="save_field" value="1">
    <div class="field"><label for="fl<?= $sfx ?>">Label <?= required_mark() ?></label><input type="text" id="fl<?= $sfx ?>" name="label" maxlength="120" value="<?= h($v('label')) ?>" <?= field_aria('label' . $sfx) ?>><?= field_error('label' . $sfx) ?></div>
    <div class="field"><label for="ft<?= $sfx ?>">Type</label><select id="ft<?= $sfx ?>" name="type" <?= field_aria('type' . $sfx) ?>><?= kv_options(CUSTOM_FIELD_TYPES, $v('type') ?: 'text', 'Choose') ?></select><?= field_error('type' . $sfx) ?></div>
    <div class="field"><label for="fo<?= $sfx ?>">Choices, one per line (for Choose one from a list)</label><textarea id="fo<?= $sfx ?>" name="options" rows="4" <?= field_aria('options' . $sfx) ?>><?= h($v('options')) ?></textarea><?= field_error('options' . $sfx) ?></div>
    <div class="field"><label for="fh<?= $sfx ?>">Hint, after the label (optional)</label><input type="text" id="fh<?= $sfx ?>" name="hint" maxlength="255" value="<?= h($v('hint')) ?>" <?= field_aria('hint' . $sfx) ?>><?= field_error('hint' . $sfx) ?></div>
    <div class="field"><label for="fe<?= $sfx ?>">Spanish label, for the public referral form (optional)</label><input type="text" id="fe<?= $sfx ?>" name="label_es" lang="es" maxlength="120" value="<?= h($v('label_es')) ?>" <?= field_aria('label_es' . $sfx) ?>><?= field_error('label_es' . $sfx) ?></div>
    <div class="field"><label for="fs<?= $sfx ?>">Order (lower numbers come first)</label><input type="number" id="fs<?= $sfx ?>" name="sort_order" min="-9999" max="9999" step="1" value="<?= h($v('sort_order') ?: '0') ?>"></div>
    <div class="field"><label><input type="checkbox" name="required" value="1"<?= $box('required', false) ?>> Required</label></div>
    <div class="field"><label><input type="checkbox" name="on_referral" value="1"<?= $box('on_referral', false) ?>> Also ask it on the public referral form</label></div>
    <?php if ($id): ?><div class="field"><label><input type="checkbox" name="is_active" value="1"<?= $box('is_active', true) ?>> In use (turn off to stop asking it; answers given are kept)</label></div><?php endif; ?>
    <button type="submit" class="btn btn-primary">Save<span class="visually-hidden"> <?= $id ? h((string)$f['label']) : 'the new field' ?></span></button>
  </form>
<?php return (string)ob_get_clean();
};
page_header('Custom fields', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Custom fields</h1>
<p>Build your intake form: add your own fields to client records, such as a funding program, a preferred device, or a question your agencies ask. They appear on each client's edit page and record, and the ones you choose are also asked on the public referral form, where the answers are carried to the client when the referral is accepted.</p>
<?php foreach ($fields as $f): $fid = (int)$f['id']; ?>
<section class="card" aria-labelledby="f-<?= $fid ?>">
  <h2 id="f-<?= $fid ?>" tabindex="-1"><?= h((string)$f['label']) ?><?= (int)$f['is_active'] ? '' : ' (not in use)' ?></h2>
  <p><?= h(implode(', ', array_filter([CUSTOM_FIELD_TYPES[$f['type']] ?? $f['type'], (int)$f['required'] ? 'required' : 'optional', (int)$f['on_referral'] ? 'asked on the referral form' : 'staff only']))) ?>.</p>
  <details<?= old_in('field_id') === (string)$fid ? ' open' : '' ?>><summary>Edit<span class="visually-hidden"> <?= h((string)$f['label']) ?></span></summary><?= $form($f) ?></details>
</section>
<?php endforeach; ?>
<section class="card" aria-labelledby="add-h">
  <h2 id="add-h" tabindex="-1">Add a field</h2>
  <?= $form(null) ?>
</section>
<?php page_footer();
