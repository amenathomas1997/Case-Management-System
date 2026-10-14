<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();

if (current_user()) redirect('index.php');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // A blank or partial entry is named on its own field and is not counted as a
    // failed sign-in (saying a box is empty reveals nothing about accounts).
    $fieldErrs = [];
    if ($email === '') $fieldErrs['email'] = 'Enter your email address.';
    elseif (!str_contains($email, '@')) $fieldErrs['email'] = 'Enter your full email address, including the @ sign, for example name@example.org.';
    // 'verify:' plus the address must fit login_attempts.email (190); users.php and
    // account.php refuse a longer address for an account, so none is shut out by this.
    elseif (strlen($email) > 183) $fieldErrs['email'] = 'That email address is too long. Check it and enter it again.';
    if ($password === '') $fieldErrs['password'] = 'Enter your password.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fieldErrs) {
    set_form_errors($fieldErrs);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: max 8 failed attempts per email or IP in 15 minutes. A signed-in
    // user's password re-check failures ('reauth:') and wrong counselor-portal codes
    // ('vrc-fail-<id>'), each limited on their own, do not use up the sign-in budget
    // of everyone sharing the address.
    // Attempts are recorded under 'login:<email>', so whatever is typed here can
    // never land in another limiter's bucket ('verify:<email>', 'portal-msg-7'…).
    // Rows under the bare email (earlier releases, and the app sign-in until it
    // uses the same key) still count toward the same limit.
    $lkey = 'login:' . $email;
    // Each attempt is recorded as a failure BEFORE it is counted or checked (and
    // the record removed if it is refused untested, or marked a success), as
    // verify.php does: requests sent in parallel cannot all see room under the
    // cap during the slow password check and all be tested.
    db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)')->execute([$ip, $lkey]);
    $attemptRow = (int)db()->lastInsertId();
    $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (email IN (?, ?) OR (ip = ? AND email NOT LIKE 'reauth:%' AND email NOT LIKE 'vrc-fail-%'))");
    $rl->execute([$lkey, $email, $ip]);
    // A correct password emails a new code and cancels the one before, so the codes
    // sent are capped per account too (shared with the app sign-in, which uses the
    // same key): someone who knows the password but not the mailbox cannot flood it,
    // or keep cancelling the code the real user is typing. Only correct passwords
    // leave success rows, and the cap is checked before the password, so the answer
    // says nothing about whether this password is right.
    $sentQ = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 1 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $sentQ->execute([$lkey]);
    $codesCapped = (int)$sentQ->fetchColumn() >= 5;
    if ((int)$rl->fetchColumn() > 8) {   // 8 earlier failures plus this attempt
        db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$attemptRow]);
        $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
    } elseif ($codesCapped) {
        db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$attemptRow]);
        $error = 'Too many sign-in codes were requested for this account. Wait 15 minutes, then sign in again.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // Verify against a dummy hash (at the same cost as real ones) for unknown
        // emails so response timing doesn't reveal which accounts exist.
        $ok = $user
            ? password_verify($password, $user['password_hash'])
            : password_verify($password, dummy_password_hash()) && false;
        if ($ok) {
            db()->prepare('UPDATE login_attempts SET success = 1 WHERE id = ?')->execute([$attemptRow]);
            // Counted again with this one included: requests sent in parallel can
            // all pass the check above during the slow password check.
            $okq = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE success = 1 AND email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
            $okq->execute([$lkey]);
            if ((int)$okq->fetchColumn() > 5) {
                $ok = false;
                $error = 'Too many sign-in codes were requested for this account. Wait 15 minutes, then sign in again.';
            }
        }

        if ($ok) {
            // Password verified — issue 6-digit OTP and email it (2FA step)
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            // Codes are bound to the channel that issued them: a web code is never
            // accepted by the app sign-in, nor an app code here.
            db()->prepare("UPDATE login_otps SET consumed = 1 WHERE user_id = ? AND channel = 'web' AND consumed = 0")->execute([$user['id']]);
            db()->prepare("INSERT INTO login_otps (user_id, code_hash, expires_at, channel) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'web')")
                ->execute([$user['id'], password_hash($code, PASSWORD_DEFAULT), (int)cfg('otp_expiry_minutes')]);

            [$subj, $body] = email_template('otp', ['name' => $user['name'], 'code' => $code, 'minutes' => cfg('otp_expiry_minutes')]);
            $sent = send_app_mail($user['email'], $subj, $body);

            // A clean session for the new sign-in: nothing an earlier account
            // left in this browser carries over (see fresh_auth_session()).
            fresh_auth_session();
            $_SESSION['pending_user_id'] = (int)$user['id'];
            $_SESSION['pending_at'] = time();
            $_SESSION['pending_credential_version'] = credential_version($user);
            $_SESSION['otp_resends'] = 0;
            $_SESSION['otp_verified'] = false;
            $_SESSION['last_activity'] = time();
            if (!$sent) flash('Warning: the verification email could not be sent. Contact your administrator.', 'error');
            redirect('verify.php');
        }
        if (!$error) $error = 'Incorrect email or password.';
    }
}

