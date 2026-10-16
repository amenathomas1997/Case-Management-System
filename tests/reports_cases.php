<?php
// Included by full_schema.php after authorization_email_cases.php, against its synthetic
// database only: the fiscal year, business summary, attendance, unused authorizations,
// data quality and duplicate warnings (3.0.49).
require_once $root.'/public/inc/report_period.php';
require_once $root.'/public/inc/scheduled_reports.php';
set_setting('fiscal_year_start','7');
check(fiscal_year_of('2026-10-16')===['2026-07-01','2027-06-30'] && fiscal_year_of('2026-03-01')===['2025-07-01','2026-06-30'],'The fiscal year follows the month set under Administration');
check(report_period_dates('last_fy')===fiscal_year_of(date('Y-m-d',strtotime(fiscal_year_of(date('Y-m-d'))[0].' -1 day'))),'Last fiscal year is the year before this one');
[$code,,$err]=$renderPage('admin',[],['save_fiscal_year'=>'1','fiscal_year_start'=>'10']);
check($code===0 && fiscal_year_start_month()===10,'Administration saves the fiscal year start' . ($err ? ": $err" : ''));
check(comparison_periods('2026-07-01','2026-09-30')===['previous'=>['2026-03-31','2026-06-30'],'last_year'=>['2025-07-01','2025-09-30']],'Comparison periods are the same length before, and the same dates a year earlier');
check(business_change_text(12,10,'')==='up 2' && business_change_text(5,10,'%')==='down 5 points' && business_change_text(3,3,'')==='no change' && business_change_text(null,3,'')==='n/a','Changes are said in words');

/* Attendance */
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Attendance synthetic client','Active Training',1)"); $ac=(int)$db->lastInsertId();
foreach (['Completed','No Show','No Show','Cancelled'] as $i=>$st) $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,is_remote) VALUES(?,1,DATE_SUB(NOW(),INTERVAL ? DAY),DATE_SUB(NOW(),INTERVAL ? DAY),?,?)")->execute([$ac,$i+40,$i+40,$st,$i%2]);
$rows=attendance_rows(date('Y-m-d',strtotime('-60 days')),date('Y-m-d'),'client','scheduled_sessions');
$mine=array_values(array_filter($rows,fn($r)=>(int)$r['client_id']===$ac))[0]??null;
check($mine && (int)$mine['done']===1 && (int)$mine['noshow']===2 && (int)$mine['cancelled']===1 && $mine['rate']===67,'Attendance counts completed, no-show and cancelled sessions, with the no-show rate');
check(in_array($ac,array_map('intval',array_column(repeated_no_shows('scheduled_sessions'),'id')),true),'Two no-shows in 60 days are listed');
check(client_attendance($ac)===['done'=>1,'noshow'=>2,'cancelled'=>1],"A client's attendance");
[$code,$out,$err]=$renderPage('attendance',['by'=>'setting','from'=>date('Y-m-d',strtotime('-60 days')),'to'=>date('Y-m-d')]);
check($code===0 && $err==='' && str_contains($out,'Remote') && str_contains($out,'In person'),'The attendance page counts by remote or in person' . ($err ? ": $err" : ''));

/* Unused authorizations, and when a track ended */
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Unused track','Active','UN-1','Unused VR',10,50,DATE_SUB(CURDATE(),INTERVAL 90 DAY),DATE_ADD(CURDATE(),INTERVAL 90 DAY))")->execute([$ac]); $ut=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by,hourly_rate) VALUES(?,DATE_SUB(CURDATE(),INTERVAL 10 DAY),4,'Synthetic',1,50)")->execute([$ut]);
$call('save_track',['id'=>$ut,'client_id'=>$ac,'title'=>'Unused track','status'=>'Completed','auth_number'=>'UN-1','funding_agency'=>'Unused VR','authorized_hours'=>10,'hourly_rate'=>50,'start_date'=>date('Y-m-d',strtotime('-90 days')),'expiration_date'=>date('Y-m-d',strtotime('+90 days'))]);
check($db->query("SELECT DATE(completed_at) FROM training_tracks WHERE id=$ut")->fetchColumn()===date('Y-m-d'),'Marking a track Completed records when it ended');
$un=array_values(array_filter(unused_authorizations(date('Y-m-d'),date('Y-m-d'),'training_tracks'),fn($r)=>(int)$r['id']===$ut))[0]??null;
check($un && (float)$un['hours_unused']===6.0 && $un['value_unused']===300.0 && $un['pct_used']===40,'An ended authorization shows what was left unused');
check((unused_totals([$un],'funding_agency')['Unused VR']['value_unused']??0)===300.0,'Unused totals by funding agency');
$call('save_track',['id'=>$ut,'client_id'=>$ac,'title'=>'Unused track','status'=>'Active','auth_number'=>'UN-1','funding_agency'=>'Unused VR','authorized_hours'=>10,'hourly_rate'=>50,'start_date'=>date('Y-m-d',strtotime('-90 days')),'expiration_date'=>date('Y-m-d',strtotime('+90 days'))]);
check($db->query("SELECT completed_at FROM training_tracks WHERE id=$ut")->fetchColumn()===null,'Reopening a track clears when it ended');
foreach (['business_summary','unused_authorizations','data_quality'] as $pg) {
    [$code,$out,$err]=$renderPage($pg,['from'=>date('Y-m-d',strtotime('-30 days')),'to'=>date('Y-m-d')]);
    check($code===0 && $err==='',"$pg opens" . ($err ? ": $err" : ''));
}
[$code,$out,$err]=$renderPage('business_summary',['export'=>'1','from'=>date('Y-m-d',strtotime('-30 days')),'to'=>date('Y-m-d')]);
check($code===0 && str_contains($out,'BUSINESS SUMMARY') && str_contains($out,'No-show rate'),'The business summary downloads as CSV' . ($err ? ": $err" : ''));
$bf=business_figures(date('Y-m-d',strtotime('-60 days')),date('Y-m-d'),'training_tracks','clients','client_status_history','scheduled_sessions',null);
check($bf['no_shows']>=2 && $bf['sessions_completed']>=1 && !array_diff(array_keys($bf),array_keys(BUSINESS_FIGURE_LABELS)) && !array_diff(array_keys(BUSINESS_FIGURE_LABELS),array_keys($bf)),'The business figures count sessions, and every figure has a label');

