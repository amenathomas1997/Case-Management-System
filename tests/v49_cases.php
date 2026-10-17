<?php
// Included by full_schema.php after v48_cases.php, against its synthetic database only:
// 4.9.0's multi-session scheduler: several days a week, a list of dates, or spread over the
// authorization, shown first and booked only for the confirmed list.
$monday=strtotime('next monday 10:00'); $len=5400;
[$o,$e]=session_plan(['plan_pattern'=>'days','plan_days'=>['1','3'],'repeat_weeks'=>'2'],$monday,$monday+$len,null);
check($e===null && count($o)===4 && date('N',$o[1][0])==='3' && date('H:i',$o[3][0])==='10:00','Monday and Wednesday for two weeks gives four sessions at the same time');
[$o,$e]=session_plan(['plan_pattern'=>'days','plan_days'=>['5'],'plan_until'=>date('Y-m-d',strtotime('+20 days',$monday))],$monday,$monday+$len,null);
check($e===null && count($o)===3 && date('N',$o[0][0])==='5','Fridays until a last date');
$d1=date('Y-m-d',strtotime('+3 days')); $d2=date('Y-m-d',strtotime('+9 days'));
[$o,$e]=session_plan(['plan_pattern'=>'dates','plan_dates'=>"$d2 2:30 PM\n$d1\n$d1"],$monday,$monday+$len,null);
check($e===null && count($o)===2 && date('Y-m-d H:i',$o[0][0])===$d1.' 10:00' && date('H:i',$o[1][0])==='14:30','A list of dates is sorted, repeats dropped, and a line can give its own time');
[$o,$e,$f]=session_plan(['plan_pattern'=>'dates','plan_dates'=>date('Y-m-d',strtotime('-1 day'))],$monday,$monday+$len,null);
check($e!==null && $f==='plan_dates' && str_contains($e,'in the past'),'A date in the past is refused, naming the line');
[$o,$e,$f]=session_plan(['plan_pattern'=>'weekly','repeat_weeks'=>'3'],$monday,$monday+$len,null);
check($e===null && count($o)===3 && date('N',$o[2][0])==='1' && session_plan(['plan_pattern'=>'weekly','repeat_weeks'=>'27'],$monday,$monday+$len,null)[2]==='repeat_weeks','Weekly repeats work as before, and too many weeks is reported on Repeat weekly');
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Plan Synthetic Client','Active Training',1)"); $pc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Plan track','Active','PLAN-1','Synthetic VR',10,50,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 120 DAY))")->execute([$pc]); $ptk=(int)$db->lastInsertId();
$ptrack=$db->query("SELECT * FROM training_tracks WHERE id=$ptk")->fetch();
[$o,$e]=session_plan(['plan_pattern'=>'fill','plan_days'=>['1']],$monday,$monday+$len,$ptrack);
check($e===null && count($o)===6 && session_plan_hours($o)===9.0,'Spread over a 10-hour authorization, 1.5-hour Monday sessions stop at six (9 hours)');
/* Through the schedule page: shown first, booked once confirmed */
$post=['add_session'=>'1','client_id'=>(string)$pc,'track_id'=>(string)$ptk,'staff_id'=>'1','date'=>date('Y-m-d',$monday),'time'=>'10:00','duration'=>'1.5','plan_pattern'=>'days','plan_days'=>['2','4'],'repeat_weeks'=>'2'];
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('schedule',[],$post));
$before=(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$pc")->fetchColumn();
check($before===0 && str_contains($sess,'plan_preview') && str_contains($sess,'Book these 4 sessions'),'A plan is shown first and nothing is booked' . ($err ? ": $err" : ''));
preg_match('/"hash";s:\d+:"([0-9a-f]{64})"/',$sess,$hm);
[$code,,$err]=$renderPage('schedule',[],$post+['plan_ok'=>str_repeat('0',64)]);
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$pc")->fetchColumn()===0,'A confirmation for a different list books nothing');
[$code,,$err]=$renderPage('schedule',[],$post+['plan_ok'=>$hm[1] ?? '']);
$rows=$db->query("SELECT COUNT(*) n, COUNT(DISTINCT series_uid) s FROM scheduled_sessions WHERE client_id=$pc")->fetch();
check((int)$rows['n']===4 && (int)$rows['s']===1,'Once confirmed, the four sessions are booked as one series' . ($err ? ": $err" : ''));

/* An authorization warning and the plan confirmed in one go */
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Plan Warning Client','Active Training',1)"); $pw=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Short track','Active','PLAN-2','Synthetic VR',100,50,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 10 DAY))")->execute([$pw]); $pwt=(int)$db->lastInsertId();
$wpost=['add_session'=>'1','client_id'=>(string)$pw,'track_id'=>(string)$pwt,'staff_id'=>'1','date'=>date('Y-m-d',$monday),'time'=>'10:00','duration'=>'1','plan_pattern'=>'days','plan_days'=>['1','3'],'repeat_weeks'=>'4'];
[[$code,,$err],$sess1]=$pageSession(fn()=>$renderPage('schedule',[],$wpost));
check(str_contains($sess1,'authorization_ack') && str_contains($sess1,'plan_ok'),'A plan past the authorization asks for the approval and the confirmation together');
preg_match('/"hash";s:\d+:"([0-9a-f]{64})"/',$sess1,$wh); preg_match('/"authorization_hash";s:\d+:"([^"]+)"/',$sess1,$ah);
[$code,,$err]=$renderPage('schedule',[],$wpost+['plan_ok'=>$wh[1] ?? '','authorization_ack'=>'1','authorization_hash'=>$ah[1] ?? '']);
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$pw")->fetchColumn()===8,'Both ticked at once, the plan is booked' . ($err ? ": $err" : ''));
