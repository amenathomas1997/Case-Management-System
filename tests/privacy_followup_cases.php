<?php
// Included by full_schema.php after records_privacy_cases.php, against its synthetic database
// only: file views and exports in the audit log, guardian details, minors without consent,
// the privacy notice record, record holds and key documents (3.0.37).
$db->exec("INSERT INTO clients(name,status,dob,client_type) VALUES('Followup synthetic minor','Active Training',DATE_SUB(CURDATE(),INTERVAL 16 YEAR),'VR')"); $fm=(int)$db->lastInsertId();
$views=fn(int $cid)=>(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='client.view' AND entity='client' AND entity_id=$cid")->fetchColumn();

/* Who opened the file */
[$code,,$err]=$renderPage('client',['id'=>$fm]);
check($code===0 && $err==='' && $views($fm)===1,'Opening a client page records a view' . ($err ? ": $err" : ''));
$renderPage('client',['id'=>$fm,'tab'=>'notes']);
check($views($fm)===1,'A second look within 30 minutes is not recorded again');
$call('client',[],['id'=>$fm]);
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='client.view' AND entity_id=$fm AND detail='iPhone app'")->fetchColumn()===1,'Opening the client in the app records a view');
[$code,$out,$err]=$renderPage('audit',['client'=>$fm,'action'=>'client.view']);
check($code===0 && $err==='' && str_contains($out,'client page') && str_contains($out,'iPhone app'),'The audit log lists who opened the file' . ($err ? ": $err" : ''));
$exports=fn()=>(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='report.export' AND detail LIKE 'Outcome report CSV%'")->fetchColumn();
$before=$exports();
[$code,,$err]=$renderPage('outcomes',['export'=>'1','from'=>date('Y-01-01'),'to'=>date('Y-m-d')]);
check($code===0 && $exports()===$before+1,'A bulk export is recorded' . ($err ? ": $err" : ''));

/* Guardian details, and a minor with no consent */
[$code,$out,$err]=$renderPage('compliance',[]);
check($code===0 && $err==='' && str_contains($out,'no guardian consent requested') && str_contains($out,'Followup synthetic minor'),'Compliance lists a minor with no guardian consent requested' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client_edit',['id'=>$fm],['name'=>'Followup synthetic minor','status'=>'Active Training','client_type'=>'VR','dob'=>date('Y-m-d',strtotime('-16 years')),
    'guardian_name'=>'Synthetic Parent','guardian_phone'=>'555-0199','guardian_email'=>'not-an-email']);
check($code===0 && $db->query("SELECT guardian_name FROM clients WHERE id=$fm")->fetchColumn()===null,'An invalid guardian email is refused' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client_edit',['id'=>$fm],['name'=>'Followup synthetic minor','status'=>'Active Training','client_type'=>'VR','dob'=>date('Y-m-d',strtotime('-16 years')),
    'guardian_name'=>'Synthetic Parent','guardian_phone'=>'555-0199','guardian_email'=>'parent@example.invalid']);
$g=$db->query("SELECT guardian_name,guardian_phone,guardian_email FROM clients WHERE id=$fm")->fetch();
check($code===0 && $err==='' && $g['guardian_name']==='Synthetic Parent' && $g['guardian_phone']==='555-0199' && $g['guardian_email']==='parent@example.invalid','Guardian details are saved from the edit page' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('client',['id'=>$fm]);
check($code===0 && $err==='' && str_contains($out,'href="tel:5550199"'),'The client page gives the guardian a call link' . ($err ? ": $err" : ''));
$call('save_client',['id'=>$fm,'name'=>'Followup synthetic minor','guardian_phone'=>'555-0100']);
$g=$db->query("SELECT guardian_name,guardian_phone FROM clients WHERE id=$fm")->fetch();
check($g['guardian_phone']==='555-0100' && $g['guardian_name']==='Synthetic Parent','The app corrects a guardian phone and keeps the fields it did not send');
check(($call('client',[],['id'=>$fm])['client']['guardian_phone']??'')==='555-0100','The app is given the guardian details');
// Turning 18: one task, ever.
$db->exec("INSERT INTO clients(name,status,dob,guardian_name) VALUES('Turning adult synthetic','Active Training',DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 18 YEAR),INTERVAL 5 DAY),'Synthetic Parent')"); $t18=(int)$db->lastInsertId();
$cronRun('daily_alerts.php',true);
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='turns18-$t18'")->fetchColumn()===1,'A client with a guardian who turns 18 soon gets one task to sign their own release');

/* The privacy notice */
[$code,,$err]=$renderPage('client',['id'=>$fm],['record_notice'=>'1','notice_how'=>'In person']);
check($code===0 && $err==='' && $db->query("SELECT privacy_notice_how FROM clients WHERE id=$fm")->fetchColumn()==='In person','Staff record that the privacy notice was given' . ($err ? ": $err" : ''));

/* Holds */
$db->exec("INSERT INTO clients(name,status,archived_at) VALUES('Held synthetic client','Closed - Other',NOW())"); $hc=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('client',['id'=>$hc],['place_hold'=>'1','hold_reason'=>'']);
check(client_hold($hc)===null,'A hold needs a reason');
$renderPage('client',['id'=>$hc],['place_hold'=>'1','hold_reason'=>'Records request from an attorney']);
check((client_hold($hc)['hold_reason']??'')==='Records request from an attorney','An administrator places a hold with a reason');
$renderPage('client',['id'=>$hc],['purge_client'=>'1','confirm_name'=>'Held synthetic client']);
check((int)$db->query("SELECT COUNT(*) FROM clients WHERE id=$hc")->fetchColumn()===1,'A record on hold is not purged');
$db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_at) VALUES('client_notes',999901,'Note on held client',?,DATE_SUB(NOW(),INTERVAL 40 DAY))")->execute([json_encode(['id'=>999901,'client_id'=>$hc,'subject'=>'x','body'=>'x'])]); $heldTrash=(int)$db->lastInsertId();
$db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_at) VALUES('client_documents',999902,'Authorization document',?,DATE_SUB(NOW(),INTERVAL 40 DAY))")->execute([json_encode(['id'=>999902,'client_id'=>$fm,'category'=>'Authorization','path'=>''])]); $keyTrash=(int)$db->lastInsertId();
$db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_at) VALUES('client_notes',999903,'Ordinary old note',?,DATE_SUB(NOW(),INTERVAL 40 DAY))")->execute([json_encode(['id'=>999903,'client_id'=>$fm,'subject'=>'x','body'=>'x'])]); $plainTrash=(int)$db->lastInsertId();
$cronRun('daily_alerts.php',true);
$inTrash=fn(int $id)=>(int)$db->query("SELECT COUNT(*) FROM trash WHERE id=$id")->fetchColumn()===1;
check($inTrash($heldTrash) && $inTrash($keyTrash) && !$inTrash($plainTrash)
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='trash.purge_auto' AND entity_id=$plainTrash")->fetchColumn()===1,
    'The nightly purge keeps key documents and a held client\'s items, and audits what it purges');
$renderPage('client',['id'=>$hc],['lift_hold'=>'1']);
check(client_hold($hc)===null,'An administrator lifts the hold');
$db->exec("INSERT INTO incidents(client_id,incident_date,type,severity,description,status) VALUES($hc,CURDATE(),'Other','Low','Synthetic','Open')");
check(client_purge_blockers($hc)===['it has an open incident'],'An open incident blocks a purge');
