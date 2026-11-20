<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/totp.php';
require __DIR__ . '/inc/account_actions.php';
csrf_check();
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_alert_email'])) {
    // Shared with the app (inc/account_actions.php, 4.36.0); the select posts only the three choices.
    $r = account_alert_email_save((int)$user['id'], $_POST['alert_email'] ?? '');
    $r['ok'] ? flash($r['message']) : flash((string)reset($r['errors']), 'error');
    redirect('account.php#alerts-h');
}
// Your mobile number, and whether urgent alerts are texted to it (4.17.0).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mobile'])) {
    // Shared with the app (inc/account_actions.php, 4.36.0): the same check, rule and audit.
    $r = account_mobile_save((int)$user['id'], is_string($_POST['mobile'] ?? null) ? $_POST['mobile'] : '', !empty($_POST['alert_sms']));
    if (!$r['ok']) { keep_input(); keep_field_errors($r['errors']); redirect('account.php#mobile-h'); }
    flash($r['message']);
    redirect('account.php#mobile-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_display_controls'])) {
    $visibility = $_POST['display_controls'] ?? null;
    if (!is_string($visibility) || !in_array($visibility, ['show', 'hide'], true)) {
        flash('Choose whether to show or hide the low-vision controls.', 'error');
    } else {
        set_setting('user_low_vision_hidden_' . (int)$user['id'], $visibility === 'hide' ? '1' : '0');
        flash('Low-vision controls ' . ($visibility === 'hide' ? 'hidden' : 'shown') . '. You can change this in My Account at any time.');
    }
    redirect('account.php#display-settings');
}

