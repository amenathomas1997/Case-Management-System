<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/totp.php';
csrf_check();
$user = require_login();

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

/* ---- TOTP: begin setup ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_begin'])) {
    $_SESSION['totp_pending_secret'] = totp_generate_secret();
    redirect('account.php#totp');
}

/* ---- TOTP: confirm with a live code ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_confirm'])) {
    $secret = $_SESSION['totp_pending_secret'] ?? '';
    // Adding a sign-in method is sensitive: re-confirm the account password
    // first, as passkey enrolment and disabling the authenticator already do.
    $pwErr = account_reauth_error($user, $_POST['password'] ?? '', 'Password incorrect — the authenticator app was not enabled.');
    if (!$secret) {
        flash('The authenticator setup had ended, so nothing was changed. Choose "Set up authenticator app" to start again.', 'error');
    } elseif ($pwErr !== null) {
        // On the field (the password is never refilled, so it is typed again).
        keep_field_errors(['totp_pw' => $pwErr]);
    } elseif (totp_verify($secret, is_string($_POST['totp_code'] ?? null) ? $_POST['totp_code'] : '')) {
        db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')->execute([$secret, $user['id']]);
        unset($_SESSION['totp_pending_secret']);
        audit($user['id'], 'totp.enable', 'user', $user['id']);
        send_app_mail($user['email'], 'An authenticator app was added to your account',
            "Hello {$user['name']},\n\nAn authenticator app was just set up for your " . cfg('name') . " account. Its codes can be used at sign-in in place of the emailed code.\n\n"
            . "If this was you, no action is needed. If it was NOT you, disable it under My Account right away, change your password, and tell your administrator.\n");
        flash('Authenticator app enabled. At sign-in you can now use either an app code or an emailed code.');
    } else {
        keep_field_errors(['totp_code' => 'That code did not match or has expired. Codes change every 30 seconds: enter the code the app shows right now, then choose "Confirm and enable".']);
    }
    redirect('account.php#totp');
}

/* ---- TOTP: disable ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_disable'])) {
    $pwErr = account_reauth_error($user, $_POST['password'] ?? '', 'Password incorrect — authenticator app was not disabled.');
    if ($pwErr === null) {
        db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')->execute([$user['id']]);
        audit($user['id'], 'totp.disable', 'user', $user['id']);
        // Tell the account owner, as adding one does: if it was not them, they can react.
        send_app_mail($user['email'], 'An authenticator app was removed from your account',
            "Hello {$user['name']},\n\nThe authenticator app was just turned off for your " . cfg('name') . " account. Sign-in codes now arrive by email only.\n\n"
            . "If this was you, no action is needed. If it was NOT you, change your password under My Account right away (or use \"Forgot your password?\" on the sign-in page), set up the authenticator app again, and tell your administrator.\n");
        flash('Authenticator app disabled. Sign-in codes will arrive by email only.');
    } else {
        keep_field_errors(['td_pw' => $pwErr]);
    }
    redirect('account.php#totp');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $row = db()->prepare('SELECT cal_token FROM users WHERE id = ?');
    $row->execute([$user['id']]);
    $hadCalFeed = (string)$row->fetchColumn() !== '';
    $pkq = db()->prepare('SELECT COUNT(*) FROM passkeys WHERE user_id = ?');
    $pkq->execute([$user['id']]);
    $passkeyCount = (int)$pkq->fetchColumn();
    // Every problem is named on its own field at once (passwords are never refilled).
    $pwErrs = [];
    $pwErr = account_reauth_error($user, $_POST['current_password'] ?? '', 'Your current password was not correct.');
    if ($pwErr !== null) $pwErrs['current_password'] = $pwErr;
    if (strlen($new) < 10) $pwErrs['new_password'] = 'The new password must be at least 10 characters.';
    elseif ($new !== $confirm) $pwErrs['confirm_password'] = 'The confirmation does not match the new password. Type the same new password in both boxes.';
    // Removing passkeys cannot be undone, so it is confirmed first (3.3.4).
    if ($passkeyCount > 0 && empty($_POST['ack_passkeys'])) {
        $pwErrs['ack_passkeys'] = 'Tick the box to confirm that your ' . $passkeyCount . ' passkey' . ($passkeyCount === 1 ? '' : 's')
            . ' will be removed, or keep your current password.';
    }
    if ($pwErrs) {
        keep_field_errors($pwErrs);
        redirect('account.php#pw-heading');
    } else {
        db()->beginTransaction();
        try {
        // The calendar-feed address goes too: whoever had the session could
        // have copied it, and it would keep serving the schedule.
        db()->prepare('UPDATE users SET password_hash = ?, cal_token = NULL WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        // Changing the password signs the mobile app out everywhere and revokes
        // enrolled passkeys, matching the reset-password flow (a password change
        // is often a response to suspected compromise).
        db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$user['id']]);
        db()->prepare('DELETE FROM passkeys WHERE user_id = ?')->execute([$user['id']]);
        db()->prepare('UPDATE login_otps SET consumed = 1 WHERE user_id = ?')->execute([$user['id']]);
        // A reset link emailed before this change must not be able to undo it.
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
        db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        audit($user['id'], 'password.change', 'user', (int)$user['id'], 'browser and app sessions revoked'
            . ($passkeyCount ? ', ' . $passkeyCount . ' passkey(s) removed' : '')
            . ($hadCalFeed ? ', calendar feed address turned off' : ''));
        // Tell the account owner what changed: if it was not them, every device
        // was just signed out and they would otherwise get no explanation.
        $mailFx = ['you were signed out on every device, including the mobile app'];
        if ($passkeyCount) $mailFx[] = 'your ' . $passkeyCount . ' passkey' . ($passkeyCount === 1 ? ' was' : 's were') . ' removed';
        if ($hadCalFeed) $mailFx[] = 'your calendar subscription address was turned off';
        $mailFxLast = array_pop($mailFx);
        send_app_mail($user['email'], 'Your password was changed',
            "Hello {$user['name']},\n\nThe password for your " . cfg('name') . ' account was changed on ' . date('M j, Y \a\t g:i A') . ".\n"
            . ucfirst(($mailFx ? implode(', ', $mailFx) . ' and ' : '') . $mailFxLast) . ".\n\n"
            . "If this was you, no action is needed. If it was NOT you, use \"Forgot your password?\" on the sign-in page ("
            . cfg('base_url') . "/forgot.php) to set a new password right away, and tell your administrator.\n");
        flash('Your password has been changed. Sign in again on all devices, including the mobile app.'
            . ($passkeyCount ? ' Your ' . $passkeyCount . ' passkey' . ($passkeyCount === 1 ? ' was' : 's were') . ' removed: add them again under My Account, and remove the old ones from your devices\' saved passwords.' : '')
            . ($hadCalFeed ? ' Your calendar subscription address was turned off as a precaution: create a new one under My Account and subscribe your calendar to it again.' : ''));
        redirect('login.php');
    }
    redirect('account.php');
}

/* Admin: check the vendor manifest for a newer version */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_updates']) && can('admin')) {
    $url = ($GLOBALS['CONFIG']['app']['update_url'] ?? null) ?: 'https://cms.example.org/updates.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $m = is_string($resp) ? json_decode($resp, true) : null;
    if (is_array($m) && preg_match('/^\d+(\.\d+){1,3}$/', (string)($m['latest'] ?? ''))) {
        set_setting('update_latest', (string)$m['latest']);
        set_setting('update_notes', mb_substr((string)($m['notes'] ?? ''), 0, 500));
        set_setting('update_checked', date('Y-m-d H:i'));
        flash(version_compare((string)$m['latest'], APP_VERSION, '>')
            ? 'A newer version is available: v' . $m['latest'] . '.'
            : 'You are up to date (v' . APP_VERSION . ').');
    } else {
        flash('The update server could not be reached. Try again later.', 'error');
    }
    redirect('account.php#housekeeping');
}

