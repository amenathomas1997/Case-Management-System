<?php
// Isolated integration checks. Requires an EMPTY cms_audit_test database.
declare(strict_types=1);
$dsn = getenv('CMS_TEST_DSN') ?: '';
if (!preg_match('/;dbname=cms_audit_test(?:;|$)/', $dsn)) throw new RuntimeException('Dedicated cms_audit_test database required.');
$root = dirname(__DIR__);
if (in_array(($argv[1] ?? ''), ['page','page_post'], true)) {
    $_GET = json_decode($argv[3], true); $_REQUEST = $_GET;
    $_SERVER += ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/index.php','HTTPS'=>'on','HTTP_HOST'=>'example.invalid','REMOTE_ADDR'=>'127.0.0.1'];
    ini_set('session.save_path', dirname($argv[2]));
    session_name('cms'); session_start();
    // CMS_TEST_ANON renders the page for a visitor who is not signed in;
    // CMS_TEST_SESSION (JSON) then gives that visitor's session, such as a
    // sign-in waiting on its one-time code.
    $_SESSION = getenv('CMS_TEST_ANON') ? (json_decode((string)getenv('CMS_TEST_SESSION'), true) ?: []) : ['user_id'=>1,'otp_verified'=>true,'credential_version'=>hash('sha256','test'),'last_activity'=>time()];
    if ($argv[1] === 'page_post') {
        $_SERVER['REQUEST_METHOD']='POST';
        $_POST=json_decode($argv[4],true)+['csrf'=>'synthetic-csrf'];
        $_SESSION['csrf']='synthetic-csrf';
    }
    session_write_close();
    assert_isolated_page($argv[2]);
    require $argv[2];
    exit;
}
if (($argv[1] ?? '') === 'api') {
    $_GET = json_decode($argv[3], true);
    // CMS_TEST_TOKEN replaces the signed-in admin's token (unknown, expired, inactive user).
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (getenv('CMS_TEST_TOKEN') ?: str_repeat('a', 64));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    assert_isolated_page($argv[2]);
    require $argv[2];
    exit;
}
$db = new PDO($dsn, getenv('CMS_TEST_USER') ?: 'root', getenv('CMS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
if ($db->query('SHOW TABLES')->fetchColumn()) throw new RuntimeException('Test database must be empty.');
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
/**
 * str_replace() that fails when nothing was replaced. The harness rewrites each
 * page's include of inc/bootstrap.php or inc/core.php to a temporary copy wired
 * to the test database. If such a line were ever reworded, a plain str_replace
 * would silently do nothing and the page would load the REAL inc/core.php — and
 * through it the real private config and database. So the text must be found,
 * and afterwards no include of the real bootstrap/core may remain.
 */
function swap_required(string $needle, string $replacement, string $source, string $what = 'the source'): string {
    $count = 0;
    $out = str_replace($needle, $replacement, $source, $count);
    if ($count < 1) throw new RuntimeException("Test harness: expected [$needle] in $what but did not find it. Refusing to continue: unmodified, it would run against the real configuration.");
    if (has_real_include($out)) throw new RuntimeException("Test harness: $what still includes the real inc/bootstrap.php or inc/core.php after the swap. Refusing to continue.");
    return $out;
}
/** True when PHP source still names the app's own bootstrap.php / core.php as a path literal. */
function has_real_include(string $source): bool {
    return preg_match('#[\'"]/(?:inc/)?(?:bootstrap|core)\.php[\'"]#', $source) === 1;
}
/** Last line of defence in the child process, whatever the caller did or forgot. */
function assert_isolated_page(string $file): void {
    $source = (string)@file_get_contents($file);
    if ($source === '' || has_real_include($source)) {
        fwrite(STDERR, "Test harness: $file is missing or would include the real inc/bootstrap.php or inc/core.php. Refusing to run it.\n");
        exit(1);
    }
}
$tmp = sys_get_temp_dir() . '/cms-integration-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
try {
    $db->exec(file_get_contents($root . '/dist/schema_full.sql'));
    // Synthetic secrets: no page, API response or export may ever show them.
    $secrets = ['entra'=>'synthetic-entra-client-secret-5b2c', 'ai'=>'synthetic-ai-provider-key-9d41'];
    $config = ['db'=>['dsn'=>$dsn,'user'=>getenv('CMS_TEST_USER') ?: 'root','pass'=>getenv('CMS_TEST_PASSWORD') ?: ''], 'app'=>['timezone'=>'America/Chicago','upload_dir'=>$tmp,'base_url'=>'https://example.invalid','name'=>'Test CMS','session_timeout_minutes'=>30],
        'entra'=>['enabled'=>false,'tenant_id'=>'','client_id'=>'','client_secret'=>$secrets['entra'],'auto_provision_group'=>'']];
    $core = file_get_contents($root . '/public/inc/core.php');
    $start = strpos($core, '$configPaths =');
    $end = $start === false ? false : strpos($core, 'const APP_VERSION', $start);
    if ($start === false || $end === false) throw new RuntimeException('Test harness: could not find the $configPaths block in inc/core.php. Refusing to continue: the copy would load the real configuration.');
    $core = substr($core, 0, $start) . '$CONFIG = ' . var_export($config, true) . ";\n" . substr($core, $end);
    $core = str_replace('__DIR__', var_export($root . '/public/inc', true), $core);
    file_put_contents($tmp . '/core.php', $core);
    $api = file_get_contents($root . '/public/api.php');
    $api = swap_required("require __DIR__ . '/inc/core.php';", 'require ' . var_export($tmp . '/core.php', true) . ';', $api, 'public/api.php');
    $api = swap_required("file_get_contents('php://input')", "getenv('CMS_TEST_INPUT')", $api, 'public/api.php');
    $api = str_replace('__DIR__', var_export($root . '/public', true), $api);
    file_put_contents($tmp . '/api.php', $api);
    $db->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES (1,'Test Admin','admin@example.invalid','test','admin')");
    $db->prepare("INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (1,?,DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([hash('sha256', str_repeat('a',64))]);
    $db->exec("INSERT INTO clients (id,name) VALUES (1,'Kept synthetic client'),(2,'Duplicate synthetic client')");
    $db->exec("INSERT INTO training_tracks (id,client_id,title,auth_number,funding_agency,hourly_rate) VALUES (1,1,'Synthetic track','TEST','State VR',95)");
    $db->exec("ALTER TABLE audit_log AUTO_INCREMENT=1000");
    foreach (['equipment','worksite_assessments','device_requests','cohorts','contracts','access_bookings'] as $table) $db->exec("ALTER TABLE `$table` AUTO_INCREMENT=1");
    $call = function(string $action, array $input=[], array $query=[], bool $expectOk=true) use ($tmp): array {
        putenv('CMS_TEST_INPUT=' . json_encode($input));
        $p = proc_open([PHP_BINARY, __FILE__, 'api', $tmp . '/api.php', json_encode(['action'=>$action]+$query)], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($p);
        $data=json_decode($output,true);
        if ($code!==0 || !is_array($data) || (bool)($data['ok'] ?? false) !== $expectOk) throw new RuntimeException("$action failed: $errors $output");
        return $data;
    };
    foreach ([
        ['add_equipment','equipment',['name'=>'Synthetic equipment']],
        ['add_worksite','worksite_assessments',['client_id'=>2]],
        ['add_device_request','device_requests',['client_id'=>2,'device_name'=>'Synthetic device']],
        ['add_cohort','cohorts',['name'=>'Synthetic cohort']],
        ['add_contract','contracts',['name'=>'Synthetic contract']],
        ['add_booking','access_bookings',['client_id'=>2]],
    ] as [$action,$table,$input]) {
        $result=$call($action,$input);
        check((int)$result['id']===(int)$db->query("SELECT MAX(id) FROM `$table`")->fetchColumn(), "$action returns its record ID, not its audit ID");
    }
    $fixtures=[];
    // Both session shapes the schedule sends (one ended but never closed, one
    // upcoming remote session on a track), so the iOS decoding check runs on
    // real rows. They are removed again once the fixture is captured.
    $db->exec("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,location,is_remote,meeting_url) VALUES
        (1,NULL,1,DATE_SUB(NOW(),INTERVAL 2 DAY),DATE_SUB(NOW(),INTERVAL 47 HOUR),'Synthetic office',0,NULL),
        (1,1,1,DATE_ADD(NOW(),INTERVAL 30 DAY),DATE_ADD(NOW(),INTERVAL 721 HOUR),NULL,1,'https://example.invalid/meet/synthetic')");
    foreach (['dashboard','clients','tracks','schedule','equipment','counselors','referrals','staff','me','reservations','instruments','curricula','worksite_assessments','device_requests','cohorts','contracts','bookings','kb_articles','compliance','followups','message_threads','incidents','utilization'] as $action) {
        $result = $call($action); check(true, "$action reads the installation schema");
        // The app's core screens decode these (tests/ios_models.py).
        if (in_array($action, ['clients','tracks','schedule','me'], true)) $fixtures[$action] = $result;
    }
    $shapes=array_map(fn(array $row):string=>json_encode([$row['overdue'],$row['is_remote'],$row['track_id']!==null]),$fixtures['schedule']['sessions']);
    check($shapes===['[true,false,false]','[false,true,true]'],'The schedule fixture holds an overdue session and an upcoming remote one');
    $db->exec('DELETE FROM scheduled_sessions');
    $call('expenses',[],['track_id'=>1]); check(true, 'expenses reads the installation schema');
    $noteInput=['client_id'=>1,'track_id'=>1,'subject'=>'Synthetic note','body'=>'Synthetic content','idem_key'=>'11111111-1111-4111-8111-111111111111'];
    $call('add_note',$noteInput); $call('add_note',$noteInput);
    check((int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn()===1,'Replaying an offline note does not duplicate it');
    $db->exec("CREATE TRIGGER test_homework_failure BEFORE INSERT ON homework FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic homework failure'");
    $failedNote = array_replace($noteInput, ['idem_key'=>'33333333-3333-4333-8333-333333333333', 'homework'=>[['title'=>'Synthetic assignment']]]);
    $call('add_note', $failedNote, [], false);
    check((int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn()===1, 'Homework storage failure rolls back the associated note');
    $db->exec('DROP TRIGGER test_homework_failure');
    $noteId=(int)$db->query('SELECT id FROM client_notes')->fetchColumn();
    $call('update_note',['id'=>$noteId,'track_id'=>0,'subject'=>'Updated synthetic note','body'=>'Updated content']);
    check($db->query('SELECT track_id FROM client_notes')->fetchColumn()===null,'Editing a note can remove its training-track attachment');
    $call('update_note',['id'=>$noteId,'track_id'=>1,'subject'=>'Updated synthetic note','body'=>'Updated content']);
    check((int)$db->query('SELECT track_id FROM client_notes')->fetchColumn()===1,'Editing a note can attach its own training track');
    // A structured note saved on the website keeps its Narrative part when the app
    // edits one section and sends no body; a section the app does not send is kept.
    $db->prepare("UPDATE client_notes SET note_format='BIRP', birp_behavior='b', birp_intervention='', birp_response='r', birp_plan='p', body=? WHERE id=?")
        ->execute(["Behavior:\nb\n\nResponse:\nr\n\nPlan:\np\n\nNarrative:\nweb narrative", $noteId]);
    $call('update_note',['id'=>$noteId,'subject'=>'Updated synthetic note','note_format'=>'BIRP','sec1'=>'b2']);
    $nrow=$db->query('SELECT * FROM client_notes')->fetch(PDO::FETCH_ASSOC);
    check($nrow['birp_behavior']==='b2' && $nrow['birp_plan']==='p' && str_ends_with((string)$nrow['body'],"Narrative:\nweb narrative"),'An app edit of one BIRP section keeps the other sections and the website narrative');
    $call('update_note',['id'=>$noteId,'subject'=>'Updated synthetic note','note_format'=>'free','body'=>'Updated content','sec1'=>'','sec2'=>'','sec3'=>'','sec4'=>'']);
    check($db->query('SELECT body FROM client_notes')->fetchColumn()==='Updated content','An app edit that sends a body replaces it');
    $call('log_hours',['track_id'=>1,'hours'=>1.5,'date'=>'2026-01-01','description'=>'Synthetic hours','idem_key'=>'22222222-2222-4222-8222-222222222222']);
    $call('log_hours',['track_id'=>1,'hours'=>1.5,'date'=>'2026-01-01','description'=>'Synthetic hours','idem_key'=>'22222222-2222-4222-8222-222222222222']);
    check((int)$db->query('SELECT COUNT(*) FROM work_logs')->fetchColumn()===1,'Replaying offline hours does not duplicate billing');
    $db->exec("INSERT INTO assessment_instruments (id,name,scale_min,scale_max) VALUES (999,'Synthetic negative scale',-3,3)");
    $db->exec("INSERT INTO assessment_instrument_items (id,instrument_id,label) VALUES (999,999,'Synthetic item')");
    $options=$call('instrument_items',[],['id'=>999]);
    check(array_column($options['scale_options'],'value')===range(-3,3),'API exposes the complete configured assessment scale');
    $call('save_scored',['client_id'=>1,'instrument_id'=>999,'scores'=>['999'=>-1]]);
    $score=$db->query('SELECT total_score,max_score,pct FROM client_scored_assessments WHERE instrument_id=999')->fetch();
    check((int)$score['total_score']===-1 && (int)$score['max_score']===3 && (float)$score['pct']===33.3,'Mobile assessments preserve negative scores and normalized percentages');
    $db->exec("UPDATE equipment SET value=125.50 WHERE id=1; UPDATE access_bookings SET cost=15.25; UPDATE device_requests SET authorized_amount=800.75; UPDATE contracts SET amount=1000.50");
    $db->exec("INSERT INTO equipment_maintenance (equipment_id,service_date,service_type,cost) VALUES (1,'2026-01-01','Maintenance',10.50)");
    $db->exec("INSERT INTO device_request_quotes (request_id,vendor,amount) VALUES (1,'Synthetic vendor',750.25)");
    $db->exec("INSERT INTO contract_spend (contract_id,spent_on,amount) VALUES (1,'2026-01-01',20.50)");
    $db->exec("INSERT INTO messages (client_id,channel,sender,body) VALUES (1,'client','client','Synthetic message')");
    foreach (['contracts','contract','bookings','device_requests','device_request','equipment_detail','message_threads','scored_assessments','scored_assessment','instrument_items','client'] as $action) {
        $id = $action==='instrument_items' ? 999 : ($action==='scored_assessment' ? (int)$db->query('SELECT id FROM client_scored_assessments WHERE instrument_id=999')->fetchColumn() : 1);
        $fixtures[$action]=$call($action,[],['id'=>$id,'client'=>1]);
    }
    // The home screen (DashboardData has its own decoder) and the other list
    // screens, read now that notes, hours and a worksite assessment exist. One
    // expense is added for the track's expense list and removed again, so the
    // invoices below bill exactly what they did before.
    foreach (['dashboard','followups','incidents','utilization','compliance','counselors','worksite_assessments'] as $action) $fixtures[$action]=$call($action);
    $db->exec("INSERT INTO track_expenses (track_id,expense_date,description,amount) VALUES (1,'2026-01-02','Synthetic fixture expense',4.50)"); $fixtureExpense=(int)$db->lastInsertId();
    $fixtures['expenses']=$call('expenses',[],['track_id'=>1]);
    $db->exec("DELETE FROM track_expenses WHERE id=$fixtureExpense");
    check(count($fixtures['expenses']['expenses'])===1 && count($fixtures['dashboard']['recent_notes'] ?? [])>=1,'The expense and dashboard fixtures hold populated rows');
    $p=proc_open(['python3',$root . '/tests/ios_models.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($fixtures)); fclose($pipes[0]);
    echo stream_get_contents($pipes[1]); fclose($pipes[1]);
    $modelErrors=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if ($modelErrors !== '') fwrite(STDERR, $modelErrors);
    check(proc_close($p)===0,'Native iOS models decode populated API responses');
    require $tmp . '/core.php';
    // As db() does for the app: SQL NOW()/CURDATE() on this connection agree with PHP's date().
    $db->exec('SET time_zone = ' . $db->quote(date('P')));
    // A year typed with too few digits is refused, not stored as year 26; a value
    // that is not a string (a query-string array, a number) is simply invalid.
    check(valid_date('0026-10-08', 2000, (int)date('Y') + 5) === null && valid_date('2026-10-08', 2000, (int)date('Y') + 5) === '2026-10-08'
        && valid_date(['2026-10-08']) === null && valid_date(20261008) === null, 'Dates outside the allowed years, and values that are not text, are refused');
    // Progress reports fall due on the same date on the compliance page, in the app
    // and in cron/monthly_reports.php: one cadence period after the last report, and
    // a monthly report on the next 1st of a month (or after 45 days if sooner).
    check(report_due_date('weekly', '2026-01-05') === '2026-01-12' && report_due_date('biweekly', '2026-01-05') === '2026-01-19'
        && report_due_date('monthly', '2026-01-01') === '2026-02-01' && report_due_date('monthly', '2026-01-20 10:00:00') === '2026-03-01'
        && report_due_date('monthly', '2026-01-05') === '2026-02-19', 'Progress report due dates follow the cadence and the 1st-of-month rhythm');
    $invoiceId=generate_invoice(1,1);
    check($invoiceId!==null && generate_invoice(1,1)===null,'Invoice generation bills logged work once');
    $issued=$db->query('SELECT auth_number,funding_agency,hourly_rate,period_start,period_end FROM invoices WHERE id=' . $invoiceId)->fetch();
    check($issued['auth_number']==='TEST' && $issued['funding_agency']==='State VR' && (float)$issued['hourly_rate']===95.0 && $issued['period_start']==='2026-01-01' && $issued['period_end']==='2026-01-01',
        'An invoice keeps the authorization, funder, rate and billing period it was issued under');
    check((float)$db->query('SELECT total_amount FROM invoices WHERE id=' . $invoiceId)->fetchColumn()===142.5,'Invoice total bills the logged hours');
    // Each log is billed at the rates captured when it was logged, mileage and
    // expenses are billed with it, and nothing is billed twice. (Client 2, so
    // client 1's export below is unchanged.)
    $db->exec("INSERT INTO training_tracks (id,client_id,title,auth_number,funding_agency,hourly_rate,mileage_rate) VALUES (50,2,'Invoice rate check','INV-AUTH','Invoice agency',100,0.5)");
    $call('log_hours',['track_id'=>50,'hours'=>2,'mileage'=>10,'date'=>'2026-01-02','description'=>'Logged at the first rates']);
    $db->exec("UPDATE training_tracks SET hourly_rate=120, mileage_rate=0.75 WHERE id=50");
    $db->exec("INSERT INTO track_expenses (track_id,expense_date,description,amount) VALUES (50,'2026-01-03','Synthetic expense',15.25)");
    $billed=generate_invoice(50,1);
    $row=$db->query('SELECT hours_amount,mileage_amount,expenses_amount,total_amount FROM invoices WHERE id=' . (int)$billed)->fetch();
    check($billed!==null && (float)$row['hours_amount']===200.0 && (float)$row['mileage_amount']===5.0 && (float)$row['expenses_amount']===15.25 && (float)$row['total_amount']===220.25,
        'Invoice bills hours and mileage at the rates captured when they were logged, not the current rates, plus expenses');
    check(generate_invoice(50,1)===null,'Billed work and expenses are not invoiced again');
    $call('log_hours',['track_id'=>50,'hours'=>1,'date'=>'2026-01-04','description'=>'Logged at the new rate']);
    $next=generate_invoice(50,1);
    check($next!==null && (float)$db->query('SELECT total_amount FROM invoices WHERE id=' . (int)$next)->fetchColumn()===120.0,'Work logged after an invoice goes on the next one, at the rate in force when it was logged');
    $db->exec("INSERT INTO track_expenses (track_id,expense_date,description,amount) VALUES (50,'2026-01-05','Expense only',9.99)");
    $only=generate_invoice(50,1);
    $row=$db->query('SELECT hours_amount,mileage_amount,expenses_amount,total_amount FROM invoices WHERE id=' . (int)$only)->fetch();
    check($only!==null && (float)$row['hours_amount']===0.0 && (float)$row['mileage_amount']===0.0 && (float)$row['expenses_amount']===9.99 && (float)$row['total_amount']===9.99,'An expenses-only invoice bills just the expenses');
    require __DIR__.'/trash_cases.php';
    // The app's expense list and delete: an unbilled expense goes to the trash; one
    // already on an invoice is refused and stays, so the invoice totals still match.
    $db->exec("INSERT INTO track_expenses(track_id,expense_date,description,amount) VALUES(50,'2026-01-08','App delete expense',3.25)"); $appExpense=(int)$db->lastInsertId();
    $appBilled=(int)$db->query('SELECT id FROM track_expenses WHERE track_id=50 AND is_billed=1 LIMIT 1')->fetchColumn();
    $listed=array_column($call('expenses',[],['track_id'=>50])['expenses'],'is_billed','id');
    $call('delete_expense',['id'=>$appExpense]);
    $call('delete_expense',['id'=>$appBilled],[],false);
    check($appBilled>0 && ($listed[$appExpense]??null)===false && ($listed[$appBilled]??null)===true
        && (int)$db->query("SELECT COUNT(*) FROM track_expenses WHERE id=$appExpense")->fetchColumn()===0
        && (int)$db->query("SELECT COUNT(*) FROM trash WHERE entity='track_expenses' AND entity_id=$appExpense")->fetchColumn()===1
        && (int)$db->query("SELECT COUNT(*) FROM track_expenses WHERE id=$appBilled")->fetchColumn()===1,
        'The app lists a track\'s expenses, deletes an unbilled one to the trash, and refuses to delete one already on an invoice');
    // A sign-in email template edited without {code} would lock people out, so the
    // built-in wording is sent instead; one that keeps {code} is used as written.
    set_setting('tpl_otp_body','Hello {name}');
    check(str_contains(email_template('otp',['name'=>'X','code'=>'123456','minutes'=>10])[1],'123456'),'A sign-in email template without {code} still sends the code');
    set_setting('tpl_otp_body','Code {code}');
    check(email_template('otp',['name'=>'X','code'=>'123456','minutes'=>10])[1]==='Code 123456','A sign-in email template that keeps {code} is used as written');
    $db->exec("DELETE FROM settings WHERE `name`='tpl_otp_body'");
    $db->exec("INSERT INTO tech_assessments (id,client_id,assessment_date,primary_tech) VALUES (1,1,'2026-01-01','Screen Reader')");
    $db->exec("INSERT INTO client_documents (client_id,category,title,orig_name,path) VALUES (1,'Other','Synthetic missing document','missing.txt','missing.txt')");
    $bootstrap = file_get_contents($root . '/public/inc/bootstrap.php');
    $bootstrap = swap_required("require __DIR__ . '/core.php';", 'require ' . var_export($tmp . '/core.php', true) . ';', $bootstrap, 'public/inc/bootstrap.php');
    $bootstrap = str_replace('__DIR__', var_export($root . '/public/inc', true), $bootstrap);
    file_put_contents($tmp . '/bootstrap.php', $bootstrap);
    // A stored provider key or Entra client secret must never reach a page, an API
    // response or an export. Both are stored where production keeps them: the
    // settings table (federation.php saves the Entra secret there).
    $db->prepare("INSERT INTO settings(`name`,value) VALUES ('ai_api_key',?),('entra_client_secret',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$secrets['ai'],$secrets['entra']]);
    foreach (['me','dashboard'] as $action) {
        $shown=json_encode($call($action));
        check(!str_contains($shown,$secrets['ai']) && !str_contains($shown,$secrets['entra']),"API $action never returns a stored secret");
    }
    // Each page must show its own content, not merely finish without a PHP error: a
    // page that redirected, refused or printed nothing would otherwise pass. The
    // marker is a fragment of the page's own <title> (a regular expression when it
    // starts with ~); 'redirect' marks the pages that only redirect a signed-in
    // user, and 'pdf'/'zip' the downloads. A page without an entry fails.
    $db->exec("INSERT INTO kb_articles (id,title,body) VALUES (1,'Synthetic knowledge article','Synthetic article body')");
    $pageMarkers = ['index'=>'Dashboard','clients'=>'Clients','client'=>'Kept synthetic client','client_edit'=>'Edit Kept synthetic client','track'=>'Synthetic track',
        'track_edit'=>'Synthetic track','tasks'=>'Tasks','referrals'=>'Referrals','pipeline'=>'Pipeline','schedule'=>'Schedule','capacity'=>'Capacity',
        'access_bookings'=>'Accommodation bookings','equipment'=>'Equipment','followups'=>'Device follow-ups','procurement'=>'~Device request|Procurement~',
        'programs'=>'~Cohort|Programs~','contracts'=>'Contract','invoices'=>'Invoices','analytics'=>'Analytics','outcomes'=>'Outcome report','compliance'=>'Compliance',
        'incidents'=>'Incidents','messages'=>'Messages','curriculum'=>'~Competency|Curricul~','templates'=>'Templates','score_assessment'=>'Scored assessment',
        'accommodations'=>'~Worksite assessment|Accommodations~','users'=>'Users','account'=>'My Account','audit'=>'Audit log','trash'=>'Trash','client_merge'=>'Merge clients',
        'counselors'=>'Counselor portal','portal_requests'=>'Client requests','email_templates'=>'Email templates','referral_settings'=>'Referral form settings',
        'kb'=>'Synthetic knowledge article','search'=>'Search','utilization'=>'Utilization','agency_report'=>'Agency summary','funder_report'=>'Funder report',
        'rsa_report'=>'RSA-911 export','doc_audit'=>'Documentation audit','risk'=>'Risk flags','ai_tools'=>'AI tools','help'=>'Help and glossary','federation'=>'Federation',
        'report'=>'Training report','eval_report'=>'Evaluation report','invoice_pdf'=>'pdf','client_export'=>'zip','equipment_import'=>'Import equipment',
        'equipment_labels'=>'Equipment QR labels','notifications'=>'Alerts','community_referral'=>'Community Referral','consent'=>'Consent','survey'=>'Survey',
        'ack'=>'Confirmation link','portal'=>'Portal','vrc'=>'Portal link not recognized','login'=>'redirect','forgot'=>'Forgot password','reset'=>'Reset password',
        'verify'=>'redirect','ai'=>'AI assistant','intake'=>'Guided intake','recommend'=>'AT recommendations','amendment_request'=>'Amendment request',
        'progress_review'=>'Progress review','translate'=>'Translate','remediate'=>'Remediation','ask'=>'Ask your caseload','kb_ask'=>'Ask the knowledge base',
        'curriculum_ai'=>'Curriculum generator'];
    $showsOwnContent=function(string $page,string $html)use($pageMarkers):bool{
        $marker=$pageMarkers[$page]??null;
        if($marker===null)return false;
        if($marker==='redirect')return trim($html)==='';
        if($marker==='pdf')return str_starts_with($html,'%PDF-');
        if($marker==='zip')return str_starts_with($html,'PK');
        if(!preg_match('~<title[^>]*>(.*?)</title>~s',$html,$m))return false;
        return $marker[0]==='~'?preg_match($marker,$m[1])===1:str_contains($m[1],$marker);
    };
    foreach (['index','clients','client','client_edit','track','track_edit','tasks','referrals','pipeline','schedule','capacity','access_bookings','equipment','followups','procurement','programs','contracts','invoices','analytics','outcomes','compliance','incidents','messages','curriculum','templates','score_assessment','accommodations','users','account','audit','trash','client_merge','counselors','portal_requests','email_templates','referral_settings','kb','search','utilization','agency_report','funder_report','rsa_report','doc_audit','risk','ai_tools','help','federation','report','eval_report','invoice_pdf','client_export','equipment_import','equipment_labels','notifications','community_referral','consent','survey','ack','portal','vrc','login','forgot','reset','verify','ai','intake','recommend','amendment_request','progress_review','translate','remediate','ask','kb_ask','curriculum_ai'] as $page) {
        $source=file_get_contents($root . '/public/' . $page . '.php');
        $source=swap_required("require __DIR__ . '/inc/bootstrap.php';", 'require ' . var_export($tmp . '/bootstrap.php',true) . ';', $source, "public/$page.php");
        $source=str_replace('__DIR__',var_export($root . '/public',true),$source);
        file_put_contents($tmp . '/page.php',$source);
        $p=proc_open([PHP_BINARY,'-d','display_errors=stderr',__FILE__,'page',$tmp . '/page.php',json_encode(['id'=>1,'client'=>1,'client_id'=>1,'track'=>1,'assessment'=>1,'invoice'=>$invoiceId,'q'=>'synthetic'])],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $html=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        check($code===0 && !preg_match('/Fatal error|Warning|Notice|Uncaught|Deprecated/', $errors), "$page renders without PHP errors" . ($errors ? ": $errors" : ''));
        // Optional synthetic HTML snapshots for browser accessibility checks.
        $snapshotDir = getenv('CMS_TEST_HTML_DIR');
        if ($snapshotDir && str_contains($html, '<html')) {
            if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0700, true);
            file_put_contents($snapshotDir . '/' . $page . '.html', $html);
        }
        check($showsOwnContent($page,$html), "$page shows its own content" . (isset($pageMarkers[$page]) ? '' : ' (no marker is listed for it in the test)'));
        check(!str_contains($html . $errors, $secrets['ai']) && !str_contains($html . $errors, $secrets['entra']), "$page never shows a stored secret");
        if ($page==='client_export') {
            file_put_contents($tmp . '/export.zip',$html);
            $zip=new ZipArchive(); check($zip->open($tmp . '/export.zip')===true,'Client export produces a readable ZIP');
            check(!str_contains((string)$zip->getFromName('client_record.json'), $secrets['ai']) && !str_contains((string)$zip->getFromName('client_record.json'), $secrets['entra']), 'Client export never includes a stored secret');
            $export=json_decode($zip->getFromName('client_record.json'),true);
            check(count($export['messages'])===1 && count($export['client_scored_assessment_items'])===1 && count($export['work_logs'])===1,'Client export includes messages, scored results, and work logs');
            check(count($export['missing_attachments'])===1,'Client export identifies a missing attachment');
            check(!isset($export['client_notes'][0]['idem_key']),'Client export omits replay keys');
            $zip->close();
        }
        if ($page==='invoice_pdf') check(str_starts_with($html,'%PDF-'),'Invoice route produces a PDF');

    }
    // The sign-in pages, rendered for a visitor who is not signed in (signed in,
    // login.php and verify.php only redirect, so the loop above cannot show them).
    // verify.php is rendered mid sign-in (password accepted, code pending for user
    // 1, whose stored password_hash is 'test'), so its code form is really shown.
    putenv('CMS_TEST_ANON=1');
    foreach (['login'=>'name="password"','forgot'=>'<h1>Forgot your password?</h1>','reset'=>'Link expired or invalid','verify'=>'name="code"'] as $page=>$marker) {
        putenv('CMS_TEST_SESSION=' . ($page==='verify' ? json_encode(['pending_user_id'=>1,'pending_at'=>time(),'pending_credential_version'=>hash('sha256','test')]) : ''));
        $source=swap_required("require __DIR__ . '/inc/bootstrap.php';", 'require ' . var_export($tmp . '/bootstrap.php',true) . ';', file_get_contents($root . '/public/' . $page . '.php'), "public/$page.php");
        file_put_contents($tmp . '/page.php',str_replace('__DIR__',var_export($root . '/public',true),$source));
        $p=proc_open([PHP_BINARY,'-d','display_errors=stderr',__FILE__,'page',$tmp . '/page.php','{}'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $html=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        check($code===0 && !preg_match('/Fatal error|Warning|Notice|Uncaught|Deprecated/', $errors) && str_contains($html,$marker), "$page renders for a visitor who is not signed in" . ($errors ? ": $errors" : ''));
    }
    putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
    require $root . '/public/inc/client_merge.php';
    $tables=$db->query("SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='clients'")->fetchAll(PDO::FETCH_COLUMN);
    check(!array_diff($tables, array_merge(CLIENT_MERGE_TABLES,['cohort_members','cohort_attendance','portal_tokens'])), 'Merge covers every client foreign key in the installation schema');
    $db->exec("INSERT INTO cohort_members (cohort_id,client_id,added_at) VALUES (1,1,'2026-02-01'),(1,2,'2026-01-01')");
    $db->exec("INSERT INTO cohort_sessions (id,cohort_id,session_date,activity) VALUES (1,1,'2026-01-01','Other')");
    $db->exec("INSERT INTO cohort_attendance (session_id,client_id,present) VALUES (1,1,0),(1,2,1)");
    $db->exec("INSERT INTO groups (id,name,perms,own_caseload_only) VALUES (999,'Synthetic scoped staff','programs;clients',1)");
    $db->exec("UPDATE users SET group_id=999 WHERE id=1; UPDATE clients SET assigned_to=id, client_type='Pre-ETS'");
    $call('cohort',[],['id'=>1],false);
    $call('cohort_member',['cohort_id'=>1,'client_id'=>1,'op'=>'remove'],[],false);
    $call('log_cohort_session',['cohort_id'=>1],[],false);
    check($call('cohorts')['cohorts']===[], 'Restricted staff cannot list, read, or modify mixed-caseload groups');
    $db->exec('UPDATE clients SET assigned_to=1');
    check(count($call('cohort',[],['id'=>1])['members'])===2,'Restricted staff can access a group entirely on their caseload');
    $db->exec('UPDATE users SET group_id=NULL WHERE id=1');
    $db->beginTransaction();
    move_client_records($db,1,2);
    $db->exec('DELETE FROM clients WHERE id=2');
    $db->commit();
    foreach (['device_requests','access_bookings','worksite_assessments'] as $table) check((int)$db->query("SELECT client_id FROM `$table` LIMIT 1")->fetchColumn()===1, "$table survives client merge");
    check((int)$db->query('SELECT COUNT(*) FROM cohort_members')->fetchColumn()===1 && $db->query('SELECT added_at FROM cohort_members')->fetchColumn()==='2026-01-01 00:00:00', 'Overlapping memberships preserve the earliest join date');
    check((int)$db->query('SELECT COUNT(*) FROM cohort_attendance')->fetchColumn()===1 && (int)$db->query('SELECT present FROM cohort_attendance')->fetchColumn()===1, 'Overlapping attendance preserves participation without double counting');
    require __DIR__ . '/at_service_cases.php';
    require __DIR__ . '/calendar_cases.php';
    require __DIR__ . '/public_confirmation_cases.php';
    require __DIR__ . '/track_edit_cases.php';
    // The track-edit cases rename track 1; later checks expect the seeded title.
    $db->exec("UPDATE training_tracks SET title='Synthetic track' WHERE id=1");
    require __DIR__ . '/analytics_cases.php';
    // Voice commands as the assistant commits them once the user has confirmed
    // (inc/voice.php): no hours on a track that cannot be billed, and checking out
    // a device another client holds moves that hold to the waitlist and says so.
    $GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED']=false;
    $voiceUser=['id'=>1,'name'=>'Test Admin','role'=>'admin'];
    $db->exec("INSERT INTO clients(name) VALUES('Voice synthetic client')"); $voiceClient=(int)$db->lastInsertId();
    $db->exec("INSERT INTO clients(name) VALUES('Voice hold synthetic client')"); $holdClient=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES(?,'Voice track','VOICE-1','Voice agency',10,0)")->execute([$voiceClient]); $voiceTrack=(int)$db->lastInsertId();
    $voiceLogs=fn():int=>(int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$voiceTrack")->fetchColumn();
    $voiceHours=['client_id'=>$voiceClient,'hours'=>1,'description'=>'x'];
    $noRate=voice_execute('log_hours',$voiceHours,$voiceUser);
    $db->exec("UPDATE training_tracks SET hourly_rate=50, auth_number='TBD' WHERE id=$voiceTrack");
    $noAuth=voice_execute('log_hours',$voiceHours,$voiceUser);
    check(empty($noRate['ok']) && empty($noAuth['ok']) && $voiceLogs()===0 && !db()->inTransaction(),'Voice refuses to log hours on a track with no hourly rate or a TBD authorization number, and logs nothing');
    $db->exec("UPDATE training_tracks SET auth_number='VOICE-1' WHERE id=$voiceTrack");
    check(!empty(voice_execute('log_hours',$voiceHours,$voiceUser)['ok']) && $voiceLogs()===1,'Voice logs hours once the track has its authorization number, hours and rate');
    $db->exec("INSERT INTO equipment(name,status) VALUES('Voice synthetic device','Available')"); $voiceItem=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO equipment_reservations(equipment_id,client_id,reserved_by,status) VALUES(?,?,2,'Reserved')")->execute([$voiceItem,$holdClient]); $voiceHold=(int)$db->lastInsertId();
    $loaned=voice_execute('check_out_device',['client_id'=>$voiceClient,'equipment_id'=>$voiceItem],$voiceUser);
    check(!empty($loaned['ok']) && str_contains((string)($loaned['speak']??''),'moved to the waitlist') && !db()->inTransaction()
        && $db->query("SELECT status FROM equipment_reservations WHERE id=$voiceHold")->fetchColumn()==='Waitlist'
        && $db->query("SELECT status FROM equipment WHERE id=$voiceItem")->fetchColumn()==='On Loan',
        'Voice check-out of a device another client holds lends it, moves that hold to the waitlist and says so, with no transaction left open');
    // A status other than Closed said by voice re-opens an archived record (and
    // says so in the audit log); a Closed status leaves it archived.
    $db->exec("INSERT INTO clients(name,status,archived_at) VALUES('Voice archived synthetic client','Closed - Other',NOW())"); $archivedVoice=(int)$db->lastInsertId();
    $reopened=voice_execute('set_status',['client_id'=>$archivedVoice,'status'=>'Active Training'],$voiceUser);
    check(!empty($reopened['ok']) && $db->query("SELECT archived_at FROM clients WHERE id=$archivedVoice")->fetchColumn()===null && !db()->inTransaction()
        && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='client.unarchive' AND entity_id=$archivedVoice")->fetchColumn()===1,
        'Voice re-opening an archived client returns it to the active lists and records the unarchive');
    $db->exec("UPDATE clients SET archived_at=NOW() WHERE id=$archivedVoice");
    $closedAgain=voice_execute('set_status',['client_id'=>$archivedVoice,'status'=>'Closed - Other'],$voiceUser);
    check(!empty($closedAgain['ok']) && $db->query("SELECT archived_at FROM clients WHERE id=$archivedVoice")->fetchColumn()!==null,'Voice setting a Closed status leaves an archived client archived');
    // A session length outside 15 minutes to 12 hours is refused, never cut to fit.
    $voiceSessions=fn():int=>(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$voiceClient")->fetchColumn();
    $sessionsBefore=$voiceSessions();
    $voiceStart=date('Y-m-d',strtotime('+1 day')).' 10:00';
    $tooShort=voice_execute('schedule_session',['client_id'=>$voiceClient,'start_at'=>$voiceStart,'duration'=>5],$voiceUser);
    $tooLong=voice_execute('schedule_session',['client_id'=>$voiceClient,'start_at'=>$voiceStart,'duration'=>800],$voiceUser);
    check(empty($tooShort['ok']) && empty($tooLong['ok']) && $voiceSessions()===$sessionsBefore,'Voice refuses a session of 5 or 800 minutes and schedules nothing');
    // Completing a session on a track with no hourly rate is refused and the session stays open.
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES(?,'Voice no-rate track','VOICE-2','Voice agency',10,0)")->execute([$voiceClient]); $noRateTrack=(int)$db->lastInsertId();
    $yesterday=date('Y-m-d',strtotime('-1 day'));
    $db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status) VALUES(?,?,1,?,?,'Scheduled')")->execute([$voiceClient,$noRateTrack,"$yesterday 09:00:00","$yesterday 10:00:00"]); $noRateSession=(int)$db->lastInsertId();
    $noRateDone=voice_execute('complete_session',['session_id'=>$noRateSession],$voiceUser);
    check(empty($noRateDone['ok']) && str_starts_with((string)($noRateDone['error']??''),'This track has no hourly rate') && !db()->inTransaction()
        && $db->query("SELECT status FROM scheduled_sessions WHERE id=$noRateSession")->fetchColumn()==='Scheduled'
        && (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$noRateTrack")->fetchColumn()===0,
        'Voice refuses to complete a session on a track with no hourly rate, leaves it open and leaves no transaction open');
    $db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE id=$noRateSession; UPDATE training_tracks SET status='Cancelled' WHERE id=$noRateTrack");
    // A referral made by voice is a staff member's new referral, named as theirs.
    $voiceReferral=voice_execute('create_referral',['name'=>'Voice referral synthetic person','reason'=>'Synthetic reason'],$voiceUser);
    $refRow=$db->query("SELECT referral_source,referral_purpose,counselor_name FROM referrals WHERE client_name='Voice referral synthetic person'")->fetch();
    check(!empty($voiceReferral['ok']) && $refRow && $refRow['referral_source']==='other' && $refRow['referral_purpose']==='New referral'
        && $refRow['counselor_name']===(string)$db->query('SELECT name FROM users WHERE id=1')->fetchColumn(),
        'A voice referral is stored as a new referral from another source, naming the staff member who made it');
    $voiceRid=(int)$db->query("SELECT id FROM referrals WHERE client_name='Voice referral synthetic person'")->fetchColumn();
    $contactTask=$db->query("SELECT due_date FROM tasks WHERE auto_key='ref-contact-$voiceRid' AND referral_id=$voiceRid")->fetchAll();
    check(count($contactTask)===1 && $contactTask[0]['due_date']!==null,'A voice referral gets the same dated contact task as the referral form');
    // Archiving by voice follows the retention rule: only a closed case, and only once.
    $db->exec("INSERT INTO clients(name,status) VALUES('Voice archive synthetic client','Active Training')"); $archiveVoice=(int)$db->lastInsertId();
    $openArchive=voice_execute('archive_client',['client_id'=>$archiveVoice],$voiceUser);
    check(empty($openArchive['ok']) && $db->query("SELECT archived_at FROM clients WHERE id=$archiveVoice")->fetchColumn()===null,'Voice refuses to archive a case that is not closed');
    $db->exec("UPDATE clients SET status='Closed - Other' WHERE id=$archiveVoice");
    $closedArchive=voice_execute('archive_client',['client_id'=>$archiveVoice],$voiceUser);
    $againArchive=voice_execute('archive_client',['client_id'=>$archiveVoice],$voiceUser);
    check(!empty($closedArchive['ok']) && $db->query("SELECT archived_at FROM clients WHERE id=$archiveVoice")->fetchColumn()!==null
        && empty($againArchive['ok']) && str_contains((string)($againArchive['error']??''),'already archived')
        && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='client.archive' AND entity_id=$archiveVoice")->fetchColumn()===1,
        'Voice archives a closed case once; a second request says it is already archived and records nothing more');
    // An over-long request is refused before it counts toward the voice limit.
    $voiceAttempts=fn():int=>(int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='voice-1'")->fetchColumn();
    $attemptsBefore=$voiceAttempts();
    $longVoice=voice_interpret(['transcript'=>str_repeat('a',2001)],$voiceUser);
    check(($longVoice['action']??'')==='answer' && $voiceAttempts()===$attemptsBefore,'A voice request over 2,000 characters is answered with a refusal and does not count toward the limit');
    // Work dated after today is never billed or logged ahead: an invoice takes only
    // work up to today, and an automatic log for tomorrow's session is refused.
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES(?,'Future work synthetic track','FUTURE-1','Voice agency',10,50)")->execute([$voiceClient]); $futureTrack=(int)$db->lastInsertId();
    $tomorrowDate=date('Y-m-d',strtotime('+1 day'));
    $db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,hourly_rate,created_by) VALUES(?,CURDATE(),1,'Synthetic work today',50,1),(?,?,2,'Synthetic work tomorrow',50,1)")->execute([$futureTrack,$futureTrack,$tomorrowDate]);
    $futureInvoice=generate_invoice($futureTrack,1);
    check($futureInvoice!==null && (float)$db->query("SELECT hours FROM invoices WHERE id=$futureInvoice")->fetchColumn()===1.0
        && (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$futureTrack AND work_date='$tomorrowDate' AND is_billed=0 AND invoice_id IS NULL")->fetchColumn()===1,
        'An invoice bills work up to today and leaves work dated tomorrow unbilled');
    $futureLogs=(int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$futureTrack")->fetchColumn();
    try { auto_log_session($futureTrack,$tomorrowDate,1,'Synthetic session tomorrow',1); $futureRefused=false; } catch (DomainException $e) { $futureRefused=true; }
    check($futureRefused && (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$futureTrack")->fetchColumn()===$futureLogs && !db()->inTransaction(),
        'Hours are not logged automatically for a session dated tomorrow');

    // Refusals on the core surfaces: caseload scope and group permissions in the
    // API, unknown / expired / deactivated-account tokens, a forged CSRF token,
    // and another caseload's client page.
    $db->exec("INSERT INTO clients(name,assigned_to) VALUES('Scope check outside client',2)"); $scopeOutside=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate) VALUES(?,'Scope check outside track','SCOPE-OUT','Scope agency',50)")->execute([$scopeOutside]); $scopeTrack=(int)$db->lastInsertId();
    $db->exec("UPDATE groups SET perms='clients;schedule',own_caseload_only=1 WHERE id=999; UPDATE users SET group_id=999 WHERE id=1; UPDATE clients SET assigned_to=1 WHERE id=1");
    $notes=(int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn(); $logs=(int)$db->query('SELECT COUNT(*) FROM work_logs')->fetchColumn();
    $read=$call('client',[],['id'=>$scopeOutside],false);
    $call('add_note',['client_id'=>$scopeOutside,'subject'=>'Out of scope','body'=>'Must be refused'],[],false);
    $call('log_hours',['track_id'=>$scopeTrack,'hours'=>1,'description'=>'Must be refused'],[],false);
    check(!isset($read['client']) && (int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn()===$notes && (int)$db->query('SELECT COUNT(*) FROM work_logs')->fetchColumn()===$logs,
        'Caseload-scoped staff cannot read, note or log hours on another caseload\'s client through the API');
    $call('add_note',['client_id'=>1,'subject'=>'In scope','body'=>'Allowed']);
    check((int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn()===$notes+1,'Caseload-scoped staff can still write to their own client');
    check(!isset($call('equipment',[],[],false)['equipment']),'An API action outside the group\'s permissions is refused');
    $db->prepare("INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (1,?,DATE_SUB(NOW(), INTERVAL 2 DAY))")->execute([hash('sha256', str_repeat('d',64))]);
    $db->exec("INSERT INTO users (id,name,email,password_hash,role,is_active) VALUES (3,'Deactivated synthetic','deactivated@example.invalid','test','instructor',0)");
    $db->prepare("INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (3,?,DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([hash('sha256', str_repeat('e',64))]);
    foreach (['unknown'=>'b','expired'=>'d','deactivated account\'s'=>'e'] as $label=>$char) {
        putenv('CMS_TEST_TOKEN=' . str_repeat($char,64));
        $refused=$call('me',[],[],false);
        putenv('CMS_TEST_TOKEN');
        check(!isset($refused['id']),"An $label API token is refused");
    }
    $renderPage=function(string $name,array $query,?array $post=null)use($root,$tmp):array{
        $source=swap_required("require __DIR__ . '/inc/bootstrap.php';",'require '.var_export($tmp.'/bootstrap.php',true).';',file_get_contents($root.'/public/'.$name.'.php'),"public/$name.php");
        file_put_contents($tmp.'/'.$name.'.php',str_replace('__DIR__',var_export($root.'/public',true),$source));
        $args=[PHP_BINARY,'-d','display_errors=stderr','-d','sendmail_path=/usr/bin/true',__FILE__,$post===null?'page':'page_post',$tmp.'/'.$name.'.php',json_encode($query)];
        if($post!==null)$args[]=json_encode($post);
        $p=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    [$code,$out,$err]=$renderPage('client',['id'=>$scopeOutside]);
    check($code===0 && $err==='' && !str_contains($out,'Scope check outside client'),'Caseload-scoped staff cannot open another caseload\'s client page');
    [$code,$out,$err]=$renderPage('client',['id'=>1]);
    check($code===0 && $err==='' && str_contains($out,'Kept synthetic client'),'Caseload-scoped staff can open their own client page');
    // File downloads, the billing CSV and the session keepalive, as the same
    // caseload-scoped user: another caseload's document, signature image or
    // billing statement is never sent; their own still is.
    file_put_contents($tmp.'/doc_scope_out.txt','SCOPE-OUT-DOCUMENT-BYTES'); file_put_contents($tmp.'/doc_scope_in.txt','SCOPE-IN-DOCUMENT-BYTES');
    file_put_contents($tmp.'/sig_scope_out.png','SCOPE-OUT-SIGNATURE-BYTES');
    $db->prepare("INSERT INTO client_documents(client_id,title,orig_name,path) VALUES(?,'Scope out document','out.txt','doc_scope_out.txt')")->execute([$scopeOutside]); $outDoc=(int)$db->lastInsertId();
    $db->exec("INSERT INTO client_documents(client_id,title,orig_name,path) VALUES(1,'Scope in document','in.txt','doc_scope_in.txt')"); $inDoc=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,signature_path,signed_name) VALUES(?,CURDATE(),0,'Scope signature','sig_scope_out.png','Synthetic signer')")->execute([$scopeTrack]); $outSig=(int)$db->lastInsertId();
    [$code,$out,$err]=$renderPage('download',['doc'=>$outDoc]);
    check($err==='' && !str_contains($out,'SCOPE-OUT-DOCUMENT-BYTES'),'Caseload-scoped staff cannot download another caseload\'s client document' . ($err ? ": $err" : ''));
    [$code,$out,$err]=$renderPage('download',['sig'=>$outSig]);
    check($err==='' && !str_contains($out,'SCOPE-OUT-SIGNATURE-BYTES'),'Caseload-scoped staff cannot view another caseload\'s client signature' . ($err ? ": $err" : ''));
    [$code,$out,$err]=$renderPage('download',['doc'=>$inDoc]);
    check($code===0 && $err==='' && str_contains($out,'SCOPE-IN-DOCUMENT-BYTES'),'Caseload-scoped staff can download their own client\'s document' . ($err ? ": $err" : ''));
    $db->exec("UPDATE groups SET perms='clients;schedule;billing' WHERE id=999");
    [$code,$out,$err]=$renderPage('export',['track'=>$scopeTrack]);
    check($err==='' && !str_contains($out,'Scope check outside') && !str_contains($out,'SCOPE-OUT'),'Caseload-scoped billing staff cannot export another caseload\'s billing statement' . ($err ? ": $err" : ''));
    [$code,$out,$err]=$renderPage('export',['track'=>1]);
    check($code===0 && $err==='' && str_contains($out,'BILLING SUMMARY') && str_contains($out,'Kept synthetic client') && str_contains($out,'Synthetic track'),'Caseload-scoped billing staff can export their own client\'s billing statement' . ($err ? ": $err" : ''));
    $db->exec("UPDATE groups SET perms='clients;schedule' WHERE id=999");
    $db->exec("DELETE FROM client_documents WHERE id IN ($outDoc,$inDoc)"); $db->exec("DELETE FROM work_logs WHERE id=$outSig");
    // The keepalive answers 'ok' to a live session. Its two headers cannot be read
    // from a CLI run, so their presence is checked in the source: session-forms.js
    // depends on both (tests/session-forms.test.cjs covers the browser side).
    [$code,$out,$err]=$renderPage('ping',[]);
    $pingSource=(string)file_get_contents($root.'/public/ping.php');
    check($code===0 && $err==='' && $out==='ok' && str_contains($pingSource,"header('X-CSRF-Token: ") && str_contains($pingSource,"header('X-User-Id: "),'The session keepalive answers a live session and sends the CSRF token and user id headers');
    // Removing a student who attended a group session marks the membership as
    // left and keeps it: they leave the current student list, while the hours
    // export still reports the session they attended.
    $db->exec('UPDATE users SET group_id=NULL WHERE id=1');
    [$code,$out,$err]=$renderPage('programs',[],['cohort_id'=>1,'client_id'=>1,'remove_member'=>'1']);
    $membership=$db->query('SELECT left_at FROM cohort_members WHERE cohort_id=1 AND client_id=1')->fetch();
    [,$cohortPage,$cohortErr]=$renderPage('programs',['id'=>1]);
    [,$cohortCsv,$csvErr]=$renderPage('programs',['export'=>1]);
    $db->exec('UPDATE users SET group_id=999 WHERE id=1');
    check($code===0 && $err==='' && $membership && $membership['left_at']!==null && (int)$db->query('SELECT COUNT(*) FROM cohort_attendance WHERE client_id=1 AND present=1')->fetchColumn()===1,
        'Removing a student from a cohort keeps the membership, marked with when they left' . ($err ? ": $err" : ''));
    check($cohortErr==='' && $csvErr==='' && str_contains($cohortPage,'Students (0)') && str_contains($cohortCsv,'Kept synthetic client (left the cohort)'),
        'A student who left is no longer listed as a current member, but the hours export still includes them' . ($cohortErr.$csvErr ? ': '.$cohortErr.$csvErr : ''));
    // Hours that round below a cent, and an odometer reading typed as mileage, are
    // refused on the track page and nothing is logged.
    $trackLogs=fn():int=>(int)$db->query('SELECT COUNT(*) FROM work_logs WHERE track_id=1')->fetchColumn();
    $logsBefore=$trackLogs();
    foreach ([['hours'=>'1','mileage'=>'45210'],['hours'=>'0.004','mileage'=>'']] as $badLog) {
        [$code,,$err]=$renderPage('track',['id'=>1],$badLog+['add_log'=>'1','work_date'=>date('Y-m-d'),'description'=>'Synthetic refused log']);
        check($code===0 && $err==='' && $trackLogs()===$logsBefore,'Logging hours refuses ' . json_encode($badLog) . ' and logs nothing' . ($err ? ": $err" : ''));
    }
    // Positive control: the same route, user and track log a valid entry, so the
    // refusals above came from the validation and not from a rate, permission or
    // scope refusal. The entry is removed again so later totals are unchanged.
    [$code,,$err]=$renderPage('track',['id'=>1],['hours'=>'1','mileage'=>'12','add_log'=>'1','work_date'=>date('Y-m-d'),'description'=>'Synthetic accepted log']);
    $acceptedLog=(int)$db->query("SELECT MAX(id) FROM work_logs WHERE track_id=1 AND description='Synthetic accepted log'")->fetchColumn();
    check($code===0 && $err==='' && $trackLogs()===$logsBefore+1 && $acceptedLog>0,'Logging valid hours on the same track is accepted (control for the refusals above)' . ($err ? ": $err" : ''));
    $db->exec("DELETE FROM work_logs WHERE id=$acceptedLog");
    $title=$db->query('SELECT title FROM training_tracks WHERE id=1')->fetchColumn();
    [$code,$out,$err]=$renderPage('track_edit',['id'=>1],['csrf'=>'forged-token','title'=>'Forged title','auth_number'=>'FORGED','funding_agency'=>'Forged','authorized_hours'=>'1','hourly_rate'=>'1']);
    check($code===0 && str_contains($out,'Invalid request token') && $db->query('SELECT title FROM training_tracks WHERE id=1')->fetchColumn()===$title,'A web form posted with a forged CSRF token is refused and changes nothing');
    $db->exec('UPDATE users SET group_id=NULL WHERE id=1');
    require __DIR__.'/referral_request_cases.php';
    // A bundle toggle names the state it asks for, so one posted from a stale page
    // (or a second click) changes nothing and is not audited; a real change is.
    $db->exec("INSERT INTO service_bundles(name,categories,active) VALUES('Stale toggle bundle','Other',1)"); $bundle=(int)$db->lastInsertId();
    $toggles=fn():int=>(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='bundle.toggle' AND entity_id=$bundle")->fetchColumn();
    $bundleActive=fn():int=>(int)$db->query("SELECT active FROM service_bundles WHERE id=$bundle")->fetchColumn();
    [$code,$out,$err]=$renderPage('templates',[],['toggle_bundle'=>'1','id'=>(string)$bundle,'to'=>'active']);
    check($code===0 && $bundleActive()===1 && $toggles()===0,'A bundle toggle from a stale page changes nothing and is not audited' . ($err ? ": $err" : ''));
    [$code,$out,$err]=$renderPage('templates',[],['toggle_bundle'=>'1','id'=>(string)$bundle,'to'=>'retired']);
    check($code===0 && $bundleActive()===0 && $toggles()===1,'A bundle toggle that asks for a change makes it once and audits it' . ($err ? ": $err" : ''));
    // Two merges in a row: a trashed note of the first duplicate (stored by an older
    // release with client_id as a string) ends on the finally kept client, as a number.
    $mergeClient=function(string $name)use($db):int{$db->prepare('INSERT INTO clients(name) VALUES(?)')->execute([$name]);return (int)$db->lastInsertId();};
    $mergeKeep=$mergeClient('Merge kept synthetic'); $mergeMiddle=$mergeClient('Merge middle synthetic'); $mergeFirst=$mergeClient('Merge first synthetic');
    $db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_by) VALUES('client_notes',999998,'Synthetic merged note',?,1)")->execute([json_encode(['id'=>999998,'client_id'=>(string)$mergeFirst,'subject'=>'Synthetic'])]); $mergedTrash=(int)$db->lastInsertId();
    $renderPage('client_merge',['keep'=>$mergeMiddle,'merge'=>$mergeFirst],['do_merge'=>'1']);
    [$code,$out,$err]=$renderPage('client_merge',['keep'=>$mergeKeep,'merge'=>$mergeMiddle],['do_merge'=>'1']);
    $moved=$db->query("SELECT JSON_TYPE(JSON_EXTRACT(payload,'$.client_id')) AS t, JSON_EXTRACT(payload,'$.client_id') AS v FROM trash WHERE id=$mergedTrash")->fetch();
    check($code===0 && (int)$db->query("SELECT COUNT(*) FROM clients WHERE id IN ($mergeMiddle,$mergeFirst)")->fetchColumn()===0 && $moved['t']==='INTEGER' && (int)$moved['v']===$mergeKeep,
        'Two merges in a row leave a trashed note on the finally kept client, with its client number stored as a number' . ($err ? ": $err" : ''));
    // Merging a duplicate from another instructor's caseload (kept: user 2, duplicate:
    // user 3): the duplicate's status history is marked as merged; its open tasks that
    // its instructor held, and its converted referral, follow the kept client's
    // instructor. A task someone else holds, a done task and an unconverted referral stay.
    $mergeOwnKeep=$mergeClient('Merge instructor kept synthetic'); $mergeOwnDupe=$mergeClient('Merge instructor duplicate synthetic');
    $db->exec("UPDATE clients SET assigned_to=2 WHERE id=$mergeOwnKeep"); $db->exec("UPDATE clients SET assigned_to=3 WHERE id=$mergeOwnDupe");
    $db->exec("INSERT INTO client_status_history(client_id,old_status,new_status,note) VALUES($mergeOwnDupe,NULL,'Active Training','Synthetic change'),($mergeOwnDupe,'Active Training','On Hold',NULL),($mergeOwnKeep,NULL,'Active Training','Kept change')");
    $mergeTask=function(int $assignee,string $status)use($db,$mergeOwnDupe):int{$db->prepare("INSERT INTO tasks(title,client_id,assigned_to,status) VALUES('Merge synthetic task',?,?,?)")->execute([$mergeOwnDupe,$assignee,$status]);return (int)$db->lastInsertId();};
    $heldTask=$mergeTask(3,'Open'); $otherTask=$mergeTask(1,'Open'); $doneTask=$mergeTask(3,'Done');
    $mergeReferral=function(string $status)use($db,$mergeOwnDupe):int{$db->prepare("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,status,assigned_to,client_id) VALUES('Synthetic','Synthetic','Synthetic','Synthetic counselor','Synthetic agency','counselor@example.invalid','555-0100','Merge instructor duplicate synthetic',?,3,?)")->execute([$status,$mergeOwnDupe]);return (int)$db->lastInsertId();};
    $convertedReferral=$mergeReferral('Converted'); $openReferral=$mergeReferral('Reviewed');
    [$code,$out,$err]=$renderPage('client_merge',['keep'=>$mergeOwnKeep,'merge'=>$mergeOwnDupe],['do_merge'=>'1']);
    $history=$db->query("SELECT note FROM client_status_history WHERE client_id=$mergeOwnKeep ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $taskOwner=fn(int $id):int=>(int)$db->query("SELECT assigned_to FROM tasks WHERE id=$id")->fetchColumn();
    $referralRow=fn(int $id):array=>$db->query("SELECT client_id,assigned_to FROM referrals WHERE id=$id")->fetch();
    $audited=fn(string $action):int=>(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='$action' AND entity_id=$mergeOwnKeep")->fetchColumn();
    check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM clients WHERE id=$mergeOwnDupe")->fetchColumn()===0
        && $history===['[merged] Synthetic change','[merged] ','Kept change'],
        'A merge marks the duplicate\'s status history as merged and leaves the kept client\'s own history unchanged' . ($err ? ": $err" : ''));
    check($taskOwner($heldTask)===2 && $taskOwner($otherTask)===1 && $taskOwner($doneTask)===3 && $audited('task.reassign')===1,
        'A merge moves the duplicate\'s open tasks its instructor held to the kept client\'s instructor, and audits it; other tasks keep their holder');
    check((int)$referralRow($convertedReferral)['client_id']===$mergeOwnKeep && (int)$referralRow($convertedReferral)['assigned_to']===2
        && (int)$referralRow($openReferral)['assigned_to']===3 && $audited('referral.reassign')===1,
        'A merge moves the duplicate\'s converted referral to the kept client\'s instructor, and audits it; an unconverted referral keeps its assignee');
    // Retention purge of an archived client: a device still on loan comes back,
    // referrals and trashed items go with the record, and an incident report is
    // kept, detached from the client, with its free text removed.
    $db->exec("INSERT INTO clients(name,assigned_to,archived_at) VALUES('Purge synthetic client',1,NOW())"); $purged=(int)$db->lastInsertId();
    $db->exec("INSERT INTO equipment(name,status) VALUES('Purge synthetic loaner','On Loan')"); $purgeItem=(int)$db->lastInsertId();
    $db->exec("INSERT INTO equipment_loans(equipment_id,client_id,loaned_at) VALUES($purgeItem,$purged,'2026-01-01')");
    $db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,client_id) VALUES('Synthetic','Synthetic','Synthetic','Synthetic counselor','Synthetic agency','counselor@example.invalid','555-0100','Purge synthetic client',$purged)");
    $db->exec("INSERT INTO incidents(client_id,incident_date,location,description,action_taken) VALUES($purged,'2026-01-02','Purge synthetic home','Purge synthetic client fell','Called the guardian of Purge synthetic client')"); $incident=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_by) VALUES('client_notes',999999,'Synthetic purged note',?,1)")->execute([json_encode(['id'=>999999,'client_id'=>$purged,'subject'=>'Synthetic'])]); $purgeTrash=(int)$db->lastInsertId();
    [$code,$out,$err]=$renderPage('client',['id'=>$purged],['purge_client'=>'1']);
    check($code===0 && (int)$db->query("SELECT COUNT(*) FROM clients WHERE id=$purged")->fetchColumn()===0
        && $db->query("SELECT status FROM equipment WHERE id=$purgeItem")->fetchColumn()==='Available'
        && (int)$db->query("SELECT COUNT(*) FROM referrals WHERE client_id=$purged")->fetchColumn()===0
        && (int)$db->query("SELECT COUNT(*) FROM trash WHERE id=$purgeTrash")->fetchColumn()===0,
        'Purging an archived client returns its loaned device and removes its referrals and trashed items' . ($err ? ": $err" : ''));
    $kept=$db->query("SELECT client_id,description,action_taken,location FROM incidents WHERE id=$incident")->fetch();
    check($kept && $kept['client_id']===null && $kept['description']==='[Removed when the client record was purged]' && $kept['action_taken']===$kept['description'] && $kept['location']===$kept['description'],
        'A purged client\'s incident report is kept, detached from the client, with its description, action taken and location removed');

    // The scheduled jobs against this database. There are no Graph settings here,
    // so mail goes through PHP's mail() to a capture script: nothing leaves the machine.
    file_put_contents($tmp.'/sendmail_ok.sh',"#!/bin/sh\n{ cat; echo '=====END====='; } >> ".escapeshellarg($tmp.'/mail.log')."\nexit 0\n");
    file_put_contents($tmp.'/sendmail_fail.sh',"#!/bin/sh\ncat > /dev/null\nexit 1\n");
    chmod($tmp.'/sendmail_ok.sh',0700); chmod($tmp.'/sendmail_fail.sh',0700);
    $cronRun=function(string $script,bool $mailWorks)use($root,$tmp):array{
        $cron=(string)file_get_contents($root.'/cron/'.$script);
        $start=strpos($cron,'foreach ([');
        $end=$start===false?false:strpos($cron,"if (!function_exists('db'))",$start);
        $end=$end===false?false:strpos($cron,"}\n",$end);
        if($start===false||$end===false)throw new RuntimeException("Test harness: could not locate the core.php lookup in cron/$script. Refusing to continue: the unmodified script would load the real configuration.");
        $cron=substr($cron,0,$start).'$inc = '.var_export($root.'/public/inc',true).'; require '.var_export($tmp.'/core.php',true).";\n".substr($cron,$end+2);
        if(has_real_include($cron))throw new RuntimeException("Test harness: cron/$script would still load the real inc/core.php. Refusing to continue.");
        file_put_contents($tmp.'/cron_'.$script,$cron);
        @unlink($tmp.'/mail.log');
        $p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','sendmail_path='.$tmp.($mailWorks?'/sendmail_ok.sh':'/sendmail_fail.sh'),$tmp.'/cron_'.$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $code=proc_close($p);
        // A job that ran cleanly reports no PHP warnings or notices.
        if($mailWorks && preg_match('/Fatal error|Warning|Notice|Uncaught|Deprecated/',$err))throw new RuntimeException("cron/$script reported PHP errors: $err");
        return [$code,(string)@file_get_contents($tmp.'/mail.log')];
    };
    $marks=fn(int $track):int=>(int)$db->query("SELECT COUNT(*) FROM sent_alerts WHERE track_id=$track AND alert_key LIKE 'exp%'")->fetchColumn();
    $db->exec("INSERT INTO clients(name,assigned_to,status,email) VALUES('Alert check client',2,'Active Training','alertclient@example.invalid')"); $alertClient=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate,expiration_date) VALUES(?,'Alert check track','ALERT-1','Alert agency',50,?)")->execute([$alertClient,date('Y-m-d',strtotime('+5 days'))]); $alertTrack=(int)$db->lastInsertId();
    [$code,$mail]=$cronRun('daily_alerts.php',true);
    check($code===0 && $marks($alertTrack)===3 && str_contains($mail,'To: admin@example.invalid') && str_contains($mail,'To: instructor@example.invalid') && substr_count($mail,'Alert check track')>=2,
        'Daily alerts reach the administrators and the assigned case worker, then mark every threshold the alert covered');
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate,expiration_date) VALUES(?,'Alert retry track','ALERT-2','Alert agency',50,?)")->execute([$alertClient,date('Y-m-d',strtotime('+6 days'))]); $retryTrack=(int)$db->lastInsertId();
    [$code]=$cronRun('daily_alerts.php',false);
    check($code===1 && $marks($retryTrack)===0,'When alert email cannot be sent the job fails visibly and leaves the alert unmarked');
    [$code,$mail]=$cronRun('daily_alerts.php',true);
    check($code===0 && $marks($retryTrack)===3 && str_contains($mail,'Alert retry track') && !str_contains($mail,'Alert check track'),'The next run sends the alert that failed, and only that one');
    $db->exec("UPDATE clients SET status='Closed - Other' WHERE id=$alertClient");
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate,expiration_date) VALUES(?,'Closed client track','ALERT-3','Alert agency',50,?)")->execute([$alertClient,date('Y-m-d',strtotime('+4 days'))]); $closedTrack=(int)$db->lastInsertId();
    [$code,$mail]=$cronRun('daily_alerts.php',true);
    check($code===0 && $marks($closedTrack)===0 && !str_contains($mail,'Closed client track'),'A closed client\'s leftover active track raises no alert');
    // Progress reports: last_report_at is written only for a report actually sent.
    // A counselor email staff have confirmed (counselor_verified = 1) receives the
    // report; one that came unconfirmed from the public referral form does not.
    $reportTrack=function(string $client,string $status,?string $counselor,int $verified=1)use($db):int{
        $db->prepare("INSERT INTO clients(name,status,counselor_email,counselor_verified) VALUES(?,?,?,?)")->execute([$client,$status,$counselor,$verified]);$cid=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate,report_cadence,start_date,created_at) VALUES(?,?,'REPORT','Report agency',50,'weekly',?,?)")
            ->execute([$cid,$client.' track',date('Y-m-d',strtotime('-30 days')),date('Y-m-d H:i:s',strtotime('-30 days'))]);
        return (int)$db->lastInsertId();
    };
    $sentTrack=$reportTrack('Report sent client','Active Training','counselor@example.invalid');
    $noEmailTrack=$reportTrack('Report no email client','Active Training',null);
    $closedReportTrack=$reportTrack('Report closed client','Closed - Rehabilitated','closed-counselor@example.invalid');
    $unconfirmedTrack=$reportTrack('Report unconfirmed client','Active Training','unconfirmed-counselor@example.invalid',0);
    $lastReport=fn(int $track)=>$db->query("SELECT last_report_at FROM training_tracks WHERE id=$track")->fetchColumn();
    [$code,$mail]=$cronRun('monthly_reports.php',true);
    check($code===0 && $lastReport($sentTrack)===date('Y-m-d') && str_contains($mail,'To: counselor@example.invalid'),'A due progress report is emailed and only then recorded as sent');
    check($lastReport($noEmailTrack)===null && str_contains($mail,'Report no email client') && $lastReport($closedReportTrack)===null && !str_contains($mail,'closed-counselor@example.invalid'),
        'A track with no counselor email stays due and is reported to the administrators; a closed client gets no automatic report');
    check($lastReport($unconfirmedTrack)===null && !str_contains($mail,'To: unconfirmed-counselor@example.invalid') && str_contains($mail,'Report unconfirmed client')
        && str_contains($mail,'has not been confirmed'),'A counselor email that staff have not confirmed gets no report, and the administrators are asked to confirm it');
    check(str_contains($mail,'from the start of this authorization'),'The automatic report says it covers the whole authorization, not only the last period');
    [$code,$mail]=$cronRun('monthly_reports.php',true);
    check($code===0 && !str_contains($mail,'Report no email client') && !str_contains($mail,'Report unconfirmed client') && $lastReport($noEmailTrack)===null,'The administrators are told about a missing or unconfirmed counselor email once per period, not every day');
    // Session reminders: an owner who was deactivated is replaced by an administrator notice.
    $tomorrow=date('Y-m-d',strtotime('+1 day'));
    $db->exec("UPDATE clients SET status='Active Training' WHERE id=$alertClient");
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at) VALUES(?,3,?,?)")->execute([$alertClient,"$tomorrow 10:00:00","$tomorrow 11:00:00"]); $reminded=(int)$db->lastInsertId();
    [$code,$mail]=$cronRun('session_reminders.php',true);
    check($code===0 && (int)$db->query("SELECT reminder_sent FROM scheduled_sessions WHERE id=$reminded")->fetchColumn()===1 && str_contains($mail,'To: alertclient@example.invalid') && str_contains($mail,'deactivated account'),
        'A session owned by a deactivated account still reminds the client and asks the administrators to reassign it');

    require __DIR__.'/post_claude_cases.php';
    require __DIR__.'/public_token_cases.php';

    // Upgrade path: mig_3.0.8.sql applied to a database still in the 3.0.7 shape
    // must give exactly the columns, indexes and foreign keys schema_full.sql
    // installs, and every migration must be safe to run again on an up-to-date one.
    $shape=fn():array=>[
        $db->query("SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(),
        $db->query("SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX")->fetchAll(),
        $db->query("SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME")->fetchAll(),
    ];
    // Statement by statement, so an error in any statement fails the run.
    $migrate=function(string $file)use($db):void{
        $sql=preg_replace('/^\s*--.*$/m','',(string)file_get_contents($file));
        foreach(preg_split('/;\s*$/m',$sql) as $statement) if(trim($statement)!=='') $db->exec($statement);
    };
    // Rows the 3.0.8 upgrade corrects: an inventory note naming a client, a
    // follow-up still pending after its loan was returned, and one on an AT repair
    // loaner. A follow-up on a device still out on an ordinary loan stays pending.
    $db->exec("INSERT INTO equipment(name,notes) VALUES('Legacy delivered device','From device request #9 for Synthetic Person')"); $legacyItem=(int)$db->lastInsertId();
    $legacyLoan=function(?string $returned)use($db,$legacyItem):int{
        $db->prepare('INSERT INTO equipment_loans(equipment_id,client_id,loaned_at,returned_at) VALUES(?,1,?,?)')->execute([$legacyItem,'2026-01-01',$returned]);
        $loan=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO equipment_followups(loan_id,client_id,equipment_id,interval_days,due_date) VALUES(?,1,?,30,'2026-01-31')")->execute([$loan,$legacyItem]);
        return $loan;
    };
    $returnedLoan=$legacyLoan('2026-01-15'); $loanerLoan=$legacyLoan(null); $openLoan=$legacyLoan(null);
    $db->prepare("INSERT INTO at_records(kind,client_id,title,status,details) VALUES('repair',1,'Legacy repair with a loaner','Open',?)")->execute([json_encode(['loan_id'=>(string)$loanerLoan])]);
    $followup=fn(int $loan):string=>(string)$db->query("SELECT CONCAT(status,'|',COALESCE(notes,'')) FROM equipment_followups WHERE loan_id=$loan")->fetchColumn();
    $installed=$shape();
    $db->exec("ALTER TABLE invoices DROP COLUMN auth_number, DROP COLUMN funding_agency, DROP COLUMN bill_to, DROP COLUMN hourly_rate, DROP COLUMN mileage_rate, DROP COLUMN period_start, DROP COLUMN period_end");
    $db->exec("ALTER TABLE login_otps DROP COLUMN channel");
    $db->exec("ALTER TABLE consent_forms DROP COLUMN withdrawn_at, DROP COLUMN withdrawn_by");
    $db->exec("ALTER TABLE auth_amendments DROP COLUMN old_auth_number, DROP COLUMN new_auth_number, DROP COLUMN old_mileage_rate, DROP COLUMN new_mileage_rate");
    $db->exec("ALTER TABLE client_documents MODIFY category enum('Authorization','IPE','Release','Report','Resource','Other') NOT NULL DEFAULT 'Other'");
    $migrate($root.'/dist/migrations/mig_3.0.8.sql');
    check($shape()===$installed,'mig_3.0.8.sql brings a 3.0.7 database to exactly the installed schema');
    check($db->query("SELECT notes FROM equipment WHERE id=$legacyItem")->fetchColumn()==='From device request #9','The upgrade removes the client name from a legacy inventory note');
    check($followup($returnedLoan)==='Skipped|Device returned' && $followup($loanerLoan)==='Skipped|Repair loaner' && $followup($openLoan)==='Pending|',
        'The upgrade closes pending follow-ups of returned loans and repair loaners, and only those');
    $db->exec('ALTER TABLE referrals DROP COLUMN referral_purpose, DROP COLUMN requested_service, DROP COLUMN specific_needs');
    $migrate($root.'/dist/migrations/mig_3.0.10.sql');
    check($shape()===$installed,'Structured referral migration restores exactly the fresh-install schema');
    foreach(glob($root.'/dist/migrations/mig_*.sql') as $file) $migrate($file);
    check($shape()===$installed,'Every migration can run again on an up-to-date database without changing its schema');
    // The checks above start from today's schema with the new columns removed, so
    // they cannot see a change no migration makes. Start again from the table
    // definitions 3.0.7 really shipped and apply the later migrations in release
    // order: the result must match a fresh install, column defaults included.
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $db->exec('DROP TABLE `' . str_replace('`','``',$table) . '`');
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    $db->exec((string)file_get_contents($root.'/tests/fixtures/schema_3.0.7.sql'));
    $db->exec("INSERT INTO clients (id,name,counselor_email) VALUES (1,'Upgraded synthetic client','upgraded-counselor@example.invalid'),(2,'Upgraded client without a counselor',NULL),(3,'Upgraded client with a blank counselor','')");
    foreach(['3.0.8','3.0.9','3.0.10','3.0.12'] as $version) $migrate($root.'/dist/migrations/mig_'.$version.'.sql');
    check($shape()===$installed,'A real 3.0.7 database upgraded with mig_3.0.8, mig_3.0.9, mig_3.0.10 and mig_3.0.12 has exactly the fresh-install schema, defaults included');
    check((int)$db->query('SELECT counselor_verified FROM clients WHERE id=1')->fetchColumn()===1,'The upgrade keeps an existing client\'s counselor email confirmed');
    check((int)$db->query('SELECT COUNT(*) FROM clients WHERE id IN (2,3) AND counselor_verified=0')->fetchColumn()===2,'The upgrade leaves a client with no counselor email unconfirmed, so a later merge cannot fill in a confirmed address');
    $db->exec("UPDATE clients SET counselor_verified=0 WHERE id=1");
    $migrate($root.'/dist/migrations/mig_3.0.12.sql');
    check((int)$db->query('SELECT counselor_verified FROM clients WHERE id=1')->fetchColumn()===0,'Running mig_3.0.12 again never confirms a counselor email that is waiting for confirmation');
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $db->exec('DROP TABLE `' . str_replace('`','``',$table) . '`');
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (glob($tmp . '/*') ?: [] as $file) unlink($file);
    rmdir($tmp);
}
