<?php
// Included by full_schema.php after v4_cases.php, against its synthetic database only:
// 4.1.0's funding agencies in other states (details, invoices, authorization email domains,
// renaming) and client time zones (forms, referrals, emails, the portal, the schedule).

/* Funding agencies */
[$code,$out,$err]=$renderPage('funding_agencies',[],['save_agency'=>'1','agency_id'=>'0','name'=>'Synthetic Ohio Opportunities','state'=>'OH','time_zone'=>'America/New_York',
    'email_domains'=>'@ohio-example.test, Mail.Ohio-Example.TEST','vendor_number'=>'OH-55501','terms_days'=>'45','bill_to'=>"Accounts payable\n100 Synthetic Way, Columbus OH",'is_active'=>'1']);
$oa=$db->query("SELECT * FROM funding_agencies WHERE name='Synthetic Ohio Opportunities'")->fetch();
check($code===0 && $err==='' && $oa && $oa['state']==='OH' && $oa['email_domains']==='ohio-example.test, mail.ohio-example.test' && (int)$oa['terms_days']===45,'An administrator adds an agency with its state, domains, vendor number and terms' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('funding_agencies',[],['save_agency'=>'1','agency_id'=>'0','name'=>'Bad domains agency','email_domains'=>'not a domain','terms_days'=>'900']);
check($db->query("SELECT COUNT(*) FROM funding_agencies WHERE name='Bad domains agency'")->fetchColumn()==0,'A wrong domain or terms are refused, saving nothing');
agencies_all(true);
check(agency_canonical('  synthetic   OHIO opportunities ')==='Synthetic Ohio Opportunities' && agency_canonical('Brand new synthetic agency')==='Brand new synthetic agency'
    && agency_canonical('Private pay')==='Private pay','A typed agency takes the listed spelling; a new one is kept as typed');
$db->exec("INSERT INTO funding_agencies(name,email_domains,is_active) VALUES('Synthetic Former Agency','former-example.test',0)"); agencies_all(true);
check(agency_for_domain('former-example.test')===null,'An agency you no longer work with is not matched to emails');
check(agency_for_domain('ohio-example.test')==='Synthetic Ohio Opportunities' && agency_for_domain('counselors.mail.ohio-example.test')==='Synthetic Ohio Opportunities'
    && agency_for_domain('example-ohio.test')===null,'Authorization emails from an agency\'s domains are matched to it');
check(authorization_email_agency('pat@ohio-example.test')==='Synthetic Ohio Opportunities','The authorization email reader uses the agencies\' domains');
check(agency_vendor_number('Synthetic Ohio Opportunities')==='OH-55501' && agency_vendor_number('Brand new synthetic agency')===billing_settings()['vendor_number']
    && agency_terms_days('Synthetic Ohio Opportunities')===45 && agency_terms_days(null)===billing_settings()['terms_days'],'Vendor number and terms come from the agency, else Billing details');

/* Invoices to the agency */
$db->exec("INSERT INTO clients(name,status,assigned_to,address,state,time_zone) VALUES('Ohio Synthetic Client','Active Training',1,'1 Test Lane','OH','America/New_York')"); $ohc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Ohio track','Active','OH-AUTH-1','Synthetic Ohio Opportunities',20,60,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 60 DAY))")->execute([$ohc]); $oht=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by) VALUES(?,DATE_SUB(CURDATE(),INTERVAL 2 DAY),2,'Synthetic Ohio work',1)")->execute([$oht]);
$ohInv=generate_invoice($oht,1);
$ohRow=$db->query("SELECT * FROM invoices WHERE id=".(int)$ohInv)->fetch();
check($ohInv && str_starts_with((string)$ohRow['bill_to'],"Accounts payable\n100 Synthetic Way") && $ohRow['vendor_number']==='OH-55501','An invoice is addressed to the agency\'s billing address and keeps its vendor number');
check(invoice_submit((int)$ohInv,date('Y-m-d')) && $db->query("SELECT due_date FROM invoices WHERE id=".(int)$ohInv)->fetchColumn()===date('Y-m-d',strtotime('+45 days')),'Its due date follows the agency\'s own terms');

/* Renaming an agency */
$db->prepare("INSERT INTO invoices(track_id,invoice_number,hours,hours_amount,total_amount,status,funding_agency,created_by) VALUES(?,'SYN-DRAFT-OH',1,60,60,'Draft','Synthetic Ohio Opportunities',1)")->execute([$oht]); $ohDraft=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('funding_agencies',[],['save_agency'=>'1','agency_id'=>(string)$oa['id'],'name'=>'Synthetic Ohio OOD','state'=>'OH','time_zone'=>'America/New_York','email_domains'=>'ohio-example.test','vendor_number'=>'OH-55501','terms_days'=>'45','is_active'=>'1']);
check($db->query("SELECT funding_agency FROM training_tracks WHERE id=$oht")->fetchColumn()==='Synthetic Ohio OOD'
    && $db->query("SELECT funding_agency FROM invoices WHERE id=$ohDraft")->fetchColumn()==='Synthetic Ohio OOD'
    && $db->query("SELECT funding_agency FROM invoices WHERE id=".(int)$ohInv)->fetchColumn()==='Synthetic Ohio Opportunities','A renamed agency follows on its tracks and drafts; a sent invoice keeps the name it was sent with' . ($err ? ": $err" : ''));
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Typed track','Active','TY-1','Synthetic Typed Agency',5,50,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY))")->execute([$ohc]);
[$code,$out,$err]=$renderPage('funding_agencies',[]);
check($code===0 && $err==='' && str_contains($out,'On tracks but not set up') && str_contains($out,'Synthetic Typed Agency') && str_contains($out,'Vendor number OH-55501'),'The page lists each agency and the names on tracks not set up yet' . ($err ? ": $err" : ''));

/* Client time zones */
check(client_zone(['time_zone'=>'America/New_York'])==='America/New_York' && client_zone(['time_zone'=>date_default_timezone_get()])===null && client_zone(['time_zone'=>'Mars/Base'])===null,'Only a listed zone other than the office\'s changes how times are shown');
$ts=strtotime('2026-07-15 14:00:00');
$diffH=((new DateTimeZone('America/New_York'))->getOffset(new DateTime('@'.$ts))-(new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTime('@'.$ts)))/3600;
check(str_ends_with(zone_date('America/New_York','g:i A T',$ts),'EDT') && (int)zone_date('America/New_York','G',$ts)===((int)date('G',$ts)+(int)$diffH+24)%24 && date_default_timezone_get()!=='America/New_York','A time is shown in the client\'s zone, and the office zone is put back');
check(str_contains(zone_when_es('America/New_York',$ts),'EDT'),'Spanish times name the client\'s zone too');
[, $lines]=client_sessions_ics([['id'=>1,'start_at'=>'2026-07-15 14:00:00','end_at'=>'2026-07-15 15:00:00','location'=>'','is_remote'=>1,'meeting_url'=>'','client_time_zone'=>'America/New_York']],'x@example.invalid','REQUEST',false);
check(str_contains($lines[0],'EDT') && str_contains($lines[0],zone_date('America/New_York','g:i A',$ts)),'Booking and change emails give the client\'s own time');
$edit=['name'=>'Zone Edit Client','status'=>'Active Training','client_type'=>'VR','state'=>'AZ','time_zone'=>''];
[$code,,$err]=$renderPage('client_edit',[],$edit);
$zc=$db->query("SELECT state,time_zone FROM clients WHERE name='Zone Edit Client'")->fetch();
check($zc && $zc['state']==='AZ' && $zc['time_zone']===null && client_zone($zc)==='America/Phoenix','A client\'s state gives their usual time zone when none is chosen' . ($err ? ": $err" : ''));
$zid=(int)$db->query("SELECT id FROM clients WHERE name='Zone Edit Client'")->fetchColumn();
[$code,,$err]=$renderPage('client_edit',['id'=>$zid],['state'=>'CA','time_zone'=>'']+$edit);
check(client_zone($db->query("SELECT state,time_zone FROM clients WHERE id=$zid")->fetch())==='America/Los_Angeles','A client who moves state follows the new state\'s zone' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('client',['id'=>$ohc]);
check($code===0 && str_contains($out,'State and time zone') && str_contains($out,'Ohio; Eastern time'),'The client record says their state and how far their time is from the office\'s' . ($err ? ": $err" : ''));
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 3 DAY),DATE_ADD(NOW(),INTERVAL 3 DAY)+INTERVAL 1 HOUR,'Scheduled')")->execute([$ohc]);
[$code,$out,$err]=$renderPage('schedule',[]);
check($code===0 && $err==='' && str_contains($out,"Client's time"),'The schedule shows the client\'s own time for a client in another zone' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client',['id'=>$ohc],['send_portal'=>'1']);
$ohTok=(string)$db->query("SELECT token FROM portal_tokens WHERE client_id=$ohc")->fetchColumn();
if ($ohTok==='') { $ohTok=bin2hex(random_bytes(24)); $db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$ohc,$ohTok]); }
[$code,$out,$err]=$renderPage('portal',['t'=>$ohTok]);
check($code===0 && (str_contains($out,' EDT') || str_contains($out,' EST')),'The portal shows the client\'s sessions in their own time zone' . ($err ? ": $err" : ''));

/* Referrals from another state */
client_apply_state($ohc,'zz');
check($db->query("SELECT state FROM clients WHERE id=$ohc")->fetchColumn()==='OH','An unknown state changes nothing');
$db->exec("INSERT INTO clients(name,status) VALUES('Referral State Client','Pending Assessment')"); $rsc=(int)$db->lastInsertId();
client_apply_state($rsc,'wa');
check(client_zone($db->query("SELECT state,time_zone FROM clients WHERE id=$rsc")->fetch())==='America/Los_Angeles','A referral\'s state gives the new client that state and its usual zone');