/* Admin: record-retention setting */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retention']) && can('admin')) {
    // Only the offered periods (or the period already stored, so an older
    // setting can be saved again unchanged); anything else is refused, never
    // stored as something other than what the audit log and message say.
    $rawRy = $_POST['retention_years'] ?? '';
    $ry = is_string($rawRy) && preg_match('/^\d{1,2}$/', $rawRy) ? (int)$rawRy : -1;
    $curRy = (int)(setting('retention_years') ?? '0');
    if (!in_array($ry, [0, 5, 6, 7, 10], true) && !($ry === $curRy && $ry >= 0 && $ry <= 25)) {
        flash('Record retention was not changed: choose Off, 5, 6, 7 or 10 years.', 'error');
        redirect('account.php#housekeeping');
    }
    set_setting('retention_years', (string)$ry);
    audit($user['id'], 'retention.set', 'settings', null, $ry . ' years');
    flash($ry > 0 ? "Retention set: clients closed for {$ry}+ years are archived nightly." : 'Retention automation is off.');
    redirect('account.php#housekeeping');
}

/* Create or replace my calendar-feed address */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cal_regen'])) {
    // The feed serves only staff with the Scheduling permission (calfeed.php
    // answers "not found" to anyone else), so an address is never made for others.
    if (!can('schedule')) {
        flash('Your account does not have the Scheduling permission, so a calendar address would show nothing. Nothing was changed. Ask an administrator if you need it.', 'error');
        redirect('account.php#calfeed');
    }
    // The address is a lasting key to the schedule (client names and meeting
    // links; in-person locations are left out of the feed), so the account
    // password is re-checked first, as for adding an authenticator app or a passkey.
    $calPw = $_POST['password'] ?? '';
    $pwErr = account_reauth_error($user, $calPw, $calPw === ''
        ? 'Enter your account password to create or replace the calendar address.'
        : 'Password incorrect — the calendar address was not created or replaced.');
    if ($pwErr !== null) {
        // On the field (the password is never refilled, so it is typed again).
        keep_field_errors(['cal_pw' => $pwErr]);
        redirect('account.php#calfeed');
    }
    $hadQ = db()->prepare('SELECT cal_token FROM users WHERE id = ?');
    $hadQ->execute([$user['id']]);
    $replaced = (string)$hadQ->fetchColumn() !== '';
    db()->prepare('UPDATE users SET cal_token = ? WHERE id = ?')
        ->execute([bin2hex(random_bytes(24)), $user['id']]);
    audit($user['id'], 'calfeed.regen', 'user', (int)$user['id'], $replaced ? 'address replaced' : 'address created');
    // Tell the account owner, as adding a sign-in method does: if it was not them, they can react.
    send_app_mail($user['email'], 'A calendar subscription address was ' . ($replaced ? 'replaced' : 'created') . ' for your account',
        "Hello {$user['name']},\n\nA private calendar subscription address was just " . ($replaced ? 'replaced' : 'created') . ' for your ' . cfg('name') . " account. "
        . "Any calendar app subscribed to it shows your scheduled sessions, including client first names and meeting links (in-person locations are left out)."
        . ($replaced ? ' The previous address no longer works.' : '') . "\n\n"
        . "If this was you, no action is needed. If it was NOT you, choose \"Replace the address\" under My Account right away (the address that was made then stops working), change your password, and tell your administrator.\n");
    flash('Your calendar address is ready — copy it into your calendar app as a subscription.');
    redirect('account.php#calfeed');
}

