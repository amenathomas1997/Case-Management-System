<?php
// Included by full_schema.php after settings_upgrade_cases.php, against its synthetic database
// only: staff invitations, renewals and departures, and the spreadsheet import (3.0.55).
[$code,,$err]=$renderPage('admin',[],['add_user'=>'1','name'=>'Invited Person','email'=>'invited.person@example.test','password'=>'','group_id'=>'0','role'=>'instructor']);
$iu=$db->query("SELECT * FROM users WHERE email='invited.person@example.test'")->fetch();
check($code===0 && $iu && $iu['invited_at']!==null && (int)$db->query("SELECT COUNT(*) FROM password_resets WHERE user_id={$iu['id']} AND used=0 AND expires_at > DATE_ADD(NOW(), INTERVAL 6 DAY)")->fetchColumn()===1,'A new account with no password is emailed a 7-day invitation' . ($err ? ": $err" : ''));
check(in_array('Confidentiality agreement not recorded',staff_compliance_notes($iu + ['last_login_at'=>null]),true),'Missing agreements are listed');
$old=date('Y-m-d',strtotime('-350 days'));
[$code,,$err]=$renderPage('users',[],['save_user'=>'1','uid'=>(string)$iu['id'],'name'=>'Invited Person','email'=>'invited.person@example.test','role'=>'instructor','group_id'=>'','weekly_hours'=>'20','is_active'=>'1','confidentiality_signed_on'=>$old,'privacy_training_on'=>date('Y-m-d')]);
$iu=$db->query("SELECT * FROM users WHERE id={$iu['id']}")->fetch();
check($iu['confidentiality_signed_on']===$old && $iu['privacy_training_on']===date('Y-m-d'),'The agreement and training dates are saved' . ($err ? ": $err" : ''));
check((bool)array_filter(staff_compliance_notes($iu + ['last_login_at'=>null]),fn($n)=>str_starts_with($n,'Confidentiality agreement due for renewal')),'An agreement due within 30 days is flagged');
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key LIKE 'staffrenew-{$iu['id']}-confidentiality_signed_on-%' AND assigned_to IS NOT NULL")->fetchColumn()===1,'A renewal task is opened for an administrator');
[$code,$out,$err]=$renderPage('compliance',[]);
check($code===0 && str_contains($out,'Staff agreements, training and unused accounts') && str_contains($out,'Invited Person'),'Compliance lists staff renewals' . ($err ? ": $err" : ''));

/* Departures */
$db->prepare("INSERT INTO clients(name,status,assigned_to) VALUES('Held Client','Active Training',?)")->execute([$iu['id']]);
$db->prepare("INSERT INTO cohorts(name,coordinator_id,active) VALUES('Held cohort',?,1)")->execute([$iu['id']]);
$db->prepare("INSERT INTO at_records(kind,title,status,details,staff_id) VALUES('rate','Rate','Active','{}',?),('license','JAWS licence','Active','{}',?)")->execute([$iu['id'],$iu['id']]);
$h=staff_holdings((int)$iu['id']);
check($h['Software licences assigned']===1,'Only software licences count as held AT records, not the person\'s own rate');
check($h['Clients assigned']===1 && $h['Group programs coordinated']===1,'What a person holds is listed');
[$code,$out,$err]=$renderPage('users',['id'=>(string)$iu['id']]);
check($code===0 && str_contains($out,'is leaving') && str_contains($out,'Group programs coordinated 1'),'The user page shows what they still hold' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('users',[],['transfer_caseload'=>'1','uid'=>(string)$iu['id'],'to_user'=>'1']);
$h=staff_holdings((int)$iu['id']);
check(array_sum($h)===0,'The transfer moves everything they held, programs included' . ($err ? ": $err" : ''));
check((int)$db->query("SELECT COUNT(*) FROM at_records WHERE kind='rate' AND staff_id={$iu['id']}")->fetchColumn()===1,'The person\'s cost rate stays with them');
check(staff_m365_sync()['checked']===0,'The Microsoft 365 check does nothing until it is turned on');

/* Spreadsheet import */
$csv=$tmp.'/clients_import.csv';
file_put_contents($csv,"name,dob,email,client_type,status,counselor_email\nImport Person One,1990-01-02,one@example.test,VR,Active Training,c@agency.test\nImport Person One,1990-01-02,,VR,,\nBad Date Person,1990-13-40,,VR,,\nOn Hold Person,,,VR,On Hold,\n");
putenv('CMS_TEST_FILES='.json_encode(['csv'=>$csv]));
[$code,$out,$err]=$renderPage('client_import',[],['do_import'=>'1','kind'=>'clients','dry_run'=>'1']);
check($code===0 && $err==='' && str_contains($out,'Would import') && str_contains($out,'Same person as row 2') && str_contains($out,'Date of birth must be') && str_contains($out,'On Hold needs a reason')
    && (int)$db->query("SELECT COUNT(*) FROM clients WHERE name='Import Person One'")->fetchColumn()===0,'Validate only checks every row and imports nothing' . ($err ? ": $err" : ''));
putenv('CMS_TEST_SESSION_ADD='.json_encode(['client_import_tokens'=>['tok1']]));
[$code,$out,$err]=$renderPage('client_import',[],['do_import'=>'1','kind'=>'clients','import_token'=>'tok1']);
$ip=$db->query("SELECT * FROM clients WHERE name='Import Person One'")->fetch();
check($code===0 && $ip && (int)$ip['email_verified']===0 && (int)$ip['counselor_verified']===0 && $ip['status']==='Active Training','A client is imported, with emails unconfirmed' . ($err ? ": $err" : ''));
$acsv=$tmp.'/auth_import.csv';
file_put_contents($acsv,"client_name,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date\nImport Person One,IMP-1,State VR,20,50,2026-10-01,2027-03-31\nImport Person One,IMP-2,State VR,0,50,,\nNobody Here,IMP-3,State VR,5,50,,\nImport Person One,IMP-4,State VR,5,50,2027-01-01,2026-01-01\n");
putenv('CMS_TEST_FILES='.json_encode(['csv'=>$acsv])); putenv('CMS_TEST_SESSION_ADD='.json_encode(['client_import_tokens'=>['tok2']]));
[$code,$out,$err]=$renderPage('client_import',[],['do_import'=>'1','kind'=>'authorizations','import_token'=>'tok2']);
check($code===0 && (int)$db->query("SELECT COUNT(*) FROM training_tracks WHERE auth_number LIKE 'IMP-%'")->fetchColumn()===1 && str_contains($out,'authorized_hours must be') && str_contains($out,'No client by that name') && str_contains($out,'expiration_date is before'),
    'Authorizations import with strict checks on hours, clients and dates' . ($err ? ": $err" : ''));
putenv('CMS_TEST_FILES'); putenv('CMS_TEST_SESSION_ADD');