/* ---- Calendar sync (4.20.0; three kinds of calendar since 4.33.0): on, with a first sync at once, or off (its events are taken out) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calendar_sync'])) {
    $uid = (int)$user['id'];
    if ($_POST['calendar_sync'] === 'on') {
        if (calendar_kind($uid) === null) { flash('Connect a calendar first (below), then turn calendar sync on.', 'error'); redirect('account.php#calendar-h'); }
        try { db()->prepare('UPDATE users SET calendar_sync = 1, calendar_kind = ? WHERE id = ?')->execute([calendar_kind($uid), $uid]); }
        catch (PDOException $e) { db()->prepare('UPDATE users SET calendar_sync = 1 WHERE id = ?')->execute([$uid]); }
        // A first pass of up to 20 seconds; the ten-minute sync carries on with the rest.
        $r = calendar_sync_user($uid, time() + 20);
        audit($uid, 'calendar.sync_on', 'user', $uid, $r['error'] ?? ($r['pushed'] . ' sessions'));
        flash($r['error'] === null ? 'Calendar sync is on: ' . $r['pushed'] . ' sessions are in your calendar so far, and the rest follow within ten minutes. Your calendar\'s busy times keep bookings away.' : 'Calendar sync is on, but: ' . $r['error'], $r['error'] === null ? 'success' : 'error');
    } else {
        $n = calendar_stop($uid);
        audit($uid, 'calendar.sync_off', 'user', $uid, $n . ' events removed');
        flash('Calendar sync is off' . ($n ? '; the ' . $n . ' session events it added were removed from your calendar.' : '.'));
    }
    redirect('account.php#calendar-h');
}

/* ---- Which calendar (4.33.0): connect a CalDAV or Google calendar, use the Microsoft one, test it, or disconnect it ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['calendar_action'] ?? null)) {
    $uid = (int)$user['id'];
    $act = (string)$_POST['calendar_action'];
    $text = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim((string)$_POST[$k]) : '';
    try {
        // Nothing is connected when it could not be stored afterwards (the key file).
        if (in_array($act, ['caldav_find', 'caldav_choose', 'google'], true)) meeting_key();
        if ($act === 'test') {
            try { flash(calendar_test($uid)); } catch (RuntimeException $e) { flash('The connection test failed: ' . $e->getMessage(), 'error'); }
        } elseif ($act === 'microsoft') {
            if (!calendar_microsoft_available() || !meeting_connected($uid, 'microsoft')) { flash('Connect Microsoft first (Online meetings, below), then choose it as your calendar.', 'error'); redirect('account.php#calendar-h'); }
            calendar_stop($uid, $left, calendar_kind($uid));
            calendar_account_forget($uid);
            db()->prepare("UPDATE users SET calendar_kind = 'microsoft' WHERE id = ?")->execute([$uid]);
            audit($uid, 'calendar.connect', 'user', $uid, 'microsoft');
            flash('Your Microsoft calendar is chosen. Turn on "Put my sessions in my calendar" below to start.');
        } elseif ($act === 'caldav_find' || $act === 'caldav_choose') {
            $server = $text('caldav_url'); $name = $text('caldav_user'); $pw = is_string($_POST['caldav_password'] ?? null) ? (string)$_POST['caldav_password'] : '';
            // Choosing among several calendars: the details were kept in the session, never sent back to the browser.
            if ($act === 'caldav_choose' && is_array($_SESSION['calendar_caldav_choice'] ?? null)) {
                $server = (string)$_SESSION['calendar_caldav_choice']['url']; $name = (string)$_SESSION['calendar_caldav_choice']['user']; $pw = (string)$_SESSION['calendar_caldav_choice']['password'];
            }
            if (!preg_match('#^https?://[^/\s]+#i', $server) || $name === '' || $pw === '') { flash('Enter the server address (starting with https://), your user name and the app password.', 'error'); redirect('account.php#calendar-h'); }
            $probe = ['kind' => 'caldav', 'user_id' => $uid, 'server_url' => $server, 'username' => $name, 'password' => $pw, 'calendar_url' => ''];
            try { $found = caldav_discover($probe); }
            catch (RuntimeException $e) { flash('The calendar could not be connected: ' . $e->getMessage(), 'error'); redirect('account.php#calendar-h'); }
            $chosen = $text('caldav_calendar');
            $pick = null;
            foreach ($found as [$url, $label]) if ($act === 'caldav_find' ? count($found) === 1 : $url === $chosen) $pick = [$url, $label];
            if ($pick === null) {
                // More than one calendar: the person picks, with the details held in their session only until then.
                $_SESSION['calendar_caldav_choice'] = ['url' => $server, 'user' => $name, 'password' => $pw, 'found' => $found];
                if ($act === 'caldav_choose') flash('Choose one of the calendars listed.', 'error');
                redirect('account.php#calendar-h');
            }
            unset($_SESSION['calendar_caldav_choice']);
            calendar_stop($uid, $left, calendar_kind($uid));
            calendar_account_store($uid, 'caldav', $pick[1], $server, $name, $pw, $pick[0]);
            audit($uid, 'calendar.connect', 'user', $uid, 'caldav ' . (parse_url($server, PHP_URL_HOST) ?: ''));
            flash('The calendar "' . $pick[1] . '" is connected. Turn on "Put my sessions in my calendar" below to start.');
        } elseif ($act === 'caldav_cancel') {
            unset($_SESSION['calendar_caldav_choice']);
        } elseif ($act === 'google') {
            $calId = $text('google_calendar');
            $sa = google_service_account((string)($_POST['google_key'] ?? ''), $why);
            if ($calId === '' || !preg_match('/^[A-Za-z0-9._%+@-]+$/', $calId)) { flash('Enter the calendar\'s address: usually your Gmail address, or the Calendar ID from the calendar\'s settings.', 'error'); redirect('account.php#calendar-h'); }
            if ($sa === null) { flash('The Google calendar could not be connected: ' . $why, 'error'); redirect('account.php#calendar-h'); }
            $probe = ['kind' => 'google', 'user_id' => $uid, 'id' => 0, 'server_url' => $calId, 'username' => $sa['client_email'], 'service_account' => $sa, 'access_token' => '', 'expires_at' => '', 'label' => ''];
            [$label, $err] = google_calendar_name($probe);
            if ($err !== null) { flash('The Google calendar could not be connected: ' . $err, 'error'); redirect('account.php#calendar-h'); }
            calendar_stop($uid, $left, calendar_kind($uid));
            calendar_account_store($uid, 'google', $label, $calId, $sa['client_email'], (string)json_encode($sa), '');
            audit($uid, 'calendar.connect', 'user', $uid, 'google');
            flash('The Google calendar "' . $label . '" is connected. Turn on "Put my sessions in my calendar" below to start.');
        } elseif ($act === 'disconnect') {
            $kind = calendar_kind($uid);
            $n = calendar_stop($uid, $left, $kind);
            calendar_account_forget($uid);
            audit($uid, 'calendar.disconnect', 'user', $uid, (string)$kind . ', ' . $n . ' events removed');
            flash('The calendar is disconnected' . ($n ? '; the ' . $n . ' session events it held were removed.' : '.') . ($left ? ' Some session events could not be removed, so delete any left in that calendar.' : ''));
        }
    } catch (MeetingKeyUnavailable $e) {
        flash('The calendar could not be connected: ' . MEETING_KEY_LOST_MSG, 'error');
    } catch (PDOException $e) {
        flash('Calendars need a database upgrade first: ask an administrator to run Database upgrade.', 'error');
    }
    redirect('account.php#calendar-h');
}

/* ---- Online meetings: disconnect Zoom or Microsoft (4.10.0; connecting is meeting_connect.php) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['meeting_disconnect'] ?? null) && isset(MEETING_PROVIDERS[$_POST['meeting_disconnect']])) {
    $prov = (string)$_POST['meeting_disconnect'];
    $calRemoved = null;
    try {
        // Outlook calendar sync runs on the Microsoft connection (4.32.0): it is turned off first,
        // while the connection can still remove the session events it put in Outlook.
        // Never in the way of disconnecting: a failure here leaves the events, and says so.
        $calLeft = 0;
        if ($prov === 'microsoft' && function_exists('calendar_stop') && calendar_kind((int)$user['id']) === 'microsoft') {
            $cs = db()->prepare('SELECT calendar_sync FROM users WHERE id = ?');
            $cs->execute([(int)$user['id']]);
            if ((int)$cs->fetchColumn()) {
                @set_time_limit(120);
                try {
                    $calRemoved = calendar_stop((int)$user['id'], $calLeft, 'microsoft');
                } catch (Throwable $e) {
                    error_log('disconnect calendar_stop: ' . get_class($e));
                    db()->prepare('UPDATE users SET calendar_sync = 0, calendar_error = NULL WHERE id = ?')->execute([(int)$user['id']]);
                    db()->prepare('DELETE FROM calendar_links WHERE user_id = ?')->execute([(int)$user['id']]);
                    $calRemoved = 0; $calLeft = -1;
                }
            }
        }
        meeting_disconnect((int)$user['id'], $prov);
    } catch (PDOException $e) {
        flash('Online meetings need a database upgrade first: ask an administrator to run Database upgrade.', 'error');
        redirect('account.php#meetings-h');
    }
    audit((int)$user['id'], 'meeting.disconnect', 'user', (int)$user['id'], $prov);
    flash(MEETING_PROVIDERS[$prov] . ' is disconnected. Links already made keep working; new remote sessions will not get ' . MEETING_PROVIDERS[$prov] . ' links until you connect again.'
        . ($calRemoved !== null ? ' Outlook calendar sync is off too' . ($calRemoved ? '; the ' . $calRemoved . ' session events it added were removed from Outlook.' : '.') : '')
        . ($calRemoved !== null && $calLeft !== 0 ? ' Some session events it added could not be removed, so delete any left in your Outlook calendar.' : ''));
    redirect('account.php#meetings-h');
}

/* ---- Phones signed in to the app: sign one out, or report it lost (3.0.39) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['device_signout']) || isset($_POST['device_lost']))) {
    $lost = isset($_POST['device_lost']);
    $ok = app_device_action((int)($_POST['device_id'] ?? 0), (int)$user['id'], $lost, (int)$user['id']);
    flash(app_device_action_message($ok, $lost), $ok ? 'success' : 'error');
    redirect('account.php#phones-h');
}

/**
 * Re-check the account password for a sensitive change on this page. Returns
 * null when it matches, else the message to show. Wrong guesses count toward a
 * shared limit; the one that reaches it ends the session, so this sends the
 * user to sign in instead of returning.
 */
