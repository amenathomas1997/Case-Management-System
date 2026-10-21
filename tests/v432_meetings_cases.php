<?php
// Included by full_schema.php after the v4311 file, against its synthetic database only:
// 4.32.0, Zoom and Microsoft Teams connections that keep working. Zoom and Microsoft are never
// called: CMS_TEST_MEETINGS answers as they would, and every request the system sends is
// recorded, so each one is checked against what Zoom's and Microsoft's documentation requires
// (who proves the app and how, the exact return address, the scopes, the meeting's fields).
// Connections are renewed every day; one Zoom or Microsoft refuses is shown and its owner told
// once; a token withdrawn early is renewed and the meeting tried again; disconnecting Zoom
// withdraws a working token; disconnecting Microsoft takes its Outlook events away first; a
// session moved far ahead gets a new link.

$GLOBALS['CONFIG']['meet']=['zoom_client_id'=>'synthetic-zoom-id','zoom_client_secret'=>'synthetic-zoom-secret','ms_client_id'=>'synthetic-ms-id','ms_client_secret'=>'synthetic-ms-secret','ms_tenant'=>''];
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.zoom_client_id','synthetic-zoom-id'),('cfg.meet.zoom_client_secret','synthetic-zoom-secret'),('cfg.meet.ms_client_id','synthetic-ms-id'),('cfg.meet.ms_client_secret','synthetic-ms-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$keep432=$db->query('SELECT * FROM meeting_accounts')->fetchAll();
$db->exec('DELETE FROM meeting_accounts');
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V432 Synthetic Instructor','v432-instructor@example.invalid','test','instructor')"); $u432=(int)$db->lastInsertId();
$basic432='Authorization: Basic '.base64_encode('synthetic-zoom-id:synthetic-zoom-secret');
// The requests sent since the last call, and a fake set of replies (a list is answered in turn).
$sent432=function():array{ $r=$GLOBALS['CMS_TEST_MEETING_REQUESTS']??[]; $GLOBALS['CMS_TEST_MEETING_REQUESTS']=[]; return $r; };
$fake432=function(array $map)use($sent432){ $GLOBALS['CMS_TEST_MEETING_SEEN']=[]; $sent432(); putenv('CMS_TEST_MEETINGS='.json_encode($map)); };
$form432=function(?string $b):array{ parse_str((string)$b,$f); return $f; };
$expire432=fn(int $uid,string $p,int $secs)=>$db->prepare('UPDATE meeting_accounts SET expires_at = ? WHERE user_id = ? AND provider = ?')->execute([date('Y-m-d H:i:s',time()+$secs),$uid,$p]);

// Connecting Zoom: the code is exchanged with the app proven by Basic auth (never in the body)
// and the exact return address registered with Zoom; no scope (Zoom takes it from the app).
$log432=$tmp.'/v432_requests.log'; @unlink($log432); putenv('CMS_TEST_MEETINGS_LOG='.$log432);
$fake432(['https://zoom.us/oauth/token'=>['body'=>['access_token'=>'v432-zoom-access-1','refresh_token'=>'v432-zoom-refresh-1','expires_in'=>3600]],
    'https://api.zoom.us/v2/users/me'=>['body'=>['email'=>'v432-zoom@example.invalid']]]);
putenv('CMS_TEST_SESSION_ADD='.json_encode(['meeting_oauth'=>['state'=>'v432-state','provider'=>'zoom']]));
[$code,,$err]=$renderPage('meeting_connect',['provider'=>'zoom','code'=>'v432-code','state'=>'v432-state']);
putenv('CMS_TEST_SESSION_ADD');
$pageReq432=array_map(fn($l)=>json_decode($l,true),array_filter(explode("\n",(string)@file_get_contents($log432))));
$tokReq=$pageReq432[0]??[]; $f=$form432($tokReq['body']??null);
check(($tokReq['url']??'')==='https://zoom.us/oauth/token' && in_array($basic432,$tokReq['headers']??[],true)
    && ($f['grant_type']??'')==='authorization_code' && ($f['code']??'')==='v432-code'
    && ($f['redirect_uri']??'')==='https://example.invalid/meeting_connect.php?provider=zoom'
    && !isset($f['client_secret']) && !isset($f['client_id']) && !isset($f['scope']),
    'Connecting Zoom exchanges the code as Zoom requires: Basic auth, the exact return address, no secret in the body' . ($err ? ": $err" : ''));
