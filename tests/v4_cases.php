<?php
// Included by full_schema.php after staff_import_cases.php, against its synthetic database
// only: 4.0.0's voice without the AI service, new voice answers and session outcomes,
// email alerts, bulk actions, call and directions links, the calendar feed, the equipment
// hand-off and the app's submit_invoice.
$secs=voice_sections();
check((voice_builtin_match('Go to the schedule',$secs)['section']??'')==='schedule' && (voice_builtin_match('search for braille',$secs)['query']??'')==='braille'
    && (voice_builtin_match('what are my alerts?',$secs)['ask']??'')==='unread_alerts' && (voice_builtin_match("what's Pat Smith's phone number",$secs)['client']??'')==='pat smith'
    && (voice_builtin_match('mark Pat Smith as a no show',$secs)['create']??'')==='no_show' && (voice_builtin_match('cancel the next session with Pat Smith',$secs)['create']??'')==='cancel_session'
    && voice_builtin_match('add a note for Pat saying she practised',$secs)===null,'Fixed voice requests are understood without the AI service; free-form ones are left for it');
check(!ai_enabled(),'(the test database has no AI service)');
check((voice_interpret(['transcript'=>'go to tasks'],$voiceUser)['url']??'')==='tasks.php','Voice still navigates with the AI service off');
$free=voice_interpret(['transcript'=>'write a lovely poem about braille'],$voiceUser);
check(($free['action']??'')==='answer' && str_contains((string)$free['speak'],'AI service is off'),'A free-form request says what still works when the AI service is off');
$db->prepare("INSERT INTO notifications(user_id,message) VALUES(1,'Synthetic voice alert')")->execute();
check(str_contains((string)(voice_interpret(['transcript'=>'read my alerts'],$voiceUser)['speak']??''),'Synthetic voice alert'),'Voice reads unread alerts');
$db->exec("INSERT INTO clients(name,status,assigned_to,phone,counselor_name,counselor_phone) VALUES('Voicey Contactperson','Active Training',1,'555-0142','Cara Counselor','555-0199')"); $vc=(int)$db->lastInsertId();
$ct=voice_interpret(['transcript'=>"what's Voicey Contactperson's phone number"],$voiceUser);
check(str_contains((string)$ct['speak'],'5 5 5 0 1 4 2') && str_contains((string)$ct['speak'],'Cara Counselor'),'Voice reads a client\'s and their counselor\'s phone, digit by digit');
check(str_contains((string)voice_interpret(['transcript'=>'what is not billed yet'],$voiceUser)['speak'],'invoiced') || str_contains((string)voice_interpret(['transcript'=>'what is not billed yet'],$voiceUser)['speak'],'Nothing is waiting'),'Voice answers what is not billed yet');
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 5 DAY),DATE_ADD(NOW(),INTERVAL 5 DAY)+INTERVAL 1 HOUR,'Scheduled')")->execute([$vc]); $vs=(int)$db->lastInsertId();
$conf=voice_interpret(['transcript'=>'cancel Voicey Contactperson\'s next session'],$voiceUser);
check(($conf['action']??'')==='confirm' && ($conf['do']??'')==='set_session_outcome' && (int)$conf['params']['session_id']===$vs,'Cancelling a session by voice asks for confirmation first');
$done=voice_handle(['do'=>'set_session_outcome']+$conf['params'],$voiceUser);
check(!empty($done['ok']) && $db->query("SELECT status FROM scheduled_sessions WHERE id=$vs")->fetchColumn()==='Cancelled','After a yes, the session is cancelled with the app\'s rules');
check(str_contains((string)voice_handle(['do'=>'set_session_outcome']+$conf['params'],$voiceUser)['speak'],'already closed'),'It is cancelled once');

/* Alerts by email */
[$code,,$err]=$renderPage('account',[],['save_alert_email'=>'1','alert_email'=>'now']);
check($code===0 && $db->query('SELECT alert_email FROM users WHERE id=1')->fetchColumn()==='now','Each person chooses how alerts reach them' . ($err ? ": $err" : ''));
$db->exec("UPDATE users SET alert_email='daily' WHERE id=1");
set_setting('alert_digest_1','');
$cronRun('daily_alerts.php',true);
check(setting('alert_digest_1')===date('Y-m-d'),'Unread alerts are emailed once a day to those who chose it');

