<?php
// Included by full_schema.php after release_3021_cases.php, against its synthetic
// database only: the 3.0.22 app parity actions (public/inc/api_parity.php) answer
// as their website pages do — tasks, alerts, training track validation, a
// permission refusal and caseload scope. ($call sends every request as POST.)
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');

/* ---- Tasks: add, list, complete (tasks.php's rules) ---- */
$taskTitle='Synthetic parity task '.bin2hex(random_bytes(3));
$call('save_task',['title'=>''],[],false,'needs a title');
$call('save_task',['title'=>$taskTitle,'due_date'=>'2026-02-31'],[],false,'Due date must be a valid date');
check((int)$db->query('SELECT COUNT(*) FROM tasks WHERE title='.$db->quote($taskTitle))->fetchColumn()===0,'save_task refuses a missing title and an impossible due date and saves nothing');
$added=$call('save_task',['title'=>$taskTitle,'details'=>'Synthetic details','due_date'=>date('Y-m-d',strtotime('-2 days')),'client_id'=>1]);
$task=$db->query('SELECT * FROM tasks WHERE id='.(int)$added['id'])->fetch();
check($task && $task['title']===$taskTitle && (int)$task['client_id']===1 && $task['status']==='Open' && (int)$task['created_by']===1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='task.add' AND detail=".$db->quote($taskTitle))->fetchColumn()===1,
    'save_task adds a task linked to a client and records task.add in the audit log, as the website does');
$listed=$call('tasks',[],['due'=>'overdue']);
$row=array_values(array_filter($listed['tasks'],fn($t)=>$t['id']===(int)$added['id']))[0]??null;
check($row!==null && $row['days_left']===-2 && $row['client_name']==='Kept synthetic client' && $row['can_open_client']===true,'tasks lists the new task under the overdue filter with its days left and client');
$call('save_task',['id'=>(int)$added['id'],'title'=>$taskTitle.' (edited)','due_date'=>'']);
check($db->query('SELECT title FROM tasks WHERE id='.(int)$added['id'])->fetchColumn()===$taskTitle.' (edited)'
    && $db->query('SELECT due_date FROM tasks WHERE id='.(int)$added['id'])->fetchColumn()===null,'save_task edits an open task and clears its due date');
$done=$call('complete_task',['id'=>(int)$added['id']]);
check($done['message']==='Task completed.' && $db->query('SELECT status FROM tasks WHERE id='.(int)$added['id'])->fetchColumn()==='Done','complete_task completes an open task');
$call('complete_task',['id'=>(int)$added['id']],[],false,'already in that state');
check(!in_array((int)$added['id'],array_column($call('tasks')['tasks'],'id'),true) && in_array((int)$added['id'],array_column($call('tasks',[],['show'=>'all'])['tasks'],'id'),true),
    'A completed task leaves the open list and stays under Everything; completing it twice is refused');
$call('complete_task',['id'=>(int)$added['id'],'reopen'=>true]);
check($db->query('SELECT status FROM tasks WHERE id='.(int)$added['id'])->fetchColumn()==='Open','complete_task with reopen reopens a done task');

/* ---- Alerts: list and mark read (notifications.php) ---- */
$db->exec("INSERT INTO notifications(user_id,message,link) VALUES(1,'Synthetic parity alert one','client.php?id=1'),(1,'Synthetic parity alert two',NULL),(2,'Another user alert',NULL)");
// A multi-row INSERT reports the first row's id.
$alertOne=(int)$db->lastInsertId(); $alertTwo=$alertOne+1; $otherAlert=$alertOne+2;
$alerts=$call('notifications');
$ids=array_column($alerts['notifications'],'id');
check(in_array($alertOne,$ids,true) && in_array($alertTwo,$ids,true) && !in_array($otherAlert,$ids,true) && $alerts['unread']>=2,'notifications lists only the signed-in user\'s alerts, with the unread count');
$marked=$call('mark_notifications_read',['ids'=>$alertOne.','.$otherAlert]);
check($marked['marked']===1 && $db->query("SELECT read_at FROM notifications WHERE id=$alertOne")->fetchColumn()!==null
    && $db->query("SELECT read_at FROM notifications WHERE id=$alertTwo")->fetchColumn()===null
    && $db->query("SELECT read_at FROM notifications WHERE id=$otherAlert")->fetchColumn()===null,
    'mark_notifications_read marks only the listed alerts of the signed-in user, never another user\'s');
$call('mark_notifications_read',['all'=>true]);
check((int)$db->query('SELECT COUNT(*) FROM notifications WHERE user_id=1 AND read_at IS NULL')->fetchColumn()===0 && $call('notifications',[],['count_only'=>'1'])['unread']===0,
    'mark_notifications_read with all marks every unread alert');

