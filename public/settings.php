<?php
// Settings (3.0.54): Microsoft Graph email, the addresses the system uses, sign-in timeouts,
// the time zone and branding, changed here instead of in config.php on the server.
// Administrators only. Each value left empty uses config.php's.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('admin');
csrf_check();

const SETTINGS_GROUPS = ['time' => 'Time zone', 'graph' => 'Microsoft Graph email', 'addresses' => 'Email addresses', 'signin' => 'Sign-in', 'brand' => 'Branding', 'legal' => 'Privacy notice', 'updates' => 'Updates', 'meetings' => 'Online meetings (Zoom and Teams)', 'texting' => 'Text messages (Twilio)', 'quickbooks' => 'QuickBooks Online', 'security' => 'Security'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_group'])) {
    $group = is_string($_POST['save_group']) && isset(SETTINGS_GROUPS[$_POST['save_group']]) ? $_POST['save_group'] : '';
    if ($group === '') redirect('settings.php');
    $errors = [];
    if ($group === 'graph') {
        // New Graph details are tried with Microsoft before they are saved: a wrong one would
        // stop every email, sign-in codes included.
        $eff = [];
        foreach (['tenant_id', 'client_id', 'client_secret', 'sender'] as $gk) {
            $f = 'graph_' . $gk;
            $typed = trim((string)($_POST[$f] ?? ''));
            // What would be in force after saving: the typed value; for an empty box, config.php's
            // (an empty box clears a stored value, except the secret, which an empty box keeps).
            $fromConfig = (string)($GLOBALS['CONFIG_FILE']['graph'][$gk] ?? '');
            if ($gk === 'client_secret') $eff[$gk] = !empty($_POST[$f . '_clear']) ? $fromConfig
                : ($typed !== '' ? $typed : ((string)(setting('cfg.graph.client_secret') ?? '') ?: $fromConfig));
            else $eff[$gk] = $typed !== '' ? $typed : $fromConfig;
        }
        $complete = $eff['tenant_id'] !== '' && $eff['client_id'] !== '' && $eff['client_secret'] !== '';
        if ($complete && !graph_credentials_work($eff['tenant_id'], $eff['client_id'], $eff['client_secret'])) {
            keep_input();
            keep_field_errors(['graph_tenant_id' => 'Microsoft did not accept these details (tenant ID, application ID and secret together), so nothing was saved. Check them in Microsoft Entra.']);
            redirect('settings.php#graph-h');
        }
    }
    // 4.29.0: the whole group is checked before any of it is kept: every value is saved inside
    // one transaction, and a single refused value rolls the group back, so a refused save
    // changes nothing (it used to keep the valid boxes and refuse the rest without saying so).
    db()->beginTransaction();
    try {
        foreach (CONFIG_OVERRIDES as $k => [$label, $kind, $g]) {
            if ($g !== $group) continue;
            $field = str_replace('.', '_', $k);
            if ($kind === 'secret') {
                // A secret is never shown again: empty keeps it, unless "clear" is ticked.
                $typed = (string)($_POST[$field] ?? '');
                if (!empty($_POST[$field . '_clear'])) $err = config_override_save($k, '', (int)$user['id']);
                elseif ($typed !== '') $err = config_override_save($k, $typed, (int)$user['id']);
                else $err = null;
            } else {
                $err = config_override_save($k, (string)($_POST[$field] ?? ''), (int)$user['id']);
            }
            if ($err !== null) $errors[$field] = $err;
        }
        if ($errors) db()->rollBack(); else db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    if ($errors) {
        keep_input();
        keep_field_errors($errors);
        redirect('settings.php#' . $group . '-h');
    }
    flash(SETTINGS_GROUPS[$group] . ' saved.' . ($group === 'graph' ? ' Send yourself a test email to check it works.' : ''));
    redirect('settings.php#' . $group . '-h');
}
/* The app icon (4.3.0): a square picture made into every size, or back to the built-in ones. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_icon'])) {
    if (!empty($_POST['remove_icon'])) {
        brand_icon_remove();
        audit($user['id'], 'settings.icon', 'settings', null, 'removed');
        flash('The built-in icons are back.');
        redirect('settings.php#icon-h');
    }
    $f = $_FILES['icon'] ?? null;
    $err = !$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ? 'Choose a picture to upload.' : brand_icon_save((string)$f['tmp_name']);
    if ($err !== null) { keep_field_errors(['icon' => $err]); redirect('settings.php#icon-h'); }
    audit($user['id'], 'settings.icon', 'settings', null, 'uploaded');
    flash('The app icon is saved. Phones that already added the app to their home screen show it after they add it again.');
    redirect('settings.php#icon-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    $sent = send_app_mail((string)$user['email'], 'Test email from ' . brand('org'), "This is a test email from the Case Management System's Settings page. If you can read it, email is working.\n");
    flash($sent ? 'A test email was sent to ' . $user['email'] . '. Check that it arrived (and not in junk).' : 'The test email could not be sent. Check the Microsoft Graph details.', $sent ? 'success' : 'error');
    redirect('settings.php#graph-h');
}

page_header('Settings', $user, 'admin', [['admin.php', 'Administration']]);
$current = function (string $k) {
    [$s, $key] = explode('.', $k, 2);
    return $s === 'brand' ? (string)($GLOBALS['CONFIG_FILE']['brand'][$key] ?? brand($key)) : (string)($GLOBALS['CONFIG_FILE'][$s][$key] ?? '');
};
?>
<h1>Settings</h1>
<p>Each value here replaces the one in the server's config.php. Leave a box empty to use config.php's value again. If a saved value ever stops the system working, add 'ignore_db_settings' => true to the app section of config.php, sign in, correct it here, then remove that line.</p>
<?php foreach (SETTINGS_GROUPS as $group => $title): ?>
<section class="card" aria-labelledby="<?= $group ?>-h">
  <h2 id="<?= $group ?>-h"><?= h($title) ?></h2>
  <form method="post" action="settings.php" class="stacked" novalidate><?= csrf_field() ?>
    <?php // 4.29.0: a refused save is announced (focus moves to this summary), with each error linked to its box.
    $groupLabels = [];
    foreach (CONFIG_OVERRIDES as $k => [$label, $kind, $g]) if ($g === $group) $groupLabels[str_replace('.', '_', $k)] = '';
    echo form_error_summary($groupLabels); ?>
    <?php foreach (CONFIG_OVERRIDES as $k => [$label, $kind, $g]): if ($g !== $group) continue;
        $field = str_replace('.', '_', $k); $stored = (string)(setting('cfg.' . $k) ?? ''); ?>
    <div class="field">
      <?php if ($kind === 'secret'): ?>
      <label for="<?= $field ?>"><?= h($label) ?> (<?= $stored !== '' ? 'one is stored here; leave empty to keep it' : (!empty($GLOBALS['CONFIG_FILE'][explode('.', $k)[0]][explode('.', $k)[1]] ?? null) ? 'config.php has one; leave empty to keep using it' : 'none set') ?>)</label>
      <input type="password" id="<?= $field ?>" name="<?= $field ?>" autocomplete="off" <?= field_aria($field) ?>><?= field_error($field) ?>
      <?php if ($stored !== ''): ?><label><input type="checkbox" name="<?= $field ?>_clear" value="1"> Remove the stored secret (use config.php's)</label><?php endif; ?>
      <?php elseif ($kind === 'yesno'): ?>
      <label for="<?= $field ?>"><?= h($label) ?></label>
      <select id="<?= $field ?>" name="<?= $field ?>" <?= field_aria($field) ?>><option value="">Use config.php (<?= $current($k) === 'yes' || ($current($k) === '' && in_array($k, YESNO_DEFAULT_YES, true)) ? 'yes' : 'no' ?>)</option><?php foreach (['yes' => 'Yes', 'no' => 'No'] as $yv => $yl): ?><option value="<?= $yv ?>"<?= old_in($field, $stored) === $yv ? ' selected' : '' ?>><?= $yl ?></option><?php endforeach; ?></select><?= field_error($field) ?>
      <?php elseif ($kind === 'timezone'): ?>
      <label for="<?= $field ?>"><?= h($label) ?></label>
      <select id="<?= $field ?>" name="<?= $field ?>" <?= field_aria($field) ?>><option value="">Use config.php (<?= h(US_TIME_ZONES[$current($k)] ?? str_replace('_', ' ', $current($k))) ?>)</option><?php foreach (CONFIG_TIMEZONES as $tz): ?><option value="<?= h($tz) ?>"<?= old_in($field, $stored) === $tz ? ' selected' : '' ?>><?= h(US_TIME_ZONES[$tz] ?? str_replace('_', ' ', $tz)) ?></option><?php endforeach; ?></select><?= field_error($field) ?>
      <?php else: ?>
      <label for="<?= $field ?>"><?= h($label) ?></label>
      <input type="<?= $kind === 'email' ? 'email' : (in_array($kind, ['minutes', 'otp'], true) ? 'number' : 'text') ?>" id="<?= $field ?>" name="<?= $field ?>" value="<?= h(old_in($field, $stored)) ?>" placeholder="<?= h($current($k)) ?>"<?= str_ends_with($k, '_es') ? ' lang="es"' : '' ?> <?= field_aria($field) ?>><?= field_error($field) ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <button type="submit" name="save_group" value="<?= $group ?>" class="btn btn-primary">Save <?= h(mb_strtolower($title)) ?></button>
  </form>
  <?php if ($group === 'graph'): ?>
  <form method="post" action="settings.php" class="inline-form"><?= csrf_field() ?><button type="submit" name="test_email" value="1" class="btn btn-secondary btn-sm">Send a test email to me</button></form>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<section class="card" aria-labelledby="icon-h">
  <h2 id="icon-h">App icon</h2>
  <p><?= brand_icon_uploaded() ? 'Your own icon is in use.' : 'The built-in icons are in use.' ?> It shows when someone adds the system to their phone's home screen.</p>
  <form method="post" action="settings.php" enctype="multipart/form-data" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['icon' => 'App icon']) ?>
    <div class="field"><label for="icon">A square PNG or JPEG picture, at least 512 by 512 pixels</label>
      <input type="file" id="icon" name="icon" accept="image/png,image/jpeg" <?= field_aria('icon') ?>><?= field_error('icon') ?></div>
    <?php if (brand_icon_uploaded()): ?><div class="field"><label><input type="checkbox" name="remove_icon" value="1"> Go back to the built-in icons</label></div><?php endif; ?>
    <button type="submit" name="save_icon" value="1" class="btn btn-primary">Save icon</button>
  </form>
</section>
<?php page_footer();
