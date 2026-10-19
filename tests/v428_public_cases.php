<?php
// Included by full_schema.php after the 4.27.0 cases, against its synthetic database only:
// 4.28.0, the public area. A reschedule chosen from offered times moves the session to the
// offered instructor, place and setting (and checks that instructor's calendar); unanswered
// survey links lapse; the privacy notice stays English for staff; a staff-written document to
// sign is marked English on a Spanish page; the counselor portal shows VR tracks only; the
// self-booking error is tied to its field; required marks and hints sit in their labels.

// ---- N12: a reschedule takes the offered instructor, setting and place ----
$db->exec("INSERT INTO users(name,email,password_hash,role) VALUES('V428 Offer Instructor B','v428-offer-b@example.invalid','x','instructor')"); $v428b=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V428 Offer Synthetic Client','Active Training',1)"); $v428c=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'V428 offer track','Active','V428-OF','Synthetic VR',20,50,DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_ADD(CURDATE(),INTERVAL 120 DAY))")->execute([$v428c]); $v428t=(int)$db->lastInsertId();
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$v428c,bin2hex(random_bytes(24))]);
$v428base=strtotime('+20 days 10:00'); while ((int)date('N',$v428base) > 5) $v428base+=86400;
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,location,is_remote,meeting_url,status) VALUES(?,?,1,?,?,'Synthetic Room A',0,NULL,'Scheduled')")
    ->execute([$v428c,$v428t,date('Y-m-d H:i:s',$v428base),date('Y-m-d H:i:s',$v428base+3600)]); $v428s=(int)$db->lastInsertId();
$v428moving=$db->query("SELECT * FROM scheduled_sessions WHERE id=$v428s")->fetch();
// Instructor B is busy at one time; the session's own instructor (1) is busy at another.
$v428busyB=$v428base+2*86400; $v428busyA=$v428base+3*86400;
$db->prepare("UPDATE scheduled_sessions SET status='Cancelled' WHERE user_id IN (1,?) AND status='Scheduled' AND id<>? AND start_at BETWEEN ? AND ?")->execute([$v428b,$v428s,date('Y-m-d H:i:s',$v428base-3600),date('Y-m-d H:i:s',$v428base+5*86400)]);
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,?,?,?,'Scheduled')")->execute([$v428c,$v428b,date('Y-m-d H:i:s',$v428busyB),date('Y-m-d H:i:s',$v428busyB+3600)]); $v428sb=(int)$db->lastInsertId();
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,?,?,'Scheduled')")->execute([$v428c,date('Y-m-d H:i:s',$v428busyA),date('Y-m-d H:i:s',$v428busyA+3600)]); $v428sa=(int)$db->lastInsertId();
check(offer_time_problem($v428busyB,$v428busyB+3600,$v428b,$v428t,$v428moving)!==null,
    'A reschedule time is refused when the offered instructor is busy then, although the session\'s own instructor is free');
check(offer_time_problem($v428busyA,$v428busyA+3600,$v428b,$v428t,$v428moving)===null,
    'A reschedule time with another instructor is not refused because the session\'s first instructor is busy then');
$db->prepare("INSERT INTO portal_requests(client_id,type,session_id,status) VALUES(?,'reschedule',?,'New')")->execute([$v428c,$v428s]); $v428r=(int)$db->lastInsertId();
$v428new=$v428base+86400+3600*3;
$v428res=offer_times_save($v428r,[$v428new],60,$v428b,null,true,'',1);
$v428o=(int)$db->query("SELECT id FROM portal_request_offers WHERE request_id=$v428r AND status='Offered'")->fetchColumn();
check($v428res['error']===null && $v428o>0,'A remote time with another instructor can be offered to move a session');
$db->prepare("UPDATE scheduled_sessions SET group_uid='v428grp' WHERE id=?")->execute([$v428s]);
$v428notesBefore=(int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND message LIKE 'Session moved to another instructor%'")->fetchColumn();
$v428conf=offer_confirm($v428o,$v428c);
$v428after=$db->query("SELECT * FROM scheduled_sessions WHERE id=$v428s")->fetch();
check($v428conf['error']===null && $v428after['start_at']===date('Y-m-d H:i:s',$v428new) && (int)$v428after['user_id']===$v428b
    && (int)$v428after['is_remote']===1 && $v428after['location']===null,
    'Choosing an offered reschedule time moves the session to the offered instructor, online, with the old room cleared');
check($v428after['group_uid']===null,'A session moved by an offered time leaves the group it was booked with');
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND message LIKE 'Session moved to another instructor%'")->fetchColumn()===$v428notesBefore+1,
    'The previous instructor is told the session moved to another instructor (with a calendar cancellation)');
