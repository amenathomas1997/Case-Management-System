<?php
// Included by full_schema.php after session_notices_cases.php, against its synthetic database
// only: My devices in the client portal, client-answered device check-ins, and loan
// reminders to the client (3.0.42). No mail is sent from this process.
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('Devices synthetic client','Active Training','dev-client@example.invalid',1)"); $dc=(int)$db->lastInsertId();
$db->exec("INSERT INTO equipment(name,status) VALUES('Synthetic magnifier','On Loan')"); $eq=(int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_loans(equipment_id,client_id,loaned_at,due_date) VALUES(?,?,DATE_SUB(CURDATE(),INTERVAL 40 DAY),DATE_ADD(CURDATE(),INTERVAL 3 DAY))")->execute([$eq,$dc]); $loan=(int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_followups(loan_id,client_id,equipment_id,interval_days,due_date) VALUES(?,?,?,30,DATE_SUB(CURDATE(),INTERVAL 2 DAY))")->execute([$loan,$dc,$eq]); $fu=(int)$db->lastInsertId();
$pt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$dc,$pt]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$out,$err]=$renderPage('portal',['t'=>$pt]);
check($code===0 && $err==='' && str_contains($out,'My devices') && str_contains($out,'Synthetic magnifier') && str_contains($out,'name="device_checkin"'),'The portal lists the client\'s loans and offers the device check-in' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('portal',['t'=>$pt,'lang'=>'es']);
check($code===0 && $err==='' && str_contains($out,'Mis dispositivos') && str_contains($out,'¿Todavía lo usa?'),'My devices is in Spanish too' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('portal',['t'=>$pt],['device_checkin'=>'1','followup_id'=>(string)$fu,'in_use'=>'no','satisfaction'=>'2','barriers'=>'Too heavy']);
$renderPage('portal',['t'=>$pt],['device_checkin'=>'1','followup_id'=>(string)$fu,'in_use'=>'yes']);
putenv('CMS_TEST_ANON');
$f=$db->query("SELECT * FROM equipment_followups WHERE id=$fu")->fetch();
check($code===0 && $err==='' && $f['status']==='Completed' && (int)$f['client_reported']===1 && (int)$f['in_use']===0 && $f['barriers']==='Too heavy' && $f['completed_by']===null,
    'The client\'s answers are saved once, as client-reported' . ($err ? ": $err" : ''));
check((int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$dc AND subject LIKE 'Device no longer in use%'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='abandon-$fu'")->fetchColumn()===1,'A device no longer in use adds a case note and a task, as when staff record it');
[$code,$out,$err]=$renderPage('followups',[]);
check($code===0 && $err==='' && str_contains($out,'reported by the client'),'Staff see that the client answered it' . ($err ? ": $err" : ''));

/* Loan reminders: three days before, and the day after it is overdue, each once */
$cronRun('daily_alerts.php',true);
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='loan-due-$loan'")->fetchColumn()===1,'The client is reminded once, three days before a loan is due');
$db->exec("UPDATE equipment_loans SET due_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=$loan");
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='loan-over-$loan'")->fetchColumn()===1,'And once the day after it is overdue');
[$subj,$body]=email_template('loan_due_client',['name'=>'X','item'=>'Synthetic magnifier','date'=>'Friday','portal_link'=>'','contact'=>'']);
check(str_contains($subj,'Synthetic magnifier'),'The reminder names the item');
