<?php
// Included by full_schema.php after portal_devices_cases.php, against its synthetic database
// only: moving a session, and cancelling the rest of a series or a whole group (3.0.43).
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Move synthetic client','Active Training',1)"); $mc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Move track','Active','MV-1','Synthetic VR',100,50,DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_ADD(CURDATE(),INTERVAL 60 DAY))")->execute([$mc]); $mt=(int)$db->lastInsertId();
$first=strtotime('+2 days 09:00');
$call('schedule_session',['client_id'=>$mc,'track_id'=>$mt,'staff_id'=>1,'date'=>date('Y-m-d',$first),'time'=>'09:00','duration'=>1,'repeat_weeks'=>3,'authorization_ack'=>true]);
$series=$db->query("SELECT id, series_uid FROM scheduled_sessions WHERE track_id=$mt ORDER BY start_at")->fetchAll();
check(count($series)===3 && $series[0]['series_uid']!==null && count(array_unique(array_column($series,'series_uid')))===1,'A weekly series shares one series ID');

/* Moving a session */
$s0=(int)$series[0]['id'];
// Another session of the same instructor at the new time: a warning to confirm first.
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,?,?,'Scheduled')")->execute([$mc,date('Y-m-d H:i:s',$first+86400),date('Y-m-d H:i:s',$first+86400+3600)]);
$moveTo=date('Y-m-d',$first+86400);
[$code,,$err]=$renderPage('schedule',[],['move_session'=>'1','session_id'=>(string)$s0,'move_date'=>$moveTo,'move_time'=>'09:30','move_duration'=>'1']);
check($code===0 && $err==='' && $db->query("SELECT start_at FROM scheduled_sessions WHERE id=$s0")->fetchColumn()===date('Y-m-d H:i:s',$first),'An overlapping new time is not taken until it is confirmed' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('schedule',[],['move_session'=>'1','session_id'=>(string)$s0,'move_date'=>$moveTo,'move_time'=>'09:30','move_duration'=>'1.5','move_confirm'=>'1']);
$moved=$db->query("SELECT * FROM scheduled_sessions WHERE id=$s0")->fetch();
check($code===0 && $err==='' && $moved['start_at']===$moveTo.' 09:30:00' && (int)$moved['ics_sequence']===1 && $moved['status']==='Scheduled' && (int)$moved['reminder_sent']===0
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='session.move' AND entity_id=$s0")->fetchColumn()===1,
    'Confirmed, the session keeps its record and moves, with its calendar sequence raised and the move audited' . ($err ? ": $err" : ''));
$row=$db->query("SELECT * FROM scheduled_sessions WHERE id=$s0")->fetch();
[$ics]=client_sessions_ics([$row],'x@example.invalid','REQUEST',false);
check(str_contains($ics,'SEQUENCE:1') && str_contains($ics,"cms-client-session-$s0@"),'The client\'s calendar update keeps the same ID with a higher sequence');
$api=$call('move_session',['session_id'=>$s0,'start_at'=>date('Y-m-d',$first+86400).' 09:00','duration'=>1]);
check(($api['moved']??null)===false && !empty($api['warnings']),'The app is given the warnings to confirm');
$api=$call('move_session',['session_id'=>$s0,'start_at'=>date('Y-m-d',$first+3*86400).' 14:00','duration'=>1]);
check(($api['moved']??null)===true && $db->query("SELECT start_at FROM scheduled_sessions WHERE id=$s0")->fetchColumn()===date('Y-m-d',$first+3*86400).' 14:00:00','The app moves a session');

/* Cancelling the rest of a series, and a whole group */
$s1=(int)$series[1]['id'];
[$code,,$err]=$renderPage('schedule',[],['cancel_series'=>'1','session_id'=>(string)$s1]);
$st=$db->query("SELECT id, status FROM scheduled_sessions WHERE track_id=$mt ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
check($code===0 && $err==='' && $st[$s0]==='Scheduled' && $st[$s1]==='Cancelled' && $st[(int)$series[2]['id']]==='Cancelled','Cancelling from a session cancels it and the later ones in its series, not the earlier' . ($err ? ": $err" : ''));
$g='grp'.substr(bin2hex(random_bytes(4)),0,8);
foreach([0,1] as $k) $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,group_uid) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 4 DAY),DATE_ADD(NOW(),INTERVAL 4 DAY) + INTERVAL 1 HOUR,'Scheduled',?)")->execute([$mc,$g]);
$gid=(int)$db->lastInsertId();
$r=$call('cancel_group',['session_id'=>$gid]);
check(($r['cancelled']??0)===2 && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE group_uid='$g' AND status='Cancelled'")->fetchColumn()===2,'The app cancels a whole group in one step');
