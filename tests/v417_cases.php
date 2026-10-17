<?php
// Included by full_schema.php after v416_cases.php, against its synthetic database only:
// 4.17.0's text messages. Twilio is never called: CMS_TEST_SMS answers as it would.
// Texts wait for 8 a.m. to 8 p.m. in the client's time zone, so what a send does depends on the
// hour the suite runs: $expect says which (sent now, or held for the morning).
putenv('CMS_TEST_SMS=ok');
$sent=fn(int $c,string $purpose):array=>$db->query("SELECT * FROM sms_log WHERE client_id=$c AND purpose=".$db->quote($purpose)." ORDER BY id")->fetchAll();
$expect=fn(int $c):string=>sms_send_after_zones(sms_client_zones(sms_client($c)))===null?'sent':'held';

check(!sms_enabled() && sms_to_client(1,'x','message')['status']==='blocked','Nothing is texted until texting is set up');
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.sms.account_sid','AC-synthetic'),('cfg.sms.auth_token','synthetic-token'),('cfg.sms.from','+15155550100') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['sms']=['account_sid'=>'AC-synthetic','auth_token'=>'synthetic-token','from'=>'+15155550100'];
check(sms_enabled(),'Texting is on once the account, token and number are set');
check(sms_number('(515) 555-0142')==='+15155550142' && sms_number('1-515-555-0142')==='+15155550142' && sms_number('+44 20 7946 0000')==='+442079460000' && sms_number('555-0142')===null && sms_number('')===null,'Phone numbers written any way become one form; a short one is refused');

// Quiet hours: 8 a.m. to 8 p.m. in the client's own zone.
foreach (array_keys(US_TIME_ZONES) as $tz) {
    $h=(int)(new DateTimeImmutable('now',new DateTimeZone($tz)))->format('G');
    $after=sms_send_after($tz);
    if ($h>=8 && $h<20) check($after===null,"A text to $tz goes now, at $h o'clock there");
    else check($after!==null && (int)(new DateTimeImmutable($after))->setTimezone(new DateTimeZone($tz))->format('G')===8 && strtotime($after)>time(),"A text to $tz at $h o'clock there waits for 8 a.m. their time");
}

// A client with no state or time zone on record (4.17.1): texted only while it is daytime in every US zone.
$allDay=function(int $ts):bool{ foreach (SMS_UNKNOWN_ZONES as $z) { $h=(int)(new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone($z))->format('G'); if ($h<8||$h>=20) return false; } return true; };
check(count(sms_client_zones(['state'=>null,'time_zone'=>null]))===8 && sms_client_zones(['state'=>'HI','time_zone'=>null])===['Pacific/Honolulu'] && sms_client_zones(['state'=>'HI','time_zone'=>'America/New_York'])===['America/New_York'],'With no state or zone, a client may be anywhere in the US; a state or picked zone narrows it to one');
$ua=sms_send_after_zones(SMS_UNKNOWN_ZONES);
check($ua===null ? $allDay(time()) : (!$allDay(time()) && $allDay(strtotime($ua)) && !$allDay(strtotime($ua)-3600) && strtotime($ua)>time()),'A text to a client of unknown zone waits for the first hour that is daytime from Puerto Rico to Hawaii');
check((bool)preg_match('/^\d{1,2}(:\d\d)? [ap]\.m\. to \d{1,2}(:\d\d)? [ap]\.m\. office time$/',sms_unknown_window()),'Staff are told those hours in office time ('.sms_unknown_window().')');
foreach (['13:00','18:59'] as $t) { $ts=strtotime('2026-07-15 '.$t); check($allDay($ts),"In summer, $t Central is daytime everywhere"); }
foreach (['12:59','19:00','07:00'] as $t) { $ts=strtotime('2026-07-15 '.$t); check(!$allDay($ts),"In summer, $t Central is not"); }

