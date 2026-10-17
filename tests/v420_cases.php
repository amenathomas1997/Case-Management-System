<?php
// Included by full_schema.php after v419_cases.php, against its synthetic database only:
// 4.20.0: two-way Outlook calendar sync and client self-booking. Microsoft is never called:
// CMS_TEST_MEETINGS answers as Graph would (by address prefix).

// ---- Outlook calendar sync ----
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.ms_client_id','synthetic-ms-id'),('cfg.meet.ms_client_secret','synthetic-ms-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['meet']=($GLOBALS['CONFIG']['meet'] ?? [])+['ms_client_id'=>'synthetic-ms-id','ms_client_secret'=>'synthetic-ms-secret'];
check(str_contains(meeting_app('microsoft')['scope'],'Calendars.ReadWrite'),'The Microsoft connection asks for the person\'s own calendar');
$db->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES (81,'Calendar Synthetic Instructor','cal-instructor@example.invalid','test','instructor')");
check(str_contains((string)calendar_problem(81),'off'),'Sync is off until the person turns it on');
$db->exec("UPDATE users SET calendar_sync=1 WHERE id=81");
check(str_contains((string)calendar_problem(81),'not connected'),'It needs the person\'s Microsoft connection');
// A connection from before 4.20.0 granted no calendar: it is asked to connect again, and keeps renewing with what it has.
meeting_store(81,'microsoft',['access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','expires_in'=>3600,'scope'=>'User.Read OnlineMeetings.ReadWrite'],'cal-instructor@example.invalid');
check(str_contains((string)calendar_problem(81),'does not include the calendar'),'A connection without the calendar is asked to connect again');
check(str_contains((string)file_get_contents($root.'/public/inc/meetings.php'),"\$fields['scope'] = trim((string)(\$acc['granted_scope'] ?? '')) ?: MEETING_MS_LEGACY_SCOPE"),'Renewals ask only for what was granted, so Teams links keep working for older connections');
meeting_store(81,'microsoft',['access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','expires_in'=>3600,'scope'=>'offline_access User.Read OnlineMeetings.ReadWrite Calendars.ReadWrite'],'cal-instructor@example.invalid');
check(calendar_problem(81)===null,'With the calendar granted, sync can run');
$db->exec("INSERT INTO clients(name,status,assigned_to,state) VALUES('Calvin Synthetic-Client','Active Training',81,'IA')"); $cc=(int)$db->lastInsertId();
$t0=strtotime('tomorrow 14:00'); $t1=$t0+3600;
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,location) VALUES(?,81,?,?,'Scheduled','Synthetic Room 3')")->execute([$cc,date('Y-m-d H:i:s',$t0),date('Y-m-d H:i:s',$t1)]); $cs=(int)$db->lastInsertId();
$own0=strtotime('tomorrow 10:00'); $own1=$own0+5400;
$g=fn(int $ts)=>['dateTime'=>gmdate('Y-m-d\TH:i:s',$ts).'.0000000','timeZone'=>'UTC'];
$fake=function(array $view) {
    putenv('CMS_TEST_MEETINGS='.json_encode(['https://graph.microsoft.com/v1.0/me/calendarView'=>['body'=>['value'=>$view]],
        'https://graph.microsoft.com/v1.0/me/events'=>['status'=>201,'body'=>['id'=>'synthetic-event-1']]]));
};
$fake([['id'=>'own-1','start'=>$g($own0),'end'=>$g($own1),'showAs'=>'busy'],['id'=>'free-1','start'=>$g($own0+86400),'end'=>$g($own1+86400),'showAs'=>'free'],
    ['id'=>'invite-1','iCalUId'=>ics_uid('session',99),'start'=>$g($own0+2*86400),'end'=>$g($own1+2*86400),'showAs'=>'tentative'],
    ['id'=>'allday-1','isAllDay'=>true,'start'=>['dateTime'=>date('Y-m-d',strtotime('+5 days')).'T00:00:00.0000000','timeZone'=>'UTC'],'end'=>['dateTime'=>date('Y-m-d',strtotime('+6 days')).'T00:00:00.0000000','timeZone'=>'UTC'],'showAs'=>'oof']]);
