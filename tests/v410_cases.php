<?php
// Included by full_schema.php after v49_cases.php, against its synthetic database only:
// 4.10.0's online meetings: each staff member connects their own Zoom or Microsoft (Teams)
// account, and a remote session can get its link made there. Zoom and Microsoft are never
// called: CMS_TEST_MEETINGS gives their replies.
check(meeting_app('zoom')===null && meeting_app('microsoft')===null,'With nothing set up, neither meeting service is offered');
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.zoom_client_id','synthetic-zoom-id'),('cfg.meet.zoom_client_secret','synthetic-zoom-secret'),('cfg.meet.ms_client_id','synthetic-ms-id'),('cfg.meet.ms_client_secret','synthetic-ms-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['meet']=['zoom_client_id'=>'synthetic-zoom-id','zoom_client_secret'=>'synthetic-zoom-secret','ms_client_id'=>'synthetic-ms-id','ms_client_secret'=>'synthetic-ms-secret'];
$za=meeting_app('zoom'); $ma=meeting_app('microsoft');
check($za['redirect']==='https://example.invalid/meeting_connect.php?provider=zoom' && str_contains($ma['token'],'/organizations/') && str_contains($ma['scope'],'OnlineMeetings.ReadWrite'),'Once set up, each service has its sign-in addresses and the return address to register');
$sealed=meeting_seal('synthetic-token-1');
$raw=base64_decode($sealed); $raw[40]=chr(ord($raw[40])^1);
check(meeting_open($sealed)==='synthetic-token-1' && meeting_open(base64_encode($raw))===null && !str_contains($sealed,'synthetic-token-1'),'Tokens are encrypted, and a changed one is refused');
check(is_file($tmp.'/meeting_tokens.key') && (fileperms($tmp.'/meeting_tokens.key') & 0077)===0,'The key is a file in the private directory that only its owner can read');
// Connecting: the return from Zoom must match the request it started.
$db->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES (41,'Meeting Synthetic Instructor','meet-instructor@example.invalid','test','instructor')");
putenv('CMS_TEST_MEETINGS='.json_encode(['https://zoom.us/oauth/token'=>['body'=>['access_token'=>'synthetic-zoom-access','refresh_token'=>'synthetic-zoom-refresh','expires_in'=>3600]],
    'https://api.zoom.us/v2/users/me/meetings'=>['status'=>201,'body'=>['join_url'=>'https://zoom.us/j/1234567890']],
    'https://api.zoom.us/v2/users/me'=>['body'=>['email'=>'admin-zoom@example.invalid']]]));
