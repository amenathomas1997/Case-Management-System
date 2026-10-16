<?php
// Included by full_schema.php after work_log_cases.php, against its synthetic database only:
// progress and final reports, closure follow-ups, surveys by phone and their reminder,
// outcome measures and breakdowns, and vision status (3.0.47).
$db->exec("INSERT INTO clients(name,status,assigned_to,dob) VALUES('Followup synthetic client','Active Training',1,'1980-05-01')"); $fc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Followup track','Active','FU-1','Synthetic VR',20,50,DATE_SUB(CURDATE(),INTERVAL 60 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY))")->execute([$fc]); $ft=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by,hourly_rate) VALUES(?,CURDATE(),2,'Synthetic followup session',1,50)")->execute([$ft]);

/* Vision status and age of onset */
$edit=['name'=>'Followup synthetic client','status'=>'Active Training','client_type'=>'VR','dob'=>'1980-05-01','assigned_to'=>'1'];
[$code,,$err]=$renderPage('client_edit',['id'=>$fc],$edit+['vision_status'=>'Low vision','vision_onset_age'=>'abc']);
check($code===0 && $db->query("SELECT vision_onset_age FROM clients WHERE id=$fc")->fetchColumn()===null,'An age of onset that is not a number is refused' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client_edit',['id'=>$fc],$edit+['vision_status'=>'Low vision','vision_onset_age'=>'40']);
$vc=$db->query("SELECT vision_status,vision_onset_age FROM clients WHERE id=$fc")->fetch();
check($code===0 && $err==='' && $vc['vision_status']==='Low vision' && (int)$vc['vision_onset_age']===40,'Vision status and age of onset are saved' . ($err ? ": $err" : ''));

/* Progress and final reports */
[$code,$out,$err]=$renderPage('report',['track'=>$ft,'final'=>'1']);
check($code===0 && $err==='' && str_contains($out,'Final'),'The final report opens' . ($err ? ": $err" : ''));
$url=create_report_ack($fc,$ft,'vrc@example.test','Synthetic report as sent');
check($db->query("SELECT report_text FROM report_acks WHERE client_id=$fc ORDER BY id DESC LIMIT 1")->fetchColumn()==='Synthetic report as sent','A copy of the report is kept as it was sent');

/* Closing schedules the 30, 60 and 90-day follow-ups */
[$code,,$err]=$renderPage('client_edit',['id'=>$fc],array_merge($edit,['status'=>'Closed - Rehabilitated','vision_status'=>'Low vision','vision_onset_age'=>'40','closure_reason'=>'Employed','closure_employed'=>'yes','closure_employer'=>'Synthetic Employer']));
$fus=$db->query("SELECT id,interval_days FROM closure_followups WHERE client_id=$fc ORDER BY interval_days")->fetchAll();
check($code===0 && $err==='' && array_map('intval',array_column($fus,'interval_days'))===[30,60,90],'Closing a case schedules follow-ups at 30, 60 and 90 days' . ($err ? ": $err" : ''));
$f1=(int)$fus[0]['id'];
$db->exec("UPDATE closure_followups SET due_on=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=$f1");
$cronRun('daily_alerts.php',true);
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='closefu-$f1' AND status='Open'")->fetchColumn()===1,'A due follow-up opens one task');
[$code,$out,$err]=$renderPage('client',['id'=>$fc]);
check($code===0 && $err==='' && str_contains($out,'id="after-h"') && str_contains($out,'Save the 30-day check'),'The client page offers the due follow-up' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client',['id'=>$fc],['closure_followup'=>'1','followup_id'=>(string)$f1,'still_employed'=>'yes','still_using_tech'=>'no','independent'=>'','needs_service'=>'yes','fu_notes'=>'Wants help with a new phone']);
$fr=$db->query("SELECT * FROM closure_followups WHERE id=$f1")->fetch();
check($code===0 && $err==='' && $fr['status']==='Completed' && (int)$fr['still_employed']===1 && (int)$fr['still_using_tech']===0 && $fr['independent']===null
    && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='closefu-$f1' AND status='Done'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='closefu-need-$f1' AND status='Open'")->fetchColumn()===1,'Recording a follow-up closes its task, and needing more service opens a re-referral task' . ($err ? ": $err" : ''));
check(closure_followup_record($f1,$fc,1,1,1,0,'',1)!==null,'A follow-up is recorded once');

/* Surveys by phone, and the reminder */
$db->exec("UPDATE clients SET a11y_contact='Phone', email='followup@example.test', email_verified=1 WHERE id=$fc");
check(send_outcome_survey($fc)===false && (int)$db->query("SELECT COUNT(*) FROM surveys WHERE client_id=$fc")->fetchColumn()===0,'A client who prefers phone is not emailed the survey');
[$code,,$err]=$renderPage('client',['id'=>$fc],['phone_survey'=>'1','satisfaction'=>'11','goals_met'=>'8']);
check($code===0 && (int)$db->query("SELECT COUNT(*) FROM surveys WHERE client_id=$fc")->fetchColumn()===0,'Phone answers out of range are refused' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client',['id'=>$fc],['phone_survey'=>'1','satisfaction'=>'9','goals_met'=>'8','comments'=>'Very helpful']);
check($code===0 && $err==='' && $db->query("SELECT channel FROM surveys WHERE client_id=$fc AND completed_at IS NOT NULL")->fetchColumn()==='Phone','Survey answers taken by phone are recorded' . ($err ? ": $err" : ''));
$db->exec("UPDATE clients SET a11y_contact=NULL WHERE id=$fc");
$db->prepare("INSERT INTO surveys(client_id,token,sent_at) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 8 DAY))")->execute([$fc,bin2hex(random_bytes(24))]); $sv8=(int)$db->lastInsertId();
$cronRun('daily_alerts.php',true);
check($db->query("SELECT reminded_at FROM surveys WHERE id=$sv8")->fetchColumn()!==null,'An emailed survey unanswered after a week gets one reminder');
[$code,$out,$err]=$renderPage('survey_responses',['from'=>date('Y-m-d',strtotime('-1 day')),'to'=>date('Y-m-d')]);
check($code===0 && $err==='' && str_contains($out,'Followup synthetic client') && str_contains($out,'Very helpful'),'The survey responses page lists the answers' . ($err ? ": $err" : ''));

/* Outcome measures and breakdowns */
$om=outcome_measures(date('Y-m-d',strtotime('-1 day')),date('Y-m-d'));
check($om['closure_followups']>=1 && $om['need_more_service']>=1 && $om['still_employed_pct']!==null,'The outcome measures count closure follow-ups');
$ob=outcome_breakdowns(date('Y-m-d',strtotime('-1 day')),date('Y-m-d'));
check(isset($ob['vision']['Low vision'],$ob['age']['22 to 54']) && $ob['vision']['Low vision']['rehab']>=1,'The outcome breakdowns count by vision status and age band');
check(age_band(null,'2026-01-01')==='Not recorded' && age_band('2012-06-01','2026-01-01')==='Under 14' && age_band('1950-01-01','2026-01-01')==='65 and over','Age bands');
[$code,$out,$err]=$renderPage('outcomes',['from'=>date('Y-m-d',strtotime('-1 day')),'to'=>date('Y-m-d')]);
check($code===0 && $err==='' && str_contains($out,'Results after training') && str_contains($out,'Clients served by vision status'),'The Outcome report shows the results and breakdowns' . ($err ? ": $err" : ''));
check(report_ai_facts(['a_b'=>1,'c'=>['x'=>2,'y'=>3]])==="a b: 1\nc: x 2, y 3",'Report figures are flattened for the AI narrative');

/* Review fixes */
$r=build_track_report($ft,'Tester','',1,['final'=>true]);
check(str_contains($r['text'],'Synthetic Employer') && !str_contains($r['ai_text'],'Synthetic Employer') && str_contains($r['ai_text'],'[EMPLOYER]'),'The employer is masked in what the AI is sent');
[$code,,$err]=$renderPage('client_edit',['id'=>$fc],array_merge($edit,['status'=>'Closed - Other','closure_reason'=>'Other','closure_note'=>'Correction']));
check((int)$db->query("SELECT COUNT(*) FROM closure_followups WHERE client_id=$fc")->fetchColumn()===3,'Correcting one Closed status to another does not schedule more follow-ups' . ($err ? ": $err" : ''));
$f2=(int)$fus[1]['id'];
$db->exec("UPDATE closure_followups SET due_on=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=$f2");
$cronRun('daily_alerts.php',true);
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='closefu-$f2' AND status='Open'")->fetchColumn()===1,'The second check opens its task');
$db->exec("UPDATE clients SET status='Active Training' WHERE id=$fc");
$cronRun('daily_alerts.php',true);
check($db->query("SELECT status FROM closure_followups WHERE id=$f2")->fetchColumn()==='Cancelled' && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='closefu-$f2' AND status='Open'")->fetchColumn()===0,'Reopening a case cancels its pending checks and closes their tasks');
$db->prepare("INSERT INTO surveys(client_id,token,sent_at) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 9 DAY))")->execute([$fc,bin2hex(random_bytes(24))]); $sv9=(int)$db->lastInsertId();
survey_record_phone($fc,7,7,'',1);
$cronRun('daily_alerts.php',true);
check($db->query("SELECT reminded_at FROM surveys WHERE id=$sv9")->fetchColumn()===null,'No reminder once the client has answered by phone');
check(client_survey_by_phone($fc)===false,'A client with no contact preference is emailed the survey');
