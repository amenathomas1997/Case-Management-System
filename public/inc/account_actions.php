<?php
declare(strict_types=1);
/*
 * The personal sign-in settings shared by My Account (account.php) and the app
 * (inc/api_account.php): authenticator app, password, calendar address and
 * passkeys. Each change makes the same database writes, audit entry and
 * notification email whichever one asks for it.
 *
 * $user needs id, name and email. $reauth re-checks the account password:
 * fn($password, string $wrong): ?string returns null when it matches, else the
 * message to show; it may end the request instead (too many wrong passwords).
 *
 * Each returns ['ok' => true, 'message' => …] on success, or ['ok' => false]
 * with 'errors' (field => message: password, code, current_password,
 * new_password, confirm_password, ack_passkeys) or 'error' (no single field).
 */

/** The calendar subscription address for a feed token. */
function account_cal_feed_url(string $token): string {
    return cfg('base_url') . '/calfeed.php?u=' . $token;
}

/** Turn on the authenticator app whose setup key is $secret ('' when the setup has ended). */
function account_totp_confirm(array $user, string $secret, $password, $code, callable $reauth): array {
    // Adding a sign-in method is sensitive: re-confirm the account password
    // first, as passkey enrolment and disabling the authenticator already do.
    $pwErr = $reauth($password, 'Password incorrect — the authenticator app was not enabled.');
    if ($secret === '') {
        return ['ok' => false, 'error' => 'The authenticator setup had ended, so nothing was changed. Choose "Set up authenticator app" to start again.'];
    }
    if ($pwErr !== null) return ['ok' => false, 'errors' => ['password' => $pwErr]];
    if (!totp_verify($secret, is_string($code) ? $code : '')) {
        return ['ok' => false, 'errors' => ['code' => 'That code did not match or has expired. Codes change every 30 seconds: enter the code the app shows right now, then choose "Confirm and enable".']];
    }
    // Stored encrypted with the private key file (4.28.0): a database backup alone holds no seed.
    db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')->execute([totp_secret_store($secret), $user['id']]);
    audit((int)$user['id'], 'totp.enable', 'user', (int)$user['id']);
    send_app_mail($user['email'], 'An authenticator app was added to your account',
        "Hello {$user['name']},\n\nAn authenticator app was just set up for your " . cfg('name') . " account. From now on, signing in asks for a code from that app (or a passkey); sign-in codes are no longer emailed.\n\n"
        . "If this was you, no action is needed. If it was NOT you, disable it under My Account right away, change your password, and tell your administrator.\n");
    return ['ok' => true, 'message' => 'Authenticator app enabled. From now on, signing in asks for a code from the app (or a passkey) instead of an emailed code. If you lose the app, an administrator can reset it.'];
}

/** Turn off the authenticator app. */
function account_totp_disable(array $user, $password, callable $reauth): array {
    $pwErr = $reauth($password, 'Password incorrect — authenticator app was not disabled.');
    if ($pwErr !== null) return ['ok' => false, 'errors' => ['password' => $pwErr]];
    db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')->execute([$user['id']]);
    audit((int)$user['id'], 'totp.disable', 'user', (int)$user['id']);
    // Tell the account owner, as adding one does: if it was not them, they can react.
    send_app_mail($user['email'], 'An authenticator app was removed from your account',
        "Hello {$user['name']},\n\nThe authenticator app was just turned off for your " . cfg('name') . " account. Sign-in codes now arrive by email only.\n\n"
        . "If this was you, no action is needed. If it was NOT you, change your password under My Account right away (or use \"Forgot your password?\" on the sign-in page), set up the authenticator app again, and tell your administrator.\n");
    return ['ok' => true, 'message' => 'Authenticator app disabled. Sign-in codes will arrive by email only.'];
}

/**
 * Change the password. This also signs the app out everywhere, removes the
 * passkeys ($ackPasskeys must confirm that when there are any), turns off the
 * calendar address, cancels open sign-in codes and reset links.
 */
