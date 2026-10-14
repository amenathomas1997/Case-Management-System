<?php
// Included by full_schema.php after workflow_cases.php, against its synthetic
// database only: one end-to-end case for each 3.0.21 rule that several parts of
// the application share (hours outside an authorization, a client email from the
// public referral form, and the equipment waitlist), then the client-record fixes
// of 3.0.21 (what the case export includes, and a new-note form sent twice).
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED']=false;

/* ---- Hours dated after the authorization ended ---- */
// The app refuses until the user acknowledges it, the website until "Log it anyway"
// is ticked (and the override is audited), and completing a session logs the hours
// and says why. Nothing refuses on its own: each surface asks once.
$db->exec("INSERT INTO clients(name,status) VALUES('Expired authorization synthetic client','Active Training')"); $expClient=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Expired authorization track','EXP-21','Synthetic agency',20,50,?,?)")
    ->execute([$expClient,date('Y-m-d',strtotime('-100 days')),date('Y-m-d',strtotime('-10 days'))]); $expTrack=(int)$db->lastInsertId();
$expLogs=fn():int=>(int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$expTrack")->fetchColumn();
$appLog=['track_id'=>$expTrack,'hours'=>1,'date'=>date('Y-m-d'),'description'=>'Synthetic session after the authorization ended'];
$refused=$call('log_hours',$appLog,[],false,'This authorization expired on');
check(str_contains($refused['error'],'Log it on the website to confirm.') && str_starts_with((string)($refused['authorization_issue']??''),'This authorization expired on') && $expLogs()===0,
    'The app refuses hours dated after the authorization ended, says why, and logs nothing until the user acknowledges it');
$call('log_hours',$appLog+['authorization_ack'=>true]);
check($expLogs()===1,'The app logs those hours once the user acknowledges the authorization problem');
$webLog=['add_log'=>'1','hours'=>'1','mileage'=>'','work_date'=>date('Y-m-d'),'description'=>'Synthetic web session after the authorization ended'];
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('track',['id'=>$expTrack],$webLog));
check($code===0 && $err==='' && $expLogs()===1 && str_contains($sess,'log_auth_ack') && str_contains($sess,'This authorization expired on'),
    'The website refuses those hours with the reason on its "Log it anyway" field' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('track',['id'=>$expTrack],$webLog+['auth_ack'=>'1']);
check($code===0 && $err==='' && $expLogs()===2 && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='hours.auth_override' AND entity_id=$expTrack")->fetchColumn()===1,
    'Ticking "Log it anyway" logs the hours and records the override in the audit log' . ($err ? ": $err" : ''));
$authIssue=null;
$autoLog=auto_log_session($expTrack,date('Y-m-d'),1.0,'Synthetic completed session',1,$authIssue);
check($autoLog>0 && $expLogs()===3 && str_starts_with((string)$authIssue,'This authorization expired on') && !db()->inTransaction(),
    'Completing a session logs its hours even after the authorization ended, and says so');

/* ---- A client email typed on the public referral form ---- */
// Converted with the referral but unconfirmed: the app is told, no outcome survey
// goes to it, and session reminders skip it, telling the instructor and the
// administrators (on the client's record) instead.
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,client_email,referral_source) VALUES('Synthetic','Synthetic family referral','Synthetic','Synthetic relative','Family','relative@example.invalid','555-0100','Unconfirmed email synthetic client','unconfirmed-client@example.invalid','family')");
$emailReferral=(int)$db->lastInsertId();
$call('convert_referral',['id'=>$emailReferral]);
$emailClient=(int)$db->query("SELECT client_id FROM referrals WHERE id=$emailReferral")->fetchColumn();
$emailRow=$db->query("SELECT email,email_verified FROM clients WHERE id=$emailClient")->fetch();
check($emailClient>0 && $emailRow['email']==='unconfirmed-client@example.invalid' && (int)$emailRow['email_verified']===0,
    'A client email copied from the public referral form is kept but marked unconfirmed');
check(($call('client',[],['id'=>$emailClient])['client']['email_verified']??null)===false,'The app is told the client email is not yet confirmed');
check(send_outcome_survey($emailClient)===false && (int)$db->query("SELECT COUNT(*) FROM surveys WHERE client_id=$emailClient")->fetchColumn()===0,
    'No outcome survey goes to an unconfirmed client email');
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at) VALUES(?,1,?,?)")->execute([$emailClient,"$tomorrow 09:00:00","$tomorrow 10:00:00"]); $heldSession=(int)$db->lastInsertId();
[$code,$mail]=$cronRun('session_reminders.php',true);
check($code===0 && !str_contains($mail,'To: unconfirmed-client@example.invalid') && str_contains($mail,'has not been confirmed')
    && (int)$db->query("SELECT reminder_sent FROM scheduled_sessions WHERE id=$heldSession")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE link='client.php?id=$emailClient' AND message LIKE '%not confirmed%'")->fetchColumn()>=1,
    'Session reminders skip an unconfirmed client email: the instructor is reminded and told, and the administrators are alerted on the client\'s record');
