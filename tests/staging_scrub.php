<?php
// Staging scrub regression (dist/staging_scrub.php, run by dist/make_staging.sh).
// Loads dist/schema_full.sql into an EMPTY dedicated test database, seeds one row in
// every table with a marker in every column, scrubs, and checks that the marker is
// left only in the columns the scrub deliberately keeps.
//
//   CMS_TEST_DSN="mysql:host=localhost;dbname=cms_audit_test" CMS_TEST_USER=... CMS_TEST_PASSWORD=... php tests/staging_scrub.php
//
// Every table in that database is dropped at the start and at the end.
$dsn = getenv('CMS_TEST_DSN') ?: '';
if ($dsn === '') { fwrite(STDERR, "Set CMS_TEST_DSN to an empty, dedicated test database.\n"); exit(2); }
$root = dirname(__DIR__);
require $root . '/dist/staging_scrub.php';
$db = new PDO($dsn, getenv('CMS_TEST_USER') ?: '', getenv('CMS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$passed = 0;
function check(bool $ok, string $label): void { global $passed; if (!$ok) throw new RuntimeException($label); $passed++; echo "PASS: $label\n"; }
function drop_all(PDO $db): void {
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $db->exec('DROP TABLE `' . str_replace('`', '``', $t) . '`');
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
}

const MARK = 'ZQXJ7';
try {
    drop_all($db);
    $db->exec(file_get_contents($root . '/dist/schema_full.sql'));

    $cols = [];
    foreach ($db->query("SELECT table_name, column_name, data_type, column_type, character_maximum_length, extra FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position", PDO::FETCH_NUM) as $r) {
        $cols[$r[0]][$r[1]] = ['type' => strtolower($r[2]), 'ctype' => $r[3], 'len' => $r[4] === null ? null : (int)$r[4], 'extra' => strtolower((string)$r[5])];
    }
    $jsonCols = [];
    foreach ($db->query("SELECT table_name, check_clause FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()", PDO::FETCH_NUM) as $r) {
        if (preg_match_all('/json_valid\(`([^`]+)`\)/i', $r[1], $m)) foreach ($m[1] as $c) $jsonCols[$r[0]][$c] = true;
    }

    // The keep list names only columns that exist (a stale entry would hide a rename).
    $stale = [];
    foreach (STAGING_SCRUB_KEEP as $t => $keep) foreach ($keep as $c) if (!isset($cols[$t][$c])) $stale[] = "$t.$c";
    check(!$stale, 'every kept column exists in schema_full.sql' . ($stale ? ': ' . implode(', ', $stale) : ''));

    // Two rows per table (4.29.0: a second row is what made two funding agencies both
    // "[scrubbed]" and stopped the scrub on a UNIQUE key), the marker in every column that
    // takes text; the rows differ in every column, so each UNIQUE key accepts both.
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $seeded = [];
    foreach ($cols as $t => $columns) {
        foreach ([1, 2] as $row) {
            $names = []; $vals = [];
            foreach ($columns as $c => $i) {
                if (str_contains($i['extra'], 'auto_increment') || str_contains($i['extra'], 'generated')) continue;
                $ty = $i['type'];
                $text = $row === 1 ? MARK . ' note' : MARK . ' note 2';
                if (isset($jsonCols[$t][$c]) || $ty === 'json') $v = '{"m":"' . MARK . '","r":' . $row . '}';
                elseif (in_array($ty, STAGING_SCRUB_TEXT_TYPES, true)) $v = $i['len'] !== null && $i['len'] < strlen($text) ? substr(($row === 1 ? '' : '2') . MARK, 0, max(1, $i['len'])) : $text;
                elseif ($ty === 'enum' || $ty === 'set') { preg_match_all("/'((?:[^']|'')*)'/", $i['ctype'], $m); $v = str_replace("''", "'", $m[1][min($row - 1, count($m[1]) - 1)]); }
                elseif ($ty === 'date') $v = $row === 1 ? '2001-02-03' : '2001-02-04';
                elseif (in_array($ty, ['datetime', 'timestamp'], true)) $v = $row === 1 ? '2001-02-03 04:05:06' : '2001-02-04 04:05:06';
                elseif ($ty === 'time') $v = $row === 1 ? '01:02:03' : '01:02:04';
                elseif ($ty === 'year') $v = $row === 1 ? '2001' : '2002';
                else $v = (string)$row;
                $names[] = '`' . $c . '`'; $vals[] = $db->quote($v); $seeded[$t][$c][$row] = $v;
            }
            $db->exec("INSERT INTO `$t` (" . implode(', ', $names) . ') VALUES (' . implode(', ', $vals) . ')');
        }
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    check(true, 'seeded two marked rows in each of ' . count($cols) . ' tables');

    $db->exec("INSERT INTO settings (name, value) VALUES ('cfg.graph.client_secret', 'V429-JOBS synthetic secret')");
    staging_scrub($db);
    staging_admin($db, 'staging-admin@example.invalid', 'correct horse battery staple');

    $left = [];
    foreach ($cols as $t => $columns) foreach ($columns as $c => $i) {
        if (in_array($c, STAGING_SCRUB_KEEP[$t] ?? [], true)) continue;
        if (!in_array($i['type'], STAGING_SCRUB_TEXT_TYPES, true) && !isset($jsonCols[$t][$c])) continue;
        if (!isset($seeded[$t][$c])) continue;
        $n = (int)$db->query("SELECT COUNT(*) FROM `$t` WHERE BINARY `$c` IN (BINARY " . $db->quote($seeded[$t][$c][1]) . ", BINARY " . $db->quote($seeded[$t][$c][2]) . ") OR `$c` LIKE BINARY " . $db->quote('%' . MARK . '%'))->fetchColumn();
        if ($n) $left[] = "$t.$c";
    }
    check(!$left, 'no free text survives outside the keep list' . ($left ? ': ' . implode(', ', $left) : ''));

    foreach (['homework.title', 'homework.instructions', 'smart_goals.specific_goal', 'smart_goals.measurable_metric', 'smart_goals.focus_area',
              'portal_requests.details', 'client_documents.orig_name', 'client_documents.title', 'tasks.title', 'scheduled_sessions.meeting_url',
              'client_notes.subject', 'client_notes.body', 'goal_progress.note', 'invoices.bill_to'] as $tc) {
        [$t, $c] = explode('.', $tc);
        if (!isset($cols[$t][$c])) continue;
        check(!in_array($db->query("SELECT `$c` FROM `$t`")->fetchColumn(), [false, null], true)
            && !str_contains((string)$db->query("SELECT `$c` FROM `$t`")->fetchColumn(), MARK), "$tc is scrubbed");
    }
    check($db->query("SELECT name FROM clients ORDER BY id")->fetchColumn() === 'Client 1', 'client names become Client N');
    // Every UNIQUE key over a scrubbed text column keeps its rows apart (per-row values).
    foreach (['funding_agencies.name', 'offices.name', 'qbo_customers.name_key', 'sms_log.provider_sid', 'goal_progress.idem_key'] as $tc) {
        [$t, $c] = explode('.', $tc);
        if (!isset($cols[$t][$c])) continue;
        $vals = $db->query("SELECT `$c` FROM `$t` ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        check(count($vals) === 2 && count(array_unique($vals)) === 2 && !str_contains(implode(' ', $vals), MARK), "$tc: each row gets its own scrubbed value");
    }
    check((int)$db->query("SELECT COUNT(*) FROM users WHERE password_hash <> '!staging' AND email <> 'staging-admin@example.invalid'")->fetchColumn() === 0
        && (int)$db->query("SELECT COUNT(*) FROM settings WHERE name = 'cfg.graph.client_secret'")->fetchColumn() === 0, 'the scrub reached the tables after funding_agencies (passwords and stored secrets)');
    check(str_ends_with((string)$db->query("SELECT email FROM clients")->fetchColumn(), '@example.invalid'), 'client emails become example.invalid');
    check($db->query("SELECT dob FROM clients")->fetchColumn() === '2001-01-01', 'dates of birth keep the year only');
    check($db->query("SELECT due_date FROM homework")->fetchColumn() === '2001-02-03', 'ordinary dates are kept');
    foreach (STAGING_SCRUB_EMPTY as $t) if (isset($cols[$t])) check((int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() === 0, "$t is emptied");
    check((int)$db->query("SELECT COUNT(*) FROM users WHERE password_hash <> '!staging' AND email <> 'staging-admin@example.invalid'")->fetchColumn() === 0, 'every production password is unusable');
    check($db->query("SELECT value FROM settings WHERE name = 'ai_enabled'")->fetchColumn() === '0', 'AI is off on staging');
    $h = $db->query("SELECT password_hash FROM users WHERE email = 'staging-admin@example.invalid'")->fetchColumn();
    check(is_string($h) && password_verify('correct horse battery staple', $h), 'the staging administrator can sign in');

    // The shell script hands the scrub to this file and copies the private directory by allowlist.
    $sh = file_get_contents($root . '/dist/make_staging.sh');
    check(str_contains($sh, 'staging_scrub.php') && !str_contains($sh, '$plan = ['), 'make_staging.sh runs staging_scrub.php (one scrub, not two)');
    check(!preg_match('/rsync[^\n]*"\$PRIVATE_SRC\/"\s/', $sh), 'make_staging.sh never copies the whole private directory (deploy-*/ dumps stay in production)');
    check(str_contains($sh, 'trap cleanup EXIT') && str_contains($sh, 'drop_staging_tables ||') && str_contains($sh, 'DB_TOUCHED=1') && str_contains($sh, 'FINISHED=1'),
        'make_staging.sh drops the staging tables when any step fails after the load starts');
    check(str_contains($sh, 'write_placeholder_config') && str_contains($sh, 'return false;') && str_contains($sh, 'was not written correctly'),
        'make_staging.sh never lets the copy fall back to the production config.php');
} finally {
    drop_all($db);
}
echo "All $passed staging scrub checks passed.\n";