check(str_contains((string)file_get_contents($root.'/public/inc/request_offers.php'),"'METHOD:CANCEL'"),'The previous instructor\'s email carries a calendar cancellation');
// A caseload-limited instructor not assigned this client is never offered.
$db->exec("INSERT INTO `groups`(name,perms,own_caseload_only) VALUES('V428 scoped offer group','clients;schedule',1)"); $v428g=(int)$db->lastInsertId();
$db->prepare("INSERT INTO users(name,email,password_hash,role,group_id) VALUES('V428 Scoped Instructor C','v428-offer-c@example.invalid','x','instructor',?)")->execute([$v428g]); $v428sc_u=(int)$db->lastInsertId();
$db->prepare("INSERT INTO portal_requests(client_id,type,session_id,status) VALUES(?,'reschedule',?,'New')")->execute([$v428c,$v428s]); $v428rc=(int)$db->lastInsertId();
$v428resC=offer_times_save($v428rc,[$v428base+6*86400],60,$v428sc_u,null,false,'',1);
check($v428resC['error']!==null && ($v428resC['field']??'')==='staff' && !(int)$db->query("SELECT COUNT(*) FROM portal_request_offers WHERE request_id=$v428rc")->fetchColumn(),
    'A caseload-limited instructor who is not assigned the client cannot be offered');
check(offer_staff_problem($v428b,$v428c)===null,'An instructor with no caseload limit can be offered');
if (isset($pageSession)) {
    [[$code,,$err],$v428ss]=$pageSession(fn()=>$renderPage('portal_requests',[],['offer_times'=>'1','id'=>(string)$v428rc,'time1'=>date('Y-m-d\TH:i',$v428base+6*86400),'staff'=>(string)$v428sc_u,'location'=>'']));
    check(str_contains($v428ss,'offer_staff-'.$v428rc) && str_contains($v428ss,'works only with their own caseload'),'The refusal is tied to the With field'.($err?": $err":''));
}
$db->prepare("UPDATE portal_requests SET status='Declined' WHERE id=?")->execute([$v428rc]);
// An in-person offer with its own place gives the session that place; an online link made by another instructor is not kept.
$db->prepare("UPDATE scheduled_sessions SET user_id=1, is_remote=1, meeting_url='https://example.invalid/old-link' WHERE id=?")->execute([$v428s]);
$db->prepare("INSERT INTO portal_requests(client_id,type,session_id,status) VALUES(?,'reschedule',?,'New')")->execute([$v428c,$v428s]); $v428r2=(int)$db->lastInsertId();
$v428new2=$v428base+4*86400+3600;
offer_times_save($v428r2,[$v428new2],60,$v428b,null,false,'Synthetic Library Room',1);
$v428o2=(int)$db->query("SELECT id FROM portal_request_offers WHERE request_id=$v428r2 AND status='Offered'")->fetchColumn();
$v428conf2=offer_confirm($v428o2,$v428c);
$v428after2=$db->query("SELECT * FROM scheduled_sessions WHERE id=$v428s")->fetch();
check($v428conf2['error']===null && (int)$v428after2['is_remote']===0 && $v428after2['location']==='Synthetic Library Room' && $v428after2['meeting_url']===null && (int)$v428after2['user_id']===$v428b,
    'Choosing an in-person offered time gives the session the offered place and drops the old online link');
// The reschedule offer form starts from the session's own instructor and place.
$db->prepare("INSERT INTO portal_requests(client_id,type,session_id,status) VALUES(?,'reschedule',?,'New')")->execute([$v428c,$v428s]); $v428r3=(int)$db->lastInsertId();
[$code,$html,$err]=$renderPage('portal_requests',[]);
check($code===0 && preg_match('~id="loc-'.$v428r3.'"[^>]*value="Synthetic Library Room"~',$html)===1
    && preg_match('~id="st-'.$v428r3.'".*?<option value="'.$v428b.'" selected~s',$html)===1,
    'The reschedule offer form starts from the session\'s instructor and place'.($err?": $err":''));