putenv('CMS_TEST_SESSION_ADD='.json_encode(['meeting_oauth'=>['state'=>'synthetic-state','provider'=>'zoom']]));
[$code,,$err]=$renderPage('meeting_connect',['provider'=>'zoom','code'=>'synthetic-code','state'=>'other-state']);
check($db->query("SELECT COUNT(*) FROM meeting_accounts")->fetchColumn()==0,'A return that does not match the request connects nothing' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('meeting_connect',['provider'=>'zoom','code'=>'synthetic-code','state'=>'synthetic-state']);
putenv('CMS_TEST_SESSION_ADD');
$acc=meeting_account(1,'zoom');
check($acc && $acc['account_email']==='admin-zoom@example.invalid' && !str_contains($acc['access_token'].$acc['refresh_token'],'synthetic-zoom') && meeting_access_token(1,'zoom')==='synthetic-zoom-access','The matching return connects the account; the database holds the tokens only encrypted' . ($err ? ": $err" : ''));
check($db->query("SELECT COUNT(*) FROM audit_log WHERE action='meeting.connect' AND user_id=1")->fetchColumn()==1,'Connecting is in the audit log');
[[$code,,$err],$csess]=$pageSession(fn()=>$renderPage('meeting_connect',['provider'=>'zoom']));
check(!str_contains($csess,'meeting_oauth') && $db->query("SELECT COUNT(*) FROM meeting_accounts")->fetchColumn()==1,'Opening the connect address as a link starts nothing (it is a button with its check)');
// An expired token is renewed.
$db->exec("UPDATE meeting_accounts SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id=1");
check(meeting_access_token(1,'zoom')==='synthetic-zoom-access' && strtotime((string)meeting_account(1,'zoom')['expires_at'])>time(),'An expired connection is renewed with its refresh token');
// Making a meeting.
$why=null;
check(meeting_create(1,'zoom','AT Training: Synthetic C.',time()+86400,time()+90000,false,$why)==='https://zoom.us/j/1234567890' && $why===null,'A Zoom meeting is made in the connected account');
check(meeting_create(41,'zoom','x',time()+86400,time()+90000,false,$why)===null && str_contains((string)$why,'not connected'),'Someone not connected gets no meeting, and the reason');
putenv('CMS_TEST_MEETINGS='.json_encode(['https://login.microsoftonline.com/'=>['body'=>['access_token'=>'synthetic-ms-access','refresh_token'=>'synthetic-ms-refresh','expires_in'=>3600]],
    'https://graph.microsoft.com/v1.0/me/onlineMeetings'=>['status'=>201,'body'=>['joinWebUrl'=>'https://teams.microsoft.com/l/meetup-join/synthetic']]]));
meeting_store(1,'microsoft',['access_token'=>'synthetic-ms-access','refresh_token'=>'synthetic-ms-refresh','expires_in'=>3600],'admin-ms@example.invalid');
check(meeting_create(1,'microsoft','AT Training',time()+86400,time()+90000,true,$why)==='https://teams.microsoft.com/l/meetup-join/synthetic','A Teams meeting is made in the connected Microsoft account');
// My Account shows each service, with Connect or Disconnect.
[$code,$html,$err]=$renderPage('account',[]);
check(str_contains($html,'id="meetings-h"') && str_contains($html,'Disconnect Zoom') && str_contains($html,'connected as admin-ms@example.invalid'),'My Account lists the connections' . ($err ? ": $err" : ''));
// Booking a remote session with a Zoom link.
putenv('CMS_TEST_MEETINGS='.json_encode(['https://api.zoom.us/v2/users/me/meetings'=>['status'=>201,'body'=>['join_url'=>'https://zoom.us/j/5550001111']]]));
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Meeting Synthetic Client','Active Training',1)"); $mc=(int)$db->lastInsertId();
$mpost=['add_session'=>'1','client_id'=>(string)$mc,'staff_id'=>'1','date'=>date('Y-m-d',strtotime('+3 days')),'time'=>'06:15','duration'=>'1','repeat_weeks'=>'2','is_remote'=>'1','meeting_make'=>'zoom']; // 4.29.0: a time the instructor is free (a clash now needs approval)
[$code,,$err]=$renderPage('schedule',[],array_diff_key($mpost,['is_remote'=>1]));
check($db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$mc")->fetchColumn()==0,'A Zoom link for a session that is not remote is refused');
[$code,,$err]=$renderPage('schedule',[],['staff_id'=>'41']+$mpost);
check($db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$mc")->fetchColumn()==0,'An instructor who has not connected Zoom cannot get a Zoom link, so nothing is booked');
[$code,,$err]=$renderPage('schedule',[],$mpost);
$links=$db->query("SELECT meeting_url FROM scheduled_sessions WHERE client_id=$mc")->fetchAll(PDO::FETCH_COLUMN);
check(count($links)===2 && count(array_unique($links))===1 && $links[0]==='https://zoom.us/j/5550001111','Both weekly sessions are booked with the one Zoom link' . ($err ? ": $err" : ''));
// Zoom refusing still books the session, without a link.
putenv('CMS_TEST_MEETINGS='.json_encode(['https://api.zoom.us/v2/users/me/meetings'=>['status'=>400,'body'=>['message'=>'synthetic refusal']]]));
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('schedule',[],['date'=>date('Y-m-d',strtotime('+20 days')),'repeat_weeks'=>'1']+$mpost));
check($db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$mc AND meeting_url IS NULL")->fetchColumn()==1 && str_contains($sess,'did not make the meeting'),'When Zoom refuses, the session is still booked and the person is told it has no link');
// Disconnecting.
[$code,,$err]=$renderPage('account',[],['meeting_disconnect'=>'zoom']);
check(meeting_account(1,'zoom')===null && meeting_account(1,'microsoft')!==null,'Disconnect removes only that service');
putenv('CMS_TEST_MEETINGS');
$db->exec("DELETE FROM settings WHERE name LIKE 'cfg.meet.%'"); unset($GLOBALS['CONFIG']['meet']);
