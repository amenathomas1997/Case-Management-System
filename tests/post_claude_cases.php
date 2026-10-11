<?php
// Executed by full_schema.php against synthetic data only.
$durationCohort=(int)$call('add_cohort',['name'=>'Duration validation test'])['id'];
foreach ([null,0,-1,1.5,1441,'invalid'] as $duration) {
    $input=['cohort_id'=>$durationCohort,'session_date'=>'2026-10-01','activity'=>'Other'];
    if ($duration!==null) $input['duration_min']=$duration;
    $call('log_cohort_session',$input,[],false);
}
check((int)$db->query("SELECT COUNT(*) FROM cohort_sessions WHERE cohort_id=$durationCohort")->fetchColumn()===0,
    'Mobile group logging refuses missing, zero, negative, fractional and oversized durations without saving');
$call('log_cohort_session',['cohort_id'=>$durationCohort,'session_date'=>'2026-10-01','activity'=>'Other','duration_min'=>75]);
check((int)$db->query("SELECT duration_min FROM cohort_sessions WHERE cohort_id=$durationCohort")->fetchColumn()===75,
    'Mobile group logging preserves a valid duration exactly');

// Historical imports may use every byte of the referral's subject/body columns.
$longReferral=function()use($db):int{
    $db->prepare("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,assigned_to) VALUES('Synthetic',?,?,'Synthetic referrer','Synthetic agency','referrer@example.invalid','555-0100','Synthetic long referral',1)")
        ->execute([str_repeat('S',255),str_repeat('界',21844)]);
    return (int)$db->lastInsertId();
};
foreach (['web','api'] as $channel) {
    $longId=$longReferral();
    if ($channel==='web') {
        [$code,$out,$err]=$renderPage('referrals',[],['id'=>(string)$longId,'convert'=>'1']);
        check($code===0 && $err==='', 'Web conversion accepts a maximum-length historical referral');
    } else $call('convert_referral',['id'=>$longId]);
    $converted=$db->query("SELECT client_id,status,body FROM referrals WHERE id=$longId")->fetch();
    $note=$db->query('SELECT subject,body FROM client_notes WHERE client_id='.(int)$converted['client_id'].' ORDER BY id DESC LIMIT 1')->fetch();
    check($converted['status']==='Converted' && mb_strlen($note['subject'])===255
        && strlen($note['body'])<=65535 && mb_check_encoding($note['body'],'UTF-8')
        && str_contains($note['body'],'full text is retained in referral #'.$longId)
        && $converted['body']===str_repeat('界',21844), $channel.' conversion fits note limits, marks shortening and preserves the original referral');
    $owner=$db->query("SELECT assigned_to FROM referrals WHERE id=$longId")->fetchColumn();
    $call('assign_referral',['id'=>$longId,'user_id'=>0],[],false);
    [$code,$out,$err]=$renderPage('referrals',[],['id'=>(string)$longId,'assign'=>'1','assigned_to'=>'0']);
    check($code===0 && $err==='' && $db->query("SELECT assigned_to FROM referrals WHERE id=$longId")->fetchColumn()===$owner,
        'Both assignment endpoints refuse to detach a converted referral from its client');
    [$code,$out,$err]=$renderPage('referrals',['view'=>$longId]);
    check(!str_contains($out,'name="assign"') && str_contains($out,'follows the assigned instructor'),
        'Converted referrals explain client ownership instead of offering reassignment');
}
$queueId=$longReferral();
$db->prepare("INSERT INTO tasks(title,referral_id,auto_key) VALUES('Synthetic referral follow-up',?,?)")->execute([$queueId,'ref-contact-'.$queueId]);
$call('assign_referral',['id'=>$queueId,'user_id'=>1]);
check((int)$db->query("SELECT assigned_to FROM tasks WHERE referral_id=$queueId")->fetchColumn()===1,
    'An open referral and its follow-up task are assigned together');
[$code,$out,$err]=$renderPage('referrals',[],['id'=>(string)$queueId,'assign'=>'1','assigned_to'=>'0']);
check($code===0 && $err==='' && $db->query("SELECT assigned_to FROM referrals WHERE id=$queueId")->fetchColumn()===null
    && $db->query("SELECT assigned_to FROM tasks WHERE referral_id=$queueId")->fetchColumn()===null,
    'Web unassignment clears the open referral and task together');