function account_reauth_error(array $user, $password, string $wrong): ?string {
    // An empty box is a missed step, not a wrong password (3.3.1).
    if (!is_string($password) || $password === '') return 'Enter your password to confirm this change. Nothing was changed.';
    $r = reauth_check((int)$user['id'], $password);
    if ($r === 'ok') return null;
    if ($r === 'signed_out') { flash(REAUTH_SIGNED_OUT_MSG, 'error'); redirect('login.php'); }
    return $r === 'locked' ? REAUTH_LOCKED_MSG : $wrong;
}

/** account_reauth_error() as the password re-check the shared account actions take (inc/account_actions.php). */
function account_reauth(array $user): callable {
    return fn($password, string $wrong): ?string => account_reauth_error($user, $password, $wrong);
}

/** A shared action's field errors under this page's field ids. */
function account_field_ids(array $errors, array $ids): array {
    $out = [];
    foreach ($errors as $field => $message) $out[$ids[$field] ?? $field] = $message;
    return $out;
}

/* ---- TOTP: begin setup ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_begin'])) {
    // 4.29.0 (P14): never a setup whose key could not be kept encrypted (no usable key file).
    if (($why = totp_store_problem()) !== null) { flash($why, 'error'); redirect('account.php#totp'); }
    $_SESSION['totp_pending_secret'] = totp_generate_secret();
    redirect('account.php#totp');
}

/* ---- TOTP: confirm with a live code ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_confirm'])) {
    try {
        $r = account_totp_confirm($user, (string)($_SESSION['totp_pending_secret'] ?? ''), $_POST['password'] ?? '', $_POST['totp_code'] ?? '', account_reauth($user));
    } catch (TotpStoreRefused $e) {
        // 4.29.0 (P14): the seed could not be stored encrypted, so nothing was stored.
        $r = ['ok' => false, 'error' => $e->getMessage()];
    }
    if ($r['ok']) {
        unset($_SESSION['totp_pending_secret']);
        flash($r['message']);
    } elseif (isset($r['error'])) {
        flash($r['error'], 'error');
    } else {
        // On the field (the password is never refilled, so it is typed again).
        keep_field_errors(account_field_ids($r['errors'], ['password' => 'totp_pw', 'code' => 'totp_code']));
    }
    redirect('account.php#totp');
}

/* ---- TOTP: disable ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_disable'])) {
    $r = account_totp_disable($user, $_POST['password'] ?? '', account_reauth($user));
    if ($r['ok']) flash($r['message']);
    else keep_field_errors(account_field_ids($r['errors'], ['password' => 'td_pw']));
    redirect('account.php#totp');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $r = account_change_password($user, $_POST['current_password'] ?? '', $_POST['new_password'] ?? null, $_POST['confirm_password'] ?? null,
        !empty($_POST['ack_passkeys']), account_reauth($user));
    if (!$r['ok']) {
        keep_field_errors($r['errors']);
        redirect('account.php#pw-heading');
    }
    flash($r['message']);
    redirect('login.php');
}

/* Create or replace my calendar-feed address */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cal_regen'])) {
    $r = account_cal_regen($user, $_POST['password'] ?? '', account_reauth($user));
    if (!empty($r['forbidden'])) flash($r['error'], 'error');
    elseif (!$r['ok']) keep_field_errors(account_field_ids($r['errors'], ['password' => 'cal_pw']));   // on the field (never refilled)
    else flash($r['message']);
    redirect('account.php#calfeed');
}

/* Remove one of my passkeys */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_passkey'])) {
    $r = account_remove_passkey($user, (int)($_POST['passkey_id'] ?? 0));
    flash($r['ok'] ? $r['message'] : $r['error'], $r['ok'] ? 'success' : 'error');
    redirect('account.php#passkeys');
}

page_header('My Account', $user, 'account');
?>
<h1>My Account</h1>
<p class="muted">Your own settings: display, password, sign-in methods and calendar address.</p>
<?php if (can('admin')): // Agency-wide settings moved to their own page (3.0.27). ?>
<div class="page-actions">
  <a class="btn btn-primary" href="admin.php">Administration</a>
  <span class="muted">Team, permissions, sign-in, forms, email, data retention and AI settings.</span>
