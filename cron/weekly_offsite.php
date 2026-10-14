<?php
declare(strict_types=1);
// Weekly off-site backup: email the newest database dump, together with the
// uploaded documents, as one AES-256 encrypted ZIP to the backup address.
// Cron: 45 2 * * 1 (Mondays, after the nightly backup)
if (PHP_SAPI !== 'cli') exit('CLI only.');

// Where the application's inc/ directory is: an optional cron_config.php beside
// this script (it returns the path) wins, then the production path, then the
// repository layout. Nothing needs editing here for a different install.
foreach ([
    is_file(__DIR__ . '/cron_config.php') ? (string)(require __DIR__ . '/cron_config.php') : '',
    '/home/cmsuser/public_html/casemanagement/inc',
    dirname(__DIR__) . '/public/inc',
] as $inc) {
    $inc = rtrim($inc, '/');
    if ($inc !== '' && is_file($inc . '/core.php')) { require $inc . '/core.php'; break; }
}
if (!function_exists('db')) {
    fwrite(STDERR, "CMS cron: the application's inc/core.php was not found. Create cron_config.php beside this script containing: <?php return '/full/path/to/your/web/docroot/inc';\n");
    exit(1);
}

$dir = dirname((string)cfg('upload_dir')) . '/backups';
$dumps = glob($dir . '/cms_*.sql.gz') ?: [];
if (!$dumps) { echo "No backups found.\n"; exit(1); }
rsort($dumps);
$latest = $dumps[0];

$recipient = cfg('backup_email');
if (!$recipient) { echo "No backup_email configured.\n"; exit(1); }

// The dump holds every client's records, so it must be encrypted before it
// leaves the server: AES-256 ZIP with the password from config ('backup_password').
// If no password is set, we REFUSE to email an unencrypted dump and send a
// configuration notice instead — PII must never leave the server in the clear.
$password = (string)(cfg('backup_password') ?? '');
if ($password === '') {
    $noticed = send_app_mail((string)cfg('backup_email'), 'CMS off-site backup NOT sent — set a backup password',
        "This week's off-site backup was NOT emailed because no 'backup_password' is configured.\n"
        . "The database dump contains every client's records and will not be sent unencrypted.\n\n"
        . "Add a strong 'backup_password' to the server's config.php to enable encrypted off-site backups,\n"
        . "or download a copy manually from the server at {$dir} and store it off-site.\n");
    echo "Refused to email an unencrypted backup — no backup_password set. " . offsite_notice($noticed) . "\n";
    exit(1);
}
// The off-site copy holds the database AND the uploaded documents (signed
// consents, assessment attachments …), which otherwise exist only on this
// server. A small archive goes as an ordinary attachment; a larger one through a
// Graph upload session. When the documents cannot go (over 1 GB together with
// the database, too big for an email attachment, or the send fails), the
// database is sent on its own and the administrators are warned.
const OFFSITE_INLINE_MAX = 2 * 1024 * 1024;      // raw-MIME send: double base64 must stay under Graph's ~4 MB request cap
const OFFSITE_ATTACH_MAX = 150 * 1024 * 1024;    // the largest attachment a Graph upload session accepts
const OFFSITE_UPLOADS_MAX = 1024 * 1024 * 1024;  // database + documents above this: database only

$uploads = offsite_files(rtrim((string)cfg('upload_dir'), '/'));
$warning = '';
if ($uploads === null) {
    $uploads = [];
    $warning = 'The uploaded documents were NOT included: the uploads folder on the server could not be read.';
} elseif ($uploads && filesize($latest) + array_sum(array_column($uploads, 2)) > OFFSITE_UPLOADS_MAX) {
    $warning = 'The uploaded documents (' . offsite_mb(array_sum(array_column($uploads, 2))) . ' MB) were NOT included: together with the database they are over 1 GB.';
    $uploads = [];
}
// Temporary archives are removed however the script ends.
$tmpFiles = [];
register_shutdown_function(function () use (&$tmpFiles): void { foreach ($tmpFiles as $f) @unlink($f); });

