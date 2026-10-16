<?php
// Included by full_schema.php after request_offers_cases.php, against its synthetic database
// only: the mail log, the backup check and the Graph secret expiry (3.0.53).
$before=(int)$db->query('SELECT COUNT(*) FROM mail_log')->fetchColumn();
mail_log_record('admin@example.invalid','graph',true); // no real send from the test process (CI has no mailer)
$ml=$db->query('SELECT * FROM mail_log ORDER BY id DESC LIMIT 1')->fetch();
check((int)$db->query('SELECT COUNT(*) FROM mail_log')->fetchColumn()===$before+1 && in_array($ml['via'],['graph','mail','sink'],true) && !str_contains(json_encode($ml),'example.invalid'),'Each email is logged by route and result, without the address');
$db->exec("INSERT INTO clients(name,status,counselor_email) VALUES('Mail Type Person','Active Training','typed.counselor@example.test')");
check(mail_recipient_type('Typed.Counselor@example.test')==='counselor' && mail_recipient_type('nobody@nowhere.test')==='other','Recipients are logged by kind');

/* The backup check */
$bdir=dirname((string)cfg('upload_dir')).'/backups';
if (!is_dir($bdir)) mkdir($bdir,0700,true);
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$good=$bdir.'/cms_29991231_000000.sql.gz';
file_put_contents($good,gzencode(implode("\n",array_map(fn($t)=>"CREATE TABLE `$t` (id int);",$tables))));
$bc=backup_check();
check($bc['ok'] && str_contains($bc['detail'],'holds all '.count($tables).' tables') && str_starts_with((string)setting('backup_check_result'),'OK'),'The backup check passes a complete dump');
file_put_contents($good,gzencode(implode("\n",array_map(fn($t)=>"CREATE TABLE `$t` (id int);",array_slice($tables,1)))));
$bc=backup_check();
check(!$bc['ok'] && str_contains($bc['detail'],'missing 1 table') && str_starts_with((string)setting('backup_check_result'),'PROBLEM'),'The backup check finds a missing table');
file_put_contents($good,substr(gzencode(str_repeat('x',200000)),0,500));
check(!backup_check()['ok'],'The backup check finds a damaged dump');
unlink($good);
[$code,$out,$err]=$renderPage('system_health',[]);
check($code===0 && $err==='' && str_contains($out,'Check the backup now') && str_contains($out,'Send a test email to me') && str_contains($out,'Recent emails'),'System health offers the backup check, a test email and the mail log' . ($err ? ": $err" : ''));

/* Graph secret expiry */
[$code,,$err]=$renderPage('system_health',[],['save_graph_expiry'=>'1','graph_expires'=>date('Y-m-d',strtotime('+14 days'))]);
check(graph_secret_days_left()===14,'The Graph secret expiry date is recorded' . ($err ? ": $err" : ''));
set_setting('graph_expiry_noticed','');
$cronRun('daily_alerts.php',true);
check(setting('graph_expiry_noticed')===date('Y-m-d') && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE message LIKE 'The Microsoft Graph email secret expires in 14 days%'")->fetchColumn()>=1,'Administrators are warned 14 days before the secret expires');
set_setting('graph_credential_expires','');