function account_change_password(array $user, $current, $new, $confirm, bool $ackPasskeys, callable $reauth): array {
    $new = is_string($new) ? $new : '';
    $confirm = is_string($confirm) ? $confirm : '';
    $row = db()->prepare('SELECT cal_token FROM users WHERE id = ?');
    $row->execute([$user['id']]);
    $hadCalFeed = (string)$row->fetchColumn() !== '';
    $pkq = db()->prepare('SELECT COUNT(*) FROM passkeys WHERE user_id = ?');
    $pkq->execute([$user['id']]);
    $passkeyCount = (int)$pkq->fetchColumn();
    // Every problem is named on its own field at once (passwords are never refilled).
    $pwErrs = [];
    $pwErr = $reauth($current, 'Your current password was not correct.');
    if ($pwErr !== null) $pwErrs['current_password'] = $pwErr;
    if (strlen($new) < 10) $pwErrs['new_password'] = 'The new password must be at least 10 characters.';
    elseif ($new !== $confirm) $pwErrs['confirm_password'] = 'The confirmation does not match the new password. Type the same new password in both boxes.';
    // Removing passkeys cannot be undone, so it is confirmed first (3.3.4).
    if ($passkeyCount > 0 && !$ackPasskeys) {
        $pwErrs['ack_passkeys'] = 'Tick the box to confirm that your ' . $passkeyCount . ' passkey' . ($passkeyCount === 1 ? '' : 's')
            . ' will be removed, or keep your current password.';
    }
    if ($pwErrs) return ['ok' => false, 'errors' => $pwErrs];
    db()->beginTransaction();
    try {
        // The calendar-feed address goes too: whoever had the session could
        // have copied it, and it would keep serving the schedule.
        db()->prepare('UPDATE users SET password_hash = ?, cal_token = NULL WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        // Changing the password signs the mobile app out everywhere and revokes
        // enrolled passkeys, matching the reset-password flow (a password change
        // is often a response to suspected compromise).
        // A phone reported lost keeps its row until it confirms the wipe (4.27.0).
        db()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND wipe_requested_at IS NULL')->execute([$user['id']]);
        db()->prepare('DELETE FROM passkeys WHERE user_id = ?')->execute([$user['id']]);
        db()->prepare('UPDATE login_otps SET consumed = 1 WHERE user_id = ?')->execute([$user['id']]);
        // A reset link emailed before this change must not be able to undo it.
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    audit((int)$user['id'], 'password.change', 'user', (int)$user['id'], 'browser and app sessions revoked'
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
    return ['ok' => true, 'passkeys_removed' => $passkeyCount, 'cal_feed_turned_off' => $hadCalFeed,
        'message' => 'Your password has been changed. Sign in again on all devices, including the mobile app.'
        . ($passkeyCount ? ' Your ' . $passkeyCount . ' passkey' . ($passkeyCount === 1 ? ' was' : 's were') . ' removed: add them again under My Account, and remove the old ones from your devices\' saved passwords.' : '')
        . ($hadCalFeed ? ' Your calendar subscription address was turned off as a precaution: create a new one under My Account and subscribe your calendar to it again.' : '')];
}

/** Create, or replace, the calendar subscription address. Needs the Scheduling permission. */
function account_cal_regen(array $user, $password, callable $reauth): array {
    // The feed serves only staff with the Scheduling permission (calfeed.php
    // answers "not found" to anyone else), so an address is never made for others.
    if (!can('schedule')) {
        return ['ok' => false, 'forbidden' => true, 'error' => 'Your account does not have the Scheduling permission, so a calendar address would show nothing. Nothing was changed. Ask an administrator if you need it.'];
    }
    // The address is a lasting key to the schedule (client names and meeting
    // links; in-person locations are left out of the feed), so the account
    // password is re-checked first, as for adding an authenticator app or a passkey.
    $pwErr = $reauth($password, $password === ''
        ? 'Enter your account password to create or replace the calendar address.'
        : 'Password incorrect — the calendar address was not created or replaced.');
    if ($pwErr !== null) return ['ok' => false, 'errors' => ['password' => $pwErr]];
    $hadQ = db()->prepare('SELECT cal_token FROM users WHERE id = ?');
    $hadQ->execute([$user['id']]);
    $replaced = (string)$hadQ->fetchColumn() !== '';
    $token = bin2hex(random_bytes(24));
    db()->prepare('UPDATE users SET cal_token = ? WHERE id = ?')->execute([$token, $user['id']]);
    audit((int)$user['id'], 'calfeed.regen', 'user', (int)$user['id'], $replaced ? 'address replaced' : 'address created');
    // Tell the account owner, as adding a sign-in method does: if it was not them, they can react.
    send_app_mail($user['email'], 'A calendar subscription address was ' . ($replaced ? 'replaced' : 'created') . ' for your account',
        "Hello {$user['name']},\n\nA private calendar subscription address was just " . ($replaced ? 'replaced' : 'created') . ' for your ' . cfg('name') . " account. "
        . "Any calendar app subscribed to it shows your scheduled sessions, including client first names and meeting links (in-person locations are left out)."
        . ($replaced ? ' The previous address no longer works.' : '') . "\n\n"
        . "If this was you, no action is needed. If it was NOT you, choose \"Replace the address\" under My Account right away (the address that was made then stops working), change your password, and tell your administrator.\n");
    return ['ok' => true, 'replaced' => $replaced, 'address' => account_cal_feed_url($token),
        'message' => 'Your calendar address is ready — copy it into your calendar app as a subscription.'];
}

/** Remove one of the user's own passkeys. */
function account_remove_passkey(array $user, int $pkId): array {
    // Its name is read first, so the trail says which passkey went.
    $pkq = db()->prepare('SELECT name FROM passkeys WHERE id = ? AND user_id = ?');
    $pkq->execute([$pkId, $user['id']]);
    $pkName = (string)$pkq->fetchColumn();
    $pkDel = db()->prepare('DELETE FROM passkeys WHERE id = ? AND user_id = ?');
    $pkDel->execute([$pkId, $user['id']]);
    if ($pkDel->rowCount() !== 1) {
        // A second click, or another open tab: nothing was removed, so nothing is logged.
        return ['ok' => false, 'error' => 'That passkey had already been removed, so nothing changed.'];
    }
    // A passkey is often removed because its device was lost: any browser
    // session it opened elsewhere ends too (4.27.0). This one carries on.
    $ended = account_end_other_sessions((int)$user['id']);
    audit((int)$user['id'], 'passkey.remove', 'user', (int)$user['id'], 'passkey #' . $pkId . ' (' . $pkName . ')' . ($ended ? '; other browser sessions ended' : ''));
    return ['ok' => true, 'name' => $pkName,
        'message' => 'Passkey "' . $pkName . '" removed.' . ($ended ? ' Any other browser signed in to your account on the website was signed out.' : '') . ' You can still sign in with your password and code.'];
}

/**
 * End every other browser session of an account (4.27.0): after removing a
 * passkey, and, called by users.php, after an administrator resets its two-step
 * verification or changes its email. The session making the change carries on
 * when it is that account's own. False before the 4.27.0 migration adds
 * users.session_epoch (nothing is ended then).
 */
function account_end_other_sessions(int $userId): bool {
    return user_session_epoch_bump($userId);
}

/* ---------- How alerts reach you, your mobile number, your connections (4.36.0) ----------
 * Shared by account.php and the app's account / save_alert_settings actions. As on the
 * website, no password is asked and nobody is notified; the mobile number is audited. */

/** The choices of "Email me about alerts", in the website's order. */
const ACCOUNT_ALERT_EMAIL_CHOICES = ['now' => 'Urgent ones at once, and daily', 'daily' => 'Once a day', 'off' => 'Never'];

/** The alert-email choice checked, without saving: null when it is one of the three, else the field error. */
function account_alert_email_error(mixed $how): ?string {
    return is_string($how) && isset(ACCOUNT_ALERT_EMAIL_CHOICES[$how]) ? null
        : 'Email me about alerts: choose Urgent ones at once, and daily; Once a day; or Never.';
}

/** Save how alerts are emailed: now, daily or off. */
function account_alert_email_save(int $userId, mixed $how): array {
    $err = account_alert_email_error($how);
    if ($err !== null) return ['ok' => false, 'errors' => ['alert_email' => $err]];
    db()->prepare('UPDATE users SET alert_email = ? WHERE id = ?')->execute([$how, $userId]);
    return ['ok' => true, 'alert_email' => $how, 'message' => ['now' => 'Urgent alerts will be emailed to you as they happen (at most one email every 15 minutes), with one email a day when you have unread alerts.',
        'daily' => 'You will get one email a day when you have unread alerts.',
        'off' => 'Alerts will no longer be emailed; they stay in Alerts in the app and on the website.'][$how]];
}

/** The mobile number checked, without saving: [number or null, error or null]. Blank removes it. */
function account_mobile_check(mixed $raw): array {
    $raw = is_string($raw) ? trim($raw) : (is_int($raw) ? (string)$raw : '');
    $mobile = $raw === '' ? null : sms_number($raw);
    return [$mobile, $raw !== '' && $mobile === null ? 'Enter a mobile number such as 515-555-0100, or leave it empty.' : null];
}

/** Save the mobile number (blank removes it) and whether urgent alerts are texted to it (only with a number). */
function account_mobile_save(int $userId, mixed $raw, bool $sms): array {
    [$mobile, $err] = account_mobile_check($raw);
    if ($err !== null) return ['ok' => false, 'errors' => ['mobile' => $err]];
    $alerts = $sms && $mobile !== null ? 1 : 0;
    db()->prepare('UPDATE users SET mobile = ?, alert_sms = ? WHERE id = ?')->execute([$mobile, $alerts, $userId]);
    audit($userId, 'user.mobile', 'user', $userId, $alerts ? 'urgent alerts by text on' : 'urgent alerts by text off');
    return ['ok' => true, 'mobile' => $mobile, 'alert_sms' => (bool)$alerts,
        'message' => $mobile === null ? 'Mobile number removed.' : 'Mobile number saved.' . ($alerts ? ' Urgent alerts are texted to it (at most one every 15 minutes, never with client details).' : '')];
}

/**
 * The calendar and online-meeting connections in the website's words (My Account): the
 * calendar (null when calendars are not available on this server) and each meeting provider
 * with an app set up. Read only; connecting and disconnecting stay on the website.
 */
function account_connections(int $userId): array {
    $calendar = null;
    if (calendar_available()) {
        $cs = db()->prepare('SELECT calendar_sync, calendar_synced_at, calendar_error FROM users WHERE id = ?');
        $cs->execute([$userId]);
        $csr = $cs->fetch() ?: [];
        $kind = calendar_kind($userId);
        $conn = $kind !== null ? calendar_connection($userId, $kind) : null;
        $syncOn = $kind !== null && !empty($csr['calendar_sync']);
        $syncedAt = !empty($csr['calendar_synced_at']) ? (string)$csr['calendar_synced_at'] : null;
        $error = !empty($csr['calendar_error']) ? (string)$csr['calendar_error'] : null;
        $kindText = $kind !== null ? calendar_kind_name($kind) . ($conn !== null ? ': ' . (string)$conn['label'] : ' (its password or key cannot be read on this server: connect it again)') . '.' : '';
        $syncText = $syncOn ? 'Sync is on' . ($syncedAt !== null ? ', last brought up to date ' . fmt_datetime($syncedAt) : '') . '.' : '';
        $calendar = ['kind' => $kind, 'kind_name' => $kind !== null ? calendar_kind_name($kind) : '', 'label' => $conn !== null ? (string)$conn['label'] : null,
            'sync_on' => $syncOn, 'synced_at' => $syncedAt, 'error' => $error,
            'kind_text' => $kindText, 'sync_text' => $syncText,
            'text' => $kind === null ? 'No calendar is connected yet.'
                : $kindText . ($syncOn ? ' ' . $syncText . ($error !== null ? ' Problem: ' . $error : '') : '')];
    }
    $meetings = [];
    foreach (array_keys(MEETING_PROVIDERS) as $prov) {
        if (meeting_app($prov) === null) continue;
        $acc = meeting_account($userId, $prov);
        $name = MEETING_PROVIDERS[$prov];
        $email = $acc && $acc['account_email'] ? (string)$acc['account_email'] : null;
        if ($acc && meeting_refused_at($acc) !== null) {
            $state = 'ended';
            $text = $name . ': the connection' . ($email !== null ? ' as ' . $email : '') . ' ended on ' . fmt_date(substr((string)$acc['refused_at'], 0, 10)) . ', so no links are made.';
        } elseif ($acc) {
            $state = 'connected';
            $text = $name . ': connected' . ($email !== null ? ' as ' . $email : '') . '.';
        } else {
            $state = 'not_connected';
            $text = $name . ': not connected.';
        }
        $meetings[] = ['provider' => $prov, 'name' => $name, 'state' => $state, 'account_email' => $email, 'text' => $text];
    }
    return ['calendar' => $calendar, 'meetings' => $meetings];
}