$meReq=$pageReq432[1]??[];
check(($meReq['method']??'')==='GET' && ($meReq['url']??'')==='https://api.zoom.us/v2/users/me' && in_array('Authorization: Bearer v432-zoom-access-1',$meReq['headers']??[],true)
    && (meeting_account(1,'zoom')['account_email']??'')==='v432-zoom@example.invalid','It then reads who connected with the new token, and keeps that address');

// Connecting Microsoft: the app is proven in the body, with the scopes asked for.
check(str_contains(meeting_app('microsoft')['token'],'/organizations/'),'With no tenant entered, any work account\'s tenant is used (organizations), as before');
@unlink($log432);
$fake432(['https://login.microsoftonline.com/'=>['body'=>['access_token'=>'v432-ms-access-1','refresh_token'=>'v432-ms-refresh-1','expires_in'=>3600,'scope'=>'OnlineMeetings.ReadWrite Calendars.ReadWrite User.Read']],
    'https://graph.microsoft.com/v1.0/me'=>['body'=>['mail'=>'v432-ms@example.invalid','userPrincipalName'=>'v432-ms@example.invalid']]]);
putenv('CMS_TEST_SESSION_ADD='.json_encode(['meeting_oauth'=>['state'=>'v432-ms-state','provider'=>'microsoft']]));
[$code,,$err]=$renderPage('meeting_connect',['provider'=>'microsoft','code'=>'v432-ms-code','state'=>'v432-ms-state']);
putenv('CMS_TEST_SESSION_ADD');
$pageReq432=array_map(fn($l)=>json_decode($l,true),array_filter(explode("\n",(string)@file_get_contents($log432))));
$tokReq=$pageReq432[0]??[]; $f=$form432($tokReq['body']??null);
$hdr=implode("\n",$tokReq['headers']??[]);
check(str_contains($tokReq['url']??'','/oauth2/v2.0/token') && !str_contains($hdr,'Authorization:')
    && ($f['client_id']??'')==='synthetic-ms-id' && ($f['client_secret']??'')==='synthetic-ms-secret' && ($f['grant_type']??'')==='authorization_code'
    && ($f['redirect_uri']??'')==='https://example.invalid/meeting_connect.php?provider=microsoft'
    && ($f['scope']??'')==='offline_access User.Read OnlineMeetings.ReadWrite Calendars.ReadWrite',
    'Connecting Microsoft exchanges the code as Microsoft requires: the app in the body, the exact return address and the scopes' . ($err ? ": $err" : ''));
putenv('CMS_TEST_MEETINGS_LOG'); @unlink($log432);

// Making a Zoom meeting: a scheduled meeting (type 2) at the UTC time, its length in minutes,
// the waiting room on and nobody in before the host; a series is one meeting with no fixed time.
$fake432(['https://api.zoom.us/v2/users/me/meetings'=>['status'=>201,'body'=>['join_url'=>'https://zoom.us/j/4320000001','id'=>4320000001]]]);
$start432=strtotime('+5 days 14:00'); $why=null;
$link=meeting_create(1,'zoom','CMS session',$start432,$start432+5400,false,$why);
$r=$sent432()[0]??[]; $b=json_decode((string)($r['body']??''),true)?:[];
check($link==='https://zoom.us/j/4320000001' && ($r['method']??'')==='POST' && in_array('Authorization: Bearer v432-zoom-access-1',$r['headers']??[],true)
    && in_array('Content-Type: application/json',$r['headers']??[],true)
    && ($b['type']??0)===2 && ($b['start_time']??'')===gmdate('Y-m-d\TH:i:s\Z',$start432) && ($b['duration']??0)===90
    && ($b['settings']['waiting_room']??null)===true && ($b['settings']['join_before_host']??null)===false && ($b['topic']??'')==='CMS session',
    'A Zoom meeting is asked for with the fields Zoom documents: type 2, UTC start, minutes, waiting room on, no joining before the host');
meeting_create(1,'zoom','CMS session',$start432,$start432+3600,true,$why);
$b=json_decode((string)(($sent432()[0]??[])['body']??''),true)?:[];
check(($b['type']??0)===3,'A weekly series asks Zoom for one recurring meeting with no fixed time (type 3)');

