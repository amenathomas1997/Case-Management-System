<?php
declare(strict_types=1);
// One-button updates (4.18.0, owner request 2026-10-17): an installation updates itself from the
// publisher's GitHub releases. Each release carries cms-update-<version>.zip (the docroot/ and cron/
// files, and manifest.json with every file's SHA-256) and cms-update-<version>.zip.sig, signed with
// the publisher's private key (dist/make_update.php). A package is used only if its signature
// matches UPDATE_PUBLIC_KEY below: neither a changed GitHub release nor a tampered download can
// put code on a site. Installing: every new file is first written beside the old one and checked;
// every file it replaces is copied to a backup; the database changes run (they only add, so the
// running code keeps working); then the new files are swapped in. A failure puts the old files
// back, and an update the server cut off part way is offered for undoing on Software update.
// A fork that publishes its own releases changes UPDATE_REPO and UPDATE_PUBLIC_KEY.

const UPDATE_REPO = 'amenathomas1997/Case-Management-System';
const UPDATE_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAk+doePk21GrqXaiaLEFi
VrvbH25KDe2Vu+Wi+QlhHhae7wDfgmHaemJIntDQsl7st/ST1Do2lZwAEgfzae6Z
yQrc42qxpXQUoUSHgJzy9lP4imVm3wcOWvSB4ZqsBev12+40sn5iP16KPmly6uE/
axHllMjbL/yNXRb30Gm59YmIxWb1VdcLZ/VvZxvBGrTy0dpQsnRDWwt7NZY9UluC
bEBfpplYqjqgdzNPuCqn7ImWwkPAwSQRggDatvHsC7kT87i3gQ25JO9weY7sAI5A
9VbyNEQsUqTzJ9MqPMQP5SE8wNrHoUr3Up2c66AnncB3A/aG5jJHPhs/NQ+SD0Iu
cbciWU0k9lwmTXtN7nfUM9rSS/9ItReM1cRWRR0IDb3kI2KLrubXFQjiN9BfVhid
nKMFCedOFfgF3CgeJTI3CxmZhYpTmvNgaB7rghVLY+rWc7I/iP2tfM3NJ1lv5y6x
IS4RiHydSLJ3wRg3ehbiN5F0Dw0/kE1gC4TwlXq9Er9hAgMBAAE=
-----END PUBLIC KEY-----
PEM;

/** Files that belong to each site and are never replaced (as the install bundle's README says). */
const UPDATE_KEEP = ['inc/config_path.php', 'install.php', 'install.php.enabled', 'install.lock', 'schema_full.sql', 'updates.json',
    'assets/apple-touch-icon.png', 'assets/icon-192.png', 'assets/icon-512.png', 'assets/icon-1024.png'];
/** Files a site may have changed itself: replaced only while they are still a copy the publisher shipped. */
const UPDATE_SITE_EDITABLE = ['.htaccess', '.user.ini'];
/** Cron folder files that belong to the site. */
const UPDATE_CRON_KEEP = ['cron_config.php', 'backup.conf'];
/** The largest package accepted (bytes). */
const UPDATE_MAX_BYTES = 50 * 1024 * 1024;

/** The test hooks below work only in a command-line run (the test suites): never on a web server. */
function updater_test_env(string $name): ?string {
    if (PHP_SAPI !== 'cli') return null;
    $v = getenv($name);
    return $v !== false && $v !== '' ? $v : null;
}

/** Whether this site updates from GitHub (Settings, Updates: "github"). */
function updater_enabled(): bool {
    return update_source() === 'github';
}