// The same on the website: conversion keeps the email unconfirmed, and no portal link
// goes to it until staff confirm it with the client.
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,client_email,referral_source) VALUES('Synthetic','Synthetic self referral','Synthetic','Web unconfirmed synthetic client','Self','web-unconfirmed@example.invalid','555-0101','Web unconfirmed synthetic client','web-unconfirmed@example.invalid','self')");
$webReferral=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('referrals',[],['id'=>(string)$webReferral,'convert'=>'1']);
$webClient=(int)$db->query("SELECT client_id FROM referrals WHERE id=$webReferral")->fetchColumn();
check($code===0 && $err==='' && $webClient>0 && (int)$db->query("SELECT email_verified FROM clients WHERE id=$webClient")->fetchColumn()===0,
    'Converting a referral on the website keeps the client email from the public form unconfirmed' . ($err ? ": $err" : ''));
$portalLinks=fn():int=>(int)$db->query("SELECT COUNT(*) FROM portal_tokens WHERE client_id=$webClient")->fetchColumn();
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('client',['id'=>$webClient],['send_portal'=>'1']));
check($code===0 && $err==='' && $portalLinks()===0 && str_contains($sess,'has not been confirmed with the client'),
    'No portal link goes to an unconfirmed client email, and the refusal says why on the send button' . ($err ? ": $err" : ''));
$db->exec("UPDATE clients SET email_verified=1 WHERE id=$webClient");
[$code,,$err]=$renderPage('client',['id'=>$webClient],['send_portal'=>'1']);
check($code===0 && $err==='' && $portalLinks()===1,'Once the email is confirmed the portal link is sent (control for the refusal above)' . ($err ? ": $err" : ''));

/* ---- The equipment waitlist ---- */
// When an item becomes available again, the client who has waited longest is moved
// up to the hold, and only that client; cancelling that hold in the app moves up the next.
$db->exec("INSERT INTO equipment(name,status) VALUES('Waitlist synthetic device','Available')"); $wlItem=(int)$db->lastInsertId();
$wlClient=function(string $name)use($db):int{$db->prepare("INSERT INTO clients(name,status) VALUES(?,'Active Training')")->execute([$name]);return (int)$db->lastInsertId();};
$borrower=$wlClient('Waitlist borrower synthetic'); $firstWaiting=$wlClient('Waitlist first synthetic'); $secondWaiting=$wlClient('Waitlist second synthetic');
loan_equipment($wlItem,$borrower,null,null,1,false);
$wlLoan=(int)$db->query("SELECT id FROM equipment_loans WHERE equipment_id=$wlItem AND returned_at IS NULL")->fetchColumn();
$db->prepare("INSERT INTO equipment_reservations(equipment_id,client_id,reserved_by,status,created_at) VALUES(?,?,1,'Waitlist','2026-01-01 09:00:00'),(?,?,1,'Waitlist','2026-01-02 09:00:00')")
    ->execute([$wlItem,$firstWaiting,$wlItem,$secondWaiting]);
$holdOf=fn(int $client):string=>(string)$db->query("SELECT status FROM equipment_reservations WHERE equipment_id=$wlItem AND client_id=$client")->fetchColumn();
$promotions=fn():int=>(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='equipment.waitlist_promote' AND entity_id=$wlItem")->fetchColumn();
return_equipment($wlLoan,1);
check(!db()->inTransaction() && $db->query("SELECT status FROM equipment WHERE id=$wlItem")->fetchColumn()==='Available'
    && $holdOf($firstWaiting)==='Reserved' && $holdOf($secondWaiting)==='Waitlist' && $promotions()===1,
    'Returning a device moves the client who has waited longest up to the hold, and only that client');
$firstHold=(int)$db->query("SELECT id FROM equipment_reservations WHERE equipment_id=$wlItem AND client_id=$firstWaiting")->fetchColumn();
$call('cancel_reservation',['reservation_id'=>$firstHold]);
check($holdOf($firstWaiting)==='Cancelled' && $holdOf($secondWaiting)==='Reserved' && $promotions()===2,
    'Cancelling that hold in the app moves the next waiting client up');

/* ---- Client-record fixes ---- */
// The case export holds device requests only for staff with procurement access, and
// referral records (with the waitlist entry kept for them) only for staff with
// referral access, as the app shows them; each one left out is said so.
require_once $root.'/public/inc/client_export.php';
$db->prepare("INSERT INTO referral_waitlist(referral_id,status) VALUES(?,'Closed')")->execute([$emailReferral]);
$db->prepare("INSERT INTO device_requests(client_id,device_name) VALUES(?,'Synthetic export device')")->execute([$emailClient]);
$GLOBALS['CURRENT_PERMS']=['clients'];
$narrowExport=client_export_records($db,$emailClient);
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);
$fullExport=client_export_records($db,$emailClient);
check(!isset($narrowExport['device_requests']) && !isset($narrowExport['referrals']) && !isset($narrowExport['referral_waitlist'])
    && isset($narrowExport['device_requests_not_included'], $narrowExport['referrals_not_included']),
    'Without procurement and referral access, the case export leaves out device requests, referrals and their waitlist entry, and says so');
