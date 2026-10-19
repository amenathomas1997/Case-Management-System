<?php
// Entra ID (Microsoft) federation — OpenID Connect authorization-code flow.
// Existing users sign in through a previously linked immutable Microsoft ID.
// New users may be provisioned into the configured group when enabled.
require __DIR__ . '/inc/bootstrap.php';

$entra = entra_cfg();
if (!$entra) {
    flash('Microsoft sign-in is not enabled on this system.', 'error');
    redirect('login.php');
}

$tenant = $entra['tenant_id'];
$redirectUri = cfg('base_url') . '/sso.php';

/* ---- Step 1: send the user to Microsoft ---- */
if (isset($_GET['start'])) {
    unset($_SESSION['sso_link_user']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_account'])) {
        csrf_check();
        $linkUser = require_login();
        $_SESSION['sso_link_user'] = (int)$linkUser['id'];
    }
    $_SESSION['sso_state'] = bin2hex(random_bytes(16));
    $_SESSION['sso_nonce'] = bin2hex(random_bytes(16));
    // 4.29.0 (P2): a browser handed over for signing in person may still hold the staff member's
    // Microsoft session. Microsoft is then told to ask for the password again (prompt=login,
    // max_age=0), and the reply must show it did (auth_time, checked below).
    $fresh = handover_marked();
    if ($fresh) $_SESSION['sso_fresh'] = time(); else unset($_SESSION['sso_fresh']);
    $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?"
        . http_build_query(sso_authorize_params((string)$entra['client_id'], $redirectUri, $_SESSION['sso_state'], $_SESSION['sso_nonce'], $fresh));
    header('Location: ' . $url);
    exit;
}

/* ---- Step 2: Microsoft sends the user back ---- */
if (isset($_GET['error'])) {
    // Anyone can request this URL, so only a reply to a sign-in this browser
    // started is recorded, only as Microsoft's short error code (never its
    // free text), and at most 10 times per address in 15 minutes.
    $state = $_GET['state'] ?? '';
    if (is_string($state) && $state !== '' && hash_equals((string)($_SESSION['sso_state'] ?? ''), $state)) {
        unset($_SESSION['sso_state']);
        $recent = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'login.sso_fail' AND ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $recent->execute([client_ip()]);
        if ((int)$recent->fetchColumn() < 10) {
            $err = substr((string)preg_replace('/[^a-z_]/', '', strtolower(is_string($_GET['error']) ? $_GET['error'] : '')), 0, 60);
            $desc = is_string($_GET['error_description'] ?? null) && preg_match('/AADSTS\d{1,9}/', $_GET['error_description'], $m) ? ' ' . $m[0] : '';
            audit(null, 'login.sso_fail', 'user', null, 'Microsoft returned ' . ($err !== '' ? $err : 'an error') . $desc);
        }
    }
    // A cancelled link from My Account goes back there (the user is still signed in).
    $wasLink = !empty($_SESSION['sso_link_user']);
    unset($_SESSION['sso_link_user']);
    if ($wasLink) {
        flash('Microsoft sign-in was cancelled or failed, so your Microsoft account was not linked.', 'error');
        redirect('account.php');
    }
    flash('Microsoft sign-in was cancelled or failed. You can sign in with your password instead.', 'error');
    redirect('login.php');
}

// Text only: anything else (code[]=…) is treated as missing, as the error branch above does.
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
if ($code === '' || $state === '' || !hash_equals((string)($_SESSION['sso_state'] ?? ''), $state)) {
    flash('Sign-in session did not match — please try again.', 'error');
    redirect('login.php');
}
unset($_SESSION['sso_state']);

/* Exchange the code for tokens (confidential client, direct TLS to Microsoft) */
$ch = curl_init("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $entra['client_id'],
        'client_secret' => $entra['client_secret'],
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid profile email',
    ]),
    CURLOPT_TIMEOUT => 20,
]);
// Tests (command line only) give Microsoft's token reply in CMS_TEST_SSO_TOKENS, as
// meeting_http() does for meetings; a web request never reads it.
if (PHP_SAPI === 'cli' && ($fakeTokens = getenv('CMS_TEST_SSO_TOKENS')) !== false && $fakeTokens !== '') {
    $resp = $fakeTokens;
} else {
    $resp = curl_exec($ch);
}
$tokens = is_string($resp) ? json_decode($resp, true) : null;
if (empty($tokens['id_token'])) {
    error_log('SSO token exchange failed: ' . substr((string)$resp, 0, 300));
    flash('Microsoft sign-in could not be completed. Try again, or sign in with your password.', 'error');
    redirect('login.php');
}

/* Validate the ID token claims. The token came directly from Microsoft's token
   endpoint over TLS using our client secret, so source authenticity is
   established; we still verify issuer, audience, tenant, expiry, and nonce.
   GUIDs are compared without regard to letter case: Microsoft issues them in
   lower case, and a tenant or client ID saved in capitals (before 3.0.21
   federation.php kept it as typed) would otherwise fail every sign-in. */
