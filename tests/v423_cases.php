<?php
// Included by full_schema.php after v422_cases.php, against its synthetic database only:
// 4.23.0: referral deadlines per agency, the VR portal tracker, e-signature for client documents,
// and the accessibility statement.

// ---- Referral deadlines ----
[$code,,$err]=$renderPage('funding_agencies',[],['save_agency'=>'1','agency_id'=>'0','name'=>'Synthetic Deadline Agency','contact_days'=>'2','assess_days'=>'5','portal_name'=>'Synthetic Portal','portal_url'=>'https://portal.example.test/vendor','is_active'=>'1']);
agencies_all(true);
$da=$db->query("SELECT * FROM funding_agencies WHERE name='Synthetic Deadline Agency'")->fetch();
check($da && (int)$da['contact_days']===2 && (int)$da['assess_days']===5 && $da['portal_name']==='Synthetic Portal','An agency keeps its own referral deadlines and portal'.($err?": $err":''));
[$code,,$err]=$renderPage('funding_agencies',[],['save_agency'=>'1','agency_id'=>'0','name'=>'Synthetic Bad Deadlines','contact_days'=>'0','portal_url'=>'javascript:alert(1)','is_active'=>'1']);
check(!$db->query("SELECT id FROM funding_agencies WHERE name='Synthetic Bad Deadlines'")->fetchColumn(),'Deadlines must be whole business days and the portal an https address');
check(agency_contact_days('Synthetic Deadline Agency')===2 && agency_assess_days('synthetic deadline agency')===5 && agency_contact_days('Unknown agency')===3 && agency_assess_days(null)===10,'Deadlines follow the agency, else 3 and 10 business days');
check(referral_agency(['counselor_agency'=>'Someone else','counselor_email'=>'x@nowhere.test'])===null && referral_agency(['counselor_agency'=>'SYNTHETIC DEADLINE AGENCY'])==='Synthetic Deadline Agency','A referral\'s agency is found by its listed name');
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,,$err]=$renderPage('community_referral',[],['role'=>'counselor','referrer_name'=>'Synthetic Referrer','referrer_phone'=>'555-0100','referrer_email'=>'referrer@example.invalid','organization'=>'Synthetic Deadline Agency',
    'client_name'=>'Deadline Synthetic Referral','body'=>'Synthetic referral.','referral_purpose'=>'Additional service','requested_services'=>['Assistive technology training'],'specific_needs'=>['Screen readers']]);
putenv('CMS_TEST_ANON');
$dr=(int)$db->query("SELECT id FROM referrals WHERE client_name='Deadline Synthetic Referral'")->fetchColumn();
$task=$db->query("SELECT * FROM tasks WHERE auto_key='ref-contact-$dr'")->fetch();
check($dr>0 && $task && $task['due_date']===business_days_from(2) && str_contains((string)$task['details'],'2 business days'),'A new referral\'s contact task is due by its agency\'s deadline'.($err?": $err":''));
[$code,$html,$err]=$renderPage('referrals',['status'=>'open']);
check(str_contains($html,'Contact by '.fmt_date(business_days_from(2))),'The referral list says when each one must be contacted by'.($err?": $err":''));
$call('convert_referral',['id'=>$dr]);
$dc=(int)$db->query("SELECT client_id FROM referrals WHERE id=$dr")->fetchColumn();
check($db->query("SELECT due_date FROM tasks WHERE auto_key='assess-$dc'")->fetchColumn()===business_days_from(5),'The first assessment is due by the agency\'s deadline once the referral is accepted');