/* Remove one of my passkeys */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_passkey'])) {
    $pkId = (int)($_POST['passkey_id'] ?? 0);
    // Its name is read first, so the trail says which passkey went.
    $pkq = db()->prepare('SELECT name FROM passkeys WHERE id = ? AND user_id = ?');
    $pkq->execute([$pkId, $user['id']]);
    $pkName = (string)$pkq->fetchColumn();
    $pkDel = db()->prepare('DELETE FROM passkeys WHERE id = ? AND user_id = ?');
    $pkDel->execute([$pkId, $user['id']]);
    if ($pkDel->rowCount() !== 1) {
        // A second click, or another open tab: nothing was removed, so nothing is logged.
        flash('That passkey had already been removed, so nothing changed.', 'error');
    } else {
        audit($user['id'], 'passkey.remove', 'user', (int)$user['id'], 'passkey #' . $pkId . ' (' . $pkName . ')');
        flash('Passkey "' . $pkName . '" removed. You can still sign in with your password and code.');
    }
    redirect('account.php#passkeys');
}

// Admin: set weekly available hours
/* Admin: assign a user to a permission group */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_group']) && can('admin')) {
    $gid = (int)($_POST['group_id'] ?? 0) ?: null;
    $uid = (int)($_POST['uid'] ?? 0);
    try {
        change_user_access($uid, (int)$user['id'], ['group_id' => $gid], function (PDO $db) use ($gid, $uid): void {
            $db->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([$gid, $uid]);
        });
    } catch (DomainException $e) { flash($e->getMessage(), 'error'); redirect('account.php#team-h'); }
    audit($user['id'], 'user.group', 'user', (int)($_POST['uid'] ?? 0), $gid ? 'group #' . $gid : 'legacy role behavior');
    flash('Group updated — takes effect on that user\'s next page load.');
    redirect('account.php#team-h');
}