// Agreement: recorded through the app, refused without it.
$db->exec("INSERT INTO clients(name,assigned_to,status,phone) VALUES('Texty Synthetic Client',1,'Active Training','515-555-0142')"); $tc=(int)$db->lastInsertId();
$r=sms_to_client($tc,'Hello','message');
check($r['status']==='blocked' && str_contains($r['message'],'not agreed') && !$sent($tc,'message'),'A client who has not agreed is never texted, and nothing is logged');
$call('save_client',['id'=>$tc,'name'=>'Texty Synthetic Client','status'=>'Active Training','phone'=>'515-555-0142','sms_consent'=>true,'sms_consent_source'=>'By phone']);
$row=sms_client($tc);
check($row['sms_consent_at']!==null && $row['sms_consent_source']==='By phone' && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='sms.consent' AND entity_id=$tc")->fetchColumn()===1,'The app records the agreement and how it was given, in the audit log');
$d=$call('client',[],['id'=>(string)$tc]);
check($d['texting']['agreed']===true && $d['texting']['blocked']===null && str_contains($d['texting']['words'],'By phone'),'The apps are told the client can be texted, in words');
$call('save_client',['id'=>$tc,'name'=>'Texty Synthetic Client','status'=>'Active Training','phone'=>'515-555-0142']);
check(sms_client($tc)['sms_consent_at']!==null,'An app that does not send the agreement leaves it as it was');
$call('save_client',['id'=>$tc,'name'=>'Texty Synthetic Client','status'=>'Active Training','phone'=>'515-555-0199']);
check(sms_client($tc)['sms_consent_at']===null,'A new phone number clears the agreement (it was for the old number)');
$call('save_client',['id'=>$tc,'name'=>'Texty Synthetic Client','status'=>'Active Training','phone'=>'515-555-0199','sms_consent'=>true,'sms_consent_source'=>'By phone']);
$r=$call('save_client',['id'=>$tc,'name'=>'Texty Synthetic Client','status'=>'Active Training','phone'=>'515-555-0142','sms_consent'=>true,'sms_consent_source'=>'By phone']);
check(sms_client($tc)['sms_consent_at']===null && str_contains((string)$r['sms_note'],'phone number changed'),'A new number clears the agreement even with the box still ticked, and staff are told');
$call('save_client',['id'=>$tc,'name'=>'Texty Synthetic Client','status'=>'Active Training','phone'=>'515-555-0142','sms_consent'=>true,'sms_consent_source'=>'Made up']);
check(sms_client($tc)['sms_consent_source']==='In person','Agreeing again for the new number records it; an unknown way of agreeing is recorded as in person');
$db->exec("INSERT INTO clients(name,assigned_to,status,phone) VALUES('No Number Synthetic',1,'Active Training','555-01')"); $nn=(int)$db->lastInsertId();
check(str_contains((string)sms_consent_save($nn,true,'In person',null,1),'cannot receive texts') && sms_client($nn)['sms_consent_at']===null,'An agreement is never recorded for a number that cannot take texts');

// Sending: a message by text from the app, shown as such in the thread.
$key='0F8FAD5B-D9CB-469F-A165-70867728950E';
$res=$call('post_message',['client_id'=>$tc,'channel'=>'client','body'=>'Your device arrived.','by_text'=>true,'idem_key'=>$key]);
$again=$call('post_message',['client_id'=>$tc,'channel'=>'client','body'=>'Your device arrived.','by_text'=>true,'idem_key'=>$key]);
check(($again['repeat'] ?? false)===true && $again['id']===$res['id'],'A message the app sends again after a lost reply is posted once');
$log=$sent($tc,'message');
check(count($log)===1 && $log[0]['status']===$expect($tc) && str_ends_with($log[0]['body'],': Your device arrived.') && $res['texted']===$expect($tc),'A message can also go as a text, now or at 8 a.m. their time');
check($db->query("SELECT via FROM messages WHERE client_id=$tc ORDER BY id DESC LIMIT 1")->fetchColumn()==='text','The message is marked as sent by text');
$th=$call('message_thread',[],['client_id'=>(string)$tc,'channel'=>'client']);
check($th['text_enabled']===true && $th['text_blocked']===null && end($th['messages'])['via']==='text','The apps\' thread says it went by text and that texting is open');
$call('post_message',['client_id'=>$tc,'channel'=>'counselor','body'=>'For the counselor','by_text'=>true]);
check(count($sent($tc,'message'))===1,'A message to the counselor is never texted to the client');
// The website does the same.
[$code,,$err]=$renderPage('messages',['client'=>(string)$tc],['reply'=>'1','client_id'=>(string)$tc,'channel'=>'client','body'=>'From the website','by_text'=>'1']);
check(count($sent($tc,'message'))===2 && $db->query("SELECT via FROM messages WHERE client_id=$tc AND body='From the website'")->fetchColumn()==='text','The website\'s messages page sends by text too' . ($err ? ": $err" : ''));
[$code,$html,$err]=$renderPage('messages',['client'=>(string)$tc]);
check(str_contains($html,'Also send it as a text message') && str_contains($html,', by text'),'The website offers texting and marks texted messages' . ($err ? ": $err" : ''));
// A refused send is said, and logged as failed.
putenv('CMS_TEST_SMS=fail');
$db->exec("INSERT INTO sms_log(direction,client_id,phone,body,purpose,status) VALUES('out',$tc,'+15155550142','Synthetic','message','queued')");
$f=sms_dispatch((int)$db->lastInsertId());
putenv('CMS_TEST_SMS=ok');
check(!$f['ok'] && str_contains($f['message'],'Synthetic refusal'),'A text the service refuses is reported with its reason');

// A held text is sent once its time comes, unless the client withdrew meanwhile.
$db->exec("INSERT INTO sms_log(direction,client_id,phone,body,purpose,status,send_after) VALUES('out',$tc,'+15155550142','Held one','message','held',DATE_SUB(NOW(),INTERVAL 1 MINUTE))"); $h1=(int)$db->lastInsertId();
check(sms_flush_held()>=1 && $db->query("SELECT status FROM sms_log WHERE id=$h1")->fetchColumn()==='sent','A held text goes once its morning comes');
$db->exec("INSERT INTO sms_log(direction,client_id,phone,body,purpose,status,send_after) VALUES('out',$tc,'+15155550142','Held two','message','held',DATE_SUB(NOW(),INTERVAL 1 MINUTE))"); $h2=(int)$db->lastInsertId();
$db->exec("UPDATE clients SET sms_opted_out_at=NOW() WHERE id=$tc");
sms_flush_held();
check($db->query("SELECT status FROM sms_log WHERE id=$h2")->fetchColumn()==='blocked','A held text is not sent to a client who replied STOP in the meantime');
$db->exec("UPDATE clients SET sms_opted_out_at=NULL WHERE id=$tc");

// Session notices and reminders.
$db->exec("UPDATE clients SET name='Texty Synthetic Client', preferred_language='en' WHERE id=$tc");
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,location,is_remote) VALUES($tc,1,DATE_ADD(NOW(),INTERVAL 20 HOUR),DATE_ADD(NOW(),INTERVAL 21 HOUR),'Scheduled','Synthetic Library Room 2',0)"); $ts=(int)$db->lastInsertId();
client_session_notice([$ts],'booked');
$b=$sent($tc,'booked');
check(count($b)===1 && str_starts_with($b[0]['body'],'Hi Texty, your '.(brand('short') ?: cfg('name')).' session is booked for') && !str_contains($b[0]['body'],'Synthetic Library') && !str_contains($b[0]['body'],'Synthetic Client'),'A booked session is texted with the first name and time only, not the place');
set_setting('sms_include_location','yes');
check(str_contains(sms_session_text(sms_client($tc),$db->query("SELECT * FROM scheduled_sessions WHERE id=$ts")->fetch(),'reminder'),'at Synthetic Library Room 2'),'The place is named when the administrator chose that');
set_setting('sms_include_location','no');
$db->exec("UPDATE clients SET preferred_language='es' WHERE id=$tc");
check(str_starts_with(sms_session_text(sms_client($tc),$db->query("SELECT * FROM scheduled_sessions WHERE id=$ts")->fetch(),'reminder'),'Hola Texty'),'A Spanish-speaking client is texted in Spanish');
$db->exec("UPDATE clients SET preferred_language='en' WHERE id=$tc");
[$code,$mail]=$cronRun('session_reminders.php',true);
$rem=$sent($tc,'reminder');
check(count($rem)===1 && str_contains($rem[0]['body'],'Reply C to confirm, R to reschedule, X to ask to cancel') && (int)$db->query("SELECT sms_reminder_sent FROM scheduled_sessions WHERE id=$ts")->fetchColumn()===1,'The reminder job texts a reminder the client can answer');
$cronRun('session_reminders.php',true);
check(count($sent($tc,'reminder'))===1,'A session is reminded by text only once');
// A session text that waited is not sent once the session has moved or been cancelled.
$db->prepare("INSERT INTO sms_log(direction,client_id,session_id,phone,body,purpose,status,send_after) VALUES('out',?,?,'+15155550142',?,'booked','held',DATE_SUB(NOW(),INTERVAL 1 MINUTE))")
    ->execute([$tc,$ts,sms_session_text(sms_client($tc),$db->query("SELECT * FROM scheduled_sessions WHERE id=$ts")->fetch(),'booked')]); $hs=(int)$db->lastInsertId();
