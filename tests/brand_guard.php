<?php
// The repository is a plain product (4.31.1): no organisation's name, domain, app IDs or
// Apple team in any tracked file (4.34.0: checked on every run, since a later note brought a
// name back). The words are kept encoded so this file never matches itself. Run by run_all.sh.
$root = dirname(__DIR__);
$words = array_map('base64_decode', ['ZnVsbCA/Y2lyY2xl', 'ZmNhdA==', 'OUcyRDdDRFo1RA==', 'U2FpbnQgUGF1bA==']);
$re = '/' . implode('|', $words) . '/i';
$files = array_filter(explode("\0", (string)shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files -z')));
$hits = [];
foreach ($files as $f) {
    $p = $root . '/' . $f;
    if (!is_file($p) || filesize($p) > 5000000) continue;
    $text = (string)file_get_contents($p);
    if (str_ends_with($f, '.zip')) {
        $z = new ZipArchive();
        if ($z->open($p) === true) {
            for ($i = 0; $i < $z->numFiles; $i++) if (preg_match($re, (string)$z->getFromIndex($i))) $hits[] = $f . ' (' . $z->getNameIndex($i) . ')';
            $z->close();
        }
        continue;
    }
    foreach (preg_split('/\R/', $text) as $n => $line) if (preg_match($re, $line)) $hits[] = $f . ':' . ($n + 1);
}
if ($hits) { fwrite(STDERR, "FAIL: a former organisation's name, address or app identity is in a tracked file:\n  " . implode("\n  ", array_slice($hits, 0, 30)) . "\n"); exit(1); }
echo 'PASS: no former organisation name, address or app identity in ' . count($files) . " tracked files (archives included)\n";
