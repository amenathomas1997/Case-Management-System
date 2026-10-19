<?php
// Included by full_schema.php after the v427 cases, against its synthetic database only:
// 4.28.0 jobs and integrations (Software update with a database behind its files, mig_3.0.8 run
// again, the audit address, unknown email and text outcomes, the backup filter, the test restore,
// the Outlook sync's false "moved" alert, installer errors and the authorization import's lock).

// ---- N14: Software update waits while the database is behind the files already on the site ----
require_once $root.'/public/inc/updater.php';
$site428=$tmp.'/upd428'; @mkdir("$site428/docroot/inc",0777,true); @mkdir("$site428/private",0777,true);
file_put_contents("$site428/docroot/index.php",'old index 428');
$savedPrivate428=$GLOBALS['CMS_PRIVATE_DIR'] ?? null; $GLOBALS['CMS_PRIVATE_DIR']="$site428/private";
putenv("CMS_TEST_UPDATE_ROOT=$site428/docroot");
$k428=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); putenv('CMS_TEST_UPDATE_KEY='.openssl_pkey_get_details($k428)['key']);
$zip428="$tmp/upd428.zip"; @unlink($zip428);
$z428=new ZipArchive(); $z428->open($zip428,ZipArchive::CREATE);
$files428=['docroot/index.php'=>'new index 428','docroot/inc/migrations/mig_98.0.0.sql'=>"CREATE TABLE IF NOT EXISTS v428_update_probe (id int);\n"];
$m428=['version'=>'98.0.0','files'=>[]];
foreach ($files428 as $f=>$d) { $z428->addFromString($f,$d); $m428['files'][$f]=hash('sha256',$d); }
$z428->addFromString('manifest.json',json_encode($m428)); $z428->close();
$schema428=setting('schema_version');
set_setting('schema_version','4.0.0');
$plan428=updater_inspect($zip428);
check(str_contains(implode(' ',$plan428['problems']),'The database is version 4.0.0 but the files on this site need version '.DB_SCHEMA_VERSION.'. Run Database upgrade first'),
    'While the database is behind the files already on the site, Software update says to run Database upgrade first');
$err428=null; try { updater_apply($zip428,$plan428,1); } catch (RuntimeException $e) { $err428=$e->getMessage(); }
$plan428['problems']=[]; // a plan made earlier, before the database fell behind (a restored copy)
$err428b=null; try { updater_apply($zip428,$plan428,1); } catch (RuntimeException $e) { $err428b=$e->getMessage(); }
check($err428!==null && str_contains($err428,'not started') && $err428b!==null && str_contains($err428b,'Run Database upgrade first')
    && file_get_contents("$site428/docroot/index.php")==='old index 428' && $db->query("SHOW TABLES LIKE 'v428_update_probe'")->fetchColumn()===false && setting('schema_version')==='4.0.0',
    'Nothing is installed and the database version is not moved past the changes still waiting');
set_setting('schema_version',DB_SCHEMA_VERSION);
check(updater_inspect($zip428)['problems']===[] && updater_db_behind()===null,'Once Database upgrade has run, the update can be installed');
set_setting('schema_version',(string)$schema428);
putenv('CMS_TEST_UPDATE_ROOT'); putenv('CMS_TEST_UPDATE_KEY');
if ($savedPrivate428===null) unset($GLOBALS['CMS_PRIVATE_DIR']); else $GLOBALS['CMS_PRIVATE_DIR']=$savedPrivate428;
check(str_contains((string)file_get_contents($root.'/public/update.php'),'<a href="upgrade.php">Open Database upgrade</a>'),'Software update links to Database upgrade when that is what stops it');

// ---- mig_3.0.8 can run again: a custom document category is kept ----
$db->exec("INSERT INTO clients(name,status,client_type,state) VALUES('V428 Documents Synthetic Client','Active Training','VR','IA')"); $c428=(int)$db->lastInsertId();
$db->prepare("INSERT INTO client_documents(client_id,category,title,orig_name,path,uploaded_by) VALUES(?,?,?,?,?,NULL)")->execute([$c428,'V428 Synthetic Custom Category','Synthetic document','synthetic.pdf','v428/synthetic.pdf']); $d428=(int)$db->lastInsertId();
[$n428,$merr428]=run_migration_file($root.'/public/inc/migrations/mig_3.0.8.sql');
$col428=$db->query("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_documents' AND COLUMN_NAME='category'")->fetch();
check($merr428===null && $db->query("SELECT category FROM client_documents WHERE id=$d428")->fetchColumn()==='V428 Synthetic Custom Category'
    && $col428['COLUMN_TYPE']==='varchar(60)' && trim((string)$col428['COLUMN_DEFAULT'],"'")==='Other',
    'mig_3.0.8 runs again without error, keeps a custom document category, and never makes it a client upload'.($merr428?": $merr428":''));