page_header(($error || form_errors() ? 'Error — ' : '') . 'Sign in');
$typedEmail = $email ?? '';
?>
<div class="card auth-card">
  <h1>Sign in</h1>
  <p class="muted">Two-step verification: after entering your password, a 6-digit code will be emailed to you.
     Need more time? Choose "Send a new code" to get a new code with a full time limit.</p>
  <p class="muted">For privacy, you are signed out after <?= (int)cfg('session_timeout_minutes') ?> minutes without activity —
     a warning appears first. Keep your form open, sign in again in a new tab, then return to submit it. File uploads may need to be selected again.</p>
  <?php // Announced by the focus move below; no alert role, which would make some screen readers read it twice.
  if ($error): ?><div class="alert alert-error" id="login-error" tabindex="-1"><?= h($error) ?></div>
  <script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('login-error');if(e)e.focus();});</script><?php endif; ?>
  <form method="post" action="login.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['email' => 'Email address', 'password' => 'Password']) ?>
    <?php // A refused sign-in describes both fields but marks neither invalid: it
          // never says which one was wrong. A blank field is marked on its own. ?>
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" required autocomplete="username" value="<?= h($typedEmail) ?>"
             <?= $error ? 'aria-describedby="login-error"' : field_aria('email') ?>>
      <?= field_error('email') ?>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password"
             <?= $error ? 'aria-describedby="login-error"' : field_aria('password') ?>>
      <?= field_error('password') ?>
    </div>
    <button type="submit" class="btn btn-primary">Continue</button>
  </form>
  <p class="mt-1" style="text-align:center;">— or —</p>
  <button type="button" id="passkey-btn" class="btn btn-secondary" style="display:block; width:100%; text-align:center;">Sign in with a passkey</button>
  <?php // Always rendered (empty until needed): a live region that is unhidden at the
        // moment it changes is often not announced (VoiceOver with Safari). ?>
  <p class="field-error" id="passkey-err" role="alert"></p>
  <p class="muted" id="passkey-status" role="status"></p>
  <?php if (entra_cfg()): ?>
  <a class="btn btn-secondary mt-1" style="display:block; text-align:center;" href="sso.php?start=1">Sign in with Microsoft</a>
  <?php endif; ?>
  <p class="mt-1"><a href="forgot.php" style="display:inline-flex; align-items:center; min-height:2.75rem;">Forgot your password?</a></p>
</div>
<script>
(function(){
  var btn = document.getElementById('passkey-btn'), err = document.getElementById('passkey-err'),
      status = document.getElementById('passkey-status');
  function b64uToBuf(s){s=s.replace(/-/g,'+').replace(/_/g,'/');var bin=atob(s),a=new Uint8Array(bin.length);for(var i=0;i<bin.length;i++)a[i]=bin.charCodeAt(i);return a.buffer;}
  function bufToB64u(b){var a=new Uint8Array(b),s='';for(var i=0;i<a.length;i++)s+=String.fromCharCode(a[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
  if (!window.PublicKeyCredential) { btn.hidden = true; return; }
  btn.addEventListener('click', function(){
    err.textContent = '';
    status.textContent = 'Waiting for your passkey. Follow your device\'s prompt.';
    fetch('passkey.php?action=login_options', {method:'POST', credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if (!d.ok) throw new Error(d.error || 'Could not start.');
        var o = d.options;
        o.challenge = b64uToBuf(o.challenge);
        return navigator.credentials.get({publicKey:o});
      })
      .then(function(cred){
        return fetch('passkey.php?action=login_finish', {method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({
            rawId: bufToB64u(cred.rawId),
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            authenticatorData: bufToB64u(cred.response.authenticatorData),
            signature: bufToB64u(cred.response.signature)
          })});
      })
      .then(function(r){return r.json();})
      .then(function(d){
        if (!d.ok) throw new Error(d.error || 'Sign-in failed.');
        location.href = d.redirect || 'index.php';
      })
      .catch(function(e){
        // Cancelling, the 10-minute limit and "no passkey for this site on this
        // device" all arrive as NotAllowedError: said politely, not as an error.
        if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) {
          status.textContent = 'Passkey sign-in was cancelled, timed out, or found no passkey for this site on this device. You can try again, or sign in with your email and password.';
          return;
        }
        status.textContent = '';
        err.textContent = e.message || 'Passkey sign-in failed. Try again, or sign in with your email and password.';
      });
  });
})();
</script>
<?php page_footer();
