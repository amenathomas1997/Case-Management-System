<?php
declare(strict_types=1);
// Restore the Case Management System from an off-site backup (3.0.53). Command line only:
//
//   php restore.php --zip=BACKUP.zip --dsn="mysql:host=localhost;dbname=NEW_DB;charset=utf8mb4" \
//       --db-user=USER --private=/home/you/cms_private --timezone=America/New_York [--force]
//
// --timezone is the organisation's time zone, as it was in the old config.php (4.29.0: required,
// never assumed: every stored date and time is read in it). It may be left out only when
// <private>/config.php already exists (the old one copied across), whose time zone is used.
//
// It asks for the backup password and the database password (nothing is typed on the
// command line, so they are not kept in the shell history), then:
//   1. opens the AES-256 ZIP with the backup password,
//   2. imports the database dump into the database named in --dsn (an empty one, unless --force),
//   3. puts the uploaded documents back in <private>/uploads,
//   4. writes <private>/config.php from the answers, with the Microsoft Graph and AI
//      secrets left blank: the backup never holds them (they are entered again afterwards).
// The key file <private>/meeting_tokens.key is not in the backup either (4.28.0): it unlocks the
// stored authenticator seeds and the QuickBooks and meeting connections, so it is copied from the
// old server by hand; the last lines say what to do without it.
// Each step is reported in plain sentences. See help.php "Restoring from a backup".
if (PHP_SAPI !== 'cli') exit("Run this from the command line.\n");
umask(0077); // config.php, the dump and the documents are private from the moment they are written

$opt = getopt('', ['zip:', 'dsn:', 'db-user:', 'private:', 'force', 'base-url:', 'timezone:']);
$example = "Example:\n  php restore.php --zip=backup.zip --dsn=\"mysql:host=localhost;dbname=cms;charset=utf8mb4\" --db-user=cms --private=/home/you/cms_private --timezone=America/New_York\n";
foreach (['zip', 'dsn', 'db-user', 'private'] as $need) {
    if (empty($opt[$need])) { fwrite(STDERR, "Missing --$need. $example"); exit(2); }
}
// The time zone (4.29.0): never assumed. A wrong one moves every stored date and time (sessions,
// reminders, due dates) by hours. From --timezone, else from an existing <private>/config.php.
$oldConfig = rtrim((string)$opt['private'], '/') . '/config.php';
$oldTz = null;
if (is_file($oldConfig)) { $old = @include $oldConfig; if (is_array($old) && is_string($old['app']['timezone'] ?? null)) $oldTz = $old['app']['timezone']; unset($old); }
$tzGiven = isset($opt['timezone']) && !is_array($opt['timezone']) ? trim((string)$opt['timezone']) : null;
if ($tzGiven === null && $oldTz === null) {
    fwrite(STDERR, "Missing --timezone: the organisation's time zone, as it was in the old config.php ('timezone' under 'app'), for example America/New_York, America/Chicago, America/Denver, America/Phoenix or America/Los_Angeles. It is never assumed, because a wrong one moves every stored date and time. This server's own zone is " . date_default_timezone_get() . ". $example");
    exit(2);
}
if ($tzGiven !== null && !in_array($tzGiven, timezone_identifiers_list(), true)) {
    fwrite(STDERR, "--timezone=$tzGiven is not a time zone name. Use one like America/New_York or America/Chicago. $example");
    exit(2);
}
if ($tzGiven !== null && $oldTz !== null && $tzGiven !== $oldTz) {
    fwrite(STDERR, "--timezone=$tzGiven differs from the time zone in the existing $oldConfig ($oldTz), which is kept as it is. Correct one of them, then run this again.\n");
    exit(2);
}
$ask = function (string $prompt, bool $hidden = true): string {
    fwrite(STDOUT, $prompt);
    $tty = $hidden && DIRECTORY_SEPARATOR === '/' && function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($tty) @shell_exec('stty -echo');
    $v = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) { @shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $v;
};
$say = fn(string $s) => fwrite(STDOUT, $s . "\n");

