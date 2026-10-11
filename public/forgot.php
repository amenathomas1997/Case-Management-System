<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    // A blank or malformed entry is named on the field and uses up no reset
    // attempt (saying a box is empty reveals nothing about which accounts exist).
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        keep_input();
        keep_field_errors(['email' => $email === '' ? 'Enter your email address.'
            : 'Enter your full email address, for example name@example.org.']);
        redirect('forgot.php');
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = 'pw-reset' AND ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() < 5) {
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?, 'pw-reset', 1)")->execute([$ip]);
        $u = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $u->execute([$email]);
        if ($user = $u->fetch()) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$user['id']]);
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
                ->execute([$user['id'], hash('sha256', $token)]);
            send_app_mail($user['email'], 'Reset your password',
                "Hello {$user['name']},\n\nUse this link to set a new password for " . cfg('name') . ":\n\n"
                . cfg('base_url') . '/reset.php?t=' . $token
                . "\n\nThe link expires in 30 minutes. If you did not request this, you can ignore this email.\n");
        }
    }
    // Same response either way — do not reveal which emails exist
    flash('If that email belongs to an account, a reset link has been sent. Check your inbox.');
    redirect('login.php');
}

page_header('Forgot password');
?>
<div class="card auth-card">
  <h1>Forgot your password?</h1>
  <p>Enter your account email and we'll send you a reset link.</p>
  <form method="post" action="forgot.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['email' => 'Email address']) ?>
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" required autocomplete="username" value="<?= h(old_in('email')) ?>" <?= field_aria('email') ?>>
      <?= field_error('email') ?>
    </div>
    <button type="submit" class="btn btn-primary">Send reset link</button>
  </form>
  <p class="mt-1"><a href="login.php">Back to sign in</a></p>
</div>
<?php page_footer();
