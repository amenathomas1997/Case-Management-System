<?php
// Passkey (WebAuthn) endpoints: enroll from My Account, sign in from the
// login page. A passkey sign-in requires user verification (Face ID, PIN,
// fingerprint), which is possession + inherence — it replaces the email code.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/webauthn.php';

header('Content-Type: application/json; charset=UTF-8');
$action = $_GET['action'] ?? '';
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];

function jout(array $d, int $status = 200): never {
    http_response_code($status);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['ok' => false, 'error' => 'POST only.'], 405);

/**
 * Same session validation as require_login(), returning JSON instead of a
 * redirect: idle timeout enforced, live user row re-checked, activity touched.
 */
function passkey_session_user(): array {
    $u = current_user();
    $timeout = ((int)cfg('session_timeout_minutes')) * 60;
    if (!$u || (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeout)) {
        jout(['ok' => false, 'error' => 'Your session has expired — sign in again first.'], 401);
    }
    $row = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $row->execute([(int)$u['id']]);
    $userRow = $row->fetch();
    if (!$userRow) jout(['ok' => false, 'error' => 'Your session has expired — sign in again first.'], 401);
    $_SESSION['last_activity'] = time();
    return $userRow;
}

/** How long a passkey ceremony may take, in seconds. WebAuthn's accessibility
 *  guidance asks for at least 5 minutes when user verification is required; a
 *  security key, its PIN and a braille display can take longer, so 10. The
 *  browser's own timeout and the stored challenge use the same limit. */
const PASSKEY_CEREMONY_SECONDS = 600;

/** A refusal already worded for the person at the device: what happened and
 *  what to do next. Shown as written. */
final class PasskeyRefusal extends RuntimeException {}

/**
 * The message shown for a failed passkey step. A PasskeyRefusal is shown as
 * written; anything else (webauthn.php's technical checks) is logged and
 * replaced by plain wording chosen by its code, never shown word for word.
 */
function passkey_user_message(RuntimeException $e, bool $signIn): string {
    if ($e instanceof PasskeyRefusal) return $e->getMessage();
    // The fixed texts of this file and webauthn.php carry no personal data; any
    // other exception class is logged by name only.
    error_log('passkey ' . ($signIn ? 'sign-in' : 'enrolment') . ' refused: '
        . (get_class($e) === RuntimeException::class ? $e->getMessage() : get_class($e)));
    $orPassword = $signIn ? ', or sign in with your email and password' : '';
    return match ($e->getCode()) {
        WEBAUTHN_ERR_CHALLENGE => 'This passkey request expired or was replaced by a newer one (for example, one started in another tab). Reload the page and try again' . $orPassword . '.',
        WEBAUTHN_ERR_ORIGIN => 'This page was not opened from this system\'s usual web address, so the passkey cannot be used here. Open the site from its usual address and try again' . $orPassword . '.',
        WEBAUTHN_ERR_KEY_TYPE => 'This security key uses a kind of passkey this system does not support. Try another security key, or your phone\'s or computer\'s built-in passkeys.',
        default => 'Your device\'s passkey response could not be checked. Try again' . $orPassword . '.',
    };
}

/** A challenge stored with its age; null once expired (PASSKEY_CEREMONY_SECONDS),
 *  when $needPassword and the challenge was not issued after a password check,
 *  or when $userId is given and the challenge was issued to another account. */
function take_challenge(string $key, bool $needPassword = false, ?int $userId = null): ?string {
    $c = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    if (!is_array($c) || !isset($c['c'], $c['t']) || (time() - (int)$c['t']) > PASSKEY_CEREMONY_SECONDS) return null;
    if ($needPassword && ($c['pw'] ?? false) !== true) return null;
    if ($userId !== null && (int)($c['uid'] ?? 0) !== $userId) return null;
    return (string)$c['c'];
}