// An option given twice arrives as a list; refuse it rather than guess which was meant.
foreach ($opt as $k => $v) if (is_array($v)) { fwrite(STDERR, "--$k was given more than once.\n"); exit(2); }
$zipPath = (string)$opt['zip'];
$private = rtrim((string)$opt['private'], '/');
if (!is_file($zipPath)) { $say("Step 1 failed: the backup file $zipPath was not found."); exit(1); }
if (!is_dir($private) && !mkdir($private, 0750, true)) { $say("Step 1 failed: the private directory $private could not be created."); exit(1); }

/* 1. Open the encrypted backup */
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) { $say('Step 1 failed: the backup file is not a ZIP archive, or it is damaged.'); exit(1); }
$password = $ask('Backup password: ');
$zip->setPassword($password);
$dumpName = null;
for ($i = 0; $i < $zip->numFiles; $i++) { $n = (string)$zip->getNameIndex($i); if (preg_match('/^cms_.*\.sql\.gz$/', $n)) $dumpName = $n; }
if ($dumpName === null) { $say('Step 1 failed: the backup holds no database dump (cms_….sql.gz).'); exit(1); }
// The dump is copied to a private temporary file and read line by line, so a large one
// never has to fit in memory.
$tmpDump = tempnam(sys_get_temp_dir(), 'cmsrestore');
register_shutdown_function(fn() => @unlink($tmpDump));
$in = $zip->getStream($dumpName);
$copied = $in ? stream_copy_to_stream($in, $out = fopen($tmpDump, 'wb')) : false;
if ($in) fclose($in);
if (isset($out) && is_resource($out)) fclose($out);
$probe = @gzopen($tmpDump, 'rb');
$first = $probe ? gzread($probe, 64) : false;
if ($probe) gzclose($probe);
if (!$copied || $first === false || $first === '') { $say('Step 1 failed: the backup password is wrong, or the archive is damaged.'); exit(1); }
$say("Step 1 done: the backup opened. It holds $dumpName (" . round((int)$copied / 1048576, 1) . ' MB compressed).');

