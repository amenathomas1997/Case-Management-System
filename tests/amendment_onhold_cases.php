<?php
// Included by full_schema.php after session_changes_cases.php, against its synthetic database
// only: amendment requests from sent to answered, and On Hold with a reason (3.0.44).
$db->exec("INSERT INTO clients(name,status,assigned_to,counselor_name,counselor_email,counselor_verified) VALUES('Amend synthetic client','Active Training',1,'AM Counselor','am-counselor@example.invalid',1)"); $ac=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Amend track','Active','AM-1','Synthetic VR',20,50,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 20 DAY))")->execute([$ac]); $at=(int)$db->lastInsertId();

/* Record the request as sent */
[$code,,$err]=$renderPage('amendment_request',['track'=>$at],['record_request'=>'1','track'=>(string)$at,'requested_on'=>date('Y-m-d'),'sent_to'=>'AM Counselor']);
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM amendment_requests WHERE track_id=$at")->fetchColumn()===0,'A request must say what was asked for' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('amendment_request',['track'=>$at],['record_request'=>'1','track'=>(string)$at,'requested_on'=>date('Y-m-d'),'sent_to'=>'AM Counselor','hours'=>'10','new_end'=>date('Y-m-d',strtotime('+120 days')),'letter'=>'Synthetic amendment letter']);
$req=$db->query("SELECT * FROM amendment_requests WHERE track_id=$at")->fetch();
check($code===0 && $err==='' && $req && $req['status']==='Awaiting reply' && (float)$req['hours_requested']===10.0
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE track_id=$at AND body LIKE '%Synthetic amendment letter%'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='amend-{$req['id']}' AND status='Open'")->fetchColumn()===1,
    'A request recorded as sent keeps what was asked, the letter as a track note, and a follow-up task' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('track',['id'=>$at]);
check($code===0 && $err==='' && str_contains($out,'Amendment requested on'),'The track says an amendment is awaiting a reply' . ($err ? ": $err" : ''));
/* Record the reply, then the approved change on the track, linked */
[$code,,$err]=$renderPage('amendment_request',['track'=>$at],['record_reply'=>'1','track'=>(string)$at,'request_id'=>(string)$req['id'],'reply_status'=>'Approved','replied_on'=>date('Y-m-d'),'reply_note'=>'10 hours approved']);
$req=$db->query("SELECT * FROM amendment_requests WHERE id={$req['id']}")->fetch();
check($code===0 && $err==='' && $req['status']==='Approved' && $db->query("SELECT status FROM tasks WHERE auto_key='amend-{$req['id']}'")->fetchColumn()==='Done','The reply is recorded and the follow-up task completed' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('track_edit',['id'=>$at,'amend_request'=>$req['id']]);
check($code===0 && $err==='' && str_contains($out,'name="amend_request"') && str_contains($out,'VR approved the amendment requested'),'The track edit page opens with the approved request linked' . ($err ? ": $err" : ''));

/* On Hold needs a reason; check-ins follow */
$edit=['name'=>'Amend synthetic client','status'=>'On Hold','client_type'=>'VR'];
[$code,$out,$err]=$renderPage('client_edit',['id'=>$ac],$edit);
check($code===0 && $db->query("SELECT status FROM clients WHERE id=$ac")->fetchColumn()==='Active Training','On Hold is refused without a reason' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client_edit',['id'=>$ac],$edit+['status_note'=>'Waiting for the authorization','on_hold_until'=>date('Y-m-d')]);
$c=$db->query("SELECT status,on_hold_reason,on_hold_until FROM clients WHERE id=$ac")->fetch();
check($code===0 && $err==='' && $c['status']==='On Hold' && $c['on_hold_reason']==='Waiting for the authorization' && $c['on_hold_until']===date('Y-m-d'),'On Hold saves the reason and the expected return date' . ($err ? ": $err" : ''));
$cronRun('daily_alerts.php',true);
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE client_id=$ac AND auto_key LIKE 'onhold-%' AND status='Open'")->fetchColumn()===1,'The return date opens one check-in task');
$call('save_client',['id'=>$ac,'name'=>'Amend synthetic client','status'=>'Active Training']);
$c=$db->query("SELECT on_hold_reason FROM clients WHERE id=$ac")->fetch();
$cronRun('daily_alerts.php',true);
check($c['on_hold_reason']===null && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE client_id=$ac AND auto_key LIKE 'onhold-%' AND status='Open'")->fetchColumn()===0,'Back to Active Training, the reason is cleared and the check-in closes');
$call('save_client',['id'=>$ac,'name'=>'Amend synthetic client','status'=>'On Hold'],[],false,'Say why');
