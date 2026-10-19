<?php
// Included by full_schema.php after the v428 cases, against its synthetic database only:
// 4.29.0, case pages: instructor double-booking warnings (P9), the meeting-link permission (P10),
// merge then purge (P11), private-pay tracks (P13), and the case-page Lows.

$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V429-CASES Instructor','v429-cases-a@example.invalid','test429a','instructor')"); $v429u=(int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,client_type,state,assigned_to) VALUES('V429-CASES Client One','Active Training','VR','IA',?)")->execute([$v429u]); $v429c1=(int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,client_type,state,assigned_to) VALUES('V429-CASES Client Two','Active Training','VR','IA',?)")->execute([$v429u]); $v429c2=(int)$db->lastInsertId();

// ---- P9: a booking that overlaps the instructor's other session needs the approval box ----
$v429day=date('Y-m-d',strtotime('+9 days'));
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,?,?,?,'Scheduled')")->execute([$v429c1,$v429u,"$v429day 10:00:00","$v429day 11:30:00"]);
$v429lines=session_instructor_overlaps($v429u,'V429-CASES Instructor',[[strtotime("$v429day 10:30"),strtotime("$v429day 11:30")]],[$v429c2]);
check($v429lines===['V429-CASES Instructor already has a session from 10:00 to 11:30 with another client.'],'An overlapping session of the instructor is named with its times'.(': '.json_encode($v429lines)));
$v429same=session_instructor_overlaps($v429u,'V429-CASES Instructor',[[strtotime("$v429day 10:30"),strtotime("$v429day 11:30")],[strtotime("$v429day 10:30 +1 week"),strtotime("$v429day 11:30 +1 week")]],[$v429c1]);
check(count($v429same)===1 && str_contains($v429same[0],' on '.date('D, M j',strtotime($v429day)).' from 10:00 to 11:30 with this client.'),'In a series the day is named, and the client\'s own session says "with this client"');
check(session_instructor_overlaps($v429u,'X',[[strtotime("$v429day 10:00"),strtotime("$v429day 11:00")]],[$v429c1])===[],'The same client at the same start (a repeated submit) is left to the duplicate check');
$v429count=fn()=>(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE user_id=$v429u AND status='Scheduled'")->fetchColumn();
$v429book=['add_session'=>'1','client_id'=>(string)$v429c2,'date'=>$v429day,'time'=>'10:30','duration'=>'1','staff_id'=>(string)$v429u,'plan_pattern'=>'weekly','repeat_weeks'=>'1'];
[$code,,$err]=$renderPage('schedule',[],$v429book);
check($err==='' && $v429count()===1,'A booking that double-books the instructor is not saved without approval'.($err?": $err":''));
[$code,,$err]=$renderPage('schedule',[],$v429book+['authorization_ack'=>'1','authorization_hash'=>hash('sha256',json_encode($v429lines))]);
check($err==='' && $v429count()===2,'Approved in the warnings box, the overlapping booking is saved'.($err?": $err":''));

// ---- P10: making a meeting in someone else's account follows the admin permission ----
$v429sched=(string)file_get_contents(dirname(__DIR__).'/public/schedule.php');
check(str_contains($v429sched,"!== (int)\$user['id'] && !can('admin')") && !str_contains($v429sched,"(\$user['role'] ?? '') !== 'admin'"),'The meeting link in another person\'s account checks the administrator permission, not the role column');

// ---- P11: a merge moves the duplicate's audit entries; a purge clears older merges' entries ----
$db->exec("INSERT INTO clients(name,status,client_type,state) VALUES('V429-CASES Keep','Active Training','VR','IA')"); $v429keep=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,client_type,state) VALUES('V429-CASES Dup','Active Training','VR','IA')"); $v429dup=(int)$db->lastInsertId();
$db->prepare("INSERT INTO audit_log(user_id,action,entity,entity_id,detail) VALUES(1,'client.create','client',?,'V429-CASES Dup created')")->execute([$v429dup]); $v429aud=(int)$db->lastInsertId();
// Merge holds: the duplicate's reservation and the kept record's waitlist place on one item.
$db->exec("INSERT INTO equipment(name,status) VALUES('V429-CASES Magnifier','Available')"); $v429item=(int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_reservations(equipment_id,client_id,status) VALUES(?,?,'Waitlist'),(?,?,'Reserved')")->execute([$v429item,$v429keep,$v429item,$v429dup]);
$db->beginTransaction(); move_client_records($db,$v429keep,$v429dup); $db->exec("DELETE FROM clients WHERE id=$v429dup"); $db->commit();
check((int)$db->query("SELECT entity_id FROM audit_log WHERE id=$v429aud")->fetchColumn()===$v429keep,'A merge moves the duplicate\'s audit entries to the kept record');
$v429holds=$db->query("SELECT status FROM equipment_reservations WHERE equipment_id=$v429item AND client_id=$v429keep AND status IN ('Reserved','Waitlist')")->fetchAll(PDO::FETCH_COLUMN);
check($v429holds===['Reserved'],'A merge keeps one hold per person and item, the reservation over the waitlist place');
// A merge made before 4.29.0: the duplicate's entries kept its id; the kept record's merge entry names it.
$db->exec("INSERT INTO clients(name,status,client_type,state,archived_at) VALUES('V429-CASES Purged','Closed - Other','VR','IA',NOW())"); $v429p=(int)$db->lastInsertId();
$v429old=$v429p+100000;
$db->prepare("INSERT INTO audit_log(user_id,action,entity,entity_id,detail) VALUES(1,'client.merge','client',?,?),(1,'note.add','client',?,'V429-CASES secret subject')")->execute([$v429p,"merged #$v429old (V429-CASES Old Dup) into #$v429p",$v429old]);
$v429n=$db->prepare("INSERT INTO notifications(user_id,message,link) VALUES(1,?,?)");
$v429n->execute(['Signed: Consent for V429-CASES Purged','signatures.php?client='.$v429p]);
$v429n->execute(['V429-CASES Purged asked for help with homework','workflows.php?view=homework&client_id='.$v429p]);
[$code,,$err]=$renderPage('client',['id'=>(string)$v429p],['purge_client'=>'1','confirm_name'=>'V429-CASES Purged']);
check((int)$db->query("SELECT COUNT(*) FROM clients WHERE id=$v429p")->fetchColumn()===0,'The archived record is purged'.($err?": $err":''));
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%V429-CASES secret%'")->fetchColumn()===0,'A purge clears the audit details of a record merged into it before 4.29.0');
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE message LIKE '%V429-CASES Purged%'")->fetchColumn()===0,'A purge removes the signed-document and homework-help alerts that name the client');

// ---- P13: a private-pay track never keeps "State VR" ----
[$code,,$err]=$renderPage('track_edit',['client_id'=>(string)$v429c1],['title'=>'V429-CASES private track','billing_mode'=>'private','funding_agency'=>'State VR','auth_number'=>'',
    'authorized_hours'=>'10','hourly_rate'=>'50','mileage_rate'=>'0','status'=>'Active','service_type'=>'Assistive Technology','report_cadence'=>'monthly']);
check($db->query("SELECT funding_agency FROM training_tracks WHERE title='V429-CASES private track'")->fetchColumn()==='Private pay','A private-pay track is stored with the agency Private pay'.($err?": $err":''));
[$code,$out,$err]=$renderPage('track_edit',['client_id'=>(string)$v429c1]);
check($err==='' && str_contains($out,'id="funding_agency"'),'The new-track form renders'.($err?": $err":''));

// ---- Reopening a referral reopens its contact task and waitlist entry ----
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,status) VALUES('Synthetic','Synthetic','Synthetic','V429 counselor','V429-CASES Agency','v429-counselor@example.invalid','555-0149','V429-CASES Referral Person','New')"); $v429r=(int)$db->lastInsertId();
$db->prepare("INSERT INTO tasks(title,assigned_to,due_date,status,auto_key) VALUES('Contact new referral: V429',NULL,CURDATE(),'Open',?)")->execute(['ref-contact-'.$v429r]);
$db->prepare("INSERT INTO referral_waitlist(referral_id,status) VALUES(?,'Waiting')")->execute([$v429r]);
check(referral_close($v429r,'Unable to reach','',1)['error']===null,'A referral is closed');
check(referral_reopen($v429r,1)===null,'A closed referral is reopened');
check($db->query("SELECT status FROM tasks WHERE auto_key='ref-contact-$v429r'")->fetchColumn()==='Open'
    && $db->query("SELECT status FROM referral_waitlist WHERE referral_id=$v429r")->fetchColumn()==='Waiting','Reopening a referral reopens its contact task and its waitlist entry');