</div>
<?php endif; ?>
<section class="card" aria-labelledby="display-settings">
<h2 id="display-settings">Display and accessibility settings</h2>
<p>Show or hide the low-vision controls while signed in to this account, on any browser. Hiding the controls keeps your current colors, text size and other display preferences. You can always show the controls again here.</p>
<form method="post">
<?= csrf_field() ?>
<fieldset><legend>Low-vision controls</legend>
<?php $hiddenControls = setting('user_low_vision_hidden_' . (int)$user['id']) === '1'; ?>
<label><input type="radio" name="display_controls" value="show" <?= !$hiddenControls ? 'checked' : '' ?>> Show controls (expand when needed)</label>
<label><input type="radio" name="display_controls" value="hide" <?= $hiddenControls ? 'checked' : '' ?>> Hide controls</label>
</fieldset>
<button type="submit" name="save_display_controls" value="1" class="btn btn-primary">Save display settings</button>
</form>
</section>
<section class="card" aria-labelledby="training-link-h">
  <h2 id="training-link-h">My training and credentials</h2>
  <?php $toTake = training_needed((int)$user['id']); ?>
  <p><?= $toTake > 0 ? ($toTake === 1 ? 'One training is' : $toTake . ' trainings are') . ' waiting for you. ' : '' ?><a href="training.php">Open My training</a>. <a href="credentials.php">Open My credentials</a></p>
</section>
<section class="card" aria-labelledby="agreements-link-h">
  <h2 id="agreements-link-h">My agreements</h2>
  <?php $toSign = staff_agreements_needed((int)$user['id']); ?>
  <p><?= $toSign > 0 ? ($toSign === 1 ? 'One agreement needs' : $toSign . ' agreements need') . ' your signature.' : 'Read the agreements you have signed, or sign one that is due.' ?> <a href="agreements.php">Open My agreements</a></p>
</section>
<?php if (entra_cfg()): ?>
<section class="card" aria-labelledby="microsoft-link-h">
  <h2 id="microsoft-link-h">Microsoft sign-in</h2>
  <p>Link your Microsoft identity while signed in here. The Microsoft email must match this account. Existing links cannot be replaced through this form.</p>
  <form method="post" action="sso.php?start=1"><?= csrf_field() ?>
    <button type="submit" name="link_account" value="1" class="btn btn-secondary">Link Microsoft account</button>
  </form>
