<?php
// Phone apps (administrators, 4.18.0): this organisation's own Android and iPhone apps. The kit is
// the apps' source set to this installation's name, server, app ID and icon (inc/appkit.php), with
// the steps to build them and hand them to staff.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/appkit.php';
csrf_check();
$user = require_login();
require_perm('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_kit'])) {
    [$v, $errs] = appkit_check($_POST);
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('phone_apps.php#kit-h'); }
    // Kept, so the next kit (a new release) is made the same way and keeps the same app ID.
    foreach ($v as $k => $val) set_setting('appkit_' . $k, $val);
    try {
        $file = appkit_build($v);
    } catch (RuntimeException $e) {
        keep_input();
        flash('The app kit could not be made: ' . $e->getMessage(), 'error');
        redirect('phone_apps.php#kit-h');
    }
    audit($user['id'], 'appkit.download', 'settings', null, $v['app_id'] . ', ' . $v['display_name']);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9.]/', '-', $v['app_id']) . '-app-kit-' . APP_VERSION . '.zip"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    @unlink($file);
    exit;
}

$v = appkit_values();
$val = fn(string $k) => h(old_in($k) !== '' ? old_in($k) : $v[$k]);
page_header('Phone apps', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Phone apps</h1>
<p>Your own Android and iPhone apps, with your name and icon, signing in to this installation (<?= h(appkit_server()) ?>). Apple and Google require each organisation to publish under its own developer account, so you download the apps' source already set up for you, build it once, and hand it to your staff through Google Play, TestFlight or a direct download. The steps come in the kit.</p>
<p>Icon: <?= brand_icon_uploaded() ? 'your own, from Settings, Branding.' : 'the built-in one. To use your own, upload it on <a href="settings.php">Settings</a>, Branding, first.' ?></p>

<section class="card" aria-labelledby="kit-h">
  <h2 id="kit-h" tabindex="-1">Your app kit</h2>
  <form method="post" action="phone_apps.php" class="stacked" novalidate><?= csrf_field() ?>
    <div class="field"><label for="display_name">Name under the icon <span class="hint">short: phones cut off about 12 characters</span></label>
      <input type="text" id="display_name" name="display_name" maxlength="30" value="<?= $val('display_name') ?>" <?= field_aria('display_name') ?>><?= field_error('display_name') ?></div>
    <div class="field"><label for="spoken_name">How screen readers say the app's name</label>
      <input type="text" id="spoken_name" name="spoken_name" maxlength="80" value="<?= $val('spoken_name') ?>" <?= field_aria('spoken_name') ?>><?= field_error('spoken_name') ?></div>
    <div class="field"><label for="signin_title">Title on the sign-in screen</label>
      <input type="text" id="signin_title" name="signin_title" maxlength="80" value="<?= $val('signin_title') ?>" <?= field_aria('signin_title') ?>><?= field_error('signin_title') ?></div>
    <div class="field"><label for="app_id">App ID <span class="hint">your web address backwards, such as org.example.cms; once your app is in a store, keep it the same</span></label>
      <input type="text" id="app_id" name="app_id" maxlength="100" autocapitalize="none" spellcheck="false" value="<?= $val('app_id') ?>" <?= field_aria('app_id') ?>><?= field_error('app_id') ?></div>
    <div class="field"><label for="apple_team">Apple team ID <span class="hint">optional; 10 letters and numbers from Apple Developer, Membership</span></label>
      <input type="text" id="apple_team" name="apple_team" maxlength="10" autocapitalize="characters" spellcheck="false" value="<?= $val('apple_team') ?>" <?= field_aria('apple_team') ?>><?= field_error('apple_team') ?></div>
    <button type="submit" name="download_kit" value="1" class="btn btn-primary">Download the app kit (zip)</button>
  </form>
</section>
<?php page_footer();
