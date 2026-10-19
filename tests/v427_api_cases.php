<?php
// Included by full_schema.php after v426_cases.php, against its synthetic database only:
// 4.27.0: the app's server side (lost-phone wipe, group cancel, authorization approval,
// guardian consent at 18, a drawn signature that cannot be saved).

// ---- A phone reported lost keeps being told to wipe until the app confirms ----
$db->exec("INSERT INTO users(name,email,password_hash,role) VALUES('V427 Wipe Synthetic','v427-wipe@example.invalid','x','instructor')"); $v427wu=(int)$db->lastInsertId();
$v427phone=bin2hex(random_bytes(32));
// The session already ended (the phone was switched off): it can still be reported lost.
$db->prepare("INSERT INTO api_tokens(user_id,token_hash,expires_at,device_name) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 1 DAY),'V427 lost iPhone')")->execute([$v427wu,hash('sha256',$v427phone)]); $v427tok=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('users',['id'=>$v427wu],['uid'=>(string)$v427wu,'device_lost'=>'1','device_id'=>(string)$v427tok]);
check($code===0 && $err==='' && $db->query("SELECT wipe_requested_at FROM api_tokens WHERE id=$v427tok")->fetchColumn()!==null,
    'A phone whose session already ended can still be reported lost'.($err?": $err":''));
// A password reset before the phone connects keeps the wipe waiting.
$v427reset=bin2hex(random_bytes(32));
$db->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$v427wu,hash('sha256',$v427reset)]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,,$err]=$renderPage('reset',['t'=>$v427reset],['new_password'=>'V427-reset-synthetic-1','confirm_password'=>'V427-reset-synthetic-1']);
putenv('CMS_TEST_ANON');
check((int)$db->query("SELECT used FROM password_resets WHERE token_hash='".hash('sha256',$v427reset)."'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$v427tok")->fetchColumn()===1,'A password reset leaves a phone waiting to wipe'.($err?": $err":''));
// Revoking every app session from Users keeps it too.
[$code,,$err]=$renderPage('users',['id'=>$v427wu],['uid'=>(string)$v427wu,'revoke_app'=>'1']);
check((int)$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$v427tok")->fetchColumn()===1,'Signing someone out of the app everywhere leaves a phone waiting to wipe'.($err?": $err":''));
putenv('CMS_TEST_TOKEN='.$v427phone);
$v427w1=$call('me',[],[],false);
$v427w2=$call('schedule',[],[],false);
putenv('CMS_TEST_TOKEN');
check(($v427w1['wipe']??null)===true && ($v427w2['wipe']??null)===true && $db->query("SELECT wiped_at FROM api_tokens WHERE id=$v427tok")->fetchColumn()!==null
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='user.device_wiped' AND entity_id=$v427wu")->fetchColumn()===1,
    'The wipe is answered on every request after the first, and recorded once');
putenv('CMS_TEST_TOKEN='.$v427phone);
$v427done=$call('wipe_done',[]);
$v427after=$call('me',[],[],false);
putenv('CMS_TEST_TOKEN');
check(($v427done['ok']??false)===true && (int)$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$v427tok")->fetchColumn()===0 && ($v427after['wipe']??null)!==true,
    'Once the app confirms the wipe its session is removed, and it is simply signed out');

// ---- Cancel the whole group: a caseload-limited user cannot cancel other caseloads' clients ----
$db->exec("INSERT INTO users(name,email,password_hash,role) VALUES('V427 Other Counselor','v427-other@example.invalid','x','instructor')"); $v427other=(int)$db->lastInsertId();
$db->exec("INSERT INTO `groups`(name,perms,own_caseload_only) VALUES('V427 scoped synthetic','clients;schedule',1)"); $v427g=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V427 Mine Synthetic','Active Training',1)"); $v427mine=(int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,assigned_to) VALUES('V427 Theirs Synthetic','Active Training',?)")->execute([$v427other]); $v427theirs=(int)$db->lastInsertId();
$v427gs=date('Y-m-d H:i:s',strtotime('+5 days 14:00')); $v427ge=date('Y-m-d H:i:s',strtotime('+5 days 15:00'));
$v427ins=$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,group_uid) VALUES(?,1,?,?,'v427grpsynth')");
$v427ins->execute([$v427mine,$v427gs,$v427ge]); $v427s1=(int)$db->lastInsertId();
$v427ins->execute([$v427theirs,$v427gs,$v427ge]); $v427s2=(int)$db->lastInsertId();
$v427oldGroup=$db->query('SELECT group_id FROM users WHERE id=1')->fetchColumn();
$db->prepare('UPDATE users SET group_id=? WHERE id=1')->execute([$v427g]);
try {
    $call('cancel_group',['session_id'=>$v427s1],[],false,'not on your caseload');
    check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE group_uid='v427grpsynth' AND status='Scheduled'")->fetchColumn()===2,
        'A caseload-limited user cannot cancel a group that has a client on another caseload');
    [$code,,$err]=$renderPage('schedule',[],['session_id'=>(string)$v427s1,'cancel_group'=>'1']);
    check($err==='' && (int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE group_uid='v427grpsynth' AND status='Scheduled'")->fetchColumn()===2,
        'The Schedule page refuses the same group cancel'.($err?": $err":''));
    $db->prepare('UPDATE clients SET assigned_to=1 WHERE id=?')->execute([$v427theirs]);
    $v427c=$call('cancel_group',['session_id'=>$v427s1]);
    check((int)($v427c['cancelled']??0)===2,'Once every client in the group is theirs, the whole group is cancelled (control for the refusal above)');
} finally {
    $db->prepare('UPDATE users SET group_id=? WHERE id=1')->execute([$v427oldGroup===false?null:$v427oldGroup]);
}

