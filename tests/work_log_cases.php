<?php
// Included by full_schema.php after plan_handover_cases.php, against its synthetic database
// only: correcting logged work, mileage-only entries, the goal and service at session
// completion, the app's work-log actions and "my week" (3.0.46).
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Worklog synthetic client','Active Training',1)"); $wc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,mileage_rate,start_date,expiration_date) VALUES(?,'Worklog track','Active','WL-1','Synthetic VR',5,50,0.5,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY))")->execute([$wc]); $wt=(int)$db->lastInsertId();
$db->prepare("INSERT INTO smart_goals(track_id,specific_goal,measurable_metric,status) VALUES(?,'Use the screen reader for email','Sends 5 emails','In Progress')")->execute([$wt]); $wg=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,mileage,description,created_by,hourly_rate,mileage_rate) VALUES(?,CURDATE(),2,10,'Synthetic session',1,50,0.5)")->execute([$wt]); $wl=(int)$db->lastInsertId();
$row=fn()=>$db->query("SELECT hours,mileage FROM work_logs WHERE id=$wl")->fetch();

/* Correcting hours and miles */
[$code,,$err]=$renderPage('track',['id'=>$wt],['correct_log'=>'1','log_id'=>(string)$wl,'fix_hours'=>'2.5','fix_miles'=>'10','fix_reason'=>'']);
check($code===0 && (float)$row()['hours']===2.0,'A correction needs a reason' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('track',['id'=>$wt],['correct_log'=>'1','log_id'=>(string)$wl,'fix_hours'=>'9','fix_miles'=>'10','fix_reason'=>'Typo']);
check($code===0 && (float)$row()['hours']===2.0,'A correction that would go over the authorized hours is refused' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('track',['id'=>$wt],['correct_log'=>'1','log_id'=>(string)$wl,'fix_hours'=>'2.5','fix_miles'=>'12','fix_reason'=>'Session ran long']);
check($code===0 && $err==='' && (float)$row()['hours']===2.5 && (float)$row()['mileage']===12.0
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='hours.correct' AND detail LIKE '%Session ran long%'")->fetchColumn()===1,'An unbilled entry is corrected, with the change and reason audited' . ($err ? ": $err" : ''));
$db->exec("UPDATE work_logs SET is_billed=1 WHERE id=$wl");
check(work_log_correct($wl,$wt,1,0,'x',1)!==null,'An invoiced entry cannot be corrected');
$db->exec("UPDATE work_logs SET is_billed=0 WHERE id=$wl");

/* A mileage-only entry */
[$code,,$err]=$renderPage('track',['id'=>$wt],['add_log'=>'1','work_date'=>date('Y-m-d'),'hours'=>'0','mileage'=>'15','description'=>'Drive to the client\'s workplace']);
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$wt AND hours=0 AND mileage=15")->fetchColumn()===1,'Hours 0 with miles saves a mileage-only entry' . ($err ? ": $err" : ''));

/* The goal worked on at session completion */
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status) VALUES(?,?,1,DATE_SUB(NOW(),INTERVAL 2 HOUR),DATE_SUB(NOW(),INTERVAL 1 HOUR),'Scheduled')")->execute([$wc,$wt]); $ws=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('schedule',[],['set_status'=>'1','session_id'=>(string)$ws,'to'=>'Completed_separate','session_hours'=>'1','session_goal'=>(string)$wg]);
$done=$db->query("SELECT w.goal_id FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$ws")->fetch();
check($code===0 && $err==='' && $done && (int)$done['goal_id']===$wg,'Completing a session records the goal worked on' . ($err ? ": $err" : ''));

/* The app */
$logs=$call('work_logs',[],['track_id'=>$wt]);
check(count($logs['logs'])>=3 && in_array($wl,array_column($logs['logs'],'id'),true),'The app lists a track\'s entries');
$call('correct_log',['id'=>$wl,'hours'=>10,'mileage'=>0,'reason'=>'x'],[],false,'authorized');
$call('correct_log',['id'=>$wl,'hours'=>2,'mileage'=>12,'reason'=>'Back to two hours']);
check((float)$row()['hours']===2.0,'The app corrects an entry');
$call('delete_log',['id'=>$wl]);
check((int)$db->query("SELECT COUNT(*) FROM work_logs WHERE id=$wl")->fetchColumn()===0 && (int)$db->query("SELECT COUNT(*) FROM trash WHERE entity='work_logs' AND entity_id=$wl")->fetchColumn()===1,'The app moves an unbilled entry to the trash');
$week=$call('my_week');
check(isset($week['logged'],$week['target']) && str_contains($week['summary'],'This week'),'The app is given the person\'s week');
[$code,$out,$err]=$renderPage('index',[]);
check($code===0 && $err==='' && str_contains($out,'id="my-week"'),'The Dashboard shows your week' . ($err ? ": $err" : ''));