// Making a Teams meeting: subject and the UTC start and end, the join link from joinWebUrl.
$fake432(['https://graph.microsoft.com/v1.0/me/onlineMeetings'=>['status'=>201,'body'=>['joinWebUrl'=>'https://teams.microsoft.com/l/meetup-join/v432']]]);
$link=meeting_create(1,'microsoft','CMS session',$start432,$start432+3600,false,$why);
$r=$sent432()[0]??[]; $b=json_decode((string)($r['body']??''),true)?:[];
check($link==='https://teams.microsoft.com/l/meetup-join/v432' && in_array('Authorization: Bearer v432-ms-access-1',$r['headers']??[],true)
    && ($b['subject']??'')==='CMS session' && ($b['startDateTime']??'')===gmdate('Y-m-d\TH:i:s\Z',$start432) && ($b['endDateTime']??'')===gmdate('Y-m-d\TH:i:s\Z',$start432+3600),
    'A Teams meeting is asked for with the fields Microsoft documents: subject, UTC start and end');

// Renewal. Zoom: Basic auth, the refresh token, and the new refresh token Zoom issues is kept
// (the old one no longer works). A token with under five minutes left is renewed first.
$expire432(1,'zoom',120);
$fake432(['https://zoom.us/oauth/token'=>['body'=>['access_token'=>'v432-zoom-access-2','refresh_token'=>'v432-zoom-refresh-2','expires_in'=>3600]]]);
$tok=meeting_access_token(1,'zoom');
$r=$sent432()[0]??[]; $f=$form432($r['body']??null);
check($tok==='v432-zoom-access-2' && in_array($basic432,$r['headers']??[],true) && ($f['grant_type']??'')==='refresh_token' && ($f['refresh_token']??'')==='v432-zoom-refresh-1' && !isset($f['scope'])
    && meeting_open((string)meeting_account(1,'zoom')['refresh_token'])==='v432-zoom-refresh-2',
    'A Zoom token with under five minutes left is renewed as Zoom requires, and the new refresh token is kept');
// Microsoft: the app in the body, only the scopes this person granted; a reply without a new
// refresh token keeps the old one.
$expire432(1,'microsoft',-60);
$fake432(['https://login.microsoftonline.com/'=>['body'=>['access_token'=>'v432-ms-access-2','expires_in'=>3600]]]);
$tok=meeting_access_token(1,'microsoft');
$r=$sent432()[0]??[]; $f=$form432($r['body']??null);
check($tok==='v432-ms-access-2' && ($f['grant_type']??'')==='refresh_token' && ($f['refresh_token']??'')==='v432-ms-refresh-1' && ($f['client_secret']??'')==='synthetic-ms-secret'
    && ($f['scope']??'')==='OnlineMeetings.ReadWrite Calendars.ReadWrite User.Read' && meeting_open((string)meeting_account(1,'microsoft')['refresh_token'])==='v432-ms-refresh-1',
    'A Microsoft token is renewed asking only for what was granted, and keeps its refresh token when Microsoft sends no new one');

// A token withdrawn before it expired (401): renewed once and the meeting made on the retry.
$fake432(['https://api.zoom.us/v2/users/me/meetings'=>[['status'=>401,'body'=>['code'=>124,'message'=>'Invalid access token.']],['status'=>201,'body'=>['join_url'=>'https://zoom.us/j/4320000002']]],
    'https://zoom.us/oauth/token'=>['body'=>['access_token'=>'v432-zoom-access-3','refresh_token'=>'v432-zoom-refresh-3','expires_in'=>3600]]]);
$link=meeting_create(1,'zoom','CMS session',$start432,$start432+3600,false,$why);
$urls=array_column($sent432(),'url');
check($link==='https://zoom.us/j/4320000002' && $urls===['https://api.zoom.us/v2/users/me/meetings','https://zoom.us/oauth/token','https://api.zoom.us/v2/users/me/meetings'],
    'A Zoom token refused before it expired is renewed once and the meeting is made on the second try');

// The app removed at Zoom: the renewal is refused. The connection is marked, the person is told
// once, My Account says to connect again, and no link is tried until they do.
$db->exec("DELETE FROM notifications WHERE user_id=1 AND link='account.php#meetings-h'");
$fake432(['https://api.zoom.us/v2/users/me/meetings'=>['status'=>401,'body'=>['code'=>124,'message'=>'Invalid access token.']],
    'https://zoom.us/oauth/token'=>['status'=>400,'body'=>['reason'=>'Invalid Token!','error'=>'invalid_request']]]);
$link=meeting_create(1,'zoom','CMS session',$start432,$start432+3600,false,$why);
check($link===null && str_contains((string)$why,'connect again on My Account') && meeting_refused_at(meeting_account(1,'zoom'))!==null && !meeting_connected(1,'zoom'),
    'When Zoom refuses the renewal, no link is made, the reason says to connect again, and the connection is marked as ended');
