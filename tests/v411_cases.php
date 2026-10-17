<?php
// Included by full_schema.php after v410_cases.php, against its synthetic database only:
// 4.11.0's lists for client statuses, progress report schedules and referral sources.
check(client_statuses()===CLIENT_STATUSES && client_open_statuses()===['Pending Assessment','Waitlist','Active Training','On Hold'],'Built in, the six client statuses, four of them open');
[$v,$e]=cms_list_parse('client_statuses',"Pending Assessment\nWaitlist\nActive Training\nOn Hold\nClosed - Rehabilitated");
check($v===null && str_contains((string)$e,'Closed - Other'),'A built-in status cannot be removed');
[$v,$e]=cms_list_parse('client_statuses',implode("\n",CLIENT_STATUSES)."\nIn Follow-up\nClosed - Moved away");
check($e===null,'Statuses can be added');
set_setting('list_client_statuses',json_encode($v));
check(client_open_statuses()===['Pending Assessment','Waitlist','Active Training','On Hold','In Follow-up'],'An added status counts as open unless it starts with Closed');
[$v,$e]=cms_list_parse('report_cadences',"none | 0\nweekly | 3\nbiweekly | 14\nmonthly | 28\nQuarterly | 91");
check($e===null && $v['weekly']==='7' && $v['Quarterly']==='91','A report schedule can be added, and the built-in ones keep their days');
check(cms_list_parse('report_cadences',"none | 0\nweekly | 7\nbiweekly | 14\nmonthly | 28\nDaily | 1")[0]===null,'A schedule under 7 days is refused');
set_setting('list_report_cadences',json_encode($v));
check(report_due_date('Quarterly','2026-01-01')==='2026-04-02' && report_due_date('weekly','2026-01-01')==='2026-01-08' && isset(report_cadence_choices()['Quarterly']),'An added schedule sets the due date, and is offered');
check(cms_list_parse('referral_sources',"Church\nself")[0]===null && cms_list_parse('referral_sources',"Employer / other")[0]===null,'A referral source cannot reuse a built-in one');
[$v,$e]=cms_list_parse('referral_sources',"Faith community");
check($e===null,'A referral source can be added'); set_setting('list_referral_sources',json_encode($v));
// Through the pages.
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Status Synthetic Client','Waitlist',1)"); $sc=(int)$db->lastInsertId();
[$code,$html,$err]=$renderPage('client_edit',['id'=>$sc]);
check(str_contains($html,'>In Follow-up<'),'The client form offers the added status' . ($err ? ": $err" : ''));
$call('save_client',['id'=>$sc,'name'=>'Status Synthetic Client','status'=>'In Follow-up']);
check($db->query("SELECT status FROM clients WHERE id=$sc")->fetchColumn()==='In Follow-up','The app can set an added status');
[$code,$html,$err]=$renderPage('pipeline',[]);
check(str_contains($html,'In Follow-up (') && str_contains($html,'Status Synthetic Client'),'The pipeline shows the added open status' . ($err ? ": $err" : ''));
$opts=$call('form_options');
check(in_array('In Follow-up',$opts['lists']['client_statuses'],true) && $opts['lists']['referral_sources']===['Faith community'],'The apps get the statuses and referral sources');
check(in_array(['value'=>'Quarterly','label'=>'Quarterly (every 91 days)'],report_cadence_options(),true) && array_column(report_cadence_options(),'value')[count(report_cadence_options())-1]==='none','The apps\' track form offers the added schedule, with Off last');
// The public referral form.
putenv('CMS_TEST_ANON=1');
[$code,$html,$err]=$renderPage('community_referral',[]);
check(str_contains($html,'value="Faith community"') && str_contains($html,'data-acts-as="other"'),'The referral form offers the added source' . ($err ? ": $err" : ''));
putenv('CMS_TEST_ANON');
set_setting('list_client_statuses',''); set_setting('list_report_cadences',''); set_setting('list_referral_sources','');
/* Review follow-ups: case, closed names, schedules in use, the public form */
check(cms_list_parse('client_statuses',implode("\n",CLIENT_STATUSES)."\nwaitlist")[0]===null && cms_list_parse('client_statuses',implode("\n",CLIENT_STATUSES)."\nclosed - moved")[0]===null
    && cms_list_parse('client_statuses',implode("\n",CLIENT_STATUSES)."\nClosed for audit")[0]===null,'A status differing only in case, or a closed one not written "Closed - ", is refused');
check(cms_list_parse('report_cadences',"none | 0\nweekly | 7\nbiweekly | 14\nmonthly | 28\nNone | 30")[0]===null,'A schedule named like a built-in one in another case is refused');
set_setting('list_report_cadences',json_encode(['none'=>'0','weekly'=>'7','biweekly'=>'14','monthly'=>'28','Quarterly'=>'91']));
$db->exec("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,report_cadence) VALUES($sc,'Quarterly track','Active','Q-1','Synthetic VR','Quarterly')");
[$v,$e]=cms_list_parse('report_cadences',"none | 0\nweekly | 7\nbiweekly | 14\nmonthly | 28");
check($v===null && str_contains((string)$e,'Quarterly'),'A schedule an active track uses cannot be removed');
$db->exec("DELETE FROM training_tracks WHERE title='Quarterly track'");
set_setting('list_client_statuses',json_encode(array_merge(CLIENT_STATUSES,['In Follow-up'])));
[$code,$html,$err]=$renderPage('schedule',[]);
check(str_contains($html,'Status Synthetic Client'),'A client in an added open status can be scheduled');
set_setting('list_client_statuses','');
[$code,$html,$err]=$renderPage('pipeline',[]);
check(str_contains($html,'In Follow-up (1)'),'A client whose status was removed from the list still shows on the pipeline');
// A referral from an added source is saved under its name, asked what "other" is asked.
$savedForm=setting("referral_form"); set_setting("referral_form",""); set_setting("list_referral_sources",json_encode(["Faith community"]));
putenv('CMS_TEST_ANON=1');
[$code,$rout,$err]=$renderPage('community_referral',[],['role'=>'Faith community','referrer_name'=>'Synthetic Pastor','referrer_phone'=>'555-0100','referrer_email'=>'pastor@example.invalid','organization'=>'Synthetic congregation',
    'client_name'=>'Synthetic Referred Person','body'=>'Needs help with a screen reader.','consent'=>'1','grade_level'=>'9','requested_services'=>['Assistive technology training'],'specific_needs'=>['Screen readers']]);
$ref=$db->query("SELECT referral_source, grade_level FROM referrals WHERE client_name='Synthetic Referred Person'")->fetch();
check($ref && $ref['referral_source']==='Faith community' && $ref['grade_level']===null,'A referral from an added source is saved under its own name, without another role\'s answers' . ($err ? ": $err" : ''));
[$code,$html,$err]=$renderPage('community_referral',[],['role'=>['x']]);
check(!str_contains($err,'TypeError') && !str_contains($err,'Fatal'),'A crafted role does not break the form');
putenv('CMS_TEST_ANON');
set_setting('referral_form',(string)$savedForm);
set_setting('list_referral_sources','');
