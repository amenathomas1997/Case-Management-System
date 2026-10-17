<?php
// Build the signed one-button update package for a release (4.18.0), from the committed files at
// tag v<version>: cms-update-<version>.zip (docroot/ = public/, cron/, manifest.json with each
// file's SHA-256) and cms-update-<version>.zip.sig, signed with the publisher's private key.
// Usage: php dist/make_update.php 4.18.0 [private key, default private/update_signing_key.pem] [output folder]
// Then attach both files to the GitHub release v<version> (gh release create/upload).
declare(strict_types=1);
$version = $argv[1] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) { fwrite(STDERR, "Usage: php dist/make_update.php <version> [key.pem] [out dir]\n"); exit(1); }
$root = dirname(__DIR__);
$keyFile = $argv[2] ?? $root . '/private/update_signing_key.pem';
$outDir = $argv[3] ?? $root . '/.audit_work/release-' . $version;
$tag = 'v' . $version;
chdir($root);
$git = fn(string $args): string => (string)shell_exec('git ' . $args . ' 2>/dev/null');
if (trim($git('rev-parse -q --verify ' . escapeshellarg("refs/tags/$tag"))) === '') { fwrite(STDERR, "No tag $tag.\n"); exit(1); }
// The version the tagged code says it is must be the one being published.
if (!preg_match("/const APP_VERSION = '" . preg_quote($version, '/') . "';/", $git('show ' . escapeshellarg("$tag:public/inc/core.php")))) { fwrite(STDERR, "public/inc/core.php at $tag is not version $version.\n"); exit(1); }
$key = openssl_pkey_get_private((string)@file_get_contents($keyFile));
if ($key === false) { fwrite(STDERR, "The private key $keyFile could not be read.\n"); exit(1); }
// Never shipped: this publisher's own feed, and each site's own cron settings.
$skip = ['public/updates.json', 'public/inc/config_path.php', 'cron/cron_config.php', 'cron/backup.conf'];
$files = array_values(array_filter(explode("\n", trim($git('ls-tree -r --name-only ' . escapeshellarg($tag) . ' -- public cron'))),
    fn($f) => $f !== '' && !in_array($f, $skip, true) && !str_ends_with($f, '.DS_Store')));
$pkgName = fn(string $f): string => str_starts_with($f, 'public/') ? 'docroot/' . substr($f, 7) : $f;
// Scripts that must stay executable (git mode 100755), such as cron/backup.sh.
$exec = [];
foreach (explode("\n", trim($git('ls-tree -r ' . escapeshellarg($tag) . ' -- public cron'))) as $line) {
    if (preg_match('/^100755 blob \S+\t(.+)$/', $line, $mm)) $exec[] = $pkgName($mm[1]);
}
// Every earlier release: the files it shipped that this one no longer has (the updater removes them),
// and each copy it shipped of the files a site may edit (replaced only while still one of these).
$known = ['docroot/.htaccess' => [], 'docroot/.user.ini' => []];
$earlier = [];
foreach (array_filter(explode("\n", trim($git("tag -l 'v*'")))) as $t) {
    if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $t, $tm) || version_compare($tm[1], $version, '>=')) continue;
    foreach (array_filter(explode("\n", trim($git('ls-tree -r --name-only ' . escapeshellarg($t) . ' -- public cron')))) as $f) $earlier[$pkgName($f)] = true;
    foreach (['public/.htaccess', 'public/.user.ini'] as $f) {
        $d = shell_exec('git show ' . escapeshellarg("$t:$f") . ' 2>/dev/null');
        if (is_string($d) && $d !== '') $known[$pkgName($f)][] = hash('sha256', $d);
    }
}
foreach ($known as $k => $list) $known[$k] = array_values(array_unique($list));
$current = array_fill_keys(array_map($pkgName, $files), true);
// The order files are swapped in (every updater follows the package's list): files new in this release
// first, so nothing that requires them arrives before them; then the rest; the files every page loads
// (bootstrap.php, core.php) last, once everything they require is in place.
$isNew = fn(string $f) => !isset($earlier[$pkgName($f)]);
$last = ['public/inc/core.php' => 2, 'public/inc/bootstrap.php' => 1];
usort($files, fn($a, $b) => [$last[$a] ?? 0, $isNew($a) ? 0 : 1, $a] <=> [$last[$b] ?? 0, $isNew($b) ? 0 : 1, $b]);
$removed = array_values(array_filter(array_keys($earlier), fn($f) => !isset($current[$f]) && !in_array(preg_replace('~^docroot/~', 'public/', $f), $skip, true)));
// The release notes, from the tagged code itself, so what the site shows is signed too.
$notesFile = tempnam(sys_get_temp_dir(), 'notes');
file_put_contents($notesFile, $git('show ' . escapeshellarg("$tag:public/inc/release_notes.php")));
$allNotes = (array)(include $notesFile);
@unlink($notesFile);
$notes = implode("\n", array_map(fn($b) => '- ' . $b, (array)($allNotes[$version] ?? [])));
@mkdir($outDir, 0700, true);
$zipPath = "$outDir/cms-update-$version.zip";
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) { fwrite(STDERR, "Cannot write $zipPath\n"); exit(1); }
$manifest = ['version' => $version, 'tag' => $tag, 'commit' => trim($git('rev-list -n1 ' . escapeshellarg($tag))), 'notes' => $notes,
    'known' => $known, 'removed' => $removed, 'exec' => $exec, 'files' => []];
foreach ($files as $f) {
    $data = (string)shell_exec('git show ' . escapeshellarg("$tag:$f"));
    $name = $pkgName($f);
    $zip->addFromString($name, $data);
    $manifest['files'][$name] = hash('sha256', $data);
}
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$zip->close();
openssl_sign((string)file_get_contents($zipPath), $sig, $key, OPENSSL_ALGO_SHA256);
file_put_contents("$zipPath.sig", base64_encode($sig) . "\n");
$pub = openssl_pkey_get_details($key)['key'];
$ok = openssl_verify((string)file_get_contents($zipPath), $sig, openssl_pkey_get_public($pub), OPENSSL_ALGO_SHA256) === 1;
echo "Built $zipPath (" . count($files) . " files) and its signature" . ($ok ? ", verified" : ", BUT IT DID NOT VERIFY") . ".\n";
exit($ok ? 0 : 1);
