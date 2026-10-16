<?php
// Included by full_schema.php after amendment_onhold_cases.php, against its synthetic database
// only: the plan for employment on the client record, and replacing a counselor (3.0.45).
$db->exec("INSERT INTO clients(name,status,counselor_name,counselor_email,counselor_agency,counselor_verified) VALUES('Plan synthetic client','Active Training','Old Counselor','old-vrc@example.invalid','Synthetic VR',1)"); $pc=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,counselor_name,counselor_email,counselor_agency,counselor_verified) VALUES('Plan synthetic second','Active Training','Old Counselor','old-vrc@example.invalid','Synthetic VR',1)"); $pc2=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Plan track','Active','PL-1','Synthetic VR',10,50,DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_ADD(CURDATE(),INTERVAL 90 DAY))")->execute([$pc]); $pt2=(int)$db->lastInsertId();

/* The plan fields */
$form=['name'=>'Plan synthetic client','status'=>'Active Training','client_type'=>'VR','counselor_name'=>'Old Counselor','counselor_email'=>'old-vrc@example.invalid','counselor_agency'=>'Synthetic VR'];
[$code,,$err]=$renderPage('client_edit',['id'=>$pc],$form+['vr_case_number'=>'VR-12345','ipe_goal'=>'Customer service representative','ipe_date'=>'2099-01-01']);
check($code===0 && $db->query("SELECT vr_case_number FROM clients WHERE id=$pc")->fetchColumn()===null,'A future IPE date is refused' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client_edit',['id'=>$pc],$form+['vr_case_number'=>'VR-12345','ipe_goal'=>'Customer service representative','ipe_date'=>date('Y-m-d',strtotime('-60 days'))]);
$c=$db->query("SELECT vr_case_number,ipe_goal,ipe_date FROM clients WHERE id=$pc")->fetch();
check($code===0 && $err==='' && $c['vr_case_number']==='VR-12345' && $c['ipe_goal']==='Customer service representative','The VR case number and IPE goal are saved' . ($err ? ": $err" : ''));
require_once $root.'/public/inc/report_builder.php';
$rep=build_track_report($pt2,'Synthetic');
check(str_contains($rep['text'],'VR case number: VR-12345') && str_contains($rep['text'],'IPE employment goal: Customer service representative') && !str_contains($rep['ai_text'],'VR-12345'),
    'The progress report carries the case number and the IPE goal, and the AI copy masks the case number');
$call('save_client',['id'=>$pc,'name'=>'Plan synthetic client','ipe_amended_on'=>date('Y-m-d')]);
$d=$call('client',[],['id'=>$pc]);
check(($d['client']['ipe_amended_on']??'')===date('Y-m-d') && ($d['client']['vr_case_number']??'')==='VR-12345','The app saves an IPE amendment date and keeps the fields it did not send');

/* Replacing a counselor on all their clients */
$vt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token,expires_at) VALUES('old-vrc@example.invalid','Old Counselor',?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$vt]);
[$code,,$err]=$renderPage('counselors',[],['replace_counselor'=>'1','email'=>'old-vrc@example.invalid','new_name'=>'New Counselor','new_email'=>'new-vrc@example.invalid']);
$moved=$db->query("SELECT COUNT(*) FROM clients WHERE id IN ($pc,$pc2) AND counselor_email='new-vrc@example.invalid' AND counselor_name='New Counselor' AND counselor_agency='Synthetic VR' AND counselor_verified=1")->fetchColumn();
check($code===0 && $err==='' && (int)$moved===2 && (int)$db->query("SELECT COUNT(*) FROM vrc_tokens WHERE counselor_email='old-vrc@example.invalid'")->fetchColumn()===0
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id IN ($pc,$pc2) AND subject='VR counselor changed'")->fetchColumn()===2,
    'Replacing a counselor moves every client, keeps the agency, notes it on each, and turns off the old portal link' . ($err ? ": $err" : ''));