// Synthetic records for the checks below.
$db->exec("INSERT INTO clients(name,assigned_to) VALUES('Follow-up synthetic client',1)"); $fuClient=(int)$db->lastInsertId();
// The session a rendered page saved (field errors are kept there across the redirect).
$pageSession=function(callable $run)use($tmp):array{
    $before=glob($tmp.'/sess_*')?:[];
    $result=$run();
    $new=array_values(array_diff(glob($tmp.'/sess_*')?:[],$before));
    return [$result,count($new)===1?(string)file_get_contents($new[0]):''];
};

// Consent: a form posted after its token lapsed (no account, a different csrf)
// signs nothing and comes back with the typed name; a name that differs only by
// case and punctuation signs.
$consentToken=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO consent_forms(client_id,guardian_name,guardian_email,token) VALUES(?,'Mary J. Smith','guardian@example.invalid',?)")->execute([$fuClient,$consentToken]);
$consentId=(int)$db->lastInsertId();
putenv('CMS_TEST_ANON=1');
[$code,$out,$err]=$renderPage('consent',['t'=>$consentToken],['csrf'=>'lapsed-synthetic-csrf','signature'=>'Mary J Smith typed']);
putenv('CMS_TEST_ANON');
check($code===0 && $err==='' && str_contains($out,'value="Mary J Smith typed"') && str_contains($out,'was open for a long time')
    && $db->query("SELECT signed_at FROM consent_forms WHERE id=$consentId")->fetchColumn()===null,
    'A consent form posted with a lapsed token signs nothing and re-shows the typed name' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('consent',['t'=>$consentToken],['signature'=>'mary j smith']);
check($code===0 && $err==='' && $db->query("SELECT signed_at FROM consent_forms WHERE id=$consentId")->fetchColumn()!==null,
    'A consent signature that differs only by case and punctuation signs' . ($err ? ": $err" : ''));

// Counselor portal: an empty code is said on the field and never counts toward
// the link's lockout; a wrong full code against a live code does.
$vrcToken=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token) VALUES('vrc-followup@example.invalid','Synthetic counselor',?)")->execute([$vrcToken]);
$vrcId=(int)$db->lastInsertId();
$vrcFails=fn():int=>(int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='vrc-fail-$vrcId'")->fetchColumn();
putenv('CMS_TEST_ANON=1');
putenv('CMS_TEST_SESSION='.json_encode(['vrc_code_'.$vrcId=>['hash'=>password_hash('123456',PASSWORD_DEFAULT),'exp'=>time()+600,'tries'=>0]]));
[[$code,$out,$err],$sess]=$pageSession(fn()=>$renderPage('vrc',['t'=>$vrcToken],['verify_code'=>'1','code'=>'']));
check($code===0 && $err==='' && $vrcFails()===0 && str_contains($sess,'Enter the 6-digit code from the email.'),
    'An empty counselor portal code is refused on the field and adds no failed attempt' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('vrc',['t'=>$vrcToken],['verify_code'=>'1','code'=>'000000']);
check($code===0 && $err==='' && $vrcFails()===1,'A wrong full code against a live code counts one failed attempt' . ($err ? ": $err" : ''));
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');

// Programs: a session logged by mistake is deleted with its attendance, and audited.
$db->exec("INSERT INTO cohorts(name) VALUES('Delete session synthetic cohort')"); $fuCohort=(int)$db->lastInsertId();
$db->prepare("INSERT INTO cohort_sessions(cohort_id,session_date,activity,duration_min) VALUES(?,'2026-10-01','Other',60)")->execute([$fuCohort]); $fuSession=(int)$db->lastInsertId();
$db->prepare('INSERT INTO cohort_attendance(session_id,client_id,present) VALUES(?,?,1)')->execute([$fuSession,$fuClient]);
[$code,$out,$err]=$renderPage('programs',[],['cohort_id'=>(string)$fuCohort,'delete_session'=>'1','session_id'=>(string)$fuSession]);
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM cohort_sessions WHERE id=$fuSession")->fetchColumn()===0
    && (int)$db->query("SELECT COUNT(*) FROM cohort_attendance WHERE session_id=$fuSession")->fetchColumn()===0
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='cohort.session_delete' AND entity_id=$fuCohort")->fetchColumn()===1,
    'Deleting a group session removes it and its attendance and writes one audit entry' . ($err ? ": $err" : ''));

// Accommodation bookings: a partly typed date and time, or an oversized duration,
// saves nothing and is said on its field.
$fuBookings=fn():int=>(int)$db->query("SELECT COUNT(*) FROM access_bookings WHERE client_id=$fuClient")->fetchColumn();
foreach ([[['needed_at_bad'=>'1'],'needed_at','Enter the full date and time'],[['duration_min'=>'2000'],'duration_min','Enter the duration as whole minutes']] as [$bad,$field,$message]) {
    [[$code,$out,$err],$sess]=$pageSession(fn()=>$renderPage('access_bookings',[],['add_booking'=>'1','client_id'=>(string)$fuClient,'service_type'=>'Other','needed_at'=>'']+$bad));
    check($code===0 && $err==='' && $fuBookings()===0 && str_contains($sess,'field_errors') && str_contains($sess,'"'.$field.'"') && str_contains($sess,$message),
        'A booking with ' . json_encode($bad) . ' saves nothing and sets a field error' . ($err ? ": $err" : ''));
}

// The billing export's table layout: one header row, then sessions and expenses in the same columns.
$db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate) VALUES(?,'Export table synthetic track','EXP-TABLE','State VR',95)")->execute([$fuClient]); $fuTrack=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by) VALUES(?,'2026-10-01',1.5,'Synthetic export session',1)")->execute([$fuTrack]);
$db->prepare("INSERT INTO track_expenses(track_id,expense_date,description,amount) VALUES(?,'2026-10-02','Synthetic export expense',12.50)")->execute([$fuTrack]);
[$code,$out,$err]=$renderPage('export',['track'=>$fuTrack,'layout'=>'table']);
$rows=preg_split('/\r?\n/',trim(preg_replace('/^\xEF\xBB\xBF/','',$out)));
check($code===0 && $err==='' && str_starts_with($rows[0],'Type,Date') && !str_contains($out,'BILLING SUMMARY')
    && count(array_filter($rows,fn($r)=>str_starts_with($r,'Session,')))===1 && count(array_filter($rows,fn($r)=>str_starts_with($r,'Expense,')))===1,
    'The table layout of the billing export starts with its header row and lists sessions and expenses' . ($err ? ": $err" : ''));

