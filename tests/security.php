<?php
declare(strict_types=1);
require __DIR__ . '/../public/inc/security.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
function db(): PDO {
    static $db;
    $dsn = getenv('CMS_TEST_DSN') ?: '';
    if (!preg_match('/;dbname=cms_audit_test(?:;|$)/', $dsn)) throw new RuntimeException('Dedicated cms_audit_test database required.');
    return $db ??= new PDO($dsn, getenv('CMS_TEST_USER') ?: 'root', getenv('CMS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
}
if (($argv[1] ?? '') === 'worker') { echo claim_login_otp(1) ? 'accepted' : 'rejected'; exit; }
foreach (['/https://example.test', '//example.test', '/\\example.test', '/%2fexample.test', "/index.php\r\nX: test", 'https://example.test', ['bad']] as $bad) {
    check(safe_resume_url($bad) === '/index.php', 'Reject unsafe post-login destination');
}
check(safe_resume_url('/cms/client.php?id=7&tab=notes') === '/cms/client.php?id=7&tab=notes', 'Preserve local route and query in subdirectory installs');
check(safe_form_draft(['title'=>'Draft','password'=>'test','ai_api_key'=>'test','client_secret'=>'test','csrf'=>'test','totp_code'=>'test','signature'=>'test','confirm'=>'test','choices'=>['x']]) === ['title'=>'Draft'], 'Credentials are removed before draft persistence');
check(!sso_link_authorized(0, null, 1), 'Email-only SSO linking is denied');
check(!sso_link_authorized(1, ['id'=>2], 1), 'Another local session cannot authorize linking');
check(!sso_link_authorized(1, ['id'=>1], 2), 'Linking intent cannot target another account');
check(sso_link_authorized(1, ['id'=>1], 1), 'Explicit linking to the authenticated account is allowed');
// Every leading character a spreadsheet treats as a formula (= + - @), after any
// leading spaces or control characters, and a leading tab / CR / LF on its own.
foreach (["=1+1", "  =1+1", "\n=1+1", "@SUM(1)", " @SUM(1)", "-2+3+cmd|' /C calc'!A0", "+cmd|' /C calc'!A0",
          "\t=1", "\r=1", "\tplain", "\rplain"] as $cell) {
    check(csv_cell($cell) === "'" . $cell, 'Formula text is neutralized in CSV: ' . json_encode($cell));
}
foreach (['-12.50', '1e3', '+15', '0', 'Infinity', 'plain text'] as $cell) {
    check(csv_cell($cell) === $cell, 'Numbers and plain text pass through CSV unchanged: ' . $cell);
}
$csv = fopen('php://temp', 'r+');
$cells = ['comma, here', 'quote " here', 'backslash\\"quote', "line\nbreak"];
write_csv_row($csv, $cells); rewind($csv);
check(fgetcsv($csv, 0, ',', '"', '') === $cells, 'CSV safely round-trips quotes, backslashes, commas and newlines');
fclose($csv);
// Product rules: the system never sends text messages and never takes payments,
// so no SMS gateway or payment processor may appear anywhere in the web app, the
// scheduled jobs, the installer or the iOS app. A term counts inside identifiers
// too (send_sms, SmsGateway, StripeClient, twilio_sid): a lower-case spelling must
// not be part of a longer lower-case word ("mechanisms", "striped" are fine), and
// a capitalised one may follow a lower-case letter (camelCase: sendSms).
$productTerms = ['sms', 'twilio', 'nexmo', 'vonage', 'plivo', 'messagebird', 'textmagic', 'clicksend', 'stripe', 'paypal', 'braintree', 'squareup'];
$alternatives = ['(?i:authorize\.net)', 'PayPal', 'MessageBird', 'TextMagic', 'ClickSend', 'BrainTree', 'SquareUp'];
foreach ($productTerms as $term) {
    $alternatives[] = '(?<![a-z])' . $term;
    $alternatives[] = '(?<![A-Z])' . ucfirst($term);
    $alternatives[] = '(?<![A-Z])' . strtoupper($term);
}
$productPattern = '/(?:' . implode('|', $alternatives) . ')(?![a-z])/';
$found = [];
// The whole iOS project is scanned, not only its Swift sources: a payment or SMS
// package is declared in project.yml and an "sms" query scheme in Info.plist. The
// SQL (schema and migrations) is scanned too. Build output and per-user Xcode
// state are skipped.
foreach (['public', 'cron', 'dist', 'ios'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!preg_match('/\.(php|js|swift|sh|py|html|css|json|yml|yaml|plist|sql|strings|xcstrings|entitlements|pbxproj)$/', $file->getFilename())) continue;
        // dist/cms_dist is the generated bundle: a copy of the files scanned here.
        if (str_contains($file->getPathname(), '/dist/cms_dist/')) continue;
        if (preg_match('~/ios/(?:DerivedData|build)/|/xcuserdata/~', $file->getPathname())) continue;
        $text = (string)file_get_contents($file->getPathname());
        // The training curriculum seeded by the schema teaches clients to recognise
        // payment apps in everyday life ("PayPal or Venmo"); that is lesson text,
        // not payment processing, so only those seed lines are left out.
        if (str_ends_with($file->getFilename(), '.sql')) $text = preg_replace('/^.*\bPayPal or Venmo\b.*$/m', '', $text);
        if (preg_match($productPattern, $text, $m)) {
            $found[] = substr($file->getPathname(), strlen(dirname(__DIR__)) + 1) . ' (' . $m[0] . ')';
        }
    }
}
check($found === [], 'No SMS/texting or payment-processor code anywhere' . ($found ? ': ' . implode(', ', $found) : ''));
// Returning to a page after signing in. These helpers live in inc/bootstrap.php,
// which cannot load without the configuration and database, so just these
// functions are taken from it.
(function (): void {
    $source = (string)file_get_contents(__DIR__ . '/../public/inc/bootstrap.php');
    foreach (['is_script_request', 'stash_resume', 'take_resume_url'] as $name) {
        $start = strpos($source, "\nfunction $name(");
        $open = $start === false ? false : strpos($source, '{', $start);
        if ($open === false) throw new RuntimeException("Test harness: could not find $name() in inc/bootstrap.php.");
        for ($depth = 0, $i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') $depth++;
            elseif ($source[$i] === '}' && --$depth === 0) break;
        }
        eval(substr($source, $start, $i - $start + 1));
    }
})();
$_SESSION = ['user_id' => 5];
$_SERVER['REQUEST_URI'] = '/cms/client.php?id=7&tab=notes';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['body' => 'Synthetic draft note', 'csrf' => 'synthetic-token'];
stash_resume(5);
check(take_resume_url() === 'client.php?id=7&tab=notes' && ($_SESSION['old_input'] ?? null) === ['body' => 'Synthetic draft note'],
    'Signing in again returns to the page the account left, with what it typed (credentials removed)');
unset($_SESSION['old_input']);
stash_resume(5);
$_SESSION['user_id'] = 6;
check(take_resume_url() === 'index.php' && !isset($_SESSION['old_input']), 'Another account signing in starts at home, without the earlier account\'s input');
stash_resume(0);
check(take_resume_url() === 'client.php?id=7&tab=notes' && !isset($_SESSION['old_input']), 'A page left with no known account is resumed, but what was typed is not');
stash_resume(0);
$_SESSION['resume']['at'] = time() - 901;
check(take_resume_url() === 'index.php', 'A page left with no known account is not resumed after 15 minutes');
// What was typed is kept only for the account that left the page, and never a password.
$_SESSION = ['user_id' => 5];
$_POST = ['subject' => 'Hello', 'password' => 'x'];
stash_resume(0);
check(($_SESSION['resume']['post'] ?? null) === [], 'A page left with no known account stores none of what was typed');
check(take_resume_url() === 'client.php?id=7&tab=notes' && !isset($_SESSION['old_input']), 'That page is resumed within 15 minutes without refilling the form');
$_SESSION['resume'] = ['url' => '/cms/client.php?id=7&tab=notes', 'uid' => 0, 'post' => ['subject' => 'x'], 'at' => time()];
check(take_resume_url() === 'client.php?id=7&tab=notes' && !isset($_SESSION['old_input']), 'Typed input stored with no known account is never refilled');
$_SESSION['resume'] = ['url' => '/cms/client.php?id=7&tab=notes', 'post' => ['subject' => 'x']];
check(take_resume_url() === 'index.php' && !isset($_SESSION['old_input']), 'A stash saved before accounts were recorded is not resumed or refilled');
stash_resume(5);
check(take_resume_url() === 'client.php?id=7&tab=notes' && ($_SESSION['old_input'] ?? null) === ['subject' => 'Hello'], 'The account that left the page gets back what it typed, without the password');
$_SESSION = []; $_POST = [];
// Passkeys: the origin a browser reports never includes the install folder.
function cfg(string $key) { return $key === 'base_url' ? $GLOBALS['TEST_BASE_URL'] : null; }
require __DIR__ . '/../public/inc/webauthn.php';
foreach (['https://example.org/cms' => 'https://example.org', 'https://Example.org:443/cms/' => 'https://example.org', 'https://example.org:8443/cms' => 'https://example.org:8443'] as $base => $origin) {
    $GLOBALS['TEST_BASE_URL'] = $base;
    check(webauthn_origin() === $origin, "Passkey origin for base URL $base is $origin");
}
// What leaves for the AI provider (inc/ai.php; settings stubbed, redaction on by default).
function setting(string $name): ?string { return $GLOBALS['TEST_SETTINGS'][$name] ?? null; }
require __DIR__ . '/../public/inc/ai.php';
check(ai_redact('last note 2026-09-30, expiry 2025-11-30, (555) 123-4567, 123-45-6789', null) === 'last note 2026-09-30, expiry 2025-11-30, [PHONE], [SSN]',
    'AI redaction removes phone and Social Security numbers but keeps dates');
$redacted = ai_redact('Mr. Lopez said Rosa will call Ms. Smith. Born Mar 3, 1985, also written 3/3/1985.',
    ['name' => 'Daniel Lopez', 'guardian_name' => 'Rosa Lopez', 'counselor_name' => 'Jane Smith', 'dob' => '1985-03-03']);
check(!preg_match('/Lopez|Rosa|Smith|1985/i', $redacted) && str_contains($redacted, '[CLIENT]') && str_contains($redacted, '[GUARDIAN]') && str_contains($redacted, '[COUNSELOR]') && str_contains($redacted, '[DOB]'),
    'AI redaction removes each part of the client, guardian and counselor names and the written date of birth');
check(ai_redact('SSN 123 45 6789 or 123.45.6789; due 2026-09-30', null) === 'SSN [SSN] or [SSN]; due 2026-09-30',
    'AI redaction catches SSNs written with spaces or dots but keeps dates');
check(ai_redact('UK +44 20 7946 0958', null) === 'UK [PHONE]', 'AI redaction masks international phone numbers');
check(ai_redact('The client will practice with the long cane. Mr. Long called.', ['name' => 'Daniel Lopez', 'counselor_name' => 'Will Long']) === 'The client will practice with the long cane. Mr. [COUNSELOR] called.',
    'Name parts that are common words are not redacted as prose, only after a title');
$redacted = ai_redact("grace practised VoiceOver; Grace's dad Tom will drive. Mr. Lee asked about funding.", ['name' => 'Grace Lee', 'guardian_name' => 'Tom Lee']);
check(!preg_match('/\bGrace\b|\bTom\b|\bLee\b/', $redacted) && str_contains($redacted, '[CLIENT]') && str_contains($redacted, '[GUARDIAN]'),
    'A name part that is a common word is redacted whenever it is capitalised; a part shared with the guardian goes to the client');
check(ai_redact('maria called. Maria came. tom left.', ['name' => 'Maria Tom']) === '[CLIENT] called. [CLIENT] came. [CLIENT] left.',
    'Name parts of three or more letters that are not common words are redacted in any case');
check(ai_redact('Will called; he will practice with the long cane. LONG day.', ['name' => 'Daniel Lopez', 'counselor_name' => 'Will Long']) === '[COUNSELOR] called; he will practice with the long cane. [COUNSELOR] day.',
    'A common-word name part is redacted capitalised or in capitals, but kept in lower-case prose');
check(ai_done('x', true)['truncated'] === true && ai_done('x')['truncated'] === false, 'An AI reply cut off at the length limit is marked as truncated');
if (!getenv('CMS_TEST_DSN')) {
    // The one-time-code concurrency checks below need the test database. Without it
    // this run is INCOMPLETE, so it must not look like a pass: say so on stderr and
    // exit non-zero (2 = skipped, as opposed to 1/255 = a check failed).
    fwrite(STDERR, "\n*** SKIPPED — NOT A PASS: the database half of tests/security.php did not run. ***\n"
        . "*** Set CMS_TEST_DSN (plus CMS_TEST_USER / CMS_TEST_PASSWORD) to the dedicated EMPTY cms_audit_test database and run it again. ***\n\n");
    exit(2);
}
$db=db();
if ($db->query('SHOW TABLES')->fetchColumn()) throw new RuntimeException('Test database must be empty.');
try {
    $db->exec('CREATE TABLE login_otps (id INT PRIMARY KEY, consumed INT DEFAULT 0, attempts INT DEFAULT 0, expires_at DATETIME) ENGINE=InnoDB');
    $db->exec('INSERT INTO login_otps VALUES (1,0,0,DATE_ADD(NOW(), INTERVAL 10 MINUTE)),(2,0,0,DATE_SUB(NOW(), INTERVAL 1 MINUTE)),(3,0,5,DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
    // The workers must genuinely overlap: this process holds the code's row lock
    // while both start, waits until both are queued behind it (or, without the
    // PROCESS privilege, a fixed pause), then releases them together, as race() in
    // tests/audit_regression.php does. Without the gate they would usually run one
    // after the other, and a check-then-act claim would still pass.
    $db->beginTransaction();
    $db->query('SELECT id FROM login_otps WHERE id = 1 FOR UPDATE')->fetchAll();
    $workers=[];
    for ($i=0;$i<2;$i++) { $p=proc_open([PHP_BINARY,__FILE__,'worker'],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $workers[]=[$p,$pipes]; }
    $deadline = microtime(true) + 10;
    do {
        usleep(50000);
        try {
            $waiting = (int)$db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn();
        } catch (PDOException $e) {
            $waiting = -1;
            usleep(1000000);
        }
    } while ($waiting >= 0 && $waiting < 2 && microtime(true) < $deadline);
    $db->commit();
    $out=[];
    foreach ($workers as [$p,$pipes]) { $out[]=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]); if(proc_close($p)!==0)throw new RuntimeException($error); }
    sort($out);
    check($out===['accepted','rejected'], 'Concurrent OTP consumers yield exactly one successful claim');
    check(!claim_login_otp(1), 'Consumed OTP cannot be reused');
    check(!claim_login_otp(2), 'Expired OTP cannot be claimed');
    check(!claim_login_otp(3), 'Exhausted OTP cannot be claimed');
    // The fifth (last allowed) guess was already counted on the row before the
    // claim, so that claim may take a row at five attempts; nothing else may.
    check(claim_login_otp(3, true), 'The guess that used the last attempt can still claim its unexpired, unconsumed code');
    check(!claim_login_otp(3, true), 'A code claimed on its last attempt cannot be claimed again');
    // Re-entering the password before a sensitive account change: five wrong
    // tries in 15 minutes lock it, and while locked not even the right one is tested.
    $db->exec('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(190), password_hash VARCHAR(255)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45), email VARCHAR(190), success TINYINT NOT NULL DEFAULT 0, attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $db->prepare('INSERT INTO users VALUES (1, ?, ?)')->execute(['reauth@example.invalid', password_hash('synthetic right password', PASSWORD_DEFAULT)]);
    check(reauth_password(1, 'synthetic right password') === 'ok', 'The right password passes the re-check');
    $results = [];
    for ($i = 0; $i < 5; $i++) $results[] = reauth_password(1, 'synthetic wrong password');
    check($results === ['wrong', 'wrong', 'wrong', 'wrong', 'lockout'], 'Four wrong passwords are refused and the fifth locks the re-check');
    check(reauth_password(1, 'synthetic right password') === 'locked', 'While locked, even the right password is not tested');
} finally { foreach (['login_otps', 'login_attempts', 'users'] as $table) $db->exec('DROP TABLE IF EXISTS ' . $table); }