$ok = false; $sentWithUploads = false; $attachName = ''; $size = 0;
foreach ($uploads ? [true, false] : [false] as $withUploads) {
    // Encryption must succeed. If anything fails (temp dir unwritable, disk full,
    // zip error), nothing is sent — NEVER fall back to emailing a plaintext dump.
    $zipPath = offsite_zip($latest, $withUploads ? $uploads : [], $password, $dir);
    if ($zipPath === null) {
        if ($withUploads) { $warning = 'The uploaded documents were NOT included: the encrypted archive holding them could not be built this week.'; continue; }
        $noticed = send_app_mail($recipient, 'CMS off-site backup could not be encrypted',
            "The weekly off-site backup could not be encrypted this run, so nothing was emailed.\n"
            . "The database dump contains every client's records and will not be sent unencrypted.\n\n"
            . "Check the server's temp space/permissions, then download a copy manually from {$dir} and store it off-site.\n");
        echo "Refused to email backup — encryption failed. " . offsite_notice($noticed) . "\n";
        exit(1);
    }
    $tmpFiles[] = $zipPath;
    $size = (int)filesize($zipPath);
    $attachName = $withUploads ? preg_replace('/\.sql\.gz$/', '', basename($latest)) . '_with_uploads.zip' : basename($latest) . '.zip';
    if ($size > OFFSITE_ATTACH_MAX) {
        @unlink($zipPath);
        if ($withUploads) { $warning = 'The uploaded documents were NOT included: the encrypted archive with them is ' . offsite_mb($size) . ' MB, more than the 150 MB an email attachment can carry.'; continue; }
        $noticed = send_app_mail($recipient, 'CMS backup too large to email',
            'The latest database backup (' . basename($latest) . ', ' . offsite_mb($size) . " MB encrypted) is too large to send by email.\n"
            . "Download a copy manually from the server at {$dir} and store it off-site.\n");
        echo "Backup too large to email. " . offsite_notice($noticed) . "\n";
        // Nobody was told when the notice itself failed: say so with the exit status too.
        exit($noticed ? 0 : 1);
    }
    $body = "Attached is the latest Case Management System off-site backup ({$attachName}, " . offsite_mb($size) . " MB): an AES-256 encrypted ZIP holding "
        . ($withUploads ? 'the database and every uploaded document' : 'the database') . ".\n\n"
        . ($warning !== '' && !$withUploads ? "NOTE: {$warning} Copy the uploads folder from the server to off-site storage another way.\n\n" : '')
        . "Keep this email (or save the file somewhere safe) — it is your off-site copy in case the server is ever lost.\n"
        . "The ZIP password is the off-site backup password the installer showed once. Keep it in your organisation's password manager, not only on the server: if it is lost, this backup cannot be opened. (It is also the 'backup_password' in the server's config.php.)\n"
        . "To restore: unzip with that password, gunzip the .sql.gz file and import it into MariaDB"
        . ($withUploads ? ', then copy the uploads folder back into the private directory' : '') . ".\n"
        . "The backup leaves out the stored AI API key and Microsoft Entra client secret, so after a restore enter them again on the AI assistant and Federation pages.\n";
    $subject = 'Weekly off-site CMS backup — ' . date('M j, Y');
    $ok = $size <= OFFSITE_INLINE_MAX
        ? send_app_mail_attach($recipient, $subject, $body, [['name' => $attachName, 'mime' => 'application/zip', 'bytes' => (string)file_get_contents($zipPath)]])
        : offsite_send_large($recipient, $subject, $body, $zipPath, $attachName);
    @unlink($zipPath);
    if ($ok) { $sentWithUploads = $withUploads; break; }
    if ($withUploads) $warning = 'The uploaded documents were NOT included: the encrypted archive with them (' . offsite_mb($size) . ' MB) could not be emailed this week.';
}
if (!$ok) {
    // Make the failure visible instead of silently skipping a week.
    send_app_mail($recipient, 'CMS off-site backup FAILED to send',
        "This week's off-site backup email could not be sent. The backup still exists on the server at {$dir}.\n");
    echo "Failed to email backup.\n";
    exit(1);
}
if ($warning !== '') {
    notify_admins('CMS off-site backup: uploaded documents not included',
        $warning . "\n\nThe encrypted database backup was emailed to {$recipient}, but the uploaded client documents now have no off-site copy from this week. "
        . "Copy the uploads folder from the server's private directory to off-site storage another way.\n");
}
echo "Off-site backup emailed to {$recipient} (" . ($sentWithUploads ? 'database and uploaded documents' : 'database only') . ").\n";
exit(0);