// ---- VR portal tracker ----
set_setting('vr_portal_since', date('Y-m-d', strtotime('-1 day')));
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,billing_mode,hourly_rate) VALUES(?,'Portal synthetic track','Active','PORTAL-AUTH-1','Synthetic Deadline Agency','vr',50)")->execute([$dc]); $pt=(int)$db->lastInsertId();
$db->prepare("INSERT INTO invoices(track_id,invoice_number,status,total_amount,bill_mode,funding_agency,auth_number,submitted_at,hours) VALUES(?,'PORTAL-INV-1','Submitted',150,'vr','Synthetic Deadline Agency','PORTAL-AUTH-1',CURDATE(),3)")->execute([$pt]); $pi=(int)$db->lastInsertId();
$db->prepare("INSERT INTO invoices(track_id,invoice_number,status,total_amount,bill_mode,funding_agency,submitted_at) VALUES(?,'PORTAL-OLD-1','Submitted',99,'vr','Synthetic Deadline Agency',?)")->execute([$pt,date('Y-m-d',strtotime('-30 days'))]);
$l=vr_portal_lists('Synthetic Deadline Agency');
check(in_array($pt,array_map(fn($r)=>(int)$r['id'],$l['accept']),true) && in_array($pi,array_map(fn($r)=>(int)$r['id'],$l['enter']),true) && !in_array('PORTAL-OLD-1',array_column($l['enter'],'invoice_number'),true),'New authorizations and invoices are listed to do in the portal; older ones are not');
[$code,$html,$err]=$renderPage('vr_portal',['agency'=>'Synthetic Deadline Agency']);
check(str_contains($html,'PORTAL-AUTH-1') && str_contains($html,'PORTAL-INV-1') && str_contains($html,'https://portal.example.test/vendor'),'The tracker page lists them, with a link to the agency\'s portal'.($err?": $err":''));
[$code,$csv,$err]=$renderPage('vr_portal',['agency'=>'Synthetic Deadline Agency','download'=>'1']);
check(str_contains($csv,'Authorization number') && str_contains($csv,'PORTAL-INV-1') && str_contains($csv,'PORTAL-AUTH-1'),'The invoices to enter download as a spreadsheet'.($err?": $err":''));
[$code,,$err]=$renderPage('vr_portal',[],['track_id'=>(string)$pt,'track_state'=>'returned','note'=>'']);
check($db->query("SELECT portal_status FROM training_tracks WHERE id=$pt")->fetchColumn()===null,'Returning an authorization needs what the agency asked for');
[$code,,$err]=$renderPage('vr_portal',[],['track_id'=>(string)$pt,'track_state'=>'returned','note'=>'Wrong rate']);
[$code,,$err2]=$renderPage('vr_portal',[],['track_id'=>(string)$pt,'track_state'=>'accepted']);
$tr=$db->query("SELECT portal_status,portal_at FROM training_tracks WHERE id=$pt")->fetch();
check($tr['portal_status']==='accepted' && $tr['portal_at']===date('Y-m-d'),'An authorization is marked returned, then accepted'.($err.$err2?": $err $err2":''));
[$code,,$err]=$renderPage('vr_portal',[],['invoice_id'=>(string)$pi,'invoice_state'=>'entered','portal_ref'=>'PR-555']);
$iv=$db->query("SELECT portal_status,portal_ref FROM invoices WHERE id=$pi")->fetch();
check($iv['portal_status']==='entered' && $iv['portal_ref']==='PR-555' && in_array($pi,array_map(fn($r)=>(int)$r['id'],vr_portal_lists()['waiting']),true),'An invoice entered as a payment request waits to be paid'.($err?": $err":''));
[$code,,$err]=$renderPage('vr_portal',[],['invoice_id'=>(string)$pi,'invoice_state'=>'rejected','note'=>'Missing case notes']);
[$code,,$err2]=$renderPage('vr_portal',[],['invoice_id'=>(string)$pi,'invoice_state'=>'entered','portal_ref'=>'']);
$iv=$db->query("SELECT portal_status,portal_ref,portal_note FROM invoices WHERE id=$pi")->fetch();
check($iv['portal_status']==='entered' && $iv['portal_ref']==='PR-555' && $iv['portal_note']===null,'A rejected request is entered again, keeping its number'.($err.$err2?": $err $err2":''));
// A caseload-limited account sees and changes only its own clients'.
$otherUser=(int)$db->query("SELECT id FROM users WHERE id<>1 ORDER BY id LIMIT 1")->fetchColumn();
$assignedTo=$db->query("SELECT assigned_to FROM clients WHERE id=$dc")->fetchColumn();
if ($otherUser && (int)$assignedTo!==$otherUser) {
    check(!in_array($pi,array_map(fn($r)=>(int)$r['id'],vr_portal_lists(null,$otherUser)['waiting']),true) && !vr_portal_set_invoice($pi,'rejected','','Not mine',$otherUser,$otherUser) && !vr_portal_set_track($pt,'returned','Not mine',$otherUser,$otherUser),'A caseload-limited account neither sees nor changes another caseload\'s portal work');
}
// A new authorization number is accepted again.
$db->exec("UPDATE training_tracks SET auth_number='PORTAL-AUTH-2' WHERE id=$pt");
$acc=array_values(array_filter(vr_portal_lists()['accept'],fn($r)=>(int)$r['id']===$pt));
check($acc && $acc[0]['portal_auth']==='PORTAL-AUTH-1','A renewed or amended authorization number is listed to accept again');
vr_portal_set_track($pt,'accepted','',1);
check(!in_array($pt,array_map(fn($r)=>(int)$r['id'],vr_portal_lists()['accept']),true),'Accepting it records the new number');
// The download never carries a spreadsheet formula.
$db->exec("INSERT INTO clients(name,status,assigned_to,state) VALUES('=HYPERLINK(\"http://x.invalid\")','Active Training',1,'IA')"); $fc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,billing_mode) VALUES(?,'Formula track','Active','PORTAL-AUTH-F','Synthetic Deadline Agency','vr')")->execute([$fc]); $ft=(int)$db->lastInsertId();
$db->prepare("INSERT INTO invoices(track_id,invoice_number,status,total_amount,bill_mode,funding_agency,submitted_at) VALUES(?,'PORTAL-INV-F','Submitted',10,'vr','Synthetic Deadline Agency',CURDATE())")->execute([$ft]);
[$code,$csv,$err]=$renderPage('vr_portal',['download'=>'1']);
check(str_contains($csv,"'=HYPERLINK") && !preg_match('/(^|,)"?=HYPERLINK/m',$csv),'A formula typed as a name is downloaded as text'.($err?": $err":''));
$db->exec("DELETE FROM clients WHERE id=$fc");
$db->exec("UPDATE invoices SET status='Paid' WHERE id=$pi");
check(!in_array($pi,array_map(fn($r)=>(int)$r['id'],vr_portal_lists()['waiting']),true),'A paid invoice leaves the tracker');

