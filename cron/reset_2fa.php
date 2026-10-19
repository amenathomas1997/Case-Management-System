<?php
declare(strict_types=1);
// Turn off two-step verification for one account, from the server's command line (4.28.0).
//
// For the administrator who has lost their authenticator app (and has no passkey), when
// there is no other administrator to use "Reset authenticator & passkeys" on the Users
// page. Since 4.27.0 an account with an authenticator app cannot finish signing in with an
// emailed code, so without this nobody could get back into Administration.
//
//   php reset_2fa.php person@example.org [--docroot=/path/to/web/docroot] [--yes]
//   (in the private cron folder, beside the scheduled jobs; Software update installs it there)
//   (or --email=person@example.org)
//
// Run it on the server, as the user that owns the site's files. --docroot is the folder
// holding the website (the one with login.php and inc/); it is found by itself when this
// script sits in the release's dist/ folder beside public/. It asks you to type the email
// again to confirm, unless --yes is given. It then, for that one account:
//   1. turns off the authenticator app (the stored seed is removed),
//   2. removes its passkeys,
//   3. signs it out of the phone apps (a phone already reported lost still wipes itself),
//      and out of every web browser (the session epoch),
//   4. clears its sign-in lockout,
//   5. records it in the audit log ("from the command line") and emails the person,
// and prints what it did. The person then signs in with their password and an emailed
// code, and sets up the authenticator app again under My Account.
//
// It refuses to run from a web server. --core (the path of inc/core.php itself) is for the tests.
if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['HTTP_HOST'])) {
    if (!headers_sent()) header('HTTP/1.1 403 Forbidden');
    exit("This tool runs only from the server's command line.\n");
}

$say = fn(string $s) => fwrite(STDOUT, $s . "\n");
$fail = function (string $s, int $code = 1): never { fwrite(STDERR, $s . "\n"); exit($code); };

// Options in any order, before or after the email (getopt() stops at the first plain word).
$opt = []; $rest = [];
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) { $rest[] = $a; continue; }
    [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, null);
    if (!in_array($k, ['email', 'docroot', 'core', 'yes'], true)) $fail("Unknown option --$k.", 2);
    if (array_key_exists($k, $opt)) $fail("--$k was given more than once.", 2);
    if ($k !== 'yes' && ($v === null || $v === '')) $fail("--$k needs a value, as in --$k=…", 2);
    $opt[$k] = $k === 'yes' ? true : $v;
}
if (count($rest) > 1 || (isset($opt['email']) && $rest)) $fail('Give one email address.', 2);
$email = strtolower(trim((string)($opt['email'] ?? ($rest[0] ?? ''))));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail("Give the account's email address. Example:\n  php reset_2fa.php person@example.org --docroot=/home/you/public_html/cms", 2);
}

/* Find the website's inc/core.php (it reads the private configuration and opens the database). */
$core = (string)($opt['core'] ?? '');
if ($core === '') {
    // Like the scheduled jobs beside it: cron_config.php (it returns the inc/ path), then the
    // production layout, then the repository layout.
    $cronInc = is_file(__DIR__ . '/cron_config.php') ? rtrim((string)(require __DIR__ . '/cron_config.php'), '/') : '';
    $roots = isset($opt['docroot']) ? [rtrim((string)$opt['docroot'], '/')]
        : array_values(array_filter([$cronInc !== '' ? dirname($cronInc) : '', dirname(__DIR__, 2) . '/public_html/casemanagement', dirname(__DIR__) . '/public']));
    foreach ($roots as $r) if (is_file($r . '/inc/core.php')) { $core = $r . '/inc/core.php'; break; }
}
if ($core === '' || !is_file($core)) {
    $fail("The website's inc/core.php was not found. Give the website folder with --docroot=/path/to/web/docroot (the folder that holds login.php and inc/).", 2);
}
require $core;

$find = db()->prepare('SELECT * FROM users WHERE LOWER(email) = ?');
$find->execute([$email]);
$target = $find->fetch();
if (!$target) $fail("No account here has the email address $email. Nothing was changed.");
$uid = (int)$target['id'];

if (!isset($opt['yes'])) {
    $say("This turns off two-step verification for {$target['name']} ($email): the authenticator app, passkeys, and every phone app and browser session.");
    fwrite(STDOUT, 'Type the email address again to go ahead: ');
    $typed = strtolower(trim((string)fgets(STDIN)));
    if ($typed !== $email) $fail('The email typed did not match. Nothing was changed.');
}

db()->beginTransaction();
try {
    $t = db()->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_last_step = NULL WHERE id = ?');
    $t->execute([$uid]);
    $pk = db()->prepare('DELETE FROM passkeys WHERE user_id = ?');
    $pk->execute([$uid]);
    // A phone reported lost keeps its row until it has wiped itself (as on the Users page).
    $app = db()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND wipe_requested_at IS NULL');
    $app->execute([$uid]);
    $lock = db()->prepare('DELETE FROM login_attempts WHERE success = 0 AND email IN (?, ?, ?, ?)');
    $lock->execute(['login:' . $email, $email, 'verify:' . $email, 'reauth:' . $email]);
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $fail('The reset failed and nothing was changed: ' . get_class($e) . ' ' . $e->getMessage());
}
$browsers = user_session_epoch_bump($uid);
$hadTotp = (int)$target['totp_enabled'] === 1;
$detail = 'from the command line (cron/reset_2fa.php): TOTP cleared, ' . $pk->rowCount() . ' passkey(s) removed, '
    . $app->rowCount() . ' app session(s) signed out' . ($browsers ? ', browser sessions ended' : '');
audit(null, 'user.reset_2fa', 'user', $uid, $detail);
$audited = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'user.reset_2fa' AND entity_id = ? AND detail = ?");
$audited->execute([$uid, mb_substr($detail, 0, 255)]);
$mailed = (int)$target['is_active'] === 1 && send_app_mail($target['email'], 'Your sign-in methods were reset',
    "Hello {$target['name']},\n\nYour authenticator app and passkeys for " . cfg('name') . " were reset from the server's command line, and you were signed out of the mobile app"
    . ($browsers ? ' and of every web browser' : '') . ".\n"
    . "You can sign in with your password and an emailed code, then set them up again under My Account.\n"
    . "If you did not expect this, contact your administrator.\n");

$say("Done for {$target['name']} ($email):");
$say($hadTotp ? '- The authenticator app was turned off.' : '- The authenticator app was already off.');
$say('- ' . $pk->rowCount() . ' passkey(s) removed.');
$say('- Signed out of the phone app (' . $app->rowCount() . ' session(s)).');
$say($browsers ? '- Signed out of every web browser.' : '- Browser sessions could not be ended (the database upgrade for 4.27.0 has not run); they end when they time out.');
$say('- ' . ($lock->rowCount() > 0 ? 'Sign-in lockout cleared.' : 'There was no sign-in lockout.'));
$say((int)$audited->fetchColumn() > 0 ? '- Recorded in the audit log.' : '- WARNING: it could not be recorded in the audit log.');
$say((int)$target['is_active'] !== 1 ? '- The account is deactivated, so nobody was emailed and it still cannot sign in.'
    : ($mailed ? "- {$target['name']} was emailed about it." : '- The email to them could not be sent.'));
if ((int)$target['is_active'] === 1) $say('They can now sign in with their password and an emailed code, then set up the authenticator app again under My Account.');
exit(0);