// A booking year typed as 26 (sent as 0026) is refused on its field, never stored.
[[$code,$out,$err],$sess]=$pageSession(fn()=>$renderPage('access_bookings',[],['add_booking'=>'1','client_id'=>(string)$fuClient,'service_type'=>'Other','needed_at'=>'0026-10-09T10:00']));
check($code===0 && $err==='' && $fuBookings()===0 && str_contains($sess,'"needed_at"') && str_contains($sess,'Enter a year from 2000'),
    'A booking dated in the year 0026 saves nothing and sets a needed_at field error' . ($err ? ": $err" : ''));

// An archived client's survey link is not recognized: nothing is greeted by name.
$db->exec("INSERT INTO clients(name,archived_at) VALUES('Archived survey synthetic client',NOW())"); $surveyClient=(int)$db->lastInsertId();
$surveyToken=bin2hex(random_bytes(24));
$db->prepare('INSERT INTO surveys(client_id,token) VALUES(?,?)')->execute([$surveyClient,$surveyToken]);
putenv('CMS_TEST_ANON=1');
[$code,$out,$err]=$renderPage('survey',['t'=>$surveyToken]);
putenv('CMS_TEST_ANON');
check($code===0 && $err==='' && str_contains($out,'Link not recognized') && !str_contains($out,'Archived survey synthetic client'),
    'A survey link for an archived client renders "Link not recognized"' . ($err ? ": $err" : ''));

// Marking a referral reviewed when it is no longer New changes nothing, says so and is not audited.
$reviewedId=$longReferral();
$db->exec("UPDATE referrals SET status='Reviewed' WHERE id=$reviewedId");
[[$code,$out,$err],$sess]=$pageSession(fn()=>$renderPage('referrals',[],['id'=>(string)$reviewedId,'mark_reviewed'=>'1']));
check($code===0 && $err==='' && $db->query("SELECT status FROM referrals WHERE id=$reviewedId")->fetchColumn()==='Reviewed'
    && str_contains($sess,'Nothing was changed')
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='referral.review' AND entity_id=$reviewedId")->fetchColumn()===0,
    'Marking a referral that is no longer New as reviewed flashes an error and writes no review audit' . ($err ? ": $err" : ''));