check(file_get_contents($root.'/public/inc/migrations/mig_3.0.8.sql')===file_get_contents($root.'/dist/migrations/mig_3.0.8.sql'),'Both copies of mig_3.0.8 are the same');
$db->exec("DELETE FROM client_documents WHERE id=$d428");

// ---- audit() records the person's address, as client_ip() reads it ----
$srv428=[$_SERVER['REMOTE_ADDR'] ?? null,$_SERVER['HTTP_X_FORWARDED_FOR'] ?? null];
$app428=$GLOBALS['CONFIG']['app'] ?? [];
$GLOBALS['CONFIG']['app']['trust_proxy']=true; unset($GLOBALS['CONFIG']['app']['trusted_proxies']);
$_SERVER['REMOTE_ADDR']='10.0.0.2'; $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.28';
audit(null,'v428.ip_probe');
$ip428=$db->query("SELECT ip FROM audit_log WHERE action='v428.ip_probe' ORDER BY id DESC LIMIT 1")->fetchColumn();
unset($_SERVER['REMOTE_ADDR'],$_SERVER['HTTP_X_FORWARDED_FOR']);
audit(null,'v428.ip_job');
$ipJob428=$db->query("SELECT ip FROM audit_log WHERE action='v428.ip_job' ORDER BY id DESC LIMIT 1")->fetchColumn();
check($ip428==='198.51.100.28' && $ipJob428===null,'Behind a trusted proxy an audit row holds the person\'s address (what the SSO failure cap counts); a job\'s row holds none');
$GLOBALS['CONFIG']['app']=$app428;
if ($srv428[0]!==null) $_SERVER['REMOTE_ADDR']=$srv428[0];
if ($srv428[1]!==null) $_SERVER['HTTP_X_FORWARDED_FOR']=$srv428[1];
$db->exec("DELETE FROM audit_log WHERE action IN ('v428.ip_probe','v428.ip_job')");

// ---- The nightly backup leaves out an authenticator being set up ----
$sh428=(string)file_get_contents($root.'/cron/backup.sh');
preg_match('/^SECRET_SETTINGS="(.*)"$/m',$sh428,$s1); preg_match('/^SETTINGS_WHERE="(.*)"$/m',$sh428,$s2);
$where428=str_replace('$SECRET_SETTINGS',$s1[1]??'',$s2[1]??'');
$in428=function(string $name) use ($db,$where428): bool { $q=$db->prepare("SELECT COUNT(*) FROM (SELECT CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci AS name) s WHERE $where428"); $q->execute([$name]); return (int)$q->fetchColumn()===1; };
check($where428!=='' && !$in428('totp_pending_7') && !$in428('totp_pending_1234') && $in428('cfg.app.timezone') && $in428('billing_terms_days'),'The nightly backup leaves out authenticator seeds still being set up, and keeps the ordinary settings');

// ---- Session reminders: an email whose outcome is unknown is not sent again, and is reported once ----
$db->exec("INSERT INTO clients(name,email,email_verified,status,client_type,state) VALUES('V428 Reminder Synthetic Client','v428-reminder@example.invalid',1,'Active Training','VR','IA')"); $rc428=(int)$db->lastInsertId();
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES($rc428,1,DATE_ADD(NOW(),INTERVAL 20 HOUR),DATE_ADD(NOW(),INTERVAL 21 HOUR),'Scheduled')"); $rs428=(int)$db->lastInsertId();
$warned428=fn():int=>(int)$db->query("SELECT COUNT(*) FROM notifications WHERE link='client.php?id=$rc428' AND message LIKE 'A session reminder to %may not have arrived%'")->fetchColumn();
putenv('CMS_TEST_MAIL_OUTCOME=unknown');
[$code428,$mail428]=$cronRun('session_reminders.php',true);
$after428=$warned428();
[$code428b]=$cronRun('session_reminders.php',true);
putenv('CMS_TEST_MAIL_OUTCOME');
check((int)$db->query("SELECT reminder_sent FROM scheduled_sessions WHERE id=$rs428")->fetchColumn()===1 && $after428>=1 && $warned428()===$after428,
    'A reminder the mail service did not confirm counts as sent (no second reminder on the next run), and the administrators are told once');
