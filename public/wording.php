<?php
// Public wording (administrators, 4.3.0): the parts of the public pages that describe your
// own business, in English and Spanish. An empty box uses the built-in text.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_wording'])) {
    $errs = [];
    $vals = [];
    foreach (PUBLIC_WORDING as $key => [$label]) {
        foreach (['en' => '', 'es' => '_es'] as $lang => $sfx) {
            $v = trim(str_replace("\r", '', is_string($_POST[$key . $sfx] ?? null) ? $_POST[$key . $sfx] : ''));
            if (mb_strlen($v) > 4000) $errs[$key . $sfx] = $label . ': keep it to 4,000 characters.';
            $vals['wording_' . $key . $sfx] = $v;
        }
    }
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('wording.php'); }
    $changed = [];
    // Each change keeps the words it replaced, dated (the newest 50), so what a guardian
    // agreed to on a given day can be found again; the audit log names what changed.
    foreach ($vals as $k => $v) {
        $old = (string)(setting($k) ?? '');
        if ($old === $v) continue;
        $hist = json_decode((string)(setting($k . '_history') ?? '[]'), true);
        $hist = is_array($hist) ? $hist : [];
        array_unshift($hist, ['until' => date('c'), 'by' => (int)$user['id'], 'text' => $old]);
        set_setting($k . '_history', json_encode(array_slice($hist, 0, 50), JSON_UNESCAPED_UNICODE));
        set_setting($k, $v);
        $changed[] = $k;
    }
    audit($user['id'], 'settings.wording', 'settings', null, $changed ? implode(', ', $changed) : 'no changes');
    flash($changed ? 'Public wording saved.' : 'Nothing was changed.');
    redirect('wording.php');
}

$kept = old_in('save_wording') !== '';
page_header('Public wording', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Public wording</h1>
<p>Words on the public pages that describe your own business. Leave a box empty to use the built-in text. A Spanish box left empty shows the Spanish built-in text, or your English text when you wrote your own.</p>
<form method="post" action="wording.php" class="stacked" novalidate><?= csrf_field() ?>
  <input type="hidden" name="save_wording" value="1">
  <?= form_error_summary() ?>
  <?php foreach (PUBLIC_WORDING as $key => [$label, $default, $lines]): ?>
  <section class="card" aria-labelledby="<?= $key ?>-h">
    <h2 id="<?= $key ?>-h"><?= h($label) ?></h2>
    <?php foreach (['en' => ['', 'English'], 'es' => ['_es', 'Spanish']] as $lang => [$sfx, $langName]): $f = $key . $sfx; ?>
    <div class="field"><label for="<?= $f ?>"><?= h($label) ?>, in <?= $langName ?></label>
      <textarea id="<?= $f ?>" name="<?= $f ?>" rows="<?= $lines || $default === '' ? 5 : 3 ?>" maxlength="4000"<?= $lang === 'es' ? ' lang="es"' : '' ?> <?= field_aria($f) ?>><?= h($kept ? old_in($f) : wording_stored($key, $lang)) ?></textarea><?= field_error($f) ?></div>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
  <button type="submit" class="btn btn-primary">Save public wording</button>
</form>
<?php page_footer();