/* Admin: create a permission group */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group']) && can('admin')) {
    $gname = trim($_POST['group_name'] ?? '');
    $gdesc = trim($_POST['group_description'] ?? '');
    $gperms = array_values(array_intersect((array)($_POST['group_perms'] ?? []), array_keys(PERMISSION_KEYS)));
    $gErrs = [];
    if ($gname === '') $gErrs['group_name'] = 'Enter a name for the group.';
    elseif (mb_strlen($gname) > 80) $gErrs['group_name'] = 'The group name is too long (80 characters maximum).';
    if (mb_strlen($gdesc) > 255) $gErrs['group_description'] = 'The description is too long (255 characters maximum).';
    if (!$gperms) $gErrs['group_perms'] = 'Tick at least one permission.';
    if ($gErrs) {
        keep_input();   // the ticked permissions too (old_in_list)
        keep_field_errors($gErrs);
    } else {
        try {
            db()->prepare('INSERT INTO `groups` (name, description, perms, own_caseload_only) VALUES (?,?,?,?)')
                ->execute([$gname, $gdesc ?: null, implode(';', $gperms), !empty($_POST['group_scoped']) ? 1 : 0]);
            audit($user['id'], 'group.add', 'group', (int)db()->lastInsertId(), $gname);
            flash('Group created.');
        } catch (PDOException $e) {
            keep_input();   // the ticked permissions too (old_in_list)
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                keep_field_errors(['group_name' => 'A group with that name already exists. Choose another name.']);
            } else {
                error_log('account.php add_group failed: ' . get_class($e) . ' ' . $e->getCode());
                flash('The group could not be created. Nothing was saved; what you typed is kept. Please try again.', 'error');
            }
        }
    }
    redirect('account.php#create-group-h');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_hours']) && can('admin')) {
    $hoursRaw = trim(is_string($_POST['weekly_hours'] ?? null) ? $_POST['weekly_hours'] : '');
    $hoursUid = (int)($_POST['uid'] ?? 0);
    if (!is_numeric($hoursRaw) || (float)$hoursRaw < 0 || (float)$hoursRaw > 80) {
        // Never save a cleared or out-of-range box as 0 or 80 without saying so;
        // the message is on that person's row, with what was typed refilled.
        keep_input();
        keep_field_errors(['wh-' . $hoursUid => 'Enter weekly available hours as a number from 0 to 80. Nothing was changed.']);
        redirect('account.php#team-h');
    }
    $weeklyHours = (float)$hoursRaw;
    db()->prepare('UPDATE users SET weekly_hours = ? WHERE id = ?')->execute([$weeklyHours, $hoursUid]);
    audit($user['id'], 'user.hours', 'user', $hoursUid, $weeklyHours . ' hrs/week');
    flash('Weekly hours updated.');
    redirect('account.php#team-h');
}