/** The private folder where downloads, staging and backups go. */
function updater_dir(): string {
    $base = (string)($GLOBALS['CMS_PRIVATE_DIR'] ?? dirname((string)cfg('upload_dir')));
    $dir = rtrim($base, '/') . '/updates';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** The web docroot (this file's parent's parent). Tests: CMS_TEST_UPDATE_ROOT names a throwaway site instead. */
function updater_docroot(): string {
    return updater_test_env('CMS_TEST_UPDATE_ROOT') ?? dirname(__DIR__);
}

/** The installer's cron folder (private/cron, holding cron_config.php), or null when the jobs live elsewhere. */
function updater_cron_dir(): ?string {
    $d = rtrim((string)($GLOBALS['CMS_PRIVATE_DIR'] ?? ''), '/') . '/cron';
    // The installer's layout has cron_config.php there; an older site's jobs find the app on their own, so the jobs themselves mark the folder.
    return ($GLOBALS['CMS_PRIVATE_DIR'] ?? '') !== '' && (is_file($d . '/cron_config.php') || is_file($d . '/watchdog.php')) ? $d : null;
}

/** The marker an install leaves while it swaps files in; still there means it was cut off. */
function updater_marker(): string {
    return updater_dir() . '/in-progress.json';
}

/**
 * GET a URL (following GitHub's redirect to its file store). With $saveTo the body goes to that
 * file. Returns [HTTP status, body or '']. Tests: CMS_TEST_UPDATES (JSON url => local file) answers instead.
 */
function updater_http(string $url, ?string $saveTo = null): array {
    if (($fake = updater_test_env('CMS_TEST_UPDATES')) !== null) {
        $map = (array)json_decode($fake, true);
        if (!isset($map[$url]) || !is_file((string)$map[$url])) return [404, ''];
        if ($saveTo !== null) { copy((string)$map[$url], $saveTo); return [200, '']; }
        return [200, (string)file_get_contents((string)$map[$url])];
    }
    if (!preg_match('~^https://(api\.github\.com|github\.com)/~', $url)) return [0, ''];
    $ch = curl_init($url);
    $fh = $saveTo !== null ? fopen($saveTo, 'wb') : null;
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_USERAGENT => 'CaseManagementSystem/' . APP_VERSION,
        CURLOPT_MAXFILESIZE => UPDATE_MAX_BYTES, CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
    if ($fh) curl_setopt($ch, CURLOPT_FILE, $fh); else curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($fh) fclose($fh);
    return [$code, is_string($body) ? $body : ''];
}

/** The newest published release: ['version', 'notes', 'zip', 'sig'] (asset addresses), or null. */
function updater_latest(): ?array {
    [$code, $body] = updater_http('https://api.github.com/repos/' . UPDATE_REPO . '/releases/latest');
    $r = $code === 200 ? json_decode($body, true) : null;
    if (!is_array($r) || !preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($r['tag_name'] ?? ''), $m)) return null;
    $v = $m[1];
    $zip = $sig = null;
    foreach ((array)($r['assets'] ?? []) as $a) {
        $name = (string)($a['name'] ?? '');
        $url = (string)($a['browser_download_url'] ?? '');
        if ($name === "cms-update-$v.zip") $zip = $url;
        if ($name === "cms-update-$v.zip.sig") $sig = $url;
    }
    return ['version' => $v, 'notes' => mb_substr((string)($r['body'] ?? ''), 0, 2000), 'zip' => $zip, 'sig' => $sig];
}

/** Whether $sigPath is the publisher's signature of $zipPath. Tests: CMS_TEST_UPDATE_KEY (a PEM public key) replaces the built-in one. */
function updater_signature_ok(string $zipPath, string $sigPath): bool {
    if (!is_file($zipPath) || filesize($zipPath) > UPDATE_MAX_BYTES) return false;
    $key = openssl_pkey_get_public(updater_test_env('CMS_TEST_UPDATE_KEY') ?? UPDATE_PUBLIC_KEY);
    $sig = base64_decode(trim((string)@file_get_contents($sigPath)), true);
    $data = @file_get_contents($zipPath);
    return $key !== false && $sig !== false && $data !== false && openssl_verify($data, $sig, $key, OPENSSL_ALGO_SHA256) === 1;
}

/** Download the release's package and signature into the updates folder. Returns the package path; throws with words for staff. */
function updater_download(array $rel): string {
    if (!$rel['zip'] || !$rel['sig']) throw new RuntimeException('Release ' . $rel['version'] . ' has no update package. Ask the publisher, or update by hand.');
    $zip = updater_dir() . '/cms-update-' . $rel['version'] . '.zip';
    $drop = function () use ($zip): void { @unlink($zip); @unlink($zip . '.sig'); };
    [$c1] = updater_http((string)$rel['zip'], $zip);
    [$c2] = updater_http((string)$rel['sig'], $zip . '.sig');
    if ($c1 !== 200 || $c2 !== 200 || !is_file($zip) || filesize($zip) < 1000) { $drop(); throw new RuntimeException('The update could not be downloaded from GitHub. Try again later.'); }
    if (filesize($zip) > UPDATE_MAX_BYTES) { $drop(); throw new RuntimeException('The download is larger than any real update, so it was deleted and nothing was changed.'); }
    if (!updater_signature_ok($zip, $zip . '.sig')) { $drop(); throw new RuntimeException('The downloaded update is not signed by the publisher, so it was deleted and nothing was changed.'); }
    return $zip;
}