$parts = explode('.', $tokens['id_token']);
$claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
$nonce = $_SESSION['sso_nonce'] ?? '';
unset($_SESSION['sso_nonce']);
if (!$claims
    || strtolower((string)($claims['iss'] ?? '')) !== strtolower("https://login.microsoftonline.com/{$tenant}/v2.0")
    || strtolower((string)($claims['aud'] ?? '')) !== strtolower((string)$entra['client_id'])
    || strtolower((string)($claims['tid'] ?? '')) !== strtolower((string)$tenant)
    || (int)($claims['exp'] ?? 0) < time()
    || $nonce === '' || !hash_equals($nonce, (string)($claims['nonce'] ?? ''))) {
    audit(null, 'login.sso_fail', 'user', null, 'ID token validation failed');
    flash('Microsoft sign-in could not be verified. Please try again.', 'error');
    redirect('login.php');
}

$email = strtolower(trim($claims['email'] ?? $claims['preferred_username'] ?? ''));
$name = trim($claims['name'] ?? $email);
$oid = (string)($claims['oid'] ?? '');
if ($oid === '') {
    flash('Your Microsoft account could not be verified. Please try again.', 'error');
    redirect('login.php');
}

/* An existing account is matched by email on the first Microsoft sign-in only when
   the ID token proves the person is a member of the configured tenant (owner
   decisions 2026-10-15 and, for 4.27.0, the `acct` claim present and 0 with this
   tenant's tid: see sso_email_match_allowed()). Anyone else, and anyone whose
   email differs, links from My Account while signed in. */
$linkId = (int)($_SESSION['sso_link_user'] ?? 0);
unset($_SESSION['sso_link_user']);
$linkUser = $linkId ? require_login() : null;
// Where a failure is reported: My Account for a link request (still signed in).
$failTo = $linkId ? 'account.php' : 'login.php';

/* 4.29.0 (P2): on a browser handed over for signing, a sign-in counts only when Microsoft
   asked for the password after this sign-in started (auth_time). Microsoft includes auth_time
   when the app registration has it as an optional ID token claim (Federation explains). */
$freshAt = (int)($_SESSION['sso_fresh'] ?? 0);
unset($_SESSION['sso_fresh']);
$freshNeeded = !$linkId && ($freshAt > 0 || handover_marked());
if ($freshNeeded) {
    // Whether the app registration sends auth_time is shown on Federation and System health:
    // without it Microsoft sign-in can never pass this check.
    if (!isset($claims['auth_time'])) set_setting('sso_auth_time_missing', date('Y-m-d'));
    elseif ((string)(setting('sso_auth_time_missing') ?? '') !== '') set_setting('sso_auth_time_missing', '');
}
if ($freshNeeded && ($freshAt === 0 || !sso_fresh_auth_ok($claims, $freshAt))) {
    audit(null, 'login.sso_fail', 'user', null, 'browser handed over for signing: Microsoft did not confirm a new password entry' . (isset($claims['auth_time']) ? '' : ' (no auth_time claim)'));
    flash(HANDOVER_SSO_MSG, 'error');
    redirect('login.php');
}

/* 4.29.0 (P1): the Microsoft identity is looked up on every account, deactivated ones
   included. An identity that belongs to a deactivated account never signs in, links or
   gets a new account, whatever its email is now (a changed name or email would otherwise
   pass the email checks below and provision a fresh, active account). */
$u = db()->prepare('SELECT * FROM users WHERE entra_oid = ? ORDER BY is_active DESC, id');
$u->execute([$oid]);
$oidRows = $u->fetchAll();
$user = null;
foreach ($oidRows as $r) if ((int)$r['is_active'] === 1) { $user = $r; break; }
if (!$user && $oidRows) {
    $gone = $oidRows[0];
    audit(null, 'login.sso_fail', 'user', (int)$gone['id'], 'Microsoft identity belongs to a deactivated account; refused' . ($linkId ? ' (link)' : '') . ($email !== '' ? ': ' . $email : ''));
    flash($linkId ? 'That Microsoft account belongs to a deactivated account here, so it cannot be linked. Ask an administrator.'
        : 'The account here for this Microsoft account has been deactivated. Ask an administrator if you need access.', 'error');
    redirect($failTo);
}
if ($user && $linkId && (int)$user['id'] !== $linkId) {
    flash('That Microsoft identity is already linked to another account.', 'error');
    redirect('account.php');
}