$db->exec("UPDATE scheduled_sessions SET start_at=DATE_ADD(start_at,INTERVAL 1 DAY), end_at=DATE_ADD(end_at,INTERVAL 1 DAY) WHERE id=$ts");
sms_flush_held();
check($db->query("SELECT status FROM sms_log WHERE id=$hs")->fetchColumn()==='blocked','A held session text is not sent once the session has moved');
$db->exec("UPDATE scheduled_sessions SET start_at=DATE_SUB(start_at,INTERVAL 1 DAY), end_at=DATE_SUB(end_at,INTERVAL 1 DAY) WHERE id=$ts");

// Replies: C confirms; R and X become requests for staff, never acted on by a text alone.
$from='+15155550142';
$rep=sms_inbound($from,' c ','SM-in-1');
check(str_contains((string)$rep,'is confirmed') && $db->query("SELECT client_reply FROM scheduled_sessions WHERE id=$ts")->fetchColumn()==='confirmed','C confirms the session and thanks the client');
check(sms_inbound($from,'c','SM-in-1')===null && (int)$db->query("SELECT COUNT(*) FROM sms_log WHERE provider_sid='SM-in-1'")->fetchColumn()===1,'The same text delivered twice is read once');
$rep=sms_inbound($from,'X','SM-in-2');
check(str_contains((string)$rep,'will confirm the cancellation') && $db->query("SELECT status FROM scheduled_sessions WHERE id=$ts")->fetchColumn()==='Scheduled'
    && (int)$db->query("SELECT COUNT(*) FROM portal_requests WHERE client_id=$tc AND type='cancel' AND session_id=$ts")->fetchColumn()===1,'X asks staff to cancel; the session stays booked until they do');