// Admin: add a new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user']) && can('admin')) {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw = $_POST['password'] ?? '';
    $role = in_array($_POST['role'] ?? '', ['admin','instructor'], true) ? $_POST['role'] : 'instructor';
    // A new account is placed in a permission group, so it gets exactly what
    // that group allows (the seeded Instructors group: their own caseload).
    // "Legacy (by role)" stays available only when chosen on purpose: a legacy
    // staff account opens every client and billing.
    $rawGroup = is_string($_POST['group_id'] ?? null) ? $_POST['group_id'] : '';
    $newGroup = null;
    if (ctype_digit($rawGroup) && (int)$rawGroup > 0) {
        $gq = db()->prepare('SELECT id, perms FROM `groups` WHERE id = ?');
        $gq->execute([(int)$rawGroup]);
        $newGroup = $gq->fetch() ?: null;
    }
    // Each field is checked on its own and its message tied to it.
    $uErrs = [];
    if ($rawGroup !== '0' && !$newGroup) $uErrs['nu_group'] = 'Choose the permission group this person belongs to.';
    // With a group, the stored role follows it, so clearing the group later
    // never hands the account more than the group allowed.
    if ($newGroup) $role = in_array('admin', array_map('trim', explode(';', (string)$newGroup['perms'])), true) ? 'admin' : 'instructor';
    if ($name === '') $uErrs['nu_name'] = 'Enter the person\'s full name.';
    elseif (mb_strlen($name) > 120) $uErrs['nu_name'] = 'The full name is too long (120 characters maximum).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $uErrs['nu_email'] = 'Enter a valid email address, for example name@agency.org.';
    // 183 so the sign-in limiter keys ('verify:' plus the address) fit login_attempts.email.
    elseif (mb_strlen($email) > 183) $uErrs['nu_email'] = 'The email address is too long (183 characters maximum).';
    if (!is_string($pw) || strlen($pw) < 10) $uErrs['nu_password'] = 'Enter a temporary password of at least 10 characters.';
    if ($uErrs) {
        keep_input();
        keep_field_errors($uErrs);
    } else {
        try {
            db()->prepare('INSERT INTO users (name, email, password_hash, role, group_id) VALUES (?,?,?,?,?)')
                ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $newGroup ? (int)$newGroup['id'] : null]);
            audit($user['id'], 'user.create', 'user', (int)db()->lastInsertId(), "{$name} <{$email}> role={$role} group="
                . ($newGroup ? (int)$newGroup['id'] : 'legacy'));
            flash("User {$name} added.");
        } catch (PDOException $e) {
            keep_input();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                keep_field_errors(['nu_email' => 'A team member with that email address already exists.']);
            } else {
                error_log('account.php add_user failed: ' . get_class($e) . ' ' . $e->getCode());
                flash('The user could not be added. Nothing was saved; the name and email you typed are kept. Please try again.', 'error');
            }
        }
    }
    redirect('account.php#add-user-h');
}

page_header('My Account', $user, 'account');
?>
<h1>My Account</h1>
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
<?php if (entra_cfg()): ?>
<section class="card" aria-labelledby="microsoft-link-h">
  <h2 id="microsoft-link-h">Microsoft sign-in</h2>
  <p>Link your Microsoft identity while signed in here. The Microsoft email must match this account. Existing links cannot be replaced through this form.</p>
  <form method="post" action="sso.php?start=1"><?= csrf_field() ?>
    <button type="submit" name="link_account" value="1" class="btn btn-secondary">Link Microsoft account</button>
  </form>