/** Whether a path inside the package is plain: no "..", ".", empty or absolute parts. */
function updater_safe_path(string $path): bool {
    if (!preg_match('~^(docroot|cron)/[A-Za-z0-9_.\-/]+$~', $path)) return false;
    foreach (explode('/', $path) as $seg) if ($seg === '' || $seg === '.' || $seg === '..') return false;
    return true;
}

/** The nearest folder that exists on the way to $target (where a new file's temporary copy is made). */
function updater_existing_dir(string $target): string {
    $dir = dirname($target);
    while (!is_dir($dir) && $dir !== dirname($dir)) $dir = dirname($dir);
    return $dir;
}

/**
 * Read and check a downloaded package: its manifest, every file against it, and that this site can
 * write each file it replaces. Returns ['version', 'notes', 'docroot' => [path => sha], 'cron' => [...],
 * 'remove' => [area/path], 'exec' => [area/path => true], 'problems' => [words], 'kept' => [paths kept as the site's own]].
 * Files already identical to the new version are left out.
 */
function updater_inspect(string $zipPath): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException("This server's PHP has no zip support.");
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) throw new RuntimeException('The update package could not be opened.');
    $m = json_decode((string)$z->getFromName('manifest.json'), true);
    if (!is_array($m) || !preg_match('/^\d+\.\d+\.\d+$/', (string)($m['version'] ?? '')) || !is_array($m['files'] ?? null)) { $z->close(); throw new RuntimeException('The update package has no valid list of its files.'); }
    $out = ['version' => (string)$m['version'], 'notes' => mb_substr((string)($m['notes'] ?? ''), 0, 3000), 'docroot' => [], 'cron' => [],
        'remove' => [], 'exec' => array_fill_keys(array_map('strval', (array)($m['exec'] ?? [])), true), 'problems' => [], 'kept' => []];
    $known = (array)($m['known'] ?? []);
    $root = updater_docroot();
    $cron = updater_cron_dir();
    $base = fn(string $area) => $area === 'cron' ? $cron : $root;
    $canWrite = function (string $target): bool {
        if (is_link($target)) return false;
        return is_writable(updater_existing_dir($target)) && (!is_file($target) || is_writable($target));
    };
    foreach ($m['files'] as $path => $sha) {
        $path = (string)$path;
        if (!updater_safe_path($path)) { $out['problems'][] = "The package lists an unsafe path ($path)."; continue; }
        $data = $z->getFromName($path);
        if ($data === false || !hash_equals((string)$sha, hash('sha256', $data))) { $out['problems'][] = "A file in the package does not match its list ($path)."; continue; }
        [$area, $rel] = explode('/', $path, 2);
        if ($area === 'docroot' && in_array($rel, UPDATE_KEEP, true)) continue;
        if ($area === 'cron' && ($cron === null || in_array($rel, UPDATE_CRON_KEEP, true))) continue;
        $target = $base($area) . '/' . $rel;
        $current = is_file($target) ? hash_file('sha256', $target) : null;
        if ($current === $sha) continue; // already the new version
        // A file a site may edit itself (.htaccess, .user.ini) is replaced only while it is a copy the publisher shipped.
        if ($area === 'docroot' && in_array($rel, UPDATE_SITE_EDITABLE, true) && $current !== null && !in_array($current, (array)($known[$path] ?? []), true)) { $out['kept'][] = $rel; continue; }
        $out[$area][$rel] = (string)$sha;
        if (!$canWrite($target)) $out['problems'][] = 'This site cannot write ' . ($area === 'cron' ? 'cron/' : '') . $rel . '.';
    }
    // Files the publisher removed in this or an earlier release, if this site still has them.
    foreach ((array)($m['removed'] ?? []) as $path) {
        $path = (string)$path;
        if (!updater_safe_path($path) || isset($m['files'][$path])) continue;
        [$area, $rel] = explode('/', $path, 2);
        if (($area === 'docroot' && (in_array($rel, UPDATE_KEEP, true) || in_array($rel, UPDATE_SITE_EDITABLE, true))) || ($area === 'cron' && ($cron === null || in_array($rel, UPDATE_CRON_KEEP, true)))) continue;
        $target = $base($area) . '/' . $rel;
        if (!is_file($target) || is_link($target)) continue;
        $out['remove'][] = $path;
        if (!is_writable(dirname($target))) $out['problems'][] = 'This site cannot remove ' . ($area === 'cron' ? 'cron/' : '') . $rel . '.';
    }
    $z->close();
    if (version_compare($out['version'], APP_VERSION, '<=')) $out['problems'][] = 'The package is version ' . $out['version'] . ', not newer than this site (' . APP_VERSION . ').';
    $out['problems'] = array_slice(array_values(array_unique($out['problems'])), 0, 20);
    return $out;
}