/* Bulk actions, links, calendar, hand-off */
add_task('Bulk one',null,null,null,null,1,1); add_task('Bulk two',null,null,null,null,1,1);
$bt=array_map('intval',$db->query("SELECT id FROM tasks WHERE title IN ('Bulk one','Bulk two')")->fetchAll(PDO::FETCH_COLUMN));
[$code,,$err]=$renderPage('tasks',[],['bulk_done'=>'1','bulk_ids'=>array_map('strval',$bt)]);
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE title IN ('Bulk one','Bulk two') AND status='Done'")->fetchColumn()===2,'Several tasks are completed at once' . ($err ? ": $err" : ''));
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,DATE_SUB(NOW(),INTERVAL 3 HOUR),DATE_SUB(NOW(),INTERVAL 2 HOUR),'Scheduled'),(?,1,DATE_SUB(NOW(),INTERVAL 5 HOUR),DATE_SUB(NOW(),INTERVAL 4 HOUR),'Scheduled')")->execute([$vc,$vc]);
$ended=array_map('strval',$db->query("SELECT id FROM scheduled_sessions WHERE client_id=$vc AND status='Scheduled' AND end_at<NOW()")->fetchAll(PDO::FETCH_COLUMN));
[$code,,$err]=$renderPage('schedule',[],['bulk_outcome'=>'1','bulk_to'=>'No Show','bulk_ids'=>$ended]);
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$vc AND status='No Show'")->fetchColumn()===2,'Several ended sessions are recorded as no-shows at once' . ($err ? ": $err" : ''));
require_once $root.'/public/inc/layout.php';
check(str_contains(tel_link('555-0142','Pat'),'href="tel:5550142"') && str_contains(directions_link('1 Main St'),'daddr=1%20Main%20St'),'Phones are call links and addresses have directions');
$tok=bin2hex(random_bytes(24)); $db->prepare("UPDATE users SET cal_token=? WHERE id=1")->execute([$tok]);
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 2 DAY)+INTERVAL 1 HOUR,'Scheduled')")->execute([$vc]);
$db->prepare("INSERT INTO access_bookings(client_id,service_type,needed_at,duration_min,status,created_by) VALUES(?,'Interpreter',DATE_ADD(NOW(),INTERVAL 3 DAY),60,'Confirmed',1)")->execute([$vc]);
// The feed loads core.php directly (tests/calendar_cases.php wrote the test copy).
$fp=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page',$tmp.'/calfeed.php',json_encode(['u'=>$tok])],[1=>['pipe','w'],2=>['pipe','w']],$fpipes);
$out=stream_get_contents($fpipes[1]);$err=stream_get_contents($fpipes[2]);fclose($fpipes[1]);fclose($fpipes[2]);proc_close($fp);
check(str_contains($out,'BEGIN:VALARM') && str_contains($out,'Support booking: Voicey C.') && !str_contains($out,'Interpreter'),'The calendar feed has reminders and support bookings, without naming the kind of support' . ($err ? ": $err" : ''));
$db->exec("INSERT INTO equipment(name,category,item_condition,status) VALUES('Handoff Display','Braille Display','Good','Available')"); $he=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('equipment',[],['loan_out'=>'1','equipment_id'=>(string)$he,'client_id'=>(string)$vc,'condition_out'=>'Good','accepted_name'=>'Voicey Contactperson']);
$hl=$db->query("SELECT * FROM equipment_loans WHERE equipment_id=$he")->fetch();
check($hl && $hl['condition_out']==='Good' && $hl['accepted_name']==='Voicey Contactperson' && $hl['accepted_at']!==null,'A loan records its condition and the client\'s typed acceptance' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('equipment',[],['return_item'=>'1','loan_id'=>(string)$hl['id'],'condition_in'=>'Needs Repair']);
check($db->query("SELECT condition_in FROM equipment_loans WHERE id={$hl['id']}")->fetchColumn()==='Needs Repair' && $db->query("SELECT item_condition FROM equipment WHERE id=$he")->fetchColumn()==='Needs Repair','Damage found on return updates the item' . ($err ? ": $err" : ''));

/* Review fixes: caseload scope, no-show timing, voice patterns, token limit */
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Otherscope Person','Active Training',3)"); $oc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Scope track','Active','SC-1','Synthetic VR',500,77,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 20 DAY))")->execute([$oc]); $otk=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by) VALUES(?,CURDATE(),300,'Synthetic other caseload work',3)")->execute([$otk]);
$all=(string)voice_answer_more('billing_unbilled','',['id'=>1])['speak'];
$GLOBALS['CURRENT_SCOPED']=true;
$mine=(string)voice_answer_more('billing_unbilled','',['id'=>1])['speak'];
$GLOBALS['CURRENT_SCOPED']=false;
check(str_contains($all,'hours worth'),'(another caseload\'s unbilled work counts for everyone)');
check($all!==$mine && (float)$mine < (float)$all,'Voice billing answers count only the caseload of someone limited to their own');
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 2 DAY)+INTERVAL 1 HOUR,'Scheduled')")->execute([$vc]); $fs=(int)$db->lastInsertId();
check(session_set_outcome($fs,'No Show',1,'test')!==null && $db->query("SELECT status FROM scheduled_sessions WHERE id=$fs")->fetchColumn()==='Scheduled','A session that has not started cannot be marked a no-show, from any channel');
check(voice_builtin_match('cancel my next appointment',$secs)===null && voice_builtin_match('add a note that John was a no show',$secs)===null
    && voice_builtin_match('add a note that Maria is managing her email',$secs)===null && voice_builtin_match('remind me to check expiring authorizations for Pat',$secs)===null,'Voice never reads a pronoun as a client, and a note mentioning a question is left for the AI');
check((voice_builtin_match('unpaid invoices',$secs)['ask']??'')==='invoices_unpaid' && (voice_builtin_match('record Pat Smith as a no show',$secs)['create']??'')==='no_show','The plain questions and commands still match');
$db->prepare("INSERT INTO api_tokens(user_id,token_hash,created_at,expires_at) VALUES(1,?,DATE_SUB(NOW(),INTERVAL 85 DAY),DATE_ADD(NOW(),INTERVAL 2 DAY))")->execute([hash('sha256',str_repeat('9',64))]); $oldTok=(int)$db->lastInsertId();
putenv('CMS_TEST_TOKEN=' . str_repeat('9',64)); $call('me'); putenv('CMS_TEST_TOKEN');
$left=(int)$db->query("SELECT TIMESTAMPDIFF(HOUR,NOW(),expires_at) FROM api_tokens WHERE id=$oldTok")->fetchColumn();
check($left>=5*24-1 && $left<=5*24,'An app sign-in is renewed in use, but never past 90 days from signing in');
$db->exec("DELETE FROM api_tokens WHERE id=$oldTok");