// ---- In-person signing: expiry after two days, refusals in the document's language ----
$db->prepare("INSERT INTO client_signatures(client_id,kind,title,body,signer_name,signer_email,token,sent_at,method,lang) VALUES(?,'services','V429 consent','Body','V429-CASES Client One','',?,DATE_SUB(NOW(),INTERVAL 3 DAY),'In person','es')")->execute([$v429c1,bin2hex(random_bytes(24))]); $v429sig=(int)$db->lastInsertId();
check(signature_status(client_signature($v429sig,$v429c1))==='Expired, not signed here','An in-person document older than two days is expired');
[$code,$out,$err]=$renderPage('signatures',['client'=>(string)$v429c1]);
check($err==='' && !str_contains($out,'sign='.$v429sig.'"'),'An expired in-person document no longer offers "Sign it now"'.($err?": $err":''));
check(str_starts_with(signature_reason_in('The name typed does not match the name on the document: X.','es','X'),'El nombre escrito no coincide'),'A refused signature is worded in Spanish for a Spanish document');

// ---- Letters: "Keep as a case note" sent twice files one note ----
$v429key='A1B2C3D4-0000-4000-8000-000000000429';
putenv('CMS_TEST_SESSION_ADD='.json_encode(['web_once'=>[$v429key.'-NOTE'=>1]]));
[$code,,$err]=$renderPage('letters',[],['client'=>(string)$v429c1,'letter'=>array_key_first(LETTER_TEMPLATES),'title'=>'V429-CASES letter','body'=>'Synthetic letter','save_note'=>'1','idem_key'=>$v429key]);
putenv('CMS_TEST_SESSION_ADD');
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='Letter: V429-CASES letter'")->fetchColumn()===0,'A letter note already kept under the form\'s key is not filed again'.($err?": $err":''));
[$code,,$err]=$renderPage('letters',[],['client'=>(string)$v429c1,'letter'=>array_key_first(LETTER_TEMPLATES),'title'=>'V429-CASES letter','body'=>'Synthetic letter','save_note'=>'1','idem_key'=>$v429key]);
check((int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='Letter: V429-CASES letter'")->fetchColumn()===1,'A letter kept as a note for the first time is filed'.($err?": $err":''));

// ---- Goal progress sent twice is recorded once ----
$db->prepare("INSERT INTO training_tracks (client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES (?,?,?,?,?,?)")->execute([$v429c1,'V429-CASES track','AUTH-429-1','State VR',30,65]); $v429t=(int)$db->lastInsertId();
$db->prepare("INSERT INTO smart_goals (track_id,specific_goal,measurable_metric,status,progress_pct) VALUES (?,?,?,?,?)")->execute([$v429t,'V429-CASES goal','Synthetic metric','In Progress',10]); $v429g=(int)$db->lastInsertId();
$v429prog=['update_goal'=>'1','goal_id'=>(string)$v429g,'goal_status'=>'In Progress','progress_pct'=>'40','progress_note'=>'','idem_key'=>'B1B2C3D4-0000-4000-8000-000000000429'];
$renderPage('track',['id'=>(string)$v429t],$v429prog);
[$code,,$err]=$renderPage('track',['id'=>(string)$v429t],$v429prog);
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM goal_progress WHERE goal_id=$v429g")->fetchColumn()===1,'Goal progress sent twice with one key is recorded once'.($err?": $err":''));

// ---- A note edit from an older form does not undo a newer edit ----
$db->prepare("INSERT INTO client_notes (client_id,note_type,subject,body,author_id) VALUES (?,'Case Note','V429-CASES note','Their newer text',1)")->execute([$v429c1]); $v429note=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('client',['id'=>(string)$v429c1],['add_note'=>'1','note_id'=>(string)$v429note,'note_type'=>'Case Note','note_format'=>'free','subject'=>'V429-CASES note','body'=>'My stale text','note_version'=>str_repeat('a',64)]);
check($db->query("SELECT body FROM client_notes WHERE id=$v429note")->fetchColumn()==='Their newer text','A note edit sent from an older form is refused'.($err?": $err":''));
$v429nr=$db->query("SELECT * FROM client_notes WHERE id=$v429note")->fetch();
$v429ver=hash('sha256',json_encode([(string)($v429nr['updated_at']??''),(string)$v429nr['note_type'],(string)($v429nr['note_format']??''),(string)$v429nr['subject'],(string)$v429nr['body'],
    (string)($v429nr['birp_behavior']??''),(string)($v429nr['birp_intervention']??''),(string)($v429nr['birp_response']??''),(string)($v429nr['birp_plan']??'')]));
[$code,,$err]=$renderPage('client',['id'=>(string)$v429c1],['add_note'=>'1','note_id'=>(string)$v429note,'note_type'=>'Case Note','note_format'=>'free','subject'=>'V429-CASES note','body'=>'My current text','note_version'=>$v429ver]);
check($db->query("SELECT body FROM client_notes WHERE id=$v429note")->fetchColumn()==='My current text','A note edit opened on the current version saves'.($err?": $err":''));

// ---- Correcting a client's email redoes the client's reminder only ----
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,reminder_sent) VALUES(?,?,?,?,'Scheduled',1)")->execute([$v429c2,$v429u,date('Y-m-d 09:00:00',strtotime('+1 day')),date('Y-m-d 10:00:00',strtotime('+1 day'))]); $v429rs=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('client_edit',['id'=>(string)$v429c2],['name'=>'V429-CASES Client Two','status'=>'Active Training','client_type'=>'VR','state'=>'IA','email'=>'v429-two@example.invalid','assigned_to'=>(string)$v429u]);
$v429rem=$db->query("SELECT reminder_sent, staff_reminded_at FROM scheduled_sessions WHERE id=$v429rs")->fetch();
check((int)$v429rem['reminder_sent']===0 && $v429rem['staff_reminded_at']!==null,'A corrected client email redoes the client\'s reminder, not the instructor\'s'.($err?": $err":''));

// ---- The closure reason is marked required ----
[$code,$out,$err]=$renderPage('client_edit',['id'=>(string)$v429c1]);
check($err==='' && str_contains($out,'name="closure_reason" required') && str_contains($out,' hidden disabled>'),'"Why is the case closing?" is marked required, and its hidden fieldset sends nothing'.($err?": $err":''));

// ---- Equipment: a past "needed until" and a client no longer on file are refused on the field ----
$db->exec("INSERT INTO equipment(name,status) VALUES('V429-CASES Display','Available')"); $v429eq=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('equipment',[],['add_reservation'=>'1','equipment_id'=>(string)$v429eq,'client_id'=>(string)$v429c1,'needed_until'=>date('Y-m-d',strtotime('-3 days'))]);
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM equipment_reservations WHERE equipment_id=$v429eq")->fetchColumn()===0,'A reservation needed until a past date is refused'.($err?": $err":''));
[$code,,$err]=$renderPage('equipment',[],['loan_equipment'=>'1','equipment_id'=>(string)$v429eq,'client_id'=>'99999429']);
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM equipment_loans WHERE equipment_id=$v429eq")->fetchColumn()===0,'Lending to a client no longer on file is refused without a server error'.($err?": $err":''));
[$code,,$err]=$renderPage('equipment',[],['add_reservation'=>'1','equipment_id'=>(string)$v429eq,'client_id'=>(string)$v429c1,'needed_from'=>'','needed_from_bad'=>'1']);
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM equipment_reservations WHERE equipment_id=$v429eq")->fetchColumn()===0,'A partly typed reservation date is refused, not saved as blank'.($err?": $err":''));

// ---- Procurement: an amount typed with a comma is kept, not erased ----
$db->prepare("INSERT INTO device_requests(client_id,requested_by,device_name,status,authorized_amount) VALUES(?,1,'V429-CASES device','Requested',1200)")->execute([$v429c1]); $v429dr=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('procurement',[],['rid'=>(string)$v429dr,'authorize'=>'1','authorized_amount'=>'3,000','authorized_at'=>date('Y-m-d')]);
check((float)$db->query("SELECT authorized_amount FROM device_requests WHERE id=$v429dr")->fetchColumn()===3000.0,'An authorized amount typed as 3,000 is saved as 3000'.($err?": $err":''));

// ---- Availability: removing hours already removed says so ----
[$code,,$err]=$renderPage('availability',[],['remove_hours'=>'99999429']);
check($err==='','Removing hours that are already gone does not fail'.($err?": $err":''));