/** Remove what an install left behind: temporary copies beside the files. */
function updater_clean_temps(array $targets): void {
    foreach ($targets as $t) @unlink($t . '.cms-update-tmp');
}

/**
 * Apply a checked package. Returns report lines; throws (after putting the old files back) when it
 * cannot finish. The caller has confirmed a database backup.
 */
function updater_apply(string $zipPath, array $plan, int $userId): array {
    if ($plan['problems']) throw new RuntimeException('The update was not started: ' . implode(' ', $plan['problems']));
    if (is_file(updater_marker())) throw new RuntimeException('An earlier update did not finish. Put its files back on Software update first.');
    // One install at a time; and once started it runs to the end even if the browser goes away.
    $lock = fopen(updater_dir() . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another update is being installed right now.');
    ignore_user_abort(true);
    @set_time_limit(900);
    try {
        return updater_install($zipPath, $plan, $userId);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function updater_install(string $zipPath, array $plan, int $userId): array {
    $root = updater_docroot();
    $cron = updater_cron_dir();
    $base = fn(string $area) => $area === 'cron' ? $cron : $root;
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) throw new RuntimeException('The update package could not be opened.');
    $report = [];
    $targets = [];
    foreach (['docroot', 'cron'] as $area) foreach ($plan[$area] as $rel => $sha) $targets["$area/$rel"] = [$base($area) . '/' . $rel, $sha];
    // 1. Every new file written beside the one it replaces, and checked, before anything changes.
    try {
        foreach ($targets as $path => [$t, $sha]) {
            $data = $z->getFromName($path);
            if ($data === false || !hash_equals($sha, hash('sha256', $data))) throw new RuntimeException("$path could not be read from the package");
            if (!is_dir(dirname($t)) && !@mkdir(dirname($t), 0755, true)) throw new RuntimeException("the folder for $path could not be made");
            $tmp = $t . '.cms-update-tmp';
            if (@file_put_contents($tmp, $data) !== strlen($data)) throw new RuntimeException("$path could not be written");
            @chmod($tmp, isset($plan['exec'][$path]) ? 0755 : (is_file($t) ? (fileperms($t) & 0777) : 0644));
        }
    } catch (Throwable $e) {
        $z->close();
        updater_clean_temps(array_column($targets, 0));
        throw new RuntimeException('The update was not installed (' . ($e instanceof RuntimeException ? $e->getMessage() : 'a server error') . '). Nothing on the site was changed.');
    }
    // 2. A copy of every file replaced or removed, and a list of those added, to put back on failure.
    $backupPath = updater_dir() . '/backup-' . APP_VERSION . '-to-' . $plan['version'] . '-' . date('Ymd-His') . '.zip';
    $b = new ZipArchive();
    $added = [];
    $ok = $b->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true;
    foreach (array_merge(array_keys($targets), $plan['remove']) as $path) {
        if (!$ok) break;
        [$area, $rel] = explode('/', $path, 2);
        $f = $base($area) . '/' . $rel;
        if (is_file($f)) $ok = $b->addFile($f, $path); else $added[] = $path;
    }
    if ($ok) $ok = $b->addFromString('added.json', json_encode($added)) && $b->close();
    if (!$ok) { $z->close(); updater_clean_temps(array_column($targets, 0)); throw new RuntimeException('The backup of the current files could not be made, so nothing was changed.'); }
    $report[] = 'The current files were copied to ' . basename($backupPath) . ' in the private updates folder.';
    // 3. The database changes the new version needs, from the package's own migrations (they only add).
    $have = db_schema_version();
    $migs = [];
    foreach ($plan['docroot'] as $rel => $sha) if (preg_match('~^inc/migrations/mig_(\d+\.\d+\.\d+)\.sql$~', $rel, $mm) && ($have === null || version_compare($mm[1], $have, '>'))) $migs[$mm[1]] = [$rel, $sha];
    uksort($migs, 'version_compare');
    foreach ($migs as $v => [$rel, $sha]) {
        $tmp = $root . '/' . $rel . '.cms-update-tmp'; // already written and checked in step 1
        if (!is_file($tmp) || !hash_equals($sha, (string)hash_file('sha256', $tmp))) { $z->close(); updater_clean_temps(array_column($targets, 0)); throw new RuntimeException("The database change for version $v could not be read. No files were replaced."); }
        [$n, $err] = run_migration_file($tmp);
        if ($err !== null) { $z->close(); updater_clean_temps(array_column($targets, 0)); throw new RuntimeException("The database change for version $v stopped: $err No files were replaced. Restore the database from your backup before trying again."); }
        if (version_compare($v, (string)(db_schema_version() ?? '0'), '>')) set_setting('schema_version', $v);
        $report[] = "Database changes for version $v applied ($n).";
    }
    $z->close();
    // 4. The swap: renames only. The marker says an install is under way, so one the server cuts off is offered for undoing.
    file_put_contents(updater_marker(), json_encode(['backup' => $backupPath, 'from' => APP_VERSION, 'to' => $plan['version'], 'at' => date('c')]));
    $swapped = 0;
    try {
        foreach ($targets as [$t]) {
            if (!@rename($t . '.cms-update-tmp', $t)) throw new RuntimeException(basename($t) . ' could not be replaced');
            if (function_exists('opcache_invalidate')) @opcache_invalidate($t, true);
            $swapped++;
        }
        foreach ($plan['remove'] as $path) {
            [$area, $rel] = explode('/', $path, 2);
            if (!@unlink($base($area) . '/' . $rel)) throw new RuntimeException("$rel could not be removed");
        }
    } catch (Throwable $e) {
        updater_clean_temps(array_column($targets, 0));
        $restored = updater_restore($backupPath);
        if ($restored) @unlink(updater_marker());
        throw new RuntimeException('The update stopped (' . ($e instanceof RuntimeException ? $e->getMessage() : 'a server error') . '). ' . ($restored ? 'The previous files were put back; the site runs as before.' : 'Putting the previous files back also failed: choose Put the previous files back on Software update, or restore them from ' . basename($backupPath) . ' in the private updates folder.'));
    }
    @unlink(updater_marker());
    // Newly written files are read fresh; a server that keeps compiled pages without checking needs a restart.
    if (function_exists('opcache_get_status') && @opcache_get_status(false) && !filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN) && !@opcache_reset()) {
        $report[] = 'This server keeps compiled pages in memory: restart PHP from your hosting control panel so every page uses the new version.';
    }
    $report[] = "$swapped files replaced" . ($plan['cron'] ? ', including the scheduled jobs' : '') . ($plan['remove'] ? ', ' . count($plan['remove']) . ' old files removed' : '') . '.';
    if ($cron === null) $report[] = 'The scheduled jobs (cron) are not in the installer\'s folder on this server, so copy the new cron/ files by hand (they are in the package, in the private updates folder).';
    foreach ($plan['kept'] as $rel) $report[] = "Your $rel has your own changes, so it was kept. Compare it by hand with the new version's, docroot/$rel in the package.";
    updater_prune();
    audit($userId, 'update.apply', 'settings', null, APP_VERSION . ' → ' . $plan['version'] . ", $swapped files" . ($plan['remove'] ? ', ' . count($plan['remove']) . ' removed' : ''));
    return $report;
}

/** Keep the newest three backups and packages in the updates folder. */
function updater_prune(): void {
    foreach (['/backup-*.zip', '/cms-update-*.zip'] as $glob) {
        $files = glob(updater_dir() . $glob) ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, 3) as $f) { @unlink($f); @unlink($f . '.sig'); }
    }
}