/* ---- Training tracks: track_edit.php's validation and amendment rules ---- */
$tracksBefore=(int)$db->query('SELECT COUNT(*) FROM training_tracks')->fetchColumn();
$bad=$call('save_track',['client_id'=>1,'title'=>'','auth_number'=>'','funding_agency'=>'State VR','authorized_hours'=>'0','hourly_rate'=>'50','start_date'=>'2026-03-01','expiration_date'=>'2026-02-01'],[],false,'Track title is required.');
check(isset($bad['errors']['title'],$bad['errors']['auth_number'],$bad['errors']['authorized_hours'],$bad['errors']['expiration_date'])
    && !isset($bad['errors']['hourly_rate']) && (int)$db->query('SELECT COUNT(*) FROM training_tracks')->fetchColumn()===$tracksBefore,
    'save_track lists every problem by field with the website\'s wording and creates nothing');
$call('save_track',['client_id'=>1,'title'=>'Synthetic parity track','auth_number'=>'PAR-1','funding_agency'=>'State VR','authorized_hours'=>'10','hourly_rate'=>'50','status'=>'Sideways'],[],false,'Status is not a valid choice');
$created=$call('save_track',['client_id'=>1,'title'=>'Synthetic parity track','auth_number'=>'PAR-1','funding_agency'=>'State VR','authorized_hours'=>'10','hourly_rate'=>'50','mileage_rate'=>'0.5']);
$again=$call('save_track',['client_id'=>1,'title'=>'Synthetic parity track','auth_number'=>'PAR-1','funding_agency'=>'State VR','authorized_hours'=>'10','hourly_rate'=>'50','mileage_rate'=>'0.5']);
$parTrack=(int)$created['id'];
check($parTrack>0 && $again['id']===$parTrack && !empty($again['duplicate']) && (int)$db->query('SELECT COUNT(*) FROM training_tracks')->fetchColumn()===$tracksBefore+1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='track.create' AND entity_id=$parTrack")->fetchColumn()===1,
    'save_track creates a track once (a resend answers with the same track) and records track.create');
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,hourly_rate) VALUES(?,'2026-01-08',1,'Unbilled parity log',50)")->execute([$parTrack]); $parLog=(int)$db->lastInsertId();
$edited=$call('save_track',['id'=>$parTrack,'title'=>'Synthetic parity track','auth_number'=>'PAR-1','funding_agency'=>'State VR','authorized_hours'=>'12','hourly_rate'=>'60','mileage_rate'=>'0.5','amendment_reason'=>'Synthetic extension']);
check($edited['amended']===true && $edited['repriced']===1 && (float)$db->query("SELECT hourly_rate FROM work_logs WHERE id=$parLog")->fetchColumn()===60.0
    && (int)$db->query("SELECT COUNT(*) FROM auth_amendments WHERE track_id=$parTrack AND reason='Synthetic extension'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('auth.amend','track.update') AND entity_id=$parTrack")->fetchColumn()===2,
    'Editing a track\'s hours and rate records the amendment, re-prices unbilled work and audits both entries, as track_edit.php does');

/* ---- Referral waitlist and the documentation review queue (workflows.php) ---- */
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name) VALUES('Synthetic','Waitlist','Synthetic','Referrer','Agency','referrer@example.invalid','555','Parity waitlist referral')");
$parRef=(int)$db->lastInsertId();
$wlItem=fn(array $r)=>array_values(array_filter($r['items'],fn($i)=>$i['referral_id']===$parRef))[0]??null;
$listed=$wlItem($call('waitlist'));
check($listed!==null && $listed['status']==='Not waitlisted' && $listed['waitlisted']===false && $listed['received_at']===date('Y-m-d') && $listed['client_name']==='Parity waitlist referral',
    'waitlist lists an open referral with no entry yet as Not waitlisted, with the day it was received');
$call('waitlist_update',['referral_id'=>$parRef,'status'=>'Sideways'],[],false,'Status: choose');
$call('waitlist_update',['referral_id'=>$parRef,'status'=>'Waiting','followup_date'=>'2026-02-31'],[],false,'Follow-up date');
check((int)$db->query("SELECT COUNT(*) FROM referral_waitlist WHERE referral_id=$parRef")->fetchColumn()===0,'waitlist_update refuses a bad status or follow-up date, naming the field, and saves nothing');
$savedWl=$call('waitlist_update',['referral_id'=>$parRef,'status'=>'Contacted','followup_date'=>date('Y-m-d',strtotime('+7 days')),'notes'=>'Weekday mornings','expertise'=>'braille']);
$wlRow=$db->query("SELECT * FROM referral_waitlist WHERE referral_id=$parRef")->fetch();
check($wlRow && $wlRow['status']==='Contacted' && $wlRow['availability']==='Weekday mornings' && $wlRow['expertise']==='braille' && $wlRow['followup_date']===date('Y-m-d',strtotime('+7 days'))
    && $savedWl['item']['status']==='Contacted' && $savedWl['item']['notes']==='Weekday mornings'
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='referral.waitlist' AND entity_id=$parRef AND detail='Contacted'")->fetchColumn()===1,
    'waitlist_update saves the entry through workflow_waitlist_save and audits referral.waitlist as the website does');