check(count($fullExport['device_requests'])===1 && count($fullExport['referrals'])===1 && count($fullExport['referral_waitlist'])===1,
    'With procurement and referral access, the case export includes the device request, the referral and its waitlist entry');
// One new-note form, one note: sent twice (a second click), it is saved once.
$webNote=['add_note'=>'1','subject'=>'Synthetic web note sent twice','body'=>'Synthetic body','note_format'=>'free','idem_key'=>'44444444-4444-4444-8444-444444444444'];
$renderPage('client',['id'=>$webClient],$webNote);
[$code,,$err]=$renderPage('client',['id'=>$webClient],$webNote);
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='Synthetic web note sent twice'")->fetchColumn()===1,
    'A new-note form sent twice on the website saves one note' . ($err ? ": $err" : ''));

// Stale and forged sign-out requests must not destroy the current session.
[[$code,$out,$err],$sess]=$pageSession(fn()=>$renderPage('logout',[],['csrf'=>'stale-token']));
check($code===0 && $err==='' && str_contains($out,'Confirm sign out') && str_contains($out,'name="csrf"') && str_contains($sess,'user_id|i:1;'),
    'A stale sign-out keeps the account signed in and offers a fresh protected confirmation');
[[$code,$out,$err],$sess]=$pageSession(fn()=>$renderPage('logout',[],[]));
check($code===0 && $err==='' && !str_contains($sess,'user_id|') && str_contains($sess,'You have been signed out.'),
    'A valid sign-out destroys the authenticated session');

// Restoring a reviewed intake draft is a GET: it must not call AI or save an assessment.
$intakeSettingKeys=['ai_enabled','ai_provider','ai_api_key','ai_feat_intake'];
$intakeSettings=[];
foreach($intakeSettingKeys as $key){$q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute([$key]);$intakeSettings[$key]=$q->fetchColumn();}
try {
    foreach(['ai_enabled'=>'1','ai_provider'=>'anthropic','ai_api_key'=>'synthetic-unused-key','ai_feat_intake'=>'1'] as $key=>$value)set_setting($key,$value);
    putenv('CMS_TEST_ANON=1');
    putenv('CMS_TEST_SESSION='.json_encode(['user_id'=>1,'otp_verified'=>true,'credential_version'=>hash('sha256','test'),'last_activity'=>time(),
        'old_input'=>['save_assessment'=>'1','summary'=>'Reviewed synthetic intake retained','primary_tech'=>'Other','goals'=>'Synthetic retained goal','assessment_date'=>date('Y-m-d')]]));
    $intakeBefore=(int)$db->query('SELECT COUNT(*) FROM tech_assessments')->fetchColumn();
    [$code,$out,$err]=$renderPage('intake',['client'=>$expClient]);
    check($code===0 && $err==='' && str_contains($out,'Reviewed synthetic intake retained') && str_contains($out,'Synthetic retained goal')
        && (int)$db->query('SELECT COUNT(*) FROM tech_assessments')->fetchColumn()===$intakeBefore,
        'Session recovery restores intake answers and the reviewed draft without saving it');
} finally {
    putenv('CMS_TEST_ANON');putenv('CMS_TEST_SESSION');
    foreach($intakeSettings as $key=>$value){if($value===false){$q=$db->prepare('DELETE FROM settings WHERE name=?');$q->execute([$key]);}else set_setting($key,$value);}
}

/* Account-owned toolbar visibility uses the signed-in actor, never a posted ID. */
$displayKey = 'user_low_vision_hidden_1';
$oldDisplay = $db->prepare('SELECT value FROM settings WHERE name=?');
$oldDisplay->execute([$displayKey]); $previousDisplay = $oldDisplay->fetchColumn();
try {
    [$code,,$err] = $renderPage('account', [], ['save_display_controls'=>'1', 'display_controls'=>'hide', 'user_id'=>'999999']);
    $oldDisplay->execute([$displayKey]);
    check($code===0 && $err==='' && $oldDisplay->fetchColumn()==='1', 'Account display settings save only for the signed-in user');
    [$code,$html,$err] = $renderPage('account', []);
    check($code===0 && $err==='' && str_contains($html,'data-account-hidden="true"') && str_contains($html,'Save display settings'), 'Hidden toolbar keeps its account recovery control available');
    [$code,,$err] = $renderPage('account', [], ['save_display_controls'=>'1', 'display_controls'=>['hide']]);
    $oldDisplay->execute([$displayKey]);
    check($code===0 && $err==='' && $oldDisplay->fetchColumn()==='1', 'Malformed visibility values cannot change the preference');
    [$code,,$err] = $renderPage('account', [], ['save_display_controls'=>'1', 'display_controls'=>'show']);
    [$code,$html,$err] = $renderPage('account', []);
    check($code===0 && $err==='' && str_contains($html,'data-account-hidden="false"'), 'Users can show the toolbar again from My Account');
} finally {
    if ($previousDisplay===false) $db->prepare('DELETE FROM settings WHERE name=?')->execute([$displayKey]);
    else $db->prepare('UPDATE settings SET value=? WHERE name=?')->execute([$previousDisplay,$displayKey]);
}
