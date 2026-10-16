<?php
// Included by full_schema.php after outcome_followup_cases.php, against its synthetic
// database only: authorization emails read into new tracks (3.0.48). Names are made up.
$sample = "From: AGENCY NOTIFY <AGENCY.NOTIFY@blind.state.ia.us>\nSent: Tuesday, October 13, 2026 10:26 AM\nTo: Pat Counselor <Pat.Counselor@blind.state.ia.us>\n"
    . "Cc: Sam Supervisor <Sam.Supervisor@blind.state.ia.us>\nSubject: Authorization AUTH-2026-09001 has been Approved\n\nDear Counselor, Pat,\n"
    . "This email notification is to notify you that Authorization AUTH-2026-09001 has been approved.\n\nAuthorization Details:\n"
    . "Authorization Number:  AUTH-2026-09001\nClient Name:  Testerly, Zebulon K\nService Category:   Rehabilitation Technology\nAuthorization Amount:  800.00\n\nThank you!";
$p = authorization_email_parse($sample);
check($p && $p['auth_number']==='AUTH-2026-09001' && $p['client_name']==='Zebulon K Testerly' && $p['service_category']==='Rehabilitation Technology'
    && $p['amount']===800.0 && $p['approved_on']==='2026-10-13' && $p['counselor_email']==='pat.counselor@blind.state.ia.us' && $p['counselor_name']==='Pat Counselor'
    && $p['agency']==='Iowa Department for the Blind' && $p['not_approved']===false,'An approved authorization email is read: number, client, service, amount, date, counselor and agency');
$fwd = "From: Pat Counselor <Pat.Counselor@blind.state.ia.us>\nSent: Wednesday, October 14, 2026 9:00 AM\nTo: Owner <owner@provider.example>\nSubject: FW: Authorization AUTH-2026-09001 has been Approved\n\n" . $sample;
$p2 = authorization_email_parse($fwd);
check($p2 && $p2['counselor_email']==='pat.counselor@blind.state.ia.us' && $p2['approved_on']==='2026-10-13','A forwarded notice is read from the original message, not the forward');
check(authorization_email_parse("Hello, can we meet on Tuesday?")===null && authorization_email_parse("Authorization Number: pending")===null,'Text that is not an authorization is not read as one');
check(authorization_email_parse(str_replace('has been approved','has been denied',str_replace('has been Approved','has been Denied',$sample)))['not_approved']===true,'A denied authorization is flagged');

$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Zebulon Testerly','Active Training',1)"); $zc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date) VALUES(?,'Earlier track','Completed','OLD-1','Iowa Department for the Blind',10,50,'2026-01-01')")->execute([$zc]);
check(array_column(authorization_email_clients('Zebulon K Testerly'),'id')===[$zc],'The client is matched by first and last name, ignoring the middle initial');