</section>
<?php endif; ?>
  <section class="card" aria-label="Your sign-in settings">
    <h2 id="pw-heading">Change password</h2>
    <p class="muted">Signed in as <?= h($user['name']) ?> (<?= h($user['email']) ?>) — role: <?= h($user['role']) ?></p>
    <?php
    // What a password change also does, said before the button (it cannot be undone).
    $pwFx = db()->prepare('SELECT (SELECT COUNT(*) FROM passkeys WHERE user_id = ?) AS pk,
        (SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND expires_at > NOW() AND wipe_requested_at IS NULL) AS app, (SELECT cal_token FROM users WHERE id = ?) AS cal');
    $pwFx->execute([$user['id'], $user['id'], $user['id']]);
    $pwFx = $pwFx->fetch();
    $myPasskeys = (int)$pwFx['pk'];
    $pwEffects = ['signs you out on every device' . ((int)$pwFx['app'] > 0 ? ', including the mobile app' : '')];
    if ($myPasskeys) $pwEffects[] = 'removes your ' . $myPasskeys . ' passkey' . ($myPasskeys === 1 ? '' : 's') . ' (you can add them again afterwards)';
    if ((string)$pwFx['cal'] !== '') $pwEffects[] = 'turns off your calendar subscription address';
    $lastFx = array_pop($pwEffects);
    ?>
    <?php // About the whole form, so above it rather than joined to one control (4.29.0). ?>
    <p class="hint">Changing your password <?= h(($pwEffects ? implode(', ', $pwEffects) . ' and ' : '') . $lastFx) ?>.</p>
    <form method="post" action="account.php" class="stacked" novalidate>
      <?= csrf_field() ?>
      <?= form_error_summary(['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password', 'ack_passkeys' => 'Passkeys']) ?>
      <div class="field">
        <label for="current_password">Current password <?= required_mark() ?></label>
        <input type="password" id="current_password" name="current_password" required aria-required="true" autocomplete="current-password" <?= field_aria('current_password') ?>>
        <?= field_error('current_password') ?>
      </div>
      <div class="field">
        <label for="new_password">New password <?= required_mark() ?> <span class="hint">At least 10 characters</span></label>
        <input type="password" id="new_password" name="new_password" required aria-required="true" minlength="10" autocomplete="new-password" <?= field_aria('new_password') ?>>
        <?= field_error('new_password') ?>
      </div>
      <div class="field">
        <label for="confirm_password">Confirm new password <?= required_mark() ?></label>
        <input type="password" id="confirm_password" name="confirm_password" required aria-required="true" autocomplete="new-password" <?= field_aria('confirm_password') ?>>
        <?= field_error('confirm_password') ?>
      </div>
      <?php if ($myPasskeys): ?>
      <div class="field"><label><input type="checkbox" id="ack_passkeys" name="ack_passkeys" value="1" <?= field_aria('ack_passkeys') ?>>
        I understand my <?= $myPasskeys ?> passkey<?= $myPasskeys === 1 ? '' : 's' ?> will be removed (required)</label>
        <?= field_error('ack_passkeys') ?></div>
      <?php endif; ?>
      <button type="submit" name="change_password" value="1" class="btn btn-primary">Change password</button>
    </form>

    <h2 id="totp">Authenticator app (optional second option)</h2>
    <?php
    $me = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $me->execute([$user['id']]);
    $totpOn = (bool)$me->fetchColumn();
    $pendingSecret = $_SESSION['totp_pending_secret'] ?? null;
    ?>
    <?php if ($totpOn): ?>
      <p><span class="badge badge-closed">Enabled</span> At sign-in, enter a code from your authenticator app (or use a passkey); codes are no longer emailed.</p>
      <form method="post" action="account.php" class="stacked" novalidate>
        <?= csrf_field() ?>
        <?= form_error_summary(['td_pw' => 'Password']) ?>
        <div class="field">
          <label for="td_pw">Confirm your password to disable <?= required_mark() ?></label>
          <input type="password" id="td_pw" name="password" required aria-required="true" autocomplete="current-password" <?= field_aria('td_pw') ?>>
          <?= field_error('td_pw') ?>
        </div>
        <button type="submit" name="totp_disable" value="1" class="btn btn-danger">Disable authenticator app</button>
      </form>
    <?php elseif ($pendingSecret): ?>
      <ol>
        <li>Open your authenticator app (Microsoft Authenticator, Google Authenticator, 1Password, etc.) and choose <strong>Add account → Enter a setup key</strong>.</li>
        <li>Account name: <strong><?= h($user['email']) ?></strong></li>
        <li>Key (spaces don't matter): <strong style="font-family:monospace; letter-spacing:.1em;"><?= h(trim(chunk_split($pendingSecret, 4, ' '))) ?></strong>
          <button type="button" class="btn btn-secondary btn-sm" id="totp-key-copy" data-key="<?= h($pendingSecret) ?>">Copy setup key</button>
          <span id="totp-key-copied" role="status" class="muted"></span>
          <script>document.getElementById('totp-key-copy').addEventListener('click', function () {
            var key = this.getAttribute('data-key'), note = document.getElementById('totp-key-copied');
            (navigator.clipboard ? navigator.clipboard.writeText(key) : Promise.reject())
              .then(function () { note.textContent = ''; setTimeout(function () { note.textContent = 'Setup key copied, without spaces.'; }, 60); })
              .catch(function () { note.textContent = 'Could not copy automatically — select the key and copy it.'; });
          });</script></li>
        <li>On a phone, you can instead tap this link: <a href="<?= h(totp_provisioning_uri($pendingSecret, $user['email'])) ?>">add to authenticator app</a></li>
      </ol>
      <?php // The password comes first and the timed code last, so the code is
            // typed just before submitting, inside its 30-second window. ?>
      <form method="post" action="account.php" class="stacked" novalidate>
        <?= csrf_field() ?>
        <?= form_error_summary(['totp_pw' => 'Account password', 'totp_code' => 'Code from the app']) ?>
        <div class="field">
          <label for="totp_pw">Confirm your account password <?= required_mark() ?> <span class="hint">Adding a sign-in method is sensitive, so your password is checked first.</span></label>
          <input type="password" id="totp_pw" name="password" required aria-required="true" autocomplete="current-password" <?= field_aria('totp_pw') ?>>
          <?= field_error('totp_pw') ?>
        </div>
        <div class="field">
          <label for="totp_code">Enter the 6-digit code the app shows now, to confirm <?= required_mark() ?> <span class="hint">Codes change every 30 seconds: enter the code shown right before you choose Confirm and enable.</span></label>
          <input type="text" id="totp_code" name="totp_code" class="otp-input" inputmode="numeric" maxlength="20" required aria-required="true" autocomplete="one-time-code" <?= field_aria('totp_code') ?>>
          <?= field_error('totp_code') ?>
        </div>
        <button type="submit" name="totp_confirm" value="1" class="btn btn-primary">Confirm and enable</button>
      </form>
    <?php else: ?>
      <p>Add an authenticator app for your sign-in codes. Once it is on, sign-in codes are no longer emailed.</p>
      <form method="post" action="account.php" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" name="totp_begin" value="1" class="btn btn-secondary">Set up authenticator app</button>
      </form>
    <?php endif; ?>

    <h2 id="mobile-h">Your mobile number</h2>
    <?php $mq = db()->prepare('SELECT mobile, alert_sms FROM users WHERE id = ?'); $mq->execute([(int)$user['id']]); $mine = $mq->fetch() ?: []; ?>
    <form method="post" action="account.php" class="stacked" novalidate><?= csrf_field() ?>
      <?= form_error_summary(['mobile' => 'Mobile number']) ?>
      <div class="field"><label for="mobile">Mobile number <span class="hint">optional; for urgent alerts by text and test texts</span></label>
        <input type="tel" id="mobile" name="mobile" maxlength="20" autocomplete="tel" value="<?= h(old_in('mobile', (string)($mine['mobile'] ?? ''))) ?>" <?= field_aria('mobile') ?>><?= field_error('mobile') ?></div>
      <?php if (sms_enabled()): ?><div class="field"><label><input type="checkbox" name="alert_sms" value="1" <?= !empty($mine['alert_sms']) ? 'checked' : '' ?>> Text me when an urgent alert comes in <span class="hint">at most one text every 15 minutes, never with client details</span></label></div><?php endif; ?>
      <button type="submit" name="save_mobile" value="1" class="btn btn-secondary btn-sm">Save</button>
    </form>

    <h2 id="alerts-h">How alerts reach you</h2>
    <?php $ae = db()->prepare('SELECT alert_email FROM users WHERE id = ?'); $ae->execute([$user['id']]); $alertHow = (string)($ae->fetchColumn() ?: 'off'); ?>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <label for="alert_email">Email me about alerts</label>
      <select id="alert_email" name="alert_email">
        <?php foreach (ACCOUNT_ALERT_EMAIL_CHOICES as $v => $label): ?>
        <option value="<?= h($v) ?>"<?= $alertHow === $v ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="save_alert_email" value="1" class="btn btn-secondary btn-sm">Save</button>
    </form>
    <h2 id="calfeed">Calendar subscription</h2>
    <?php
    $ct = db()->prepare('SELECT cal_token FROM users WHERE id = ?');
    $ct->execute([$user['id']]);
    $calToken = $ct->fetchColumn();
    // Creating or replacing the address re-checks the account password (as
    // adding an authenticator app does); the field is the same in both forms.
    $calPwField = '<div class="field"><label for="cal_pw">Confirm your account password for the calendar address ' . required_mark()
        . ' <span class="hint">The address gives access to your schedule, so your password is checked first.'
        . (entra_cfg() ? ' If you sign in only with Microsoft and have no password here, first set one with "Forgot your password?" on the sign-in page.' : '')
        . '</span></label><input type="password" id="cal_pw" name="password" required autocomplete="current-password" ' . field_aria('cal_pw') . '>'
        . field_error('cal_pw') . '</div>';
    ?>
    <?php if (!can('schedule')): ?>
      <p>A calendar subscription shows your scheduled sessions, so it needs the Scheduling permission, which your account does not have. Ask an administrator if you need it.</p>
    <?php elseif ($calToken): ?>
      <p>Subscribe your Outlook, Apple, or Google calendar to this private address and your sessions appear and update automatically. Client names are shortened to first name + last initial, and in-person locations are left out of the feed (they are on the schedule in the app):</p>
      <p><code id="calfeed-url" style="user-select: all; word-break: break-all;"><?= h(cfg('base_url')) ?>/calfeed.php?u=<?= h($calToken) ?></code></p>
      <p><a href="<?= h('webcal://' . preg_replace('#^https?://#', '', (string)cfg('base_url')) . '/calfeed.php?u=' . $calToken) ?>">Subscribe in your calendar app</a> (opens Calendar or Outlook on this device)</p>
      <p><button type="button" class="btn btn-secondary btn-sm" id="calfeed-copy">Copy address</button>
         <span id="calfeed-copied" role="status" class="muted"></span></p>
      <script>document.getElementById('calfeed-copy').addEventListener('click', function () {
        var url = document.getElementById('calfeed-url').textContent, note = document.getElementById('calfeed-copied');
        (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject())
          .then(function () { note.textContent = ''; setTimeout(function () { note.textContent = 'Address copied.'; }, 60); })
          .catch(function () { note.textContent = 'Could not copy automatically — select the address and copy it.'; });
      });</script>
      <form method="post" action="account.php" class="stacked" novalidate><?= csrf_field() ?>
        <?= form_error_summary(['cal_pw' => 'Account password']) ?>
        <?= $calPwField ?>
        <button type="submit" name="cal_regen" value="1" class="btn btn-secondary btn-sm"
          onclick="return confirm('Make a new calendar address? The old one stops working everywhere it was added.');">Replace the address</button>
      </form>
    <?php else: ?>
      <p>Get a private address your calendar app can subscribe to, so your sessions always show up without individual invites. Client names are shortened to first name + last initial, and in-person locations are left out of the feed (they are on the schedule in the app).</p>
      <form method="post" action="account.php" class="stacked" novalidate><?= csrf_field() ?>
        <?= form_error_summary(['cal_pw' => 'Account password']) ?>
        <?= $calPwField ?>
        <button type="submit" name="cal_regen" value="1" class="btn btn-secondary">Create my calendar address</button>
      </form>
    <?php endif; ?>

    <?php $connections = account_connections((int)$user['id']); $meetApps = $connections['meetings']; ?>
    <?php if ($meetApps): ?>
    <h2 id="meetings-h">Online meetings</h2>
    <p>Connect your own Zoom or Microsoft Teams account, and remote sessions you book for yourself, or an administrator books for you, can get their meeting link made there. Disconnecting Zoom also withdraws it at Zoom; for Microsoft, also remove the app from your Microsoft account's app permissions.</p>
    <ul>
    <?php foreach ($meetApps as $meet): $prov = $meet['provider']; ?>
      <li>
      <?php if ($meet['state'] === 'ended'): ?>
        <form method="post" action="meeting_connect.php?provider=<?= h($prov) ?>" class="inline-form"><?= csrf_field() ?>
          <?= h($meet['text']) ?>
          <button type="submit" class="btn btn-primary btn-sm">Connect <?= h(MEETING_PROVIDERS[$prov]) ?> again</button>
        </form>
        <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
          <button type="submit" name="meeting_disconnect" value="<?= h($prov) ?>" class="btn btn-secondary btn-sm">Disconnect <?= h(MEETING_PROVIDERS[$prov]) ?></button>
        </form>
      <?php elseif ($meet['state'] === 'connected'): ?>
        <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
          <?= h($meet['text']) ?>
          <button type="submit" name="meeting_disconnect" value="<?= h($prov) ?>" class="btn btn-secondary btn-sm">Disconnect <?= h(MEETING_PROVIDERS[$prov]) ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="meeting_connect.php?provider=<?= h($prov) ?>" class="inline-form"><?= csrf_field() ?>
          <?= h($meet['text']) ?>
          <button type="submit" class="btn btn-secondary btn-sm">Connect <?= h(MEETING_PROVIDERS[$prov]) ?></button>
        </form>
      <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h2 id="avail-h">My availability</h2>
    <p><a href="availability.php">My availability</a>: the hours you take sessions, which clients can book in the portal when self-booking is on.</p>
    <?php if ($connections['calendar'] !== null): $calStatus = $connections['calendar']; $calKind = $calStatus['kind'];
        $calChoice = is_array($_SESSION['calendar_caldav_choice'] ?? null) ? $_SESSION['calendar_caldav_choice'] : null; ?>
    <h2 id="calendar-h">My calendar</h2>
    <p>Put your sessions in your own calendar, kept up to date when they are booked, moved or cancelled. Your other appointments there then count as busy, so no session is offered, booked or moved over them. If you move or delete a session's event in your calendar, you are told, so you can change the session here; the calendar alone never changes a session. Three kinds of calendar work: your Microsoft account (work, or a personal Outlook.com account), a CalDAV calendar (iCloud, Nextcloud, a cPanel email account's calendar, Fastmail) or a Google calendar.</p>
    <?php if ($calKind !== null): ?>
    <h3 id="calendar-connected-h">Your calendar</h3>
    <p><?= h($calStatus['kind_text']) ?></p>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <button type="submit" name="calendar_action" value="test" class="btn btn-secondary btn-sm">Test the connection</button>
    </form>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <button type="submit" name="calendar_action" value="disconnect" class="btn btn-secondary btn-sm">Disconnect this calendar</button>
    </form>
    <?php if ($calStatus['sync_on']): ?>
    <p><?= h($calStatus['sync_text']) ?><?= $calStatus['error'] !== null ? ' <strong>Problem: ' . h($calStatus['error']) . '</strong>' : '' ?></p>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <button type="submit" name="calendar_sync" value="off" class="btn btn-secondary btn-sm">Turn off and remove the session events</button>
    </form>
    <?php else: ?>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <button type="submit" name="calendar_sync" value="on" class="btn btn-primary btn-sm">Put my sessions in my calendar</button>
    </form>
    <?php endif; ?>
    <p>To use another calendar, disconnect this one first.</p>
    <?php else: ?>
    <p>No calendar is connected yet. Connect one of these three.</p>
    <h3 id="calendar-ms-h">1. Microsoft (Outlook)</h3>
    <?php if (!calendar_microsoft_available()): ?>
    <p>An administrator has not set up the Microsoft connection (Settings, Online meetings).</p>
    <?php elseif (meeting_connected((int)$user['id'], 'microsoft')): ?>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <p>Microsoft is connected (Online meetings, below). Use its calendar.</p>
      <button type="submit" name="calendar_action" value="microsoft" class="btn btn-secondary btn-sm">Use my Microsoft calendar</button>
    </form>
    <?php else: ?>
    <p>Connect Microsoft under Online meetings below, then come back here and choose Use my Microsoft calendar. If you connected Microsoft before October 2026, connect it again to allow the calendar.</p>
    <?php endif; ?>
    <h3 id="calendar-caldav-h">2. A CalDAV calendar (iCloud, Nextcloud, cPanel, Fastmail)</h3>
    <p>Use an app password, not your account password: iCloud and Fastmail make one under your account's security settings; Nextcloud under Security, Devices and sessions. For a cPanel email account the address is usually your domain with port 2080, such as https://example.org:2080, and the user name is the email address.</p>
    <?php if ($calChoice !== null): ?>
    <form method="post" action="account.php"><?= csrf_field() ?>
      <fieldset>
        <legend>Several calendars were found at <?= h((string)$calChoice['url']) ?>. Which one should hold your sessions?</legend>
        <?php foreach ((array)$calChoice['found'] as $i => [$cUrl, $cLabel]): ?>
        <div class="field"><label><input type="radio" name="caldav_calendar" value="<?= h($cUrl) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= h($cLabel) ?></label></div>
        <?php endforeach; ?>
      </fieldset>
      <button type="submit" name="calendar_action" value="caldav_choose" class="btn btn-primary btn-sm">Use this calendar</button>
      <button type="submit" name="calendar_action" value="caldav_cancel" class="btn btn-secondary btn-sm">Start over</button>
    </form>
    <?php else: ?>
    <form method="post" action="account.php"><?= csrf_field() ?>
      <div class="field">
        <label for="caldav_url">Calendar server address <?= required_mark() ?> <span class="hint">For example https://caldav.icloud.com or https://example.org:2080</span></label>
        <input type="url" id="caldav_url" name="caldav_url" required aria-required="true" autocomplete="url" <?= field_aria('caldav_url') ?>>
      </div>
      <div class="field">
        <label for="caldav_user">User name <?= required_mark() ?> <span class="hint">Usually your email address</span></label>
        <input type="text" id="caldav_user" name="caldav_user" required aria-required="true" autocomplete="username" <?= field_aria('caldav_user') ?>>
      </div>
      <div class="field">
        <label for="caldav_password">App password <?= required_mark() ?></label>
        <input type="password" id="caldav_password" name="caldav_password" required aria-required="true" autocomplete="off" <?= field_aria('caldav_password') ?>>
      </div>
      <button type="submit" name="calendar_action" value="caldav_find" class="btn btn-secondary btn-sm">Connect the CalDAV calendar</button>
    </form>
    <?php endif; ?>
    <h3 id="calendar-google-h">3. Google Calendar</h3>
    <p>Google Calendar is used through a service account: a Google Cloud account that acts for the system. Ask your administrator for its key file, or make one: in Google Cloud, make a project, turn on the Google Calendar API, add a service account and download a JSON key for it. Then, in Google Calendar, open your calendar's settings, Share with specific people, and add the service account's email address with "Make changes to events". Paste the key file's contents below.</p>
    <form method="post" action="account.php"><?= csrf_field() ?>
      <div class="field">
        <label for="google_calendar">Calendar address <?= required_mark() ?> <span class="hint">Usually your Gmail address; otherwise the Calendar ID from the calendar's settings</span></label>
        <input type="text" id="google_calendar" name="google_calendar" required aria-required="true" autocomplete="off" <?= field_aria('google_calendar') ?>>
      </div>
      <div class="field">
        <label for="google_key">Service account key file (JSON) <?= required_mark() ?></label>
        <textarea id="google_key" name="google_key" rows="6" required aria-required="true" autocomplete="off" <?= field_aria('google_key') ?>></textarea>
      </div>
      <button type="submit" name="calendar_action" value="google" class="btn btn-secondary btn-sm">Connect the Google calendar</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <h2 id="phones-h">Phones signed in to the app</h2>
    <p>If a phone is lost or stolen, choose Report lost and wipe: the next time it connects, the app removes its drafts, unsent offline entries and saved lists, and signs out.</p>
    <?= app_devices_html(app_devices((int)$user['id']), 'account.php', []) ?>

    <h2 id="passkeys">Passkeys</h2>
    <p>Sign in with Face ID, Touch ID, Windows Hello, or your password manager — nothing to read or retype, and stronger than email codes.</p>
    <?php
    $pks = db()->prepare('SELECT id, name, created_at, last_used_at FROM passkeys WHERE user_id = ? ORDER BY created_at');
    $pks->execute([$user['id']]);
    $pks = $pks->fetchAll();
    ?>
    <?php if ($pks): ?>
    <ul>
      <?php foreach ($pks as $pk): ?>
      <li><?= h($pk['name']) ?> <span class="muted">— added <?= fmt_date($pk['created_at']) ?><?= $pk['last_used_at'] ? ', last used ' . fmt_date($pk['last_used_at']) : '' ?></span>
        <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="passkey_id" value="<?= (int)$pk['id'] ?>">
          <button type="submit" name="remove_passkey" value="1" class="btn btn-danger btn-sm"
            onclick="return confirm('Remove this passkey? You can still sign in with your password.');">Remove<span class="visually-hidden"> passkey <?= h($pk['name']) ?></span></button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div class="field">
      <label for="pk_name">Name for the new passkey <span class="hint">e.g. "My iPhone", "Work laptop"</span></label>
      <input type="text" id="pk_name" maxlength="120" value="">
    </div>
    <div class="field">
      <?php // 4.30.0: a wrong password is said inside the label and focus moves to the field, so it is heard once. ?>
      <label for="pk_pw">Confirm your account password <?= required_mark() ?> <span class="hint">adding a sign-in method is sensitive</span> <span class="field-error" id="pk_pw_err"></span></label>
      <input type="password" id="pk_pw" required aria-required="true" autocomplete="current-password">
    </div>
    <button type="button" id="pk_add" class="btn btn-secondary">Add a passkey on this device</button>
    <?php // Always rendered (empty until needed): a live region unhidden at the moment it changes is often not announced. ?>
    <p class="field-error" id="pk_err" role="alert"></p>
    <script>
    (function(){
      var btn = document.getElementById('pk_add'), err = document.getElementById('pk_err'), pw = document.getElementById('pk_pw'), pwErr = document.getElementById('pk_pw_err');
      function b64uToBuf(s){s=s.replace(/-/g,'+').replace(/_/g,'/');var bin=atob(s),a=new Uint8Array(bin.length);for(var i=0;i<bin.length;i++)a[i]=bin.charCodeAt(i);return a.buffer;}
      function bufToB64u(b){var a=new Uint8Array(b),s='';for(var i=0;i<a.length;i++)s+=String.fromCharCode(a[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
      function fail(m){err.textContent=m;}
      // A message the server wrote (wrong password, too many tries, already registered,
      // the device did not confirm it was you) is shown as it is (4.28.0), as on the sign-in page.
      function serverError(m, onPassword){var e=new Error(m);e.fromServer=true;e.onPassword=!!onPassword;return e;}
      // A password refusal is put inside the field's label and focus moves to the field (4.30.0):
      // heard once, with the field, never also through the alert below.
      function markPassword(bad, m){
        if (bad) { pwErr.textContent = m || ''; pw.setAttribute('aria-invalid','true'); pw.focus(); }
        else { pwErr.textContent = ''; pw.removeAttribute('aria-invalid'); }
      }
      if (!window.PublicKeyCredential) { btn.disabled = true; fail('This browser does not support passkeys.'); return; }
      btn.addEventListener('click', function(){
        err.textContent = ''; markPassword(false);
        // The password is checked before the device prompt, so a typo never
        // costs a Face ID / PIN / security-key round trip.
        fetch('passkey.php?action=reg_options', {method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({password: document.getElementById('pk_pw').value})})
          .then(function(r){return r.json();})
          .then(function(d){
            // Signed out after too many wrong passwords: the sign-in page explains.
            if (d.redirect) { location.href = d.redirect; return new Promise(function(){}); }
            // Before the device prompt, the only refusals are about the password.
            if (!d.ok) throw serverError(d.error || 'The passkey could not be started. Try again.', true);
            var o = d.options;
            o.challenge = b64uToBuf(o.challenge);
            o.user.id = b64uToBuf(o.user.id);
            o.excludeCredentials = (o.excludeCredentials||[]).map(function(c){return {type:c.type, id:b64uToBuf(c.id)};});
            return navigator.credentials.create({publicKey:o});
          })
          .then(function(cred){
            return fetch('passkey.php?action=reg_finish', {method:'POST', credentials:'same-origin',
              headers:{'Content-Type':'application/json'},
              body: JSON.stringify({
                clientDataJSON: bufToB64u(cred.response.clientDataJSON),
                attestationObject: bufToB64u(cred.response.attestationObject),
                name: document.getElementById('pk_name').value
              })});
          })
          .then(function(r){return r.json();})
          .then(function(d){
            if (!d.ok) throw serverError(d.error || 'The passkey could not be saved. Try again.', false);
            // The server queued a "Passkey added" message; the reloaded page
            // moves focus to it, so the save is announced.
            location.hash = 'passkeys';
            location.reload();
          })
          .catch(function(e){
            if (e && e.fromServer) { if (e.onPassword) { markPassword(true, e.message); } else { fail(e.message); } return; }
            fail(e && (e.name === 'NotAllowedError' || e.name === 'AbortError') ? 'The passkey was not added: the request was cancelled or timed out. Choose Add a passkey on this device to try again.' : (e && e.name === 'InvalidStateError' ? 'This passkey is already registered on this account.' : 'The passkey was not added. Try again, or use a different device or browser.')); });
      });
    })();
    </script>
  </section>
<?php page_footer();