$n=fn()=>(int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND link='account.php#meetings-h' AND message LIKE 'Your Zoom connection has ended%'")->fetchColumn();
$sent432();
meeting_create(1,'zoom','CMS session',$start432,$start432+3600,false,$why);
check($n()===1 && count($sent432())===0,'The person is told once, and nothing more is sent to Zoom until they connect again');
[$code,$html,$err]=$renderPage('account',[]);
check(str_contains($html,'Connect Zoom again') && str_contains($html,'ended on') && str_contains($html,'Disconnect Zoom'),'My Account says the Zoom connection ended, with Connect Zoom again' . ($err ? ": $err" : ''));
$post=['add_session'=>'1','client_id'=>'0','staff_id'=>'1','date'=>date('Y-m-d',strtotime('+9 days')),'time'=>'03:15','duration'=>'1','repeat_weeks'=>'1','is_remote'=>'1','meeting_make'=>'zoom'];
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V432 Synthetic Client','Active Training',1)"); $c432=(int)$db->lastInsertId(); $post['client_id']=(string)$c432;
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('schedule',[],$post));
check($db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$c432")->fetchColumn()==0 && str_contains($sess,'connection has ended'),'Asking for a Zoom link from an ended connection is refused with that reason' . ($err ? ": $err" : ''));
meeting_store(1,'zoom',['access_token'=>'v432-zoom-access-4','refresh_token'=>'v432-zoom-refresh-4','expires_in'=>3600],'v432-zoom@example.invalid');
check(meeting_connected(1,'zoom') && meeting_access_token(1,'zoom')==='v432-zoom-access-4','Connecting again makes the connection work again');

// A fault of the app itself (its secret expired or changed): nobody's connection ends, and
// administrators are told once a day. A token this server cannot read is not a refusal either.
$db->exec("DELETE FROM settings WHERE name LIKE 'meeting_app_refused_%'");
$expire432(1,'zoom',-60);
$fake432(['https://zoom.us/oauth/token'=>['status'=>401,'body'=>['reason'=>'Invalid client_id or client_secret','error'=>'invalid_client']]]);
$adminN=fn()=>(int)$db->query("SELECT COUNT(*) FROM notifications WHERE link='settings.php' AND message LIKE 'Zoom refused this system%'")->fetchColumn();
$before=$adminN();
check(meeting_access_token(1,'zoom')===null && meeting_connected(1,'zoom') && $adminN()>$before,'A refused Zoom app secret keeps the connection and tells the administrators');
$after1=$adminN(); meeting_access_token(1,'zoom');
check($adminN()===$after1,'Administrators are told about the app only once a day');
$fake432(['https://login.microsoftonline.com/'=>['status'=>401,'body'=>['error'=>'invalid_client','error_description'=>'AADSTS7000222: The provided client secret keys are expired.']]]);
meeting_store($u432,'microsoft',['access_token'=>'v432-other-access','refresh_token'=>'v432-other-refresh','expires_in'=>3600],'v432-other@example.invalid');
$expire432($u432,'microsoft',-60);
check(meeting_access_token($u432,'microsoft')===null && meeting_connected($u432,'microsoft'),'An expired Microsoft app secret ends nobody\'s connection');
$fake432(['https://login.microsoftonline.com/'=>['status'=>400,'body'=>['error'=>'invalid_scope','error_description'=>'AADSTS70011']]]);
check(meeting_access_token($u432,'microsoft')===null && meeting_connected($u432,'microsoft'),'A permission change on the app ends nobody\'s connection');
$db->prepare("UPDATE meeting_accounts SET refresh_token = 'not-readable' WHERE user_id = ? AND provider = 'microsoft'")->execute([$u432]);
$fake432(['https://login.microsoftonline.com/'=>['body'=>['access_token'=>'never','expires_in'=>3600]]]);
check(meeting_access_token($u432,'microsoft')===null && meeting_connected($u432,'microsoft') && count($sent432())===0,'A token this server cannot read (key file missing) is not sent and ends nobody\'s connection');
$db->exec("DELETE FROM meeting_accounts WHERE user_id=$u432");
$db->exec("DELETE FROM settings WHERE name LIKE 'meeting_app_refused_%'");
meeting_store(1,'zoom',['access_token'=>'v432-zoom-access-4','refresh_token'=>'v432-zoom-refresh-4','expires_in'=>3600],'v432-zoom@example.invalid');

// The daily renewal: every working connection renewed, a refused one marked and its owner told.
meeting_store($u432,'microsoft',['access_token'=>'v432-other-access','refresh_token'=>'v432-other-refresh','expires_in'=>3600],'v432-other@example.invalid');
$fake432(['https://zoom.us/oauth/token'=>['body'=>['access_token'=>'v432-zoom-access-5','refresh_token'=>'v432-zoom-refresh-5','expires_in'=>3600]],
    'https://login.microsoftonline.com/'=>['status'=>400,'body'=>['error'=>'invalid_grant','error_description'=>'AADSTS700082: The refresh token has expired due to inactivity.']]]);
[$ok,$refused,$failed]=meeting_renew_all();
check($ok===1 && $refused===2 && $failed===0 && meeting_open((string)meeting_account(1,'zoom')['refresh_token'])==='v432-zoom-refresh-5'
    && !meeting_connected($u432,'microsoft') && !meeting_connected(1,'microsoft')
    && (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=$u432 AND message LIKE 'Your Microsoft Teams connection has ended%'")->fetchColumn()===1,
    'The daily renewal keeps Zoom connected with its new refresh token, and marks and tells the people Microsoft refused');
$fake432(['https://login.microsoftonline.com/'=>['status'=>503,'body'=>null]]);
meeting_store($u432,'microsoft',['access_token'=>'v432-other-access','refresh_token'=>'v432-other-refresh','expires_in'=>3600],'v432-other@example.invalid');
$db->exec("DELETE FROM meeting_accounts WHERE user_id=1 AND provider='zoom'");
[$ok,$refused,$failed]=meeting_renew_all();
check($failed===1 && $refused===0 && meeting_connected($u432,'microsoft'),'When Microsoft does not answer, the connection is kept for the next try (only a refusal ends it)');

// Reasons in words for the common refusals.
meeting_store($u432,'microsoft',['access_token'=>'v432-other-access','refresh_token'=>'v432-other-refresh','expires_in'=>3600],'v432-other@example.invalid');
$fake432(['https://graph.microsoft.com/v1.0/me/onlineMeetings'=>['status'=>403,'body'=>['error'=>['code'=>'Forbidden','message'=>'User does not have a valid Teams license.']]]]);
meeting_create($u432,'microsoft','x',$start432,$start432+3600,false,$why);
check(str_contains((string)$why,'may not have Teams') && str_contains((string)$why,'User does not have a valid Teams license.'),'A Teams refusal names the likely cause and Microsoft\'s own words');
$fake432(['https://graph.microsoft.com/v1.0/me/onlineMeetings'=>['status'=>429,'body'=>['error'=>['code'=>'TooManyRequests','message'=>'Throttled']]]]);
meeting_create($u432,'microsoft','x',$start432,$start432+3600,false,$why);
check(str_contains((string)$why,'too many requests'),'Throttling says to try again in a minute');
$fake432([]);
meeting_create($u432,'microsoft','x',$start432,$start432+3600,false,$why);
check(str_contains((string)$why,'did not answer'),'No answer says so');

// Disconnecting Zoom withdraws a working access token (renewed first when it has expired), as Zoom's revoke requires.
meeting_store(1,'zoom',['access_token'=>'v432-zoom-access-6','refresh_token'=>'v432-zoom-refresh-6','expires_in'=>3600],'v432-zoom@example.invalid');
$expire432(1,'zoom',-60);
$fake432(['https://zoom.us/oauth/token'=>['body'=>['access_token'=>'v432-zoom-access-7','refresh_token'=>'v432-zoom-refresh-7','expires_in'=>3600]],'https://zoom.us/oauth/revoke'=>['body'=>['status'=>'success']]]);
meeting_disconnect(1,'zoom');
$reqs=$sent432(); $rev=end($reqs); $f=$form432($rev['body']??null);
check(($rev['url']??'')==='https://zoom.us/oauth/revoke' && in_array($basic432,$rev['headers']??[],true) && ($f['token']??'')==='v432-zoom-access-7' && meeting_account(1,'zoom')===null,
    'Disconnecting Zoom renews the expired token and withdraws that access token at Zoom');

// Disconnecting Microsoft with Outlook calendar sync on: its session events are removed first.
meeting_store(1,'microsoft',['access_token'=>'v432-ms-access-8','refresh_token'=>'v432-ms-refresh-8','expires_in'=>3600,'scope'=>'Calendars.ReadWrite OnlineMeetings.ReadWrite User.Read'],'v432-ms@example.invalid');
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES($c432,1,'".date('Y-m-d H:i:s',$start432)."','".date('Y-m-d H:i:s',$start432+3600)."','Scheduled')"); $s432=(int)$db->lastInsertId();
$db->exec("INSERT INTO calendar_links(session_id,user_id,event_id,pushed_hash,event_start,event_end) VALUES($s432,1,'v432-event','".str_repeat('a',40)."',NOW(),NOW())");
$db->exec('UPDATE users SET calendar_sync=1 WHERE id=1');
@unlink($log432); putenv('CMS_TEST_MEETINGS_LOG='.$log432);
$fake432(['https://graph.microsoft.com/v1.0/me/events/'=>['status'=>204,'body'=>null]]);
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('account',[],['meeting_disconnect'=>'microsoft']));
putenv('CMS_TEST_MEETINGS_LOG');
$pageReq432=array_map(fn($l)=>json_decode($l,true),array_filter(explode("\n",(string)@file_get_contents($log432))));
check(($pageReq432[0]['method']??'')==='DELETE' && ($pageReq432[0]['url']??'')==='https://graph.microsoft.com/v1.0/me/events/v432-event'
    && (int)$db->query('SELECT calendar_sync FROM users WHERE id=1')->fetchColumn()===0 && meeting_account(1,'microsoft')===null
    && (int)$db->query('SELECT COUNT(*) FROM calendar_links WHERE user_id=1')->fetchColumn()===0 && str_contains($sess,'Outlook calendar sync is off too'),
    'Disconnecting Microsoft turns Outlook calendar sync off first and removes its events from Outlook' . ($err ? ": $err" : ''));
@unlink($log432);

// A session moved far past the time its link was made for gets a new link (a single Zoom meeting
// expires about 30 days after its time, a Teams one 60), however many moves that took. A link
// someone typed, or a series' meeting with no fixed time, is kept.
meeting_store(1,'zoom',['access_token'=>'v432-zoom-access-9','refresh_token'=>'v432-zoom-refresh-9','expires_in'=>3600],'v432-zoom@example.invalid');
$mk=function(int $daysPast, bool $made)use($db,$c432,$start432):int{
    $at=$start432+86400*30;
    $db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,is_remote,meeting_url,meeting_made_for) VALUES(?,1,?,?,'Scheduled',1,?,?)")
        ->execute([$c432,date('Y-m-d H:i:s',$at),date('Y-m-d H:i:s',$at+3600),'https://zoom.us/j/43200'.mt_rand(10000,99999),$made?date('Y-m-d H:i:s',$at-$daysPast*86400):null]);
    $sid=(int)$db->lastInsertId(); meeting_refresh_after_move($sid,$at-3*86400); return $sid;
};
$fake432(['https://api.zoom.us/v2/users/me/meetings'=>['status'=>201,'body'=>['join_url'=>'https://zoom.us/j/4320000077']]]);
$far=$mk(40,true); $near=$mk(20,true); $typed=$mk(40,false);
$url=fn(int $id)=>$db->query("SELECT meeting_url FROM scheduled_sessions WHERE id=$id")->fetchColumn();
check($url($far)==='https://zoom.us/j/4320000077' && strtotime((string)$db->query("SELECT meeting_made_for FROM scheduled_sessions WHERE id=$far")->fetchColumn())===$start432+86400*30,
    'A session now 40 days past the time its Zoom link was made for gets a new link, made for its new time (even when the last move was only 3 days)');
check($url($near)!=='https://zoom.us/j/4320000077' && $url($typed)!=='https://zoom.us/j/4320000077','A session 20 days past keeps its link, and a typed link (a personal meeting room) is never replaced');
$sent432();
$db->exec("UPDATE scheduled_sessions SET start_at = DATE_ADD(start_at, INTERVAL 40 DAY), end_at = DATE_ADD(end_at, INTERVAL 40 DAY) WHERE id=$far");
meeting_refresh_after_move($far,$start432+86400*30);
check(count($sent432())===1,'After that, a further move far past the new time makes a new link again');

// Put back what the earlier files left.
putenv('CMS_TEST_MEETINGS');
$db->exec('DELETE FROM meeting_accounts');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$c432");
$db->exec('UPDATE users SET calendar_sync=0 WHERE id=1');
foreach ($keep432 as $row) {
    $cols=array_keys($row);
    $db->prepare('INSERT INTO meeting_accounts ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));
}
