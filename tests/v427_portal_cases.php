<?php
// Included by full_schema.php after v426_cases.php, against its synthetic database only:
// 4.27.0, the portal area: "Email me a new link" keeps a working link, a portal phone change
// clears texting consent, an empty device check-in is refused, the referral confirmation needs
// the session that sent it, and session reminders are recorded per recipient.

// ---- "Email me a new link" while the link still works: the same link, nothing replaced ----
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('Newlink Synthetic Client','Active Training','newlink-client@example.invalid',1)"); $nlc=(int)$db->lastInsertId();
$nlt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$nlc,$nlt]);
$nlAsk=function() use ($db,$renderPage): void {
    $db->exec("DELETE FROM login_attempts WHERE email='portal-newlink'"); // the per-address limit is not what is tested here
    putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
    $renderPage('portal',[],['new_link'=>'1','new_link_email'=>'NewLink-Client@example.invalid']);
    putenv('CMS_TEST_ANON');
};
$nlToken=fn()=>(string)$db->query("SELECT token FROM portal_tokens WHERE client_id=$nlc")->fetchColumn();
$nlAsk();
check($nlToken()===$nlt && (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='portal-newlink-c$nlc'")->fetchColumn()===1,
    'Asking for a new link while the link still works sends the same link and does not replace it');
$nlAsk();
$db->exec("UPDATE portal_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE client_id=$nlc");
$nlAsk();
check($nlToken()===$nlt,'A client is sent at most two links a day from the expired-link page');
$db->exec("DELETE FROM login_attempts WHERE email='portal-newlink-c$nlc'");
$nlAsk();
check($nlToken()!==$nlt,'A lapsed link is replaced with a new one');
check(portal_rate_ip('2001:db8:1:2:aaaa::1')===portal_rate_ip('2001:db8:1:2:bbbb:cccc:dddd:2') && portal_rate_ip('2001:db8:1:3::1')!==portal_rate_ip('2001:db8:1:2::1')
    && portal_rate_ip('192.0.2.7')==='192.0.2.7','The per-address limit counts an IPv6 /64 as one address');

// ---- A phone number changed on the portal clears the agreement to texts ----
$db->exec("INSERT INTO clients(name,status,email,email_verified,phone,sms_consent_at,sms_consent_source,sms_consent_by) VALUES('Portal Phone Synthetic','Active Training','portal-phone@example.invalid',1,'515-555-0199',NOW(),'In person',1)"); $ppc=(int)$db->lastInsertId();
$ppt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$ppc,$ppt]);
$shownNow=array_map(fn($v)=>hash('sha256',$v),['phone'=>'515-555-0199','address'=>'','a11y_screen_reader'=>'0','a11y_format'=>'','a11y_primary_tech'=>'','a11y_contact'=>'','a11y_notes'=>'']);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,,$err]=$renderPage('portal',['t'=>$ppt],['update_profile'=>'1','p_phone'=>'515-555-0123','p_address'=>'','p_format'=>'','p_tech'=>'','p_contact'=>'','p_notes'=>'','p_shown_json'=>json_encode($shownNow)]);
putenv('CMS_TEST_ANON');
$pp=$db->query("SELECT phone, sms_consent_at, sms_consent_source, sms_consent_by FROM clients WHERE id=$ppc")->fetch();
$ppNote=(string)$db->query("SELECT body FROM client_notes WHERE client_id=$ppc ORDER BY id DESC LIMIT 1")->fetchColumn();
check($pp['phone']==='515-555-0123' && $pp['sms_consent_at']===null && $pp['sms_consent_source']===null && $pp['sms_consent_by']===null && str_contains($ppNote,'Texts are off'),
    'A phone number changed on the portal clears the agreement to texts, and the case note says so'.($err?": $err":''));