/* Data quality and duplicates */
$db->exec("INSERT INTO clients(name,status,dob,email) VALUES('Quality Duplicate','Active Training','1990-02-03','dup@example.test'),('Quality Duplicate','Pending Assessment','1990-02-03',NULL)");
$dq=array_column(data_quality_checks(null),3,0);
check(in_array('Quality Duplicate',array_column($dq['duplicates'],'name'),true) && in_array('Quality Duplicate',array_column($dq['training_no_track'],'name'),true),'Data quality lists likely duplicates and Active Training with no track');
check(count(possible_duplicate_clients('Someone Else',null,null,'DUP@example.test'))===1 && possible_duplicate_clients('Nobody Like This',null,null,null)===[],'Possible duplicates match on email, case-insensitively');
[$code,$out,$err]=$renderPage('client_edit',[],['name'=>'Quality Duplicate','status'=>'Pending Assessment','client_type'=>'VR']);
check($code===0 && str_contains($out,'Possible duplicate') && (int)$db->query("SELECT COUNT(*) FROM clients WHERE name='Quality Duplicate'")->fetchColumn()===2,'Adding a client who may already have a record warns first' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client_edit',[],['name'=>'Quality Duplicate','status'=>'Pending Assessment','client_type'=>'VR','not_duplicate'=>'1']);
check($code===0 && (int)$db->query("SELECT COUNT(*) FROM clients WHERE name='Quality Duplicate'")->fetchColumn()===3,'Ticking "This is a different person" saves it' . ($err ? ": $err" : ''));
$r=$call('save_client',['name'=>'Quality Duplicate','status'=>'Pending Assessment']);
check(str_contains((string)($r['duplicate_warning']??''),'Possible duplicate'),'The app is told about a possible duplicate');

/* Review fixes */
$db->exec("INSERT INTO users(name,email,password_hash,role,is_active) VALUES('Other Instructor','other.instructor@example.test','x','instructor',1)"); $oi=(int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,assigned_to,dob) VALUES('Hidden Caseload Person','Active Training',?,'1980-01-01')")->execute([$oi]);
$hd=possible_duplicate_clients('Hidden Caseload Person',null,null,null);
$txt=duplicate_warning_text($hd,1);
check(!str_contains($txt,'Hidden Caseload Person') && str_contains($txt,'1 record on another caseload') && str_contains(duplicate_warning_text($hd,null),'Hidden Caseload Person'),'A caseload-limited user is told only a count for duplicates on other caseloads');
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Future end','Active','FUT-1','',10,50,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 5 DAY))")->execute([$ac]); $ft2=(int)$db->lastInsertId();
check(!in_array($ft2,array_map('intval',array_column(unused_authorizations(date('Y-m-d'),date('Y-m-d',strtotime('+30 days')),'training_tracks'),'id')),true),'An active authorization that has not expired yet is not counted as ended');
check(array_keys(unused_totals([['funding_agency'=>'','authorized_hours'=>1,'hours_done'=>0,'hours_unused'=>1,'authorized_value'=>50,'value_done'=>0,'invoiced'=>0,'value_unused'=>50]],'funding_agency'))===['Not recorded'],'A blank group is labelled Not recorded');
check(comparison_periods('2024-02-01','2024-02-29')['last_year']===['2023-02-01','2023-02-28'],'February 29 compares with February 28 a year earlier');
