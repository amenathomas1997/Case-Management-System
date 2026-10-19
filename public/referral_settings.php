<?php
// Administrator editor for the public referral form: the option lists, their
// Spanish labels, the hints, which referrer roles are offered and whether at
// least one service must be chosen. Stored as JSON in settings.referral_form;
// blank = the built-in lists in inc/referral_request.php.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

const RF_LISTS = ['purposes' => 'Referral purposes', 'services' => 'Assistive technology services', 'needs' => 'Specific needs'];
const RF_MAX_OPTIONS = 40;
const RF_MAX_LABEL = 80;
// What each list's English labels must fit, because they are stored on the
// referral (inc/referral_request.php): one purpose per referral, and every
// service (or need) a referrer might tick, joined with '; '.
const RF_LIST_LIMITS = [
    'purposes' => ['label' => REFERRAL_PURPOSE_MAX],
    'services' => ['label' => RF_MAX_LABEL, 'joined_chars' => REFERRAL_SERVICES_MAX],
    'needs' => ['label' => RF_MAX_LABEL, 'joined_bytes' => REFERRAL_NEEDS_MAX_BYTES],
];

/** One option per line, "English | Spanish" (Spanish optional). Returns [options, error]. */
function rf_parse_list(string $text, bool $required, int $maxEn = RF_MAX_LABEL): array {
    $out = []; $seen = [];
    foreach (preg_split('/\R/u', trim($text)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        [$en, $es] = array_map('trim', explode('|', $line, 2) + [1 => '']);
        if ($en === '') return [[], 'Each line needs an English label before the | sign.'];
        if (mb_strlen($en) > $maxEn) return [[], '"' . $en . '" is ' . mb_strlen($en) . ' characters; English labels in this list are limited to ' . $maxEn . '.'];
        if (mb_strlen($es) > RF_MAX_LABEL) return [[], 'Spanish labels are limited to ' . RF_MAX_LABEL . ' characters.'];
        if (str_contains($en, ';') || str_contains($es, ';')) return [[], 'Labels cannot contain a semicolon; it separates the choices that are saved on a referral.'];
        if (isset($seen[mb_strtolower($en)])) return [[], '"' . $en . '" is listed twice.'];
        $seen[mb_strtolower($en)] = true;
        $out[] = ['en' => $en, 'es' => $es];
    }
    if ($required && !$out) return [[], 'Enter at least one option, one per line.'];
    if (count($out) > RF_MAX_OPTIONS) return [[], 'At most ' . RF_MAX_OPTIONS . ' options are allowed.'];
    return [$out, null];
}
/** Record which built-in option each non-built-in purpose or service stands for,
 *  so the referral type never depends on positions (see referral_builtin_label()).
 *  An option keeps the meaning its label had; a new label typed over a removed
 *  one on the same line (the list otherwise unchanged in length) is a rename
 *  and takes that option's meaning; any other new label stands for none (''). */
function rf_with_builtins(string $k, array $opts): array {
    $builtins = ['purposes' => REFERRAL_PURPOSES, 'services' => REFERRAL_TECH_SERVICES][$k] ?? null;
    if ($builtins === null) return $opts;
    $current = referral_form_config()[$k]; // the saved lists, before this change
    $currentEn = array_column($current, 'en');
    $newEn = array_column($opts, 'en');
    foreach ($opts as $i => $o) {
        if (in_array($o['en'], $builtins, true)) continue;
        if (in_array($o['en'], $currentEn, true)) { $opts[$i]['builtin'] = referral_builtin_label($o['en'], $k, $builtins); continue; }
        $opts[$i]['builtin'] = '';
        if (count($opts) === count($current) && isset($current[$i]) && !in_array($current[$i]['en'], $newEn, true)) {
            $prev = referral_builtin_label($current[$i]['en'], $k, $builtins);
            if ($prev !== '' && !in_array($prev, $newEn, true)) $opts[$i]['builtin'] = $prev;
        }
    }
    return $opts;
}
function rf_list_text(array $options): string {
    return implode("\n", array_map(fn($o) => $o['en'] . ($o['es'] !== '' ? ' | ' . $o['es'] : ''), $options));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_form'])) {
    set_setting('referral_form', '');
    audit($user['id'], 'referral_form.reset', 'settings', null, 'built-in lists restored');
    flash('The built-in referral form was restored.');
    redirect('referral_settings.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_form'])) {
    $errors = []; $cfg = [];
    foreach (RF_LISTS as $k => $label) {
        [$opts, $err] = rf_parse_list((string)($_POST['list_' . $k] ?? ''), $k !== 'needs', RF_LIST_LIMITS[$k]['label']);
        // A referral can tick every option, so all of them joined must fit the column.
        $joined = implode('; ', array_column($opts, 'en'));
        if ($err === null && isset(RF_LIST_LIMITS[$k]['joined_chars']) && mb_strlen($joined) > RF_LIST_LIMITS[$k]['joined_chars']) {
            $err = 'All the services together, with 2 characters between each, come to ' . mb_strlen($joined) . ' characters; the limit is '
                . RF_LIST_LIMITS[$k]['joined_chars'] . ', because a referral can choose every one. Shorten or remove some.';
        }
        if ($err === null && isset(RF_LIST_LIMITS[$k]['joined_bytes']) && strlen($joined) > RF_LIST_LIMITS[$k]['joined_bytes']) {
            $err = 'All the needs together are too long to save on a referral (' . number_format(RF_LIST_LIMITS[$k]['joined_bytes']) . ' bytes). Shorten or remove some.';
        }
        if ($err !== null) $errors['list_' . $k] = $err;
        $cfg[$k] = rf_with_builtins($k, $opts);
    }
    $roles = array_values(array_filter((array)($_POST['roles'] ?? []), fn($r) => is_string($r) && in_array($r, REFERRAL_ROLE_KEYS, true)));
    if (!$roles) $errors['roles'] = 'Keep at least one kind of referrer, or nobody can use the form.';
    $cfg['roles'] = $roles;
    $cfg['min_services'] = ($_POST['min_services'] ?? '1') === '0' ? 0 : 1;
    $cfg['hints'] = [];
    foreach (['purpose', 'services', 'needs'] as $k) {
        // Browsers count a line break as one character towards maxlength but send
        // it as CR LF: stored (and measured) as LF, as the browser counted it.
        $en = str_replace("\r\n", "\n", trim((string)($_POST['hint_' . $k . '_en'] ?? '')));
        $es = str_replace("\r\n", "\n", trim((string)($_POST['hint_' . $k . '_es'] ?? '')));
        if (mb_strlen($en) > 500) $errors['hint_' . $k . '_en'] = 'English hints are limited to 500 characters; this one has ' . mb_strlen($en) . '.';
        if (mb_strlen($es) > 500) $errors['hint_' . $k . '_es'] = 'Spanish hints are limited to 500 characters; this one has ' . mb_strlen($es) . '.';
        $cfg['hints'][$k] = ['en' => $en, 'es' => $es];
    }
    if ($errors) {
        keep_input();
        // keep_input() keeps only text fields, so the ticked roles go back as one string.
        $_SESSION['old_input']['roles_ticked'] = implode(',', $roles);
        keep_field_errors($errors);
        redirect('referral_settings.php');
    }
    set_setting('referral_form', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    audit($user['id'], 'referral_form.save', 'settings', null,
        count($cfg['purposes']) . ' purposes, ' . count($cfg['services']) . ' services, ' . count($cfg['needs']) . ' needs; roles ' . implode(',', $roles));
    flash('Referral form saved. Open the public form to check it.');
    redirect('referral_settings.php');
}

$cfg = referral_form_config();
$v = fn(string $k, string $default) => h(old_in($k, $default));
// After a validation error the ticked roles come back from the kept input
// (none ticked stays none); otherwise the saved configuration is shown.
$checkedRoles = isset($GLOBALS['OLD_INPUT']['roles_ticked'])
    ? array_values(array_intersect(explode(',', old_in('roles_ticked')), REFERRAL_ROLE_KEYS))
    : $cfg['roles'];
// A referral can choose every service, so all of them joined must fit the column.
$servicesJoined = mb_strlen(implode('; ', array_column($cfg['services'], 'en')));
page_header('Referral form settings', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Referral form settings</h1>
<p class="muted">Decide what the public <a href="community_referral.php">referral form</a> asks. One option per line; add a Spanish label after a vertical bar, for example <code>Assessment | Evaluación</code>. Options already chosen on past referrals stay on those records even if you remove them here. <a href="admin.php#forms-h">Back to My Account</a></p>
<?= form_error_summary(['list_purposes' => 'Referral purposes', 'list_services' => 'Assistive technology services', 'list_needs' => 'Specific needs', 'roles' => 'Who may refer', 'hint_purpose_en' => 'Purpose hint (English)', 'hint_purpose_es' => 'Purpose hint (Spanish)',
    'hint_services_en' => 'Services hint (English)', 'hint_services_es' => 'Services hint (Spanish)', 'hint_needs_en' => 'Needs hint (English)', 'hint_needs_es' => 'Needs hint (Spanish)'], ['roles' => 'roles-group']) ?>
<?php if ($cfg['customized']): ?><p><span class="badge badge-active">customized</span> This form differs from the built-in one.</p><?php endif; ?>

<form method="post" action="referral_settings.php" class="card" novalidate>
  <?= csrf_field() ?>
  <fieldset id="roles-group" tabindex="-1"<?= has_field_error('roles') ? ' aria-describedby="err-roles"' : '' ?>>
    <legend><h2 class="legend-h">Who may refer</h2> <span class="hint">Untick a kind of referrer to remove it from the form. Each kind keeps its own required details (for example a school referral still asks for the parent or guardian).</span></legend>
    <?= field_error('roles') ?>
    <?php foreach (REFERRER_ROLES as $key => $label): ?>
      <label class="check"><input type="checkbox" name="roles[]" id="role_<?= h($key) ?>" value="<?= h($key) ?>" <?= in_array($key, $checkedRoles, true) ? 'checked' : '' ?>> <?= h($label) ?></label>
    <?php endforeach; ?>
  </fieldset>

  <fieldset>
    <legend><h2 class="legend-h">Questions and options</h2></legend>
  <?php foreach (RF_LISTS as $k => $label): ?>
  <div class="field">
    <label for="list_<?= h($k) ?>"><?= h($label) ?><?= $k === 'needs' ? ' <span class="hint">(optional: leave empty to hide the question)</span>' : '' ?>
    <?php if (isset(RF_LIST_LIMITS[$k]['joined_chars'])): ?>
    <span class="hint">One per line. Add <code>| Spanish label</code> to translate it. A referral can choose every service, so all the English labels together, counting 2 characters between each, must fit in <?= RF_LIST_LIMITS[$k]['joined_chars'] ?> characters; the saved list uses <?= $servicesJoined ?>. Spanish labels up to <?= RF_MAX_LABEL ?>; no semicolons.</span>
    <?php else: ?>
    <span class="hint">One per line. Add <code>| Spanish label</code> to translate it. Up to <?= RF_MAX_OPTIONS ?> options; English labels up to <?= RF_LIST_LIMITS[$k]['label'] ?> characters each; Spanish labels up to <?= RF_MAX_LABEL ?>; no semicolons.</span>
    <?php endif; ?></label>
    <textarea id="list_<?= h($k) ?>" name="list_<?= h($k) ?>" rows="<?= $k === 'needs' ? 10 : 6 ?>" <?= field_aria('list_' . $k) ?>><?= $v('list_' . $k, rf_list_text($cfg[$k])) ?></textarea>
    <?= field_error('list_' . $k) ?>
  </div>
  <?php endforeach; ?>

  <div class="field">
    <label for="min_services">Service choice</label>
    <select id="min_services" name="min_services">
      <option value="1" <?= old_in('min_services', (string)$cfg['min_services']) === '1' ? 'selected' : '' ?>>At least one service must be chosen</option>
      <option value="0" <?= old_in('min_services', (string)$cfg['min_services']) === '0' ? 'selected' : '' ?>>Services are optional</option>
    </select>
  </div>
  </fieldset>

  <fieldset>
    <legend><h2 class="legend-h">Hints shown under each question</h2></legend>
    <?php foreach (['purpose' => 'Referral purpose', 'services' => 'Assistive technology services', 'needs' => 'Specific needs'] as $k => $label): ?>
    <div class="field">
      <label for="hint_<?= h($k) ?>_en"><?= h($label) ?> hint (English)</label>
      <textarea id="hint_<?= h($k) ?>_en" name="hint_<?= h($k) ?>_en" rows="2" maxlength="500" <?= field_aria('hint_' . $k . '_en') ?>><?= $v('hint_' . $k . '_en', $cfg['hints'][$k]['en']) ?></textarea>
      <?= field_error('hint_' . $k . '_en') ?>
    </div>
    <div class="field">
      <label for="hint_<?= h($k) ?>_es"><?= h($label) ?> hint (Spanish) <span class="hint">(optional)</span></label>
      <textarea id="hint_<?= h($k) ?>_es" name="hint_<?= h($k) ?>_es" rows="2" maxlength="500" lang="es" <?= field_aria('hint_' . $k . '_es') ?>><?= $v('hint_' . $k . '_es', $cfg['hints'][$k]['es']) ?></textarea>
      <?= field_error('hint_' . $k . '_es') ?>
    </div>
    <?php endforeach; ?>
  </fieldset>

  <div class="actions">
    <button type="submit" name="save_form" value="1" class="btn btn-primary">Save referral form</button>
    <a class="btn btn-secondary" href="community_referral.php">Open the public form</a>
  </div>
</form>
<?php // The confirm sits on the button: session-forms.js re-submits the form after
// checking the session, which would run a form onsubmit confirm a second time. ?>
<form method="post" action="referral_settings.php" class="inline-form">
  <?= csrf_field() ?>
  <button type="submit" name="reset_form" value="1" class="btn btn-secondary" onclick="return confirm('Restore the built-in referral form? Your customized lists and hints will be discarded.');">Restore the built-in form</button>
</form>
<?php page_footer();