/** Every regular file under $root as [path, name in the archive, bytes]; null when the folder cannot be read. */
function offsite_files(string $root): ?array {
    if ($root === '' || !is_dir($root)) return [];
    $files = [];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isLink() || !$f->isFile()) continue;
            $files[] = [$f->getPathname(), 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1)), (int)$f->getSize()];
        }
    } catch (Throwable $e) {
        return null;
    }
    return $files;
}

/** AES-256 ZIP of the dump (plus $files) in a new private temp file; null on any failure. */
function offsite_zip(string $dump, array $files, string $password, string $tmpDir): ?string {
    $path = @tempnam(is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : sys_get_temp_dir(), '.cms_offsite_');
    if ($path === false) return null;
    @chmod($path, 0600);
    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true;
    $ok = $opened && $zip->setPassword($password);
    foreach (array_merge([[$dump, basename($dump)]], $files) as $f) {
        if (!$ok) break;
        $ok = $zip->addFile($f[0], $f[1]) && $zip->setEncryptionName($f[1], ZipArchive::EM_AES_256);
    }
    $closed = $opened ? @$zip->close() : false;
    if (!$ok || !$closed || !is_file($path) || filesize($path) < 1) { @unlink($path); return null; }
    return $path;
}

function offsite_mb(int $bytes): string {
    return (string)round($bytes / 1048576, 1);
}

/** What the run's output says about a notice email: only one that really went out is reported as sent. */
function offsite_notice(bool $sent): string {
    return $sent ? 'Notice sent.' : 'The notice could NOT be emailed either: tell the backup contact another way.';
}

/**
 * Email a large file through Microsoft Graph: a draft holding the text, the
 * attachment uploaded in pieces through an upload session, then send. The draft
 * is deleted if any step fails, and is not kept in Sent Items once sent, so no
 * extra copy of the backup is left in the mailbox. Nothing secret is logged.
 */
function offsite_send_large(string $to, string $subject, string $body, string $path, string $name): bool {
    $g = graph_cfg();
    if (!$g) return false;
    $size = (int)filesize($path);
    $sender = '/users/' . rawurlencode($g['sender']);
    [$code, $draft] = graph_api('POST', $sender . '/messages', [
        'subject' => $subject,
        'body' => ['contentType' => 'Text', 'content' => $body],
        'toRecipients' => [['emailAddress' => ['address' => $to]]],
        // PR_DELETE_AFTER_SUBMIT: do not keep the sent copy in Sent Items.
        'singleValueExtendedProperties' => [['id' => 'Boolean 0x0E01', 'value' => 'true']],
    ]);
    if ($code !== 201 || empty($draft['id'])) { error_log("weekly_offsite: could not create the backup email draft (HTTP {$code})"); return false; }
    $message = $sender . '/messages/' . rawurlencode((string)$draft['id']);
    $ok = false;
    [$code, $session] = graph_api('POST', $message . '/attachments/createUploadSession', ['AttachmentItem' => [
        'attachmentType' => 'file', 'name' => $name, 'size' => $size, 'contentType' => 'application/zip',
    ]]);
    $url = (string)($session['uploadUrl'] ?? '');
    $fh = ($code === 201 && $url !== '') ? fopen($path, 'rb') : false;
    if ($fh === false) {
        error_log("weekly_offsite: could not start the attachment upload (HTTP {$code})");
    } else {
        $ok = true;
        // Each piece is under Graph's 4 MB per-request limit and a multiple of 320 KiB.
        for ($offset = 0; $ok && $offset < $size; ) {
            $chunk = fread($fh, 12 * 327680);
            if ($chunk === false || $chunk === '') { $ok = false; break; }
            $end = $offset + strlen($chunk) - 1;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $chunk,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                // The upload URL carries its own authorisation; no bearer token is sent.
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', "Content-Range: bytes {$offset}-{$end}/{$size}"],
            ]);
            curl_exec($ch);
            $put = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($put !== 200 && $put !== 201) { error_log("weekly_offsite: the attachment upload failed (HTTP {$put})"); $ok = false; }
            $offset = $end + 1;
        }
        fclose($fh);
    }
    if ($ok) {
        [$code] = graph_api('POST', $message . '/send');
        $ok = $code === 202;
        if (!$ok) error_log("weekly_offsite: the backup email could not be sent (HTTP {$code})");
    }
    if (!$ok) graph_api('DELETE', $message);   // never leave an unsent copy in Drafts
    return $ok;
}