/* 2. Import the database */
$dbPass = $ask('Database password for ' . $opt['db-user'] . ': ');
try {
    $pdo = new PDO((string)$opt['dsn'], (string)$opt['db-user'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { $say('Step 2 failed: could not connect to the database (' . $e->getMessage() . ').'); exit(1); }
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($existing && !isset($opt['force'])) { $say('Step 2 stopped: the database already has ' . count($existing) . ' tables. Use an empty database, or add --force to replace them.'); exit(1); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($existing as $t) $pdo->exec('DROP TABLE `' . str_replace('`', '', (string)$t) . '`');
$stmt = ''; $count = 0;
$gz = gzopen($tmpDump, 'rb');
while (($line = gzgets($gz)) !== false) {
    if (trim($line) === '' || str_starts_with($line, '--')) continue;
    $stmt .= $line;
    if (preg_match('/;\s*$/', $line)) {
        try { $pdo->exec($stmt); } catch (Throwable $e) { $say('Step 2 failed at statement ' . ($count + 1) . ': ' . $e->getMessage()); exit(1); }
        $stmt = ''; $count++;
    }
}
gzclose($gz);
if ($count === 0) { $say('Step 2 failed: the database dump inside the backup holds no statements; it is damaged.'); exit(1); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$clients = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$say("Step 2 done: $count statements imported; the database has $clients client records.");

/* 3. Put the documents back */
$uploads = $private . '/uploads';
if (!is_dir($uploads)) mkdir($uploads, 0750, true);
$files = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $n = (string)$zip->getNameIndex($i);
    if (!str_starts_with($n, 'uploads/') || str_ends_with($n, '/') || str_contains($n, '..')) continue;
    $target = $uploads . '/' . substr($n, 8);
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0750, true);
    $bytes = $zip->getFromIndex($i);
    if ($bytes !== false && file_put_contents($target, $bytes) !== false) { @chmod($target, 0640); $files++; }
}
$zip->close();
$say("Step 3 done: $files uploaded document" . ($files === 1 ? '' : 's') . " put back in $uploads"
    . '. A daily backup holds only the documents changed in its last eight days: also open the latest Monday (full) backup with any ZIP tool and copy its uploads folder into ' . $uploads . ', keeping newer files.');

/* 4. config.php */
$configFile = $private . '/config.php';
if (is_file($configFile)) {
    $say("Step 4 skipped: $configFile already exists, so it was left as it is.");
} else {
    $baseUrl = (string)($opt['base-url'] ?? $ask('The site address, for example https://cms.example.org: ', false));
    $tz = (string)$tzGiven; // checked above: given and a real time zone name
    $cfg = "<?php\n// Written by dist/restore.php on " . date('Y-m-d') . ". Fill in the empty values (Microsoft Graph, email addresses, branding), then check every value, the time zone included.\nreturn " . var_export([
        'db' => ['dsn' => (string)$opt['dsn'], 'user' => (string)$opt['db-user'], 'pass' => $dbPass],
        'brand' => ['name' => '', 'short' => '', 'org' => '', 'tagline' => '', 'color' => '#1e3a8a', 'color_dark' => '#0f1f4d', 'accent' => '#f59e0b'],
        'graph' => ['tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'sender' => '', 'token_cache' => $private . '/graph_token.json'],
        'entra' => ['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'auto_provision_group' => ''],
        'app' => ['name' => '', 'base_url' => rtrim($baseUrl, '/'), 'mail_from' => '', 'mail_from_name' => '', 'support_email' => '', 'backup_email' => '',
            'session_timeout_minutes' => 30, 'otp_expiry_minutes' => 10, 'upload_dir' => $uploads, 'timezone' => $tz, 'backup_password' => $password],
    ], true) . ";\n";
    file_put_contents($configFile, $cfg);
    @chmod($configFile, 0600);
    $say("Step 4 done: $configFile written, with the time zone $tz. Open it and fill in the empty values: the Microsoft Graph details (from Microsoft Entra; a new client secret may be needed), the email addresses and the branding; check that 'timezone' is the organisation's own.");
}
$say('');
$say('Next: copy the application files into the website folder, sign in as an administrator, and open System health.');
$say('The AI provider key and the Microsoft Entra sign-in secret were not in the backup: enter them again on the AI assistant and Federation pages.');
// 4.29.0: backups made by cron/backup.sh hold no live link tokens.
$say('Links were not in the backup either: every client portal link, counselor portal link and instructor calendar-feed address stopped working, and so did every signature, consent, release, report acknowledgement and survey link not yet used. '
    . 'Send each client a new portal link from the client\'s page and each counselor a new link from the Counselors page, have each instructor copy the calendar-feed address again under My Account, and resend any pending signature, consent, release, acknowledgement or survey request.');
// 4.28.0: authenticator seeds are encrypted with this key file, which no backup holds.
if (is_file($private . '/meeting_tokens.key')) {
    $say("The key file $private/meeting_tokens.key is in place: if it is the old server's copy, authenticator apps and the QuickBooks and meeting connections keep working.");
} else {
    // 4.29.0 wording, matching System health's "Forget stored connections and keys".
    $say("meeting_tokens.key is not in $private (no backup holds it). " . 'If meeting_tokens.key was not restored, copy the old meeting_tokens.key back into the private directory beside config.php (chmod 600): then nothing is lost. If it is gone for good, sign in as an administrator (if every administrator uses an authenticator app and has no passkey or Microsoft sign-in, first run `php reset_2fa.php admin@example.org` in the cron folder for one of them), open System health, and under Sign-in and security use "Forget stored connections and keys" (type FORGET to confirm). It turns off the authenticator apps the server can no longer read, forgets the QuickBooks connection and meeting accounts it can no longer read, and makes a new key file. Then connect QuickBooks again and ask staff to connect Zoom or Teams again on My Account.');
}
exit(0);