/** Put back the files saved by an install (and remove those it added). Returns whether every one went back. */
function updater_restore(string $backupPath): bool {
    $root = updater_docroot();
    $cron = updater_cron_dir();
    $b = new ZipArchive();
    if ($b->open($backupPath) !== true) return false;
    $ok = true;
    for ($i = 0; $i < $b->numFiles; $i++) {
        $name = (string)$b->getNameIndex($i);
        if ($name === 'added.json' || !updater_safe_path($name)) continue;
        [$area, $rel] = explode('/', $name, 2);
        $base = $area === 'cron' ? $cron : $root;
        $data = $b->getFromIndex($i);
        if ($base === null || $data === false) { $ok = false; continue; }
        $t = "$base/$rel";
        if (is_file($t) && hash_file('sha256', $t) === hash('sha256', $data)) continue; // never changed
        $tmp = $t . '.cms-update-tmp';
        if (!is_dir(dirname($t))) @mkdir(dirname($t), 0755, true);
        if (@file_put_contents($tmp, $data) !== strlen($data) || !@rename($tmp, $t)) { @unlink($tmp); $ok = false; continue; }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($t, true);
    }
    foreach ((array)json_decode((string)$b->getFromName('added.json'), true) as $name) {
        if (!updater_safe_path((string)$name)) continue;
        [$area, $rel] = explode('/', (string)$name, 2);
        $base = $area === 'cron' ? $cron : $root;
        if ($base !== null && is_file("$base/$rel")) @unlink("$base/$rel");
    }
    $b->close();
    return $ok;
}
