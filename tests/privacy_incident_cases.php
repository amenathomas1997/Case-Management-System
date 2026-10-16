<?php
// Included by full_schema.php after privacy_followup_cases.php, against its synthetic database
// only: the privacy incident log and requests for a copy or correction of records (3.0.38).
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('Incident synthetic client','Active Training','incident-client@example.invalid',1)"); $ic=(int)$db->lastInsertId();

/* Report, assess, notify, close */
[$code,,$err]=$renderPage('privacy_incidents',[],['report_incident'=>'1','kind'=>'Misdirected email or report','discovered_on'=>date('Y-m-d',strtotime('-50 days')),
    'description'=>'A progress report went to the wrong counselor','client_id'=>(string)$ic,'info_involved'=>'Name, goals and hours']);
$pi=$db->query("SELECT * FROM privacy_incidents ORDER BY id DESC LIMIT 1")->fetch();
check($code===0 && $err==='' && $pi && $pi['notice_deadline']===date('Y-m-d',strtotime('-50 days +60 days'))
    && (int)$db->query("SELECT COUNT(*) FROM privacy_incident_clients WHERE incident_id={$pi['id']} AND client_id=$ic")->fetchColumn()===1,
    'A privacy incident is reported with its affected client, and the notice deadline is 60 days from discovery' . ($err ? ": $err" : ''));
$pid=(int)$pi['id'];
$renderPage('privacy_incidents',['id'=>$pid],['incident_id'=>(string)$pid,'close_incident'=>'1']);
check($db->query("SELECT status FROM privacy_incidents WHERE id=$pid")->fetchColumn()==='Open','An incident is not closed before its risk assessment');
$cronRun('daily_alerts.php',true);
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='privinc-$pid' AND status='Open'")->fetchColumn()===1,'Two weeks before the deadline one task is opened');
[$code,,$err]=$renderPage('privacy_incidents',['id'=>$pid],['incident_id'=>(string)$pid,'save_risk'=>'1','risk_level'=>'Breach: notice required','risk_nature'=>'Names and goals','risk_recipient'=>'Another counselor','risk_acquired'=>'Opened','risk_mitigation'=>'Deletion confirmed']);
check($code===0 && $err==='' && $db->query("SELECT risk_level FROM privacy_incidents WHERE id=$pid")->fetchColumn()==='Breach: notice required','The risk assessment is saved' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('privacy_incidents',['id'=>$pid],['incident_id'=>(string)$pid,'add_event'=>'1','event_type'=>'Notice','recipient'=>'Affected person','notified_client'=>(string)$ic,
    'happened_on'=>date('Y-m-d'),'method'=>'Letter','detail'=>'Notice letter sent']);
check($code===0 && $err==='' && $db->query("SELECT notified_at FROM privacy_incident_clients WHERE incident_id=$pid AND client_id=$ic")->fetchColumn()===date('Y-m-d')
    && (int)$db->query("SELECT COUNT(*) FROM privacy_incident_events WHERE incident_id=$pid AND event_type='Notice'")->fetchColumn()===1,'A notice is logged and marks the person notified' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('privacy_incidents',['id'=>$pid]);
check($code===0 && $err==='' && str_contains($out,'audit.php?client='.$ic) && str_contains($out,'Notice letter sent'),'The incident links to the client\'s access history and shows the notices' . ($err ? ": $err" : ''));
$renderPage('privacy_incidents',['id'=>$pid],['incident_id'=>(string)$pid,'close_incident'=>'1']);
check($db->query("SELECT status FROM privacy_incidents WHERE id=$pid")->fetchColumn()==='Closed'
    && $db->query("SELECT status FROM tasks WHERE auto_key='privinc-$pid'")->fetchColumn()==='Done','Closing the incident completes its deadline task');
// The affected person's name is kept when their record is removed.
$db->exec("INSERT INTO clients(name,status) VALUES('Removed incident synthetic','Closed - Other')"); $gone=(int)$db->lastInsertId();
$db->prepare("INSERT INTO privacy_incident_clients(incident_id,client_id,client_label) VALUES(?,?,?)")->execute([$pid,$gone,'Removed incident synthetic']);
$db->exec("DELETE FROM clients WHERE id=$gone");
check($db->query("SELECT client_label FROM privacy_incident_clients WHERE incident_id=$pid AND client_id IS NULL")->fetchColumn()==='Removed incident synthetic','An affected person stays listed by name after their record is removed');

/* Requests for a copy of records, or a correction */
$token=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$ic,$token]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,,$err]=$renderPage('portal',['t'=>$token],['new_request'=>'1','req_type'=>'records','details'=>'Please send me a copy of my file']);
putenv('CMS_TEST_ANON');
$rq=$db->query("SELECT * FROM portal_requests WHERE client_id=$ic AND type='records'")->fetch();
check($code===0 && $err==='' && $rq && $rq['due_on']===date('Y-m-d',strtotime('+30 days')),'A client asks for a copy of their records in the portal; it is due in 30 days' . ($err ? ": $err" : ''));
$rid=(int)$rq['id'];
$renderPage('portal_requests',[],['set_status'=>'1','id'=>(string)$rid,'to'=>'Done']);
check($db->query("SELECT status FROM portal_requests WHERE id=$rid")->fetchColumn()==='New','A records request is not closed without the outcome the client sees');
[$code,,$err]=$renderPage('portal_requests',[],['publish_copy'=>'1','id'=>(string)$rid]);
$doc=$db->query("SELECT * FROM client_documents WHERE client_id=$ic AND category='Resource' ORDER BY id DESC LIMIT 1")->fetch();
check($code===0 && $err==='' && $doc && str_ends_with($doc['path'],'.html'),'A copy of the records is published to the client\'s portal' . ($err ? ": $err" : ''));
$copy=client_records_copy_html($ic);
check(str_contains($copy,'Incident synthetic client') && !str_contains($copy,'hourly') && !str_contains($copy,'invoice'),'The copy holds the record and leaves out billing');
[$code,,$err]=$renderPage('portal_requests',[],['set_status'=>'1','id'=>(string)$rid,'to'=>'Done','outcome'=>'Published to your portal']);
check($code===0 && $err==='' && $db->query("SELECT outcome FROM portal_requests WHERE id=$rid")->fetchColumn()==='Published to your portal','The request closes with its outcome' . ($err ? ": $err" : ''));
$list=$call('portal_requests',[],['all'=>'1']);
$mine=array_values(array_filter($list['requests'],fn($r)=>$r['id']===$rid))[0]??[];
check(($mine['type_label']??'')==='Copy of records' && ($mine['outcome']??'')==='Published to your portal','The app is given the request type, due date and outcome');
$db->prepare("INSERT INTO portal_requests(client_id,type,details,due_on) VALUES(?,'correction','My date of birth is wrong',DATE_ADD(CURDATE(),INTERVAL 30 DAY))")->execute([$ic]); $cr=(int)$db->lastInsertId();
$call('portal_request_update',['id'=>$cr,'to'=>'Declined'],[],false,'Say why');
$call('portal_request_update',['id'=>$cr,'to'=>'Declined','outcome'=>'The date on file matches your ID']);
check($db->query("SELECT status FROM portal_requests WHERE id=$cr")->fetchColumn()==='Declined','The app closes a correction request only with a reason');
