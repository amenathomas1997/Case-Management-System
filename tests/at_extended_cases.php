<?php
require_once $root.'/public/inc/at_ai.php';
require_once $root.'/public/inc/at_homework.php';
$context=at_ai_context('homework','instructions',['track_id'=>'100','title'=>'Read a document','instructions'=>'AT synthetic client will practice'],1,'Use short steps');
check(str_contains($context['prompt'],'Read a document')&&!str_contains($context['prompt'],'password_hash'),'Homework AI uses only the selected task and scoped training context');
try{at_ai_context('setup','settings',['client_id'=>'3','track_id'=>'101'],1);check(false,'Cross-client AI track should fail');}catch(DomainException $e){check(true,'AI rejects a track belonging to another client');}
// Exercise the actual governance path with a substituted HTTP transport; no network call.
$originalCore=file_get_contents($tmp.'/core.php');$aiSource=file_get_contents($root.'/public/inc/ai.php');
$start=strpos($aiSource,'function ai_http(');$end=$start===false?false:strpos($aiSource,"/**\n * Send a system",$start);
if($start===false||$end===false)throw new RuntimeException('Test harness: could not locate ai_http() in inc/ai.php. Refusing to continue: the real network transport would be used.');
$stub='function ai_http(string $url,array $headers,string $body): array { file_put_contents('.var_export($tmp.'/ai_request.json',true).', $body); return [200,["choices"=>[["message"=>["content"=>"Synthetic editable practice draft"]]]],""]; }' . "\n\n";
file_put_contents($tmp.'/ai.php',substr($aiSource,0,$start).$stub.substr($aiSource,$end));
file_put_contents($tmp.'/core.php',swap_required(var_export($root.'/public/inc',true)." . '/ai.php'",var_export($tmp.'/ai.php',true),$originalCore,'the temporary core.php (AI transport stub)'));
// The stored key stays the suite's synthetic secret, which no page or response may show.
foreach(['ai_enabled'=>'1','ai_api_key'=>$secrets['ai'],'ai_provider'=>'openai','ai_redact'=>'1','ai_feat_draft'=>'1'] as $key=>$value)$db->prepare('INSERT INTO settings(`name`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$key,$value]);
try{
    $db->exec("UPDATE clients SET name='Syntheticlong Sensitiveperson' WHERE id=3");
    $db->prepare('INSERT INTO smart_goals(track_id,specific_goal,measurable_metric) VALUES(100,?,?)')->execute([str_repeat('x',75).' Syntheticlong Sensitiveperson practices','Synthetic metric']);
    $question='How is Syntheticlong Sensitiveperson doing?';
    $call('ai_ask',['question'=>$question]);
    $sent=file_get_contents($tmp.'/ai_request.json');
    check(!str_contains($sent,'Syntheticlong')&&!str_contains($sent,'Sensitiveperson')&&str_contains($sent,'C3'),'Mobile caseload AI tokenizes questions and redacts names before truncation');
    $askSource=swap_required("require __DIR__ . '/inc/bootstrap.php';",'require '.var_export($tmp.'/bootstrap.php',true).';',file_get_contents($root.'/public/ask.php'),'public/ask.php');
    $askSource=str_replace('__DIR__',var_export($root.'/public',true),$askSource);file_put_contents($tmp.'/ask.php',$askSource);
    $process=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page_post',$tmp.'/ask.php','{}',json_encode(['ask'=>'1','question'=>$question])],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    $sent=file_get_contents($tmp.'/ai_request.json');
    check($code===0&&$errors===''&&!str_contains($sent,'Syntheticlong')&&!str_contains($sent,'Sensitiveperson')&&str_contains($sent,'C3'),'Web caseload AI tokenizes questions and redacts names before truncation');
    $db->exec("UPDATE clients SET name='AT synthetic client' WHERE id=3");
    $count=(int)$db->query('SELECT COUNT(*) FROM homework')->fetchColumn();
    $draft=$call('at_ai_draft',['kind'=>'homework','field'=>'instructions','values'=>['track_id'=>'100','instructions'=>'AT synthetic client practices reading'],'instruction'=>'Make this a short exercise']);
    $sent=file_get_contents($tmp.'/ai_request.json');
    check($draft['text']==='Synthetic editable practice draft'&&str_contains($sent,'[CLIENT]')&&!str_contains($sent,'AT synthetic client'),'AI provider receives redacted context and returns an editable draft');
    check((int)$db->query('SELECT COUNT(*) FROM homework')->fetchColumn()===$count,'AI drafting does not save or assign homework');
    $call('at_ai_draft',['kind'=>'rate','field'=>'hourly_cost','values'=>['staff_id'=>'1','due_date'=>'2026-01-01']],[],false);
    $db->exec("UPDATE settings SET value='0' WHERE `name`='ai_feat_draft'");
    $call('at_ai_draft',['kind'=>'homework','field'=>'instructions','values'=>['track_id'=>'100']],[],false);
    check(true,'AI cannot change numeric cost fields and respects the feature switch');
}finally{file_put_contents($tmp.'/core.php',$originalCore);$db->exec("UPDATE settings SET value='0' WHERE `name`='ai_enabled'");}
$call('assign_homework',['track_id'=>100,'title'=>'Original exercise','instructions'=>'Original instructions']);
$homeworkRows=$call('homework',[],['client_id'=>3])['homework'];$homeworkId=(int)$homeworkRows[0]['id'];$token=$homeworkRows[0]['edit_token'];
$call('at_homework_update',['id'=>$homeworkId,'edit_token'=>$token,'title'=>'Reviewed exercise','instructions'=>'Reviewed AI instructions','due_date'=>'2026-11-01']);
$call('at_homework_update',['id'=>$homeworkId,'edit_token'=>$token,'title'=>'Stale edit','instructions'=>'Old text'],[],false);
check(at_homework_load($homeworkId,1)['instructions']==='Reviewed AI instructions','Homework revisions save explicitly and reject stale overwrites');
// Two simultaneous allocations compete for one purchased seat.
$raceLicense=$save('license',['vendor'=>'Synthetic','product'=>'Race test','seats'=>'1','due_date'=>'2099-01-01','responsible'=>'Business']);
$db->beginTransaction();$db->query('SELECT id FROM at_records WHERE id='.$raceLicense.' FOR UPDATE');$workers=[];
foreach([3,4] as $cid){
    $env=getenv();$env['CMS_TEST_INPUT']=json_encode(['kind'=>'license_seat','title'=>'Concurrent seat','values'=>['client_id'=>(string)$cid,'parent_id'=>(string)$raceLicense]]);
    $process=proc_open([PHP_BINARY,$root.'/tests/full_schema.php','api',$tmp.'/api.php',json_encode(['action'=>'at_save'])],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);$workers[]=[$process,$pipes];
}
// Release the parent lock only once both workers are queued behind it (as race()
// in audit_regression.php does); otherwise they could simply run one after the
// other and pass without at_save taking any lock. Each worker is a fresh PHP process
// loading the whole application, so on a busy machine (a build running beside the
// suite) the second can take well over 10 seconds to queue: 30 seconds are allowed.
$queued=null;$deadline=microtime(true)+30;
// Only the waits on at_records count: another transaction waiting elsewhere on the
// server at that moment must not make the count 3. Some MariaDB builds (the CI
// service container) do not list a row-lock wait in INNODB_TRX, so a statement on
// at_records still running in the process list counts too; the larger is used.
$waitSql="SELECT GREATEST((SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_query LIKE '%at_records%'),"
    ."(SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND COMMAND='Query' AND INFO LIKE '%at_records%FOR UPDATE%'))";
do{usleep(50000);try{$queued=(int)$db->query($waitSql)->fetchColumn();}catch(PDOException $e){$queued=null;usleep(1000000);break;}}while($queued<2&&microtime(true)<$deadline);
$db->commit();$success=0;$workerSaid=[];
foreach($workers as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);check($code===0,'Concurrent seat request returned a handled response');$success+=(int)(json_decode($output,true)['ok']??false);$workerSaid[]=mb_strimwidth(trim($output.' '.$errors),0,300,'…');}
// Without the PROCESS privilege the queue cannot be seen; a fixed pause stands in.
if($queued!==null)check($queued===2,'Both concurrent seat requests waited on the license lock'.($queued===2?'':' (waiting on at_records: '.$queued.'; the workers said: '.implode(' | ',$workerSaid).')'));
check($success===1&&(int)$db->query("SELECT COUNT(*) FROM at_records WHERE parent_id=$raceLicense AND status='Assigned'")->fetchColumn()===1,'Concurrent requests cannot overallocate a license');
// Reminder delivery and deduplication share a transaction.
$db->exec("UPDATE at_records SET due_date=CURDATE(),status='Scheduled' WHERE id=$outcome");
$cron=file_get_contents($root.'/cron/at_reminders.php');$start=strpos($cron,'foreach([');$end=$start===false?false:strpos($cron,'if(!at_ready())',$start);
if($start===false||$end===false)throw new RuntimeException('Test harness: could not locate the core.php lookup in cron/at_reminders.php. Refusing to continue: the unmodified script would load the real configuration.');
$cron=substr($cron,0,$start).'require '.var_export($tmp.'/core.php',true).'; require_once '.var_export($root.'/public/inc/at_services.php',true).';'."\n".substr($cron,$end);file_put_contents($tmp.'/at_reminders.php',$cron);
$db->exec("CREATE TRIGGER at_test_notification_fail BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic notification failure'");
$runCron=function()use($tmp):int{$p=proc_open([PHP_BINARY,$tmp.'/at_reminders.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($p);};
check($runCron()!==0&&(int)$db->query('SELECT COUNT(*) FROM at_reminders')->fetchColumn()===0,'Failed notification delivery does not consume the reminder');
$db->exec('DROP TRIGGER at_test_notification_fail');check($runCron()===0,'Due AT reminders are generated');$count=(int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
check($runCron()===0&&(int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===$count,'Repeated cron runs do not duplicate AT reminders');
// Rerunning the upgrade preserves existing service records.
$count=(int)$db->query('SELECT COUNT(*) FROM at_records')->fetchColumn();
$db->exec(file_get_contents($root.'/dist/migrations/mig_2.73.0.sql'));
check((int)$db->query('SELECT COUNT(*) FROM at_records')->fetchColumn()===$count,'AT migration is safely repeatable');
// Legacy loan entry points must honor quarantine as well.
$db->exec("INSERT INTO equipment(id,name,status) VALUES(103,'Quarantined device','Available')");
$quarantine=$save('reuse',['equipment_id'=>'103','received_on'=>'2026-10-01','inspection'=>'No','sanitized'=>'No','data_wiped'=>'No','repairs_done'=>'No']);
$db->exec("UPDATE equipment SET status='Available' WHERE id=103");
try { loan_equipment(103,3,null,null,1);check(false,'Quarantined loan should fail'); }
catch(DomainException $e) {check(true,'Legacy equipment lending also rejects incomplete reuse checks');}
require_once $root.'/public/inc/client_export.php';
$caseData=client_export_records($db,3);
check(count($caseData['at_services'])>0&&!array_filter($caseData['at_services'],fn($r)=>in_array($r['kind'],['cost','rate'],true)),'Case export includes AT records without internal cost data');
// The rows themselves must move: at_records cascades on client delete, so a
// count of 0 left on the merged client would pass even if they had been deleted.
$mergedAt=array_map('intval',$db->query('SELECT id FROM at_records WHERE client_id=4')->fetchAll(PDO::FETCH_COLUMN));
check($mergedAt!==[],'The merged client holds AT records before the merge');
$db->beginTransaction();move_client_records($db,3,4);$db->exec('DELETE FROM clients WHERE id=4');$db->commit();
check((int)$db->query('SELECT COUNT(*) FROM at_records WHERE client_id=3 AND id IN ('.implode(',',$mergedAt).')')->fetchColumn()===count($mergedAt),'Client merge preserves new AT assignments under the retained client');
$db->exec("INSERT INTO invoices(track_id,invoice_number,total_amount,status) VALUES(100,'AT-DRAFT',999,'Draft')");
$profitRows=$call('at_profitability')['rows'];$trackProfit=array_values(array_filter($profitRows,fn($r)=>$r['id']===100))[0];
check($trackProfit['invoiced']==200,'Draft invoices do not inflate recognized service revenue');
// New models decode populated real API responses.
$fixtures['at_catalog']=$call('at_catalog');$fixtures['at_list']=$call('at_list',[],['kind'=>'setup']);$fixtures['at_detail']=$call('at_detail',[],['id'=>$setup]);$fixtures['at_detail']['summary']=(object)$fixtures['at_detail']['summary'];$fixtures['at_matches']=$call('at_matches');$fixtures['at_profitability']=$call('at_profitability');$fixtures['at_issue_choices']=$call('at_issue_choices',[],['id'=>$repair]);$fixtures['homework']=$call('homework',[],['client_id'=>3]);
$p=proc_open(['python3',$root.'/tests/ios_models.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($fixtures));fclose($pipes[0]);echo stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,'iOS decodes new AT workflows and editable homework: '.$errors);