/* ---------- Enrollment (signed-in users only) ---------- */
if ($action === 'reg_options' || $action === 'reg_finish') {
    $userRow = passkey_session_user();
    $u = ['id' => (int)$userRow['id'], 'name' => $userRow['name'], 'email' => $userRow['email']];

    if ($action === 'reg_options') {
        // Adding a sign-in method is sensitive, so the account password is
        // checked first (a hijacked session is not enough), before the device
        // prompt, and every wrong guess counts toward the re-check limit.
        $pwCheck = reauth_check((int)$u['id'], is_string($input['password'] ?? null) ? $input['password'] : '');
        if ($pwCheck === 'signed_out') {
            flash(REAUTH_SIGNED_OUT_MSG, 'error');
            jout(['ok' => false, 'error' => REAUTH_SIGNED_OUT_MSG, 'redirect' => 'login.php'], 401);
        }
        if ($pwCheck === 'locked') jout(['ok' => false, 'error' => REAUTH_LOCKED_MSG], 429);
        if ($pwCheck !== 'ok') {
            jout(['ok' => false, 'error' => 'Your account password is required to add a passkey, and it did not match.'], 400);
        }
        $challenge = random_bytes(32);
        $_SESSION['webauthn_reg_challenge'] = ['c' => $challenge, 't' => time(), 'pw' => true, 'uid' => (int)$u['id']];
        $exclude = [];
        $s = db()->prepare('SELECT credential_id FROM passkeys WHERE user_id = ?');
        $s->execute([(int)$u['id']]);
        foreach ($s->fetchAll() as $row) {
            $exclude[] = ['type' => 'public-key', 'id' => $row['credential_id']];
        }
        jout(['ok' => true, 'options' => [
            'challenge' => b64url_encode($challenge),
            'rp' => ['name' => (string)cfg('name'), 'id' => webauthn_rp_id()],
            'user' => ['id' => b64url_encode('user-' . (int)$u['id']), 'name' => $u['email'], 'displayName' => $u['name']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => PASSKEY_CEREMONY_SECONDS * 1000,
            'excludeCredentials' => $exclude,
        ]]);
    }

    // reg_finish — only a challenge issued after this account's password check counts.
    $challenge = take_challenge('webauthn_reg_challenge', true, (int)$u['id']);
    try {
        if ($challenge === null) throw new PasskeyRefusal('Adding the passkey took longer than 10 minutes, or was started again in another tab, so it was stopped. Type your password and choose "Add a passkey on this device" again.');
        $clientDataJSON = b64url_decode((string)($input['clientDataJSON'] ?? ''));
        webauthn_check_client_data($clientDataJSON, 'webauthn.create', $challenge);
        $att = cbor_decode(b64url_decode((string)($input['attestationObject'] ?? '')));
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) throw new RuntimeException('Bad attestation object');
        $ad = webauthn_parse_auth_data($att['authData'], true);
        if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $ad['rpIdHash'])) throw new RuntimeException('Wrong site (rpIdHash)', WEBAUTHN_ERR_ORIGIN);
        if (!($ad['flags'] & 0x01) || !($ad['flags'] & 0x04)) {
            throw new PasskeyRefusal('Your device did not confirm it was you with Face ID, a fingerprint or a PIN, so the passkey was not added. If you use a security key, set a PIN on it first, then try again.');
        }
        if (!$ad['credentialId'] || !is_array($ad['cosePublicKey'])) throw new RuntimeException('No credential in response');
        [$pem, $alg] = cose_key_to_pem($ad['cosePublicKey']);
        $name = trim((string)($input['name'] ?? '')) ?: 'Passkey added ' . date('M j, Y');
        db()->prepare('INSERT INTO passkeys (user_id, credential_id, public_key, alg, sign_count, name) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$u['id'], b64url_encode((string)$ad['credentialId']), $pem, $alg, (int)$ad['signCount'], mb_substr($name, 0, 120)]);
        audit((int)$u['id'], 'passkey.add', 'user', (int)$u['id'], $name);
        // Shown, and focused, when My Account reloads, so the save is confirmed.
        flash('Passkey "' . mb_substr($name, 0, 120) . '" added. You can now use it to sign in.');
        // Tell the account owner — if they didn't do this, they can react.
        send_app_mail($u['email'], 'A passkey was added to your account',
            "Hello {$u['name']},\n\nA passkey (\"{$name}\") was just added to your " . cfg('name') . " account. It can be used to sign in without a password.\n\n"
            . "If this was you, no action is needed. If it was NOT you, remove it under My Account right away, change your password, and tell your administrator.\n");
        jout(['ok' => true]);
    } catch (PDOException $e) {
        // PDOException is itself a RuntimeException, so it must be caught first:
        // its message quotes SQL and key values and is never shown.
        if ((string)$e->getCode() === '23000') {
            jout(['ok' => false, 'error' => 'That passkey is already registered here.'], 400);
        }
        error_log('passkey reg_finish: ' . get_class($e) . ' ' . $e->getCode());
        jout(['ok' => false, 'error' => 'Could not save the passkey — try again.'], 400);
    } catch (RuntimeException $e) {
        jout(['ok' => false, 'error' => 'The passkey was not added. ' . passkey_user_message($e, false)], 400);
    } catch (Throwable $e) {
        error_log('passkey reg_finish: ' . get_class($e));
        jout(['ok' => false, 'error' => 'Could not save the passkey — try again.'], 400);
    }
}

