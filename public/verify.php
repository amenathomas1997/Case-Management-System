<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();

if (current_user()) redirect('index.php');
$pendingId = $_SESSION['pending_user_id'] ?? null;
// The pending state (password verified, waiting on the code) expires after 15 minutes.
if (!$pendingId || (time() - (int)($_SESSION['pending_at'] ?? 0)) > 900) {
    unset($_SESSION['pending_user_id'], $_SESSION['pending_at']);
    // Say why only when a sign-in was actually under way (not on a direct visit).
    if ($pendingId) flash('Your sign-in took longer than 15 minutes, so it was stopped for security. Enter your email and password again.', 'error');
    redirect('login.php');
}

// A reset after the password step invalidates the pending sign-in as well.
$pending = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
$pending->execute([$pendingId]);
$pendingUser = $pending->fetch();
if (!$pendingUser || !hash_equals(credential_version($pendingUser), (string)($_SESSION['pending_credential_version'] ?? ''))) {
    unset($_SESSION['pending_user_id'], $_SESSION['pending_at'], $_SESSION['pending_credential_version']);
    redirect('login.php');
}

$error = null;
// An account with an authenticator app signs in with it (or a passkey) only
// (4.27.0, owner decision): no code is emailed to it, and an emailed code is never
// accepted for it, so control of the mailbox alone (with "Forgot your password?")
// does not get anyone in. A lost app is reset by an administrator on Users.
$totpOnly = totp_seed_present($pendingUser);
// 4.28.0: a seed this server cannot read (key file lost or replaced) is said plainly; no code can work.
$totpUnreadable = $totpOnly && totp_seed_state($pendingUser) === 'unreadable';
if ($totpUnreadable) $error = TOTP_UNREADABLE_MSG;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend']) && $totpOnly) {
    // Nothing is emailed to an account with an authenticator app.
    flash('This account uses an authenticator app, so no code is emailed. Enter the code the app shows now. If you no longer have the app, ask an administrator to reset your two-step verification.', 'error');
    redirect('verify.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    // Cap resends per sign-in attempt: each new code resets the 5-guess counter,
    // so unlimited resends would allow unlimited guessing (and inbox flooding).
    $_SESSION['otp_resends'] = (int)($_SESSION['otp_resends'] ?? 0) + 1;
    if ($_SESSION['otp_resends'] > 5) {
        unset($_SESSION['pending_user_id'], $_SESSION['pending_at'], $_SESSION['otp_resends']);
        flash('Too many new codes were requested. Please sign in again.', 'error');
        redirect('login.php');
    }
    // Each new code counts against the same cap as a sign-in (4.27.0): at most 5
    // codes for this account in 15 minutes, whichever page asks. The new code is
    // recorded first and counted with it included, so parallel requests cannot
    // all slip under the cap.
    $lkey = 'login:' . strtolower((string)$pendingUser['email']);
    db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)')->execute([client_ip(), $lkey]);
    $sentRow = (int)db()->lastInsertId();
    $sentQ = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 1 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $sentQ->execute([$lkey]);
    if ((int)$sentQ->fetchColumn() > 5) {
        db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$sentRow]);
        flash('Too many sign-in codes were requested for this account. Wait 15 minutes, then use “Send a new code” again.', 'error');
        redirect('verify.php');
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$pendingId]);
    if ($user = $stmt->fetch()) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        db()->prepare("UPDATE login_otps SET consumed = 1 WHERE user_id = ? AND channel = 'web' AND consumed = 0")->execute([$user['id']]);
        db()->prepare("INSERT INTO login_otps (user_id, code_hash, expires_at, channel) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'web')")
            ->execute([$user['id'], password_hash($code, PASSWORD_DEFAULT), ((int)cfg('otp_expiry_minutes') ?: 10)]);
        [$subj, $body] = email_template('otp', ['name' => $user['name'], 'code' => $code, 'minutes' => (string)((int)cfg('otp_expiry_minutes') ?: 10)]);
        $sent = send_app_mail($user['email'], $subj, $body);
        // Requesting a new code extends the sign-in window (WCAG 2.2.1) —
        // the resend cap above still bounds the total attempts.
        $_SESSION['pending_at'] = time();
        if ($sent) {
            flash('A new code has been emailed to you.');
        } else {
            flash('The new code could not be emailed. If you use an authenticator app, enter its code instead; otherwise contact your administrator.', 'error');
        }
    }
    redirect('verify.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $totpUnreadable) {
    audit((int)$pendingUser['id'], 'login.totp_unreadable', 'user', (int)$pendingUser['id'], 'authenticator seed stored but not readable with this server\'s key file');
    $error = TOTP_UNREADABLE_MSG;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/inc/totp.php';
    $code = preg_replace('/\D/', '', is_string($_POST['code'] ?? null) ? $_POST['code'] : '');

    // Global guess-rate cap for this account (mirrors the mobile API): more than
    // 8 failed code attempts in 15 minutes halts verification until it ages out,
    // so re-running the password step to mint fresh codes can't widen the window.
    // Each guess is recorded as a failure BEFORE it is counted or checked (and
    // the record removed if it turns out right or is never tested), so requests
    // sent in parallel cannot all see room under the cap and all be tested.
    $vkey = 'verify:' . (string)$pendingUser['email'];
    $guessRow = 0;      // this guess's provisional failure record
    $tested = false;    // whether the code was actually checked
    if (strlen($code) !== 6) {
        // Every code (emailed or from the app) is 6 digits: an empty or partial
        // entry is not a guess, so it uses up no tries and is not logged.
        $error = $totpOnly ? 'Enter the 6-digit code from your authenticator app.' : 'Enter the 6-digit code from the email or from your authenticator app.';
    } else {
        db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)')->execute([client_ip(), $vkey]);
        $guessRow = (int)db()->lastInsertId();
        $vrc = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $vrc->execute([$vkey]);
        if ((int)$vrc->fetchColumn() > 8) {   // 8 earlier failures plus this guess
            // Refused untested, so it is not a failure of its own.
            db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$guessRow]);
            $guessRow = 0;
            // The cap lifts when enough of those failures are over 15 minutes old;
            // a new code does not lift it, so say when, not "send a new code".
            $until = db()->prepare("SELECT DATE_ADD(attempted_at, INTERVAL 15 MINUTE) FROM login_attempts WHERE email = ? AND success = 0
                AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY attempted_at DESC LIMIT 1 OFFSET 7");
            $until->execute([$vkey]);
            $at = strtotime((string)$until->fetchColumn());
            $error = 'Too many incorrect codes, so code entry is paused for this account. Try again after '
                . ($at ? date('g:i A', $at) : '15 minutes') . '. A new code does not end the pause.';
        }
    }

    // Both code types share one attempt counter on the active email-OTP row,
    // which only exists after the password step — so neither can be guessed
    // without the password, and neither gets more than 5 tries per code.
    $stmt = db()->prepare("SELECT *, (expires_at < NOW()) AS is_expired FROM login_otps WHERE user_id = ? AND channel = 'web' AND consumed = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pendingId]);
    $otp = $stmt->fetch();

    // Where more tries come from: a new emailed code, or for an account with an
    // authenticator app (no code is emailed) signing in again.
    $more = $totpOnly ? 'Sign in again with your password to try more codes.' : 'Use “Send a new code” below.';
    if ($error) {
        // Rate-capped above — fall through to the form with the message.
    } elseif (!$otp || $otp['is_expired']) {
        $error = $totpOnly ? 'This sign-in has expired. Sign in again with your password.' : 'Your code has expired. Use “Send a new code” below.';
    } elseif ((int)$otp['attempts'] >= 5) {
        db()->prepare('UPDATE login_otps SET consumed = 1 WHERE id = ?')->execute([$otp['id']]);
        $error = 'Too many incorrect attempts. ' . $more;
    } else {
        // Take one of the code's 5 tries before checking the guess: every
        // session waiting on this account shares the newest code, and a try
        // counted only after the slow check could be spent many times at once.
        $take = db()->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE id = ? AND consumed = 0 AND attempts < 5');
        $take->execute([$otp['id']]);
        if ($take->rowCount() !== 1) {
            $error = 'Too many incorrect attempts. ' . $more;
        } else {
            $tested = true;
            // Authenticator-app code accepted whenever the user has TOTP enabled
            $tu = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
            $tu->execute([$pendingId]);
            $totpUser = $tu->fetch();
            // The seed is stored encrypted (4.28.0); one stored before then is re-stored encrypted once it works.
            $totpPlain = $totpUser && totp_seed_present($totpUser) ? totp_secret_plain($totpUser['totp_secret']) : null;
            if ($totpPlain !== null && totp_verify_once($totpPlain, $code, (int)$totpUser['id'])) {
                totp_secret_upgrade((int)$totpUser['id'], $totpUser['totp_secret'], $totpPlain);
                db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$guessRow]);
                if (!claim_login_otp((int)$otp['id'], true)) {
                    flash('That code has expired or was already used. Sign in again.', 'error');
                    redirect('login.php');
                }
                unset($_SESSION['pending_user_id'], $_SESSION['pending_at'], $_SESSION['pending_credential_version'], $_SESSION['otp_resends']);
                establish_user_session($totpUser);
                audit((int)$totpUser['id'], 'login', 'user', (int)$totpUser['id'], '2FA verified (authenticator app)');
                flash('Welcome back, ' . $totpUser['name'] . '.');
                redirect(resume_url_after_sign_in());
            }
        }
    }

    if ($error) {
        // fall through to the form with the message; a guess that was never
        // tested is not counted as a failure
        if ($guessRow && !$tested) db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$guessRow]);
    } elseif ($totpOnly || !password_verify($code, $otp['code_hash'])) {
        // The try and the failure were both recorded before the check. An account
        // with an authenticator app reaches here only with a wrong app code: the
        // emailed-code check is never made for it (4.27.0).
        $used = db()->prepare('SELECT attempts FROM login_otps WHERE id = ?');
        $used->execute([$otp['id']]);
        $left = max(0, 5 - (int)$used->fetchColumn());
        $error = ($totpOnly ? 'That code is not correct. Enter the code your authenticator app shows right now. '
                : 'That code is not correct. Check the newest email, or your authenticator app if you use one. ')
            . ($left > 0 ? $left . ($left === 1 ? ' try' : ' tries') . ' left' . ($totpOnly ? '.' : ' with this code.')
                : ($totpOnly ? 'No tries are left: sign in again with your password to try more codes.' : 'No tries are left with this code: use “Send a new code” below.'));
    } else {
        db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$guessRow]);
        if (!claim_login_otp((int)$otp['id'], true)) {
            flash('That code has expired or was already used. Sign in again.', 'error');
            redirect('login.php');
        }
        $u = db()->prepare('SELECT * FROM users WHERE id = ?');
        $u->execute([$pendingId]);
        $user = $u->fetch();
        if (!$user || !$user['is_active'] || !hash_equals(credential_version($pendingUser), credential_version($user))) redirect('login.php');
        unset($_SESSION['pending_user_id'], $_SESSION['pending_at'], $_SESSION['pending_credential_version'], $_SESSION['otp_resends']);
        establish_user_session($user);
        audit((int)$user['id'], 'login', 'user', (int)$user['id'], '2FA verified');
        flash('Welcome back, ' . $user['name'] . '.');
        redirect(resume_url_after_sign_in());
    }
}