check(str_contains((string)file_get_contents($root.'/cron/daily_alerts.php'),"if (\$o === 'unknown') \$uncertainTo[] = \$uid;") && str_contains((string)file_get_contents($root.'/cron/monthly_reports.php'),"\$outcome === 'unknown'"),
    'The daily alerts and the monthly reports treat an unconfirmed email the same way');

// ---- Texts: a reply that never came is recorded as unknown, and the text is not sent again ----
$smsWas428=$GLOBALS['CONFIG']['sms'] ?? null;
$GLOBALS['CONFIG']['sms']=['account_sid'=>'AC-synthetic','auth_token'=>'synthetic-token','from'=>'+15155550100'];
putenv('CMS_TEST_SMS=timeout');
$db->exec("INSERT INTO clients(name,phone,status,client_type,state,sms_consent_at) VALUES('V428 Text Synthetic Client','515-555-0128','Active Training','VR','IA',NOW())"); $tc428=(int)$db->lastInsertId();
$db->prepare("INSERT INTO sms_log(direction,client_id,phone,body,purpose,status) VALUES('out',?,?,?,'message','queued')")->execute([$tc428,'+15155550128','Synthetic text']); $tl428=(int)$db->lastInsertId();
$tr428=sms_dispatch($tl428);
$row428=$db->query("SELECT status, error FROM sms_log WHERE id=$tl428")->fetch();
$again428=sms_dispatch($tl428);
check($tr428['status']==='unknown' && $row428['status']==='unknown' && str_contains((string)$row428['error'],'not sent again') && $again428['status']==='failed',
    'A text the service did not confirm in time is recorded as unknown, not failed, and is never sent a second time');
check(str_contains((string)file_get_contents($root.'/cron/session_reminders.php'),"['sent', 'held', 'unknown']"),'The reminder job marks a session whose reminder text is unconfirmed, so it is not texted again');
putenv('CMS_TEST_SMS');
if ($smsWas428===null) unset($GLOBALS['CONFIG']['sms']); else $GLOBALS['CONFIG']['sms']=$smsWas428;

// ---- The monthly test restore empties the scratch database whatever happens ----
$ops428=(string)file_get_contents($root.'/public/inc/ops.php');
check(preg_match('/\} finally \{\s*if \(\$gz\) @gzclose\(\$gz\);\s*if \(\$scratch\) \{\s*try \{ \$empty\(\); \}/',$ops428)===1,'A failed test restore still drops the client records it loaded into the scratch database');

// ---- Outlook: a session moved on the website during a sync is not reported as moved in Outlook ----
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.ms_client_id','synthetic-ms-id'),('cfg.meet.ms_client_secret','synthetic-ms-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['meet']=($GLOBALS['CONFIG']['meet'] ?? [])+['ms_client_id'=>'synthetic-ms-id','ms_client_secret'=>'synthetic-ms-secret'];
$db->exec("INSERT INTO users (id,name,email,password_hash,role,calendar_sync) VALUES (4281,'V428 Calendar Synthetic Instructor','v428-cal@example.invalid','test','instructor',1)");
meeting_store(4281,'microsoft',['access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','expires_in'=>3600,'scope'=>'offline_access User.Read OnlineMeetings.ReadWrite Calendars.ReadWrite'],'v428-cal@example.invalid');
$db->exec("INSERT INTO clients(name,status,assigned_to,state) VALUES('Vera Synthetic-Client','Active Training',4281,'IA')"); $cc428=(int)$db->lastInsertId();
$t428=strtotime('tomorrow 15:00');
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,4281,?,?,'Scheduled')")->execute([$cc428,date('Y-m-d H:i:s',$t428),date('Y-m-d H:i:s',$t428+3600)]); $cs428=(int)$db->lastInsertId();
// The link as the sync read it, before the website moved the session from 13:00 to 15:00 and moved its event.
$db->prepare("INSERT INTO calendar_links(session_id,user_id,event_id,pushed_hash,event_start,event_end) VALUES(?,4281,'v428-event',?,?,?)")
    ->execute([$cs428,str_repeat('0',40),date('Y-m-d H:i:s',$t428-7200),date('Y-m-d H:i:s',$t428-3600)]);