// ---- E-signature ----
$db->prepare("UPDATE clients SET email='signer@example.invalid' WHERE id=?")->execute([$dc]);
[$code,$html,$err]=$renderPage('signatures',['client'=>(string)$dc]);
check(str_contains($html,'Send a document to sign') && str_contains($html,'signer@example.invalid'),'The documents-to-sign page offers to send one, to the client\'s email'.($err?": $err":''));
[$code,,$err]=$renderPage('signatures',['client'=>(string)$dc],['send_signature'=>'1','kind'=>'services','signer_role'=>'Client','signer_name'=>'Deadline Synthetic Referral','signer_email'=>'signer@example.invalid']);
$sg=$db->query("SELECT * FROM client_signatures WHERE client_id=$dc ORDER BY id DESC LIMIT 1")->fetch();
check($sg && $sg['kind']==='services' && str_contains($sg['body'],'You can stop services at any time') && strlen($sg['token'])===48,'Consent to services is sent with its words kept'.($err?": $err":''));
[$code,,$err]=$renderPage('signatures',['client'=>(string)$dc],['send_signature'=>'1','kind'=>'loan','signer_role'=>'Client','signer_name'=>'X','signer_email'=>'signer@example.invalid']);
check((int)$db->query("SELECT COUNT(*) FROM client_signatures WHERE client_id=$dc")->fetchColumn()===1,'A loan agreement needs a device on loan');
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('sign',['t'=>$sg['token']]);
check(str_contains($html,'Consent to services') && str_contains($html,'Electronic signature'),'The signer reads the document'.($err?": $err":''));
[$code,,$err]=$renderPage('sign',['t'=>$sg['token']],['signature'=>'Someone Else']);
check($db->query("SELECT signed_at FROM client_signatures WHERE id=".(int)$sg['id'])->fetchColumn()===null,'A name that does not match signs nothing');
[$code,,$err]=$renderPage('sign',['t'=>$sg['token']],['signature'=>'deadline synthetic referral']);
[$code,$html,$err2]=$renderPage('sign',['t'=>$sg['token']]);
putenv('CMS_TEST_ANON');
$sg2=$db->query("SELECT * FROM client_signatures WHERE id=".(int)$sg['id'])->fetch();
check($sg2['signed_at']!==null && $sg2['signed_name']==='deadline synthetic referral' && str_contains($html,'Document signed'),'Typing the name signs it, and the page becomes a receipt'.($err.$err2?": $err $err2":''));
check((bool)$db->query("SELECT 1 FROM client_notes WHERE client_id=$dc AND subject='Signed: Consent to services'")->fetchColumn(),'A signed document is noted on the client\'s record');
// A loan agreement, signed, records the borrower's acceptance on the loan.
$db->exec("INSERT INTO equipment(name,category,status,serial) VALUES('Synthetic braille display','Braille','On Loan','SN-423')"); $se=(int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_loans(equipment_id,client_id,loaned_at,due_date) VALUES(?,?,CURDATE(),?)")->execute([$se,$dc,date('Y-m-d',strtotime('+30 days'))]); $sl=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('signatures',['client'=>(string)$dc],['send_signature'=>'1','kind'=>'loan','loan_id'=>(string)$sl,'signer_role'=>'Client','signer_name'=>'Deadline Synthetic Referral','signer_email'=>'signer@example.invalid']);
$lg=$db->query("SELECT * FROM client_signatures WHERE client_id=$dc AND kind='loan'")->fetch();
check($lg && str_contains($lg['title'],'Synthetic braille display (serial number SN-423)') && str_contains($lg['body'],'I will return it'),'A loan agreement names the device and keeps its terms'.($err?": $err":''));
signature_sign($lg,'Deadline Synthetic Referral');
check($db->query("SELECT accepted_name FROM equipment_loans WHERE id=$sl")->fetchColumn()==='Deadline Synthetic Referral','Signing the loan agreement records the borrower\'s acceptance on the loan');
[$code,,$err]=$renderPage('signatures',['client'=>(string)$dc],['withdraw'=>(string)$lg['id']]);
// 4.27.0: a signed document is never withdrawn.
check($db->query("SELECT withdrawn_at FROM client_signatures WHERE id=".(int)$lg['id'])->fetchColumn()===null,'A signed document cannot be withdrawn'.($err?": $err":''));
$db->prepare("INSERT INTO client_signatures(client_id,kind,title,body,signer_role,signer_name,signer_email,token,sent_at) VALUES(?,'other','Synthetic unsigned document','Synthetic text.','Client','Deadline Synthetic Referral','signer@example.invalid',?,NOW())")->execute([$dc,$wtok=bin2hex(random_bytes(24))]);
[$code,,$err]=$renderPage('signatures',['client'=>(string)$dc],['withdraw'=>(string)$db->lastInsertId()]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err2]=$renderPage('sign',['t'=>$wtok]);
putenv('CMS_TEST_ANON');
check(str_contains($html,'Document withdrawn'),'A withdrawn document says so on its link'.($err.$err2?": $err $err2":''));
check(signature_status(['withdrawn_at'=>null,'signed_at'=>null,'sent_at'=>date('Y-m-d H:i:s',strtotime('-400 days'))])==='Link expired','An old unsigned link expires');

// A Spanish-speaking client gets consent to services in Spanish, marked as Spanish.
$db->prepare("UPDATE clients SET preferred_language='es' WHERE id=?")->execute([$dc]);
[$code,,$err]=$renderPage('signatures',['client'=>(string)$dc],['send_signature'=>'1','kind'=>'services','signer_role'=>'Client','signer_name'=>'Deadline Synthetic Referral','signer_email'=>'signer@example.invalid']);
$es=$db->query("SELECT * FROM client_signatures WHERE client_id=$dc ORDER BY id DESC LIMIT 1")->fetch();
check($es['title']==='Consentimiento para servicios' && $es['lang']==='es' && str_contains($es['body'],'Puede dejar los servicios'),'Consent to services is sent in Spanish to a Spanish-speaking client'.($err?": $err":''));
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('sign',['t'=>$es['token'],'lang'=>'en']);
putenv('CMS_TEST_ANON');
check(str_contains($html,'<div lang="es">') && str_contains($html,'<span lang="es">Consentimiento para servicios</span>'),'A Spanish document on an English page is marked as Spanish'.($err?": $err":''));
$db->prepare("UPDATE clients SET preferred_language='en' WHERE id=?")->execute([$dc]);

// ---- Accessibility statement ----
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('accessibility',[]);
putenv('CMS_TEST_ANON');
check(str_contains($html,'WCAG') && str_contains($html,'ACCESSIBILITY-CONFORMANCE-REPORT.md'),'The accessibility statement is public and links the full report'.($err?": $err":''));
check(is_file($root.'/ACCESSIBILITY-CONFORMANCE-REPORT.md') && str_contains((string)file_get_contents($root.'/ACCESSIBILITY-CONFORMANCE-REPORT.md'),'not a third-party audit'),'The conformance report is published, and says how it was evaluated');