// A refused code puts 'Error — ' in the title too, so it is the first thing heard.
page_header(($error ? 'Error — ' : '') . 'Enter verification code');
?>
<div class="card auth-card">
  <h1>Enter your verification code</h1>
  <?php if ($totpOnly): ?>
  <p id="code-intro">Enter the 6-digit code your authenticator app shows now. No code is emailed to an account with an authenticator app. Lost the app? Ask an administrator to reset your two-step verification.</p>
  <?php else: ?>
  <p id="code-intro">We emailed a 6-digit code to your registered address. Enter it below — or, if you've set up an authenticator app, you can enter the current code from the app instead.</p>
  <?php endif; ?>
  <?php // Read once, as the description of the code field that takes focus on load (aria-describedby below).
  if ($error): ?><div class="alert alert-error" id="code-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="verify.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <?php // 10 minutes is the installer's default (dist/install.php) when the setting is missing. ?>
      <label for="code">6-digit code <span class="hint"><?= $totpOnly ? 'This sign-in expires ' . ((int)cfg('otp_expiry_minutes') ?: 10) . ' minutes after you entered your password.' : 'The code expires ' . ((int)cfg('otp_expiry_minutes') ?: 10) . ' minutes after it was sent.' ?></span></label>
      <input type="text" id="code" name="code" class="otp-input" inputmode="numeric"
             maxlength="20" required autocomplete="one-time-code" autofocus
             <?= $error ? 'aria-invalid="true"' : '' ?>
             aria-describedby="<?= $error ? 'code-error ' : '' ?>code-intro">
    </div>
    <button type="submit" class="btn btn-primary">Verify and sign in</button>
  </form>
  <?php if (!$totpOnly): ?>
  <form method="post" action="verify.php" class="mt-1">
    <?= csrf_field() ?>
    <button type="submit" name="resend" value="1" class="btn btn-secondary">Send a new code</button>
  </form>
  <?php endif; ?>
</div>
<?php page_footer();