sms_inbound($from,'R','SM-in-3');
check((int)$db->query("SELECT COUNT(*) FROM portal_requests WHERE client_id=$tc AND type='reschedule'")->fetchColumn()===1,'R asks staff for a new time');
$rep=sms_inbound($from,'Running ten minutes late','SM-in-4');
check($rep===null && $db->query("SELECT via FROM messages WHERE client_id=$tc AND sender='client' AND body='Running ten minutes late'")->fetchColumn()==='text'
    && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND message LIKE 'New text message from Texty%'")->fetchColumn()>=1,'Any other text lands in the client\'s message thread and alerts their staff member');
check(str_contains((string)sms_inbound($from,'HELP','SM-in-5'),'Reply STOP'),'HELP is answered');
// "Cancel" is a carrier stop word: it stops texts, and a cancellation it meant still reaches staff.
$cancelsBefore=(int)$db->query("SELECT COUNT(*) FROM portal_requests WHERE client_id=$tc AND type='cancel'")->fetchColumn();
sms_inbound($from,'Cancel','SM-in-5b');
check(sms_client($tc)['sms_opted_out_at']!==null && (int)$db->query("SELECT COUNT(*) FROM portal_requests WHERE client_id=$tc AND type='cancel'")->fetchColumn()===$cancelsBefore+1,'A reply of Cancel stops texts and still asks staff to cancel the reminded session');
sms_inbound($from,'START','SM-in-5c');
// A number several current clients share: STOP stops all of them; other replies go to staff to sort out.
$db->exec("INSERT INTO clients(name,assigned_to,status,phone,sms_consent_at) VALUES('Sibling Synthetic Client',1,'Active Training','(515) 555-0142',NOW())"); $sib=(int)$db->lastInsertId();
check(sms_client_by_phone($from)===null && count(sms_clients_by_phone($from))===2,'A shared number is not filed under one of the clients');
$msgsBefore=(int)$db->query("SELECT COUNT(*) FROM messages WHERE client_id IN ($tc,$sib)")->fetchColumn();
sms_inbound($from,'Which of us is this for?','SM-in-5d');
check((int)$db->query("SELECT COUNT(*) FROM messages WHERE client_id IN ($tc,$sib)")->fetchColumn()===$msgsBefore,'A message from a shared number is left for staff, not put in one client\'s thread');
sms_inbound($from,'STOP','SM-in-5e');
check(sms_client($sib)['sms_opted_out_at']!==null && sms_client($tc)['sms_opted_out_at']!==null,'STOP from a shared number stops texts to every client with it');
sms_inbound($from,'START','SM-in-5f');
check(sms_client($sib)['sms_opted_out_at']===null && sms_client($tc)['sms_opted_out_at']===null,'START restores them all');
$db->exec("DELETE FROM clients WHERE id=$sib");
sms_inbound($from,'Stop','SM-in-6');
check(sms_client($tc)['sms_opted_out_at']!==null && sms_to_client($tc,'x','message')['status']==='blocked','STOP stops all texts at once');
$call('save_client',['id'=>$tc,'name'=>'Texty Synthetic Client','status'=>'Active Training','phone'=>'515-555-0142','sms_consent'=>true]);
check(sms_client($tc)['sms_opted_out_at']!==null,'Staff cannot turn texts back on after STOP');
check(str_contains((string)sms_inbound($from,'START','SM-in-7'),'again') && sms_client($tc)['sms_opted_out_at']===null,'START turns them back on');
$before=(int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
check(sms_inbound('+15155550777','Who is this?','SM-in-8')===null && (int)$db->query("SELECT client_id IS NULL FROM sms_log WHERE provider_sid='SM-in-8'")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn()>$before,'A text from an unknown number is logged and the administrators told');

// Signed requests only, and delivery reports.
$fields=['From'=>$from,'Body'=>'C','MessageSid'=>'SM-in-9'];
$data='https://example.invalid/sms_inbound.php'; ksort($fields); foreach ($fields as $k=>$v) $data.=$k.$v;
$sig=base64_encode(hash_hmac('sha1',$data,'synthetic-token',true));
check(sms_signature_ok('https://example.invalid/sms_inbound.php',$fields,$sig) && !sms_signature_ok('https://example.invalid/sms_inbound.php',['Body'=>'X']+$fields,$sig) && !sms_signature_ok('https://example.invalid/sms_inbound.php',$fields,''),'Only a request signed with the auth token is accepted');
// The webhook loads core.php only (no session): run as the calendar feed is, posted as Twilio posts.
$hook=function(string $name,array $post)use($root,$tmp):array{
    file_put_contents($tmp.'/'.$name.'.php',swap_required("require __DIR__ . '/inc/core.php';",'require '.var_export($tmp.'/core.php',true).';',file_get_contents($root.'/public/'.$name.'.php'),'public/'.$name.'.php'));
    $p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page_post',$tmp.'/'.$name.'.php','[]',json_encode($post)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),$out,$err];
};
// The harness adds its own form check field to a post, which Twilio's signature covers too.
$signed=$fields+['csrf'=>'synthetic-csrf'];
$data='https://example.invalid/sms_inbound.php'; ksort($signed); foreach ($signed as $k=>$v) $data.=$k.$v;
$hookSig=base64_encode(hash_hmac('sha1',$data,'synthetic-token',true));
[$code,$out,$err]=$hook('sms_inbound',$fields);
check(str_contains($out,'<Response/>') && !$db->query("SELECT 1 FROM sms_log WHERE provider_sid='SM-in-9'")->fetchColumn(),'An unsigned text to the address is refused and not read' . ($err ? ": $err" : ''));
putenv('HTTP_X_TWILIO_SIGNATURE='.$hookSig);
[$code,$out,$err]=$hook('sms_inbound',$fields);
putenv('HTTP_X_TWILIO_SIGNATURE');
check(str_contains($out,'<Message>') && $db->query("SELECT 1 FROM sms_log WHERE provider_sid='SM-in-9'")->fetchColumn(),'A signed text is read and answered' . ($err ? ": $err" : ''));
$sid=(string)$db->query("SELECT provider_sid FROM sms_log WHERE client_id=$tc AND direction='out' AND provider_sid IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
sms_status_update($sid,'delivered',null);
sms_status_update($sid,'sent',null);
check($sid!=='' && $db->query("SELECT status FROM sms_log WHERE provider_sid=".$db->quote($sid))->fetchColumn()==='delivered','A delivery report is recorded, and a late earlier one does not undo it');

$db->exec("UPDATE sms_log SET provider_sid='SM-synthetic-21610' WHERE id=(SELECT id FROM (SELECT MAX(id) id FROM sms_log WHERE client_id=$tc AND direction='out') x)");
sms_status_update('SM-synthetic-21610','undelivered','Error 21610');
check(sms_client($tc)['sms_opted_out_at']!==null,'A number the service reports as unsubscribed (21610) is opted out here too');
$db->exec("UPDATE clients SET sms_opted_out_at=NULL WHERE id=$tc");
// Moving a session lets it be reminded by text again, at its new time.
$db->exec("UPDATE scheduled_sessions SET sms_reminder_sent=1, client_reply='confirmed' WHERE id=$ts");
$mv=session_move($ts, strtotime('+2 days 10:00'), strtotime('+2 days 11:00'), 1, true);
check($mv['moved'] && (int)$db->query("SELECT sms_reminder_sent FROM scheduled_sessions WHERE id=$ts")->fetchColumn()===0 && $db->query("SELECT client_reply FROM scheduled_sessions WHERE id=$ts")->fetchColumn()===null,'A moved session is reminded again and loses the old confirmation' . (isset($mv['error']) && $mv['error'] ? ': '.$mv['error'] : ''));

// Staff alerts by text: only to staff who chose it, no client details, at most every 15 minutes.
$db->exec("UPDATE users SET mobile='+15155550188', alert_sms=1 WHERE id=1");
$db->exec("DELETE FROM settings WHERE name='alert_texted_1'");
notify_user(1,'Texty Synthetic Client asked for help','x.php',true);
notify_user(1,'Second urgent','x.php',true);
$al=$db->query("SELECT body FROM sms_log WHERE user_id=1 AND purpose='alert'")->fetchAll(PDO::FETCH_COLUMN);
check(count($al)===1 && !str_contains($al[0],'Texty') && str_contains($al[0],'Sign in to read it'),'An urgent alert is texted to staff who chose it, without its words, once per 15 minutes');
notify_user(1,'Not urgent','x.php');
check(count($db->query("SELECT id FROM sms_log WHERE user_id=1 AND purpose='alert'")->fetchAll())===1,'Everyday notifications are never texted');
[$code,,$err]=$renderPage('account',[],['save_mobile'=>'1','mobile'=>'555','alert_sms'=>'1']);
check($db->query("SELECT mobile FROM users WHERE id=1")->fetchColumn()==='+15155550188','A mobile number that cannot take texts is refused' . ($err ? ": $err" : ''));
sms_inbound('+15155550188','STOP','SM-in-staff');
check((int)$db->query("SELECT alert_sms FROM users WHERE id=1")->fetchColumn()===0,'A staff member who replies STOP gets no more alert texts');
$db->exec("UPDATE users SET mobile=NULL, alert_sms=0 WHERE id=1");

// Homework: one text per client, and old homework is never texted after a later agreement.
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency) VALUES($tc,'Texty track','TXT-1','Synthetic agency')"); $tt=(int)$db->lastInsertId();
$db->exec("INSERT INTO homework(track_id,title,status,assigned_at) VALUES($tt,'Practice one','Assigned',CURDATE()),($tt,'Practice two','Assigned',CURDATE())");
$cronRun('session_reminders.php',true);
$hw=$sent($tc,'homework');
check(count($hw)===1 && str_contains($hw[0]['body'],'2 new homework assignments') && !str_contains($hw[0]['body'],'Practice'),'New homework is one text, without its titles');

// The administrator's page and the client's record.
[$code,$html,$err]=$renderPage('sms',[]);
check(str_contains($html,'https://example.invalid/sms_inbound.php') && str_contains($html,'Texty Synthetic Client') && str_contains($html,'Waiting for 8 a.m.') === ($db->query("SELECT COUNT(*) FROM sms_log WHERE status='held'")->fetchColumn()>0),'The Text messages page gives the webhook address and the log' . ($err ? ": $err" : ''));
[$code,$html,$err]=$renderPage('client',['id'=>(string)$tc]);
check(str_contains($html,'Agreed to texts on') && str_contains($html,'Their state is not on their record'),'The client\'s record says whether they agreed to texts, and that a missing state narrows the hours' . ($err ? ": $err" : ''));
$db->exec("UPDATE clients SET state='IA' WHERE id=$tc");
check(!str_contains(sms_client_words(sms_client($tc)),'state is not'),'With a state on record, the note goes away');
$db->exec("UPDATE clients SET state=NULL WHERE id=$tc");

// The referral form's "OK to text" carries into the client record on conversion.
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,client_phone,referral_source,sms_ok,status) VALUES('Self','Self referral','Synthetic','','','','','Referral Text Synthetic','515-555-0166','self',1,'New')"); $rf=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('referrals',[],['convert'=>'1','id'=>(string)$rf]);
$cv=$db->query("SELECT sms_consent_at, sms_consent_source FROM clients WHERE name='Referral Text Synthetic'")->fetch();
check($cv && $cv['sms_consent_at']!==null && $cv['sms_consent_source']==='Referral form','A self-referral that said OK to text is recorded as agreed when it becomes a client' . ($err ? ": $err" : ''));

putenv('CMS_TEST_SMS');
$db->exec("DELETE FROM settings WHERE name LIKE 'cfg.sms.%'");
unset($GLOBALS['CONFIG']['sms']);
