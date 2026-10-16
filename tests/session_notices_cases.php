<?php
// Included by full_schema.php after client_notices_cases.php, against its synthetic database
// only: counselor no-show and authorization emails, client calendar invites, the counselor
// portal's attendance, reports and receipt, and the client portal's past sessions (3.0.41).
// Nothing here calls the mailer in this process (the CI runner has none): sends go through
// pages, the API or the cron job, which have their own.
$vrcEmail='sn-counselor@example.invalid';
$db->prepare("INSERT INTO clients(name,status,email,email_verified,counselor_name,counselor_email,counselor_verified) VALUES('Session notice synthetic','Active Training','sn-client@example.invalid',1,'SN Counselor',?,1)")->execute([$vrcEmail]); $sc=(int)$db->lastInsertId();
$vrcToken=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token,expires_at) VALUES(?,'SN Counselor',?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$vrcEmail,$vrcToken]); $vrcId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'SN track','Active','SN-AUTH-1','Synthetic VR',10,50,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 200 DAY))")->execute([$sc]); $st=(int)$db->lastInsertId();

/* A no-show emails the counselor, once */
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status) VALUES(?,?,1,DATE_SUB(NOW(),INTERVAL 3 HOUR),DATE_SUB(NOW(),INTERVAL 2 HOUR),'Scheduled')")->execute([$sc,$st]); $ns=(int)$db->lastInsertId();
$call('set_session_status',['session_id'=>$ns,'to'=>'No Show']);
check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='noshow-$ns'")->fetchColumn()===1,'A no-show emails the client\'s confirmed counselor');
[$subj,$body]=email_template('counselor_noshow',['name'=>'C','date'=>'Monday','link'=>'https://example.invalid/v']);
check(!str_contains($subj.$body,'Session notice synthetic') && str_contains($body,'https://example.invalid/v'),'The no-show email names no client and carries the portal link');
// A cancellation does not email the counselor.
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status) VALUES(?,?,1,DATE_SUB(NOW(),INTERVAL 5 HOUR),DATE_SUB(NOW(),INTERVAL 4 HOUR),'Scheduled')")->execute([$sc,$st]); $cs=(int)$db->lastInsertId();
$call('set_session_status',['session_id'=>$cs,'to'=>'Cancelled']);
check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='noshow-$cs'")->fetchColumn()===0,'A cancellation does not email the counselor');

/* An authorization nearly used up emails the counselor, once per crossing */
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description) VALUES(?,CURDATE(),8.5,'Synthetic hours')")->execute([$st]);
$cronRun('daily_alerts.php',true);
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM sent_alerts WHERE track_id=$st AND alert_key='vrc80'")->fetchColumn()===1,'At 80% of the authorized hours the counselor is emailed, once');
[$subj,$body]=email_template('counselor_auth_low',['name'=>'C','auth'=>'SN-AUTH-1','used'=>'8.5 of 10 hours used','link'=>'https://example.invalid/v']);
check(str_contains($subj,'SN-AUTH-1') && !str_contains($subj.$body,'Session notice synthetic'),'The authorization email gives the authorization number, not the client');

/* Calendar invites for the client */
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status,location) VALUES(?,?,1,DATE_ADD(NOW(),INTERVAL 3 DAY),DATE_ADD(NOW(),INTERVAL 3 DAY) + INTERVAL 1 HOUR,'Scheduled','Synthetic office')")->execute([$sc,$st]); $fs=(int)$db->lastInsertId();
$row=$db->query("SELECT * FROM scheduled_sessions WHERE id=$fs")->fetch();
[$ics]=client_sessions_ics([$row],'sn-client@example.invalid','REQUEST',false);
[$cancel]=client_sessions_ics([$row],'sn-client@example.invalid','CANCEL',false);
check(str_contains($ics,"UID:cms-client-session-$fs@") && str_contains($ics,'METHOD:REQUEST') && str_contains($cancel,'METHOD:CANCEL') && str_contains($cancel,"UID:cms-client-session-$fs@") && str_contains($cancel,'STATUS:CANCELLED'),
    'A session\'s invite and its cancellation share one calendar ID');
$db->exec("UPDATE clients SET a11y_contact='Postal mail' WHERE id=$sc");
check(client_session_notice([$fs],'booked')===false,'A client who prefers post is not emailed an invite');
$db->exec("UPDATE clients SET a11y_contact=NULL WHERE id=$sc");
[$code,,$err]=$renderPage('schedule',[],['set_status'=>'1','session_id'=>(string)$fs,'to'=>'Cancelled']);
check($code===0 && $err==='' && $db->query("SELECT status FROM scheduled_sessions WHERE id=$fs")->fetchColumn()==='Cancelled','Cancelling a future session sends the client a calendar cancellation' . ($err ? ": $err" : ''));

/* The counselor portal: attendance, a report opened again, receipt confirmed */
$db->prepare("INSERT INTO report_acks(client_id,track_id,token,sent_to) VALUES(?,?,?,?)")->execute([$sc,$st,bin2hex(random_bytes(24)),$vrcEmail]); $ack=(int)$db->lastInsertId();
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION='.json_encode(['vrc_ok_'.$vrcId=>true,'vrc_ok_'.$vrcId.'_at'=>time()]));
[$code,$out,$err]=$renderPage('vrc',['t'=>$vrcToken]);
check($code===0 && $err==='' && str_contains($out,'Attendance, last 90 days') && str_contains($out,'1 missed') && str_contains($out,'name="confirm_report"'),'The counselor portal shows attendance and a Confirm receipt button' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('vrc',['t'=>$vrcToken,'report'=>$ack]);
check($code===0 && $err==='' && str_contains($out,'This is the report as it reads today'),'The counselor opens a report again' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('vrc',['t'=>$vrcToken],['confirm_report'=>'1','ack_id'=>(string)$ack]);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
check($code===0 && $err==='' && $db->query("SELECT acked_at FROM report_acks WHERE id=$ack")->fetchColumn()!==null,'The counselor confirms receipt in the portal' . ($err ? ": $err" : ''));

/* The client portal: past sessions and Add to calendar */
$ptoken=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$sc,$ptoken]);
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status) VALUES(?,?,1,DATE_ADD(NOW(),INTERVAL 5 DAY),DATE_ADD(NOW(),INTERVAL 5 DAY) + INTERVAL 1 HOUR,'Scheduled')")->execute([$sc,$st]); $up=(int)$db->lastInsertId();
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$out,$err]=$renderPage('portal',['t'=>$ptoken]);
check($code===0 && $err==='' && str_contains($out,'Past sessions') && str_contains($out,'Missed') && str_contains($out,'ics='.$up),'The client portal lists past sessions and offers Add to calendar' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('portal',['t'=>$ptoken,'ics'=>$up]);
putenv('CMS_TEST_ANON');
check($code===0 && str_contains($out,'BEGIN:VCALENDAR') && str_contains($out,"cms-client-session-$up@"),'Add to calendar downloads that session');
