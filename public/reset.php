<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
$row = null;
if (strlen($token) === 64) {
    $stmt = db()->prepare('SELECT r.*, u.name, u.email FROM password_resets r JOIN users u ON u.id = r.user_id
        WHERE r.token_hash = ? AND r.used = 0 AND r.expires_at > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
}

if (!$row) {
    page_header('Reset password');
    echo '<div class="card auth-card"><h1>Link expired or invalid</h1><p>Request a new one from the <a href="forgot.php">forgot password page</a>.</p></div>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    // Tied to the field in error (passwords are never refilled, so both are typed again).
    if (strlen($new) < 10) {
        keep_field_errors(['new_password' => 'The new password must be at least 10 characters.']);
    } elseif ($new !== $confirm) {
        keep_field_errors(['confirm_password' => 'The confirmation does not match the new password. Type the same new password in both boxes.']);
    } else {
        // Credential changes and revocations commit together.
        db()->beginTransaction();
        try {
        // Consume the token atomically first, so it can only ever be used once.
        $claim = db()->prepare('UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0');
        $claim->execute([$row['id']]);
        if ($claim->rowCount() !== 1) {
            db()->rollBack();
            flash('That reset link was already used. Request a new one.', 'error');
            redirect('forgot.php');
        }
        $cf = db()->prepare('SELECT cal_token FROM users WHERE id = ?');
        $cf->execute([$row['user_id']]);
        $hadCalFeed = (string)$cf->fetchColumn() !== '';
        // The calendar-feed address goes too: anyone who had the session could
        // have copied it, and it would keep serving the schedule.
        db()->prepare('UPDATE users SET password_hash = ?, cal_token = NULL WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $row['user_id']]);
        // A password reset means the old credentials may be compromised:
        // sign the mobile app out everywhere and remove passkeys too, so an
        // attacker-enrolled passkey can't outlive the reset.
        db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$row['user_id']]);
        $pkDel = db()->prepare('DELETE FROM passkeys WHERE user_id = ?');
        $pkDel->execute([$row['user_id']]);
        db()->prepare('UPDATE login_otps SET consumed = 1 WHERE user_id = ?')->execute([$row['user_id']]);
        db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        audit((int)$row['user_id'], 'password.reset', 'user', (int)$row['user_id'],
            'via emailed reset link; browser and app sessions revoked' . ($pkDel->rowCount() ? ', ' . $pkDel->rowCount() . ' passkey(s) removed' : '')
            . ($hadCalFeed ? ', calendar feed address turned off' : ''));
        flash('Password changed. Sign in with your new password. (For safety, any passkeys on the account were removed — you can add them back from My Account.)'
            . ($hadCalFeed ? ' Your calendar subscription address was also turned off: create a new one under My Account and subscribe your calendar to it again.' : ''));
        redirect('login.php');
    }
    redirect('reset.php?t=' . $token);
}

// What a reset also does, said before the button (passkeys cannot be restored).
$pkc = db()->prepare('SELECT (SELECT COUNT(*) FROM passkeys WHERE user_id = ?) AS pk, (SELECT cal_token FROM users WHERE id = ?) AS cal');
$pkc->execute([$row['user_id'], $row['user_id']]);
$fx = $pkc->fetch();
$pkCount = (int)$fx['pk'];
page_header('Reset password');
?>
<div class="card auth-card">
  <h1>Set a new password</h1>
  <p class="muted">For <?= h($row['email']) ?></p>
  <form method="post" action="reset.php?t=<?= h($token) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['new_password' => 'New password', 'confirm_password' => 'Confirm new password']) ?>
    <?php // The account's email as the username, so a password manager updates the
          // right saved sign-in (it is read-only and not used by the server). ?>
    <div class="field">
      <label for="reset_username">Account email</label>
      <input type="email" id="reset_username" name="username" value="<?= h($row['email']) ?>" autocomplete="username" readonly>
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
    <?php $fxList = ['signs this account out on every device, including the mobile app'];
    if ($pkCount) $fxList[] = 'removes its ' . $pkCount . ' passkey' . ($pkCount === 1 ? '' : 's') . ' (they can be added again from My Account)';
    if ((string)$fx['cal'] !== '') $fxList[] = 'turns off its calendar subscription address';
    $fxLast = array_pop($fxList); ?>
    <p class="hint" id="reset-effects">Setting a new password <?= h(($fxList ? implode(', ', $fxList) . ' and ' : '') . $fxLast) ?>.</p>
    <button type="submit" class="btn btn-primary" aria-describedby="reset-effects">Change password</button>
  </form>
</div>
<?php page_footer();