// ---- Booking in the app: the approval covers only the warnings shown ----
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'V427 track','Active','V427-AUTH','Synthetic VR',100,50,DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_ADD(CURDATE(),INTERVAL 3 DAY))")->execute([$v427mine]); $v427t=(int)$db->lastInsertId();
$v427book=['client_id'=>$v427mine,'track_id'=>$v427t,'staff_id'=>1,'date'=>date('Y-m-d',strtotime('+10 days')),'time'=>'10:00','duration'=>1];
$v427r=$call('schedule_session',$v427book+['authorization_ack'=>false],[],false,'Authorization review');
check(is_string($v427r['authorization_hash']??null) && strlen($v427r['authorization_hash'])===64,'A booking with authorization warnings is answered with a fingerprint of them');
$call('schedule_session',$v427book+['authorization_ack'=>true,'authorization_ack_hash'=>str_repeat('0',64)],[],false,'not the ones you approved');
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$v427t")->fetchColumn()===0,'An approval of other warnings books nothing');
$call('schedule_session',$v427book+['authorization_ack'=>true,'authorization_ack_hash'=>$v427r['authorization_hash']]);
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$v427t")->fetchColumn()===1,'Approving the warnings shown books the session');

// ---- A guardian's consent stops covering when the client turns 18 ----
$db->prepare("INSERT INTO clients(name,status,dob) VALUES('V427 Adult Synthetic','Active Training',?)")->execute([date('Y-m-d',strtotime('-18 years -10 days'))]); $v427adult=(int)$db->lastInsertId();
$db->prepare("INSERT INTO consent_forms(client_id,guardian_name,guardian_email,token,signed_at,signed_name) VALUES(?,'V427 Guardian','g427@example.invalid',?,DATE_SUB(NOW(),INTERVAL 20 DAY),'V427 Guardian')")->execute([$v427adult,bin2hex(random_bytes(24))]);
$db->prepare("INSERT INTO client_releases(client_id,signer_role,signer_name,recipient_type,recipient_name,info_scope,purpose,method,signed_at,signed_name,expires_on) VALUES(?,'Guardian','V427 Guardian','Employer','V427 Employer','Progress','Job','Paper',DATE_SUB(NOW(),INTERVAL 20 DAY),'V427 Guardian',DATE_ADD(CURDATE(),INTERVAL 300 DAY))")->execute([$v427adult]);
$db->prepare("INSERT INTO clients(name,status,dob) VALUES('V427 Minor Synthetic','Active Training',?)")->execute([date('Y-m-d',strtotime('-17 years'))]); $v427minor=(int)$db->lastInsertId();
$db->prepare("INSERT INTO consent_forms(client_id,guardian_name,guardian_email,token,signed_at,signed_name) VALUES(?,'V427 Guardian','g427m@example.invalid',?,NOW(),'V427 Guardian')")->execute([$v427minor,bin2hex(random_bytes(24))]);
check(release_warning($v427adult,'VR counselor and agency')!==null && release_warning($v427adult,'Employer')!==null,
    'A guardian\'s consent, and a release a guardian signed, stop covering once the client is 18');