if (!$user && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $u = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 AND entra_oid IS NULL');
    $u->execute([$email]);
    if ($user = $u->fetch()) {
        if ($linkId ? !sso_link_authorized($linkId, $linkUser, (int)$user['id'])
                    : !sso_email_match_allowed($claims, "https://login.microsoftonline.com/{$tenant}/v2.0")) {
            audit(null, 'login.sso_fail', 'user', (int)$user['id'], ($linkId ? 'link refused: ' : 'not shown to be a member of this tenant (acct claim), so not matched by email: ') . $email);
            flash($linkId ? 'That Microsoft account could not be linked to this account. Please try again.'
                : SSO_EMAIL_MATCH_REFUSED_MSG, 'error');
            redirect($failTo);
        }
        $bind = db()->prepare('UPDATE users SET entra_oid = ? WHERE id = ? AND entra_oid IS NULL AND is_active = 1');
        $bind->execute([$oid, (int)$user['id']]);
        if ($bind->rowCount() !== 1) {
            flash('Account linking changed during this request. Please try again.', 'error');
            redirect('account.php');
        }
        audit((int)$user['id'], 'user.sso_bind', 'user', (int)$user['id'], 'linked to Microsoft account ' . $email);
    }
}

/* An account here already has this email but cannot be used: deactivated, or
   linked to a different Microsoft identity (e.g. a recreated Microsoft account).
   Say which, rather than failing on the duplicate email when provisioning. */
if (!$user && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $ex = db()->prepare('SELECT id, is_active, entra_oid FROM users WHERE email = ?');
    $ex->execute([$email]);
    if ($existing = $ex->fetch()) {
        $deactivated = !(int)$existing['is_active'];
        audit(null, 'login.sso_fail', 'user', (int)$existing['id'], $deactivated
            ? 'account is deactivated: ' . $email : 'email already linked to a different Microsoft identity: ' . $email);
        flash($deactivated
            ? 'The account here for ' . $email . ' has been deactivated. Ask an administrator if you need access.'
            : 'The account here for ' . $email . ' is linked to a different Microsoft identity, so this one cannot sign in to it. Ask an administrator for help.', 'error');
        redirect($failTo);
    }
}

if (!$user && !$linkId && !empty($entra['auto_provision_group']) && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    /* 4.28.0 (N1): an account is created only for a member of the organisation's own
       tenant, by the same rule as matching by email (acct claim 0, no other
       organisation's idp). A guest (B2B) invited into the tenant passes every
       ID-token check above but is not staff, so no account is made for them. */
    if (!sso_email_match_allowed($claims, "https://login.microsoftonline.com/{$tenant}/v2.0")) {
        audit(null, 'login.sso_fail', 'user', null, 'not shown to be a member of this tenant (acct claim), so no account was created: ' . $email);
        flash('Your Microsoft account (' . $email . ') is not a member of this organisation, so no account was created for it. Ask an administrator to add you.', 'error');
        redirect('login.php');
    }
    $g = db()->prepare('SELECT id FROM `groups` WHERE name = ?');
    $g->execute([$entra['auto_provision_group']]);
    if ($gid = $g->fetchColumn()) {
        try {
            db()->prepare('INSERT INTO users (name, email, password_hash, role, group_id, entra_oid) VALUES (?,?,?,?,?,?)')
                ->execute([mb_substr($name, 0, 120), $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'instructor', (int)$gid, $oid]);
        } catch (PDOException $e) {
            // Another request created the same email a moment ago.
            if ((string)$e->getCode() !== '23000') throw $e;
            audit(null, 'login.sso_fail', 'user', null, 'provisioning clashed with an existing account: ' . $email);
            flash('An account for ' . $email . ' was created at the same moment. Try signing in with Microsoft again.', 'error');
            redirect('login.php');
        }
        $newId = (int)db()->lastInsertId();
        $u = db()->prepare('SELECT * FROM users WHERE id = ?');
        $u->execute([$newId]);
        $user = $u->fetch();
        audit($newId, 'user.provision', 'user', $newId, 'Entra SSO auto-provision into ' . $entra['auto_provision_group']);
    }
}

if (!$user) {
    audit(null, 'login.sso_fail', 'user', null, 'no matching user: ' . ($email ?: $oid));
    flash($linkId
        ? "Your Microsoft account ({$email}) does not use this account's email address, so it was not linked."
        : "Your Microsoft account ({$email}) is verified, but no user with that email exists here. Ask an administrator to add you.", 'error');
    redirect($failTo);
}

/* Linking from My Account: the user is already signed in, so this is not a new
   sign-in. Confirm the link and return them to My Account. */
if ($linkId) {
    flash('Your Microsoft account is linked. Next time you can use "Sign in with Microsoft".');
    redirect('account.php');
}

/* Establish the session — Microsoft enforced the tenant's own MFA policy */
// After a hand-over, Microsoft has just asked for the password (checked above): that ends the
// marker as the password and code do. Otherwise a Microsoft sign-in leaves any marker as it is.
establish_user_session($user, $freshNeeded);
audit((int)$user['id'], 'login', 'user', (int)$user['id'], 'Entra ID SSO');
flash('Welcome back, ' . $user['name'] . '.');
redirect(resume_url_after_sign_in());
