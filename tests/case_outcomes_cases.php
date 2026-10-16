<?php
// Included by full_schema.php after billing_cases.php, against its synthetic database
// only: referral outcomes and case closure (3.0.35).
$newRef=function(string $name,string $email='referrer-34@example.invalid')use($db):int{
    $db->prepare("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,referral_source) VALUES('Synthetic','Synthetic referral','Synthetic body','Synthetic counselor','Synthetic agency',?,'555-0134',?,'counselor')")->execute([$email,$name]);
    $id=(int)$db->lastInsertId();
    add_task('Contact referral',null,null,$id,date('Y-m-d'),null,null,'ref-contact-'.$id);
    return $id;
};
$refRow=fn(int $id)=>$db->query("SELECT * FROM referrals WHERE id=$id")->fetch();

/* Close a referral with a reason; the referrer is emailed the outcome */
$r1=$newRef('Closed referral synthetic person');
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('referrals',[],['id'=>(string)$r1,'close_referral'=>'1','close_reason'=>'Other','close_note'=>'']));
check($code===0 && $err==='' && $refRow($r1)['status']==='New' && str_contains($sess,'Give a short note for Other'),'Closing with Other needs a note' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('referrals',[],['id'=>(string)$r1,'close_referral'=>'1','close_reason'=>'Unable to reach','close_note'=>'Three calls, no answer']);
$row=$refRow($r1);
check($code===0 && $err==='' && $row['status']==='Closed' && $row['closed_reason']==='Unable to reach' && $row['outcome_emailed_at']!==null
    && $db->query("SELECT status FROM tasks WHERE auto_key='ref-contact-$r1'")->fetchColumn()==='Done',
    'A referral closes with its reason, the referrer is emailed the outcome, and the contact task is done' . ($err ? ": $err" : ''));
[$subj,$body]=email_template('referral_closed',['ref'=>referral_ref($r1),'date'=>fmt_date($row['created_at']),'reason'=>REFERRAL_CLOSE_REASONS['Unable to reach']]);
check(str_contains($subj.$body,referral_ref($r1)) && !str_contains($subj.$body,'Closed referral synthetic person') && !str_contains($body,'Three calls'),
    'The outcome email gives the reference number and a general reason, never the client or the staff note');
[$code,$out,$err]=$renderPage('referrals',[]);
check($code===0 && $err==='' && !str_contains($out,'Closed referral synthetic person'),'A closed referral leaves the open list' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('referrals',['status'=>'closed']);
check($code===0 && $err==='' && str_contains($out,'Closed referral synthetic person'),'The Closed filter lists it' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('referrals',[],['id'=>(string)$r1,'reopen_referral'=>'1']);
check($code===0 && $err==='' && $refRow($r1)['status']==='New' && $refRow($r1)['closed_reason']===null,'A referral closed by mistake can be reopened' . ($err ? ": $err" : ''));

/* Attach a repeat referral to the client already on file; a closed record reopens */
$db->exec("INSERT INTO clients(name,status,archived_at) VALUES('Returning Zzyzxwell','Closed - Other',NOW())"); $back=(int)$db->lastInsertId();
$r2=$newRef('Returning Zzyzxwell');
[$code,$out,$err]=$renderPage('referrals',['view'=>$r2]);
check($code===0 && $err==='' && str_contains($out,'name="attach_referral"') && str_contains($out,'value="'.$back.'"'),'A possible duplicate client offers Attach to this client' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('referrals',[],['id'=>(string)$r2,'attach_referral'=>'1','client_id'=>(string)$back]);
$cl=$db->query("SELECT status,archived_at FROM clients WHERE id=$back")->fetch();
check($code===0 && $err==='' && $cl['status']==='Pending Assessment' && $cl['archived_at']===null
    && $refRow($r2)['status']==='Converted' && (int)$refRow($r2)['client_id']===$back
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$back AND note_type='Intake'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM client_status_history WHERE client_id=$back AND note LIKE '%".referral_ref($r2)."%'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='reref-$r2'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM clients WHERE name='Returning Zzyzxwell'")->fetchColumn()===1,
    'Attaching reopens the closed record, adds the intake note and history, opens a follow-up task, and makes no second record' . ($err ? ": $err" : ''));
/* The app: close and attach */
$r3=$newRef('App closed referral person');
$closed=$call('close_referral',['id'=>$r3,'reason'=>'Duplicate']);
check(($closed['emailed']??null)===true && $refRow($r3)['status']==='Closed','The app closes a referral with a reason, and the referrer is emailed');
$call('close_referral',['id'=>$r3,'reason'=>'Duplicate'],[],false,'Only an open referral');
$detail=$call('referral',[],['id'=>$newRef('Returning Zzyzxwell')]);
check(isset($detail['duplicate_clients'][0]['id']) && in_array('Unable to reach',$detail['close_reasons'],true),'The app is given the clients a referral can be attached to, and the close reasons');

/* Closing a case records why, and the outcome */
$db->exec("INSERT INTO clients(name,status) VALUES('Closure synthetic client','Active Training')"); $cc=(int)$db->lastInsertId();
$closeForm=['name'=>'Closure synthetic client','status'=>'Closed - Rehabilitated','client_type'=>'VR'];
[$code,$out,$err]=$renderPage('client_edit',['id'=>$cc],$closeForm);
check($code===0 && $err==='' && $db->query("SELECT status FROM clients WHERE id=$cc")->fetchColumn()==='Active Training' && str_contains($out,'Choose why the case is closing'),
    'A case does not close without a reason' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client_edit',['id'=>$cc],$closeForm+['closure_reason'=>'Employed','closure_employed'=>'yes','closure_employer'=>'Synthetic Employer','closure_job_title'=>'Clerk','closure_hours'=>'30','closure_wage'=>'17.25']);
$cr=client_last_closure($cc);
check($code===0 && $err==='' && $db->query("SELECT status FROM clients WHERE id=$cc")->fetchColumn()==='Closed - Rehabilitated'
    && $cr && $cr['reason']==='Employed' && (int)$cr['employed']===1 && $cr['employer']==='Synthetic Employer' && (float)$cr['hourly_wage']===17.25,
    'Closing a case records the reason and the employment outcome' . ($err ? ": $err" : ''));
require_once $root.'/public/inc/scheduled_reports.php';
$om=outcome_metrics(date('Y-m-d',strtotime('-1 day')),date('Y-m-d'));
check(in_array('Employed',array_column($om['closureReasons'],'reason'),true) && $om['employedAtClosure']>=1,'The outcome report counts closures by reason, and employment at closure');
$db->exec("INSERT INTO clients(name,status) VALUES('App closure synthetic client','Active Training')"); $ac=(int)$db->lastInsertId();
$call('save_client',['id'=>$ac,'name'=>'App closure synthetic client','status'=>'Closed - Other']);
check((client_last_closure($ac)['reason']??'')==='Not recorded','A closure from an app version that does not ask is recorded as Not recorded');
check(closure_checklist($cc)===[] || is_array(closure_checklist($cc)),'The closure checklist runs');