$db->prepare("UPDATE portal_requests SET status='Declined' WHERE id=?")->execute([$v428r3]);
$db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE id IN ($v428sb,$v428sa)");

// ---- Survey links lapse when unanswered; an answered one stays viewable ----
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('V428 Survey Synthetic','Closed','v428-survey@example.invalid',1)"); $v428sc=(int)$db->lastInsertId();
$v428old=bin2hex(random_bytes(24)); $v428done=bin2hex(random_bytes(24)); $v428fresh=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO surveys(client_id,token,sent_at) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 130 DAY))")->execute([$v428sc,$v428old]);
$db->prepare("INSERT INTO surveys(client_id,token,sent_at,completed_at,satisfaction,goals_met) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 130 DAY),DATE_SUB(NOW(),INTERVAL 120 DAY),8,8)")->execute([$v428sc,$v428done]);
$db->prepare("INSERT INTO surveys(client_id,token,sent_at) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 10 DAY))")->execute([$v428sc,$v428fresh]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$hOld,$err1]=$renderPage('survey',['t'=>$v428old]);
[$code,$hDone,$err2]=$renderPage('survey',['t'=>$v428done]);
[$code,$hFresh,$err3]=$renderPage('survey',['t'=>$v428fresh]);
[$code,,$err4]=$renderPage('survey',['t'=>$v428old],['satisfaction'=>'5','goals_met'=>'5','comments'=>'']);
putenv('CMS_TEST_ANON');
check(str_contains($hOld,'Link not recognized') && !str_contains($hOld,'V428')
    && $db->query("SELECT completed_at FROM surveys WHERE token='$v428old'")->fetchColumn()===null,
    'An unanswered survey link sent 130 days ago is not recognized, greets nobody and records nothing'.($err1.$err4?": $err1 $err4":''));
check(!str_contains($hDone,'Link not recognized') && !str_contains($hFresh,'Link not recognized') && str_contains($hFresh,'name="satisfaction"'),
    'An answered survey stays viewable, and a recent link still opens the survey'.($err2.$err3?": $err2 $err3":''));
check(!str_contains($hFresh,'id="sat-hint"') && !str_contains($hFresh,'aria-describedby="sat-hint') && preg_match('~<legend>[^<]*<span class="hint">[^<]*1 = not satisfied~',$hFresh)===1,
    'The survey\'s rating scale is described inside its legend, not in a separate hint');

// ---- The privacy notice stays English for a signed-in staff member ----
putenv('CMS_TEST_SESSION_ADD='.json_encode(['lang'=>'es']));
[$code,$hPriv,$err]=$renderPage('privacy',[]);
putenv('CMS_TEST_SESSION_ADD');
check($code===0 && preg_match('~<html[^>]*lang="en"~',$hPriv)===1 && str_contains($hPriv,'<h1>Privacy notice</h1>'),
    'A signed-in staff member gets the privacy notice in English even after a Spanish public page'.($err?": $err":''));

// ---- A staff-written document to sign is marked English on a Spanish page ----
$db->exec("INSERT INTO clients(name,status,email,email_verified) VALUES('V428 Signer Synthetic','Active Training','v428-signer@example.invalid',1)"); $v428gc=(int)$db->lastInsertId();
$v428sig=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO client_signatures(client_id,kind,title,body,signer_role,signer_name,signer_email,token,lang) VALUES(?,'document','V428 Synthetic Photo Release','We may use photos of training.','Client','V428 Signer Synthetic','v428-signer@example.invalid',?,NULL)")->execute([$v428gc,$v428sig]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$hSign,$err]=$renderPage('sign',['t'=>$v428sig,'lang'=>'es']);
putenv('CMS_TEST_ANON');
check(str_contains($hSign,'<span lang="en">V428 Synthetic Photo Release</span>') && str_contains($hSign,'<div lang="en">')
    && !str_contains($hSign,'id="sig-hint"') && !str_contains($hSign,'aria-describedby="sig-hint'),
    'A staff-written document (language unknown) is marked English on a Spanish signing page, and the signing hint is in the label'.($err?": $err":''));