$r=calendar_sync_user(81);
$link=$db->query("SELECT * FROM calendar_links WHERE session_id=$cs")->fetch();
check($r['error']===null && $r['pushed']===1 && $link && $link['event_id']==='synthetic-event-1','A booked session is put in the person\'s Outlook calendar');
check($r['busy']===2 && calendar_busy_overlaps(81,$own0+600,$own0+1200) && !calendar_busy_overlaps(81,$own0+86400,$own1+86400),'Their own busy appointments are kept (free ones are not)');
check(!calendar_busy_overlaps(81,$own0+2*86400,$own1+2*86400),'The system\'s own invitation emails in their calendar are not counted as busy');
$ad=strtotime(date('Y-m-d',strtotime('+5 days')).' 00:00');
check(calendar_busy_overlaps(81,$ad+1,$ad+600) && calendar_busy_overlaps(81,$ad+23*3600,$ad+23*3600+600) && !calendar_busy_overlaps(81,$ad-3600,$ad-1800),'An all-day event (a day off) covers the whole local day, not a UTC day');
check(calendar_payload(['id'=>1,'client_name'=>'A B','start_at'=>date('Y-m-d H:i:s',$t0),'end_at'=>date('Y-m-d H:i:s',$t1),'is_remote'=>1,'meeting_url'=>null,'location'=>null])['sensitivity']==='private','Events are private, so colleagues who see the calendar do not see the client');
check(str_contains((string)offer_time_problem($own0,$own1,81,null,null),'Outlook'),'A time Outlook shows busy is never offered to a client');
$p=calendar_payload($db->query("SELECT s.*, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id=s.client_id WHERE s.id=$cs")->fetch());
check(str_ends_with($p['subject'],'Session with Calvin') && !str_contains(json_encode($p),'Synthetic-Client') && $p['location']['displayName']==='Synthetic Room 3' && $p['start']['timeZone']==='UTC','The event names the first name only, with the time and place');
$GLOBALS['CONFIG']['meet']['calendar_first_name']='no';
check(str_ends_with(calendar_payload(['id'=>1,'client_name'=>'Calvin X','start_at'=>date('Y-m-d H:i:s',$t0),'end_at'=>date('Y-m-d H:i:s',$t1),'is_remote'=>1,'meeting_url'=>null,'location'=>null])['subject'],'Client session'),'An organisation can leave even the first name out');
unset($GLOBALS['CONFIG']['meet']['calendar_first_name']);
$fake([['id'=>'own-1','start'=>$g($own0),'end'=>$g($own1),'showAs'=>'busy'],['id'=>'synthetic-event-1','start'=>$g($t0),'end'=>$g($t1),'showAs'=>'busy']]);
$r2=calendar_sync_user(81);
check($r2['error']===null && $r2['noticed']===0 && (int)$db->query("SELECT COUNT(*) FROM calendar_links WHERE user_id=81")->fetchColumn()===1 && $r2['busy']===1,'An unchanged session is left alone, and its own event is not counted as busy');
$db->exec("UPDATE users SET calendar_error=NULL WHERE id=81");
// Moved in Outlook: the person is told, the session is not changed.
$fake([['id'=>'synthetic-event-1','start'=>$g($t0+7200),'end'=>$g($t1+7200),'showAs'=>'busy']]);
$r3=calendar_sync_user(81);
check($r3['noticed']===1 && $db->query("SELECT start_at FROM scheduled_sessions WHERE id=$cs")->fetchColumn()===date('Y-m-d H:i:s',$t0)
    && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=81 AND message LIKE 'You moved the session with Calvin%'")->fetchColumn()===1,'A session moved in Outlook is not moved here; the person is told to move it on the Schedule');
calendar_sync_user(81);
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=81 AND message LIKE 'You moved%'")->fetchColumn()===1,'They are told once');
// Deleted in Outlook.
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,is_remote) VALUES(?,81,?,?,'Scheduled',1)")->execute([$cc,date('Y-m-d H:i:s',$t0+2*86400),date('Y-m-d H:i:s',$t1+2*86400)]); $cs2=(int)$db->lastInsertId();
$db->exec("UPDATE calendar_links SET outlook_change=NULL, event_start='".date('Y-m-d H:i:s',$t0+7200)."' WHERE session_id=$cs");
$fake([['id'=>'synthetic-event-1','start'=>$g($t0+7200),'end'=>$g($t1+7200),'showAs'=>'busy']]);
calendar_sync_user(81); // the second session's event is made (same fake id is fine for this test)
$db->exec("UPDATE calendar_links SET event_id='synthetic-event-2' WHERE session_id=$cs2");
calendar_sync_user(81);
check($db->query("SELECT outlook_change FROM calendar_links WHERE session_id=$cs2")->fetchColumn()==='deleted' && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=81 AND message LIKE 'You removed the session%'")->fetchColumn()===1,'A session deleted from Outlook stays booked, and the person is told');
// A session moved here after its event was deleted in Outlook gets a new event (not a change to the deleted one).
$db->exec("UPDATE scheduled_sessions SET start_at=DATE_ADD(start_at,INTERVAL 1 HOUR), end_at=DATE_ADD(end_at,INTERVAL 1 HOUR) WHERE id=$cs2");
calendar_push_session($cs2);
check($db->query("SELECT outlook_change FROM calendar_links WHERE session_id=$cs2")->fetchColumn()===null,'A changed session whose event was deleted in Outlook is put back as a new event');
// Cancelled here: its event goes at once.
$db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE id=$cs");
calendar_push_session($cs);
check((int)$db->query("SELECT COUNT(*) FROM calendar_links WHERE session_id=$cs")->fetchColumn()===0,'A cancelled session\'s event is removed from Outlook');
// Outlook refuses: the person is asked to connect again.
putenv('CMS_TEST_MEETINGS='.json_encode(['https://graph.microsoft.com/v1.0/me/calendarView'=>['status'=>403,'body'=>[]]]));
$r4=calendar_sync_user(81);
check(str_contains((string)$r4['error'],'connect Microsoft again') && str_contains((string)$db->query("SELECT calendar_error FROM users WHERE id=81")->fetchColumn(),'connect Microsoft again'),'A refused calendar asks the person to connect again, and My Account shows it');
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=81 AND message LIKE 'Your sessions are not reaching your Outlook calendar%'")->fetchColumn()===1,'The person is alerted when sync starts failing');
calendar_sync_user(81);
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=81 AND message LIKE 'Your sessions are not reaching%'")->fetchColumn()===1,'...once, not on every run');
$fake([]);
$n=calendar_stop(81);
check($n>=1 && (int)$db->query("SELECT COUNT(*) FROM calendar_links WHERE user_id=81")->fetchColumn()===0 && (int)$db->query("SELECT calendar_sync FROM users WHERE id=81")->fetchColumn()===0,'Turning it off removes the events it added');
$wd=(string)file_get_contents($root.'/cron/watchdog.php');
check(str_contains($wd,'calendar_sync_all(240)'),'The watchdog brings calendars up to date every ten minutes, within a time limit');

// ---- Client self-booking ----
check(availability_add(81,'9','09:00','12:00',true,'')[0]==='weekday' && availability_add(81,'1','09:00','12:00',false,'')[0]==='location' && availability_add(81,'1','12:00','09:00',true,'')!==null && availability_add(81,'1','09:00','12:00',false,'')!==null,'Hours need a day, an end after the start, and a place for in-person sessions');
foreach (['1','2','3','4','5','6','7'] as $d) check(availability_add(81,$d,'09:00','17:00',true,'')===null,"Hours on day $d are added");
check(availability_add(81,'1','10:00','11:00',true,'')!==null,'Overlapping hours are refused');
$client=$db->query("SELECT * FROM clients WHERE id=$cc")->fetch();
check(self_booking_blocked($client)==='off' && self_booking_slots($client)===[],'Nothing is offered while self-booking is off');
set_setting('self_booking','on'); set_setting('self_booking_minutes','60'); set_setting('self_booking_notice_hours','24'); set_setting('self_booking_days','7'); set_setting('self_booking_max','2');
check(self_booking_blocked($client)==='no_track','A client with no active authorization books through their instructor, never by themselves');
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,status,start_date,expiration_date) VALUES($cc,'Self-book track','SB-1','Synthetic agency',0,'Active',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY))"); $st=(int)$db->lastInsertId();
check(self_booking_slots($client)===[],'A client whose authorization has no hours left is offered no times');
$db->exec("UPDATE training_tracks SET authorized_hours=40 WHERE id=$st");
self_booking_tracks($cc,true);
$slots=self_booking_slots($client);
check(count($slots)>10 && $slots[0]['start']>=time()+24*3600 && $slots[0]['track_id']===$st,'Open times start at least 24 hours ahead, and count against the track');
$db->exec("UPDATE users SET is_active=0 WHERE id=81");
check(self_booking_blocked($client)==='no_instructor','No booking with an instructor whose account is switched off');
$db->exec("UPDATE users SET is_active=1 WHERE id=81");
// A session and an Outlook busy time take their slots away.
$busyStart=$slots[0]['start']; $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,81,?,?,'Scheduled')")->execute([$cc,date('Y-m-d H:i:s',$busyStart),date('Y-m-d H:i:s',$busyStart+3600)]);
$db->prepare("INSERT INTO calendar_busy(user_id,start_at,end_at) VALUES(81,?,?)")->execute([date('Y-m-d H:i:s',$slots[1]['start']),date('Y-m-d H:i:s',$slots[1]['start']+1800)]);
$slots2=array_column(self_booking_slots($client),'start');
check(!in_array($busyStart,$slots2,true) && !in_array($slots[1]['start'],$slots2,true),'A time with a session or an Outlook appointment is not offered');
// The client's own session with another instructor takes its time away too.
$db->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES (82,'Other Synthetic Instructor','other-instr@example.invalid','test','instructor')");
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,82,?,?,'Scheduled')")->execute([$cc,date('Y-m-d H:i:s',$slots[2]['start']),date('Y-m-d H:i:s',$slots[2]['start']+3600)]);
self_booking_tracks($cc,true);
$slots3=self_booking_slots($client);
check(!in_array($slots[2]['start'],array_column($slots3,'start'),true),'A time the client has a session with someone else is not offered');
// Booking: once, by one client; locked so it cannot be taken twice.
$pick=$slots3[1]['start'];
$b=self_booking_book($client,$pick);
$bs=$db->query("SELECT * FROM scheduled_sessions WHERE id=".(int)$b['session_id'])->fetch();
check($b['error']===null && $bs && (int)$bs['booked_by_client']===1 && (int)$bs['track_id']===$st && (int)$bs['is_remote']===1 && strtotime($bs['start_at'])===$pick,'The client books an open time, as an online session on their track');
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=81 AND message LIKE 'Calvin booked a session in the portal%'")->fetchColumn()===1 && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='session.self_book'")->fetchColumn()===1,'The instructor is told and the booking is audited');
check(self_booking_book($client,$pick)['error']!==null,'The same time cannot be booked twice');
self_booking_book($client,$slots3[2]['start']);
check(self_booking_blocked($client)==='max' && self_booking_book($client,$slots3[3]['start'])['error']!==null,'A client can hold only the set number of self-booked sessions ahead');
// The portal, in English and Spanish.
set_setting('self_booking_max','5');
$pt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$cc,$pt]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$out,$err]=$renderPage('portal',['t'=>$pt]);
check(str_contains($out,'Book a session') && str_contains($out,'<label for="slot">Time with your instructor</label>') && str_contains($out,'minutes, online'),'The portal offers the open times'.($err?": $err":''));
[$code,$out,$err]=$renderPage('portal',['t'=>$pt,'lang'=>'es']);
check(str_contains($out,'Reservar una sesión') && str_contains($out,'en línea'),'In Spanish too'.($err?": $err":''));
self_booking_tracks($cc,true);
$nextSlot=self_booking_slots($client)[0]['start'];
[$code,,$err]=$renderPage('portal',['t'=>$pt],['self_book'=>'1','slot'=>(string)$nextSlot]);
putenv('CMS_TEST_ANON');
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$cc AND booked_by_client=1 AND start_at='".date('Y-m-d H:i:s',$nextSlot)."'")->fetchColumn()===1,'Booking from the portal works'.($err?": $err":''));
// Staff keep their hours on My availability; administrators set the rules.
[$code,$html,$err]=$renderPage('availability',['user'=>'81']);
check(str_contains($html,'Mondays 9:00 AM to 5:00 PM, online') && str_contains($html,'Client self-booking (administrators)'),'My availability lists the hours and, for administrators, the rules'.($err?": $err":''));
[$code,,$err]=$renderPage('availability',[],['save_self_booking'=>'1','minutes'=>'45','notice'=>'48','days'=>'14','max'=>'3']);
check(setting('self_booking')==='off' && setting('self_booking_minutes')==='45','The rules are saved, and unticking turns self-booking off'.($err?": $err":''));
putenv('CMS_TEST_MEETINGS');