$g428=fn(int $ts)=>['dateTime'=>gmdate('Y-m-d\TH:i:s',$ts).'.0000000','timeZone'=>'UTC'];
$fake428=fn(int $start)=>putenv('CMS_TEST_MEETINGS='.json_encode(['https://graph.microsoft.com/v1.0/me/calendarView'=>['body'=>['value'=>[['id'=>'v428-event','start'=>$g428($start),'end'=>$g428($start+3600),'showAs'=>'busy']]]],
    'https://graph.microsoft.com/v1.0/me/events'=>['status'=>200,'body'=>['id'=>'v428-event']]]));
$moved428=fn():int=>(int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=4281 AND message LIKE 'You moved the session%'")->fetchColumn();
$fake428($t428);
$cr428=calendar_sync_user(4281);
check($cr428['noticed']===0 && $moved428()===0 && $db->query("SELECT outlook_change FROM calendar_links WHERE session_id=$cs428")->fetchColumn()===null,
    'An event where the website just put the session is not reported as a move made in Outlook');
$db->exec("UPDATE calendar_links SET outlook_change=NULL, event_start='".date('Y-m-d H:i:s',$t428)."' WHERE session_id=$cs428");
$fake428($t428+7200);
$cr428b=calendar_sync_user(4281);
check($cr428b['noticed']===1 && $moved428()===1,'A real move made in Outlook is still reported');
putenv('CMS_TEST_MEETINGS');

// ---- Installer: errors use the labels and link to their fields ----
$inst428=$tmp.'/inst428'; @mkdir($inst428,0777,true);
copy($root.'/dist/install.php',"$inst428/install.php"); touch("$inst428/install.php.enabled");
$ip428p=proc_open([PHP_BINARY,'-r','$_SERVER["REQUEST_METHOD"]="POST"; $_POST=["brand_short"=>"","db_name"=>"","base_url"=>"https://cms.example.invalid","timezone"=>"Nowhere/Synthetic"]; require $argv[1];',"$inst428/install.php"],[1=>['pipe','w'],2=>['pipe','w']],$ipp);
$instOut428=stream_get_contents($ipp[1]); fclose($ipp[1]); fclose($ipp[2]); proc_close($ip428p);
check(str_contains($instOut428,'<a href="#brand_short">Short brand is required.</a>') && str_contains($instOut428,'<a href="#db_name">Database name is required.</a>')
    && str_contains($instOut428,'id="brand_short" name="brand_short" required autocomplete="off" value="" aria-invalid="true" aria-describedby="err-brand_short"')
    && str_contains($instOut428,'<p class="field-error" id="err-brand_short">Short brand is required.</p>') && str_contains($instOut428,'<a href="#timezone">Time zone:')
    && !str_contains($instOut428,"Field '"),
    'The installer names each field by its label, links each error to its field, and marks the field invalid');

// ---- Authorization import: no lock on the whole tracks table ----
$ci428=(string)file_get_contents($root.'/public/client_import.php');
check(!str_contains($ci428,'FOR UPDATE') && str_contains($ci428,"GET_LOCK(CONCAT('cms_auth_'") && str_contains($ci428,'RELEASE_LOCK'),'The import re-checks each authorization under its own named lock, not by locking every track');
$a428=$tmp.'/v428_auth_import.csv';
file_put_contents($a428,"client_name,auth_number,funding_agency,authorized_hours,hourly_rate\nV428 Documents Synthetic Client,AUTH-428-JOBS-1,State VR,10,50\n");
putenv('CMS_TEST_FILES='.json_encode(['csv'=>$a428])); putenv('CMS_TEST_SESSION_ADD='.json_encode(['client_import_tokens'=>['tok428a']]));
[$code,$out,$err]=$renderPage('client_import',[],['do_import'=>'1','kind'=>'authorizations','import_token'=>'tok428a']);
check((int)$db->query("SELECT COUNT(*) FROM training_tracks WHERE auth_number='AUTH-428-JOBS-1' AND client_id=$c428")->fetchColumn()===1,'An authorization still imports'.($err?": $err":''));
putenv('CMS_TEST_FILES'); putenv('CMS_TEST_SESSION_ADD');

// ---- Restore instructions: the key file for authenticator seeds is copied by hand, never emailed ----
$off428=(string)file_get_contents($root.'/cron/weekly_offsite.php');
check(str_contains($off428,'meeting_tokens.key file is never in the backup') && str_contains($off428,'reset_2fa.php')
    && str_contains((string)file_get_contents($root.'/dist/restore.php'),"Copy meeting_tokens.key from the old server's private folder") && str_contains((string)file_get_contents($root.'/cron/backup.sh'),'cron/reset_2fa.php'),
    'The restore instructions say to copy the key file across, or to use reset_2fa.php without it');