</section>
<?php endif; ?>
<div class="grid grid-2">
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

  <?php if (can('admin')): ?>
  <section class="card" aria-labelledby="users-heading">
    <h2 id="users-heading">Team</h2>
    <p><a class="btn btn-secondary" href="audit.php">View audit log</a></p>
    <h3 id="add-user-h">Add a team member</h3>
    <?php $nuRefill = old_in('add_user') !== ''; // refill only after this form was sent
    $allGroups = db()->query('SELECT * FROM `groups` ORDER BY name')->fetchAll();
    // Preselected: what was chosen before an error, else the seeded own-caseload Instructors group.
    $nuGroup = $nuRefill ? old_in('group_id') : (string)(array_column($allGroups, 'id', 'name')['Instructors'] ?? ''); ?>
    <form method="post" action="account.php" class="stacked" novalidate>
      <?= csrf_field() ?>
      <?= form_error_summary(['nu_name' => 'Full name', 'nu_email' => 'Email address', 'nu_password' => 'Temporary password', 'nu_group' => 'Permission group']) ?>
      <div class="field">
        <label for="nu_name">Full name *</label>
        <input type="text" id="nu_name" name="name" required value="<?= h($nuRefill ? old_in('name') : '') ?>" <?= field_aria('nu_name') ?>>
        <?= field_error('nu_name') ?>
      </div>
      <div class="field">
        <label for="nu_email">Email address *</label>
        <input type="email" id="nu_email" name="email" required value="<?= h($nuRefill ? old_in('email') : '') ?>" <?= field_aria('nu_email') ?>>
        <?= field_error('nu_email') ?>
      </div>
      <div class="field">
        <label for="nu_password">Temporary password * <span class="hint">At least 10 characters — ask them to change it after first sign-in</span></label>
        <?php if ($nuRefill): ?><span class="hint" id="nu_password-again">For safety a typed password is never kept after an error: type it again.</span><?php endif; ?>
        <input type="text" id="nu_password" name="password" required minlength="10" autocomplete="off" <?= field_aria('nu_password', $nuRefill ? 'nu_password-again' : '') ?>>
        <?= field_error('nu_password') ?>
      </div>
      <div class="field">
        <label for="nu_group">Permission group *</label>
        <span class="hint" id="nu_group-hint">Decides which areas this person can open. "Own caseload only" groups see just the clients assigned to them.</span>
        <select id="nu_group" name="group_id" required <?= field_aria('nu_group', 'nu_group-hint') ?>>
          <option value="">— Choose a group —</option>
          <?php foreach ($allGroups as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $nuGroup === (string)(int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?><?= $g['own_caseload_only'] ? ' (own caseload only)' : '' ?></option>
          <?php endforeach; ?>
          <option value="0" <?= $nuGroup === '0' ? 'selected' : '' ?>>Legacy (by role below)</option>
        </select>
        <?= field_error('nu_group') ?>
      </div>
      <div class="field">
        <label for="nu_role">Legacy role</label>
        <span class="hint" id="nu_role-hint">Used only with "Legacy (by role below)": staff then see every client and billing, admins everything. Both can provide any service, including counseling to Pre-ETS students.</span>
        <select id="nu_role" name="role" aria-describedby="nu_role-hint">
          <option value="instructor">Staff (instructor/counselor)</option>
          <option value="admin" <?= $nuRefill && old_in('role') === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
      </div>
      <button type="submit" name="add_user" value="1" class="btn btn-primary">Add user</button>
    </form>
    <h3 id="team-h">Team members</h3>
    <p class="muted">Quick group and hours changes live here — for everything else (edit details, password resets, two-factor (2FA) sign-in recovery, caseload transfer, deactivate or delete), open <a href="users.php">User management</a>.</p>
    <?php $teamRows = db()->query('SELECT id, name, email, role, group_id, weekly_hours FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
    $hoursRefill = old_in('set_hours') !== '' ? old_in('uid') : '';
    if ($teamRows) echo form_error_summary(array_column(array_map(fn($r) => ['wh-' . (int)$r['id'], 'Weekly available hours for ' . $r['name']], $teamRows), 1, 0)); ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Team members">
      <table>
        <caption class="visually-hidden">Team members</caption>
        <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Permission group</th><th scope="col">Available hours per week</th></tr></thead>
        <tbody>
        <?php foreach ($teamRows as $u): ?>
          <tr>
            <th scope="row"><?= h($u['name']) ?></th><td><?= h($u['email']) ?></td><td><?= h($u['role']) ?></td>
            <td>
              <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                <label class="visually-hidden" for="gr-<?= (int)$u['id'] ?>">Permission group for <?= h($u['name']) ?></label>
                <select id="gr-<?= (int)$u['id'] ?>" name="group_id">
                  <option value="0">— Legacy (by role) —</option>
                  <?php foreach ($allGroups as $g): ?>
                  <option value="<?= (int)$g['id'] ?>" <?= (int)($u['group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" name="set_group" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> permission group for <?= h($u['name']) ?></span></button>
              </form>
            </td>
            <td>
              <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                <label class="visually-hidden" for="wh-<?= (int)$u['id'] ?>">Weekly available hours for <?= h($u['name']) ?></label>
                <input type="number" id="wh-<?= (int)$u['id'] ?>" name="weekly_hours" step="0.5" min="0" max="80"
                       value="<?= h($hoursRefill === (string)(int)$u['id'] ? old_in('weekly_hours') : rtrim(rtrim(number_format((float)$u['weekly_hours'], 2), '0'), '.')) ?>" size="4"
                       <?= field_aria('wh-' . (int)$u['id']) ?>>
                <button type="submit" name="set_hours" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> weekly hours for <?= h($u['name']) ?></span></button>
                <?= field_error('wh-' . (int)$u['id']) ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Available hours feed the <a href="capacity.php">capacity planning</a> view.</p>

    <h2 id="groups">Permission groups</h2>
    <p class="muted">A group decides which areas its members can open. "Own caseload only" limits client records to those assigned to the member. Users on "Legacy (by role)" behave as before: admins see everything, instructors see everything except administration.</p>
    <div class="table-scroll" tabindex="0" role="region" aria-labelledby="groups">
      <table>
        <caption class="visually-hidden">Permission groups</caption>
        <thead><tr><th scope="col">Group</th><th scope="col">Permissions</th><th scope="col">Caseload</th></tr></thead>
        <tbody>
        <?php foreach ($allGroups as $g): ?>
          <tr>
            <th scope="row"><?= h($g['name']) ?><?= $g['description'] ? '<br><span class="muted">' . h($g['description']) . '</span>' : '' ?></th>
            <td><?= h(implode(' · ', array_map(fn($p) => PERMISSION_KEYS[$p] ?? $p, array_filter(explode(';', $g['perms']))))) ?></td>
            <td><?= $g['own_caseload_only'] ? 'Own caseload only' : 'All clients' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 id="create-group-h">Create a group</h3>
    <?php // After an error, the ticked choices come back as they were (3.3.7).
    $groupRefill = old_in('add_group') !== '';
    $keptPerms = $groupRefill ? old_in_list('group_perms') : [];
    $firstPerm = array_key_first(PERMISSION_KEYS); ?>
    <form method="post" action="account.php" class="stacked" novalidate>
      <?= csrf_field() ?>
      <?= form_error_summary(['group_name' => 'Group name', 'group_description' => 'Description', 'group_perms' => 'Permissions'], ['group_perms' => 'gp-' . $firstPerm]) ?>
      <div class="field"><label for="group_name">Group name *</label>
        <input type="text" id="group_name" name="group_name" required value="<?= h($groupRefill ? old_in('group_name') : '') ?>" <?= field_aria('group_name') ?>>
        <?= field_error('group_name') ?></div>
      <div class="field"><label for="group_description">Description</label>
        <input type="text" id="group_description" name="group_description" value="<?= h($groupRefill ? old_in('group_description') : '') ?>" <?= field_aria('group_description') ?>>
        <?= field_error('group_description') ?></div>
      <fieldset <?= has_field_error('group_perms') ? 'aria-describedby="err-group_perms"' : '' ?>>
        <legend>Permissions *</legend>
        <?= field_error('group_perms') ?>
        <?php foreach (PERMISSION_KEYS as $key => $label): ?>
        <div class="field"><label><input type="checkbox" id="gp-<?= h($key) ?>" name="group_perms[]" value="<?= h($key) ?>" <?= in_array($key, $keptPerms, true) ? 'checked' : '' ?>> <?= h($label) ?></label></div>
        <?php endforeach; ?>
      </fieldset>
      <div class="field"><label><input type="checkbox" name="group_scoped" value="1" <?= $groupRefill && old_in('group_scoped') === '1' ? 'checked' : '' ?>> Own caseload only (members see just their assigned clients)</label></div>
      <button type="submit" name="add_group" value="1" class="btn btn-primary">Create group</button>
    </form>

    <h2 id="federation">Sign-in &amp; federation</h2>
    <p class="muted">Let the team sign in with their Microsoft work accounts instead of a password and emailed code.</p>
    <p><a class="btn btn-secondary" href="federation.php">Set up Microsoft Entra ID federation</a></p>

    <h2 id="housekeeping">Housekeeping</h2>
    <div class="page-actions">
      <a class="btn btn-secondary" href="users.php">User management (edit / recover / delete)</a>
      <a class="btn btn-secondary" href="trash.php">Trash (restore deleted items)</a>
      <a class="btn btn-secondary" href="client_merge.php">Merge duplicate clients</a>
      <a class="btn btn-secondary" href="outcomes.php">Annual outcome report</a>
      <a class="btn btn-secondary" href="email_templates.php">Email templates</a>
      <a class="btn btn-secondary" href="referral_settings.php">Referral form settings</a>
      <a class="btn btn-secondary" href="clients.php?archived=1">Archived clients</a>
      <a class="btn btn-secondary" href="ai.php">AI assistant settings</a>
    </div>
    <h3 id="retention-h">Record retention</h3>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <label for="retention_years">Record retention: auto-archive clients closed for</label>
      <select id="retention_years" name="retention_years">
        <?php $curRet = (int)(setting('retention_years') ?? '0');
        $retOpts = [0 => 'Off', 5 => '5 years', 6 => '6 years', 7 => '7 years', 10 => '10 years'];
        // A period stored by an earlier release is shown as it is, not as "Off".
        if (!isset($retOpts[$curRet])) { $retOpts[$curRet] = $curRet . ($curRet === 1 ? ' year' : ' years'); ksort($retOpts); }
        foreach ($retOpts as $ry => $rl): ?>
        <option value="<?= $ry ?>" <?= $curRet === $ry ? 'selected' : '' ?>><?= $rl ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="save_retention" value="1" class="btn btn-secondary btn-sm">Save</button>
    </form>
    <p class="hint">Archived records are hidden from daily lists but kept intact until you export and purge them from the archived-clients page.</p>
    <h3 id="updates-h">Software updates</h3>
    <?php
    $updLatest = setting('update_latest');
    $updChecked = setting('update_checked');
    $updNewer = $updLatest !== null && version_compare($updLatest, APP_VERSION, '>');
    ?>
    <p>
      This system is <strong>v<?= h(APP_VERSION) ?></strong>.
      <?php if ($updNewer): ?><span class="badge badge-pending">Update available: v<?= h($updLatest) ?></span>
        <?php if (setting('update_notes')): ?><br><span class="muted"><?= h(setting('update_notes')) ?></span><?php endif; ?>
      <?php elseif ($updChecked): ?><span class="muted">Up to date (last checked <?= h($updChecked) ?>).</span><?php endif; ?>
    </p>
    <form method="post" action="account.php" class="inline-form"><?= csrf_field() ?>
      <button type="submit" name="check_updates" value="1" class="btn btn-secondary btn-sm">Check for updates</button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php page_footer();
