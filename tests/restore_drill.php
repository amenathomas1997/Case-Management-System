<?php
// Restore drill (4.19.0): the whole way back from an off-site backup, on synthetic data only.
// A database is installed from schema_full.sql and given a synthetic client, a note and a document;
// it is dumped with mysqldump (as cron/backup.sh does), packed into the AES-256 ZIP the off-site job
// emails (cron/weekly_offsite.php's own offsite_zip), and restored with dist/restore.php into an
// empty database. The drill passes only if every table, the records and the document come back.
// Needs CMS_TEST_DSN (host), CMS_TEST_USER and CMS_TEST_PASSWORD, like the other database suites;
// it uses its own databases cms_restore_src and cms_restore_dst, created and dropped here.
declare(strict_types=1);
$root = dirname(__DIR__);
$dsn = getenv('CMS_TEST_DSN') ?: '';
if (!preg_match('/host=([^;]+)/', $dsn, $hm)) { echo "SKIPPED: set CMS_TEST_DSN to run the restore drill.\n"; exit(0); }
$host = $hm[1];
$user = getenv('CMS_TEST_USER') ?: 'root';
$pass = getenv('CMS_TEST_PASSWORD') ?: '';
$fails = 0;
$check = function (bool $ok, string $what) use (&$fails): void { echo ($ok ? 'PASS: ' : 'FAIL: ') . $what . "\n"; if (!$ok) $fails++; };
$tmp = sys_get_temp_dir() . '/cms_restore_drill_' . getmypid();
@mkdir("$tmp/private/backups", 0700, true);
@mkdir("$tmp/private/uploads", 0700, true);
$server = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['cms_restore_src', 'cms_restore_dst'] as $d) { $server->exec("DROP DATABASE IF EXISTS `$d`"); $server->exec("CREATE DATABASE `$d` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
$cnf = "$tmp/db.cnf";
file_put_contents($cnf, "[client]\nuser=$user\npassword=$pass\nhost=$host\n");
chmod($cnf, 0600);

// 1. A database with synthetic records, as a site has.
exec('mysql --defaults-extra-file=' . escapeshellarg($cnf) . ' cms_restore_src < ' . escapeshellarg("$root/dist/schema_full.sql") . ' 2>&1', $out, $code);
$check($code === 0, 'A database is installed from the schema' . ($code ? ': ' . implode(' ', $out) : ''));
$src = new PDO("mysql:host=$host;dbname=cms_restore_src;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$src->exec("INSERT INTO clients (name, status, phone) VALUES ('Drill Synthétic Client', 'Active Training', '515-555-0111')");
$cid = (int)$src->lastInsertId();
$src->prepare("INSERT INTO client_notes (client_id, note_type, subject, body) VALUES (?, 'Case Note', 'Drill note', ?)")->execute([$cid, "Synthetic note body with a quote ' and accents é"]);
file_put_contents("$tmp/private/uploads/drill_document.txt", 'SYNTHETIC DOCUMENT BYTES');
$tables = (int)$src->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cms_restore_src'")->fetchColumn();

// 2. The nightly dump, made as cron/backup.sh makes it.
$dump = "$tmp/private/backups/cms_drill_" . date('Ymd_His') . '.sql.gz';
// MySQL's own mysqldump (as on the CI runner) asks MariaDB for a table MariaDB lacks unless told not to.
$extra = str_contains((string)shell_exec('mysqldump --help 2>/dev/null'), 'column-statistics') ? ' --column-statistics=0' : '';
exec('bash -c ' . escapeshellarg('set -o pipefail; mysqldump --defaults-extra-file=' . escapeshellarg($cnf) . $extra . ' --single-transaction --routines --triggers cms_restore_src | gzip > ' . escapeshellarg($dump)), $out, $code);
$check($code === 0 && filesize($dump) > 1000, 'The database is dumped');

// 3. The off-site archive, with the off-site job's own function.
$cron = (string)file_get_contents("$root/cron/weekly_offsite.php");
if (!preg_match('/function offsite_zip\(.*?\n}\n/s', $cron, $fm)) { $check(false, 'offsite_zip was found in cron/weekly_offsite.php'); exit(1); }
eval($fm[0]);
$zip = offsite_zip($dump, [["$tmp/private/uploads/drill_document.txt", 'uploads/drill_document.txt']], 'synthetic-drill-password', "$tmp/private/backups");
$check($zip !== null && is_file((string)$zip), 'The encrypted off-site archive is made');

// 4. Restore into an empty database, answering the questions as a person would.
$restoreTo = "$tmp/restored_private";
$cmd = [PHP_BINARY, "$root/dist/restore.php", "--zip=$zip", "--dsn=mysql:host=$host;dbname=cms_restore_dst;charset=utf8mb4", "--db-user=$user",
    "--private=$restoreTo", '--base-url=https://drill.example.invalid', '--timezone=America/Chicago'];
$p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], "synthetic-drill-password\n$pass\n");
fclose($pipes[0]);
$said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$code = proc_close($p);
$check($code === 0, 'The restore script finishes' . ($code ? ': ' . trim($said) : ''));

// 5. Everything came back.
$dst = new PDO("mysql:host=$host;dbname=cms_restore_dst;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check((int)$dst->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cms_restore_dst'")->fetchColumn() === $tables, "All $tables tables are restored");
$check($dst->query("SELECT name FROM clients WHERE id = $cid")->fetchColumn() === 'Drill Synthétic Client'
    && $dst->query("SELECT body FROM client_notes WHERE client_id = $cid")->fetchColumn() === "Synthetic note body with a quote ' and accents é", 'The records come back exactly, accents and quotes included');
$check(@file_get_contents("$restoreTo/uploads/drill_document.txt") === 'SYNTHETIC DOCUMENT BYTES', 'The uploaded document comes back');
$check(is_file("$restoreTo/config.php") && (fileperms("$restoreTo/config.php") & 0077) === 0, 'A private config.php is written for the restored site');
// A wrong password restores nothing.
$server->exec('DROP DATABASE IF EXISTS cms_restore_dst'); $server->exec('CREATE DATABASE cms_restore_dst');
$p = proc_open(array_merge(array_slice($cmd, 0, 6), ["--private=$tmp/wrong", '--base-url=https://drill.example.invalid']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], "not-the-password\n$pass\n"); fclose($pipes[0]);
$said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$code = proc_close($p);
$check($code !== 0 && str_contains($said, 'password is wrong') && (int)$dst->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cms_restore_dst'")->fetchColumn() === 0, 'A wrong backup password restores nothing and says why');

foreach (['cms_restore_src', 'cms_restore_dst'] as $d) $server->exec("DROP DATABASE IF EXISTS `$d`");
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails restore drill check(s) FAILED\n" : "Restore drill passed.\n";
exit($fails ? 1 : 0);
