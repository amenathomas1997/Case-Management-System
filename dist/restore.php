<?php
declare(strict_types=1);
// Restore the Case Management System from an off-site backup (3.0.53). Command line only:
//
//   php restore.php --zip=BACKUP.zip --dsn="mysql:host=localhost;dbname=NEW_DB;charset=utf8mb4" \
//       --db-user=USER --private=/home/you/cms_private [--force]
//
// It asks for the backup password and the database password (nothing is typed on the
// command line, so they are not kept in the shell history), then:
//   1. opens the AES-256 ZIP with the backup password,
//   2. imports the database dump into the database named in --dsn (an empty one, unless --force),
//   3. puts the uploaded documents back in <private>/uploads,
//   4. writes <private>/config.php from the answers, with the Microsoft Graph and AI
//      secrets left blank: the backup never holds them (they are entered again afterwards).
// Each step is reported in plain sentences. See help.php "Restoring from a backup".
if (PHP_SAPI !== 'cli') exit("Run this from the command line.\n");
umask(0077); // config.php, the dump and the documents are private from the moment they are written

$opt = getopt('', ['zip:', 'dsn:', 'db-user:', 'private:', 'force', 'base-url:', 'timezone:']);
foreach (['zip', 'dsn', 'db-user', 'private'] as $need) {
    if (empty($opt[$need])) { fwrite(STDERR, "Missing --$need. Example:\n  php restore.php --zip=backup.zip --dsn=\"mysql:host=localhost;dbname=cms;charset=utf8mb4\" --db-user=cms --private=/home/you/cms_private\n"); exit(2); }
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
    $tz = (string)($opt['timezone'] ?? 'America/Chicago');
    $cfg = "<?php\n// Written by dist/restore.php on " . date('Y-m-d') . ". Fill in the empty values (Microsoft Graph, email addresses, branding), then check every value.\nreturn " . var_export([
        'db' => ['dsn' => (string)$opt['dsn'], 'user' => (string)$opt['db-user'], 'pass' => $dbPass],
        'brand' => ['name' => '', 'short' => '', 'org' => '', 'tagline' => '', 'color' => '#1e3a8a', 'color_dark' => '#0f1f4d', 'accent' => '#f59e0b'],
        'graph' => ['tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'sender' => '', 'token_cache' => $private . '/graph_token.json'],
        'entra' => ['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'auto_provision_group' => ''],
        'app' => ['name' => '', 'base_url' => rtrim($baseUrl, '/'), 'mail_from' => '', 'mail_from_name' => '', 'support_email' => '', 'backup_email' => '',
            'session_timeout_minutes' => 30, 'otp_expiry_minutes' => 10, 'upload_dir' => $uploads, 'timezone' => $tz, 'backup_password' => $password],
    ], true) . ";\n";
    file_put_contents($configFile, $cfg);
    @chmod($configFile, 0600);
    $say("Step 4 done: $configFile written. Open it and fill in the empty values: the Microsoft Graph details (from Microsoft Entra; a new client secret may be needed), the email addresses and the branding.");
}
$say('');
$say('Next: copy the application files into the website folder, sign in as an administrator, and open System health.');
$say('The AI provider key and the Microsoft Entra sign-in secret were not in the backup: enter them again on the AI assistant and Federation pages.');
exit(0);