// ---- The counselor portal shows VR tracks only, and only their sessions ----
$v428vEmail='v428-counselor@example.invalid';
$db->prepare("INSERT INTO clients(name,status,counselor_name,counselor_email,counselor_verified) VALUES('V428 VRC Synthetic','Active Training','V428 Counselor',?,1)")->execute([$v428vEmail]); $v428vc=(int)$db->lastInsertId();
$v428vTok=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token,expires_at) VALUES(?,'V428 Counselor',?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$v428vEmail,$v428vTok]); $v428vId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date,billing_mode) VALUES(?,'V428 VR track','Active','V428-VR','Synthetic VR',10,50,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 90 DAY),'vr')")->execute([$v428vc]); $v428vt=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date,billing_mode) VALUES(?,'V428 Private pay track','Active','','',10,50,DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 90 DAY),'private')")->execute([$v428vc]); $v428pt=(int)$db->lastInsertId();
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status) VALUES(?,?,1,DATE_SUB(NOW(),INTERVAL 5 DAY),DATE_SUB(NOW(),INTERVAL 119 HOUR),'Completed')")->execute([$v428vc,$v428vt]);
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,status) VALUES(?,?,1,DATE_SUB(NOW(),INTERVAL 6 DAY),DATE_SUB(NOW(),INTERVAL 143 HOUR),'No Show')")->execute([$v428vc,$v428pt]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION='.json_encode(['vrc_ok_'.$v428vId=>true,'vrc_ok_'.$v428vId.'_at'=>time()]));
[$code,$hVrc,$err]=$renderPage('vrc',['t'=>$v428vTok]);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
check($code===0 && str_contains($hVrc,'V428 VR track') && !str_contains($hVrc,'V428 Private pay track') && str_contains($hVrc,'1 attended, 0 missed'),
    'The counselor portal lists only the client\'s VR tracks, and attendance only on them'.($err?": $err":''));

// ---- Self-booking: "Choose a time first." is tied to the time field ----
$v428bt=(string)$db->query("SELECT token FROM portal_tokens WHERE client_id=$v428c")->fetchColumn();
if (isset($pageSession)) {
    putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
    [[$code,,$err],$v428sess]=$pageSession(fn()=>$renderPage('portal',['t'=>$v428bt],['self_book'=>'1','slot'=>'']));
    putenv('CMS_TEST_ANON');
    check(str_contains($v428sess,'book_slot') && str_contains($v428sess,'Choose a time first.'),'Booking with no time chosen marks the time field, not only a message at the top'.($err?": $err":''));
}
$v428portal=(string)file_get_contents($root.'/public/portal.php');
check(str_contains($v428portal,"field_aria('book_slot')") && str_contains($v428portal,"field_error('book_slot')") && str_contains($v428portal,"form_error_summary(['book_slot'"),
    'The booking form ties its error to the time field, with a summary');

// ---- Required marks, hints inside labels ----
check(str_contains($v428portal,"<legend><?= t('Are you still using it?') ?> <span class=\"hint\"><?= t('(required)') ?></span></legend>"),
    'The device check-in question shows a visible (required)');
check(str_contains((string)file_get_contents($root.'/public/report.php'),'<label for="recipient">Send to <?= required_mark() ?>'),'The progress report\'s Send to is marked required');
$v428files=['portal.php','portal_requests.php','survey.php','privacy.php','help.php','sign.php','vrc.php','report.php','eval_report.php','consent.php','release.php',
    'community_referral.php','amendment_request.php','ask.php','kb.php','kb_ask.php','translate.php','inc/homework_support_ui.php','inc/request_offers.php','inc/self_booking.php'];
$v428bad=[];
foreach ($v428files as $f) {
    $src=(string)file_get_contents($root.'/public/'.$f);
    // A hint joined to a control by id (field_aria with a hint id, or a describedby naming a -hint/-note element).
    if (preg_match('~(?:field_aria|\$aria)\([^)]*,\s*\'[^\']*(?:hint|note)|aria-describedby="[^"]*(?:hint|note)~',$src)) $v428bad[]=$f;
    if (preg_match('~<p class="hint" id=~',$src)) $v428bad[]=$f.' (hint paragraph with an id)';
}
check(!$v428bad,'No hint on the public pages is a separate element joined by aria-describedby'.($v428bad?': '.implode(', ',$v428bad):''));

// ---- Help: administrators locked out of their authenticator app ----
[$code,$hHelp,$err]=$renderPage('help',[]);
check($code===0 && str_contains($hHelp,'Locked out of your authenticator app') && str_contains($hHelp,'php reset_2fa.php their@email'),
    'The administrator guide says how to get back in when the authenticator app is lost'.($err?": $err":''));