$call('waitlist_update',['referral_id'=>$parRef,'status'=>'Ready','version'=>0],[],false,'Someone else saved');
$call('waitlist_update',['referral_id'=>$parRef,'status'=>'Ready']);
$wlRow=$db->query("SELECT * FROM referral_waitlist WHERE referral_id=$parRef")->fetch();
check($wlRow['status']==='Ready' && $wlRow['expertise']==='braille' && $wlRow['availability']==='Weekday mornings' && (int)$wlRow['version']===2,
    'A stale version is refused; an update without one keeps the fields it leaves out and bumps the version');
$listed=$wlItem($call('waitlist'));
check($listed['status']==='Ready' && $listed['followup_date']===date('Y-m-d',strtotime('+7 days')) && $listed['version']===2,'waitlist lists the saved entry');
$db->exec("INSERT INTO client_notes(client_id,subject,body,author_id) VALUES(1,'','Synthetic review body with a [TODO] left in',1)"); $flaggedNote=(int)$db->lastInsertId();
$review=array_values(array_filter($call('doc_review')['items'],fn($i)=>$i['note_id']===$flaggedNote))[0]??null;
check($review!==null && $review['client_id']===1 && $review['client_name']==='Kept synthetic client' && $review['subject']===null
    && str_contains($review['flagged_for'],'Missing subject') && str_contains($review['flagged_for'],'Unresolved placeholder') && strlen($review['fingerprint'])===64,
    'doc_review lists a flagged note with its client, flags and fingerprint, as the review queue does');
$db->exec("DELETE FROM client_notes WHERE id=$flaggedNote");

/* ---- A permission refusal and caseload scope ---- */
$db->exec("UPDATE `groups` SET perms='schedule',own_caseload_only=0 WHERE id=999; UPDATE users SET group_id=999 WHERE id=1");
try {
    $call('invoices',[],[],false,'does not have access');
    $call('waitlist',[],[],false,'does not have access');
    $call('waitlist_update',['referral_id'=>$parRef,'status'=>'Closed'],[],false,'does not have access');
    $call('doc_review',[],[],false,'does not have access');
    check($db->query("SELECT status FROM referral_waitlist WHERE referral_id=$parRef")->fetchColumn()==='Ready',
        'Without the referrals permission the waitlist is neither listed nor changed, and without the clients permission the review queue is refused');
    $call('save_task',['title'=>'Linked without the clients permission','client_id'=>1],[],false,'cannot be linked to a client');
    check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE title='Linked without the clients permission'")->fetchColumn()===0,
        'Without the billing permission invoices is refused, and without the clients permission a task cannot be linked to a client');
    $db->exec("UPDATE `groups` SET perms='clients',own_caseload_only=1 WHERE id=999; UPDATE clients SET assigned_to=1 WHERE id=1");
    $db->exec("INSERT INTO clients(name,assigned_to) VALUES('Parity scope outside client',2)"); $parOutside=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO client_status_history(client_id,old_status,new_status,changed_by) VALUES(?,NULL,'Waitlist',2)")->execute([$parOutside]);
    $db->prepare("INSERT INTO portal_requests(client_id,type,details) VALUES(?,'other','Outside caseload request'),(1,'other','Own caseload request')")->execute([$parOutside]);
    $outsideRequest=(int)$db->lastInsertId(); $ownRequest=$outsideRequest+1;
    $call('client_history',[],['client_id'=>$parOutside],false,'not on your caseload');
    $requests=array_column($call('portal_requests')['requests'],'id');
    $call('portal_request_update',['id'=>$outsideRequest,'to'=>'Done'],[],false,'not on your caseload');
    check(in_array($ownRequest,$requests,true) && !in_array($outsideRequest,$requests,true)
        && $db->query("SELECT status FROM portal_requests WHERE id=$outsideRequest")->fetchColumn()==='New',
        'Caseload-scoped staff see and change only their own clients\' portal requests, and cannot read another caseload\'s status history');
    $updated=$call('portal_request_update',['id'=>$ownRequest,'to'=>'In Progress']);
    check($updated['changed']===true && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='portal_request.status' AND detail LIKE 'request #$ownRequest:%'")->fetchColumn()===1,
        'portal_request_update changes an own-caseload request and audits it as the website does');
} finally {
    $db->exec("UPDATE `groups` SET perms='clients;schedule',own_caseload_only=1 WHERE id=999; UPDATE users SET group_id=NULL WHERE id=1");
}