check(release_warning($v427minor,'VR counselor and agency')===null,'A guardian\'s consent still covers a client under 18 (control)');

// ---- Logging hours in the app: a drawn signature that cannot be saved refuses the entry ----
$call('log_hours',['track_id'=>$v427t,'hours'=>1,'date'=>date('Y-m-d'),'description'=>'V427 bad signature entry','signature_b64'=>base64_encode('not a picture')],[],false,'signature could not be saved');
check((int)$db->query("SELECT COUNT(*) FROM work_logs WHERE description='V427 bad signature entry'")->fetchColumn()===0,'Hours sent with a signature that cannot be saved are not logged');

// ---- Sent again after a lost reply: the app's idem_key answers the first result ----
$v427k='v427-idem-'.bin2hex(random_bytes(8));
$v427sig=['client_id'=>$ac,'kind'=>'document','signer_role'=>'Client','signer_name'=>'App Synthetic Client','title'=>'V427 idem consent','body'=>'Signed once.','idem_key'=>$v427k];
$v427a=$call('start_signature',$v427sig);
$v427b=$call('start_signature',$v427sig);
check((int)$v427a['id']===(int)$v427b['id'] && !empty($v427b['repeat'])
    && (int)$db->query("SELECT COUNT(*) FROM client_signatures WHERE title='V427 idem consent'")->fetchColumn()===1,'A document to sign started twice with the same key is started once');
$v427rel=['client_id'=>$ac,'signer_role'=>'Client','signer_name'=>'App Synthetic Client','signer_email'=>'v427-signer@example.invalid','recipient_type'=>'Employer',
    'recipient_name'=>'V427 Idem Employer','info_other'=>'Progress reports','purpose'=>'Job placement','idem_key'=>$v427k];
$v427r1=$call('request_release',$v427rel);
$db->exec("UPDATE client_releases SET sent_at=DATE_SUB(sent_at, INTERVAL 10 MINUTE) WHERE recipient_name='V427 Idem Employer'"); // past the two-minute repeat check
$v427r2=$call('request_release',$v427rel);
check((int)$v427r1['id']===(int)$v427r2['id'] && !empty($v427r2['repeat'])
    && (int)$db->query("SELECT COUNT(*) FROM client_releases WHERE recipient_name='V427 Idem Employer'")->fetchColumn()===1,'A release request sent twice with the same key is emailed once');

// ---- App sign-in for an account with an authenticator app: no emailed code (4.27.0) ----
if (!function_exists('totp_code')) require_once $root.'/public/inc/totp.php';
$v427secret=totp_generate_secret();
$db->prepare("INSERT INTO users(name,email,password_hash,role,totp_enabled,totp_secret) VALUES('V427 TOTP Synthetic','v427-totp@example.invalid',?,'instructor',1,?)")
    ->execute([password_hash('V427-totp-pass-1',PASSWORD_DEFAULT),$v427secret]); $v427tu=(int)$db->lastInsertId();
$v427l=$call('login',['email'=>'v427-totp@example.invalid','password'=>'V427-totp-pass-1']);
check(($v427l['email_sent']??null)===false && ($v427l['totp_required']??null)===true,'An authenticator account is asked for its authenticator code, and no code is emailed');
// Even a code that reached the mailbox (put on the row here) does not finish the sign-in.
$v427otp=(int)$db->query("SELECT id FROM login_otps WHERE user_id=$v427tu AND channel='api' AND consumed=0 ORDER BY id DESC LIMIT 1")->fetchColumn();
$v427h=password_hash('246810',PASSWORD_DEFAULT);
$db->prepare('UPDATE login_otps SET code_hash=? WHERE id=?')->execute([$v427h,$v427otp]);
$v427ch=hash_hmac('sha256','api-login-challenge:'.$v427otp,$v427h);
$call('verify',['email'=>'v427-totp@example.invalid','code'=>'246810','login_challenge'=>$v427ch],[],false,'not correct');
$v427v=$call('verify',['email'=>'v427-totp@example.invalid','code'=>totp_code($v427secret),'login_challenge'=>$v427ch]);
check(is_string($v427v['token']??null),'The authenticator code signs the app in (control for the refusal above)');
// A correct password for an authenticator account sends no code, so it never uses up the code cap.
for($i=0;$i<6;$i++) $call('login',['email'=>'v427-totp@example.invalid','password'=>'V427-totp-pass-1']);
check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE email='login:v427-totp@example.invalid' AND success=1")->fetchColumn()===0,
    'Signing in to the app with an authenticator does not count toward the emailed-code cap');