// ---- An empty device check-in is refused ----
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('Checkin Synthetic Client','Active Training','checkin-client@example.invalid',1)"); $kc=(int)$db->lastInsertId();
$db->exec("INSERT INTO equipment(name,status) VALUES('Synthetic check-in reader','On Loan')"); $keq=(int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_loans(equipment_id,client_id,loaned_at) VALUES(?,?,DATE_SUB(CURDATE(),INTERVAL 40 DAY))")->execute([$keq,$kc]); $kloan=(int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_followups(loan_id,client_id,equipment_id,interval_days,due_date) VALUES(?,?,?,30,CURDATE())")->execute([$kloan,$kc,$keq]); $kfu=(int)$db->lastInsertId();
$kt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$kc,$kt]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('portal',['t'=>$kt]);
[$code2,,$err2]=$renderPage('portal',['t'=>$kt],['device_checkin'=>'1','followup_id'=>(string)$kfu,'satisfaction'=>'4','barriers'=>'']);
putenv('CMS_TEST_ANON');
check(str_contains($html,'role="radiogroup" aria-required="true"') && $db->query("SELECT status FROM equipment_followups WHERE id=$kfu")->fetchColumn()==='Pending',
    'A device check-in without the in-use answer is refused, and the question is marked required'.($err.$err2?": $err $err2":''));

// ---- The referral confirmation belongs to the session that sent it ----
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('community_referral',['submitted'=>'1','fee'=>'free']);
putenv('CMS_TEST_SESSION='.json_encode(['referral_sent'=>['ref'=>4242,'by'=>date('Y-m-d',strtotime('+3 days')),'done'=>'&fee=free']]));
[$code2,$html2,$err2]=$renderPage('community_referral',['submitted'=>'1','fee'=>'paid']);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
check(!str_contains($html,'Thank you — the referral has been received.') && !str_contains($html,'at no cost') && str_contains($html,'name="referrer_name"'),
    'A ?submitted=1&fee=… address with no referral sent in this session shows the form'.($err?": $err":''));
check(str_contains($html2,'Thank you — the referral has been received.') && str_contains($html2,'4242') && str_contains($html2,'at no cost'),
    'The confirmation and its cost come from the session, not the address'.($err2?": $err2":''));

// ---- Session reminders are recorded per recipient ----
$db->exec("INSERT INTO users(name,email,password_hash,role,is_active) VALUES('Reminder Bad Staff','not-an-address','test','instructor',1)"); $badStaff=(int)$db->lastInsertId();
$db->exec("INSERT INTO users(name,email,password_hash,role,is_active) VALUES('Reminder Good Staff','rem427-staff@example.invalid','test','instructor',1)"); $goodStaff=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('Reminder 427 Client','Active Training','rem427-client@example.invalid',1)"); $rc=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('Reminder 427 Bad Address','Active Training','not an address',1)"); $rb=(int)$db->lastInsertId();
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 20 HOUR),DATE_ADD(NOW(),INTERVAL 21 HOUR),'Scheduled')")->execute([$rc,$badStaff]); $rs1=(int)$db->lastInsertId();
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 20 HOUR),DATE_ADD(NOW(),INTERVAL 21 HOUR),'Scheduled')")->execute([$rb,$goodStaff]); $rs2=(int)$db->lastInsertId();
$alerts=fn()=>(int)$db->query("SELECT COUNT(*) FROM notifications WHERE message LIKE '%Reminder 427 Bad Address%'")->fetchColumn();
[,$mail1]=$cronRun('session_reminders.php',true);
$alertsAfterFirst=$alerts();
[,$mail2]=$cronRun('session_reminders.php',true);
check(str_contains($mail1,'To: rem427-client@example.invalid') && !str_contains($mail2,'To: rem427-client@example.invalid')
    && (int)$db->query("SELECT reminder_sent FROM scheduled_sessions WHERE id=$rs1")->fetchColumn()===0,
    'A reminder that fails for the instructor is retried for the instructor only: the client is not emailed again');
check(str_contains($mail1,'To: rem427-staff@example.invalid') && !str_contains($mail2,'To: rem427-staff@example.invalid') && $alertsAfterFirst>0 && $alerts()===$alertsAfterFirst
    && (int)$db->query("SELECT reminder_sent FROM scheduled_sessions WHERE id=$rs2")->fetchColumn()===1,
    'A client address that cannot be emailed is reported once: the instructor is not emailed again and the administrators are not alerted again');
$db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE id IN ($rs1,$rs2)");
