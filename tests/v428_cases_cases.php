<?php
// Included by full_schema.php after the v427 cases, against its synthetic database only:
// 4.28.0, case pages: stale-form checks (tracks, worksite assessments), caseload scoping of the
// referral deadlines report, session moves, merges, letters and the client file export.

$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V428 Cases Instructor A','v428-cases-a@example.invalid','test428a','instructor')"); $v428ua=(int)$db->lastInsertId();
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V428 Cases Instructor B','v428-cases-b@example.invalid','test428b','instructor')"); $v428ub=(int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,client_type,state,assigned_to) VALUES('V428 Cases Synthetic Client','Active Training','VR','IA',?)")->execute([$v428ub]); $v428c=(int)$db->lastInsertId();

// ---- N9: an old track form never reverses someone else's authorization change ----
$db->prepare("INSERT INTO training_tracks (client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES (?,?,?,?,?,?)")
    ->execute([$v428c,'V428 synthetic track','AUTH-428-1','State VR',30,65]); $v428t=(int)$db->lastInsertId();
$v428trackPost=function(array $orig) use ($v428t): array {
    return ['title'=>'V428 synthetic track renamed','billing_mode'=>'vr','auth_number'=>'AUTH-428-1','funding_agency'=>'State VR','authorized_hours'=>'20',
        'hourly_rate'=>'60','mileage_rate'=>'0','status'=>'Active','service_type'=>'Assistive Technology','report_cadence'=>'monthly','orig'=>json_encode($orig)];
};
// The form was opened at 20 h and $60; someone has since amended it to 30 h and $65.
$v428orig=['title'=>'V428 synthetic track','auth_number'=>'AUTH-428-1','funding_agency'=>'State VR','authorized_hours'=>'20.00','hourly_rate'=>'60.00','mileage_rate'=>'0.000'];
[$code,$out,$err]=$renderPage('track_edit',['id'=>(string)$v428t],$v428trackPost($v428orig));
$v428row=$db->query("SELECT title,authorized_hours,hourly_rate FROM training_tracks WHERE id=$v428t")->fetch();
check($v428row['title']==='V428 synthetic track' && (float)$v428row['authorized_hours']===30.0 && (float)$v428row['hourly_rate']===65.0
    && str_contains($out,'Someone else changed') && str_contains($out,'Authorized hours') && str_contains($out,'Hourly billing rate'),
    'An old track form is refused, naming the fields someone else changed, and nothing is re-priced'.($err?": $err":''));
check((int)$db->query("SELECT COUNT(*) FROM auth_amendments WHERE track_id=$v428t")->fetchColumn()===0,'A refused track save records no amendment');
// Opened on the current values, the same save goes through.
$v428orig['authorized_hours']='30.00'; $v428orig['hourly_rate']='65.00';
$v428ok=$v428trackPost($v428orig); $v428ok['authorized_hours']='30'; $v428ok['hourly_rate']='65';
[$code,$out,$err]=$renderPage('track_edit',['id'=>(string)$v428t],$v428ok);
check($db->query("SELECT title FROM training_tracks WHERE id=$v428t")->fetchColumn()==='V428 synthetic track renamed','A track form opened on the current values saves'.($err?": $err":''));
check(!str_contains((string)$db->query("SELECT GROUP_CONCAT(detail) FROM audit_log WHERE action='auth.amend' AND entity_id=$v428t")->fetchColumn(),'30'),'Saving only a new title records no authorization change');

// ---- N10: worksite assessment saves never overwrite each other ----
$db->prepare("INSERT INTO worksite_assessments (client_id,employer,barriers,status) VALUES (?,?,?,?)")->execute([$v428c,'V428 Synthetic Employer','Their barriers text','Assessed']); $v428w=(int)$db->lastInsertId();
$v428wPost=fn(array $orig, string $barriers)=>['save_assessment'=>'1','id'=>(string)$v428w,'employer'=>'V428 Synthetic Employer','barriers'=>$barriers,'status'=>'Assessed','orig'=>json_encode($orig)];
$v428wOrig=['employer'=>'V428 Synthetic Employer','job_title'=>'','worksite_location'=>'','assessment_date'=>'','job_tasks'=>'','barriers'=>'Older barriers text','recommendations'=>'','status'=>'Assessed','follow_up_date'=>'','outcome'=>''];
[$code,,$err]=$renderPage('accommodations',[],$v428wPost($v428wOrig,'My stale barriers'));
check($db->query("SELECT barriers FROM worksite_assessments WHERE id=$v428w")->fetchColumn()==='Their barriers text','A worksite assessment changed since the form was opened is not overwritten'.($err?": $err":''));
$v428wOrig['barriers']='Their barriers text';
[$code,,$err]=$renderPage('accommodations',[],$v428wPost($v428wOrig,'My new barriers'));
check($db->query("SELECT barriers FROM worksite_assessments WHERE id=$v428w")->fetchColumn()==='My new barriers','A worksite form opened on the current text saves'.($err?": $err":''));