/* Paste, review, start the track */
[$code,,$err]=$renderPage('authorizations',[],['read_email'=>'1','email_text'=>'nothing useful here']);
check($code===0 && (int)$db->query("SELECT COUNT(*) FROM incoming_authorizations")->fetchColumn()===0,'Text with no authorization number is refused' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('authorizations',[],['read_email'=>'1','email_text'=>$sample]);
$ia=$db->query("SELECT * FROM incoming_authorizations WHERE auth_number='AUTH-2026-09001'")->fetch();
check($code===0 && $err==='' && $ia && (int)$ia['client_id']===$zc && $ia['status']==='New' && $ia['source']==='Pasted','A pasted email is kept and matched to its client' . ($err ? ": $err" : ''));
$renderPage('authorizations',[],['read_email'=>'1','email_text'=>$sample]);
check((int)$db->query("SELECT COUNT(*) FROM incoming_authorizations WHERE auth_number='AUTH-2026-09001'")->fetchColumn()===1,'Pasting the same email again reuses it');
[$code,$out,$err]=$renderPage('authorizations',['id'=>(string)$ia['id']]);
check($code===0 && $err==='' && str_contains($out,'Start the track from this authorization') && str_contains($out,'Zebulon Testerly'),'The review shows what was read and the matched client' . ($err ? ": $err" : ''));
$pre=authorization_track_prefill(incoming_authorization((int)$ia['id']),$zc);
check($pre['auth_number']==='AUTH-2026-09001' && $pre['authorized_amount']==='800.00' && $pre['authorized_hours']==='16' && $pre['hourly_rate']==='50.00'
    && $pre['rsa_category']==='Rehabilitation Technology' && $pre['start_date']==='2026-10-13' && $pre['funding_agency']==='Iowa Department for the Blind','The track form is filled in, with hours worked out from the amount at the last rate');
[$code,,$err]=$renderPage('authorizations',[],['start_track'=>'1','id'=>(string)$ia['id'],'client_id'=>(string)$zc,'set_counselor'=>'1']);
check($code===0 && $db->query("SELECT counselor_email FROM clients WHERE id=$zc")->fetchColumn()==='pat.counselor@blind.state.ia.us','Starting the track can record the counselor from the email' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('track_edit',['client_id'=>(string)$zc],['title'=>'Rehabilitation Technology','billing_mode'=>'vr','auth_number'=>'AUTH-2026-09001','funding_agency'=>'Iowa Department for the Blind',
    'authorized_hours'=>'16','hourly_rate'=>'50','authorized_amount'=>'800.00','start_date'=>'2026-10-13','status'=>'Active','incoming_auth'=>(string)$ia['id']]);
$ia2=incoming_authorization((int)$ia['id']);
check($code===0 && $err==='' && $ia2['status']==='Track created' && (int)$ia2['track_id']>0
    && (int)$db->query("SELECT COUNT(*) FROM training_tracks WHERE auth_number='AUTH-2026-09001' AND authorized_amount=800")->fetchColumn()===1,'Saving the track marks the email handled' . ($err ? ": $err" : ''));
$id3=authorization_email_store(authorization_email_parse(str_replace('09001','09002',$sample)),'x','Pasted',null,1);
[$code,$out,$err]=$renderPage('authorizations',['id'=>(string)$id3]);
check($code===0 && !str_contains($out,'Already on a track'),'A new number is not reported as already on a track');
$id4=authorization_email_store($p,'x','Mailbox','k'.str_repeat('0',63),null);
[$code,$out,$err]=$renderPage('authorizations',['id'=>(string)$id4]);
check($code===0 && str_contains($out,'Already on a track'),'A number already on a track says so' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('authorizations',[],['dismiss'=>'1','id'=>(string)$id3]);
check(incoming_authorization($id3)['status']==='Dismissed','An email can be dismissed' . ($err ? ": $err" : ''));

/* Review fixes */
$quoted = "Thanks for the report.\n\n> Client: Zebulon Testerly\n> Authorization #: AUTH-2026-09001 — Iowa Department for the Blind\n";
$pq = authorization_email_parse($quoted);
check($pq && $pq['auth_number']==='AUTH-2026-09001' && $pq['approved']===false,'A reply quoting a report reads only the number, and is not an approval');
$multi = str_replace("To: Pat Counselor <Pat.Counselor@blind.state.ia.us>","To: Synthetic Admin <".$db->query("SELECT email FROM users WHERE id=1")->fetchColumn().">; Counselor, Pat <Pat.Counselor@blind.state.ia.us>",$sample);
$pm = authorization_email_parse($multi);
check($pm['counselor_email']==='pat.counselor@blind.state.ia.us' && $pm['counselor_name']==='Pat Counselor','With several addressees, the counselor is the first who is not staff, and "Last, First" is turned round');
check(authorization_email_parse(str_replace('Tuesday, October 13, 2026','February 30, 2026',$sample))['approved_on']===null,'An impossible date is not rolled over');
check(authorization_email_parse("Total Units: 10\n".$sample)['amount']===800.0,'The authorization amount wins over another total');
$db->exec("INSERT INTO clients(name,status,assigned_to,counselor_email) VALUES('Zebulon Testerly','Active Training',1,'someone.else@example.test')"); $zc2=(int)$db->lastInsertId();
$id5=authorization_email_store(authorization_email_parse(str_replace('09001','09005',$sample)),'x','Pasted',null,1);
check(incoming_authorization($id5)['client_id']===null,'Two clients with the name leave the email unmatched');
[$code,$out,$err]=$renderPage('authorizations',['id'=>(string)$id5]);
check($code===0 && $err==='' && !preg_match('/name="client_id" value="\d+" checked/',$out) && str_contains($out,'value="other" checked'),'With two matches, no client is chosen for you' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('authorizations',[],['start_track'=>'1','id'=>(string)$id5,'client_id'=>'other','other_client'=>(string)$zc2,'set_counselor'=>'1']);
check($code===0 && (int)incoming_authorization($id5)['client_id']===$zc2 && $db->query("SELECT counselor_email FROM clients WHERE id=$zc2")->fetchColumn()==='someone.else@example.test','Another client can be chosen, and an existing different counselor is not replaced' . ($err ? ": $err" : ''));
check(incoming_authorization_handled($id5,'Track created',null,1,$zc)===false && incoming_authorization($id5)['status']==='New','An email is marked handled only by a track for its own client');
