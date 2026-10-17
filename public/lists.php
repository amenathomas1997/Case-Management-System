<?php
// Lists and time limits (administrators, 4.4.0): the choices offered on forms and the number
// of days or percent behind reminders and warnings, so any AT business can use its own.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

const LIST_GROUPS = ['clients' => 'Clients and referrals', 'services' => 'Services', 'equipment' => 'Equipment', 'privacy' => 'Releases of information', 'times' => 'Time limits and warnings'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lists'])) {
    $group = is_string($_POST['save_lists']) && isset(LIST_GROUPS[$_POST['save_lists']]) ? $_POST['save_lists'] : '';
    if ($group === '') redirect('lists.php');
    $errs = []; $saves = [];
    if ($group === 'times') {
        foreach (CMS_NUMBERS as $k => [$label, , $min, $max]) {
            $v = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
            if ($v === '') { $saves[cms_number_setting($k)] = ''; continue; }
            if (!ctype_digit($v) || (int)$v < $min || (int)$v > $max) { $errs[$k] = $label . ': enter a whole number from ' . $min . ' to ' . $max . '.'; continue; }
            $saves[cms_number_setting($k)] = (string)(int)$v;
        }
        foreach (CMS_DAY_LISTS as $k => [$label]) {
            $v = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
            if ($v === '') { $saves['days_' . $k] = ''; continue; }
            $days = cms_parse_days($v);
            if ($days === null) { $errs[$k] = $label . ': up to 6 whole numbers of days from 1 to 730, with a comma between, such as 7, 14, 30.'; continue; }
            $saves['days_' . $k] = implode(', ', $days);
        }
    } else {
        foreach (cms_list_defs() as $k => [$label, $kind, $default, $g]) {
            if ($g !== $group) continue;
            $text = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
            if (!empty($_POST[$k . '_reset'])) { $saves['list_' . $k] = ''; continue; }
            // A list the form did not send is left as it is; an empty one whose built-in list is
            // empty (the referral sources an organisation adds, 4.11.0) goes back to none.
            if (!array_key_exists($k, $_POST)) continue;
            if (trim($text) === '' && (defined($default) ? constant($default) : []) === []) { $saves['list_' . $k] = ''; continue; }
            [$val, $err] = cms_list_parse($k, $text);
            if ($err !== null) { $errs[$k] = $label . ': ' . $err; continue; }
            $saves['list_' . $k] = $val == (defined($default) ? constant($default) : null) ? '' : json_encode($val, JSON_UNESCAPED_UNICODE);
        }
    }
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('lists.php#' . $group . '-h'); }
    $changed = [];
    foreach ($saves as $k => $v) if ((string)(setting($k) ?? '') !== $v) { set_setting($k, $v); $changed[] = $k; }
    audit($user['id'], 'settings.lists', 'settings', null, $changed ? implode(', ', $changed) : 'no changes');
    flash(LIST_GROUPS[$group] . ($changed ? ' saved.' : ': nothing was changed.'));
    redirect('lists.php#' . $group . '-h');
}

$kept = fn(string $group) => old_in('save_lists') === $group;
page_header('Lists and time limits', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Lists and time limits</h1>
<p>The choices forms offer, and the days and percent behind reminders and warnings. Write one choice per line. Removing a choice does not change the records that already have it.</p>
<?php foreach (LIST_GROUPS as $group => $title): ?>
<section class="card" aria-labelledby="<?= $group ?>-h">
  <h2 id="<?= $group ?>-h"><?= h($title) ?></h2>
  <form method="post" action="lists.php" class="stacked" novalidate><?= csrf_field() ?>
    <?php if ($kept($group)) echo form_error_summary(); ?>
    <?php if ($group === 'times'): ?>
      <?php foreach (CMS_NUMBERS as $k => [$label, $default, $min, $max]): $stored = (string)(setting(cms_number_setting($k)) ?? ''); ?>
      <div class="field"><label for="<?= $k ?>"><?= h($label) ?> (built-in <?= (int)$default ?>)</label>
        <input type="number" id="<?= $k ?>" name="<?= $k ?>" min="<?= $min ?>" max="<?= $max ?>" step="1" value="<?= h($kept($group) ? old_in($k) : $stored) ?>" placeholder="<?= (int)$default ?>" <?= field_aria($k) ?>><?= field_error($k) ?></div>
      <?php endforeach; ?>
      <?php foreach (CMS_DAY_LISTS as $k => [$label, $default]): $stored = (string)(setting('days_' . $k) ?? ''); ?>
      <div class="field"><label for="<?= $k ?>"><?= h($label) ?> (built-in <?= h(implode(', ', $default)) ?>)</label>
        <input type="text" id="<?= $k ?>" name="<?= $k ?>" maxlength="40" value="<?= h($kept($group) ? old_in($k) : $stored) ?>" placeholder="<?= h(implode(', ', $default)) ?>" <?= field_aria($k) ?>><?= field_error($k) ?></div>
      <?php endforeach; ?>
    <?php else: foreach (cms_list_defs() as $k => [$label, $kind, $default, $g]): if ($g !== $group) continue; $own = (string)(setting('list_' . $k) ?? '') !== ''; ?>
      <?php $locked = cms_list_defs()[$k][4] ?? []; ?>
      <div class="field"><label for="<?= $k ?>"><?= h($label) ?><?= $own ? '' : ' (built-in)' ?><?= $locked ? '; always keeps ' . h(implode(', ', $locked)) : '' ?></label>
        <textarea id="<?= $k ?>" name="<?= $k ?>" rows="<?= min(12, max(4, count(cms_list($k)) + 1)) ?>" <?= field_aria($k) ?>><?= h($kept($group) ? old_in($k) : cms_list_text($k)) ?></textarea><?= field_error($k) ?></div>
      <?php if ($own): ?><div class="field"><label><input type="checkbox" name="<?= $k ?>_reset" value="1"<?= $kept($group) && old_in($k . '_reset') !== '' ? ' checked' : '' ?>> <?= h(preg_replace('/ \(.*$|, \d+ to \d+.*$|, and .*$/', '', $label)) ?>: go back to the built-in list</label></div><?php endif; ?>
    <?php endforeach; endif; ?>
    <button type="submit" name="save_lists" value="<?= $group ?>" class="btn btn-primary">Save <?= h(mb_strtolower($title)) ?></button>
  </form>
</section>
<?php endforeach; ?>
<?php page_footer();