// ---- N11: the referral deadlines report follows the caseload; attached referrals follow the client ----
$db->prepare("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,status,assigned_to) VALUES('Synthetic','Synthetic','Synthetic','Synthetic counselor','V428 Synthetic Agency','v428-counselor@example.invalid','555-0142','V428 Cases Synthetic Client','New',?)")->execute([$v428ua]);
$v428r=(int)$db->lastInsertId();
// An earlier conversion's assessment task, created before this referral arrived.
$db->prepare("INSERT INTO tasks (title,client_id,assigned_to,due_date,status,auto_key,created_at) VALUES ('V428 earlier assessment',?,?,DATE_SUB(CURDATE(), INTERVAL 200 DAY),'Done',?,DATE_SUB(NOW(), INTERVAL 210 DAY))")->execute([$v428c,$v428ub,'assess-'.$v428c]);
$v428att=referral_attach($v428r,$v428c,1);
check($v428att['error']===null && (int)$db->query("SELECT assigned_to FROM referrals WHERE id=$v428r")->fetchColumn()===$v428ub,'An attached referral is assigned to the client\'s instructor');
$v428repA=referral_deadline_report(date('Y-m-d',strtotime('-1 day')),date('Y-m-d'),$v428ua);
$v428repB=referral_deadline_report(date('Y-m-d',strtotime('-1 day')),date('Y-m-d'),$v428ub);
check(!in_array($v428r,array_column($v428repA['rows'],'id'),true),'The referral deadlines report leaves out a referral whose client is on another caseload');
$v428rowB=array_values(array_filter($v428repB['rows'],fn($r)=>$r['id']===$v428r))[0]??null;
$v428reref=$db->query("SELECT due_date FROM tasks WHERE auto_key='reref-$v428r'")->fetchColumn();
check($v428rowB!==null && $v428rowB['assess_due']===$v428reref && $v428rowB['assess']==='waiting','An attached referral is judged by its own follow-up task, not an earlier assessment');

// ---- Session moves: "Move anyway" approves only the warnings shown; a moved member leaves its group ----
$v428start=strtotime('+3 days 09:00'); $v428other=strtotime('+3 days 14:00');
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,group_uid) VALUES(?,?,?,?,'Scheduled','v428group')")->execute([$v428c,$v428ub,date('Y-m-d H:i:s',$v428start),date('Y-m-d H:i:s',$v428start+3600)]); $v428s=(int)$db->lastInsertId();
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,?,?,?,'Scheduled')")->execute([$v428c,$v428ub,date('Y-m-d H:i:s',$v428other),date('Y-m-d H:i:s',$v428other+3600)]);
$v428mv=session_move($v428s,$v428other,$v428other+3600,1,'not-the-shown-hash');
check(!$v428mv['moved'] && $v428mv['warnings'] && $v428mv['hash']!=='','A "Move anyway" sent with other warnings than those shown does not move the session');
$v428mv=session_move($v428s,$v428other,$v428other+3600,1,session_move_hash(['An unrelated warning.'],$v428other,$v428other+3600));
check(!$v428mv['moved'],'Approval of a different warning moves nothing');
$v428mv=session_move($v428s,$v428other,$v428other+3600,1,$v428mv['hash']);
check($v428mv['moved'] && $db->query("SELECT group_uid FROM scheduled_sessions WHERE id=$v428s")->fetchColumn()===null,'The matching approval moves the session, and it leaves its group');

// ---- Merge: one privacy-incident entry for the person, keeping the notification date ----
$db->exec("INSERT INTO clients(name,status,client_type,state) VALUES('V428 Merge Duplicate Synthetic','Active Training','VR','IA')"); $v428dup=(int)$db->lastInsertId();
$db->exec("INSERT INTO privacy_incidents (kind,description,discovered_on,notice_deadline,reported_by) VALUES ('Misdirected email','V428 synthetic',CURDATE(),DATE_ADD(CURDATE(), INTERVAL 60 DAY),1)"); $v428pi=(int)$db->lastInsertId();
$db->prepare("INSERT INTO privacy_incident_clients (incident_id,client_id,client_label,notified_at) VALUES (?,?,?,NULL),(?,?,?,CURDATE())")->execute([$v428pi,$v428c,'V428 kept',$v428pi,$v428dup,'V428 dup']);
$db->beginTransaction(); move_client_records($db,$v428c,$v428dup); $db->exec("DELETE FROM clients WHERE id=$v428dup"); $db->commit();
$v428pic=$db->query("SELECT client_id, notified_at FROM privacy_incident_clients WHERE incident_id=$v428pi")->fetchAll();
check(count($v428pic)===1 && (int)$v428pic[0]['client_id']===$v428c && $v428pic[0]['notified_at']!==null,'A merge keeps one privacy-incident entry for the person, marked notified');

// ---- The client list remembers a caseload only from the CSRF-checked filter form ----
[$code,$out,$err]=$renderPage('clients',['who'=>'unassigned','save'=>'1']);
check((setting('clients_filter_1') ?? '')!=='unassigned','A link with save=1 does not change the remembered caseload'.($err?": $err":''));
[$code,,$err]=$renderPage('clients',[],['filter_clients'=>'1','who'=>'unassigned']);
check(setting('clients_filter_1')==='unassigned','The filter form, sent as a POST, remembers the caseload'.($err?": $err":''));
set_setting('clients_filter_1','');

// ---- Client file export: drawn signatures are listed (and included when on disk) ----
$src=(string)file_get_contents($root.'/public/client_export.php');
check(str_contains($src,'documents/signatures/') && str_contains($src,'client_signatures') && str_contains($src,'work_logs w JOIN training_tracks'),'The complete client file includes drawn signatures');

// ---- Signed documents offer no Withdraw ----
$src=(string)file_get_contents($root.'/public/signatures.php');
check(!str_contains($src,"['Signed', 'Awaiting signature'"),'A signed document shows no Withdraw button');
