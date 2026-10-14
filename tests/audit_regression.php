<?php
// Run against a dedicated empty MySQL/MariaDB database named cms_audit_test.
// CMS_TEST_DSN='mysql:unix_socket=/path/mysql.sock;dbname=cms_audit_test' php tests/audit_regression.php
declare(strict_types=1);
require __DIR__ . '/../public/inc/access.php';
require __DIR__ . '/../public/inc/tasks.php';
require __DIR__ . '/../public/inc/task_filters.php';
require __DIR__ . '/../public/inc/program_access.php';
$testPerms = ['clients'];
function can(string $perm): bool { return in_array($perm, $GLOBALS['testPerms'], true); }
function caseload_scoped(): bool { return true; }
// The application's own h(), taken from inc/core.php without loading the rest of
// it (configuration, database), so the escaping checks below exercise the real one.
(function (): void {
    $source = (string)file_get_contents(__DIR__ . '/../public/inc/core.php');
    $start = strpos($source, "\nfunction h(");
    $open = $start === false ? false : strpos($source, '{', $start);
    if ($open === false) throw new RuntimeException('Test harness: could not find h() in inc/core.php.');
    for ($depth = 0, $i = $open; $i < strlen($source); $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}' && --$depth === 0) break;
    }
    eval(substr($source, $start, $i - $start + 1));
})();
require __DIR__ . '/../public/inc/i18n.php';
require __DIR__ . '/../public/inc/document.php';
function db(): PDO {
    static $pdo;
    $dsn = getenv('CMS_TEST_DSN') ?: '';
    if (!preg_match('/;dbname=cms_audit_test(?:;|$)/', $dsn)) throw new RuntimeException('A dedicated cms_audit_test DSN is required.');
    return $pdo ??= new PDO($dsn, getenv('CMS_TEST_USER') ?: 'root', getenv('CMS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function denied(callable $f): bool {
    try { $f(); return false; } catch (DomainException $e) { return true; }
}
function setGroup(int $target, int $actor, ?int $group): void {
    change_user_access($target, $actor, ['group_id' => $group], function (PDO $db) use ($target, $group): void {
        $db->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([$group, $target]);
    });
}
if (($argv[1] ?? '') === 'worker') {
    usleep(100000);
    try {
        if ($argv[2] === 'loan') loan_equipment(1, 1, null, null, 1);
        else setGroup((int)$argv[2], (int)$argv[3], 1);
        echo 'success';
    } catch (DomainException $e) { echo 'denied'; }
    exit;
}
/**
 * Run the jobs in parallel worker processes that genuinely overlap: the parent
 * holds $gate (a row lock the operation needs) while both workers start, waits
 * until both are queued behind it, then releases them together. Without the gate
 * the workers could simply run one after the other, which gives the same
 * one-success-one-denial result even if the code took no lock at all.
 */
function race(array $jobs, string $gate): array {
    $db = db();
    $db->beginTransaction();
    $db->query($gate)->fetchAll();
    $running = [];
    foreach ($jobs as $args) {
        $process = proc_open(array_merge([PHP_BINARY, __FILE__, 'worker'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $running[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 10;
    do {
        usleep(50000);
        try {
            $waiting = (int)$db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn();
        } catch (PDOException $e) {
            $waiting = -1; // no PROCESS privilege: fall back to a fixed pause
            usleep(1000000);
        }
    } while ($waiting >= 0 && $waiting < count($jobs) && microtime(true) < $deadline);
    $db->commit();
    $results = [];
    foreach ($running as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($errors);
    }
    sort($results);
    return $results;
}

$row = ['id' => 1, 'is_active' => 1, 'password_hash' => 'original hash'];
$session = ['user_id' => 1, 'otp_verified' => true, 'credential_version' => credential_version($row)];
check(session_matches_user($session, $row), 'Current credentials accept a verified session');
check(!session_matches_user($session, array_replace($row, ['password_hash' => 'reset hash'])), 'Password reset invalidates a previous browser session');
check(!session_matches_user($session, array_replace($row, ['is_active' => 0])), 'Deactivation invalidates a browser session');
unset($session['credential_version']);
check(!session_matches_user($session, $row), 'Pre-upgrade sessions fail closed');

$_SESSION['lang'] = 'es'; enable_public_translation();
$services = ['Job exploration counseling', 'Work-based learning experiences', 'Counseling on postsecondary opportunities', 'Workplace readiness training', 'Instruction in self-advocacy'];
$html = '';
foreach ($services as $service) $html .= '<label><input name="preets[]" value="' . h($service) . '">' . t($service) . '</label>';
$dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$values = []; foreach ($dom->getElementsByTagName('input') as $input) $values[] = $input->getAttribute('value');
check($values === $services, 'All five Spanish service labels retain their machine values');
check(mb_stripos($html, 'consejería') !== false, 'Spanish labels are translated');
check(h('We need free software. <script>') === 'We need free software. &lt;script&gt;', 'Record text is escaped without translation');
check(h('"Quoted" & \'single\'') === '&quot;Quoted&quot; &amp; &#039;single&#039;', 'Record text escapes quotes for attribute values');
check(__('Theme: Dark') === 'Tema: oscuro' && __('Larger text') === 'Texto más grande', 'Shared display labels are translated');
$copy = t('Larger text <script>alert(1)</script>');
check(str_contains($copy, 'Texto más grande') && !str_contains($copy, '<script>') && str_contains($copy, '&lt;script&gt;'), 'Translated interface copy is still escaped');
check(str_contains(t('Untranslated interface phrase'), 'lang="en"'), 'English fallback copy declares its language');
$GLOBALS['PUBLIC_TRANSLATION'] = false;
check(page_lang() === 'en' && __('Larger text') === 'Larger text', 'Staff pages remain consistently English');

$doc = new AccessibleDocument('Test <report>');
$doc->heading('Results'); $doc->properties(['Client' => 'Synthetic only']);
$doc->table('Hours', ['Date', 'Hours'], [['2026-09-29', '2']]);
$doc->paragraph('<script>alert(1)</script>');
$dom = new DOMDocument(); @$dom->loadHTML($doc->html()); $xp = new DOMXPath($dom);
check($xp->query('/html[@lang="en"]')->length === 1 && $xp->query('//th[@scope="col"]')->length === 2 && $xp->query('//caption')->length === 1, 'Portable report declares language and table relationships');
check($xp->query('//script')->length === 0 && $xp->query('//h1')->length === 1, 'Report escapes record content and has a primary heading');

$db = db();
// Refuse to alter a database that contains application tables or unrelated data.
$existing = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($existing) throw new RuntimeException('Test database must be empty.');
try {
    // own_caseload_only: change_user_access() keeps an administrator who sees every caseload.
    $db->exec('CREATE TABLE `groups` (id INT PRIMARY KEY, perms VARCHAR(255), own_caseload_only TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $db->exec('CREATE TABLE users (id INT PRIMARY KEY, role VARCHAR(20), group_id INT NULL, is_active TINYINT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE clients (id INT PRIMARY KEY, assigned_to INT NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE equipment (id INT PRIMARY KEY, status VARCHAR(20)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE equipment_loans (id INT AUTO_INCREMENT PRIMARY KEY, equipment_id INT, client_id INT, loaned_at DATE, returned_at DATE NULL, due_date DATE NULL, notes TEXT NULL, created_by INT, FOREIGN KEY (equipment_id) REFERENCES equipment(id), FOREIGN KEY (client_id) REFERENCES clients(id)) ENGINE=InnoDB');
    $db->exec("CREATE TABLE equipment_followups (id INT AUTO_INCREMENT PRIMARY KEY, loan_id INT NOT NULL, client_id INT NOT NULL, equipment_id INT NOT NULL, interval_days INT NOT NULL, due_date DATE NOT NULL, status ENUM('Pending','Completed','Skipped') NOT NULL DEFAULT 'Pending', completed_at DATETIME NULL, notes TEXT NULL, FOREIGN KEY (loan_id) REFERENCES equipment_loans(id)) ENGINE=InnoDB");
    $db->exec("INSERT INTO `groups` (id, perms) VALUES (1, 'clients;equipment'), (2, 'admin;clients')");
    $db->exec("INSERT INTO users VALUES (1, 'admin', NULL, 1), (2, 'admin', NULL, 1), (3, 'instructor', NULL, 1)");
    $db->exec("INSERT INTO clients VALUES (1, 1), (2, 2); INSERT INTO equipment VALUES (1, 'Available'), (2, 'Available')");
    $db->exec("CREATE TABLE tasks (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190), details TEXT, client_id INT NULL, referral_id INT NULL, assigned_to INT NULL, created_by INT NULL, auto_key VARCHAR(190) UNIQUE, due_date DATE NULL, status VARCHAR(20) DEFAULT 'Open', done_at DATETIME NULL, done_by INT NULL, FOREIGN KEY (client_id) REFERENCES clients(id)) ENGINE=InnoDB");
    check(add_task('100% ready', 'literal_search', 1, null, date('Y-m-d'), null, 1), 'Manual task creation reports success');
    $taskId = (int)$db->lastInsertId();
    check(add_task('Other client', null, 2, null, null, null, 1), 'Create out-of-scope task fixture');
    $otherTaskId = (int)$db->lastInsertId();
    check(!set_task_done($otherTaskId, ['id' => 1], true), 'Cannot complete a hidden unassigned task');
    check(set_task_done($taskId, ['id' => 1], true), 'Can complete an in-scope unassigned task');
    check(set_task_done($taskId, ['id' => 1], false), 'Can reopen an in-scope task');
    $testPerms = [];
    check(!set_task_done($taskId, ['id' => 1], true), 'Client permission is required for unassigned client tasks');
    $db->exec('UPDATE tasks SET assigned_to = 1 WHERE id = ' . $taskId);
    check(set_task_done($taskId, ['id' => 1], true), 'Explicit task delegation remains supported');
    $testPerms = ['admin'];
    check(set_task_done($otherTaskId, ['id' => 1], true), 'Administrators can complete other caseload tasks');
    // Explicit delegation wins over caseload scope: a task assigned to the user
    // stays visible to them, and completable, even though client 2 is on user 2's
    // caseload (it may have been reassigned since the task was made).
    $testPerms = ['clients'];
    $db->exec("UPDATE tasks SET status = 'Open', assigned_to = 1 WHERE id = " . $otherTaskId);
    check(set_task_done($otherTaskId, ['id' => 1], true), 'An explicitly assigned task stays visible to its assignee even for a client on another caseload');
    // Once done, that task is not theirs to reopen: delegation covers an open
    // task only, so a finished one about another caseload's client stays done.
    check(!set_task_done($otherTaskId, ['id' => 1], false), 'A scoped assignee cannot reopen a done task about a client on another caseload');
    check($db->query('SELECT status FROM tasks WHERE id = ' . $otherTaskId)->fetchColumn() === 'Done', 'The refused reopen leaves the task done');
    $db->exec("UPDATE tasks SET status = 'Done', assigned_to = NULL WHERE id = " . $otherTaskId);
    // Unassigned referral tasks follow the referral's scope: a referral converted
    // into a client on another caseload (and assigned to that user) is out of
    // scope; an unassigned referral not yet converted is in scope.
    $db->exec("CREATE TABLE referrals (id INT PRIMARY KEY, assigned_to INT NULL, status VARCHAR(20), client_id INT NULL) ENGINE=InnoDB");
    $db->exec("INSERT INTO referrals VALUES (1, 2, 'Converted', 2), (2, NULL, 'New', NULL)");
    check(add_task('Converted referral follow-up', null, null, 1, null, null, 1), 'Create converted-referral task fixture');
    $convertedTaskId = (int)$db->lastInsertId();
    check(add_task('New referral follow-up', null, null, 2, null, null, 1), 'Create new-referral task fixture');
    $newReferralTaskId = (int)$db->lastInsertId();
    $testPerms = ['referrals'];
    check(!set_task_done($convertedTaskId, ['id' => 1], true), 'A scoped user cannot complete an unassigned task for a referral converted onto another caseload');
    $db->exec('UPDATE referrals SET assigned_to=1 WHERE id=1');
    check(!set_task_done($convertedTaskId, ['id'=>1], true), 'A stale referral assignee cannot access an unassigned task after the client moves caseload');
    $db->exec("UPDATE tasks SET status = 'Done' WHERE id = " . $convertedTaskId);
    check(!set_task_done($convertedTaskId, ['id' => 1], false), 'A scoped user cannot reopen an unassigned task for a referral converted onto another caseload');
    check(set_task_done($newReferralTaskId, ['id' => 1], true), 'A scoped user can complete an unassigned task for an unassigned new referral');
    // A second "complete" on a task already done changes nothing (who and when stay).
    $testPerms = ['admin'];
    $db->exec("UPDATE tasks SET done_by = 3, done_at = '2026-01-01 09:00:00' WHERE id = " . $taskId);
    check(!set_task_done($taskId, ['id' => 1], true), 'Completing a task that is already done reports no change');
    $doneRow = $db->query('SELECT status, done_by, done_at FROM tasks WHERE id = ' . $taskId)->fetch();
    check($doneRow['status'] === 'Done' && (int)$doneRow['done_by'] === 3 && $doneRow['done_at'] === '2026-01-01 09:00:00', 'A repeated completion keeps who completed the task and when');
    check(!add_task('Invalid client', null, 999, null, null, 1, 1), 'Failed task insert returns failure');
    $db->exec("UPDATE tasks SET status = 'Open', due_date = CURDATE() WHERE id = " . $taskId);
    // Open tasks that only an unescaped wildcard would match ('%' as any text, '_' as
    // any one character): the counts below hold only while % and _ are searched literally.
    $db->exec("INSERT INTO tasks (title, details, status) VALUES ('1000 units', NULL, 'Open'), ('literalXsearch', NULL, 'Open')");
    foreach (['100%' => 1, 'literal_' => 1, 'missing' => 0] as $query => $expected) {
        $params = [];
        $stmt = $db->prepare('SELECT COUNT(*) FROM tasks t WHERE 1=1' . task_filter_sql(task_filters(['q' => $query]), $params));
        $stmt->execute($params);
        check((int)$stmt->fetchColumn() === $expected, 'Task search handles ' . $query);
    }
    $db->exec("DELETE FROM tasks WHERE title IN ('1000 units', 'literalXsearch')");
    foreach (['today' => 1, 'week' => 1, 'overdue' => 0, 'none' => 0] as $due => $expected) {
        $params = [];
        $stmt = $db->prepare('SELECT COUNT(*) FROM tasks t WHERE 1=1' . task_filter_sql(task_filters(['due' => $due]), $params));
        $stmt->execute($params);
        check((int)$stmt->fetchColumn() === $expected, 'Task due filter: ' . $due);
    }
    check(task_filters(['q' => ['bad'], 'due' => ['bad'], 'show' => ['bad']]) === ['show' => 'open', 'q' => '', 'due' => 'any'], 'Malformed filters normalize safely');
    check(race([['loan'], ['loan']], 'SELECT id FROM equipment WHERE id = 1 FOR UPDATE') === ['denied', 'success'], 'Concurrent allocations allow exactly one loan');
    check((int)$db->query('SELECT COUNT(*) FROM equipment_loans WHERE returned_at IS NULL')->fetchColumn() === 1, 'Only one open loan persisted');
    $id = (int)$db->query('SELECT id FROM equipment_loans')->fetchColumn();
    check((int)$db->query('SELECT COUNT(*) FROM equipment_followups WHERE loan_id = ' . $id)->fetchColumn() === 3, 'Loan schedules three follow-ups linked to the correct loan');
    return_equipment($id);
    check((int)$db->query("SELECT COUNT(*) FROM equipment_followups WHERE loan_id = $id AND status = 'Skipped' AND notes = 'Device returned' AND completed_at IS NOT NULL")->fetchColumn() === 3, 'Returning a device closes its pending follow-ups');
    check(denied(fn() => return_equipment($id)), 'Repeated returns are rejected');
    try { loan_equipment(2, 999, null, null, 1); } catch (PDOException $e) {}
    check($db->query('SELECT status FROM equipment WHERE id = 2')->fetchColumn() === 'Available'
        && (int)$db->query('SELECT COUNT(*) FROM equipment_loans WHERE equipment_id = 2')->fetchColumn() === 0, 'Failed allocation rolls back both status and loan');
    $db->exec("INSERT INTO equipment_loans (equipment_id, client_id, loaned_at) VALUES (1,1,CURDATE()),(1,1,CURDATE()); UPDATE equipment SET status='On Loan' WHERE id=1");
    return_equipment((int)$db->query('SELECT MIN(id) FROM equipment_loans WHERE returned_at IS NULL')->fetchColumn());
    check($db->query('SELECT status FROM equipment WHERE id=1')->fetchColumn() === 'On Loan', 'Returning legacy duplicate loans keeps equipment unavailable');
    check(denied(fn() => setGroup(1, 1, 1)), 'Self-demotion through permission groups is rejected');
    check(denied(fn() => setGroup(2, 1, 999)), 'Unknown groups cannot restore fallback permissions');
    check(race([['2', '1'], ['1', '2']], 'SELECT id FROM users ORDER BY id FOR UPDATE') === ['denied', 'success'], 'Concurrent administrators cannot demote each other and leave zero admins');
    check((int)$db->query('SELECT COUNT(*) FROM users WHERE role="admin" AND group_id IS NULL')->fetchColumn() === 1, 'One effective administrator remains');
    $admin = (int)$db->query('SELECT id FROM users WHERE role="admin" AND group_id IS NULL')->fetchColumn();
    setGroup(3, $admin, 2);
    check((int)$db->query('SELECT group_id FROM users WHERE id=3')->fetchColumn() === 2, 'An instructor can acquire administrator access through a group');
    // An administrator limited to their own caseload still counts as an administrator,
    // but cannot remove the last one who sees every caseload.
    $db->exec("INSERT INTO `groups` (id, perms, own_caseload_only) VALUES (3, 'clients;admin', 1)");
    setGroup(3, $admin, 3);
    check(denied(fn() => setGroup($admin, 3, 1)), 'A caseload-limited administrator cannot demote the only administrator who sees every caseload');
    check($db->query("SELECT group_id FROM users WHERE id=$admin")->fetchColumn() === null, 'The refused demotion leaves that administrator unchanged');
    // Program cohorts (inc/program_access.php), as caseload-scoped user 1 (client 1
    // is theirs, client 2 is user 2's): the coordinator always sees their cohort;
    // anyone else only while every current member and every attendee is theirs.
    $db->exec('CREATE TABLE cohorts (id INT PRIMARY KEY, coordinator_id INT NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE cohort_members (cohort_id INT, client_id INT, left_at DATETIME NULL, PRIMARY KEY (cohort_id, client_id)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE cohort_sessions (id INT PRIMARY KEY, cohort_id INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE cohort_attendance (session_id INT, client_id INT) ENGINE=InnoDB');
    $db->exec('INSERT INTO cohorts VALUES (1, 1), (2, 2), (3, 2)');
    $db->exec('INSERT INTO cohort_members (cohort_id, client_id) VALUES (1, 2), (2, 2), (3, 1), (3, 2)');
    $db->exec('UPDATE cohort_members SET left_at = NOW() WHERE cohort_id = 1 AND client_id = 2');
    check(cohort_accessible(1, 1), "A scoped coordinator keeps their cohort after another caseload's client leaves it with no attendance recorded");
    check(!cohort_accessible(2, 1), "A cohort the user does not coordinate stays hidden while a current member is on another caseload");
    check(!cohort_accessible(3, 1), "A cohort with members on several caseloads is hidden from a scoped user who does not coordinate it");
    $db->exec('UPDATE cohort_members SET left_at = NOW() WHERE cohort_id = 3 AND client_id = 2');
    check(cohort_accessible(3, 1), 'A member on another caseload who left without attending no longer hides the cohort');
    $db->exec('INSERT INTO cohort_sessions VALUES (1, 3)');
    $db->exec('INSERT INTO cohort_attendance VALUES (1, 2)');
    check(!cohort_accessible(3, 1), "A former member's recorded attendance still hides the cohort from other caseloads");
} finally {
    foreach (['cohort_attendance', 'cohort_sessions', 'cohort_members', 'cohorts', 'tasks', 'referrals', 'equipment_followups', 'equipment_loans', 'equipment', 'clients', 'users', 'groups'] as $table) $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
echo "Audit regression checks passed.\n";