/* ---------- Sign-in (public) ---------- */
if ($action === 'login_options') {
    $challenge = random_bytes(32);
    $_SESSION['webauthn_login_challenge'] = ['c' => $challenge, 't' => time()];
    jout(['ok' => true, 'options' => [
        'challenge' => b64url_encode($challenge),
        'rpId' => webauthn_rp_id(),
        'userVerification' => 'required',
        'timeout' => PASSKEY_CEREMONY_SECONDS * 1000,
        'allowCredentials' => [],   // discoverable: the authenticator offers its passkeys
    ]]);
}

if ($action === 'login_finish') {
    $challenge = take_challenge('webauthn_login_challenge');
    try {
        if ($challenge === null) throw new PasskeyRefusal('The passkey sign-in took longer than 10 minutes, or was started again in another tab, so it was stopped. Choose "Sign in with a passkey" to try again, or sign in with your email and password.');
        $credId = (string)($input['rawId'] ?? '');
        $s = db()->prepare('SELECT p.*, u.password_hash, u.is_active, u.name AS user_name, u.email AS user_email, u.role AS user_role
            FROM passkeys p JOIN users u ON u.id = p.user_id WHERE p.credential_id = ?');
        $s->execute([$credId]);
        $pk = $s->fetch();
        if (!$pk || !$pk['is_active']) {
            // Passkeys are removed when a password is changed or reset, but the
            // device keeps offering them; say so, and what to do instead.
            throw new PasskeyRefusal('This passkey is no longer linked to an account here (passkeys are removed when a password is changed or reset). Sign in with your email and password, then remove the old passkey from your device\'s saved passwords; you can add a new one under My Account.');
        }

        $clientDataJSON = b64url_decode((string)($input['clientDataJSON'] ?? ''));
        webauthn_check_client_data($clientDataJSON, 'webauthn.get', $challenge);
        $authData = b64url_decode((string)($input['authenticatorData'] ?? ''));
        $ad = webauthn_parse_auth_data($authData);
        if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $ad['rpIdHash'])) throw new RuntimeException('Wrong site (rpIdHash)', WEBAUTHN_ERR_ORIGIN);
        if (!($ad['flags'] & 0x01) || !($ad['flags'] & 0x04)) {
            throw new PasskeyRefusal('Your device did not confirm it was you with Face ID, a fingerprint or a PIN. If you use a security key, set a PIN on it, then try again, or sign in with your email and password.');
        }
        if (!webauthn_verify_signature($pk['public_key'], $pk['alg'], $authData, $clientDataJSON, b64url_decode((string)($input['signature'] ?? '')))) {
            throw new RuntimeException('Signature check failed.');
        }
        // Sign-count check per WebAuthn §7.2: suspicious when either counter is
        // non-zero and the new one hasn't increased. (Both zero is normal —
        // most passkey providers never count.) The stored value never goes down.
        if (((int)$ad['signCount'] !== 0 || (int)$pk['sign_count'] !== 0) && (int)$ad['signCount'] <= (int)$pk['sign_count']) {
            audit((int)$pk['user_id'], 'login.passkey_replay', 'user', (int)$pk['user_id'], 'sign count did not increase');
            throw new PasskeyRefusal('This passkey response could not be accepted because it repeated an earlier one. Choose "Sign in with a passkey" to try again, or sign in with your email and password.');
        }
        db()->prepare('UPDATE passkeys SET sign_count = GREATEST(sign_count, ?), last_used_at = NOW() WHERE id = ?')
            ->execute([(int)$ad['signCount'], (int)$pk['id']]);

        establish_user_session(['id' => $pk['user_id'], 'name' => $pk['user_name'],
            'email' => $pk['user_email'], 'role' => $pk['user_role'], 'password_hash' => $pk['password_hash']]);
        audit((int)$pk['user_id'], 'login', 'user', (int)$pk['user_id'], 'passkey (' . $pk['name'] . ')');
        flash('Welcome back, ' . $pk['user_name'] . '.');
        jout(['ok' => true, 'redirect' => take_resume_url()]);
    } catch (PDOException $e) {
        // A database fault (connection limit, missing table) must never reach a
        // signed-out visitor: its text names the database user and schema.
        error_log('passkey login_finish: ' . get_class($e) . ' ' . $e->getCode());
        jout(['ok' => false, 'error' => 'Passkey sign-in failed. Try again or use your password.'], 400);
    } catch (RuntimeException $e) {
        // Plain wording with a next step; webauthn.php's technical text is only logged.
        jout(['ok' => false, 'error' => passkey_user_message($e, true)], 400);
    } catch (Throwable $e) {
        error_log('passkey login_finish: ' . get_class($e));
        jout(['ok' => false, 'error' => 'Passkey sign-in failed. Try again or use your password.'], 400);
    }
}

jout(['ok' => false, 'error' => 'Unknown action.'], 404);