// ---- An adult under guardianship: a guardian's consent signed after 18 still covers ----
$db->prepare("INSERT INTO clients(name,status,dob) VALUES('V427 Ward Synthetic','Active Training',?)")->execute([date('Y-m-d',strtotime('-25 years'))]); $v427ward=(int)$db->lastInsertId();
$db->prepare("INSERT INTO consent_forms(client_id,guardian_name,guardian_email,token,signed_at,signed_name) VALUES(?,'V427 Guardian','g427w@example.invalid',?,NOW(),'V427 Guardian')")->execute([$v427ward,bin2hex(random_bytes(24))]);
check(release_warning($v427ward,'VR counselor and agency')===null,'A guardian\'s consent signed after the client turned 18 (an adult under guardianship) still covers');

// ---- A closed referral cannot be converted from the app ----
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,status) VALUES('Synthetic','V427 closed','Synthetic','Referrer','Agency','referrer427@example.invalid','555','V427 Closed Referral','Closed')"); $v427ref=(int)$db->lastInsertId();
$call('convert_referral',['id'=>$v427ref],[],false,'Reopen it first');
check($db->query("SELECT status FROM referrals WHERE id=$v427ref")->fetchColumn()==='Closed' && (int)$db->query("SELECT COUNT(*) FROM clients WHERE name='V427 Closed Referral'")->fetchColumn()===0,
    'A closed referral is not converted into a client');

// ---- A moved session's reminders are sent again for the new time ----
$v427ms=date('Y-m-d H:i:s',strtotime('+6 days 09:00'));
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,reminder_sent,client_reminded_at,staff_reminded_at) VALUES(?,1,?,DATE_ADD(?,INTERVAL 1 HOUR),1,NOW(),NOW())")->execute([$v427mine,$v427ms,$v427ms]); $v427mv=(int)$db->lastInsertId();
$call('move_session',['session_id'=>$v427mv,'start_at'=>date('Y-m-d',strtotime('+8 days')).' 13:00','duration'=>1,'confirm'=>true]);
$v427row=$db->query("SELECT reminder_sent,client_reminded_at,staff_reminded_at FROM scheduled_sessions WHERE id=$v427mv")->fetch();
check((int)$v427row['reminder_sent']===0 && $v427row['client_reminded_at']===null && $v427row['staff_reminded_at']===null,'Moving a session re-arms its client and staff reminders');

// ---- A phone waiting to wipe is not counted as signed in ----
$v427before=(int)$call('account')['app_sessions'];
$db->prepare("INSERT INTO api_tokens(user_id,token_hash,expires_at,device_name,wipe_requested_at) VALUES(1,?,DATE_ADD(NOW(),INTERVAL 5 DAY),'V427 lost phone',NOW())")->execute([hash('sha256',bin2hex(random_bytes(32)))]);
check((int)$call('account')['app_sessions']===$v427before,'A phone reported lost is not counted among the app sessions');

// ---- A signed document cannot be withdrawn ----
$v427sd=$call('start_signature',['client_id'=>$ac,'kind'=>'document','signer_role'=>'Client','signer_name'=>'App Synthetic Client','title'=>'V427 signed then withdrawn','body'=>'Signed.']);
$call('sign_in_person',['client_id'=>$ac,'id'=>$v427sd['id'],'typed_name'=>'App Synthetic Client']);
$v427wd=$call('withdraw_signature',['client_id'=>$ac,'id'=>$v427sd['id']],[],false,'already signed');
check(($v427wd['signed']??null)===true && $db->query('SELECT withdrawn_at FROM client_signatures WHERE id='.(int)$v427sd['id'])->fetchColumn()===null,'A document already signed is not withdrawn, and the app is told it is signed');
