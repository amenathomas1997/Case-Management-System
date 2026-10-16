<?php
// Included by full_schema.php after devices_cases.php, against its synthetic database only:
// portal links that renew and warn, message and request notices, and the contact
// preference in session reminders (3.0.40).
$db->exec("INSERT INTO clients(name,status,email,email_verified,counselor_name,counselor_email,counselor_verified) VALUES('Notice synthetic client','Active Training','notice-client@example.invalid',1,'Notice counselor','notice-counselor@example.invalid',1)"); $nc=(int)$db->lastInsertId();

/* Sending and renewing a portal link */
[$code,,$err]=$renderPage('client',['id'=>$nc],['send_portal'=>'1']);
$pt=$db->query("SELECT * FROM portal_tokens WHERE client_id=$nc")->fetch();
check($code===0 && $err==='' && $pt && substr($pt['expires_at'],0,10)===date('Y-m-d',strtotime('+180 days')),'The client page emails a portal link that lasts 180 days' . ($err ? ": $err" : ''));
$db->exec("UPDATE portal_tokens SET expires_at=DATE_ADD(NOW(),INTERVAL 10 DAY) WHERE client_id=$nc");
$cronRun('daily_alerts.php',true);
check($db->query("SELECT expiry_warned_at FROM portal_tokens WHERE client_id=$nc")->fetchColumn()!==null,'A link about to lapse is warned about once');
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$out,$err]=$renderPage('portal',['t'=>$pt['token']]);
putenv('CMS_TEST_ANON');
$after=$db->query("SELECT expires_at, expiry_warned_at FROM portal_tokens WHERE client_id=$nc")->fetch();
check($code===0 && $err==='' && substr($after['expires_at'],0,10)===date('Y-m-d',strtotime('+180 days')) && $after['expiry_warned_at']===null,'Opening the link keeps it open another 180 days' . ($err ? ": $err" : ''));
$appSent=$call('send_portal_link',['client_id'=>$nc]);
check(str_contains($appSent['message']??'','notice-client@example.invalid') && $db->query("SELECT token FROM portal_tokens WHERE client_id=$nc")->fetchColumn()!==$pt['token'],'The app sends a new portal link, replacing the old one');
// Email me a new link: the answer never says whether the address is known.
$db->exec("UPDATE portal_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE client_id=$nc");
$oldToken=$db->query("SELECT token FROM portal_tokens WHERE client_id=$nc")->fetchColumn();
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$out,$err]=$renderPage('portal',[]);
check($code===0 && $err==='' && str_contains($out,'name="new_link_email"'),'The expired-link page offers to email a new link' . ($err ? ": $err" : ''));
$renderPage('portal',[],['new_link'=>'1','new_link_email'=>'nobody@example.invalid']);
$renderPage('portal',[],['new_link'=>'1','new_link_email'=>'NOTICE-client@example.invalid']);
putenv('CMS_TEST_ANON');
$renewed=$db->query("SELECT token, expires_at FROM portal_tokens WHERE client_id=$nc")->fetch();
check($renewed['token']!==$oldToken && $renewed['expires_at']>date('Y-m-d H:i:s'),'A client with a confirmed address gets a new link sent to it');

/* New-message notices, at most once a day */
$sends=fn()=>(int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='msgnotice-client-$nc'")->fetchColumn();
[$code,,$err]=$renderPage('messages',['client'=>$nc,'channel'=>'client'],['reply'=>'1','client_id'=>(string)$nc,'channel'=>'client','body'=>'Synthetic staff message one']);
check($code===0 && $err==='' && $sends()===1,'A staff message emails the client a short notice' . ($err ? ": $err" : ''));
[$subj,$body]=email_template('message_notice',['name'=>'X','link'=>'https://example.invalid/p']);
check(!str_contains($subj.$body,'Synthetic staff message one') && str_contains($body,'https://example.invalid/p'),'The notice carries the portal link and not the message');
$renderPage('messages',['client'=>$nc,'channel'=>'client'],['reply'=>'1','client_id'=>(string)$nc,'channel'=>'client','body'=>'Synthetic staff message two']);
check($sends()===1,'A second message the same day sends no second notice');

/* A closed request emails the client */
$db->prepare("INSERT INTO portal_requests(client_id,type,details) VALUES(?,'device','Synthetic device help')")->execute([$nc]); $nr=(int)$db->lastInsertId();
// Sending is exercised through the page (its own mailer); here, what is sent and to whom.
[$code,,$err]=$renderPage('portal_requests',[],['set_status'=>'1','id'=>(string)$nr,'to'=>'Done']);
check($code===0 && $err==='' && $db->query("SELECT status FROM portal_requests WHERE id=$nr")->fetchColumn()==='Done','Answering a request closes it and emails the client' . ($err ? ": $err" : ''));
[$subj,$body]=email_template('request_closed',['name'=>'X','link'=>'https://example.invalid/p']);
check(str_contains($body,'https://example.invalid/p') && !str_contains($subj.$body,'Synthetic device help'),'The answered-request email carries the portal link, not the request');
$db->exec("UPDATE clients SET email_verified=0 WHERE id=$nc");
check(request_closed_notice($nr)===false,'An unconfirmed client address is never emailed');
$db->exec("UPDATE clients SET email_verified=1 WHERE id=$nc");

/* Contact preference in session reminders */
$db->exec("UPDATE clients SET a11y_contact='Phone', phone='555-0142' WHERE id=$nc");
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES($nc,1,DATE_ADD(NOW(),INTERVAL 20 HOUR),DATE_ADD(NOW(),INTERVAL 21 HOUR),'Scheduled')");
[$code,$mail]=$cronRun('session_reminders.php',true);
check($code===0 && !str_contains($mail,'To: notice-client@example.invalid') && str_contains($mail,'they prefer phone. Phone: 555-0142'),'A client who prefers phone is not emailed; the instructor is told to call');
