<?php
// Included by full_schema.php after exports_timeline_cases.php, against its synthetic database
// only: report copies in the portal, practice feedback, portal history and letters (3.0.51).
$db->exec("INSERT INTO clients(name,status,assigned_to,counselor_name) VALUES('Portal History Person','Active Training',1,'Pat Counselor')"); $pc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date,completed_at) VALUES(?,'Finished JAWS training','Completed','PH-1','Synthetic VR',10,50,'2026-01-01','2026-12-31',NOW())")->execute([$pc]); $pt1=(int)$db->lastInsertId();
$db->prepare("INSERT INTO smart_goals(track_id,specific_goal,measurable_metric,status) VALUES(?,'Send an email with JAWS','Sends 5','Achieved')")->execute([$pt1]);
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by,hourly_rate) VALUES(?,CURDATE(),3,'Synthetic',1,50)")->execute([$pt1]);
$db->prepare("INSERT INTO homework(track_id,title,instructions,status,assigned_at) VALUES(?,'Practice Insert+F7','Every day','Completed',CURDATE())")->execute([$pt1]); $phw=(int)$db->lastInsertId();
$db->prepare("INSERT INTO homework_practice(homework_id,minutes,difficulty,notes,request_key) VALUES(?,20,'Easy','Went well',?)")->execute([$phw,bin2hex(random_bytes(16))]); $pp=(int)$db->lastInsertId();
$tok=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$pc,$tok]);

/* Practice feedback */
check(practice_feedback_save($pp,'',1)!==null,'Feedback must say something');
[$code,,$err]=$renderPage('workflows',['view'=>'homework'],['practice_feedback'=>'1','client_id'=>(string)$pc,'practice_id'=>(string)$pp,'feedback'=>'Great work on the shortcut']);
check($code===0 && $db->query("SELECT staff_feedback FROM homework_practice WHERE id=$pp")->fetchColumn()==='Great work on the shortcut','Staff record feedback on a practice update' . ($err ? ": $err" : ''));

/* Portal history */
[$code,$out,$err]=$renderPage('portal',['t'=>$tok]);
check($code===0 && $err==='' && str_contains($out,'Great work on the shortcut') && str_contains($out,'Practice Insert+F7') && str_contains($out,'Finished JAWS training') && str_contains($out,'Send an email with JAWS'),
    'The portal shows feedback, finished homework and a completion summary' . ($err ? ": $err" : ''));

/* A report copy in the portal */
$rid=report_share_to_portal($pc,'Progress report, test','Line one'."\n\n".'Line two',1);
$rd=$db->query("SELECT * FROM client_documents WHERE id=$rid")->fetch();
check($rid>0 && $rd['category']==='Resource' && str_contains((string)file_get_contents(cfg('upload_dir').'/'.$rd['path']),'<h1>Progress report, test</h1>'),'A report copy is saved as an accessible page in the portal');
[$code,$out,$err]=$renderPage('portal',['t'=>$tok]);
check(str_contains($out,'Progress report, test'),'The client sees the report copy');

/* Letters */
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 3 DAY),DATE_ADD(NOW(),INTERVAL 3 DAY)+INTERVAL 1 HOUR,'Scheduled')")->execute([$pc]);
$l=letter_render('welcome',$pc);
check(str_contains($l,'Dear Portal,') && str_contains($l,'Pat Counselor') && !str_contains($l,'{') && str_contains($l,date('l, F j, Y',strtotime('+3 days'))),'A letter is filled in from the record');
check(str_contains(letter_render('loan',$pc),'[not on file]'),'A detail the record lacks is marked');
[$code,$out,$err]=$renderPage('letters',['client'=>(string)$pc,'letter'=>'appointment']);
check($code===0 && $err==='' && str_contains($out,'Appointment confirmation') && str_contains($out,'Dear Portal,'),'The letters page opens a filled-in letter' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('letters',[],['client'=>(string)$pc,'letter'=>'appointment','title'=>'Appointment confirmation','body'=>"Dear Portal,\n\nSee you soon.",'save_doc'=>'1','to_portal'=>'1']);
check($code===0 && (int)$db->query("SELECT COUNT(*) FROM client_documents WHERE client_id=$pc AND category='Resource' AND title LIKE 'Appointment confirmation%'")->fetchColumn()===1,'A letter is saved to the documents and the portal' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('letters',[],['client'=>(string)$pc,'letter'=>'no_show','title'=>'Missed appointment','body'=>'We missed you.','save_note'=>'1']);
check((int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$pc AND subject='Letter: Missed appointment'")->fetchColumn()===1,'A letter can be kept as a case note' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('letters',[],['save_wording'=>'1','wording_key'=>'appointment','wording'=>"Hello {first_name}, see you {next_session}."]);
check(letter_template('appointment')==="Hello {first_name}, see you {next_session}." && str_starts_with(letter_render('appointment',$pc),'Hello Portal,'),'An administrator changes a letter\'s wording' . ($err ? ": $err" : ''));
$renderPage('letters',[],['save_wording'=>'1','wording_key'=>'appointment','wording'=>'']);
check(letter_template('appointment')===LETTER_TEMPLATES['appointment'][1],'Empty wording returns to the built-in letter');

/* Review fixes */
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES(?,'Next plan','Active','PH-2','Synthetic VR',10,50)")->execute([$pc]);
check(str_contains(letter_render('completion',$pc),'Finished JAWS training') && str_contains(letter_render('welcome',$pc),'Next plan'),'A completion letter names the plan just finished; others the current plan');
$db->exec("INSERT INTO clients(name,status) VALUES('Dr. Jane Titled','Active Training')"); $dj=(int)$db->lastInsertId();
check(str_contains(letter_render('appointment',$dj),'Dear Jane,') && str_contains(letter_render('appointment',$dj),'[not on file]'),'A title is skipped for the first name, and a missing instructor is marked');
