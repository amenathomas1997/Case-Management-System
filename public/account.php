<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/totp.php';
require __DIR__ . '/inc/account_actions.php';
csrf_check();
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_alert_email'])) {
    $how = in_array($_POST['alert_email'] ?? '', ['now', 'daily', 'off'], true) ? $_POST['alert_email'] : 'off';
    db()->prepare('UPDATE users SET alert_email = ? WHERE id = ?')->execute([$how, (int)$user['id']]);
    flash(['now' => 'Urgent alerts will be emailed to you as they happen (at most one email every 15 minutes), with one email a day when you have unread alerts.', 'daily' => 'You will get one email a day when you have unread alerts.', 'off' => 'Alerts will no longer be emailed; they stay in Alerts in the app and on the website.'][$how]);
    redirect('account.php#alerts-h');
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

/* ---- Online meetings: disconnect Zoom or Microsoft (4.10.0; connecting is meeting_connect.php) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['meeting_disconnect'] ?? null) && isset(MEETING_PROVIDERS[$_POST['meeting_disconnect']])) {
    $prov = (string)$_POST['meeting_disconnect'];
    try {
        meeting_disconnect((int)$user['id'], $prov);
    } catch (PDOException $e) {
        flash('Online meetings need a database upgrade first: ask an administrator to run Database upgrade.', 'error');
        redirect('account.php#meetings-h');
    }
    audit((int)$user['id'], 'meeting.disconnect', 'user', (int)$user['id'], $prov);
    flash(MEETING_PROVIDERS[$prov] . ' is disconnected. Links already made keep working; new remote sessions will not get ' . MEETING_PROVIDERS[$prov] . ' links until you connect again.');
    redirect('account.php#meetings-h');
}

/* ---- Phones signed in to the app: sign one out, or report it lost (3.0.39) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['device_signout']) || isset($_POST['device_lost']))) {
    $lost = isset($_POST['device_lost']);
    $ok = app_device_action((int)($_POST['device_id'] ?? 0), (int)$user['id'], $lost, (int)$user['id']);
    flash($ok ? ($lost ? 'Reported lost. The next time that phone connects, the app removes everything it keeps and signs out. Change your password too if someone may know it.' : 'That phone is signed out of the app.')
        : 'That phone was already signed out or reported lost.', $ok ? 'success' : 'error');
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
    $_SESSION['totp_pending_secret'] = totp_generate_secret();
    redirect('account.php#totp');
}

/* ---- TOTP: confirm with a live code ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_confirm'])) {
    $r = account_totp_confirm($user, (string)($_SESSION['totp_pending_secret'] ?? ''), $_POST['password'] ?? '', $_POST['totp_code'] ?? '', account_reauth($user));
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
  <a class="btn btn-primary" href="admin.php" aria-describedby="admin-link-hint">Administration</a>
  <span id="admin-link-hint" class="muted">Team, permissions, sign-in, forms, email, data retention and AI settings.</span>
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
        (SELECT COUNT(*) FROM api_tokens WHERE user_id = ?) AS app, (SELECT cal_token FROM users WHERE id = ?) AS cal');
    $pwFx->execute([$user['id'], $user['id'], $user['id']]);
    $pwFx = $pwFx->fetch();
    $myPasskeys = (int)$pwFx['pk'];
    $pwEffects = ['signs you out on every device' . ((int)$pwFx['app'] > 0 ? ', including the mobile app' : '')];
    if ($myPasskeys) $pwEffects[] = 'removes your ' . $myPasskeys . ' passkey' . ($myPasskeys === 1 ? '' : 's') . ' (you can add them again afterwards)';
    if ((string)$pwFx['cal'] !== '') $pwEffects[] = 'turns off your calendar subscription address';
    $lastFx = array_pop($pwEffects);
    ?>
    <form method="post" action="account.php" class="stacked" novalidate>
      <?= csrf_field() ?>
      <?= form_error_summary(['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password', 'ack_passkeys' => 'Passkeys']) ?>
      <div class="field">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password" <?= field_aria('current_password') ?>>
        <?= field_error('current_password') ?>
      </div>
      <div class="field">
        <label for="new_password">New password <span class="hint">At least 10 characters</span></label>
        <input type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password" <?= field_aria('new_password') ?>>
        <?= field_error('new_password') ?>
      </div>
      <div class="field">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" <?= field_aria('confirm_password') ?>>
        <?= field_error('confirm_password') ?>
      </div>
      <p class="hint" id="pw-effects">Changing your password <?= h(($pwEffects ? implode(', ', $pwEffects) . ' and ' : '') . $lastFx) ?>.</p>
      <?php if ($myPasskeys): ?>
      <div class="field"><label><input type="checkbox" id="ack_passkeys" name="ack_passkeys" value="1" <?= field_aria('ack_passkeys', 'pw-effects') ?>>
        I understand my <?= $myPasskeys ?> passkey<?= $myPasskeys === 1 ? '' : 's' ?> will be removed (required)</label>
        <?= field_error('ack_passkeys') ?></div>
      <?php endif; ?>
      <button type="submit" name="change_password" value="1" class="btn btn-primary" aria-describedby="pw-effects">Change password</button>
    </form>

    <h2 id="totp">Authenticator app (optional second option)</h2>
    <?php
    $me = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $me->execute([$user['id']]);
    $totpOn = (bool)$me->fetchColumn();
    $pendingSecret = $_SESSION['totp_pending_secret'] ?? null;
    ?>
    <?php if ($totpOn): ?>
      <p><span class="badge badge-closed">Enabled</span> At sign-in you can enter a code from your authenticator app <em>or</em> the emailed code — both work.</p>
      <form method="post" action="account.php" class="stacked" novalidate>
        <?= csrf_field() ?>
        <?= form_error_summary(['td_pw' => 'Password']) ?>
        <div class="field">
          <label for="td_pw">Confirm your password to disable</label>
          <input type="password" id="td_pw" name="password" autocomplete="current-password" <?= field_aria('td_pw') ?>>
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
          <label for="totp_pw">Confirm your account password (required)</label>
          <span class="hint" id="totp_pw-hint">Adding a sign-in method is sensitive, so your password is checked first.</span>
          <input type="password" id="totp_pw" name="password" required autocomplete="current-password" <?= field_aria('totp_pw', 'totp_pw-hint') ?>>
          <?= field_error('totp_pw') ?>
        </div>
        <div class="field">
          <label for="totp_code">Enter the 6-digit code the app shows now, to confirm</label>
          <span class="hint" id="totp_code-hint">Codes change every 30 seconds: enter the code shown right before you choose Confirm and enable.</span>
          <input type="text" id="totp_code" name="totp_code" class="otp-input" inputmode="numeric" maxlength="20" required autocomplete="one-time-code" <?= field_aria('totp_code', 'totp_code-hint') ?>>
          <?= field_error('totp_code') ?>
        </div>
        <button type="submit" name="totp_confirm" value="1" class="btn btn-primary">Confirm and enable</button>
      </form>
    <?php else: ?>
      <p>Add an authenticator app as a second way to get sign-in codes. Email codes keep working either way.</p>
      <form method="post" action="account.php" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" name="totp_begin" value="1" class="btn btn-secondary">Set up authenticator app</button>
      </form>
    <?php endif; ?>

    <h2 id="alerts-h">How alerts reach you</h2>
    <?php $ae = db()->prepare('SELECT alert_email FROM users WHERE id = ?'); $ae->execute([$user['id']]); $alertHow = (string)($ae->fetchColumn() ?: 'off'); ?>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <label for="alert_email">Email me about alerts</label>
      <select id="alert_email" name="alert_email">
        <option value="now"<?= $alertHow === 'now' ? ' selected' : '' ?>>Urgent ones at once, and daily</option>
        <option value="daily"<?= $alertHow === 'daily' ? ' selected' : '' ?>>Once a day</option>
        <option value="off"<?= $alertHow === 'off' ? ' selected' : '' ?>>Never</option>
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
    $calPwField = '<div class="field"><label for="cal_pw">Confirm your account password for the calendar address (required)</label>'
        . '<span class="hint" id="cal_pw-hint">The address gives access to your schedule, so your password is checked first.'
        . (entra_cfg() ? ' If you sign in only with Microsoft and have no password here, first set one with "Forgot your password?" on the sign-in page.' : '')
        . '</span><input type="password" id="cal_pw" name="password" required autocomplete="current-password" ' . field_aria('cal_pw', 'cal_pw-hint') . '>'
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

    <?php $meetApps = array_filter(array_keys(MEETING_PROVIDERS), fn($p) => meeting_app($p) !== null); ?>
    <?php if ($meetApps): ?>
    <h2 id="meetings-h">Online meetings</h2>
    <p>Connect your own Zoom or Microsoft Teams account, and remote sessions you book for yourself, or an administrator books for you, can get their meeting link made there. Disconnecting Zoom also withdraws it at Zoom; for Microsoft, also remove the app from your Microsoft account's app permissions.</p>
    <ul>
    <?php foreach ($meetApps as $prov): $acc = meeting_account((int)$user['id'], $prov); ?>
      <li>
      <?php if ($acc): ?>
        <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
          <?= h(MEETING_PROVIDERS[$prov]) ?>: connected<?= $acc['account_email'] ? ' as ' . h($acc['account_email']) : '' ?>.
          <button type="submit" name="meeting_disconnect" value="<?= h($prov) ?>" class="btn btn-secondary btn-sm">Disconnect <?= h(MEETING_PROVIDERS[$prov]) ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="meeting_connect.php?provider=<?= h($prov) ?>" class="inline-form"><?= csrf_field() ?>
          <?= h(MEETING_PROVIDERS[$prov]) ?>: not connected.
          <button type="submit" class="btn btn-secondary btn-sm">Connect <?= h(MEETING_PROVIDERS[$prov]) ?></button>
        </form>
      <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
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
      <label for="pk_pw">Confirm your account password <span class="hint">required — adding a sign-in method is sensitive</span></label>
      <input type="password" id="pk_pw" autocomplete="current-password">
    </div>
    <button type="button" id="pk_add" class="btn btn-secondary">Add a passkey on this device</button>
    <?php // Always rendered (empty until needed): a live region unhidden at the moment it changes is often not announced. ?>
    <p class="field-error" id="pk_err" role="alert"></p>
    <script>
    (function(){
      var btn = document.getElementById('pk_add'), err = document.getElementById('pk_err');
      function b64uToBuf(s){s=s.replace(/-/g,'+').replace(/_/g,'/');var bin=atob(s),a=new Uint8Array(bin.length);for(var i=0;i<bin.length;i++)a[i]=bin.charCodeAt(i);return a.buffer;}
      function bufToB64u(b){var a=new Uint8Array(b),s='';for(var i=0;i<a.length;i++)s+=String.fromCharCode(a[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
      function fail(m){err.textContent=m;}
      if (!window.PublicKeyCredential) { btn.disabled = true; fail('This browser does not support passkeys.'); return; }
      btn.addEventListener('click', function(){
        err.textContent = '';
        // The password is checked before the device prompt, so a typo never
        // costs a Face ID / PIN / security-key round trip.
        fetch('passkey.php?action=reg_options', {method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({password: document.getElementById('pk_pw').value})})
          .then(function(r){return r.json();})
          .then(function(d){
            // Signed out after too many wrong passwords: the sign-in page explains.
            if (d.redirect) { location.href = d.redirect; return new Promise(function(){}); }
            if (!d.ok) throw new Error(d.error || 'Could not start.');
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
            if (!d.ok) throw new Error(d.error || 'Could not save.');
            // The server queued a "Passkey added" message; the reloaded page
            // moves focus to it, so the save is announced.
            location.hash = 'passkeys';
            location.reload();
          })
          .catch(function(e){ fail(e && (e.name === 'NotAllowedError' || e.name === 'AbortError') ? 'The passkey was not added: the request was cancelled or timed out. Choose Add passkey to try again.' : (e && e.name === 'InvalidStateError' ? 'This passkey is already registered on this account.' : 'The passkey was not added. Try again, or use a different device or browser.')); });
      });
    })();
    </script>
  </section>
<?php page_footer();
